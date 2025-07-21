<?php
/**
 * PRC Sitemap Cache Utility Class
 *
 * @package PRC\Sitemap
 */

/**
 * Advanced caching utilities for the PRC Sitemap plugin.
 */
class PRC_Sitemap_Cache {

	/**
	 * Cache group for sitemap-related caching.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'prc_sitemap';

	/**
	 * Simplified cache expiration times in seconds.
	 *
	 * Three-tier strategy optimized for low-frequency publishing:
	 * - Short: Admin UI interactions that need quick feedback
	 * - Standard: All sitemap data (12 hours works well for infrequent publishing)
	 * - Long: Static data that rarely changes
	 */
	const CACHE_TIMES = array(
		'short'    => 900,   // 15 minutes - Admin UI interactions
		'standard' => 43200, // 12 hours - All sitemap data
		'long'     => 86400, // 24 hours - Static data
	);

	/**
	 * Get cached data with fallback.
	 *
	 * @param string   $key Cache key.
	 * @param callable $callback Callback to generate data if not cached.
	 * @param string   $duration Cache duration key from CACHE_TIMES.
	 * @param string   $type Cache type ('transient' or 'object').
	 * @return mixed Cached data or callback result.
	 */
	public static function get_cached_data( $key, $callback, $duration = 'standard', $type = 'transient' ) {
		$cache_time = self::CACHE_TIMES[ $duration ] ?? self::CACHE_TIMES['standard'];

		if ( 'object' === $type ) {
			$cached_data = wp_cache_get( $key, self::CACHE_GROUP );
		} else {
			$cached_data = get_transient( $key );
		}

		if ( false !== $cached_data ) {
			return $cached_data;
		}

		$data = $callback();

		if ( 'object' === $type ) {
			wp_cache_set( $key, $data, self::CACHE_GROUP, $cache_time );
		} else {
			set_transient( $key, $data, $cache_time );
		}

		return $data;
	}

	/**
	 * Batch delete transients by pattern.
	 *
	 * @param string $pattern SQL LIKE pattern for transient names.
	 * @return int Number of transients deleted.
	 */
	public static function delete_transients_by_pattern( $pattern ) {
		global $wpdb;

		$pattern = $wpdb->esc_like( $pattern );

		// Delete transients and their timeout options.
		$deleted = $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
				"_transient_{$pattern}",
				"_transient_timeout_{$pattern}"
			)
		);

		return $deleted;
	}

	/**
	 * Invalidate date-specific caches.
	 *
	 * @param string $date Date in Y-m-d format.
	 * @return void
	 */
	public static function invalidate_date_caches( $date ) {
		list( $year, $month, $day ) = explode( '-', $date );

		delete_transient( "prc_sitemap_date_has_posts_{$date}" );
		delete_transient( "prc_sitemap_post_ids_{$date}" );
		delete_transient( "prc_sitemap_year_has_posts_{$year}" );

		// Clear any post IDs cache with different limits.
		self::delete_transients_by_pattern( "prc_sitemap_post_ids_{$date}_%" );
	}

	/**
	 * Invalidate year-specific caches.
	 *
	 * @param int $year The year.
	 * @return void
	 */
	public static function invalidate_year_caches( $year ) {
		delete_transient( "prc_sitemap_year_has_posts_{$year}" );
		self::delete_transients_by_pattern( "prc_sitemap_date_has_posts_{$year}-%" );
		self::delete_transients_by_pattern( "prc_sitemap_post_ids_{$year}-%" );
	}

	/**
	 * Invalidate all sitemap caches.
	 *
	 * @return void
	 */
	public static function invalidate_all_caches() {
		global $wpdb;

		// Delete all sitemap transients.
		$deleted = $wpdb->query(
			"DELETE FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_prc_sitemap_%'
			    OR option_name LIKE '_transient_timeout_prc_sitemap_%'
			    OR option_name LIKE '_transient_sitemap_ajax_stats_%'
			    OR option_name LIKE '_transient_timeout_sitemap_ajax_stats_%'"
		);

		// Clear object cache group
		wp_cache_flush_group( self::CACHE_GROUP );

		// Clear taxonomy caches
		wp_cache_delete_multiple(
			array(
				'sitemap_stats',
				'tax_lastmod_max_category',
				'tax_lastmod_max_post_tag',
			),
			self::CACHE_GROUP
		);

		return $deleted;
	}

	/**
	 * Get cache statistics.
	 *
	 * @return array Cache statistics.
	 */
	public static function get_cache_stats() {
		global $wpdb;

		$transient_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_prc_sitemap_%'"
		);

		$timeout_count = $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options}
			 WHERE option_name LIKE '_transient_timeout_prc_sitemap_%'"
		);

		return array(
			'transients' => intval( $transient_count ),
			'timeouts'   => intval( $timeout_count ),
			'total'      => intval( $transient_count ) + intval( $timeout_count ),
		);
	}

	/**
	 * Preload commonly used caches.
	 *
	 * @return void
	 */
	public static function preload_caches() {
		// Preload year range
		PRC_Sitemap::get_post_year_range();

		// Preload years with posts
		PRC_Sitemap::check_year_has_posts();

		// Preload sitemap count
		PRC_Sitemap::count_sitemaps();

		// Preload recent URL counts
		PRC_Sitemap::get_recent_sitemap_url_counts( 7 );
	}

	/**
	 * Clean up expired transients.
	 *
	 * @return int Number of expired transients cleaned up.
	 */
	public static function cleanup_expired_transients() {
		global $wpdb;

		$current_time = time();

		// Find expired timeout options
		$expired_timeouts = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT option_name FROM {$wpdb->options}
				 WHERE option_name LIKE '_transient_timeout_prc_sitemap_%'
				   AND option_value < %d",
				$current_time
			)
		);

		$deleted = 0;
		foreach ( $expired_timeouts as $timeout_option ) {
			$transient_option = str_replace( '_transient_timeout_', '_transient_', $timeout_option );

			// Delete both the transient and its timeout
			delete_option( $timeout_option );
			delete_option( $transient_option );
			$deleted += 2;
		}

		return $deleted;
	}

	/**
	 * Warm up caches for a specific date range.
	 *
	 * @param string $start_date Start date in Y-m-d format.
	 * @param string $end_date End date in Y-m-d format.
	 * @return void
	 */
	public static function warm_up_date_range( $start_date, $end_date ) {
		$start = strtotime( $start_date );
		$end   = strtotime( $end_date );

		for ( $current = $start; $current <= $end; $current = strtotime( '+1 day', $current ) ) {
			$date = date( 'Y-m-d', $current );

			// Pre-cache date checks
			PRC_Sitemap::date_range_has_posts( $date, $date );

			// Pre-cache post IDs if date has posts
			$post_ids = PRC_Sitemap::get_post_ids_for_date( $date );

			if ( ! empty( $post_ids ) ) {
				// Cache different limits too
				PRC_Sitemap::get_post_ids_for_date( $date, 100 );
				PRC_Sitemap::get_post_ids_for_date( $date, 1000 );
			}
		}
	}
}
