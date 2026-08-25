<?php
/**
 * Settings API integration (option storage, sanitization).
 *
 * All PressHangar AI Citations settings live in a single option ('phcite_settings') with three
 * top-level branches: 'robots', 'schema', 'llms' — one per admin tab. Each
 * tab's form only submits its own branch (plus a hidden '_tab' marker), so
 * sanitize_settings() merges the submitted branch into the existing full
 * settings array rather than replacing it wholesale. Programmatic callers
 * (e.g. PHCITE_Robots::physicalize()) instead pass a full settings array with
 * no '_tab' key, which is re-validated branch by branch.
 *
 * @package PressHangar AI Citations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCITE_Settings
 */
class PHCITE_Settings {

	/**
	 * Settings page slug.
	 */
	const PAGE_SLUG = 'presshangar-ai-citations';

	/**
	 * Settings API option group.
	 */
	const OPTION_GROUP = 'phcite_settings_group';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
	}

	/**
	 * Canonical list of AI bots PressHangar AI Citations can add explicit robots.txt rules
	 * for. Extend via the 'phcite_bot_list' filter.
	 *
	 * @return array<string,array{label:string,description:string,default:string}>
	 */
	public static function get_bot_list() {
		$bots = array(
			'GPTBot'              => array(
				'label'       => 'GPTBot',
				'description' => __( "OpenAI's crawler used to train GPT models. Allowing it lets OpenAI use your content as training data.", 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'OAI-SearchBot'       => array(
				'label'       => 'OAI-SearchBot',
				'description' => __( "OpenAI's crawler for ChatGPT's search feature. Allowing it lets your pages appear as sources in ChatGPT search results.", 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'ChatGPT-User'        => array(
				'label'       => 'ChatGPT-User',
				'description' => __( 'Fetches a page on behalf of a user who pasted your URL into ChatGPT. Allowing it lets ChatGPT read and summarize that specific page when asked.', 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'Google-Extended'     => array(
				'label'       => 'Google-Extended',
				'description' => __( "Controls whether Google may use your content to train Gemini and other AI models, separate from regular Googlebot indexing.", 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'PerplexityBot'       => array(
				'label'       => 'PerplexityBot',
				'description' => __( "Perplexity's crawler that indexes content for its answer engine. Allowing it lets your pages be cited in Perplexity's answers.", 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'Perplexity-User'     => array(
				'label'       => 'Perplexity-User',
				'description' => __( 'Fetches a page on behalf of a user who shared your URL with Perplexity, similar to ChatGPT-User.', 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'ClaudeBot'           => array(
				'label'       => 'ClaudeBot',
				'description' => __( "Anthropic's crawler used to train Claude models. Allowing it lets Anthropic use your content as training data.", 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'Claude-Web'          => array(
				'label'       => 'Claude-Web',
				'description' => __( "Anthropic's crawler that fetches a page in real time when a Claude user references its URL.", 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'Applebot-Extended'   => array(
				'label'       => 'Applebot-Extended',
				'description' => __( 'Controls whether Apple may use your content to train Apple Intelligence features, separate from regular Applebot indexing.', 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'meta-externalagent'  => array(
				'label'       => 'meta-externalagent',
				'description' => __( "Meta's crawler used to train AI models and improve products such as Meta AI.", 'presshangar-ai-citations' ),
				'default'     => 'allow',
			),
			'Bytespider'          => array(
				'label'       => 'Bytespider',
				'description' => __( "ByteDance's (TikTok) crawler. Its purpose and AI use are less clearly disclosed than the bots above, so no rule is set by default.", 'presshangar-ai-citations' ),
				'default'     => 'none',
			),
			'CCBot'               => array(
				'label'       => 'CCBot',
				'description' => __( 'Common Crawl\'s crawler. Its open dataset is reused to train many different companies\' AI models, not just one.', 'presshangar-ai-citations' ),
				'default'     => 'none',
			),
			'Amazonbot'           => array(
				'label'       => 'Amazonbot',
				'description' => __( "Amazon's general-purpose crawler (Alexa, shopping, and AI features). Not focused on assistant citations, so no rule is set by default.", 'presshangar-ai-citations' ),
				'default'     => 'none',
			),
		);

		/**
		 * Filter the list of AI bots PressHangar AI Citations offers Allow/Block/No rule
		 * controls for.
		 *
		 * @param array $bots Bot slug => array( label, description, default ).
		 */
		return apply_filters( 'phcite_bot_list', $bots );
	}

	/**
	 * Default settings values.
	 *
	 * @return array
	 */
	public static function get_defaults() {
		$bots = array();
		foreach ( self::get_bot_list() as $slug => $bot ) {
			$bots[ $slug ] = isset( $bot['default'] ) ? $bot['default'] : 'none';
		}

		return array(
			'robots'   => array(
				'mode' => 'virtual',
				'bots' => $bots,
			),
			'schema'   => array(
				'enabled'            => true,
				'q_prefix'           => 'Q',
				'a_prefix'           => 'A',
				'max_faq'            => 10,
				'min_faq'            => 2,
				'min_q_len'          => 5,
				'min_a_len'          => 10,
				'post_types'         => array( 'post' ),
				'exclude_categories' => array(),
			),
			'llms'     => array(
				'description' => '',
				'pages'       => home_url( '/' ),
			),
			'indexnow' => array(
				'enabled'      => false,
				'key'          => '',
				'auto_publish' => true,
				'auto_update'  => false,
			),
		);
	}

	/**
	 * Get current settings, deep-merged with defaults so every key is
	 * always present regardless of plugin version history.
	 *
	 * @return array
	 */
	public static function get_settings() {
		$defaults = self::get_defaults();
		$saved    = get_option( PHCITE_OPTION_SETTINGS, array() );

		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$settings = $defaults;

		foreach ( array( 'robots', 'schema', 'llms', 'indexnow' ) as $branch ) {
			if ( isset( $saved[ $branch ] ) && is_array( $saved[ $branch ] ) ) {
				$settings[ $branch ] = array_merge( $defaults[ $branch ], $saved[ $branch ] );
			}
		}

		// Bots is itself an assoc array that needs the same defaulting.
		if ( isset( $saved['robots']['bots'] ) && is_array( $saved['robots']['bots'] ) ) {
			$settings['robots']['bots'] = array_merge( $defaults['robots']['bots'], $saved['robots']['bots'] );
		}

		return $settings;
	}

	/**
	 * Persist a full settings array (still passes through sanitize_settings()
	 * via WordPress's per-option sanitize_option filter).
	 *
	 * @param array $settings Full settings array (all three branches).
	 * @return bool
	 */
	public static function update_settings( $settings ) {
		return update_option( PHCITE_OPTION_SETTINGS, $settings );
	}

	/**
	 * Register the setting with the Settings API. Sections/fields are
	 * rendered manually by PHCITE_Admin per tab rather than via
	 * add_settings_field(), since the three tabs need very different field
	 * layouts (a bot table, an FAQ preview tool, textareas) that don't map
	 * cleanly onto the one-row-per-field Settings API model.
	 */
	public static function register_settings() {
		register_setting(
			self::OPTION_GROUP,
			PHCITE_OPTION_SETTINGS,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize_settings' ),
				'default'           => self::get_defaults(),
			)
		);
	}

	/**
	 * Sanitize/validate the settings array on save.
	 *
	 * @param mixed $input Raw input: either a single tab's submission
	 *                      (array with a '_tab' key) or a full, already-shaped
	 *                      settings array (programmatic update).
	 * @return array Sanitized full settings array.
	 */
	public static function sanitize_settings( $input ) {
		$existing = self::get_settings();

		if ( ! is_array( $input ) ) {
			$input = array();
		}

		if ( isset( $input['_tab'] ) ) {
			$tab    = sanitize_key( $input['_tab'] );
			$output = $existing;

			switch ( $tab ) {
				case 'robots':
					$output['robots'] = self::sanitize_robots( isset( $input['robots'] ) ? $input['robots'] : array(), $existing['robots'] );
					break;
				case 'schema':
					$output['schema'] = self::sanitize_schema( isset( $input['schema'] ) ? $input['schema'] : array(), $existing['schema'] );
					break;
				case 'llms':
					$output['llms'] = self::sanitize_llms( isset( $input['llms'] ) ? $input['llms'] : array(), $existing['llms'] );

					// Keep the physical file's content in sync with settings
					// immediately, the same way physical robots.txt is kept
					// in sync, so the "llms.txt exists" status stays accurate
					// without requiring a separate "Regenerate" click.
					if ( class_exists( 'PHCITE_Llms_Txt' ) && class_exists( 'PHCITE_Filewriter' ) && PHCITE_Llms_Txt::exists() ) {
						$sync_result = PHCITE_Filewriter::write( 'llms.txt', PHCITE_Llms_Txt::build_content( $output['llms'] ) );
						if ( is_wp_error( $sync_result ) ) {
							set_transient( 'phcite_llms_sync_error', $sync_result->get_error_message(), MINUTE_IN_SECONDS * 5 );
						}
					}
					break;
				case 'indexnow':
					$output['indexnow'] = self::sanitize_indexnow( isset( $input['indexnow'] ) ? $input['indexnow'] : array(), $existing['indexnow'] );
					break;
			}

			// The Settings API only auto-adds its generic "Settings saved."
			// message under whatever slug core happens to use; register our
			// own under our own known option slug so render_notices()'s
			// settings_errors( PHCITE_OPTION_SETTINGS ) call always finds it.
			add_settings_error( PHCITE_OPTION_SETTINGS, 'phcite_settings_saved', __( 'Settings saved.', 'presshangar-ai-citations' ), 'success' );

			return $output;
		}

		// Programmatic full update: re-validate every branch.
		return array(
			'robots'   => self::sanitize_robots( isset( $input['robots'] ) ? $input['robots'] : $existing['robots'], $existing['robots'] ),
			'schema'   => self::sanitize_schema( isset( $input['schema'] ) ? $input['schema'] : $existing['schema'], $existing['schema'] ),
			'llms'     => self::sanitize_llms( isset( $input['llms'] ) ? $input['llms'] : $existing['llms'], $existing['llms'] ),
			'indexnow' => self::sanitize_indexnow( isset( $input['indexnow'] ) ? $input['indexnow'] : $existing['indexnow'], $existing['indexnow'] ),
		);
	}

	/**
	 * Sanitize the 'indexnow' branch. Generates a fresh key the first time
	 * IndexNow is enabled with no key yet on record; an existing key is
	 * always preserved as-is (there is no user-facing "regenerate key"
	 * control, since a key rotation would silently invalidate the file URL
	 * already indexed by IndexNow's participants).
	 *
	 * @param array $input    Raw indexnow input.
	 * @param array $existing Existing (already-sanitized) indexnow branch, used as a fallback.
	 * @return array
	 */
	private static function sanitize_indexnow( $input, $existing ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$output = array();

		$output['enabled']      = ! empty( $input['enabled'] );
		$output['auto_publish'] = ! empty( $input['auto_publish'] );
		$output['auto_update']  = ! empty( $input['auto_update'] );

		$key = isset( $existing['key'] ) ? (string) $existing['key'] : '';

		if ( $output['enabled'] && '' === $key && class_exists( 'PHCITE_Indexnow' ) ) {
			$key = PHCITE_Indexnow::generate_key();
		}

		$output['key'] = $key;

		return $output;
	}

	/**
	 * Sanitize the 'robots' branch.
	 *
	 * @param array $input    Raw robots input.
	 * @param array $existing Existing (already-sanitized) robots branch, used as a fallback.
	 * @return array
	 */
	private static function sanitize_robots( $input, $existing ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$mode = isset( $input['mode'] ) ? sanitize_key( $input['mode'] ) : $existing['mode'];
		if ( ! in_array( $mode, array( 'virtual', 'physical' ), true ) ) {
			$mode = $existing['mode'];
		}

		$bot_list  = self::get_bot_list();
		$raw_bots  = isset( $input['bots'] ) && is_array( $input['bots'] ) ? $input['bots'] : array();
		$out_bots  = array();
		$valid_set = array( 'allow', 'block', 'none' );

		foreach ( $bot_list as $slug => $bot ) {
			$state = isset( $raw_bots[ $slug ] ) ? sanitize_key( $raw_bots[ $slug ] ) : null;
			if ( ! in_array( $state, $valid_set, true ) ) {
				$state = isset( $existing['bots'][ $slug ] ) ? $existing['bots'][ $slug ] : $bot['default'];
			}
			if ( ! in_array( $state, $valid_set, true ) ) {
				$state = $bot['default'];
			}
			$out_bots[ $slug ] = $state;
		}

		return array(
			'mode' => $mode,
			'bots' => $out_bots,
		);
	}

	/**
	 * Sanitize the 'schema' branch.
	 *
	 * @param array $input    Raw schema input.
	 * @param array $existing Existing (already-sanitized) schema branch, used as a fallback.
	 * @return array
	 */
	private static function sanitize_schema( $input, $existing ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$defaults = self::get_defaults()['schema'];
		$output   = array();

		$output['enabled'] = ! empty( $input['enabled'] );

		// Note: no wp_unslash() here — options.php has already unslashed
		// $_POST before this sanitize callback runs; unslashing again would
		// strip backslashes the user actually typed.
		$q_prefix = isset( $input['q_prefix'] ) ? sanitize_text_field( $input['q_prefix'] ) : $existing['q_prefix'];
		$a_prefix = isset( $input['a_prefix'] ) ? sanitize_text_field( $input['a_prefix'] ) : $existing['a_prefix'];
		$output['q_prefix'] = ( '' !== trim( $q_prefix ) ) ? $q_prefix : $defaults['q_prefix'];
		$output['a_prefix'] = ( '' !== trim( $a_prefix ) ) ? $a_prefix : $defaults['a_prefix'];

		$max_faq = isset( $input['max_faq'] ) ? (int) $input['max_faq'] : $existing['max_faq'];
		$min_faq = isset( $input['min_faq'] ) ? (int) $input['min_faq'] : $existing['min_faq'];
		$max_faq = max( 1, min( 50, $max_faq ) );
		$min_faq = max( 1, min( 50, $min_faq ) );
		if ( $min_faq > $max_faq ) {
			$min_faq = $max_faq;
		}
		$output['max_faq'] = $max_faq;
		$output['min_faq'] = $min_faq;

		$min_q_len = isset( $input['min_q_len'] ) ? (int) $input['min_q_len'] : $existing['min_q_len'];
		$min_a_len = isset( $input['min_a_len'] ) ? (int) $input['min_a_len'] : $existing['min_a_len'];
		$output['min_q_len'] = max( 1, min( 200, $min_q_len ) );
		$output['min_a_len'] = max( 1, min( 500, $min_a_len ) );

		$post_types = array();
		if ( ! empty( $input['post_types'] ) && is_array( $input['post_types'] ) ) {
			foreach ( $input['post_types'] as $post_type ) {
				$post_type = sanitize_key( $post_type );
				if ( post_type_exists( $post_type ) ) {
					$post_types[] = $post_type;
				}
			}
		}
		$output['post_types'] = ! empty( $post_types ) ? array_values( array_unique( $post_types ) ) : $defaults['post_types'];

		$exclude_categories = array();
		if ( ! empty( $input['exclude_categories'] ) && is_array( $input['exclude_categories'] ) ) {
			foreach ( $input['exclude_categories'] as $term_id ) {
				$term_id = absint( $term_id );
				if ( $term_id > 0 && term_exists( $term_id, 'category' ) ) {
					$exclude_categories[] = $term_id;
				}
			}
		}
		$output['exclude_categories'] = array_values( array_unique( $exclude_categories ) );

		return $output;
	}

	/**
	 * Sanitize the 'llms' branch.
	 *
	 * @param array $input    Raw llms input.
	 * @param array $existing Existing (already-sanitized) llms branch, used as a fallback.
	 * @return array
	 */
	private static function sanitize_llms( $input, $existing ) {
		if ( ! is_array( $input ) ) {
			$input = array();
		}

		$output = array();

		// Note: no wp_unslash() here — options.php has already unslashed
		// $_POST before this sanitize callback runs.
		$description           = isset( $input['description'] ) ? sanitize_textarea_field( $input['description'] ) : $existing['description'];
		$output['description'] = $description;

		$pages_raw = isset( $input['pages'] ) ? (string) $input['pages'] : $existing['pages'];
		$lines     = preg_split( '/\r\n|\r|\n/', $pages_raw );
		$urls      = array();

		foreach ( (array) $lines as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$url = esc_url_raw( $line );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		$output['pages'] = ! empty( $urls ) ? implode( "\n", $urls ) : home_url( '/' );

		return $output;
	}
}
