<?php
// phpcs:ignoreFile -- Pre-existing MSM-sitemap CLI. New commands load from includes/cli/.

// TODO: reduce some of the duplication between the CLI commands and the main class

WP_CLI::add_command( 'prc sitemap', 'PRC_Sitemap_CLI' );

require_once __DIR__ . '/class-prc-sitemap-xml-urls.php';
if ( ! class_exists( 'PRC_Sitemap_Builder_Cron' ) ) {
	require_once __DIR__ . '/prc-sitemap-builder-cron.php';
}
if ( class_exists( 'WPCOM_VIP_CLI_Command' ) ) {
	require_once __DIR__ . '/cli/class-prc-sitemap-export-urls-command.php';
	require_once __DIR__ . '/cli/class-prc-sitemap-dedupe-command.php';
}

class PRC_Sitemap_CLI extends WP_CLI_Command {
	/**
	 * @var string Type of command triggered so we can keep track of killswitch cleanup.
	 */
	private $command = '';

	/**
	 * @var bool Flag whether or not execution should be stopped.
	 */
	private $halt = false;

	/**
	 * @var bool Whether this process holds the lease of the full build.
	 */
	private $owns_build = false;

	/**
	 * @var string The lease token of the full build this process owns, or ''.
	 */
	private $build_token = '';

	/**
	 * Generate full sitemap for site
	 *
	 * Exits with an error while another full build holds the build lease.
	 *
	 * @subcommand generate-sitemap
	 */
	function generate_sitemap( $args, $assoc_args ) {
		$this->command = 'all';

		$this->build_token = PRC_Sitemap_Builder_Cron::begin_build();
		if ( '' === $this->build_token ) {
			WP_CLI::error( 'A full sitemap build is already running.' );
		}
		$this->owns_build = true;
		PRC_Sitemap_Builder_Cron::drop_walk_events();

		$all_years_with_posts = PRC_Sitemap::check_year_has_posts();

		$sitemap_args = array();
		foreach ( $all_years_with_posts as $year ) {
			if ( $this->halt_execution() ) {
				break;
			}

			$sitemap_args['year'] = $year;
			$this->generate_sitemap_for_year( array(), $sitemap_args );
		}

		$this->owns_build  = false;
		$this->build_token = '';
		if ( ! $this->halt ) {
			PRC_Sitemap_Builder_Cron::end_build();
		}
	}

	/**
	 * Show the state of the full build: idle, running, or halting.
	 *
	 * @subcommand build-state
	 */
	function build_state() {
		WP_CLI::line( PRC_Sitemap_Builder_Cron::get_build_state() );
	}

	/**
	 * Generate sitemap for a given year
	 *
	 * @subcommand generate-sitemap-for-year
	 */
	function generate_sitemap_for_year( $args, $assoc_args ) {
		if ( empty( $this->command ) ) {
			$this->command = 'year';
		}

		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'year' => false,
			)
		);

		$year = intval( $assoc_args['year'] );

		$valid = $this->validate_year( $year );
		if ( is_wp_error( $valid ) ) {
			WP_CLI::error( $valid->get_error_message() );
		}

		$enabled_post_types = PRC_Sitemap::get_supported_post_types();

		WP_CLI::line( sprintf( 'Generating sitemap for %s (%s)', $year, implode( ', ', $enabled_post_types ) ) );

		$max_month = 12;
		if ( date( 'Y' ) == $year ) {
			$max_month = date( 'n' );
		}

		$months = range( 1, $max_month );

		foreach ( $months as $month ) {
			if ( $this->halt_execution() ) {
				break;
			}

			$assoc_args['month'] = $month;
			$this->generate_sitemap_for_year_month( $args, $assoc_args );
		}
	}

	/**
	 * @subcommand generate-sitemap-for-year-month
	 */
	function generate_sitemap_for_year_month( $args, $assoc_args ) {
		if ( empty( $this->command ) ) {
			$this->command = 'month';
		}

		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'year'  => false,
				'month' => false,
			)
		);

		$year  = intval( $assoc_args['year'] );
		$month = intval( $assoc_args['month'] );

		$valid = $this->validate_year_month( $year, $month );
		if ( is_wp_error( $valid ) ) {
			WP_CLI::error( $valid->get_error_message() );
		}

		$enabled_post_types = PRC_Sitemap::get_supported_post_types();

		WP_CLI::line( sprintf( 'Generating sitemap for %s-%s (%s)', $year, $month, implode( ', ', $enabled_post_types ) ) );

		// Calculate actual number of days in the month since we don't have cal_days_in_month available
		if ( ! function_exists( 'cal_days_in_month' ) ) {
			$max_days = 31;
		} else {
			$max_days = cal_days_in_month( CAL_GREGORIAN, (int) $month, (int) $year );
		}

		if ( date( 'Y' ) == $year && date( 'n' ) == $month ) {
			$max_days = date( 'j' );
		}

		$days = range( 1, $max_days );

		foreach ( $days as $day ) {
			if ( $this->halt_execution() ) {
				break;
			}

			$assoc_args['day'] = $day;
			$this->generate_sitemap_for_year_month_day( $args, $assoc_args );
		}
	}


	/**
	 * @subcommand generate-sitemap-for-year-month-day
	 */
	function generate_sitemap_for_year_month_day( $args, $assoc_args ) {
		if ( empty( $this->command ) ) {
			$this->command = 'day';
		}

		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'year'  => false,
				'month' => false,
				'day'   => false,
			)
		);

		$year  = intval( $assoc_args['year'] );
		$month = intval( $assoc_args['month'] );
		$day   = intval( $assoc_args['day'] );

		$valid = $this->validate_year_month_day( $year, $month, $day );
		if ( is_wp_error( $valid ) ) {
			WP_CLI::error( $valid->get_error_message() );
		}

		$enabled_post_types = PRC_Sitemap::get_supported_post_types();

		WP_CLI::line( sprintf( 'Generating sitemap for %s-%s-%s (%s)', $year, $month, $day, implode( ', ', $enabled_post_types ) ) );

		$date_stamp = PRC_Sitemap::get_date_stamp( $year, $month, $day );
		$has_posts  = PRC_Sitemap::read_date_range_has_posts( $date_stamp, $date_stamp );
		if ( is_wp_error( $has_posts ) ) {
			WP_CLI::warning( sprintf( 'Skipped %s: a read failed, so its sitemap was kept (%s). Run the command again.', $date_stamp, $has_posts->get_error_message() ) );
			return;
		}

		if ( $has_posts ) {
			// TODO: simplify; this function should accept the year, month, day and translate accordingly.
			$built = PRC_Sitemap::generate_sitemap_for_date( $date_stamp );
			if ( is_wp_error( $built ) ) {
				WP_CLI::warning( sprintf( 'Skipped %s: a read failed, so its sitemap was kept (%s). Run the command again.', $date_stamp, $built->get_error_message() ) );
			} elseif ( ! $built ) {
				WP_CLI::warning( sprintf( 'Skipped %s: another build of this date is running. Run the command again when it ends.', $date_stamp ) );
			}
		} else {
			$deleted = PRC_Sitemap::delete_sitemap_for_date( $date_stamp );
			if ( is_wp_error( $deleted ) ) {
				WP_CLI::warning( sprintf( 'Skipped %s: a read failed, so its sitemap was kept (%s). Run the command again.', $date_stamp, $deleted->get_error_message() ) );
			} elseif ( null === $deleted ) {
				WP_CLI::warning( sprintf( 'Skipped %s: another build of this date is running. Run the command again when it ends.', $date_stamp ) );
			}
		}
	}

	private function validate_year( $year ) {
		if ( $year > date( 'Y' ) ) {
			return new WP_Error( 'prc-invalid-year', __( 'Please specify a valid year', 'prc-sitemap' ) );
		}

		return true;
	}

	private function validate_year_month( $year, $month ) {
		$valid_year = $this->validate_year( $year );
		if ( is_wp_error( $valid_year ) ) {
			return $valid_year;
		}

		if ( $month < 1 || $month > 12 ) {
			return new WP_Error( 'prc-invalid-month', __( 'Please specify a valid month', 'prc-sitemap' ) );
		}

		return true;
	}

	private function validate_year_month_day( $year, $month, $day ) {
		$valid_year_month = $this->validate_year_month( $year, $month );
		if ( is_wp_error( $valid_year_month ) ) {
			return $valid_year_month;
		}

		$date = strtotime( sprintf( '%d-%d-%d', $year, $month, $day ) );
		if ( false === $date ) {
			return new WP_Error( 'prc-invalid-day', __( 'Please specify a valid day', 'prc-sitemap' ) );
		}

		return true;
	}


	/**
	 * Generate (or regenerate) sitemaps for every day in a date range.
	 *
	 * Useful for backfilling dates that were affected by a bug, or for
	 * manually triggering a bulk rebuild after a data migration.
	 *
	 * ## OPTIONS
	 *
	 * [--start=<start-date>]
	 * : Start date (inclusive) in Y-m-d format. Defaults to 7 days ago.
	 *
	 * [--end=<end-date>]
	 * : End date (inclusive) in Y-m-d format. Defaults to today.
	 *
	 * [--clear-cache]
	 * : When set, invalidates date-specific transient caches before rebuilding each day.
	 *
	 * @subcommand generate-sitemap-for-date-range
	 * @synopsis [--start=<start-date>] [--end=<end-date>] [--clear-cache]
	 */
	public function generate_sitemap_for_date_range( $args, $assoc_args ) {
		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'start'       => date( 'Y-m-d', strtotime( '-7 days' ) ),
				'end'         => date( 'Y-m-d' ),
				'clear-cache' => false,
			)
		);

		$start_date  = $assoc_args['start'];
		$end_date    = $assoc_args['end'];
		$clear_cache = (bool) $assoc_args['clear-cache'];

		if ( ! strtotime( $start_date ) ) {
			WP_CLI::error( 'Invalid --start date. Use Y-m-d format.' );
		}
		if ( ! strtotime( $end_date ) ) {
			WP_CLI::error( 'Invalid --end date. Use Y-m-d format.' );
		}
		if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
			WP_CLI::error( '--start must be before or equal to --end.' );
		}

		$current = strtotime( $start_date );
		$end     = strtotime( $end_date );
		$now     = time();

		WP_CLI::line( sprintf( 'Generating sitemaps from %s to %s%s...', $start_date, $end_date, $clear_cache ? ' (clearing cache each day)' : '' ) );

		$generated = 0;
		$deleted   = 0;
		$skipped   = 0;

		while ( $current <= $end ) {
			if ( $this->halt_execution() ) {
				WP_CLI::warning( 'Halted: the full sitemap build is halting.' );
				break;
			}

			$date_stamp = date( 'Y-m-d', $current );

			// Skip future dates.
			if ( $current > $now ) {
				WP_CLI::debug( "Skipping future date {$date_stamp}." );
				++$skipped;
				$current = strtotime( '+1 day', $current );
				continue;
			}

			if ( $clear_cache ) {
				PRC_Sitemap_Cache::bump();
			}

			$has_posts = PRC_Sitemap::read_date_range_has_posts( $date_stamp, $date_stamp );
			if ( is_wp_error( $has_posts ) ) {
				WP_CLI::warning( "Skipped {$date_stamp}: a read failed, so its sitemap was kept ({$has_posts->get_error_message()})." );
				++$skipped;
			} elseif ( $has_posts ) {
				$built = PRC_Sitemap::generate_sitemap_for_date( $date_stamp );
				if ( is_wp_error( $built ) ) {
					WP_CLI::warning( "Skipped {$date_stamp}: a read failed, so its sitemap was kept ({$built->get_error_message()})." );
					++$skipped;
				} elseif ( $built ) {
					WP_CLI::line( "Generated: {$date_stamp}" );
					++$generated;
				} else {
					WP_CLI::warning( "Skipped {$date_stamp}: another build of this date is running." );
					++$skipped;
				}
			} else {
				$removed = PRC_Sitemap::delete_sitemap_for_date( $date_stamp );
				if ( is_wp_error( $removed ) ) {
					WP_CLI::warning( "Skipped {$date_stamp}: a read failed, so its sitemap was kept ({$removed->get_error_message()})." );
					++$skipped;
				} elseif ( null === $removed ) {
					WP_CLI::warning( "Skipped {$date_stamp}: another build of this date is running." );
					++$skipped;
				} else {
					WP_CLI::debug( "No posts on {$date_stamp} — sitemap deleted if it existed." );
					++$deleted;
				}
			}

			$current = strtotime( '+1 day', $current );
		}

		WP_CLI::success( sprintf(
			'Done. Generated: %d, Cleaned: %d, Skipped: %d.',
			$generated,
			$deleted,
			$skipped
		) );
	}

	/**
	 * @subcommand recount-indexed-posts
	 */
	public function recount_indexed_posts() {

		$all_sitemaps = get_posts(
			array(
				'post_type'        => PRC_Sitemap::SITEMAP_CPT,
				'post_status'      => 'publish',
				'fields'           => 'ids',
				'suppress_filters' => false,
				'posts_per_page'   => -1,
			)
		);

		$total_count   = 0;
		$sitemap_count = 0;

		foreach ( $all_sitemaps as $sitemap_id ) {

			$xml_data = get_post_meta( $sitemap_id, 'prc_sitemap_xml', true );

			$xml   = simplexml_load_string( $xml_data );
			$count = count( $xml->url );
			update_post_meta( $sitemap_id, 'prc_indexed_url_count', $count );

			$total_count   += $count;
			$sitemap_count += 1;
		}

		PRC_Sitemap_Cache::bump();

		WP_CLI::line( sprintf( 'Total posts found: %s', $total_count ) );
		WP_CLI::line( sprintf( 'Number of sitemaps found: %s', $sitemap_count ) );
	}

	/**
	 * Clear all sitemap caches
	 *
	 * @subcommand clear-cache
	 */
	public function clear_cache() {
		$versions = PRC_Sitemap_Cache::invalidate_all_caches();
		WP_CLI::success( sprintf( 'Retired every cached sitemap item. Cache version %s replaces %s.', substr( $versions['current'], 0, 8 ), substr( $versions['previous'], 0, 8 ) ) );
	}

	/**
	 * Show the current cache version
	 *
	 * @subcommand cache-stats
	 */
	public function cache_stats() {
		WP_CLI::line( sprintf( 'Cache version: %s', substr( PRC_Sitemap_Cache::version(), 0, 8 ) ) );
	}

	/**
	 * Preload commonly used caches
	 *
	 * @subcommand preload-cache
	 */
	public function preload_cache() {
		WP_CLI::line( 'Preloading sitemap caches...' );
		PRC_Sitemap_Cache::preload_caches();
		WP_CLI::success( 'Cache preloading completed.' );
	}

	/**
	 * Warm up caches for a date range
	 *
	 * @subcommand warm-cache
	 * @synopsis --start=<start-date> --end=<end-date>
	 */
	public function warm_cache( $args, $assoc_args ) {
		$assoc_args = wp_parse_args(
			$assoc_args,
			array(
				'start' => date( 'Y-m-d', strtotime( '-7 days' ) ),
				'end'   => date( 'Y-m-d' ),
			)
		);

		$start_date = $assoc_args['start'];
		$end_date   = $assoc_args['end'];

		// Validate dates
		if ( ! strtotime( $start_date ) || ! strtotime( $end_date ) ) {
			WP_CLI::error( 'Invalid date format. Use Y-m-d format.' );
		}

		if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
			WP_CLI::error( 'Start date must be before end date.' );
		}

		WP_CLI::line( sprintf( 'Warming up caches from %s to %s...', $start_date, $end_date ) );
		PRC_Sitemap_Cache::warm_up_date_range( $start_date, $end_date );
		WP_CLI::success( 'Cache warming completed.' );
	}

	/**
	 * Check if the build is halting, or lost its lease, so the command must stop.
	 *
	 * The full build owns the lease and renews it at each check. Every other
	 * command stops when a full build is halting. Once `$this->halt` is set the
	 * command stops without more reads.
	 *
	 * @return bool
	 */
	private function halt_execution() {
		if ( $this->halt ) {
			return true;
		}

		$may_continue = $this->owns_build ? PRC_Sitemap_Builder_Cron::walk_may_continue( $this->build_token ) : ! PRC_Sitemap_Builder_Cron::is_halting();
		if ( ! $may_continue ) {
			$this->halt = true;
			return true;
		}

		return false;
	}
}
