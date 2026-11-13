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
	 * - Standard: All sitemap data (24 hours; invalidation hooks handle freshness)
	 * - Long: Static data that rarely changes
	 */
	const CACHE_TIMES = array(
		'short'    => 900,    // 15 minutes - Admin UI interactions
		'standard' => 86400,  // 24 hours - All sitemap data
		'long'     => 172800, // 48 hours - Static data
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
	 * Whether a version bump waits for the end of the request.
	 *
	 * @var bool
	 */
	private static $bump_queued = false;

	/**
	 * The current sitemap data version.
	 *
	 * @return string
	 */
	public static function version() {
		return md5( wp_cache_get_last_changed( self::CACHE_GROUP ) );
	}

	/**
	 * Name a cache entry after the current sitemap data version.
	 *
	 * A persistent object cache cannot delete transients by pattern, so a
	 * change calls bump() and every key named before it stops matching.
	 * Name the key before reading the data it holds, so data read before a
	 * bump never lands under the version that follows it.
	 *
	 * @param string $key Cache key without the version.
	 * @return string
	 */
	public static function versioned_key( $key ) {
		return $key . '_' . self::version();
	}

	/**
	 * Start a new sitemap data version.
	 *
	 * @return void
	 */
	public static function bump() {
		wp_cache_set_last_changed( self::CACHE_GROUP );
	}

	/**
	 * Start a new sitemap data version once, after the request has written everything it changes.
	 *
	 * A save runs several hooks, and a bulk edit saves many posts. Each one
	 * asks for a bump, and the version changes at shutdown, so a reader never
	 * caches old data under the new version between two writes.
	 *
	 * @return void
	 */
	public static function bump_once_per_request() {
		if ( self::$bump_queued ) {
			return;
		}
		self::$bump_queued = true;
		add_action( 'shutdown', array( __CLASS__, 'run_queued_bump' ) );
	}

	/**
	 * Run the bump that bump_once_per_request() queued.
	 *
	 * @return void
	 */
	public static function run_queued_bump() {
		if ( ! self::$bump_queued ) {
			return;
		}
		self::$bump_queued = false;
		self::bump();
	}

	/**
	 * Retire every cached sitemap entry.
	 *
	 * Every cache family names its keys with the version, so one bump retires
	 * them all. The entries stay in the cache until they expire (24 hours),
	 * and nothing reads them.
	 *
	 * @return array{previous: string, current: string} The version before and after.
	 */
	public static function invalidate_all_caches() {
		$previous = self::version();
		self::bump();

		return array(
			'previous' => $previous,
			'current'  => self::version(),
		);
	}

	/**
	 * Preload commonly used caches.
	 *
	 * @return void
	 */
	public static function preload_caches() {
		// Preload year range.
		PRC_Sitemap::get_post_year_range();

		// Preload years with posts.
		PRC_Sitemap::check_year_has_posts();

		// Preload sitemap count.
		PRC_Sitemap::count_sitemaps();

		// Preload recent URL counts.
		PRC_Sitemap::get_recent_sitemap_url_counts( 7 );
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
			$date = gmdate( 'Y-m-d', $current );

			// Pre-cache date checks.
			PRC_Sitemap::date_range_has_posts( $date, $date );

			// Pre-cache post IDs if date has posts.
			$post_ids = PRC_Sitemap::get_post_ids_for_date( $date );

			if ( ! empty( $post_ids ) ) {
				// Cache different limits too.
				PRC_Sitemap::get_post_ids_for_date( $date, 100 );
				PRC_Sitemap::get_post_ids_for_date( $date, 1000 );
			}
		}
	}
}
