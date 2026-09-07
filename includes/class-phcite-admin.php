<?php
/**
 * Admin screen: tabbed settings page (AI Crawlers / FAQ Schema / llms.txt),
 * sidebar status + measurement links, and the admin-post.php handlers for
 * every file-touching action (physicalize, remove block, restore backup,
 * generate/remove llms.txt).
 *
 * @package PressHangar AI Citations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCITE_Admin
 */
class PHCITE_Admin {

	const NONCE_ROBOTS_PHYSICALIZE = 'phcite_robots_physicalize';
	const NONCE_ROBOTS_REMOVE      = 'phcite_robots_remove_block';
	const NONCE_ROBOTS_RESTORE     = 'phcite_robots_restore_backup';
	const NONCE_LLMS_GENERATE      = 'phcite_llms_generate';
	const NONCE_LLMS_REMOVE        = 'phcite_llms_remove';
	const NONCE_LLMS_RESTORE       = 'phcite_llms_restore_backup';
	const NONCE_FAQ_PREVIEW        = 'phcite_faq_preview';
	const NONCE_INDEXNOW_MANUAL    = 'phcite_indexnow_manual_submit';

	/**
	 * Option flag: set to 1 once the user has dismissed the review request,
	 * so it never appears again on this site.
	 */
	const OPTION_REVIEW_DISMISSED = 'phcite_review_dismissed';

	/**
	 * Transient key (per user) used to hand the intended file content to
	 * the manual-copy textarea fallback after a failed physical write.
	 *
	 * @param int $user_id Current user ID.
	 * @return string
	 */
	private static function manual_copy_transient_key( $user_id ) {
		return 'phcite_manual_copy_' . absint( $user_id );
	}

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_notices', array( __CLASS__, 'render_notices' ) );

		add_action( 'admin_post_phcite_robots_physicalize', array( __CLASS__, 'handle_robots_physicalize' ) );
		add_action( 'admin_post_phcite_robots_remove_block', array( __CLASS__, 'handle_robots_remove_block' ) );
		add_action( 'admin_post_phcite_robots_restore_backup', array( __CLASS__, 'handle_robots_restore_backup' ) );
		add_action( 'admin_post_phcite_llms_generate', array( __CLASS__, 'handle_llms_generate' ) );
		add_action( 'admin_post_phcite_llms_remove', array( __CLASS__, 'handle_llms_remove' ) );
		add_action( 'admin_post_phcite_llms_restore_backup', array( __CLASS__, 'handle_llms_restore_backup' ) );
		add_action( 'admin_post_phcite_indexnow_manual_submit', array( __CLASS__, 'handle_indexnow_manual_submit' ) );
	}

	/**
	 * Register the "Settings > PressHangar AI Citations" submenu page.
	 */
	public static function add_menu() {
		add_options_page(
			__( 'PressHangar AI Citations', 'presshangar-ai-citations' ),
			__( 'PressHangar AI Citations', 'presshangar-ai-citations' ),
			'manage_options',
			PHCITE_Settings::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Build the redirect-back URL to a given settings tab with a notice
	 * query arg attached.
	 *
	 * @param string $tab    Tab slug.
	 * @param array  $extra  Extra query args (e.g. 'phcite_notice' => 'physicalized').
	 * @return string
	 */
	private static function redirect_url( $tab, $extra = array() ) {
		$args = array_merge(
			array(
				'page' => PHCITE_Settings::PAGE_SLUG,
				'tab'  => $tab,
			),
			$extra
		);

		return add_query_arg( $args, admin_url( 'options-general.php' ) );
	}

	/**
	 * Store a failed write's intended content for the manual-copy fallback.
	 *
	 * @param string $filename Managed filename, e.g. 'robots.txt'.
	 * @param string $content  Intended content.
	 */
	private static function stash_manual_copy( $filename, $content ) {
		set_transient(
			self::manual_copy_transient_key( get_current_user_id() ),
			array(
				'filename' => $filename,
				'content'  => $content,
			),
			10 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Clear the current user's stashed manual-copy content if it was for
	 * the given filename — called after any subsequent successful write of
	 * that same file, since the stashed (failed) content is now stale.
	 *
	 * @param string $filename Managed filename, e.g. 'robots.txt'.
	 */
	private static function clear_manual_copy_if_matches( $filename ) {
		$key    = self::manual_copy_transient_key( get_current_user_id() );
		$stored = get_transient( $key );

		if ( is_array( $stored ) && isset( $stored['filename'] ) && $filename === $stored['filename'] ) {
			delete_transient( $key );
		}
	}

	/**
	 * Handle the "make robots.txt physical" admin-post action.
	 */
	public static function handle_robots_physicalize() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-ai-citations' ) );
		}
		check_admin_referer( self::NONCE_ROBOTS_PHYSICALIZE );

		$result = PHCITE_Robots::physicalize();

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['content'] ) ) {
				self::stash_manual_copy( 'robots.txt', $data['content'] );
			}
			set_transient( 'phcite_admin_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::redirect_url( 'robots', array( 'phcite_notice' => 'error' ) ) );
			exit;
		}

		self::clear_manual_copy_if_matches( 'robots.txt' );
		wp_safe_redirect( self::redirect_url( 'robots', array( 'phcite_notice' => 'physicalized' ) ) );
		exit;
	}

	/**
	 * Handle the "remove managed block from robots.txt" admin-post action.
	 */
	public static function handle_robots_remove_block() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-ai-citations' ) );
		}
		check_admin_referer( self::NONCE_ROBOTS_REMOVE );

		$result = PHCITE_Robots::remove_physical_block();

		if ( is_wp_error( $result ) ) {
			set_transient( 'phcite_admin_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::redirect_url( 'robots', array( 'phcite_notice' => 'error' ) ) );
			exit;
		}

		self::clear_manual_copy_if_matches( 'robots.txt' );
		wp_safe_redirect( self::redirect_url( 'robots', array( 'phcite_notice' => 'block_removed' ) ) );
		exit;
	}

	/**
	 * Handle the "restore robots.txt from backup" admin-post action.
	 */
	public static function handle_robots_restore_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-ai-citations' ) );
		}
		check_admin_referer( self::NONCE_ROBOTS_RESTORE );

		$backup = isset( $_POST['phcite_backup_file'] ) ? sanitize_file_name( wp_unslash( $_POST['phcite_backup_file'] ) ) : '';
		$result = PHCITE_Filewriter::restore_backup( 'robots.txt', $backup );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['content'] ) ) {
				self::stash_manual_copy( 'robots.txt', $data['content'] );
			}
			set_transient( 'phcite_admin_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::redirect_url( 'robots', array( 'phcite_notice' => 'error' ) ) );
			exit;
		}

		self::clear_manual_copy_if_matches( 'robots.txt' );
		delete_transient( 'phcite_robots_live_preview' );
		wp_safe_redirect( self::redirect_url( 'robots', array( 'phcite_notice' => 'restored' ) ) );
		exit;
	}

	/**
	 * Handle the "generate/update llms.txt" admin-post action.
	 */
	public static function handle_llms_generate() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-ai-citations' ) );
		}
		check_admin_referer( self::NONCE_LLMS_GENERATE );

		$result = PHCITE_Llms_Txt::generate();

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['content'] ) ) {
				self::stash_manual_copy( 'llms.txt', $data['content'] );
			}
			set_transient( 'phcite_admin_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::redirect_url( 'llms', array( 'phcite_notice' => 'error' ) ) );
			exit;
		}

		self::clear_manual_copy_if_matches( 'llms.txt' );
		wp_safe_redirect( self::redirect_url( 'llms', array( 'phcite_notice' => 'generated' ) ) );
		exit;
	}

	/**
	 * Handle the "remove llms.txt" admin-post action.
	 */
	public static function handle_llms_remove() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-ai-citations' ) );
		}
		check_admin_referer( self::NONCE_LLMS_REMOVE );

		$result = PHCITE_Llms_Txt::remove();

		if ( is_wp_error( $result ) ) {
			set_transient( 'phcite_admin_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::redirect_url( 'llms', array( 'phcite_notice' => 'error' ) ) );
			exit;
		}

		self::clear_manual_copy_if_matches( 'llms.txt' );
		wp_safe_redirect( self::redirect_url( 'llms', array( 'phcite_notice' => 'removed' ) ) );
		exit;
	}

	/**
	 * Handle the "restore llms.txt from backup" admin-post action.
	 */
	public static function handle_llms_restore_backup() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-ai-citations' ) );
		}
		check_admin_referer( self::NONCE_LLMS_RESTORE );

		$backup = isset( $_POST['phcite_backup_file'] ) ? sanitize_file_name( wp_unslash( $_POST['phcite_backup_file'] ) ) : '';
		$result = PHCITE_Filewriter::restore_backup( 'llms.txt', $backup );

		if ( is_wp_error( $result ) ) {
			$data = $result->get_error_data();
			if ( is_array( $data ) && isset( $data['content'] ) ) {
				self::stash_manual_copy( 'llms.txt', $data['content'] );
			}
			set_transient( 'phcite_admin_error_' . get_current_user_id(), $result->get_error_message(), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::redirect_url( 'llms', array( 'phcite_notice' => 'error' ) ) );
			exit;
		}

		self::clear_manual_copy_if_matches( 'llms.txt' );
		wp_safe_redirect( self::redirect_url( 'llms', array( 'phcite_notice' => 'restored' ) ) );
		exit;
	}

	/**
	 * Handle the "manually submit URLs to IndexNow" admin-post action.
	 */
	public static function handle_indexnow_manual_submit() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'presshangar-ai-citations' ) );
		}
		check_admin_referer( self::NONCE_INDEXNOW_MANUAL );

		$settings = PHCITE_Settings::get_settings()['indexnow'];

		if ( empty( $settings['enabled'] ) || empty( $settings['key'] ) ) {
			set_transient( 'phcite_admin_error_' . get_current_user_id(), __( 'IndexNow is not enabled, so no URLs were submitted.', 'presshangar-ai-citations' ), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::redirect_url( 'indexnow', array( 'phcite_notice' => 'error' ) ) );
			exit;
		}

		$raw   = isset( $_POST['phcite_indexnow_urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['phcite_indexnow_urls'] ) ) : '';
		$lines = preg_split( '/[\r\n]+/', (string) $raw );
		$lines = is_array( $lines ) ? array_map( 'trim', $lines ) : array();
		$lines = array_filter( $lines, static function ( $line ) {
			return '' !== $line;
		} );

		$valid = PHCITE_Indexnow::filter_own_host_urls( $lines );

		if ( empty( $valid ) ) {
			set_transient( 'phcite_admin_error_' . get_current_user_id(), __( 'No valid URLs on this site were found in the submitted list (max 100, must match this site\'s host).', 'presshangar-ai-citations' ), 5 * MINUTE_IN_SECONDS );
			wp_safe_redirect( self::redirect_url( 'indexnow', array( 'phcite_notice' => 'error' ) ) );
			exit;
		}

		PHCITE_Indexnow::submit_urls( $valid );

		wp_safe_redirect( self::redirect_url( 'indexnow', array( 'phcite_notice' => 'indexnow_submitted' ) ) );
		exit;
	}

	/**
	 * Whether the current admin screen is this plugin's settings page.
	 *
	 * @return bool
	 */
	private static function is_plugin_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return $screen && false !== strpos( (string) $screen->id, PHCITE_Settings::PAGE_SLUG );
	}

	/**
	 * Render admin notices for the plugin screen: action feedback, stashed
	 * errors, and settings-API errors.
	 */
	public static function render_notices() {
		if ( ! current_user_can( 'manage_options' ) || ! self::is_plugin_screen() ) {
			return;
		}

		settings_errors( PHCITE_OPTION_SETTINGS );

		$user_id = get_current_user_id();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, selects which success notice to display.
		if ( isset( $_GET['phcite_notice'] ) ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$notice = sanitize_key( wp_unslash( $_GET['phcite_notice'] ) );

			$messages = array(
				'physicalized' => __( 'robots.txt was written to your site root. Its output now permanently overrides the virtual robots.txt WordPress would otherwise generate.', 'presshangar-ai-citations' ),
				'block_removed' => __( 'The PressHangar AI Citations block was removed from robots.txt. The rest of the file (and the file itself) was left in place.', 'presshangar-ai-citations' ),
				'restored'     => __( 'The file was restored from the selected backup. The version it replaced was itself backed up first.', 'presshangar-ai-citations' ),
				'generated'    => __( 'llms.txt was written to your site root.', 'presshangar-ai-citations' ),
				'removed'      => __( 'llms.txt was deleted (a backup was kept).', 'presshangar-ai-citations' ),
				'indexnow_submitted' => __( 'The submitted URLs were sent to IndexNow. Check the log below for the result.', 'presshangar-ai-citations' ),
			);

			if ( 'error' === $notice ) {
				$error_message = get_transient( 'phcite_admin_error_' . $user_id );
				delete_transient( 'phcite_admin_error_' . $user_id );
				printf(
					'<div class="notice notice-error is-dismissible"><p>%s</p></div>',
					esc_html( $error_message ? $error_message : __( 'The action could not be completed.', 'presshangar-ai-citations' ) )
				);
			} elseif ( isset( $messages[ $notice ] ) ) {
				printf(
					'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
					esc_html( $messages[ $notice ] )
				);
			}
		}

		$sync_error = get_transient( 'phcite_robots_sync_error' );
		if ( $sync_error ) {
			delete_transient( 'phcite_robots_sync_error' );
			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html__( 'PressHangar AI Citations could not update the physical robots.txt file after your settings change:', 'presshangar-ai-citations' ),
				esc_html( $sync_error )
			);
		}

		$llms_sync_error = get_transient( 'phcite_llms_sync_error' );
		if ( $llms_sync_error ) {
			delete_transient( 'phcite_llms_sync_error' );
			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html__( 'PressHangar AI Citations could not update the physical llms.txt file after your settings change:', 'presshangar-ai-citations' ),
				esc_html( $llms_sync_error )
			);
		}

		$virtual_malformed = get_transient( 'phcite_robots_virtual_malformed' );
		if ( $virtual_malformed ) {
			delete_transient( 'phcite_robots_virtual_malformed' );
			printf(
				'<div class="notice notice-error"><p>%s %s</p></div>',
				esc_html__( 'PressHangar AI Citations could not add its rules to the virtual robots.txt output:', 'presshangar-ai-citations' ),
				esc_html( $virtual_malformed )
			);
		}

		if ( get_transient( 'phcite_robots_seed_fallback' ) ) {
			delete_transient( 'phcite_robots_seed_fallback' );
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'PressHangar AI Citations could not fetch your site\'s live robots.txt to seed the new physical file, so it seeded from WordPress\'s internal defaults instead. Please compare the new robots.txt against your site\'s actual live output and add anything missing by hand (for example, rules added only by a front-end-only plugin).', 'presshangar-ai-citations' )
			);
		}

		$settings = PHCITE_Settings::get_settings();
		if ( 'physical' === $settings['robots']['mode'] && ! PHCITE_Filewriter::exists( 'robots.txt' ) ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'PressHangar AI Citations is set to physical robots.txt mode, but the physical file no longer exists (it may have been removed outside the plugin). PressHangar AI Citations is serving its virtual robots.txt output as a fallback in the meantime. Click "Make physical" again to re-create the file, or switch back to virtual mode.', 'presshangar-ai-citations' )
			);
		}

		$schema_skip = get_transient( 'phcite_schema_skip_notice' );
		if ( $schema_skip ) {
			delete_transient( 'phcite_schema_skip_notice' );
			$label = ( 'content_too_large' === $schema_skip )
				? __( 'PressHangar AI Citations skipped FAQ schema generation on at least one recent page view because the post content was too large to scan safely.', 'presshangar-ai-citations' )
				: __( 'PressHangar AI Citations skipped FAQ schema generation on at least one recent page view: the content was too complex for the FAQ pattern to scan safely.', 'presshangar-ai-citations' );
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html( $label )
			);
		}
	}

	/**
	 * Gather status-card data.
	 *
	 * @return array
	 */
	private static function get_status() {
		$settings = PHCITE_Settings::get_settings();

		$allow_count = 0;
		$block_count = 0;
		foreach ( $settings['robots']['bots'] as $state ) {
			if ( 'allow' === $state ) {
				++$allow_count;
			} elseif ( 'block' === $state ) {
				++$block_count;
			}
		}

		return array(
			'robots_mode'            => $settings['robots']['mode'],
			'robots_physical_exists' => PHCITE_Filewriter::exists( 'robots.txt' ),
			'allow_count'            => $allow_count,
			'block_count'            => $block_count,
			'schema_status'          => $settings['schema'],
			'llms_exists'            => PHCITE_Llms_Txt::exists(),
			'indexnow_enabled'       => ! empty( $settings['indexnow']['enabled'] ),
		);
	}

	/**
	 * Process a submitted FAQ preview form (post ID or URL), if present.
	 * Read-only: never saves anything.
	 *
	 * @return array|null Array with keys 'input', 'post', 'skip_reason', 'faq' — or null if no form was submitted.
	 */
	private static function maybe_handle_faq_preview() {
		if ( empty( $_POST['phcite_faq_preview_submit'] ) ) {
			return null;
		}

		check_admin_referer( self::NONCE_FAQ_PREVIEW, 'phcite_faq_preview_nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			return null;
		}

		$raw_input = isset( $_POST['phcite_faq_preview_target'] ) ? sanitize_text_field( wp_unslash( $_POST['phcite_faq_preview_target'] ) ) : '';
		if ( '' === $raw_input ) {
			return array(
				'input'       => '',
				'post'        => null,
				'skip_reason' => 'empty_input',
				'faq'         => array(),
			);
		}

		$post_id = 0;
		if ( ctype_digit( $raw_input ) ) {
			$post_id = (int) $raw_input;
		} else {
			$post_id = url_to_postid( $raw_input );
		}

		$post = $post_id ? get_post( $post_id ) : null;

		if ( ! $post ) {
			return array(
				'input'       => $raw_input,
				'post'        => null,
				'skip_reason' => 'not_found',
				'faq'         => array(),
			);
		}

		$settings    = PHCITE_Settings::get_settings()['schema'];
		$content     = get_post_field( 'post_content', $post );
		$skip_reason = PHCITE_Schema::get_skip_reason( $post->ID, $content, $settings );
		$faq         = PHCITE_Schema::extract_qas( $content, $settings );

		return array(
			'input'       => $raw_input,
			'post'        => $post,
			'skip_reason' => $skip_reason,
			'faq'         => $faq,
		);
	}

	/**
	 * Human-readable label for a skip reason code.
	 *
	 * @param string $reason Skip reason code.
	 * @return string
	 */
	private static function skip_reason_label( $reason ) {
		$labels = array(
			''                    => __( 'This page would get FAQPage schema output.', 'presshangar-ai-citations' ),
			'empty_input'         => __( 'Enter a post ID or URL above.', 'presshangar-ai-citations' ),
			'not_found'           => __( 'No post was found for that ID or URL.', 'presshangar-ai-citations' ),
			'disabled'            => __( 'FAQ schema generation is turned off.', 'presshangar-ai-citations' ),
			'post_type'           => __( "This content's post type is not in the selected list.", 'presshangar-ai-citations' ),
			'password_protected'  => __( 'This post is password protected.', 'presshangar-ai-citations' ),
			'excluded_category'   => __( 'This post is in an excluded category.', 'presshangar-ai-citations' ),
			'existing_jsonld'     => __( 'This post already contains its own FAQPage JSON-LD, so PressHangar AI Citations skips it to avoid duplicate output.', 'presshangar-ai-citations' ),
			'existing_faq_block'  => __( 'This post already uses a Yoast or Rank Math FAQ block, so PressHangar AI Citations skips it to avoid duplicate output.', 'presshangar-ai-citations' ),
			'too_few_qas'         => __( 'Fewer Q&A pairs were detected than the configured minimum.', 'presshangar-ai-citations' ),
			'content_too_large'   => __( 'This content is too large (over 300 KB) to scan safely, so it was skipped.', 'presshangar-ai-citations' ),
			'regex_error'         => __( 'The FAQ pattern could not be evaluated on this content (too complex), so it was skipped.', 'presshangar-ai-citations' ),
		);

		return isset( $labels[ $reason ] ) ? $labels[ $reason ] : $reason;
	}

	/**
	 * Record the user's choice to dismiss the review request. Fired from the
	 * "No thanks" link on the review card; guarded by capability + nonce.
	 *
	 * @return void
	 */
	private static function maybe_dismiss_review() {
		if ( ! isset( $_GET['phcite_review_off'] ) || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'phcite_review_off' ) ) {
			return;
		}
		update_option( self::OPTION_REVIEW_DISMISSED, 1 );
	}

	/**
	 * A gentle, dismissible request for a wordpress.org review. Shows only
	 * after the user has actually used the plugin (saved settings at least
	 * once) and never again once dismissed. No incentive is offered.
	 *
	 * @param bool $earned Whether the user has used the plugin enough to ask.
	 * @return void
	 */
	private static function render_review_ask( $earned ) {
		if ( ! $earned || get_option( self::OPTION_REVIEW_DISMISSED ) ) {
			return;
		}

		$review_url  = 'https://wordpress.org/support/plugin/presshangar-ai-citations/reviews/#new-post';
		$dismiss_url = wp_nonce_url( self::redirect_url( 'robots', array( 'phcite_review_off' => 1 ) ), 'phcite_review_off' );

		$html  = '<div style="border:1px solid #c3c4c7;border-left:4px solid #f6a72a;background:#fff;border-radius:4px;padding:12px 16px;max-width:820px;margin:16px 0;">';
		$html .= '<p style="margin:.2em 0 .6em;">' . esc_html__( 'Finding this plugin useful? A quick review really helps others discover it — thank you!', 'presshangar-ai-citations' ) . '</p>';
		$html .= '<a href="' . esc_url( $review_url ) . '" target="_blank" rel="noopener" class="button button-primary" style="margin-right:.6em;">' . esc_html__( 'Leave a review ★★★★★', 'presshangar-ai-citations' ) . '</a>';
		$html .= '<a href="' . esc_url( $dismiss_url ) . '" style="color:#50575e;text-decoration:none;">' . esc_html__( 'No thanks', 'presshangar-ai-citations' ) . '</a>';
		$html .= '</div>';

		echo wp_kses_post( $html );
	}

	/**
	 * Render the settings page.
	 */
	/**
	 * "Getting started" panel: a short, state-aware checklist so a first-time
	 * user knows what to do and what is already done.
	 *
	 * @param array $settings Current settings.
	 * @return void
	 */
	private static function render_getting_started( $settings ) {
		$saved_once   = ( false !== get_option( PHCITE_OPTION_SETTINGS, false ) );
		$schema_on    = ! empty( $settings['schema']['enabled'] );
		$llms_made    = class_exists( 'PHCITE_Llms_Txt' ) && PHCITE_Llms_Txt::exists();
		$indexnow_on  = ! empty( $settings['indexnow']['enabled'] ) && ! empty( $settings['indexnow']['key'] );

		$steps = array(
			array(
				'done'  => $saved_once,
				'title' => __( 'Choose which AI crawlers may read your site', 'presshangar-ai-citations' ),
				'desc'  => __( 'On the "AI Crawlers" tab, allow the assistants you want to be cited by (ChatGPT, Claude, Perplexity…) and block the rest. Then Save.', 'presshangar-ai-citations' ),
				'tab'   => 'robots',
			),
			array(
				'done'  => $schema_on,
				'title' => __( 'Keep FAQ schema on', 'presshangar-ai-citations' ),
				'desc'  => __( 'Turns "Q. … A. …" text in your posts into FAQ structured data, which AI and search engines can quote. It is on by default.', 'presshangar-ai-citations' ),
				'tab'   => 'schema',
			),
			array(
				'done'  => $llms_made,
				'title' => __( 'Generate your llms.txt file', 'presshangar-ai-citations' ),
				'desc'  => __( 'A simple guide file that tells AI assistants what your site is about and where the key pages are. Create it on the "llms.txt" tab.', 'presshangar-ai-citations' ),
				'tab'   => 'llms',
			),
			array(
				'done'  => $indexnow_on,
				'title' => __( 'Turn on IndexNow', 'presshangar-ai-citations' ),
				'desc'  => __( 'Instantly tells Bing, Yandex and other engines when you publish or update a page. Generate a key and enable it on the "IndexNow" tab.', 'presshangar-ai-citations' ),
				'tab'   => 'indexnow',
			),
		);

		echo '<div style="border:1px solid #c3c4c7;border-left:4px solid #2271b1;background:#fff;border-radius:4px;padding:12px 16px;max-width:820px;margin:12px 0;">';
		echo '<h2 style="margin-top:.2em">' . esc_html__( 'Getting started', 'presshangar-ai-citations' ) . '</h2>';
		echo '<ol style="margin:0;padding-left:0;list-style:none;">';
		$i = 0;
		foreach ( $steps as $step ) {
			$i++;
			$badge = ! empty( $step['done'] )
				? '<span style="display:inline-block;width:1.7em;color:#00a32a;font-weight:700;">&#10004;</span>'
				: '<span style="display:inline-block;width:1.7em;color:#2271b1;font-weight:700;">' . (int) $i . '.</span>';
			echo '<li style="margin:.4em 0;">' . $badge // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static markup.
				. '<a href="' . esc_url( self::redirect_url( $step['tab'] ) ) . '"><strong>' . esc_html( $step['title'] ) . '</strong></a>'
				. '<br /><span style="color:#50575e;margin-left:1.7em;display:inline-block;">' . esc_html( $step['desc'] ) . '</span></li>';
		}
		echo '</ol></div>';
	}

	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		self::maybe_dismiss_review();
		$review_earned = ( false !== get_option( PHCITE_OPTION_SETTINGS, false ) );

		$settings = PHCITE_Settings::get_settings();
		$status   = self::get_status();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only, selects which tab to display.
		$active_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'robots';
		if ( ! in_array( $active_tab, array( 'robots', 'schema', 'llms', 'indexnow' ), true ) ) {
			$active_tab = 'robots';
		}

		$faq_preview = ( 'schema' === $active_tab ) ? self::maybe_handle_faq_preview() : null;

		$manual_copy_key = self::manual_copy_transient_key( get_current_user_id() );
		$manual_copy     = get_transient( $manual_copy_key );
		if ( $manual_copy ) {
			// Show-once: once rendered, don't keep re-showing it on every
			// subsequent page load.
			delete_transient( $manual_copy_key );
		}

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'PressHangar AI Citations', 'presshangar-ai-citations' ); ?></h1>
			<p><?php esc_html_e( 'Help AI assistants like ChatGPT, Gemini, and Perplexity find, crawl, and cite your content. New here? Follow the steps below — it only takes a few minutes.', 'presshangar-ai-citations' ); ?></p>

			<?php self::render_getting_started( $settings ); ?>

			<h2 class="nav-tab-wrapper">
				<a href="<?php echo esc_url( self::redirect_url( 'robots' ) ); ?>" class="nav-tab <?php echo 'robots' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'AI Crawlers', 'presshangar-ai-citations' ); ?></a>
				<a href="<?php echo esc_url( self::redirect_url( 'schema' ) ); ?>" class="nav-tab <?php echo 'schema' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'FAQ Schema', 'presshangar-ai-citations' ); ?></a>
				<a href="<?php echo esc_url( self::redirect_url( 'llms' ) ); ?>" class="nav-tab <?php echo 'llms' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'llms.txt', 'presshangar-ai-citations' ); ?></a>
				<a href="<?php echo esc_url( self::redirect_url( 'indexnow' ) ); ?>" class="nav-tab <?php echo 'indexnow' === $active_tab ? 'nav-tab-active' : ''; ?>"><?php esc_html_e( 'IndexNow', 'presshangar-ai-citations' ); ?></a>
			</h2>

			<div style="display:flex;gap:24px;align-items:flex-start;margin-top:16px;">
				<div style="flex:1 1 auto;min-width:0;">
					<?php if ( $manual_copy && is_array( $manual_copy ) ) : ?>
						<div class="card" style="max-width:800px;border-left:4px solid #d63638;">
							<h2>
								<?php
								printf(
									/* translators: %s: filename, e.g. robots.txt */
									esc_html__( 'Manual copy needed for %s', 'presshangar-ai-citations' ),
									esc_html( $manual_copy['filename'] )
								);
								?>
							</h2>
							<p><?php esc_html_e( 'PressHangar AI Citations could not write this file automatically. Copy the text below and upload it to your site root via FTP or your host\'s file manager.', 'presshangar-ai-citations' ); ?></p>
							<textarea readonly="readonly" onclick="this.select();" rows="12" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $manual_copy['content'] ); ?></textarea>
						</div>
					<?php endif; ?>

					<?php if ( 'robots' === $active_tab ) : ?>
						<?php self::render_robots_tab( $settings, $status ); ?>
					<?php elseif ( 'schema' === $active_tab ) : ?>
						<?php self::render_schema_tab( $settings, $faq_preview ); ?>
					<?php elseif ( 'indexnow' === $active_tab ) : ?>
						<?php self::render_indexnow_tab( $settings ); ?>
					<?php else : ?>
						<?php self::render_llms_tab( $settings ); ?>
					<?php endif; ?>
				</div>

				<div style="flex:0 0 300px;">
					<?php self::render_sidebar( $status ); ?>
				</div>
			</div>

			<?php self::render_review_ask( $review_earned ); ?>
		</div>
		<?php
	}

	/**
	 * Render the "AI Crawlers" tab.
	 *
	 * @param array $settings Full settings array.
	 * @param array $status   Status-card data.
	 */
	private static function render_robots_tab( $settings, $status ) {
		$bots = PHCITE_Settings::get_bot_list();
		?>
		<div class="card" style="max-width:800px;">
			<h2><?php esc_html_e( 'Output mode', 'presshangar-ai-citations' ); ?></h2>
			<p>
				<?php if ( 'physical' === $status['robots_mode'] && ! $status['robots_physical_exists'] ) : ?>
					<strong style="color:#a00;"><?php esc_html_e( 'Physical file missing — serving virtual fallback', 'presshangar-ai-citations' ); ?></strong>
					<?php esc_html_e( 'PressHangar AI Citations is set to physical mode, but the physical robots.txt file is gone (likely removed outside the plugin). Virtual output is being served as a fallback until you re-create it.', 'presshangar-ai-citations' ); ?>
				<?php elseif ( 'physical' === $status['robots_mode'] ) : ?>
					<strong style="color:#008a20;"><?php esc_html_e( 'Physical file — active', 'presshangar-ai-citations' ); ?></strong>
					<?php esc_html_e( 'A real robots.txt file exists at your site root. It always wins over any virtual robots.txt output, including this plugin\'s own.', 'presshangar-ai-citations' ); ?>
				<?php else : ?>
					<strong><?php esc_html_e( 'Virtual (default)', 'presshangar-ai-citations' ); ?></strong>
					<?php esc_html_e( 'PressHangar AI Citations adds its rules to WordPress\'s generated robots.txt on the fly. This is overridden if any physical robots.txt file exists on the server (from this plugin or another).', 'presshangar-ai-citations' ); ?>
				<?php endif; ?>
			</p>
			<p>
				<strong><?php esc_html_e( 'Why physical mode exists:', 'presshangar-ai-citations' ); ?></strong>
				<?php esc_html_e( 'On some setups, another plugin or server rule can silently override the virtual robots.txt even at maximum filter priority. Writing a real file guarantees your AI-crawler rules take effect. Physicalizing captures the current virtual output (including other plugins\' rules) as a starting point — after that, the physical file is fully independent and virtual output no longer has any effect.', 'presshangar-ai-citations' ); ?>
			</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5em;">
				<input type="hidden" name="action" value="phcite_robots_physicalize" />
				<?php wp_nonce_field( self::NONCE_ROBOTS_PHYSICALIZE ); ?>
				<?php submit_button( 'physical' === $status['robots_mode'] ? __( 'Re-apply to physical file', 'presshangar-ai-citations' ) : __( 'Make physical (write robots.txt)', 'presshangar-ai-citations' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( 'physical' === $status['robots_mode'] ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Remove the PressHangar AI Citations block from robots.txt? The rest of the file is kept.', 'presshangar-ai-citations' ) ); ?>');">
					<input type="hidden" name="action" value="phcite_robots_remove_block" />
					<?php wp_nonce_field( self::NONCE_ROBOTS_REMOVE ); ?>
					<?php submit_button( __( 'Remove managed block', 'presshangar-ai-citations' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php self::render_backup_list( 'robots.txt', self::NONCE_ROBOTS_RESTORE, 'phcite_robots_restore_backup' ); ?>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( PHCITE_Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[_tab]" value="robots" />

			<div class="card" style="max-width:800px;">
				<h2><?php esc_html_e( 'AI bot rules', 'presshangar-ai-citations' ); ?></h2>
				<p><?php esc_html_e( 'Choose Allow, Block, or No rule (omit entirely) for each bot. These rules are combined into a single managed block in robots.txt.', 'presshangar-ai-citations' ); ?></p>
				<table class="widefat striped" style="max-width:760px;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Bot', 'presshangar-ai-citations' ); ?></th>
							<th><?php esc_html_e( 'What it does', 'presshangar-ai-citations' ); ?></th>
							<th style="width:220px;"><?php esc_html_e( 'Rule', 'presshangar-ai-citations' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $bots as $slug => $bot ) : ?>
							<?php $current = isset( $settings['robots']['bots'][ $slug ] ) ? $settings['robots']['bots'][ $slug ] : $bot['default']; ?>
							<tr>
								<td><code><?php echo esc_html( $bot['label'] ); ?></code></td>
								<td><?php echo esc_html( $bot['description'] ); ?></td>
								<td>
									<select name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[robots][bots][<?php echo esc_attr( $slug ); ?>]">
										<option value="allow" <?php selected( $current, 'allow' ); ?>><?php esc_html_e( 'Allow', 'presshangar-ai-citations' ); ?></option>
										<option value="block" <?php selected( $current, 'block' ); ?>><?php esc_html_e( 'Block', 'presshangar-ai-citations' ); ?></option>
										<option value="none" <?php selected( $current, 'none' ); ?>><?php esc_html_e( 'No rule', 'presshangar-ai-citations' ); ?></option>
									</select>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php submit_button( __( 'Save AI Crawler Settings', 'presshangar-ai-citations' ) ); ?>
			</div>
		</form>

		<?php
		$live = PHCITE_Robots::get_live_preview();
		if ( false !== $live ) :
			?>
			<div class="card" style="max-width:800px;">
				<h2><?php esc_html_e( 'Current live robots.txt', 'presshangar-ai-citations' ); ?></h2>
				<p class="description"><?php esc_html_e( 'This is what a visitor to your site sees right now at /robots.txt.', 'presshangar-ai-citations' ); ?></p>
				<textarea readonly="readonly" rows="10" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $live ); ?></textarea>
			</div>
			<?php
		endif;
	}

	/**
	 * Render the "FAQ Schema" tab.
	 *
	 * @param array      $settings    Full settings array.
	 * @param array|null $faq_preview Preview tool result, or null if not submitted.
	 */
	private static function render_schema_tab( $settings, $faq_preview ) {
		$schema      = $settings['schema'];
		$post_types  = get_post_types( array( 'public' => true ), 'objects' );
		$categories  = get_categories( array( 'hide_empty' => false ) );
		$selected_pt = (array) $schema['post_types'];
		$selected_cat = array_map( 'absint', (array) $schema['exclude_categories'] );
		?>
		<form method="post" action="options.php">
			<?php settings_fields( PHCITE_Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[_tab]" value="schema" />

			<div class="card" style="max-width:800px;">
				<h2><?php esc_html_e( 'FAQPage schema generation', 'presshangar-ai-citations' ); ?></h2>
				<p><?php esc_html_e( 'Detects "Q. question / A. answer" pairs inside a post\'s own content and outputs FAQPage structured data (JSON-LD) automatically. No changes are made to the post itself.', 'presshangar-ai-citations' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'presshangar-ai-citations' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][enabled]" value="1" <?php checked( ! empty( $schema['enabled'] ) ); ?> />
								<?php esc_html_e( 'Automatically output FAQPage schema where detected.', 'presshangar-ai-citations' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Question / Answer markers', 'presshangar-ai-citations' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( 'Question prefix', 'presshangar-ai-citations' ); ?>
								<input type="text" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][q_prefix]" value="<?php echo esc_attr( $schema['q_prefix'] ); ?>" style="width:8em;" />
							</label>
							&nbsp;&nbsp;
							<label>
								<?php esc_html_e( 'Answer prefix', 'presshangar-ai-citations' ); ?>
								<input type="text" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][a_prefix]" value="<?php echo esc_attr( $schema['a_prefix'] ); ?>" style="width:8em;" />
							</label>
							<p class="description"><?php esc_html_e( 'Expected pattern: <strong>{Q prefix} question</strong> followed by {A prefix}. answer. For example "Q" / "A" matches "<strong>Q. What is...?</strong> A. It is...". Both full-width and half-width periods are accepted after the question prefix; a period is required after the answer prefix.', 'presshangar-ai-citations' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Question / answer counts', 'presshangar-ai-citations' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( 'Minimum pairs to output', 'presshangar-ai-citations' ); ?>
								<input type="number" min="1" max="50" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][min_faq]" value="<?php echo esc_attr( $schema['min_faq'] ); ?>" style="width:5em;" />
							</label>
							&nbsp;&nbsp;
							<label>
								<?php esc_html_e( 'Maximum pairs to output', 'presshangar-ai-citations' ); ?>
								<input type="number" min="1" max="50" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][max_faq]" value="<?php echo esc_attr( $schema['max_faq'] ); ?>" style="width:5em;" />
							</label>
							<p class="description"><?php esc_html_e( 'Posts with fewer detected pairs than the minimum get no schema output at all.', 'presshangar-ai-citations' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Minimum lengths', 'presshangar-ai-citations' ); ?></th>
						<td>
							<label>
								<?php esc_html_e( 'Question, characters', 'presshangar-ai-citations' ); ?>
								<input type="number" min="1" max="200" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][min_q_len]" value="<?php echo esc_attr( $schema['min_q_len'] ); ?>" style="width:5em;" />
							</label>
							&nbsp;&nbsp;
							<label>
								<?php esc_html_e( 'Answer, characters', 'presshangar-ai-citations' ); ?>
								<input type="number" min="1" max="500" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][min_a_len]" value="<?php echo esc_attr( $schema['min_a_len'] ); ?>" style="width:5em;" />
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Post types', 'presshangar-ai-citations' ); ?></th>
						<td>
							<fieldset>
								<?php foreach ( $post_types as $post_type ) : ?>
									<?php if ( 'attachment' === $post_type->name ) { continue; } ?>
									<label style="margin-right:1em;">
										<input type="checkbox" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][post_types][]" value="<?php echo esc_attr( $post_type->name ); ?>" <?php checked( in_array( $post_type->name, $selected_pt, true ) ); ?> />
										<?php echo esc_html( $post_type->labels->singular_name ); ?>
									</label>
								<?php endforeach; ?>
							</fieldset>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Excluded categories', 'presshangar-ai-citations' ); ?></th>
						<td>
							<select multiple="multiple" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[schema][exclude_categories][]" style="min-width:20em;height:8em;">
								<?php foreach ( $categories as $category ) : ?>
									<option value="<?php echo esc_attr( $category->term_id ); ?>" <?php selected( in_array( (int) $category->term_id, $selected_cat, true ) ); ?>>
										<?php echo esc_html( $category->name ); ?>
									</option>
								<?php endforeach; ?>
							</select>
							<p class="description"><?php esc_html_e( 'No schema is output for posts in these categories. Useful, for example, to exclude a sponsored/PR category where a rich-result FAQ snippet would be inappropriate.', 'presshangar-ai-citations' ); ?></p>
						</td>
					</tr>
				</table>

				<p class="description"><?php esc_html_e( 'Safety: to avoid duplicate structured data, output is automatically skipped for any post that already contains its own FAQPage JSON-LD, or a Yoast or Rank Math FAQ block.', 'presshangar-ai-citations' ); ?></p>

				<?php submit_button( __( 'Save FAQ Schema Settings', 'presshangar-ai-citations' ) ); ?>
			</div>
		</form>

		<div class="card" style="max-width:800px;">
			<h2><?php esc_html_e( 'Preview: what would this page output?', 'presshangar-ai-citations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Enter a post ID or a URL on this site to see what PressHangar AI Citations would detect and whether it would output schema for it. This is read-only — nothing is saved or published.', 'presshangar-ai-citations' ); ?></p>
			<form method="post">
				<?php wp_nonce_field( self::NONCE_FAQ_PREVIEW, 'phcite_faq_preview_nonce' ); ?>
				<input type="text" name="phcite_faq_preview_target" value="<?php echo esc_attr( $faq_preview ? $faq_preview['input'] : '' ); ?>" placeholder="<?php esc_attr_e( 'Post ID or URL', 'presshangar-ai-citations' ); ?>" style="width:24em;" />
				<?php submit_button( __( 'Preview', 'presshangar-ai-citations' ), 'secondary', 'phcite_faq_preview_submit', false ); ?>
			</form>

			<?php if ( null !== $faq_preview ) : ?>
				<div style="margin-top:1em;">
					<?php if ( $faq_preview['post'] ) : ?>
						<p>
							<?php
							printf(
								/* translators: %s: post title. */
								esc_html__( 'Checked: %s', 'presshangar-ai-citations' ),
								esc_html( get_the_title( $faq_preview['post'] ) )
							);
							?>
						</p>
					<?php endif; ?>
					<p><strong><?php echo esc_html( self::skip_reason_label( $faq_preview['skip_reason'] ) ); ?></strong></p>
					<?php if ( ! empty( $faq_preview['faq'] ) ) : ?>
						<table class="widefat striped">
							<thead>
								<tr>
									<th><?php esc_html_e( 'Question', 'presshangar-ai-citations' ); ?></th>
									<th><?php esc_html_e( 'Answer', 'presshangar-ai-citations' ); ?></th>
								</tr>
							</thead>
							<tbody>
								<?php foreach ( $faq_preview['faq'] as $qa ) : ?>
									<tr>
										<td><?php echo esc_html( $qa['q'] ); ?></td>
										<td><?php echo esc_html( $qa['a'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the "llms.txt" tab.
	 *
	 * @param array $settings Full settings array.
	 */
	private static function render_llms_tab( $settings ) {
		$llms = $settings['llms'];
		?>
		<div class="notice notice-info inline" style="margin:0 0 16px;">
			<p>
				<strong><?php esc_html_e( 'Honest note:', 'presshangar-ai-citations' ); ?></strong>
				<?php esc_html_e( 'llms.txt is an emerging, informal convention. There is currently no confirmation that major AI crawlers read or use it, and its effect on citations may be limited. It costs nothing to publish, but treat it as experimental, not a guaranteed win.', 'presshangar-ai-citations' ); ?>
			</p>
		</div>

		<div class="card" style="max-width:800px;">
			<h2><?php esc_html_e( 'Status', 'presshangar-ai-citations' ); ?></h2>
			<p>
				<?php if ( PHCITE_Llms_Txt::exists() ) : ?>
					<strong style="color:#008a20;"><?php esc_html_e( 'llms.txt exists at your site root.', 'presshangar-ai-citations' ); ?></strong>
				<?php else : ?>
					<strong><?php esc_html_e( 'No llms.txt file yet.', 'presshangar-ai-citations' ); ?></strong>
				<?php endif; ?>
			</p>

			<form method="post" action="options.php">
				<?php settings_fields( PHCITE_Settings::OPTION_GROUP ); ?>
				<input type="hidden" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[_tab]" value="llms" />

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Site name / tagline', 'presshangar-ai-citations' ); ?></th>
						<td>
							<p><?php echo esc_html( get_bloginfo( 'name' ) ); ?> — <?php echo esc_html( get_bloginfo( 'description' ) ); ?></p>
							<p class="description"><?php esc_html_e( 'Pulled automatically from Settings > General.', 'presshangar-ai-citations' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Free-text description', 'presshangar-ai-citations' ); ?></th>
						<td>
							<textarea name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[llms][description]" rows="5" style="width:100%;"><?php echo esc_textarea( $llms['description'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'Anything you want an AI assistant reading llms.txt to know about this site: what it covers, how to cite it, etc.', 'presshangar-ai-citations' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Key pages', 'presshangar-ai-citations' ); ?></th>
						<td>
							<textarea name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[llms][pages]" rows="6" style="width:100%;font-family:monospace;"><?php echo esc_textarea( $llms['pages'] ); ?></textarea>
							<p class="description"><?php esc_html_e( 'One URL per line.', 'presshangar-ai-citations' ); ?></p>
						</td>
					</tr>
				</table>
				<?php submit_button( __( 'Save llms.txt Settings', 'presshangar-ai-citations' ) ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5em;">
				<input type="hidden" name="action" value="phcite_llms_generate" />
				<?php wp_nonce_field( self::NONCE_LLMS_GENERATE ); ?>
				<?php submit_button( PHCITE_Llms_Txt::exists() ? __( 'Regenerate llms.txt', 'presshangar-ai-citations' ) : __( 'Generate llms.txt', 'presshangar-ai-citations' ), 'primary', 'submit', false ); ?>
			</form>

			<?php if ( PHCITE_Llms_Txt::exists() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;" onsubmit="return confirm('<?php echo esc_js( __( 'Delete llms.txt? A backup will be kept.', 'presshangar-ai-citations' ) ); ?>');">
					<input type="hidden" name="action" value="phcite_llms_remove" />
					<?php wp_nonce_field( self::NONCE_LLMS_REMOVE ); ?>
					<?php submit_button( __( 'Delete llms.txt', 'presshangar-ai-citations' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>

			<?php self::render_backup_list( 'llms.txt', self::NONCE_LLMS_RESTORE, 'phcite_llms_restore_backup' ); ?>
		</div>
		<?php
	}

	/**
	 * Render the "IndexNow" tab.
	 *
	 * @param array $settings Full settings array.
	 */
	private static function render_indexnow_tab( $settings ) {
		$indexnow = $settings['indexnow'];
		$log      = PHCITE_Indexnow::get_log();
		$queue_count = PHCITE_Indexnow::get_queue_count();
		?>
		<div class="notice notice-info inline" style="margin:0 0 16px;">
			<p>
				<strong><?php esc_html_e( 'Honest note:', 'presshangar-ai-citations' ); ?></strong>
				<?php esc_html_e( 'IndexNow is used by Bing, Yandex, and other participating search engines. Google does not use IndexNow — submit to Google Search Console separately if you want faster Google indexing.', 'presshangar-ai-citations' ); ?>
			</p>
		</div>

		<form method="post" action="options.php">
			<?php settings_fields( PHCITE_Settings::OPTION_GROUP ); ?>
			<input type="hidden" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[_tab]" value="indexnow" />

			<div class="card" style="max-width:800px;">
				<h2><?php esc_html_e( 'IndexNow', 'presshangar-ai-citations' ); ?></h2>
				<p><?php esc_html_e( 'Automatically tell IndexNow-participating search engines about new and updated content, so they can crawl it faster.', 'presshangar-ai-citations' ); ?></p>

				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><?php esc_html_e( 'Enable', 'presshangar-ai-citations' ); ?></th>
						<td>
							<label>
								<input type="checkbox" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[indexnow][enabled]" value="1" <?php checked( ! empty( $indexnow['enabled'] ) ); ?> />
								<?php esc_html_e( 'Turn on IndexNow submissions for this site.', 'presshangar-ai-citations' ); ?>
							</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Auto-submit', 'presshangar-ai-citations' ); ?></th>
						<td>
							<label style="display:block;margin-bottom:.5em;">
								<input type="checkbox" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[indexnow][auto_publish]" value="1" <?php checked( ! empty( $indexnow['auto_publish'] ) ); ?> />
								<?php esc_html_e( 'When a post or page is published.', 'presshangar-ai-citations' ); ?>
							</label>
							<label style="display:block;">
								<input type="checkbox" name="<?php echo esc_attr( PHCITE_OPTION_SETTINGS ); ?>[indexnow][auto_update]" value="1" <?php checked( ! empty( $indexnow['auto_update'] ) ); ?> />
								<?php esc_html_e( 'When an already-published post or page is updated.', 'presshangar-ai-citations' ); ?>
							</label>
							<p class="description"><?php esc_html_e( 'Submissions are queued and sent in the background a few minutes later, so publishing and saving are never slowed down.', 'presshangar-ai-citations' ); ?></p>
						</td>
					</tr>
					<?php if ( ! empty( $indexnow['key'] ) ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Your IndexNow key', 'presshangar-ai-citations' ); ?></th>
							<td>
								<code><?php echo esc_html( $indexnow['key'] ); ?></code>
								<p class="description">
									<?php
									printf(
										/* translators: %s: key file URL. */
										esc_html__( 'Hosted automatically at: %s', 'presshangar-ai-citations' ),
										'<a href="' . esc_url( PHCITE_Indexnow::get_key_url( $indexnow['key'] ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( PHCITE_Indexnow::get_key_url( $indexnow['key'] ) ) . '</a>'
									);
									?>
									<a href="<?php echo esc_url( PHCITE_Indexnow::get_key_url( $indexnow['key'] ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '(verify)', 'presshangar-ai-citations' ); ?></a>
								</p>
							</td>
						</tr>
					<?php else : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Your IndexNow key', 'presshangar-ai-citations' ); ?></th>
							<td><p class="description"><?php esc_html_e( 'A key will be generated automatically the first time you enable and save IndexNow.', 'presshangar-ai-citations' ); ?></p></td>
						</tr>
					<?php endif; ?>
					<tr>
						<th scope="row"><?php esc_html_e( 'Queue', 'presshangar-ai-citations' ); ?></th>
						<td>
							<?php
							printf(
								/* translators: %s: number of URLs currently waiting to be submitted. */
								esc_html( _n( '%s URL waiting to be submitted.', '%s URLs waiting to be submitted.', $queue_count, 'presshangar-ai-citations' ) ),
								esc_html( number_format_i18n( $queue_count ) )
							);
							?>
						</td>
					</tr>
				</table>

				<?php submit_button( __( 'Save IndexNow Settings', 'presshangar-ai-citations' ) ); ?>
			</div>
		</form>

		<div class="card" style="max-width:800px;">
			<h2><?php esc_html_e( 'Manual submit', 'presshangar-ai-citations' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Paste up to 100 URLs on this site, one per line. URLs on other hosts are ignored.', 'presshangar-ai-citations' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="phcite_indexnow_manual_submit" />
				<?php wp_nonce_field( self::NONCE_INDEXNOW_MANUAL ); ?>
				<textarea name="phcite_indexnow_urls" rows="8" style="width:100%;font-family:monospace;" placeholder="<?php echo esc_attr( home_url( '/example-post/' ) ); ?>"></textarea>
				<?php submit_button( __( 'Submit URLs to IndexNow', 'presshangar-ai-citations' ), 'secondary', 'submit', false, empty( $indexnow['enabled'] ) ? array( 'disabled' => 'disabled' ) : array() ); ?>
				<?php if ( empty( $indexnow['enabled'] ) ) : ?>
					<p class="description"><?php esc_html_e( 'Enable and save IndexNow above first.', 'presshangar-ai-citations' ); ?></p>
				<?php endif; ?>
			</form>
		</div>

		<?php if ( ! empty( $log ) ) : ?>
			<div class="card" style="max-width:800px;">
				<h2><?php esc_html_e( 'Submission log', 'presshangar-ai-citations' ); ?></h2>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Time', 'presshangar-ai-citations' ); ?></th>
							<th><?php esc_html_e( 'URLs', 'presshangar-ai-citations' ); ?></th>
							<th><?php esc_html_e( 'Result', 'presshangar-ai-citations' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $log as $entry ) : ?>
							<tr>
								<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry['time'] ) ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $entry['count'] ) ); ?></td>
								<td><?php echo esc_html( $entry['result'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a managed file's backup list with restore buttons.
	 *
	 * @param string $filename    Managed filename.
	 * @param string $nonce_action Nonce action for the restore form.
	 * @param string $post_action admin-post.php action name.
	 */
	private static function render_backup_list( $filename, $nonce_action, $post_action ) {
		$backups = PHCITE_Filewriter::list_backups( $filename );
		if ( empty( $backups ) ) {
			return;
		}
		?>
		<h3><?php esc_html_e( 'Backups', 'presshangar-ai-citations' ); ?></h3>
		<table class="widefat striped" style="max-width:600px;">
			<tbody>
				<?php foreach ( $backups as $backup ) : ?>
					<tr>
						<td><?php echo esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $backup['time'] ) ); ?></td>
						<td><?php echo esc_html( size_format( $backup['size'] ) ); ?></td>
						<td>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo esc_js( __( 'Restore this backup? The current file will itself be backed up first.', 'presshangar-ai-citations' ) ); ?>');">
								<input type="hidden" name="action" value="<?php echo esc_attr( $post_action ); ?>" />
								<input type="hidden" name="phcite_backup_file" value="<?php echo esc_attr( $backup['file'] ); ?>" />
								<?php wp_nonce_field( $nonce_action ); ?>
								<?php submit_button( __( 'Restore', 'presshangar-ai-citations' ), 'secondary', 'submit', false ); ?>
							</form>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Render the sidebar: status card + measurement links.
	 *
	 * @param array $status Status-card data.
	 */
	private static function render_sidebar( $status ) {
		$home = home_url( '/' );
		?>
		<div class="card">
			<h2><?php esc_html_e( 'Current status', 'presshangar-ai-citations' ); ?></h2>
			<table class="widefat">
				<tbody>
					<tr>
						<td><?php esc_html_e( 'robots.txt mode', 'presshangar-ai-citations' ); ?></td>
						<td>
							<?php if ( 'physical' === $status['robots_mode'] && ! $status['robots_physical_exists'] ) : ?>
								<span style="color:#a00;"><?php esc_html_e( 'Physical (file missing)', 'presshangar-ai-citations' ); ?></span>
							<?php elseif ( 'physical' === $status['robots_mode'] ) : ?>
								<?php esc_html_e( 'Physical', 'presshangar-ai-citations' ); ?>
							<?php else : ?>
								<?php esc_html_e( 'Virtual', 'presshangar-ai-citations' ); ?>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Bots allowed', 'presshangar-ai-citations' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $status['allow_count'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'Bots blocked', 'presshangar-ai-citations' ); ?></td>
						<td><?php echo esc_html( number_format_i18n( $status['block_count'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'FAQ schema', 'presshangar-ai-citations' ); ?></td>
						<td><?php echo ! empty( $status['schema_status']['enabled'] ) ? esc_html__( 'Enabled', 'presshangar-ai-citations' ) : esc_html__( 'Disabled', 'presshangar-ai-citations' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'FAQ target', 'presshangar-ai-citations' ); ?></td>
						<td><?php echo esc_html( implode( ', ', (array) $status['schema_status']['post_types'] ) ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'llms.txt', 'presshangar-ai-citations' ); ?></td>
						<td><?php echo $status['llms_exists'] ? esc_html__( 'Present', 'presshangar-ai-citations' ) : esc_html__( 'Not created', 'presshangar-ai-citations' ); ?></td>
					</tr>
					<tr>
						<td><?php esc_html_e( 'IndexNow', 'presshangar-ai-citations' ); ?></td>
						<td><?php echo ! empty( $status['indexnow_enabled'] ) ? esc_html__( 'Enabled', 'presshangar-ai-citations' ) : esc_html__( 'Disabled', 'presshangar-ai-citations' ); ?></td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="card">
			<h2><?php esc_html_e( 'Measure your AI visibility', 'presshangar-ai-citations' ); ?></h2>
			<ul>
				<li><a href="<?php echo esc_url( 'https://www.bing.com/webmasters/home?siteUrl=' . rawurlencode( $home ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Bing Webmaster Tools (AI Performance report)', 'presshangar-ai-citations' ); ?></a></li>
				<li><a href="<?php echo esc_url( 'https://search.google.com/search-console?resource_id=' . rawurlencode( $home ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Search Console', 'presshangar-ai-citations' ); ?></a></li>
				<li><a href="<?php echo esc_url( 'https://search.google.com/test/rich-results?url=' . rawurlencode( $home ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Google Rich Results Test', 'presshangar-ai-citations' ); ?></a></li>
			</ul>
		</div>
		<?php
	}
}
