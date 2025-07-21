<?php

// TODO: reduce some of the duplication between the CLI commands and the main class

WP_CLI::add_command( 'prc sitemap', 'PRC_Sitemap_CLI' );

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
	 * Generate full sitemap for site
	 *
	 * @subcommand generate-sitemap
	 */
	function generate_sitemap( $args, $assoc_args ) {
		$this->command = 'all';

		$all_years_with_posts = PRC_Sitemap::check_year_has_posts();

		$sitemap_args = array();
		foreach ( $all_years_with_posts as $year ) {
			if ( $this->halt_execution() ) {
				delete_option( 'prc_stop_processing' );
				break;
			}

			$sitemap_args['year'] = $year;
			$this->generate_sitemap_for_year( array(), $sitemap_args );
		}
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
				if ( 'year' === $this->command ) {
					delete_option( 'prc_stop_processing' );
				}

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
				if ( 'month' === $this->command ) {
					delete_option( 'prc_stop_processing' );
				}

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
		if ( PRC_Sitemap::date_range_has_posts( $date_stamp, $date_stamp ) ) {
			PRC_Sitemap::generate_sitemap_for_date( $date_stamp ); // TODO: simplify; this function should accept the year, month, day and translate accordingly
		} else {
			PRC_Sitemap::delete_sitemap_for_date( $date_stamp );
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

		update_option( 'prc_sitemap_indexed_url_count', $total_count, false );
		
		// Clear related caches after recount
		delete_transient( 'prc_sitemap_count' );
		delete_transient( 'prc_sitemap_recent_url_counts' );
		wp_cache_delete( 'sitemap_stats', PRC_Sitemap::CACHE_GROUP );
		
		WP_CLI::line( sprintf( 'Total posts found: %s', $total_count ) );
		WP_CLI::line( sprintf( 'Number of sitemaps found: %s', $sitemap_count ) );
	}

	/**
	 * Clear all sitemap caches
	 *
	 * @subcommand clear-cache
	 */
	public function clear_cache() {
		$deleted = PRC_Sitemap_Cache::invalidate_all_caches();
		WP_CLI::success( sprintf( 'Cleared %d cached items.', $deleted ) );
	}

	/**
	 * Show cache statistics
	 *
	 * @subcommand cache-stats
	 */
	public function cache_stats() {
		$stats = PRC_Sitemap_Cache::get_cache_stats();
		
		WP_CLI::line( sprintf( 'Transients: %d', $stats['transients'] ) );
		WP_CLI::line( sprintf( 'Timeouts: %d', $stats['timeouts'] ) );
		WP_CLI::line( sprintf( 'Total: %d', $stats['total'] ) );
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
	 * Clean up expired transients
	 *
	 * @subcommand cleanup-cache
	 */
	public function cleanup_cache() {
		$deleted = PRC_Sitemap_Cache::cleanup_expired_transients();
		WP_CLI::success( sprintf( 'Cleaned up %d expired cache items.', $deleted ) );
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
	 * Check if the user has flagged to bail on sitemap generation.
	 *
	 * Once `$this->halt` is set, we take advantage of PHP's boolean operator to stop querying the option in hopes of
	 * limiting database interaction.
	 *
	 * @return bool
	 */
	private function halt_execution() {
		if ( $this->halt || get_option( 'prc_stop_processing' ) ) {
			// Allow user to bail out of the current process, doesn't remove where the job got up to
			delete_option( 'prc_sitemap_create_in_progress' );
			$this->halt = true;
			return true;
		}

		return false;
	}
}
