<?php
/**
 * WP-CLI command: export public sitemap page URLs as CSV.
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
 * Stream every public sitemap page URL to CSV on STDOUT.
 *
 * ## EXAMPLES
 *
 *     wp prc sitemap export-urls > sitemap-urls.csv
 *     vip @pewresearch.production -- wp prc sitemap export-urls --url=https://www.example.org > sitemap-urls.csv
 *
 * @when after_wp_load
 */
class PRC_Sitemap_Export_URLs_Command extends WPCOM_VIP_CLI_Command {

	/**
	 * Number of daily sitemap posts to read per batch.
	 *
	 * @var int
	 */
	private const BATCH_SIZE = 100;

	/**
	 * Export page URLs from stored daily XML, taxonomies, RLS, and Religious Projections.
	 *
	 * Writes CSV with columns url, lastmod, source to STDOUT. Progress and
	 * warnings go to STDERR.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 */
	public function __invoke( array $args, array $assoc_args ): void { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable
		$stdout = STDOUT;
		$this->write_row( $stdout, 'url', 'lastmod', 'source' );

		$count  = 0;
		$count += $this->export_posts( $stdout );
		$count += $this->export_taxonomies( $stdout );
		$count += $this->export_provider( $stdout, 'PRC\\Platform\\RLS\\Sitemap_Provider', 'rls' );
		$count += $this->export_provider( $stdout, 'PRC\\Platform\\Religious_Projections\\Sitemap_Provider', 'religious-projections' );

		WP_CLI::debug( sprintf( 'Exported %d sitemap URLs.', $count ) );
	}

	/**
	 * Walk stored daily sitemap XML and write post page URLs.
	 *
	 * @param resource $stdout Output stream.
	 * @return int Row count.
	 */
	private function export_posts( $stdout ): int {
		global $wpdb;

		$last        = 0;
		$count       = 0;
		$batch_count = 0;

		do {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- CLI ID-cursor walk; caching the ID list would defeat batching.
			$ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
					PRC_Sitemap::SITEMAP_CPT,
					$last,
					self::BATCH_SIZE
				)
			);

			if ( empty( $ids ) ) {
				break;
			}

			$batch_count = count( $ids );

			foreach ( $ids as $id ) {
				$last = (int) $id;
				$xml  = get_post_meta( $last, 'prc_sitemap_xml', true );
				if ( ! is_string( $xml ) || '' === $xml ) {
					continue;
				}

				$rows = PRC_Sitemap_Xml_Urls::extract( $xml );
				if ( false === $rows ) {
					WP_CLI::warning( sprintf( 'Could not parse sitemap XML for post %d.', $last ) );
					continue;
				}

				foreach ( $rows as $row ) {
					$this->write_row( $stdout, $row['url'], $row['lastmod'], 'posts' );
					++$count;
				}
			}

			WP_CLI::debug( sprintf( 'Exported posts batch through ID %d. Rows so far: %d.', $last, $count ) );
			$this->vip_inmemory_cleanup();
			if ( self::BATCH_SIZE === $batch_count ) {
				sleep( 1 );
			}
		} while ( self::BATCH_SIZE === $batch_count );

		return $count;
	}

	/**
	 * Write term archive URLs from the core taxonomy sitemap provider.
	 *
	 * @param resource $stdout Output stream.
	 * @return int Row count.
	 */
	private function export_taxonomies( $stdout ): int {
		if ( ! class_exists( 'WP_Sitemaps_Taxonomies' ) ) {
			WP_CLI::warning( 'WP_Sitemaps_Taxonomies is not available.' );
			return 0;
		}

		$count      = 0;
		$provider   = new WP_Sitemaps_Taxonomies();
		$taxonomies = apply_filters( 'prc_sitemap_supported_taxonomies', array( 'category' ) );

		foreach ( $taxonomies as $taxonomy ) {
			if ( ! is_string( $taxonomy ) || '' === $taxonomy ) {
				continue;
			}

			$max_pages = (int) $provider->get_max_num_pages( $taxonomy );
			for ( $page = 1; $page <= $max_pages; $page++ ) {
				$url_list = $provider->get_url_list( $page, $taxonomy );
				if ( ! is_array( $url_list ) ) {
					continue;
				}

				foreach ( $url_list as $entry ) {
					$loc = isset( $entry['loc'] ) ? (string) $entry['loc'] : '';
					if ( '' === $loc ) {
						continue;
					}

					$lastmod = isset( $entry['lastmod'] ) ? (string) $entry['lastmod'] : '';
					$this->write_row( $stdout, $loc, $lastmod, $taxonomy );
					++$count;
				}
			}
		}

		return $count;
	}

	/**
	 * Write URLs from an optional sitemap provider class.
	 *
	 * @param resource $stdout         Output stream.
	 * @param string   $provider_class Fully qualified provider class name.
	 * @param string   $source         Source column value.
	 * @return int Row count.
	 */
	private function export_provider( $stdout, string $provider_class, string $source ): int {
		if ( ! class_exists( $provider_class ) ) {
			WP_CLI::warning( sprintf( '%s is not available.', $provider_class ) );
			return 0;
		}

		$provider = new $provider_class();
		if ( ! method_exists( $provider, 'get_entries' ) ) {
			WP_CLI::warning( sprintf( '%s does not implement get_entries().', $provider_class ) );
			return 0;
		}

		$entries = $provider->get_entries();
		if ( ! is_array( $entries ) ) {
			return 0;
		}

		$count = 0;
		foreach ( $entries as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$loc = isset( $entry['loc'] ) ? (string) $entry['loc'] : '';
			if ( '' === $loc ) {
				continue;
			}

			$lastmod = isset( $entry['mod'] ) ? (string) $entry['mod'] : '';
			$this->write_row( $stdout, $loc, $lastmod, $source );
			++$count;
		}

		return $count;
	}

	/**
	 * Write one CSV row to the output stream.
	 *
	 * @param resource $stdout  Output stream.
	 * @param string   $url     Page URL.
	 * @param string   $lastmod Last modified value.
	 * @param string   $source  Sitemap source label.
	 */
	private function write_row( $stdout, string $url, string $lastmod, string $source ): void {
		// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- CSV is streamed to STDOUT, not a VIP filesystem path.
		fputcsv( $stdout, array( $url, $lastmod, $source ), ',', '"', '\\' );
	}
}

WP_CLI::add_command( 'prc sitemap export-urls', 'PRC_Sitemap_Export_URLs_Command' );
