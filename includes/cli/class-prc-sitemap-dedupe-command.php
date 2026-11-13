<?php
/**
 * WP-CLI command: remove the extra sitemap posts of a date.
 *
 * @package PRC\Sitemap
 */

declare(strict_types=1);

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

if ( ! class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	return;
}

/**
 * Remove duplicate prc_sitemap posts.
 *
 * Two builds of one date that ran at once, before the date lease shipped, can
 * each insert a sitemap post. Only the lowest ID is read after that. The others
 * keep their own XML and count their URLs a second time in "Indexed URLs".
 *
 * ## EXAMPLES
 *
 *     wp prc sitemap dedupe
 *     wp prc sitemap dedupe --yes
 *
 * @when after_wp_load
 */
class PRC_Sitemap_Dedupe_Command extends WPCOM_VIP_CLI_Command {

	/**
	 * Sitemap posts to read per page of the scan, and dates to clean per batch.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Remove the duplicate sitemap posts of each date and keep the lowest ID.
	 *
	 * Runs as a dry run unless --yes is set. The scan reads every sitemap post
	 * once, in ID order, and captures the IDs to delete. The delete step removes
	 * only those IDs, and each date under its date lease. A date whose lease is
	 * busy is queued as an update event and skipped, so run the command again
	 * to clear it. At the end, the cache version moves on and the command prints
	 * the Indexed URLs total.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Delete the duplicates. Without it, the command only lists them.
	 *
	 * [--sleep=<seconds>]
	 * : Seconds to wait between batches of dates. Default 2.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$apply = ! empty( $assoc_args['yes'] );
		$sleep = isset( $assoc_args['sleep'] ) ? max( 0, (int) $assoc_args['sleep'] ) : 2;

		WP_CLI::line( $apply ? 'Live run: deleting duplicate sitemap posts.' : 'Dry run: nothing is deleted. Add --yes to delete.' );

		$duplicates = $this->scan();
		$extra      = array_sum( array_map( static fn( array $entry ): int => count( $entry['delete'] ), $duplicates ) );
		WP_CLI::line( sprintf( 'Dates with duplicates: %d. Posts to delete: %d.', count( $duplicates ), $extra ) );

		$deleted = 0;
		$skipped = 0;
		$batches = array_chunk( $duplicates, self::BATCH_SIZE, true );

		$this->start_bulk_operation();
		foreach ( $batches as $index => $batch ) {
			foreach ( $batch as $date => $ids ) {
				WP_CLI::line( sprintf( '%s keeps %d and %s: %s', $date, $ids['keep'], $apply ? 'deletes' : 'would delete', implode( ', ', $ids['delete'] ) ) );
				if ( ! $apply ) {
					continue;
				}

				$removed = $this->delete_extra_posts( $date, $ids['keep'], $ids['delete'] );
				if ( null === $removed ) {
					WP_CLI::warning( sprintf( 'Skipped %s: another build of this date holds the lease. The date is queued again. Run the command again when it ends.', $date ) );
					++$skipped;
					continue;
				}
				$deleted += $removed;
			}

			$this->vip_inmemory_cleanup();
			if ( $apply && $sleep > 0 && $index < count( $batches ) - 1 ) {
				sleep( $sleep );
			}
		}
		$this->end_bulk_operation();

		if ( ! $apply ) {
			WP_CLI::success( sprintf( 'Dry run done. %d posts on %d dates would be deleted.', $extra, count( $duplicates ) ) );
			return;
		}

		PRC_Sitemap_Cache::bump();
		WP_CLI::success( sprintf( 'Deleted %d posts, skipped %d dates. Indexed URLs is now %d.', $deleted, $skipped, PRC_Sitemap::get_total_indexed_url_count() ) );
	}

	/**
	 * Read every sitemap post that is not in the trash and capture the extras of each date.
	 *
	 * The scan walks IDs in ascending order. The post that stays is the lowest
	 * published ID of the date, which is the post that the site reads. A date with
	 * no published post keeps its lowest ID.
	 *
	 * A page that cannot be read stops the command with an error. The command does not treat it as
	 * the last page, because a partial scan would report that no duplicates are left.
	 *
	 * @return array<string, array{keep: int, delete: int[]}> Dates that have more than one post.
	 */
	private function scan(): array {
		global $wpdb;

		$last      = 0;
		$first     = array();
		$live      = array();
		$posts     = array();
		$page_size = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI ID-cursor walk; caching the page would defeat the batching.
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT ID, post_date, post_status FROM {$wpdb->posts} WHERE post_type = %s AND post_status != 'trash' AND ID > %d ORDER BY ID ASC LIMIT %d",
					PRC_Sitemap::SITEMAP_CPT,
					$last,
					self::BATCH_SIZE
				)
			);

			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				WP_CLI::error( sprintf( 'The sitemap posts after ID %d could not be read (%s). Nothing was deleted. Run the command again.', $last, (string) $wpdb->last_error ) );
				return array();
			}

			$page_size = count( $rows );

			foreach ( $rows as $row ) {
				$last  = (int) $row->ID;
				$date  = substr( (string) $row->post_date, 0, 10 );
				$first = $first + array( $date => $last );
				if ( 'publish' === $row->post_status && ! isset( $live[ $date ] ) ) {
					$live[ $date ] = $last;
				}
				$posts[ $date ][] = $last;
			}

			$this->vip_inmemory_cleanup();
		} while ( self::BATCH_SIZE === $page_size );

		$out = array();
		foreach ( $posts as $date => $ids ) {
			if ( count( $ids ) < 2 ) {
				continue;
			}
			$keep         = $live[ $date ] ?? $first[ $date ];
			$out[ $date ] = array(
				'keep'   => $keep,
				'delete' => array_values( array_diff( $ids, array( $keep ) ) ),
			);
		}
		ksort( $out );

		return $out;
	}

	/**
	 * Delete the captured extra posts of one date, under the lease of the date.
	 *
	 * Each ID is checked again under the lease. The post must still exist, still be
	 * a sitemap post of this date, and not be the post that is kept.
	 *
	 * @param string $date   The date, Y-m-d.
	 * @param int    $keep   The ID that stays.
	 * @param int[]  $delete The captured IDs to delete.
	 * @return int|null The number deleted, or null while a build of the date holds the lease.
	 */
	private function delete_extra_posts( string $date, int $keep, array $delete ): ?int {
		$lease = PRC_Sitemap::date_lease();
		$token = $lease->acquire( $date );
		if ( '' === $token ) {
			list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );
			PRC_Sitemap_Builder_Cron::schedule_sitemap_update_for_year_month_date( array( $year, $month, $day ), time() + PRC_Sitemap_Builder_Cron::DATE_BUSY_RETRY_SECONDS );
			return null;
		}

		$deleted = 0;
		try {
			foreach ( $delete as $id ) {
				$post = get_post( $id );
				if ( ! $post || PRC_Sitemap::SITEMAP_CPT !== $post->post_type || substr( (string) $post->post_date, 0, 10 ) !== $date || $id === $keep ) {
					continue;
				}
				if ( PRC_Sitemap::delete_sitemap_by_id( $id ) ) {
					++$deleted;
				}
			}
		} finally {
			$lease->release( $date, $token );
		}

		return $deleted;
	}
}

WP_CLI::add_command( 'prc sitemap dedupe', 'PRC_Sitemap_Dedupe_Command' );
