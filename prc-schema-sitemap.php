<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- WordPress loads the plugin by this file name.
/**
 * Plugin Name: PRC Schema - Sitemap
 * Description: A plugin for PRC Platform that generates comprehensive sitemaps for your WordPress site. Joint collaboration between Pew Research Center, Metro.co.uk, MAKE, Alley Interactive, and WordPress VIP.
 * Author: Artur Synowiec, Paul Kevan, Seth Rubenstein,and others
 * Version: 1.5.1
 * Stable tag: 1.5.1
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Requires Plugins: prc-shared
 *
 * @package PRC\Sitemap
 */

use PRC\Primitives\Concurrency\Lease;

// Load cache utility class.
require __DIR__ . '/includes/class-prc-sitemap-cache.php';

if ( defined( 'WP_CLI' ) && true === WP_CLI ) {
	require __DIR__ . '/includes/wp-cli.php';
}

/**
 * Sitemap class.
 *
 * @package PRC\Sitemap
 */
class PRC_Sitemap {
	/**
	 * The default number of posts per sitemap page.
	 *
	 * @var int
	 */
	const DEFAULT_POSTS_PER_SITEMAP_PAGE = 500;

	/**
	 * The sitemap custom post type.
	 *
	 * @var string
	 */
	const SITEMAP_CPT = 'prc_sitemap';

	/**
	 * Cache group for sitemap-related caching.
	 *
	 * @var string
	 */
	const CACHE_GROUP = 'prc_sitemap';

	/**
	 * Cron hooks the plugin schedules events on.
	 *
	 * @var string[]
	 */
	const CRON_HOOKS = array(
		'prc_cron_update_sitemap',
		'prc_cron_generate_sitemap_for_year',
		'prc_cron_generate_sitemap_for_year_month',
		'prc_cron_generate_sitemap_for_year_month_day',
	);

	/**
	 * Prefix of the lease row of one date: prc_sitemap_date_{Y-m-d}.
	 *
	 * @var string
	 */
	const DATE_LEASE_PREFIX = 'prc_sitemap_date_';

	/**
	 * Seconds one build of a date holds its lease. A build of one date takes seconds.
	 *
	 * @var int
	 */
	const DATE_LEASE_TTL = 300;

	/**
	 * Seconds the update window ends before time(). wp_insert_post() stamps
	 * post_modified_gmt before it writes the row, so a row can appear after a
	 * pass has read past its stamp.
	 *
	 * @var int
	 */
	const UPDATE_WINDOW_LAG = 60;

	/**
	 * The longest span of post_modified_gmt one pass reads, in seconds. After
	 * an outage the first pass reads this much and leaves the cursor at the
	 * end of it. The next passes read on.
	 *
	 * @var int
	 */
	const UPDATE_WINDOW_MAX = DAY_IN_SECONDS;

	/**
	 * Whether the sitemap is indexed by year.
	 *
	 * @var bool
	 */
	public static $index_by_year = false;

	/**
	 * Register actions for our hook
	 */
	public static function setup() {
		// How far apart should full cron generation events be spaced.
		define( 'PRC_INTERVAL_PER_GENERATION_EVENT', 60 );

		add_filter( 'cron_schedules', array( __CLASS__, 'sitemap_15_min_cron_interval' ) );

		// Filter to allow the sitemap to be indexed by year.
		self::$index_by_year = apply_filters( 'prc_sitemap_index_by_year', false );

		// Register default post type support.
		add_action( 'init', array( __CLASS__, 'register_default_post_type_support' ), 5 );

		// A cron schedule for creating/updating sitemap posts based on updated content since the last run.
		add_action( 'init', array( __CLASS__, 'sitemap_init' ) );
		add_action( 'admin_init', array( __CLASS__, 'sitemap_init_cron' ) );
		add_action( 'redirect_canonical', array( __CLASS__, 'disable_canonical_redirects_for_sitemap_xml' ), 10, 2 );
		add_action( 'init', array( __CLASS__, 'create_post_type' ) );
		add_filter( 'posts_pre_query', array( __CLASS__, 'disable_main_query_for_sitemap_xml' ), 10, 2 );
		add_filter( 'template_include', array( __CLASS__, 'load_sitemap_template' ) );

		// Run late so we don't conflict with other plugins.
		add_filter( 'prc_sitemap_entry', array( __CLASS__, 'add_entry_featured_image' ), 1, 2 );

		// Cache invalidation hooks.
		add_action( 'save_post', array( __CLASS__, 'invalidate_caches_on_post_change' ), 10, 2 );
		add_action( 'deleted_post', array( __CLASS__, 'invalidate_caches_on_post_change' ), 10, 2 );
		add_action( 'transition_post_status', array( __CLASS__, 'invalidate_caches_on_post_status_change' ), 10, 3 );

		// Changes that the update pass cannot see in post_modified_gmt.
		add_action( 'post_updated', array( __CLASS__, 'queue_dates_on_post_date_change' ), 10, 3 );
		add_action( 'transition_post_status', array( __CLASS__, 'queue_date_on_publish' ), 10, 3 );
		add_action( 'before_delete_post', array( __CLASS__, 'queue_date_on_hard_delete' ), 10, 2 );

		// Taxonomy cache invalidation hooks.
		add_action( 'created_term', array( __CLASS__, 'invalidate_caches_on_term_change' ), 10, 3 );
		add_action( 'edited_term', array( __CLASS__, 'invalidate_caches_on_term_change' ), 10, 3 );
		add_action( 'delete_term', array( __CLASS__, 'invalidate_caches_on_term_change' ), 10, 4 );

		// The daily cleanup of transient rows went away with the SQL pattern deletes.
		if ( wp_next_scheduled( 'prc_sitemap_cache_cleanup' ) ) {
			wp_unschedule_hook( 'prc_sitemap_cache_cleanup' );
		}

		// By default, we use wp-cron to help generate the full sitemap.
		// However, this will let us override it, if necessary.
		if ( true === apply_filters( 'prc_sitemap_use_cron_builder', true ) ) {
			require_once __DIR__ . '/includes/prc-sitemap-builder-cron.php';
			PRC_Sitemap_Builder_Cron::setup();
		}
	}

	/**
	 * Register 15 minute cron interval for latest articles
	 *
	 * @param array[] $schedules The schedules.
	 * @return array[] The modified schedules.
	 */
	public static function sitemap_15_min_cron_interval( $schedules ) {
		$schedules['ms-sitemap-15-min-cron-interval'] = array(
			'interval' => 900,
			'display'  => __( 'Every 15 minutes', 'prc-sitemaps' ),
		);
		return $schedules;
	}

	/**
	 * Register endpoint for sitemap and other hooks
	 */
	public static function sitemap_init() {
		define( 'WPCOM_SKIP_DEFAULT_SITEMAP', true );

		self::sitemap_rewrite_init();

		add_filter( 'robots_txt', array( __CLASS__, 'robots_txt' ), 10, 2 );
		add_action( 'admin_menu', array( __CLASS__, 'prc_sitemap_menu' ) );
		add_action( 'prc_cron_update_sitemap', array( __CLASS__, 'update_sitemap_from_modified_posts' ) );
		add_action( 'wp_ajax_prc-sitemap-get-sitemap-counts', array( __CLASS__, 'ajax_get_sitemap_counts' ) );
	}

	/**
	 * Setup rewrite rules for the sitemap
	 */
	public static function sitemap_rewrite_init() {
		// Allow 'sitemap=true' parameter.
		add_rewrite_tag( '%sitemap%', 'true' );
		add_rewrite_tag( '%sitemap-type%', '[a-zA-Z0-9_-]+' );
		add_rewrite_tag( '%sitemap-taxonomy%', '[a-zA-Z0-9_-]+' );
		add_rewrite_tag( '%paged%', '\d+' );

		// Define rewrite rules for the public post objectsindex based on the setup.
		if ( self::$index_by_year ) {
			add_rewrite_tag( '%sitemap-year%', '[0-9]{4}' );
			add_rewrite_rule( '^sitemap-posts-([a-zA-Z0-9_-]+).xml$', 'index.php?sitemap=true&sitemap-year=$matches[1]&sitemap-type=posts', 'top' );
		} else {
			// All public post objects fallback.
			add_rewrite_rule( '^sitemap-posts.xml$', 'index.php?sitemap=true&sitemap-type=posts', 'top' );
		}

		// Sitemap index.
		add_rewrite_rule( '^sitemap.xml$', 'index.php?sitemap=true&sitemap-type=index', 'top' );

		// News Sitemap.
		add_rewrite_rule( '^sitemap-news.xml$', 'index.php?sitemap=true&sitemap-type=news', 'top' );

		// RLS Sitemap.
		add_rewrite_rule( '^sitemap-rls.xml$', 'index.php?sitemap=true&sitemap-type=rls', 'top' );

		// Religious Projections Sitemap.
		add_rewrite_rule( '^sitemap-religious-projections.xml$', 'index.php?sitemap=true&sitemap-type=religious-projections', 'top' );

		// All public archive taxonomy terms index sitemap.
		add_rewrite_rule( '^sitemap-taxonomies.xml$', 'index.php?sitemap=true&sitemap-type=taxonomies-index', 'top' );
		// Public taxonomy terms sitemap.
		add_rewrite_rule( '^sitemap-terms-([a-zA-Z0-9_-]+).xml$', 'index.php?sitemap=true&sitemap-type=terms&sitemap-taxonomy=$matches[1]', 'top' );
	}

	/**
	 * Register admin menu for sitemap
	 */
	public static function prc_sitemap_menu() {
		$page_hook = add_management_page( __( 'Sitemap', 'prc-sitemaps' ), __( 'Sitemap', 'prc-sitemaps' ), 'manage_options', 'prc-sitemap', array( __CLASS__, 'render_sitemap_options_page' ) );
		add_action( 'admin_print_scripts-' . $page_hook, array( __CLASS__, 'add_admin_scripts' ) );
	}

	/**
	 * Add admin scripts.
	 */
	public static function add_admin_scripts() {
		wp_enqueue_script( 'flot', plugins_url( '/js/flot/jquery.flot.js', __FILE__ ), array( 'jquery' ) );
		wp_enqueue_script( 'prc-sitemap-admin', plugins_url( '/js/prc-sitemap-admin.js', __FILE__ ), array( 'jquery', 'flot' ) );
		wp_enqueue_script( 'flot-time', plugins_url( '/js/flot/jquery.flot.time.js', __FILE__ ), array( 'jquery', 'flot' ) );

		wp_enqueue_style( 'prc-sitemap-css', plugins_url( 'css/style.css', __FILE__ ) );
		wp_enqueue_style( 'noticons', '//s0.wordpress.com/i/noticons/noticons.css' );
	}

	/**
	 * AJAX callback to get sitemap counts.
	 */
	public static function ajax_get_sitemap_counts() {
		check_admin_referer( 'prc-sitemap-action' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'prc-sitemaps' ) );
		}

		$n = 10;
		if ( isset( $_REQUEST['num_days'] ) ) {
			$n = intval( $_REQUEST['num_days'] );
		}

		// Try to get cached stats first.
		$cache_key   = PRC_Sitemap_Cache::versioned_key( "sitemap_ajax_stats_{$n}" );
		$cached_data = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached_data ) {
			wp_send_json( $cached_data );
			return;
		}

		$data = array(
			'total_indexed_urls'   => number_format( self::get_total_indexed_url_count() ),
			'total_sitemaps'       => number_format( self::count_sitemaps() ),
			'sitemap_indexed_urls' => self::get_recent_sitemap_url_counts( $n ),
		);

		// Cache for 15 minutes (short duration for admin UI feedback).
		wp_cache_set( $cache_key, $data, self::CACHE_GROUP, PRC_Sitemap_Cache::CACHE_TIMES['short'] );

		wp_send_json( $data );
	}

	/**
	 * Render admin options page
	 */
	public static function render_sitemap_options_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'prc-sitemaps' ) );
		}

		// Array of possible user actions.
		$actions = apply_filters( 'prc_sitemap_actions', array() );

		// Start outputting HTML.
		echo '<div class="wrap">';
		echo '<h2>' . esc_html__( 'Sitemap', 'prc-sitemaps' ) . '</h2>';

		if ( ! self::is_blog_public() ) {
			self::show_action_message( __( 'Oops! Sitemaps are not supported on private blogs. Please make your blog public and try again.', 'prc-sitemaps' ), 'error' );
			echo '</div>';
			return;
		}

		if ( isset( $_POST['action'] ) ) {
			check_admin_referer( 'prc-sitemap-action' );

			// Handle cache actions.
			if ( isset( $_POST['cache_action'] ) ) {
				switch ( $_POST['cache_action'] ) {
					case 'clear':
						$versions = self::clear_all_caches();
						/* translators: 1: new cache version, 2: previous cache version. */
						self::show_action_message( sprintf( __( 'Retired every cached sitemap item. Cache version %1$s replaces %2$s.', 'prc-sitemaps' ), substr( $versions['current'], 0, 8 ), substr( $versions['previous'], 0, 8 ) ) );
						break;
					case 'preload':
						PRC_Sitemap_Cache::preload_caches();
						self::show_action_message( __( 'Cache preloading completed.', 'prc-sitemaps' ) );
						break;
				}
			} else {
				foreach ( $actions as $slug => $action ) {
					if ( $action['text'] !== $_POST['action'] ) {
						continue;
					}

					do_action( 'prc_sitemap_action-' . $slug );
					break;
				}
			}
		}

		// All the settings we need to read to display the page.
		$sitemap_update_last_run = get_option( 'prc_sitemap_update_last_run' );

		// Determine sitemap status text.
		$sitemap_create_status = apply_filters(
			'prc_sitemap_create_status',
			__( 'Not Running', 'prc-sitemaps' )
		);

		?>
		<div class="stats-container">
			<div class="stats-box"><strong id="sitemap-count"><?php echo number_format( self::count_sitemaps() ); ?></strong><?php esc_html_e( 'Sitemaps', 'prc-sitemaps' ); ?></div>
			<div class="stats-box"><strong id="sitemap-indexed-url-count"><?php echo number_format( self::get_total_indexed_url_count() ); ?></strong><?php esc_html_e( 'Indexed URLs', 'prc-sitemaps' ); ?></div>
			<div class="stats-footer"><span><span class="noticon noticon-time"></span><?php esc_html_e( 'Updated', 'prc-sitemaps' ); ?> <strong><?php echo esc_html( human_time_diff( $sitemap_update_last_run ) ); ?> <?php esc_html_e( 'ago', 'prc-sitemaps' ); ?></strong></span></div>
		</div>

		<h3><?php esc_html_e( 'Latest Sitemaps', 'prc-sitemaps' ); ?></h3>
		<div class="stats-container stats-placeholder"></div>
		<div id="stats-graph-summary">
		<?php
		printf(
			/* translators: 1: most URLs on one day, 2: that day, 3: number of days shown. */
			esc_html__( 'Max: %1$s on %2$s. Showing the last %3$s days.', 'prc-sitemaps' ),
			'<span id="stats-graph-max"></span>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- empty span that stats.js fills.
			'<span id="stats-graph-max-date"></span>', // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- empty span that stats.js fills.
			'<span id="stats-graph-num-days"></span>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- empty span that stats.js fills.
		);
		?>
		</div>

		<h3><?php esc_html_e( 'Generate', 'prc-sitemaps' ); ?></h3>
		<p><strong><?php esc_html_e( 'Sitemap Create Status:', 'prc-sitemaps' ); ?></strong> <?php echo esc_html( $sitemap_create_status ); ?></p>
		<form action="<?php echo esc_url( menu_page_url( 'prc-sitemap', false ) ); ?>" method="post" style="float: left;">
			<?php wp_nonce_field( 'prc-sitemap-action' ); ?>
			<?php
			foreach ( $actions as $action ) :
				if ( ! $action['enabled'] ) {
					continue;
				}
				?>
				<input type="submit" name="action" class="button-secondary" value="<?php echo esc_attr( $action['text'] ); ?>">
			<?php endforeach; ?>
		</form>

		<div style="clear: both;"></div>

		<h3><?php esc_html_e( 'Cache Management', 'prc-sitemaps' ); ?></h3>
		<p>
		<?php
		printf(
			/* translators: %s: current cache version. */
			esc_html__( 'Current cache version: %s', 'prc-sitemaps' ),
			esc_html( substr( PRC_Sitemap_Cache::version(), 0, 8 ) )
		);
		?>
		</p>

		<form action="<?php echo esc_url( menu_page_url( 'prc-sitemap', false ) ); ?>" method="post" style="margin-top: 10px;">
			<?php wp_nonce_field( 'prc-sitemap-action' ); ?>
			<input type="submit" name="action" class="button-secondary" value="<?php esc_attr_e( 'Clear All Caches', 'prc-sitemaps' ); ?>">
			<input type="hidden" name="cache_action" value="clear">
		</form>

		<form action="<?php echo esc_url( menu_page_url( 'prc-sitemap', false ) ); ?>" method="post" style="display: inline-block; margin-top: 5px;">
			<?php wp_nonce_field( 'prc-sitemap-action' ); ?>
			<input type="submit" name="action" class="button-secondary" value="<?php esc_attr_e( 'Preload Caches', 'prc-sitemaps' ); ?>">
			<input type="hidden" name="cache_action" value="preload">
		</form>

		<div style="margin-top: 15px;">
			<p><strong><?php esc_html_e( 'Simplified Cache Strategy:', 'prc-sitemaps' ); ?></strong></p>
			<ul style="margin-left: 20px;">
				<li><?php esc_html_e( 'Admin UI responses: 15 minutes', 'prc-sitemaps' ); ?></li>
				<li><?php esc_html_e( 'All sitemap data: 24 hours', 'prc-sitemaps' ); ?></li>
				<li><?php esc_html_e( 'Static data (year ranges, taxonomies): 48 hours', 'prc-sitemaps' ); ?></li>
				<li><?php esc_html_e( 'A change to a published post, a term, or a sitemap starts a new cache version, and entries from the old version are no longer read', 'prc-sitemaps' ); ?></li>
			</ul>
		</div>

		</div>
		<div id="tooltip"><strong class="content"></strong> <?php esc_html_e( 'indexed urls', 'prc-sitemaps' ); ?></div>
		<?php
	}

	/**
	 * Displays a notice, error or warning to the user
	 *
	 * @param string $message The message to show to the user.
	 * @param string $level The level of the message.
	 */
	public static function show_action_message( $message, $level = 'notice' ) {
		$class = 'updated';
		if ( 'warning' === $level ) {
			$class = 'update-nag';
		} elseif ( 'error' === $level ) {
			$class = 'error';
		}

		echo '<div class="' . esc_attr( $class ) . ' prc-sitemap-message"><p>' . wp_kses( $message, wp_kses_allowed_html( 'post' ) ) . '</p></div>';
	}

	/**
	 * Counts the number of sitemaps that have been generated.
	 *
	 * @return int The number of sitemaps that have been generated
	 */
	public static function count_sitemaps() {
		$cache_key    = PRC_Sitemap_Cache::versioned_key( 'prc_sitemap_count' );
		$cached_count = get_transient( $cache_key );

		if ( false !== $cached_count ) {
			return (int) $cached_count;
		}

		$count  = wp_count_posts( self::SITEMAP_CPT );
		$result = (int) $count->publish;

		set_transient( $cache_key, $result, PRC_Sitemap_Cache::CACHE_TIMES['standard'] );

		return $result;
	}

	/**
	 * Gets the current number of URLs indexed by prc-sitemap accross all sitemaps.
	 *
	 * The sum of the published sitemaps' prc_indexed_url_count, kept in the
	 * object cache under the sitemap data version. Every build and every delete
	 * starts a new version, so the next read sums again. A stored total would
	 * lose a change when two builds write it at once.
	 *
	 * A date that has two sitemap posts counts twice until one is removed.
	 *
	 * Only published sitemaps count. The old total counted every status, so on
	 * a site that holds draft sitemap posts the number steps down once at deploy.
	 *
	 * @return int The number of total number URLs indexed
	 */
	public static function get_total_indexed_url_count() {
		global $wpdb;

		$cache_key = PRC_Sitemap_Cache::versioned_key( 'prc_sitemap_indexed_url_total' );
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( false !== $cached ) {
			return (int) $cached;
		}

		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT SUM( pm.meta_value ) FROM $wpdb->postmeta pm INNER JOIN $wpdb->posts p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND p.post_type = %s AND p.post_status = 'publish'", 'prc_indexed_url_count', self::SITEMAP_CPT ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The result is cached below, under the version.
		wp_cache_set( $cache_key, $total, self::CACHE_GROUP, DAY_IN_SECONDS );

		return $total;
	}

	/**
	 * Returns the $n most recent sitemap indexed url counts.
	 *
	 * @param int $n The number of days of sitemap stats to grab.
	 * @return array An array of sitemap stats
	 */
	public static function get_recent_sitemap_url_counts( $n = 7 ) {
		$cache_key    = PRC_Sitemap_Cache::versioned_key( "prc_sitemap_recent_url_counts_{$n}" );
		$cached_stats = get_transient( $cache_key );

		if ( false !== $cached_stats ) {
			return $cached_stats;
		}

		$stats = array();

		for ( $i = 0; $i < $n; $i++ ) {
			$date = gmdate( 'Y-m-d', strtotime( "-$i days" ) );

			list( $year, $month, $day ) = explode( '-', $date );

			$stats[ $date ] = self::get_indexed_url_count( $year, $month, $day );
		}

		set_transient( $cache_key, $stats, PRC_Sitemap_Cache::CACHE_TIMES['standard'] );

		return $stats;
	}

	/**
	 * Checks if the blog is public.
	 *
	 * @return bool True if the blog is public, false otherwise.
	 */
	public static function is_blog_public() {
		if ( 'local' === wp_get_environment_type() ) {
			return true;
		}
		return ( 1 == get_option( 'blog_public' ) );
	}

	/**
	 * Gets the number of URLs indexed for the given sitemap.
	 *
	 * @param int $year The year.
	 * @param int $month The month.
	 * @param int $day The day.
	 * @return int|false The number of URLs indexed for the given sitemap, or false if the sitemap does not exist.
	 */
	public static function get_indexed_url_count( $year, $month, $day ) {
		$sitemap_id = self::get_sitemap_post_id( $year, $month, $day );

		if ( $sitemap_id ) {
			return intval( get_post_meta( $sitemap_id, 'prc_indexed_url_count', true ) );
		}

		return false;
	}

	/**
	 * Add entries to the bottom of robots.txt
	 *
	 * @param string $output The output to add to.
	 * @param int    $public The public status of the site.
	 * @return string The output to add to.
	 */
	public static function robots_txt( $output, $public ) {
		// Make sure the site isn't private.
		if ( '1' == $public ) {
			$output .= '# Sitemap archive' . PHP_EOL;

			if ( self::$index_by_year ) {
				$years = self::check_year_has_posts();
				foreach ( $years as $year ) {
					$output .= 'Sitemap: ' . home_url( '/sitemap-' . absint( $year ) . '.xml' ) . PHP_EOL;
				}

				$output .= PHP_EOL;
			} else {
				$output .= 'Sitemap: ' . home_url( '/sitemap.xml' ) . PHP_EOL;
				$output .= 'Sitemap: ' . home_url( '/sitemap-taxonomies.xml' ) . PHP_EOL;
				$output .= 'Sitemap: ' . home_url( '/sitemap-news.xml' ) . PHP_EOL;
				$output .= 'Sitemap: ' . home_url( '/sitemap-rls.xml' ) . PHP_EOL;
				$output .= 'Sitemap: ' . home_url( '/sitemap-religious-projections.xml' ) . PHP_EOL;
				$output .= PHP_EOL;
			}
		}
		return $output;
	}

	/**
	 * Add cron jobs required to generate these sitemaps
	 */
	public static function sitemap_init_cron() {
		if ( self::is_blog_public() && ! wp_next_scheduled( 'prc_cron_update_sitemap' ) ) {
			wp_schedule_event( time(), 'ms-sitemap-15-min-cron-interval', 'prc_cron_update_sitemap' );
		}
	}

	/**
	 * Disable canonical redirects for the sitemap files
	 *
	 * @hook redirect_canonical
	 *
	 * @see http://codex.wordpress.org/Function_Reference/redirect_canonical
	 * @param string $redirect_url The redirect URL.
	 * @param string $requested_url The requested URL.
	 * @return string URL to redirect.
	 */
	public static function disable_canonical_redirects_for_sitemap_xml( $redirect_url, $requested_url ) {
		if ( self::$index_by_year ) {
			$pattern = '|sitemap-([0-9]{4})\.xml|';
		} else {
			$pattern = '|sitemap(-[a-zA-Z0-9_-]+)*\.xml|';
		}

		if ( preg_match( $pattern, $requested_url ) ) {
			return $requested_url;
		}
		return $redirect_url;
	}

	/**
	 * Return range of years for posts in the database
	 *
	 * @return int[] Valid years.
	 */
	public static function get_post_year_range() {
		$cache_key    = PRC_Sitemap_Cache::versioned_key( 'prc_sitemap_post_year_range' );
		$cached_range = get_transient( $cache_key );

		if ( false !== $cached_range ) {
			return $cached_range;
		}

		global $wpdb;

		$oldest_post_date_year = $wpdb->get_var( "SELECT DISTINCT YEAR(post_date) as year FROM $wpdb->posts WHERE post_status = 'publish' ORDER BY year ASC LIMIT 1" );

		$result = array();
		if ( null !== $oldest_post_date_year ) {
			$current_year = gmdate( 'Y' );
			$result       = range( (int) $oldest_post_date_year, $current_year );
		}

		set_transient( $cache_key, $result, PRC_Sitemap_Cache::CACHE_TIMES['long'] );

		return $result;
	}

	/**
	 * Get every year that has valid posts in a range
	 *
	 * @return int[] Years with posts.
	 */
	public static function check_year_has_posts() {
		$cache_key    = PRC_Sitemap_Cache::versioned_key( 'prc_sitemap_years_with_posts' );
		$cached_years = get_transient( $cache_key );

		if ( false !== $cached_years ) {
			return $cached_years;
		}

		$all_years = self::get_post_year_range();

		$years_with_posts = array();
		foreach ( $all_years as $year ) {
			if ( self::year_has_posts( $year ) ) {
				$years_with_posts[] = $year;
			}
		}

		set_transient( $cache_key, $years_with_posts, PRC_Sitemap_Cache::CACHE_TIMES['standard'] );

		return $years_with_posts;
	}

	/**
	 * Check if a specific year has posts (optimized version).
	 *
	 * @param int $year The year to check.
	 * @return bool True if year has posts.
	 */
	public static function year_has_posts( $year ) {
		$cache_key     = PRC_Sitemap_Cache::versioned_key( "prc_sitemap_year_has_posts_{$year}" );
		$cached_result = get_transient( $cache_key );

		if ( false !== $cached_result ) {
			return (bool) $cached_result;
		}

		$result = self::date_range_has_posts(
			self::get_date_stamp( $year, 1, 1 ),
			self::get_date_stamp( $year, 12, 31 )
		);

		set_transient( $cache_key, $result ? 1 : 0, PRC_Sitemap_Cache::CACHE_TIMES['standard'] );

		return (bool) $result;
	}

	/**
	 * Get properly formatted data stamp from year, month, and day
	 *
	 * @param int $year The year.
	 * @param int $month The month.
	 * @param int $day The day.
	 * @return string formatted stamp
	 */
	public static function get_date_stamp( $year, $month, $day ) {
		return sprintf( '%s-%s-%s', $year, str_pad( $month, 2, '0', STR_PAD_LEFT ), str_pad( $day, 2, '0', STR_PAD_LEFT ) );
	}

	/**
	 * Does a current date range have posts?
	 *
	 * @param string $start_date The start date.
	 * @param string $end_date The end date.
	 * @param bool   $use_cache False to read the posts table, as the builder does.
	 * @return int|false
	 */
	public static function date_range_has_posts( $start_date, $end_date, $use_cache = true ) {
		$cache_key = $start_date === $end_date ? PRC_Sitemap_Cache::versioned_key( "prc_sitemap_date_has_posts_{$start_date}" ) : null;
		if ( $use_cache && $cache_key ) {
			$cached_result = get_transient( $cache_key );

			if ( false !== $cached_result ) {
				return '1' === $cached_result ? 1 : false;
			}
		}

		$result = self::read_date_range_has_posts( $start_date, $end_date );
		if ( is_wp_error( $result ) ) {
			// A failed read is not "no posts", so it is not cached.
			return false;
		}

		if ( $cache_key ) {
			set_transient( $cache_key, $result ? '1' : '0', PRC_Sitemap_Cache::CACHE_TIMES['standard'] );
		}

		return $result;
	}

	/**
	 * Read from the posts table whether a date range has a published post.
	 *
	 * @param string $start_date The start date, Y-m-d.
	 * @param string $end_date The end date, Y-m-d.
	 * @return string|null|WP_Error The ID of a post, null when the range has none, or a WP_Error when the read failed. A failed read returns null too, so a caller that decides to build or to delete must look at the error.
	 */
	public static function read_date_range_has_posts( $start_date, $end_date ) {
		global $wpdb;

		$start_date .= ' 00:00:00';
		$end_date   .= ' 23:59:59';

		$post_types_in = self::get_supported_post_types_in();
		$result        = $wpdb->get_var( $wpdb->prepare( "SELECT ID FROM $wpdb->posts WHERE post_status = 'publish' AND post_date >= %s AND post_date <= %s AND post_type IN ( {$post_types_in} ) LIMIT 1", $start_date, $end_date ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $post_types_in joins $wpdb->prepare( '%s' ) values.
		if ( '' !== (string) $wpdb->last_error ) {
			return new WP_Error(
				'prc_sitemap_post_read_failed',
				sprintf( 'The posts of %s to %s could not be read (%s).', substr( $start_date, 0, 10 ), substr( $end_date, 0, 10 ), (string) $wpdb->last_error ),
				array( 'date' => substr( $start_date, 0, 10 ) )
			);
		}

		return $result;
	}

	/**
	 * Get a list of support post_type IDs for a given date
	 *
	 * @param string $sitemap_date Date in Y-m-d format.
	 * @param int    $limit Number of post IDs to return.
	 * @return array IDs of posts.
	 */
	public static function get_post_ids_for_date( $sitemap_date, $limit = 500 ) {
		$cache_key  = PRC_Sitemap_Cache::versioned_key( "prc_sitemap_post_ids_{$sitemap_date}_{$limit}" );
		$cached_ids = get_transient( $cache_key );

		if ( false !== $cached_ids ) {
			return $cached_ids;
		}

		$result = self::query_post_ids_for_date( $sitemap_date, $limit );
		if ( is_wp_error( $result ) ) {
			// A failed read is not "no posts", so it is not cached.
			return array();
		}

		set_transient( $cache_key, $result, PRC_Sitemap_Cache::CACHE_TIMES['standard'] );

		return $result;
	}

	/**
	 * Read the published post IDs for a date from the posts table.
	 *
	 * @param string $sitemap_date Date in Y-m-d format.
	 * @param int    $limit Number of post IDs to return.
	 * @return int[]|WP_Error IDs of posts. A WP_Error when the read failed: a failed query returns no rows, like a date with no posts, and a caller must not read it as one.
	 */
	public static function query_post_ids_for_date( $sitemap_date, $limit = 500 ) {
		global $wpdb;

		$start_date    = $sitemap_date . ' 00:00:00';
		$end_date      = $sitemap_date . ' 23:59:59';
		$post_types_in = self::get_supported_post_types_in();

		$posts = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date FROM $wpdb->posts WHERE post_status = 'publish' AND post_date >= %s AND post_date <= %s AND post_type IN ( {$post_types_in} ) ORDER BY post_date DESC LIMIT %d", $start_date, $end_date, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $post_types_in joins $wpdb->prepare( '%s' ) values.

		if ( ! is_array( $posts ) || '' !== (string) $wpdb->last_error ) {
			return new WP_Error(
				'prc_sitemap_post_read_failed',
				sprintf( 'The posts of %s could not be read (%s).', $sitemap_date, (string) $wpdb->last_error ),
				array( 'date' => $sitemap_date )
			);
		}

		usort( $posts, array( __CLASS__, 'order_by_post_date' ) );

		$post_ids = wp_list_pluck( $posts, 'ID' );

		return array_map( 'intval', $post_ids );
	}

	/**
	 * The lease that one build of a date holds.
	 *
	 * @return Lease
	 */
	public static function date_lease() {
		return new Lease( self::DATE_LEASE_PREFIX, self::DATE_LEASE_TTL, 'date_' );
	}

	/**
	 * Generate sitemap for a date.
	 *
	 * One build of a date runs at a time. The build takes the lease of the date
	 * first, then looks for the sitemap post of the date, so two builds never
	 * both find none and both insert one. A build that finds the lease held
	 * does nothing and returns false. The caller decides when to try again.
	 *
	 * @param string $sitemap_date The date to generate the sitemap for.
	 * @return bool|WP_Error True when this call built the date. False when another build of the date holds the lease. A WP_Error when a read of the date failed: the sitemap of the date is left as it was, and the caller builds the date again later.
	 */
	public static function generate_sitemap_for_date( $sitemap_date ) {
		$lease = self::date_lease();
		$token = $lease->acquire( $sitemap_date );
		if ( '' === $token ) {
			return false;
		}

		try {
			$written = self::write_sitemap_for_date( $sitemap_date );
		} finally {
			$lease->release( $sitemap_date, $token );
		}

		return is_wp_error( $written ) ? $written : true;
	}

	/**
	 * Find the sitemap post of a date by post_name.
	 *
	 * WordPress renames a trashed post to `{post_name}__trashed`, so a lookup of
	 * the plain name misses it and the build would insert a second post for the
	 * date. The lookup reads both names. A live post wins, the lowest ID first,
	 * and a trashed post comes back to `publish` when no live one exists.
	 * Callers hold the lease of the date.
	 *
	 * @param string $sitemap_name The date, which is the post_name of its sitemap post.
	 * @return int|WP_Error The post ID, or 0 when the date has none. A WP_Error when the read failed: a failed read is not "none", and a build that took it for none would insert a second post for the date.
	 */
	private static function find_sitemap_post_id( $sitemap_name ) {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_status FROM $wpdb->posts WHERE post_type = %s AND post_name IN ( %s, %s ) ORDER BY ID ASC", self::SITEMAP_CPT, $sitemap_name, $sitemap_name . '__trashed' ) );
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			return new WP_Error(
				'prc_sitemap_post_read_failed',
				sprintf( 'The sitemap post of %s could not be looked up (%s).', $sitemap_name, (string) $wpdb->last_error ),
				array( 'date' => $sitemap_name )
			);
		}
		if ( empty( $rows ) ) {
			return 0;
		}

		foreach ( $rows as $row ) {
			if ( 'trash' !== $row->post_status ) {
				return (int) $row->ID;
			}
		}

		$trashed_id = (int) $rows[0]->ID;
		$publish    = static function () {
			return 'publish';
		};
		add_filter( 'wp_untrash_post_status', $publish );
		wp_untrash_post( $trashed_id );
		remove_filter( 'wp_untrash_post_status', $publish );

		return $trashed_id;
	}

	/**
	 * Write the sitemap post of a date; this is where XML is rendered.
	 *
	 * @param string $sitemap_date The date to generate the sitemap for.
	 * @return true|WP_Error A WP_Error when a read failed. A sitemap post that was there stays as it was. A post that this call inserted is removed.
	 */
	private static function write_sitemap_for_date( $sitemap_date ) {
		list( $year, $month, $day ) = explode( '-', $sitemap_date );

		$sitemap_name = $sitemap_date;

		$sitemap_data = array(
			'post_name'   => $sitemap_name,
			'post_title'  => $sitemap_name,
			'post_type'   => self::SITEMAP_CPT,
			'post_status' => 'publish',
			'post_date'   => $sitemap_date,
		);

		$sitemap_id = self::find_sitemap_post_id( $sitemap_name );
		if ( is_wp_error( $sitemap_id ) ) {
			return $sitemap_id;
		}

		$inserted = ! $sitemap_id;
		if ( $inserted ) {
			$sitemap_id = wp_insert_post( $sitemap_data );
		}

		$per_page = apply_filters( 'prc_sitemap_entry_posts_per_page', self::DEFAULT_POSTS_PER_SITEMAP_PAGE );
		$post_ids = self::query_post_ids_for_date( $sitemap_date, $per_page );
		if ( is_wp_error( $post_ids ) ) {
			if ( $inserted ) {
				// This call made the post and it has no XML yet, so the index would list a date with nothing behind it.
				self::delete_sitemap_by_id( $sitemap_id );
			}
			return $post_ids;
		}

		if ( empty( $post_ids ) ) {
			// If no entries - delete the whole sitemap post.
			self::delete_sitemap_by_id( $sitemap_id );
			return true;
		}

		// SimpleXML doesn't allow us to define namespaces using addAttribute, so we need to specify them in the construction instead.
		$namespaces = apply_filters(
			'prc_sitemap_namespace',
			array(
				'xmlns:xsi'          => 'http://www.w3.org/2001/XMLSchema-instance',
				'xmlns'              => 'http://www.sitemaps.org/schemas/sitemap/0.9',
				'xmlns:n'            => 'http://www.google.com/schemas/sitemap-news/0.9',
				'xmlns:image'        => 'http://www.google.com/schemas/sitemap-image/1.1',
				'xmlns:xhtml'        => 'http://www.w3.org/1999/xhtml',
				'xsi:schemaLocation' => 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd',
			)
		);

		$namespace_str = '<?xml version="1.0" encoding="utf-8"?><urlset';
		foreach ( $namespaces as $ns => $value ) {
			$namespace_str .= sprintf( ' %s="%s"', esc_attr( $ns ), esc_attr( $value ) );
		}
		$namespace_str .= '></urlset>';

		// Create XML.
		$xml = new SimpleXMLElement( $namespace_str );

		$url_count = 0;
		foreach ( $post_ids as $post_id ) {
			$GLOBALS['post'] = get_post( $post_id ); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- get_permalink() and the prc_sitemap_entry filters read the global post; wp_reset_postdata() restores it.
			setup_postdata( $GLOBALS['post'] );

			if ( apply_filters( 'prc_sitemap_skip_post', false, $post_id ) ) {
				continue;
			}

			$url = $xml->addChild( 'url' );
			$url->addChild( 'loc', esc_url( get_permalink() ) );
			$url->addChild( 'lastmod', get_post_modified_time( 'c', true ) );
			$url->addChild( 'changefreq', 'monthly' );
			$url->addChild( 'priority', '0.7' );

			$url = apply_filters( 'prc_sitemap_entry', $url, $post_id );

			++$url_count;
		}

		update_post_meta( $sitemap_id, 'prc_sitemap_xml', $xml->asXML() );
		update_post_meta( $sitemap_id, 'prc_indexed_url_count', $url_count );
		do_action( 'prc_update_sitemap_post', $sitemap_id, $year, $month, $day );
		PRC_Sitemap_Cache::bump();

		wp_reset_postdata();

		return true;
	}

	/**
	 * Delete the sitemap of a date that has no posts left.
	 *
	 * The delete takes the lease of the date first, as a build does. Without
	 * it, a delete that read "no posts" could run after a build that had read
	 * the posts, and a deleted URL would stay in the sitemap, or a date that
	 * gained a post would lose its sitemap. Under the lease the date reads its
	 * posts again: when it has gained one, the call builds the date instead.
	 *
	 * A failed read is not "no posts". When the lookup of the sitemap post or the read of the posts
	 * fails, the sitemap stays, the lease goes back, and the call returns a WP_Error so that the caller
	 * queues the date again.
	 *
	 * @param string $sitemap_date The date to delete the sitemap for.
	 * @return bool|null|WP_Error True if the sitemap was deleted, false if there was nothing to delete, null while a build of the date holds the lease. A WP_Error when a read failed and nothing was deleted. The caller queues the date again on null or on a WP_Error.
	 */
	public static function delete_sitemap_for_date( $sitemap_date ) {
		global $wpdb;

		list( $year, $month, $day ) = explode( '-', $sitemap_date );
		$wpdb->last_error           = '';
		$sitemap_id                 = self::get_sitemap_post_id( $year, $month, $day );
		if ( ! $sitemap_id ) {
			if ( '' !== (string) $wpdb->last_error ) {
				return new WP_Error(
					'prc_sitemap_post_read_failed',
					sprintf( 'The sitemap post of %s could not be looked up (%s).', $sitemap_date, (string) $wpdb->last_error ),
					array( 'date' => $sitemap_date )
				);
			}
			return false;
		}

		$lease = self::date_lease();
		$token = $lease->acquire( $sitemap_date );
		if ( '' === $token ) {
			return null;
		}

		try {
			$post_ids = self::query_post_ids_for_date( $sitemap_date, 1 );
			if ( is_wp_error( $post_ids ) ) {
				return $post_ids;
			}
			if ( ! empty( $post_ids ) ) {
				$written = self::write_sitemap_for_date( $sitemap_date );
				return is_wp_error( $written ) ? $written : false;
			}
			return self::delete_sitemap_by_id( $sitemap_id );
		} finally {
			$lease->release( $sitemap_date, $token );
		}
	}

	/**
	 * Delete a sitemap by ID
	 *
	 * The caller holds the lease of the sitemap's date.
	 *
	 * @param int $sitemap_id The ID of the sitemap to delete.
	 * @return bool True if the sitemap was deleted, false otherwise.
	 */
	public static function delete_sitemap_by_id( $sitemap_id ) {
		$sitemap = get_post( $sitemap_id );
		if ( ! $sitemap ) {
			return false;
		}

		$sitemap_date               = gmdate( 'Y-m-d', strtotime( $sitemap->post_date ) );
		list( $year, $month, $day ) = explode( '-', $sitemap_date );

		wp_delete_post( $sitemap_id, true );
		PRC_Sitemap_Cache::bump();
		do_action( 'prc_delete_sitemap_post', $sitemap_id, $year, $month, $day );

		return true;
	}

	/**
	 * Register our CPT
	 */
	public static function create_post_type() {
		register_post_type(
			self::SITEMAP_CPT,
			array(
				'labels'       => array(
					'name'          => __( 'Sitemaps' ),
					'singular_name' => __( 'Sitemap' ),
				),
				'public'       => false,
				'has_archive'  => false,
				'rewrite'      => false,
				'show_ui'      => true,
				'show_in_menu' => false, // Since we're manually adding a Sitemaps menu, no need to auto-add one through the CPT.
				'supports'     => array(
					'title',
				),
			)
		);
	}

	/**
	 * Where the next update window starts.
	 *
	 * The cursor is the end of the last window the pass read, so the next
	 * window is [cursor, end). It supersedes prc_sitemap_update_last_run, which
	 * stays only as the start of the first window and as the "Updated ... ago"
	 * time of the admin page.
	 *
	 * @return string GMT datetime.
	 */
	public static function get_update_cursor() {
		$cursor = get_option( 'prc_sitemap_update_cursor', false );
		if ( is_string( $cursor ) && '' !== $cursor ) {
			return $cursor;
		}

		$last_run = get_option( 'prc_sitemap_update_last_run', false );
		return gmdate( 'Y-m-d H:i:s', $last_run ? (int) $last_run : time() - 3600 );
	}

	/**
	 * Where the update window that starts at $start ends, exclusive.
	 *
	 * The end is UPDATE_WINDOW_LAG seconds before now, and never more than
	 * UPDATE_WINDOW_MAX after the start.
	 *
	 * @param string|null $start GMT datetime. Defaults to the stored cursor.
	 * @return string GMT datetime.
	 */
	public static function get_update_window_end( $start = null ) {
		$start = $start ?? self::get_update_cursor();
		$end   = min( time() - self::UPDATE_WINDOW_LAG, strtotime( $start . ' UTC' ) + self::UPDATE_WINDOW_MAX );
		return gmdate( 'Y-m-d H:i:s', $end );
	}

	/**
	 * Every post changed in the window [$start, $end), in one scan.
	 *
	 * The query has no LIMIT and no ORDER BY. post_modified_gmt has no index, so
	 * a second scan or a sort would cost as much as the first read. A post whose
	 * date is floating (never set) is not in any sitemap and is skipped.
	 *
	 * @param string|null $start GMT datetime. Defaults to the stored cursor.
	 * @param string|null $end   GMT datetime. Defaults to the window end now.
	 * @return object[] The modified posts: ID, post_date.
	 */
	public static function get_last_modified_posts( $start = null, $end = null ) {
		global $wpdb;

		$start         = $start ?? self::get_update_cursor();
		$end           = $end ?? self::get_update_window_end( $start );
		$post_types_in = self::get_supported_post_types_in();

		return $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date FROM $wpdb->posts WHERE post_type IN ( {$post_types_in} ) AND post_modified_gmt >= %s AND post_modified_gmt < %s AND post_date_gmt != '0000-00-00 00:00:00'", $start, $end ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $post_types_in joins $wpdb->prepare( '%s' ) values.
	}

	/**
	 * Get dates for an array of posts
	 *
	 * A post with a floating date (post_date_gmt of zero) has no place in a
	 * date sitemap, so it adds no date.
	 *
	 * @param object[] $posts The posts to get the dates for.
	 * @return string[] unique dates of each post.
	 */
	public static function get_post_dates( $posts ) {
		$dates = array();
		foreach ( $posts as $post ) {
			if ( isset( $post->post_date_gmt ) && '0000-00-00 00:00:00' === $post->post_date_gmt ) {
				continue;
			}
			$dates[] = gmdate( 'Y-m-d', strtotime( $post->post_date ) );
		}
		$dates = array_unique( $dates );

		return $dates;
	}

	/**
	 * Update the sitemap with changes from recently modified posts.
	 *
	 * The pass reads the window [cursor, end) in one scan, queues the date of
	 * every post it read, and only then moves the cursor to the end. A post
	 * saved while the pass runs has a stamp at or after the end, so the next
	 * pass reads it. A scan that fails moves nothing: it returns no rows, and
	 * an empty window would hide the changes the pass never read.
	 *
	 * An admin who asks for the latest articles sets $through_lag. That pass
	 * reads up to now, because the editor's save is already committed. It still
	 * stores the lagged end as the cursor, so a row that was not committed yet
	 * is read by the next cron pass. A date that two passes queue is one event.
	 *
	 * @param bool $through_lag Read the posts inside the lag too.
	 * @return int The number of dates queued.
	 */
	public static function update_sitemap_from_modified_posts( $through_lag = false ) {
		global $wpdb;

		$start    = self::get_update_cursor();
		$end      = self::get_update_window_end( $start );
		$read_end = $end;
		if ( $through_lag ) {
			$read_end = gmdate( 'Y-m-d H:i:s', min( time() + 1, strtotime( $start . ' UTC' ) + self::UPDATE_WINDOW_MAX ) );
		}
		if ( $read_end <= $start ) {
			return 0;
		}

		$modified = self::get_last_modified_posts( $start, $read_end );
		if ( ! empty( $wpdb->last_error ) ) {
			// A failed query returns no rows, like an empty window. Leave the cursor so the next pass reads it.
			return 0;
		}

		$queued = self::queue_date_updates( self::get_post_dates( $modified ), time() );

		if ( $end > $start ) {
			update_option( 'prc_sitemap_update_cursor', $end, false );
		}
		update_option( 'prc_sitemap_update_last_run', time(), false );

		return $queued;
	}

	/**
	 * Queue a rebuild of each date, one generation interval apart.
	 *
	 * @param string[] $dates Dates as Y-m-d.
	 * @param int      $time  The time of the event before the first one.
	 * @return int The number of dates queued.
	 */
	public static function queue_date_updates( $dates, $time ) {
		$today  = wp_date( 'Y-m-d' );
		$queued = 0;
		foreach ( $dates as $date ) {
			list( $year, $month, $day ) = explode( '-', $date );

			// Do not allow non-existant or future dates to be queued. A post date is in the site's time zone, so today is too.
			if ( false === checkdate( (int) $month, (int) $day, (int) $year ) || $date > $today ) {
				continue;
			}

			$time += PRC_INTERVAL_PER_GENERATION_EVENT;

			do_action( 'prc_update_sitemap_for_year_month_date', array( $year, $month, $day ), $time );
			++$queued;
		}
		return $queued;
	}

	/**
	 * Rebuild the old and the new date when a post date changes.
	 *
	 * The update pass sees only the new post_date, so the old date's sitemap
	 * would keep the URL. This runs for any status: a post unpublished and
	 * then moved as a draft before the next pass is still listed on its old
	 * date.
	 *
	 * @param int     $post_id     The post ID.
	 * @param WP_Post $post_after  The post after the update.
	 * @param WP_Post $post_before The post before the update.
	 */
	public static function queue_dates_on_post_date_change( $post_id, $post_after, $post_before ) {
		if ( ! in_array( $post_after->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		$dates = self::get_post_dates( array( $post_before, $post_after ) );
		if ( count( $dates ) > 1 ) {
			self::queue_date_updates( $dates, time() );
		}
	}

	/**
	 * Rebuild the date of a post when it goes live.
	 *
	 * Any move into publish: a scheduled post that cron publishes with
	 * wp_publish_post(), which changes only post_status, and a post inserted
	 * with a past post_date (the REST `date` field, `wp post create
	 * --post_date`, importers), which core stamps with post_modified_gmt =
	 * post_date. Both stamps are older than the window, so the update pass
	 * does not select the post.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post       Post object.
	 */
	public static function queue_date_on_publish( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status || 'publish' === $old_status ) {
			return;
		}
		if ( in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			self::queue_date_updates( self::get_post_dates( array( $post ) ), time() );
		}
	}

	/**
	 * Rebuild the date of a published post that is deleted for good.
	 *
	 * A hard delete leaves no row for the update pass to read, so the date's
	 * sitemap would keep the URL. A post that was published and then trashed
	 * counts too: core keeps its earlier status in _wp_trash_meta_status. The
	 * event runs one generation interval later, after the row is gone, and the
	 * rebuild takes the lease of the date.
	 *
	 * @param int          $post_id The post ID.
	 * @param WP_Post|null $post    The post. Core passes it since 5.5.
	 */
	public static function queue_date_on_hard_delete( $post_id, $post = null ) {
		$post = $post ?? get_post( $post_id );
		if ( ! $post || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		$was_published = 'publish' === $post->post_status;
		if ( 'trash' === $post->post_status ) {
			$was_published = 'publish' === get_post_meta( $post->ID, '_wp_trash_meta_status', true );
		}
		if ( ! $was_published ) {
			return;
		}

		self::queue_date_updates( self::get_post_dates( array( $post ) ), time() );
	}

	/**
	 * Trigger rendering of the actual sitemap
	 *
	 * @param string $template The template to load.
	 * @return string The template to load.
	 */
	public static function load_sitemap_template( $template ) {
		$sitemap_type = get_query_var( 'sitemap-type' );
		if ( $sitemap_type ) {
			// Prevent WordPress from treating this as a 404.
			global $wp_query;
			$wp_query->is_404 = false;
			status_header( 200 );

			$template = __DIR__ . "/templates/sitemap-{$sitemap_type}.php";
			// Check if the template exists.
			if ( ! file_exists( $template ) ) {
				$template = __DIR__ . '/templates/sitemap-posts.php';
			}
		}
		return $template;
	}

	/**
	 * Disable Main Query when rendering sitemaps
	 *
	 * @param array|null $posts array of post data or null.
	 * @param WP_Query   $query The WP_Query instance.
	 */
	public static function disable_main_query_for_sitemap_xml( $posts, $query ) {
		if ( $query->is_main_query() && isset( $query->query_vars['sitemap'] ) && 'true' === $query->query_vars['sitemap'] ) {
			$posts = array();
		}
		if ( $query->is_main_query() && isset( $query->query_vars['news-sitemap'] ) && 'true' === $query->query_vars['news-sitemap'] ) {
			$posts = array();
		}
		return $posts;
	}

	/**
	 * Build Root sitemap XML
	 * Can be all days (default) or a specific year.
	 *
	 * @param int|boolean $year The year to build the sitemap for.
	 */
	public static function build_root_sitemap_xml( $year = false ) {
		$cache_key  = PRC_Sitemap_Cache::versioned_key( 'prc_sitemap_root_xml_' . md5( serialize( array( 'year' => $year ) ) ) );
		$cached_xml = get_transient( $cache_key );

		if ( false !== $cached_xml ) {
			return $cached_xml;
		}

		$xml_prefix = '<?xml version="1.0" encoding="utf-8"?>';
		global $wpdb;

		// Direct query because we just want dates of the sitemap entries and this is much faster than WP_Query.
		if ( is_numeric( $year ) ) {
			$query = $wpdb->prepare( "SELECT post_date FROM $wpdb->posts WHERE post_type = %s AND YEAR(post_date) = %s ORDER BY post_date DESC LIMIT 10000", self::SITEMAP_CPT, $year );
		} else {
			$query = $wpdb->prepare( "SELECT post_date FROM $wpdb->posts WHERE post_type = %s ORDER BY post_date DESC LIMIT 10000", self::SITEMAP_CPT );
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$sitemaps = $wpdb->get_col( $query );

		// Sometimes duplicate sitemaps exist, lets make sure so they are not output.
		$sitemaps = array_unique( $sitemaps );

		/**
		 * Filter daily sitemaps from the index by date.
		 *
		 * Expects an array of dates in MySQL DATETIME format [ Y-m-d H:i:s ].
		 *
		 * Since adding dates that do not have posts is pointless, this filter is primarily intended for removing
		 * dates before or after a specific date or possibly targeting specific dates to exclude.
		 *
		 * @since 1.4.0
		 *
		 * @param array  $sitemaps Array of dates in MySQL DATETIME format [ Y-m-d H:i:s ].
		 * @param string $year     Year that sitemap is being generated for.
		 */
		$sitemaps = apply_filters( 'prc_sitemap_index', $sitemaps, $year );

		$xml = new SimpleXMLElement( $xml_prefix . '<sitemapindex xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></sitemapindex>' );
		foreach ( $sitemaps as $sitemap_date ) {
			$sitemap      = $xml->addChild( 'sitemap' );
			$sitemap->loc = self::build_sitemap_url( $sitemap_date ); // Manually set the child instead of addChild to prevent "unterminated entity reference" warnings due to encoded ampersands http://stackoverflow.com/a/555039/169478.
		}

		$result = $xml->asXML();

		set_transient( $cache_key, $result, PRC_Sitemap_Cache::CACHE_TIMES['standard'] );

		return $result;
	}

	/**
	 * Build the sitemap URL for a given date
	 *
	 * @param string $sitemap_date The date to build the sitemap for.
	 * @return string The sitemap URL.
	 */
	public static function build_sitemap_url( $sitemap_date ) {
		$sitemap_time = strtotime( $sitemap_date );

		if ( self::$index_by_year ) {
			$sitemap_url = add_query_arg(
				array(
					'mm' => gmdate( 'm', $sitemap_time ),
					'dd' => gmdate( 'd', $sitemap_time ),
				),
				home_url( '/sitemap-' . gmdate( 'Y', $sitemap_time ) . '.xml' )
			);
		} else {
			$sitemap_url = add_query_arg(
				array(
					'yyyy' => gmdate( 'Y', $sitemap_time ),
					'mm'   => gmdate( 'm', $sitemap_time ),
					'dd'   => gmdate( 'd', $sitemap_time ),
				),
				home_url( '/sitemap-posts.xml' )
			);
		}

		return $sitemap_url;
	}

	/**
	 * Get the sitemap post ID for a given date
	 *
	 * @param int $year The year to get the sitemap post ID for.
	 * @param int $month The month to get the sitemap post ID for.
	 * @param int $day The day to get the sitemap post ID for.
	 * @return int|false The sitemap post ID, or false if no sitemap post is found.
	 */
	public static function get_sitemap_post_id( $year, $month, $day ) {
		$ymd = self::get_date_stamp( $year, $month, $day );

		$sitemap_args = array(
			'date_query'        => array(
				array(
					'before'    => sprintf( '%s 00:00:00', $ymd ),
					'after'     => sprintf( '%s 00:00:00', $ymd ),
					'inclusive' => true,
				),
			),
			'orderby'           => 'ID',
			'order'             => 'ASC',
			'posts_per_page'    => 1,
			'fields'            => 'ids',
			'post_type'         => self::SITEMAP_CPT,
			'no_found_rows'     => true,
			'update_term_cache' => false,
			'suppress_filters'  => false,
		);

		$sitemap_query = get_posts( $sitemap_args );

		if ( ! empty( $sitemap_query ) ) {
			return $sitemap_query[0];
		}

		return false;
	}

	/**
	 * Get XML for individual day
	 *
	 * @param int $year The year to build the sitemap for.
	 * @param int $month The month to build the sitemap for.
	 * @param int $day The day to build the sitemap for.
	 * @return string|false
	 */
	public static function build_individual_sitemap_xml( $year, $month, $day ) {

		// Get XML for an individual day. Stored as full XML.
		$sitemap_id = self::get_sitemap_post_id( $year, $month, $day );

		if ( $sitemap_id ) {
			$sitemap_content = get_post_meta( $sitemap_id, 'prc_sitemap_xml', true );
			// Return is now as it should be valid xml!
			return $sitemap_content;
		}
		/* There are no posts for this day */
		return false;
	}

	/**
	 * Build XML for output to clean up the template file
	 *
	 * @param array $request The request to build the sitemap for.
	 * @return string|false
	 */
	public static function build_xml( $request = array() ) {

		$year  = $request['year'];
		$month = $request['month'];
		$day   = $request['day'];

		if ( ( false === $year || is_numeric( $year ) ) && false === $month && false === $day ) {
			$xml = self::build_root_sitemap_xml( $year );
		} elseif ( $year > 0 && $month > 0 && $day > 0 ) {
			$xml = self::build_individual_sitemap_xml( $year, $month, $day );
		} else {
			/* Invalid options sent */
			return false;
		}
		return $xml;
	}

	/**
	 * Register default post type support for sitemap.
	 *
	 * @hook init
	 */
	public static function register_default_post_type_support() {
		add_post_type_support( 'post', 'prc-sitemap' );
		add_post_type_support( 'page', 'prc-sitemap' );
	}

	/**
	 * Get supported post types
	 *
	 * @return array
	 */
	public static function get_supported_post_types() {
		$post_types      = get_post_types( array( 'public' => true ), 'names' );
		$supported_types = array_values(
			array_filter(
				$post_types,
				function ( $pt ) {
					return post_type_supports( $pt, 'prc-sitemap' );
				}
			)
		);
		// Maintain backward compatibility with filter.
		$filter_types       = apply_filters( 'prc_sitemap_supported_post_types', array() );
		$enabled_post_types = array_unique( array_merge( $supported_types, $filter_types ) );
		return array_values( $enabled_post_types );
	}

	/**
	 * Get supported post types in SQL format
	 *
	 * @return string
	 */
	private static function get_supported_post_types_in() {
		global $wpdb;

		$post_types          = self::get_supported_post_types();
		$post_types_prepared = array();

		foreach ( $post_types as $post_type ) {
			$post_types_prepared[] = $wpdb->prepare( '%s', $post_type );
		}

		return implode( ', ', $post_types_prepared );
	}

	/**
	 * Helper function for PHP ordering of posts by date, desc.
	 *
	 * @param object $post_a StdClass object, or WP_Post object to order.
	 * @param object $post_b StdClass object or WP_Post object to order.
	 *
	 * @return int
	 */
	private static function order_by_post_date( $post_a, $post_b ) {
		$a_date = strtotime( $post_a->post_date );
		$b_date = strtotime( $post_b->post_date );
		if ( $a_date === $b_date ) {
			return 0;
		}
		return ( $a_date < $b_date ) ? -1 : 1;
	}

	/**
	 * Add featured image to sitemap entry.
	 *
	 * Emits the canonical (un-transformed) attachment URL for each image so
	 * Google indexes one URL per image instead of a query-string variant
	 * (e.g. `?resize=1024,…`). On WordPress VIP the File System rewrites
	 * sized requests to its image transformation service, so emitting a
	 * sized URL here would teach Google about a per-edge cached variant
	 * with an unstable ETag — exactly what we are trying to avoid.
	 *
	 * @param SimpleXMLElement $entry_xml The sitemap url entry XML.
	 * @param int              $post_id   The post ID being processed.
	 * @return SimpleXMLElement The modified sitemap url entry XML.
	 */
	public static function add_entry_featured_image( $entry_xml, $post_id = 0 ) {
		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		if ( ! $post_id ) {
			return $entry_xml;
		}

		// Back-compat; if someone is already adding images, don't bother.
		$existing_images = $entry_xml->children( 'image', true );
		if ( count( $existing_images ) > 0 ) {
			return $entry_xml;
		}

		$images = array();
		$seen   = array();

		$thumbnail_id  = get_post_thumbnail_id( $post_id );
		$thumbnail_url = $thumbnail_id ? wp_get_attachment_url( $thumbnail_id ) : '';

		// Get the attachments that are images for the post.
		$attachments = get_children(
			array(
				'post_parent'    => $post_id,
				'post_type'      => 'attachment',
				'numberposts'    => -1, // phpcs:ignore WordPressVIPMinimum.Performance.NoPaging.posts_per_page_numberposts -- one post's own image attachments.
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
			)
		);
		foreach ( $attachments as $attachment ) {
			$url = wp_get_attachment_url( $attachment->ID );
			if ( ! $url ) {
				$url = $attachment->guid;
			}
			if ( ! $url || isset( $seen[ $url ] ) ) {
				continue;
			}
			$seen[ $url ] = true;
			$images[]     = array( 'image:loc' => $url );
		}

		// If, for some reason, the featured image isn't a child attachment of
		// the post, add it directly so it still appears in the sitemap.
		if ( $thumbnail_url && ! isset( $seen[ $thumbnail_url ] ) ) {
			$seen[ $thumbnail_url ] = true;
			$images[]               = array( 'image:loc' => $thumbnail_url );
		}

		$images = apply_filters( 'prc_sitemap_entry_images', $images );

		foreach ( $images as $image ) {
			$image_xml = $entry_xml->addChild( 'image:image' );
			foreach ( $image as $meta => $value ) {
				$image_xml->addChild( $meta, esc_html( $value ) );
			}
		}

		return $entry_xml;
	}

	/**
	 * Get the most recent last modified date for any term in a taxonomy.
	 *
	 * @param string $taxonomy The taxonomy name.
	 * @return string|false The last modified date in MySQL datetime format, or false if not found.
	 */
	public static function get_taxonomy_last_modified_max( string $taxonomy ): string|false {
		global $wpdb;

		// Check cache first.
		$cache_key   = PRC_Sitemap_Cache::versioned_key( "tax_lastmod_max_{$taxonomy}" );
		$cached_time = wp_cache_get( $cache_key, self::CACHE_GROUP );

		if ( false !== $cached_time ) {
			return $cached_time;
		}

		// Get all term IDs for this taxonomy.
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'fields'     => 'ids',
				'hide_empty' => true,
			)
		);

		if ( empty( $terms ) || is_wp_error( $terms ) ) {
			wp_cache_set( $cache_key, false, self::CACHE_GROUP, PRC_Sitemap_Cache::CACHE_TIMES['long'] );
			return false;
		}

		// Get the most recent post modification date for any term in this taxonomy.
		$post_modified = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MAX(p.post_modified)
			FROM {$wpdb->posts} p
			INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
			INNER JOIN {$wpdb->term_taxonomy} tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
			WHERE tt.taxonomy = %s
			AND p.post_status = 'publish'
			AND p.post_type IN ( " . self::get_supported_post_types_in() . ' )', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- get_supported_post_types_in() joins $wpdb->prepare( '%s' ) values.
				$taxonomy
			)
		);

		// If neither exists, cache false result and return.
		if ( empty( $post_modified ) ) {
			wp_cache_set( $cache_key, false, self::CACHE_GROUP, PRC_Sitemap_Cache::CACHE_TIMES['long'] );
			return false;
		}

		// Return the most recent of the two dates.
		if ( empty( $post_modified ) ) {
			$last_modified = false;
		} else {
			$last_modified = $post_modified;
		}

		wp_cache_set( $cache_key, $last_modified, self::CACHE_GROUP, PRC_Sitemap_Cache::CACHE_TIMES['long'] );

		return $last_modified;
	}

	/**
	 * Start a new cache version when a published post is saved or deleted.
	 *
	 * A draft is not in any sitemap, so saving one changes nothing the caches
	 * hold. A post that leaves or enters publish reaches
	 * invalidate_caches_on_post_status_change().
	 *
	 * @param int     $post_id The post ID.
	 * @param WP_Post $post The post object (optional).
	 */
	public static function invalidate_caches_on_post_change( $post_id, $post = null ) {
		if ( ! $post ) {
			$post = get_post( $post_id );
		}

		if ( ! $post || 'publish' !== $post->post_status || ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		PRC_Sitemap_Cache::bump_once_per_request();
	}

	/**
	 * Start a new cache version when a post enters or leaves publish.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Old post status.
	 * @param WP_Post $post Post object.
	 */
	public static function invalidate_caches_on_post_status_change( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status && 'publish' !== $old_status ) {
			return;
		}

		if ( ! in_array( $post->post_type, self::get_supported_post_types(), true ) ) {
			return;
		}

		PRC_Sitemap_Cache::bump_once_per_request();
	}

	/**
	 * Start a new cache version when a term of a sitemap taxonomy is created, edited, or deleted.
	 *
	 * @param int     $term_id      Term ID.
	 * @param int     $tt_id        Term taxonomy ID.
	 * @param string  $taxonomy     Taxonomy slug.
	 * @param WP_Term $deleted_term Deleted term object (only on delete_term).
	 */
	public static function invalidate_caches_on_term_change( $term_id, $tt_id, $taxonomy, $deleted_term = null ) {
		$supported = apply_filters( 'prc_sitemap_supported_taxonomies', array( 'category' ) );
		if ( ! in_array( $taxonomy, $supported, true ) ) {
			return;
		}
		PRC_Sitemap_Cache::bump_once_per_request();
	}

	/**
	 * Remove every queued sitemap cron event: the 15-minute update pass and the full build's walk.
	 */
	public static function unschedule_cron_hooks() {
		foreach ( self::CRON_HOOKS as $hook ) {
			wp_unschedule_hook( $hook );
		}
	}

	/**
	 * Retire every cached sitemap entry.
	 *
	 * @return array{previous: string, current: string} The cache version before and after.
	 */
	public static function clear_all_caches() {
		return PRC_Sitemap_Cache::invalidate_all_caches();
	}
}

add_action( 'after_setup_theme', array( 'PRC_Sitemap', 'setup' ) );
register_deactivation_hook( __FILE__, array( 'PRC_Sitemap', 'unschedule_cron_hooks' ) );
