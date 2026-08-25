<?php
/**
 * AI crawler allow manager: builds the "explicit allow/block" robots.txt
 * block from the configured bot list and applies it either virtually (via
 * the 'robots_txt' filter) or physically (a real robots.txt file at
 * ABSPATH, via PHCITE_Filewriter).
 *
 * The virtual filter's blog_public check mirrors the field-tested snippet's
 * intent exactly (private sites get no explicit AI-bot rules), but uses a
 * truthiness check rather than a strict `'1' === $public` string compare:
 * WordPress core changed what type it passes to the 'robots_txt' filter
 * across versions (string '1'/'0' on older core, bool on WP 6.8+), and a
 * truthiness check is correct for both.
 *
 * @package PressHangar AI Citations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCITE_Robots
 */
class PHCITE_Robots {

	/**
	 * Guards against PHCITE_Filewriter::write() (triggered from within
	 * physicalize()) causing update_option()'s sanitize/action chain to
	 * fire maybe_sync_physical_block(), which would otherwise perform a
	 * second, redundant write of the same content immediately afterward.
	 *
	 * @var bool
	 */
	private static $writing = false;

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), PHP_INT_MAX, 2 );
		add_action( 'update_option_' . PHCITE_OPTION_SETTINGS, array( __CLASS__, 'maybe_sync_physical_block' ), 10, 2 );
	}

	/**
	 * Build the managed block's inner body (no markers) from the current
	 * bot states. Follows the field-tested snippet's line format exactly:
	 * "User-agent: {bot}\nAllow: /\n\n" per allowed bot; blocked bots get
	 * "Disallow: /" instead; bots set to "no rule" are omitted entirely.
	 *
	 * @param array $bot_states Bot slug => 'allow' | 'block' | 'none'.
	 * @return string
	 */
	public static function build_block_body( $bot_states ) {
		$bot_list = PHCITE_Settings::get_bot_list();
		$body     = "# --- AI search / assistant crawlers (explicit rules, managed by PressHangar AI Citations) ---\n";
		$has_any  = false;

		foreach ( $bot_list as $slug => $bot ) {
			$state = isset( $bot_states[ $slug ] ) ? $bot_states[ $slug ] : $bot['default'];

			if ( 'allow' === $state ) {
				$body   .= "User-agent: {$slug}\nAllow: /\n\n";
				$has_any = true;
			} elseif ( 'block' === $state ) {
				$body   .= "User-agent: {$slug}\nDisallow: /\n\n";
				$has_any = true;
			}
		}

		return $has_any ? rtrim( $body, "\n" ) : '';
	}

	/**
	 * Virtual mode: append the managed block to WordPress's own robots.txt
	 * output. Skipped outright when physical mode is active AND a physical
	 * robots.txt file actually exists on disk (web servers serve it
	 * directly, so this filter would never even run for that request — but
	 * if the physical file has gone missing, fall through and keep serving
	 * the virtual block as a fail-open fallback rather than going silent).
	 *
	 * @param string     $output Existing robots.txt output.
	 * @param string|bool $public Value of the 'blog_public' option, as passed by do_robots(). WordPress core has passed this as either a string ('1'/'0') or a bool depending on version.
	 * @return string
	 */
	public static function filter_robots_txt( $output, $public ) {
		$settings = PHCITE_Settings::get_settings();

		if ( 'physical' === $settings['robots']['mode'] && PHCITE_Filewriter::exists( 'robots.txt' ) ) {
			return $output;
		}

		// Private sites get no explicit AI-bot rules added. Truthiness
		// check: correct whether core passes '1'/'0' or true/false here.
		if ( ! $public ) {
			return $output;
		}

		$body = self::build_block_body( $settings['robots']['bots'] );
		if ( '' === $body ) {
			return $output;
		}

		$result = PHCITE_Filewriter::upsert_block( $output, $body );

		if ( is_wp_error( $result ) ) {
			set_transient( 'phcite_robots_virtual_malformed', $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			return $output;
		}

		return $result;
	}

	/**
	 * Build the seed content for a brand-new physical robots.txt from
	 * WordPress core's own do_robots() default output, run through the
	 * 'robots_txt' filter (so other active plugins' rules are captured),
	 * with our own filter temporarily removed to avoid seeding our own
	 * block twice. Used only as a fallback when a live HTTP fetch of the
	 * site's current /robots.txt (see physicalize()) is unavailable, since
	 * this admin-context filter chain can miss front-end-only plugins'
	 * rules.
	 *
	 * @return string
	 */
	private static function build_seed_default() {
		remove_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), PHP_INT_MAX );

		// Mirrors WordPress core's do_robots() in wp-includes/functions.php.
		// $public is cast to bool to match the type current core (WP 6.8+)
		// passes to the 'robots_txt' filter; our own filter (and any other
		// well-behaved one) treats it via truthiness, so this is safe for
		// older core too.
		$output = "User-agent: *\n";
		$public = (bool) get_option( 'blog_public' );

		// These are robots.txt directives (mirroring WordPress core's
		// do_robots()), NOT a JavaScript AJAX endpoint. The admin and
		// admin-ajax paths are derived from admin_url() rather than a
		// hardcoded "/wp-admin/…" so this stays correct on installs where
		// the admin location differs.
		$admin_path = wp_parse_url( admin_url( '/' ), PHP_URL_PATH );
		$ajax_path  = wp_parse_url( admin_url( 'admin-ajax.php' ), PHP_URL_PATH );
		if ( is_string( $admin_path ) && '' !== $admin_path ) {
			$output .= 'Disallow: ' . $admin_path . "\n";
		}
		if ( is_string( $ajax_path ) && '' !== $ajax_path ) {
			$output .= 'Allow: ' . $ajax_path . "\n";
		}

		$seed = apply_filters( 'robots_txt', $output, $public ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Intentionally applying WordPress core's own robots_txt filter to seed the current output.

		add_filter( 'robots_txt', array( __CLASS__, 'filter_robots_txt' ), PHP_INT_MAX, 2 );

		return $seed;
	}

	/**
	 * Create (seeding with the current live robots.txt output where
	 * possible) or update the physical robots.txt with the managed block,
	 * and switch settings to physical mode.
	 *
	 * Seeding prefers a live HTTP fetch of the site's own /robots.txt (via
	 * get_live_preview()) over the admin-context apply_filters() approach,
	 * since the latter runs inside wp-admin and can miss rules added only
	 * by front-end-hooked plugins. The apply_filters()-based seed is used
	 * only as a fallback when the HTTP fetch fails, in which case the
	 * caller is warned (via a transient consumed by PHCITE_Admin) to verify
	 * the seeded content against their actual live robots.txt.
	 *
	 * @return true|WP_Error
	 */
	public static function physicalize() {
		$settings = PHCITE_Settings::get_settings();
		$body     = self::build_block_body( $settings['robots']['bots'] );

		$seed_is_fallback = false;

		if ( PHCITE_Filewriter::exists( 'robots.txt' ) ) {
			$current = PHCITE_Filewriter::read( 'robots.txt' );
			if ( false === $current ) {
				return new WP_Error( 'phcite_read_failed', __( 'Could not read the existing robots.txt file.', 'presshangar-ai-citations' ) );
			}
		} else {
			$live = self::get_live_preview();

			if ( false !== $live ) {
				$current = PHCITE_Filewriter::has_block( $live ) ? PHCITE_Filewriter::remove_block( $live ) : $live;
			} else {
				$current          = self::build_seed_default();
				$seed_is_fallback = true;
			}
		}

		$new_content = PHCITE_Filewriter::upsert_block( $current, $body );
		if ( is_wp_error( $new_content ) ) {
			return $new_content;
		}

		// The guard must stay up through update_settings() below too, not
		// just the write() call: update_settings() -> update_option() is
		// what actually fires maybe_sync_physical_block() (via the
		// update_option_{option} action), and that's the call we need to
		// suppress here to avoid a redundant second write.
		self::$writing = true;
		$result        = PHCITE_Filewriter::write( 'robots.txt', $new_content );

		if ( is_wp_error( $result ) ) {
			self::$writing = false;
			return $result;
		}

		$settings['robots']['mode'] = 'physical';
		PHCITE_Settings::update_settings( $settings );
		self::$writing = false;

		delete_transient( 'phcite_robots_live_preview' );

		if ( $seed_is_fallback ) {
			set_transient( 'phcite_robots_seed_fallback', 1, 5 * MINUTE_IN_SECONDS );
		} else {
			delete_transient( 'phcite_robots_seed_fallback' );
		}

		return true;
	}

	/**
	 * Remove the managed block from the physical robots.txt (the rest of
	 * the file, and the file itself, are left in place) and switch settings
	 * back to virtual mode.
	 *
	 * @return true|WP_Error
	 */
	public static function remove_physical_block() {
		if ( ! PHCITE_Filewriter::exists( 'robots.txt' ) ) {
			return new WP_Error( 'phcite_no_file', __( 'No physical robots.txt file exists.', 'presshangar-ai-citations' ) );
		}

		$current = PHCITE_Filewriter::read( 'robots.txt' );
		if ( false === $current ) {
			return new WP_Error( 'phcite_read_failed', __( 'Could not read the existing robots.txt file.', 'presshangar-ai-citations' ) );
		}

		$new_content = PHCITE_Filewriter::remove_block( $current );

		// See the comment in physicalize(): keep the guard up through
		// update_settings() as well, not just write().
		self::$writing = true;
		$result        = PHCITE_Filewriter::write( 'robots.txt', $new_content );

		if ( is_wp_error( $result ) ) {
			self::$writing = false;
			return $result;
		}

		$settings                   = PHCITE_Settings::get_settings();
		$settings['robots']['mode'] = 'virtual';
		PHCITE_Settings::update_settings( $settings );
		self::$writing = false;

		delete_transient( 'phcite_robots_live_preview' );

		return true;
	}

	/**
	 * Fired on every save of the settings option. When physical mode is
	 * active and a physical robots.txt already exists, re-applies the
	 * managed block so bot allow/block changes take effect immediately
	 * without requiring a separate "physicalize" click. Best-effort: on
	 * failure the error is stashed in a transient for an admin notice
	 * rather than interrupting the settings save. No-ops while
	 * physicalize()/remove_physical_block() are themselves mid-write, to
	 * avoid a redundant second write of the same content.
	 *
	 * @param array $old_value Previous settings array.
	 * @param array $new_value New settings array.
	 */
	public static function maybe_sync_physical_block( $old_value, $new_value ) {
		if ( self::$writing ) {
			return;
		}

		if ( ! is_array( $new_value ) || 'physical' !== ( $new_value['robots']['mode'] ?? '' ) ) {
			return;
		}

		if ( ! PHCITE_Filewriter::exists( 'robots.txt' ) ) {
			return;
		}

		$current = PHCITE_Filewriter::read( 'robots.txt' );
		if ( false === $current ) {
			return;
		}

		$body        = self::build_block_body( $new_value['robots']['bots'] );
		$new_content = PHCITE_Filewriter::upsert_block( $current, $body );

		if ( is_wp_error( $new_content ) ) {
			set_transient( 'phcite_robots_sync_error', $new_content->get_error_message(), MINUTE_IN_SECONDS * 5 );

			// Same manual-copy stash PHCITE_Admin's handlers use, so the user
			// sees the same "here's the file, go fix/remove the stray
			// marker" textarea even though this fired from a settings save
			// rather than a dedicated admin-post action.
			$data = $new_content->get_error_data();
			if ( is_array( $data ) && isset( $data['content'] ) ) {
				set_transient(
					'phcite_manual_copy_' . absint( get_current_user_id() ),
					array(
						'filename' => 'robots.txt',
						'content'  => $data['content'],
					),
					10 * MINUTE_IN_SECONDS
				);
			}

			return;
		}

		$result = PHCITE_Filewriter::write( 'robots.txt', $new_content );

		if ( is_wp_error( $result ) ) {
			set_transient( 'phcite_robots_sync_error', $result->get_error_message(), MINUTE_IN_SECONDS * 5 );
			return;
		}

		delete_transient( 'phcite_robots_live_preview' );
	}

	/**
	 * Best-effort fetch of the site's currently live robots.txt (whatever a
	 * visitor's browser would actually see), for the admin preview and as
	 * the preferred seed source for physicalize(). Cached in a 5-minute
	 * transient (busted after any physical robots.txt write) so repeated
	 * page loads of the admin screen don't re-fetch on every render. Fails
	 * silently — callers should simply hide the preview / fall back to the
	 * apply_filters()-based seed on false.
	 *
	 * @return string|false
	 */
	public static function get_live_preview() {
		$cached = get_transient( 'phcite_robots_live_preview' );
		if ( false !== $cached ) {
			return $cached;
		}

		$response = wp_remote_get(
			home_url( '/robots.txt' ),
			array(
				'timeout'     => 3,
				'redirection' => 1,
			)
		);

		if ( is_wp_error( $response ) || 200 !== (int) wp_remote_retrieve_response_code( $response ) ) {
			return false;
		}

		$body = wp_remote_retrieve_body( $response );

		set_transient( 'phcite_robots_live_preview', $body, 5 * MINUTE_IN_SECONDS );

		return $body;
	}
}
