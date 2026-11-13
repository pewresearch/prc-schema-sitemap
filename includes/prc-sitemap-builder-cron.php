<?php // phpcs:ignore WordPress.Files.FileName.InvalidClassFileName -- prc-schema-sitemap.php requires this path when prc_sitemap_use_cron_builder is on.
/**
 * Full sitemap builder on WP-Cron.
 *
 * @package PRC\Sitemap
 */

use PRC\Primitives\Concurrency\Lease;

/**
 * Walks every year, month, and day with posts through single cron events.
 */
class PRC_Sitemap_Builder_Cron {

	/**
	 * Option that holds the build state: idle, running, or halting.
	 */
	const BUILD_STATE_OPTION = 'prc_sitemap_build_state';

	/**
	 * Option that holds the token of the lease a full build holds.
	 */
	const BUILD_TOKEN_OPTION = 'prc_sitemap_build_token';

	/**
	 * Prefix of every lease row: prc_sitemap_build, prc_sitemap_date_{Y-m-d}.
	 */
	const LEASE_PREFIX = 'prc_sitemap_';

	/**
	 * Lease key of the full build.
	 */
	const BUILD_LEASE_KEY = 'build';

	/**
	 * Seconds a full build keeps its lease without a step. Each step renews it.
	 */
	const BUILD_LEASE_TTL = 900;

	/**
	 * Seconds a build of a date waits to try again after it finds the date lease held.
	 */
	const DATE_BUSY_RETRY_SECONDS = 30;

	/**
	 * Seconds a build of a date waits to try again after a read of the date failed.
	 */
	const DATE_READ_RETRY_SECONDS = 60;

	/**
	 * Sitemap posts that one page of the reset reads.
	 *
	 * @var int
	 */
	const RESET_BATCH_SIZE = 200;

	/**
	 * Register the cron hooks and, in wp-admin, the builder actions.
	 */
	public static function setup() {
		add_action( 'prc_update_sitemap_for_year_month_date', array( __CLASS__, 'schedule_sitemap_update_for_year_month_date' ), 10, 2 );

		add_action( 'prc_cron_generate_sitemap_for_year', array( __CLASS__, 'generate_sitemap_for_year' ) );
		add_action( 'prc_cron_generate_sitemap_for_year_month', array( __CLASS__, 'generate_sitemap_for_year_month' ) );
		add_action( 'prc_cron_generate_sitemap_for_year_month_day', array( __CLASS__, 'generate_sitemap_for_year_month_day' ) );

		if ( is_admin() ) {
			add_filter( 'prc_sitemap_actions', array( __CLASS__, 'add_actions' ) );
			add_filter( 'prc_sitemap_create_status', array( __CLASS__, 'sitemap_create_status' ) );

			add_action( 'prc_sitemap_action-generate', array( __CLASS__, 'action_generate' ) );
			add_action( 'prc_sitemap_action-generate_from_latest', array( __CLASS__, 'action_generate_from_latest' ) );
			add_action( 'prc_sitemap_action-halt_generation', array( __CLASS__, 'action_halt' ) );
			add_action( 'prc_sitemap_action-reset_sitemap_data', array( __CLASS__, 'action_reset_data' ) );
		}
	}

	/**
	 * Adds the builder cron actions to the sitemaps admin page.
	 *
	 * Hooked into the prc_sitemap_actions filter.
	 *
	 * @param array $actions The actions to show on the admin page.
	 * @return array
	 */
	public static function add_actions( $actions ) {
		// No actions for private blogs.
		if ( ! PRC_Sitemap::is_blog_public() ) {
			return $actions;
		}

		$build_state                = self::get_build_state();
		$sitemap_create_in_progress = 'idle' !== $build_state;
		$sitemap_halt_in_progress   = 'halting' === $build_state;

		$actions['generate']             = array(
			'text'    => __( 'Generate from all articles', 'prc--sitemaps' ),
			'enabled' => ! $sitemap_create_in_progress && ! $sitemap_halt_in_progress,
		);
		$actions['generate_from_latest'] = array(
			'text'    => __( 'Generate from latest articles', 'prc--sitemaps' ),
			'enabled' => ! $sitemap_create_in_progress && ! $sitemap_halt_in_progress,
		);
		$actions['halt_generation']      = array(
			'text'    => __( 'Halt Sitemap Generation', 'prc--sitemaps' ),
			'enabled' => $sitemap_create_in_progress && ! $sitemap_halt_in_progress,
		);
		$actions['reset_sitemap_data']   = array(
			'text'    => __( 'Reset Sitemap Data', 'prc--sitemaps' ),
			'enabled' => ! $sitemap_create_in_progress && ! $sitemap_halt_in_progress,
		);

		return $actions;
	}

	/**
	 * Shows the build state of the builder cron as the sitemap create status.
	 *
	 * Hooked into the prc_sitemap_create_status filter.
	 *
	 * @param string $status The status text to show the user on the admin page.
	 * @return string The status text.
	 */
	public static function sitemap_create_status( $status ) {
		$build_state = self::get_build_state();
		if ( 'halting' === $build_state ) {
			return __( 'Halting', 'prc--sitemaps' );
		}
		if ( 'running' === $build_state ) {
			return __( 'Running', 'prc--sitemaps' );
		}

		return $status;
	}

	/**
	 * The lease that one full build holds.
	 *
	 * @return Lease
	 */
	public static function build_lease() {
		return new Lease( self::LEASE_PREFIX, self::BUILD_LEASE_TTL, 'sitemap_' );
	}

	/**
	 * The state of the full build: idle, running, or halting.
	 *
	 * A build that does not hold a fresh lease is idle, whatever the option
	 * says. So a build that died, or a halt that arrived after the build
	 * ended, cannot leave the page stuck. Both reads go to the database, so a
	 * long CLI run sees a halt that an admin wrote after it started.
	 *
	 * @return string idle, running, or halting.
	 */
	public static function get_build_state() {
		$state = self::read_build_option( self::BUILD_STATE_OPTION );
		if ( ! in_array( $state, array( 'running', 'halting' ), true ) ) {
			return 'idle';
		}

		return self::build_lease()->is_held( self::BUILD_LEASE_KEY ) ? $state : 'idle';
	}

	/**
	 * Read a build option from the database, not the options cache.
	 *
	 * A cached option can hide a halt, or a token, that another process wrote.
	 *
	 * @param string $name The option name.
	 * @return string|null The row's option_value, or null when the row does not exist.
	 */
	private static function read_build_option( $name ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A cached option can hide a halt that another process wrote.

		return null === $value ? null : (string) $value;
	}

	/**
	 * Whether someone asked the full build to halt.
	 *
	 * @return bool
	 */
	public static function is_halting() {
		return 'halting' === self::get_build_state();
	}

	/**
	 * Take the lease of the full build and mark the build as running.
	 *
	 * @return string The lease token, or '' while another full build holds the lease.
	 */
	public static function begin_build() {
		$token = self::build_lease()->acquire( self::BUILD_LEASE_KEY );
		if ( '' === $token ) {
			return '';
		}

		update_option( self::BUILD_TOKEN_OPTION, $token, false );
		update_option( self::BUILD_STATE_OPTION, 'running', false );

		return $token;
	}

	/**
	 * Mark the build as idle and give the lease back.
	 *
	 * The walk keeps its place in prc_years_to_process, prc_months_to_process,
	 * and prc_days_to_process, so the next build resumes there. A build that no
	 * longer owns the lease changes nothing, so it cannot end a newer build.
	 * The token goes with the build, so an event that the ended build left
	 * queued finds no token that matches its own and stops.
	 *
	 * A step that ends its own build passes its token. Without it, a build that
	 * started after the step read the state would have its token read here and
	 * be ended instead. The lease release is the atomic check: it fails for a
	 * token that does not own the lease row. A build that starts between the
	 * release and the writes stores its own token first, and then this call
	 * leaves the rows alone. The check and the writes are single statements, see
	 * write_idle_for_token().
	 *
	 * @param string $build_token The token of the build to end. Empty reads the stored token.
	 */
	public static function end_build( $build_token = '' ) {
		$token = '' !== (string) $build_token ? (string) $build_token : (string) get_option( self::BUILD_TOKEN_OPTION, '' );
		if ( '' !== $token && ! self::build_lease()->release( self::BUILD_LEASE_KEY, $token ) ) {
			return;
		}

		if ( '' === $token ) {
			delete_option( self::BUILD_TOKEN_OPTION );
			update_option( self::BUILD_STATE_OPTION, 'idle', false );
			return;
		}

		self::write_idle_for_token( $token );
	}

	/**
	 * Write the idle state and drop the token, each in one statement, and only
	 * while the stored token is still the token of the build that ended.
	 *
	 * A read of the token followed by a write is not safe: a build that starts
	 * between the two stores its token and its running state, and the late write
	 * then ends it. The state row changes only through a join on the token row,
	 * and the token row is deleted only by its own value, so a newer build keeps
	 * both rows. A new build writes its token before its state, so a state write
	 * that finds the old token happens before the new build writes running.
	 *
	 * @param string $token The token of the build that ended.
	 */
	private static function write_idle_for_token( $token ) {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- One statement keeps the token check and the write together.
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE $wpdb->options AS state INNER JOIN $wpdb->options AS token ON token.option_name = %s AND token.option_value = %s SET state.option_value = 'idle' WHERE state.option_name = %s",
				self::BUILD_TOKEN_OPTION,
				$token,
				self::BUILD_STATE_OPTION
			)
		);
		$wpdb->delete(
			$wpdb->options,
			array(
				'option_name'  => self::BUILD_TOKEN_OPTION,
				'option_value' => $token,
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		wp_cache_delete( self::BUILD_STATE_OPTION, 'options' );
		wp_cache_delete( self::BUILD_TOKEN_OPTION, 'options' );
	}

	/**
	 * End whatever build the options still describe, whoever holds the lease.
	 *
	 * Halt while the page reads idle, and Reset, call this. A build whose lease
	 * expired leaves its token and its queued walk events behind, and a later
	 * step could take the lease again. This writes the state idle, deletes the
	 * token, clears the lease, and drops the queued walk events. The walk keeps
	 * its place, so the next build resumes there.
	 */
	private static function retire_build() {
		update_option( self::BUILD_STATE_OPTION, 'idle', false );
		delete_option( self::BUILD_TOKEN_OPTION );
		self::build_lease()->clear( self::BUILD_LEASE_KEY );
		self::drop_walk_events();
	}

	/**
	 * Check, at the start of a walk step, that the build may go on.
	 *
	 * Every walk event carries the token of the build that queued it. A step
	 * runs only while that token is the stored one, so an event of an ended
	 * build, of a halted build, of a build that died, or of a reset stops
	 * without a change. That holds when Cron Control delivers an event twice,
	 * and when a new build starts while old events are still queued. A halting
	 * build ends here. A build that is not running, or whose lease expired, is
	 * not building: its step ends it and stops, and never takes the lease back.
	 *
	 * An event with no token was queued before tokens existed. It goes on only
	 * while no build has ever written the build state. Once a build has, such
	 * an event stops, whatever the token option says.
	 *
	 * @param string $event_token The token in the event's args, or '' for an event without one.
	 * @return bool False when the step must not run.
	 */
	public static function walk_may_continue( $event_token = '' ) {
		$event_token = (string) $event_token;

		if ( '' === $event_token ) {
			return null === self::read_build_option( self::BUILD_STATE_OPTION );
		}

		$token = (string) self::read_build_option( self::BUILD_TOKEN_OPTION );
		if ( '' === $token || $event_token !== $token ) {
			return false;
		}

		$state = self::read_build_option( self::BUILD_STATE_OPTION );
		if ( ! in_array( $state, array( 'running', 'halting' ), true ) ) {
			self::end_build( $token );
			return false;
		}

		if ( ! self::build_lease()->is_held( self::BUILD_LEASE_KEY ) ) {
			self::end_build( $token );
			return false;
		}

		if ( 'halting' === $state ) {
			self::end_build( $token );
			return false;
		}

		return self::build_lease()->refresh( self::BUILD_LEASE_KEY, $token );
	}

	/**
	 * Generates full sitemaps for the site.
	 *
	 * Hooked into the prc_sitemap_actions-generate action.
	 */
	public static function action_generate() {
		switch ( self::start_full_build() ) {
			case 'busy':
				PRC_Sitemap::show_action_message( __( 'Cannot start sitemap generation: a full build is already running.', 'prc--sitemaps' ), 'warning' );
				break;
			case 'empty':
				PRC_Sitemap::show_action_message( __( 'Cannot generate sitemaps: no posts found.', 'prc--sitemaps' ), 'error' );
				break;
			case 'resumed':
				PRC_Sitemap::show_action_message( __( 'Resuming sitemap creation', 'prc--sitemaps' ) );
				break;
			default:
				PRC_Sitemap::show_action_message( __( 'Starting sitemap generation...', 'prc--sitemaps' ) );
		}
	}

	/**
	 * Drop the walk events that a build which died left queued.
	 *
	 * A build that holds the lease calls this before it starts. It keeps the
	 * queue short, and nothing else. It cannot make the walk safe: a runner
	 * may have taken an event before the drop, and Cron Control may deliver
	 * one again. Such an event carries the token of its own build, so it stops
	 * at walk_may_continue(). Update events share the day hook, so they stay
	 * queued.
	 */
	public static function drop_walk_events() {
		wp_unschedule_hook( 'prc_cron_generate_sitemap_for_year' );
		wp_unschedule_hook( 'prc_cron_generate_sitemap_for_year_month' );

		foreach ( _get_cron_array() as $timestamp => $hooks ) {
			foreach ( $hooks['prc_cron_generate_sitemap_for_year_month_day'] ?? array() as $event ) {
				if ( empty( $event['args'][0]['update'] ) ) {
					wp_unschedule_event( $timestamp, 'prc_cron_generate_sitemap_for_year_month_day', $event['args'] );
				}
			}
		}
	}

	/**
	 * Start the full build under the build lease.
	 *
	 * A second full build exits while the first holds the lease. The new build
	 * drops the walk events a build that died left queued, so two walks never
	 * share the position options.
	 *
	 * @return string busy, empty, resumed, or started.
	 */
	public static function start_full_build() {
		$token = self::begin_build();
		if ( '' === $token ) {
			return 'busy';
		}

		$resumed = ! empty( get_option( 'prc_years_to_process' ) );

		self::drop_walk_events();

		if ( ! self::generate_full_sitemap( $token ) ) {
			self::end_build();
			return 'empty';
		}

		return $resumed ? 'resumed' : 'started';
	}

	/**
	 * Generates sitemaps from the latest posts.
	 *
	 * Hooked into the prc_sitemap_actions-generate_from_latest action
	 */
	public static function action_generate_from_latest() {
		if ( PRC_Sitemap::update_sitemap_from_modified_posts( true ) > 0 ) {
			PRC_Sitemap::show_action_message( __( 'Updating sitemap from latest articles...', 'prc--sitemaps' ) );
		} else {
			PRC_Sitemap::show_action_message( __( 'Cannot generate from latest articles: no posts updated lately.', 'prc--sitemaps' ), 'error' );
		}
	}

	/**
	 * Halts sitemap generation on the next cron run. Saves current position for resuming.
	 *
	 * Hooked into the prc_sitemap_actions-halt_generation action.
	 */
	public static function action_halt() {
		$build_state = self::get_build_state();

		if ( 'running' === $build_state ) {
			update_option( self::BUILD_STATE_OPTION, 'halting', false );
			PRC_Sitemap::show_action_message( __( 'Halting sitemap generation. The build stops at its next step.', 'prc--sitemaps' ) );
		} elseif ( 'halting' === $build_state ) {
			PRC_Sitemap::show_action_message( __( 'Cannot stop sitemap generation: sitemap generation is already being halted.', 'prc--sitemaps' ), 'warning' );
		} else {
			// The page reads idle, but a build whose lease expired can leave its token and queued events behind.
			self::retire_build();
			PRC_Sitemap::show_action_message( __( 'Cannot stop sitemap generation: sitemap generation not in progress', 'prc--sitemaps' ), 'warning' );
		}
	}

	/**
	 * Resets sitemap data and prints out a message to the user.
	 *
	 * Hooked into the prc_sitemap_actions-reset_sitemap_data action.
	 */
	public static function action_reset_data() {
		// Do the same as when we finish then tell use to delete manuallyrather than remove all data.
		$reset = self::reset_sitemap_data();
		if ( is_wp_error( $reset ) ) {
			PRC_Sitemap::show_action_message(
				sprintf(
					/* translators: %s: the reason that the reset stopped. */
					__( 'Sitemap data was not reset. %s Press Reset Sitemap Data again to continue.', 'prc-sitemap' ),
					esc_html( $reset->get_error_message() )
				),
				'error'
			);
			return;
		}

		PRC_Sitemap::show_action_message(
			sprintf(
				/* translators: 1: sitemap post type, 2: WP-CLI command that deletes those posts. */
				__( '<p>Sitemap data reset. If you want to completely remove the data you must do so manually by deleting all posts with post type <code>%1$s</code>.</p><p>The WP-CLI command to do this is: <code>%2$s</code></p>', 'prc-sitemap' ),
				PRC_Sitemap::SITEMAP_CPT,
				'wp post delete $(wp post list --post_type=' . PRC_Sitemap::SITEMAP_CPT . ' --format=ids)'
			)
		);
	}

	/**
	 * Empty every sitemap post: remove its XML and its URL count.
	 *
	 * A sitemap post is emptied under the lease of its date, as a build writes it.
	 * Without the lease, a build that wrote the XML could meet a reset that removed
	 * the XML and kept the count, or the other way round, and the date would then
	 * count URLs that it does not list. A date whose lease is busy keeps its data
	 * and is queued again, so the build that holds the lease is the last writer.
	 *
	 * A page that cannot be read, or whose data cannot be deleted, ends the walk with an error. It does
	 * not end it as the last page, so the caller never reports a reset that stopped part way. Pages that were already
	 * emptied stay empty, and a second call starts again from the first post.
	 *
	 * @return int|WP_Error The number of sitemap posts that this call emptied, or the error of the page that failed.
	 */
	public static function empty_sitemap_posts() {
		global $wpdb;

		$last      = 0;
		$emptied   = 0;
		$page_size = 0;

		do {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_date FROM $wpdb->posts WHERE post_type = %s AND ID > %d ORDER BY ID ASC LIMIT %d", PRC_Sitemap::SITEMAP_CPT, $last, self::RESET_BATCH_SIZE ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- ID-cursor walk; a cached page would defeat the batching.
			if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
				return new WP_Error(
					'prc_sitemap_reset_read_failed',
					sprintf(
						/* translators: 1: the last sitemap post ID that was read, 2: the database error. */
						__( 'The sitemap posts after ID %1$d could not be read (%2$s).', 'prc-sitemap' ),
						$last,
						(string) $wpdb->last_error
					),
					array(
						'last_id' => $last,
						'emptied' => $emptied,
					)
				);
			}

			$page_size = count( $rows );
			$leased    = array();
			$post_ids  = array();
			$lease     = PRC_Sitemap::date_lease();

			try {
				foreach ( $rows as $row ) {
					$last = (int) $row->ID;
					$date = substr( $row->post_date, 0, 10 );
					if ( ! isset( $leased[ $date ] ) ) {
						$leased[ $date ] = $lease->acquire( $date );
						if ( '' === $leased[ $date ] ) {
							list( $year, $month, $day ) = array_map( 'intval', explode( '-', $date ) );
							self::schedule_sitemap_update_for_year_month_date( array( $year, $month, $day ), time() + self::DATE_BUSY_RETRY_SECONDS );
						}
					}
					if ( '' !== $leased[ $date ] ) {
						$post_ids[] = $last;
					}
				}

				if ( ! empty( $post_ids ) ) {
					$id_list = implode( ',', array_map( 'intval', $post_ids ) );
					$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM $wpdb->postmeta WHERE post_id IN ($id_list) AND meta_key IN (%s, %s)", 'prc_indexed_url_count', 'prc_sitemap_xml' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- one delete per page, IDs are integers.
					foreach ( $post_ids as $post_id ) {
						wp_cache_delete( $post_id, 'post_meta' );
					}
					if ( false === $deleted || '' !== (string) $wpdb->last_error ) {
						return new WP_Error(
							'prc_sitemap_reset_delete_failed',
							sprintf(
								/* translators: 1: the last sitemap post ID of the page, 2: the database error. */
								__( 'The sitemap data up to ID %1$d could not be deleted (%2$s).', 'prc-sitemap' ),
								$last,
								(string) $wpdb->last_error
							),
							array(
								'last_id' => $last,
								'emptied' => $emptied,
							)
						);
					}
					$emptied += count( $post_ids );
				}
			} finally {
				foreach ( $leased as $date => $token ) {
					$lease->release( (string) $date, $token );
				}
			}
		} while ( self::RESET_BATCH_SIZE === $page_size );

		return $emptied;
	}

	/**
	 * Reset sitemap options
	 *
	 * Stops with the error of empty_sitemap_posts() before it touches any option, so a reset that
	 * stopped part way keeps the walk, the build, and the counts as they are and a second reset
	 * continues the work.
	 *
	 * @return true|WP_Error True when the reset is done.
	 */
	public static function reset_sitemap_data() {
		// Remove the stats meta and the XML of every sitemap post, date by date under the date lease.
		$emptied = self::empty_sitemap_posts();
		if ( is_wp_error( $emptied ) ) {
			// Pages that were emptied before the failure changed what a visitor reads.
			PRC_Sitemap_Cache::bump();
			return $emptied;
		}

		// Delete state options.
		delete_option( 'prc_days_to_process' );
		delete_option( 'prc_months_to_process' );
		delete_option( 'prc_years_to_process' );
		self::retire_build();

		// Rows that builds before the build state option wrote.
		delete_option( 'prc_stop_processing' );
		delete_option( 'prc_sitemap_create_in_progress' );

		// Delete stats options.
		delete_option( 'prc_sitemap_indexed_url_count' );

		// The next update window starts again at prc_sitemap_update_last_run.
		delete_option( 'prc_sitemap_update_cursor' );

		PRC_Sitemap_Cache::bump();

		return true;
	}

	/**
	 * Queue a rebuild of one date.
	 *
	 * Hooked into prc_update_sitemap_for_year_month_date. The event runs on the
	 * day hook, as the full build's day events do, so Cron Control runs one
	 * of them at a time. Its `update` arg sends it to build_date() alone, so
	 * it never moves the full build's place in prc_days_to_process.
	 *
	 * @param int[] $date Year, month, and day.
	 * @param int   $time When the event runs.
	 */
	public static function schedule_sitemap_update_for_year_month_date( $date, $time ) {
		list( $year, $month, $day ) = $date;

		$scheduled = wp_schedule_single_event(
			$time,
			'prc_cron_generate_sitemap_for_year_month_day',
			array(
				array(
					'year'   => (int) $year,
					'month'  => (int) $month,
					'day'    => (int) $day,
					'update' => true,
				),
			),
			true
		);

		// A duplicate of a pending event is expected: it means the date is already queued.
		if ( is_wp_error( $scheduled ) && 'duplicate_event' !== $scheduled->get_error_code() ) {
			error_log( "PRC Sitemap: could not queue the update of {$year}-{$month}-{$day}: " . $scheduled->get_error_message() ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		}
	}

	/*
	 * We want to generate the entire sitemap catalogue async to avoid running into timeout and memory issues.
	 *
	 * Here's how it all works:
	 *
	 * -- Get year range for content
	 * -- Store these years in options table
	 * -- Cascade through all months and days in reverse order i.e. newest first
	 * -- Generate cron event for each individual day and when finished queue up the cron for the next one
	 * -- Add each post from that day to the custom post
	 *
	 */

	/**
	 * Generate full sitemap
	 *
	 * @param string $token The token of the build that walks. Its events carry it.
	 * @return bool Whether the walk's first event is queued. False when the site has no posts.
	 */
	public static function generate_full_sitemap( $token = '' ) {
		global $wpdb;

		$is_partial_or_running = get_option( 'prc_years_to_process' );

		if ( empty( $is_partial_or_running ) ) {
			$all_years_with_posts = PRC_Sitemap::check_year_has_posts();
			update_option( 'prc_years_to_process', $all_years_with_posts );
		} else {
			$all_years_with_posts = $is_partial_or_running;
		}

		if ( 0 == count( $all_years_with_posts ) ) {
			return false; // Cannot generate sitemaps if there are no posts.
		}

		$time      = time();
		$next_year = end( $all_years_with_posts );

		wp_schedule_single_event(
			$time,
			'prc_cron_generate_sitemap_for_year',
			array(
				self::with_token(
					array(
						'year' => (int) $next_year,
					),
					$token
				),
			)
		);

		return true;
	}

	/**
	 * Generate sitemap for a given year
	 *
	 * @param mixed[] $args The year.
	 */
	public static function generate_sitemap_for_year( $args ) {
		if ( ! self::walk_may_continue( $args['token'] ?? '' ) ) {
			return;
		}

		$is_partial_or_running = get_option( 'prc_months_to_process' );

		$year      = $args['year'];
		$max_month = 12;
		if ( gmdate( 'Y' ) == $year ) {
			$max_month = gmdate( 'n' );
		}

		if ( empty( $is_partial_or_running ) ) {
			$months = range( 1, $max_month );
			update_option( 'prc_months_to_process', $months );
		} else {
			$months = $is_partial_or_running;
		}

		$time       = time();
		$next_month = end( $months );

		wp_schedule_single_event(
			$time,
			'prc_cron_generate_sitemap_for_year_month',
			array(
				self::with_token(
					array(
						'year'  => (int) $year,
						'month' => (int) $next_month,
					),
					$args['token'] ?? ''
				),
			)
		);
	}

	/**
	 * Generate sitemap for a given month in a given year
	 *
	 * @param mixed[] $args The year and month.
	 */
	public static function generate_sitemap_for_year_month( $args ) {
		if ( ! self::walk_may_continue( $args['token'] ?? '' ) ) {
			return;
		}

		$is_partial_or_running = get_option( 'prc_days_to_process' );

		$year  = $args['year'];
		$month = $args['month'];

		// cal_days_in_month doesn't exist on WP.com so set it to a possible max. Will skip invalid dates as no posts will be found.
		if ( ! function_exists( 'cal_days_in_month' ) ) {
			$max_days = 31;
		} else {
			$max_days = cal_days_in_month( CAL_GREGORIAN, (int) $month, (int) $year );
		}

		if ( gmdate( 'Y' ) == $year && gmdate( 'n' ) == $month ) {
			$max_days = gmdate( 'j' );
		}

		if ( empty( $is_partial_or_running ) ) {
			$days = range( 1, $max_days );
			update_option( 'prc_days_to_process', $days );
		} else {
			$days = $is_partial_or_running;
		}

		$next_day = end( $days );

		$time = time();

		wp_schedule_single_event(
			$time,
			'prc_cron_generate_sitemap_for_year_month_day',
			array(
				self::with_token(
					array(
						'year'  => (int) $year,
						'month' => (int) $month,
						'day'   => (int) $next_day,
					),
					$args['token'] ?? ''
				),
			)
		);
	}

	/**
	 * Build the sitemap of one date. A full build event then moves the walk on; an update event does not.
	 *
	 * The full build walks with events that carry year, month, and day. The update
	 * pass queues events that also carry `update`. Those only rebuild their date, and
	 * they ignore Halt and the build lease: halt stops the walk, not the 15-minute
	 * update, and an update never ends the build.
	 *
	 * @param mixed[] $args The year, month, and day, and `update` for an update event.
	 */
	public static function generate_sitemap_for_year_month_day( $args ) {
		if ( empty( $args['update'] ) && ! self::walk_may_continue( $args['token'] ?? '' ) ) {
			return;
		}

		$year  = $args['year'];
		$month = $args['month'];
		$day   = $args['day'];

		self::build_date( $year, $month, $day );
		if ( ! empty( $args['update'] ) ) {
			return;
		}
		self::find_next_day_to_process( $year, $month, $day, $args['token'] ?? '' );
	}

	/**
	 * Add the token of the build to the args of a walk event.
	 *
	 * An event with no token is one that was queued before tokens existed, so
	 * a walk that has no token adds no key.
	 *
	 * @param mixed[] $args The year, month, and day that the event needs.
	 * @param string  $token The token of the build that walks.
	 * @return mixed[]
	 */
	private static function with_token( array $args, $token ) {
		if ( '' !== (string) $token ) {
			$args['token'] = (string) $token;
		}

		return $args;
	}

	/**
	 * Build the sitemap of a date from the posts table, or delete it when the date has no posts.
	 *
	 * @param int $year The year.
	 * @param int $month The month.
	 * @param int $day The day.
	 */
	private static function build_date( $year, $month, $day ) {
		$date_stamp = PRC_Sitemap::get_date_stamp( $year, $month, $day );
		$has_posts  = PRC_Sitemap::read_date_range_has_posts( $date_stamp, $date_stamp );
		if ( is_wp_error( $has_posts ) ) {
			// A failed read looks like a date with no posts. The date is neither built nor deleted until a read works.
			self::retry_date_after_failed_read( $year, $month, $day, $has_posts );
			return;
		}

		if ( $has_posts ) {
			$built = PRC_Sitemap::generate_sitemap_for_date( $date_stamp );
			if ( is_wp_error( $built ) ) {
				self::retry_date_after_failed_read( $year, $month, $day, $built );
			} elseif ( ! $built ) {
				// Another build of this date is running. It may have read the posts before the change that queued this event, so build the date again later.
				self::schedule_sitemap_update_for_year_month_date( array( $year, $month, $day ), time() + self::DATE_BUSY_RETRY_SECONDS );
			}
		} else {
			$deleted = PRC_Sitemap::delete_sitemap_for_date( $date_stamp );
			if ( is_wp_error( $deleted ) ) {
				self::retry_date_after_failed_read( $year, $month, $day, $deleted );
			} elseif ( null === $deleted ) {
				// A build of this date holds the lease. It may have read a post that this change removed, so run the date again later.
				self::schedule_sitemap_update_for_year_month_date( array( $year, $month, $day ), time() + self::DATE_BUSY_RETRY_SECONDS );
			}
		}
	}

	/**
	 * Queue a date again after a read of its posts or of its sitemap post failed.
	 *
	 * A failed query returns no rows, like a date with no posts. The sitemap of the date was left as it was,
	 * so the event that runs later builds the date from a read that works.
	 *
	 * @param int      $year The year.
	 * @param int      $month The month.
	 * @param int      $day The day.
	 * @param WP_Error $error The failed read.
	 */
	private static function retry_date_after_failed_read( $year, $month, $day, $error ) {
		error_log( sprintf( 'PRC Sitemap: kept the sitemap of %s and queued the date again: %s', PRC_Sitemap::get_date_stamp( $year, $month, $day ), $error->get_error_message() ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		self::schedule_sitemap_update_for_year_month_date( array( $year, $month, $day ), time() + self::DATE_READ_RETRY_SECONDS );
	}

	/**
	 * Move the full build's walk one step on, from the date this event built.
	 *
	 * Cron Control, or a second WP-Cron request, can deliver one event twice.
	 * Both deliveries would pop the position options, and the walk would skip a
	 * day and queue two steps. So a step moves the walk only if the event's own
	 * date is the walk's place, and the write that moves it is a compare-and-swap
	 * on the row it read. The step that loses finds the place already moved and
	 * does nothing. Both reads go to the database, because a cached copy of a
	 * position can be older than the row.
	 *
	 * @param int    $year The year the event built.
	 * @param int    $month The month the event built.
	 * @param int    $day The day the event built.
	 * @param string $token The token of the build that walks, from the event's args.
	 * @return void, just updates options.
	 */
	public static function find_next_day_to_process( $year, $month, $day, $token = '' ) {

		if ( ! PRC_Sitemap::is_blog_public() ) {
			// The blog became private while sitemaps were enabled: stop here and keep where the walk got up to.
			self::end_build();
			return;
		}

		$days_row   = self::read_walk_row( 'prc_days_to_process' );
		$months_row = self::read_walk_row( 'prc_months_to_process' );
		$years_row  = self::read_walk_row( 'prc_years_to_process' );

		$days_being_processed   = (array) maybe_unserialize( $days_row );
		$months_being_processed = (array) maybe_unserialize( $months_row );
		$years_being_processed  = (array) maybe_unserialize( $years_row );

		$total_days   = count( $days_being_processed );
		$total_months = count( $months_being_processed );
		$total_years  = count( $years_being_processed );

		$at_own_day = $total_days && (int) end( $days_being_processed ) === (int) $day
			&& $total_months && (int) end( $months_being_processed ) === (int) $month
			&& $total_years && (int) end( $years_being_processed ) === (int) $year;
		if ( ! $at_own_day ) {
			return;
		}

		if ( $day > 1 ) {
			// Day has finished.
			if ( ! self::move_walk_row( 'prc_days_to_process', $days_row, array_slice( $days_being_processed, 0, -1 ) ) ) {
				return;
			}
			self::generate_sitemap_for_year_month(
				self::with_token(
					array(
						'year'  => $year,
						'month' => $month,
					),
					$token
				)
			);
		} elseif ( $month > 1 ) {
			// Month has finished.
			if ( ! self::move_walk_row( 'prc_months_to_process', $months_row, array_slice( $months_being_processed, 0, -1 ) ) ) {
				return;
			}
			delete_option( 'prc_days_to_process' );
			self::generate_sitemap_for_year( self::with_token( array( 'year' => $year ), $token ) );
		} elseif ( $total_years > 1 ) {
			// Year has finished.
			if ( ! self::move_walk_row( 'prc_years_to_process', $years_row, array_slice( $years_being_processed, 0, -1 ) ) ) {
				return;
			}
			delete_option( 'prc_days_to_process' );
			delete_option( 'prc_months_to_process' );
			self::generate_full_sitemap( $token );
		} else {
			// We've finished - remove all options.
			if ( ! self::drop_walk_row( 'prc_years_to_process', $years_row ) ) {
				return;
			}
			delete_option( 'prc_days_to_process' );
			delete_option( 'prc_months_to_process' );
			self::end_build();
		}
	}

	/**
	 * Read the stored value of a walk position option from the database.
	 *
	 * @param string $name The option name.
	 * @return string The row's option_value, or '' when the row is missing.
	 */
	private static function read_walk_row( $name ) {
		global $wpdb;

		$value = $wpdb->get_var( $wpdb->prepare( "SELECT option_value FROM $wpdb->options WHERE option_name = %s", $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- A cached position can be older than the row.

		return null === $value ? '' : (string) $value;
	}

	/**
	 * Write a new position, only if the row still holds the value this step read.
	 *
	 * @param string $name The option name.
	 * @param string $read The option_value this step read.
	 * @param array  $next The new position.
	 * @return bool False when another step moved the position first.
	 */
	private static function move_walk_row( $name, $read, array $next ) {
		global $wpdb;

		$moved = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The compare-and-swap needs the row's own value in the WHERE.
			$wpdb->options,
			array( 'option_value' => maybe_serialize( $next ) ),
			array(
				'option_name'  => $name,
				'option_value' => $read,
			)
		);
		self::forget_walk_option( $name );

		return 1 === $moved;
	}

	/**
	 * Delete a position row, only if it still holds the value this step read.
	 *
	 * @param string $name The option name.
	 * @param string $read The option_value this step read.
	 * @return bool False when another step moved the position first.
	 */
	private static function drop_walk_row( $name, $read ) {
		global $wpdb;

		$dropped = $wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- The compare-and-swap needs the row's own value in the WHERE.
			$wpdb->options,
			array(
				'option_name'  => $name,
				'option_value' => $read,
			)
		);
		self::forget_walk_option( $name );

		return 1 === $dropped;
	}

	/**
	 * Drop the cached copies of an option that a statement changed behind the options API.
	 *
	 * @param string $name The option name.
	 */
	private static function forget_walk_option( $name ) {
		wp_cache_delete( $name, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		wp_cache_delete( 'notoptions', 'options' );
	}
}
