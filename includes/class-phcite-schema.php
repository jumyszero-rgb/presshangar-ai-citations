<?php
/**
 * FAQPage schema auto-generator: detects "Q. ... A. ..." style FAQ content
 * inside a post's own content and emits FAQPage JSON-LD for it, without any
 * edits to the post itself.
 *
 * The detection pattern, safety limits, and escaping in extract_qas() are
 * ported from the owner's field-tested snippet exactly (スニペット準拠):
 * preg pattern shape, wp_strip_all_tags(), the mb_strlen() minimums, the
 * max-10 cap, the min-2 skip, and JSON_UNESCAPED_UNICODE. Only the Q/A
 * prefix text itself, the counts, and the length minimums are made
 * configurable — the surrounding regex and safety-check structure is
 * unchanged.
 *
 * @package PressHangar AI Citations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCITE_Schema
 */
class PHCITE_Schema {

	/**
	 * Content larger than this (in bytes) is skipped rather than scanned,
	 * to bound worst-case regex cost on pathologically large posts.
	 */
	const MAX_CONTENT_BYTES = 307200; // 300 KB.

	/**
	 * Machine-readable reason the most recent extract_qas() call returned no
	 * results due to a content/engine issue rather than "no Q&A found":
	 * '' | 'content_too_large' | 'regex_error'.
	 *
	 * @var string
	 */
	private static $last_extraction_issue = '';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'wp_footer', array( __CLASS__, 'output_schema' ), 99 );
	}

	/**
	 * Build the preg pattern for the configured Q/A prefixes. The literal
	 * prefix text is preg_quote()'d as-is (so a custom prefix that already
	 * includes its own punctuation, e.g. "Q." or "質問:", is matched
	 * literally), followed by an *optional* half-width/full-width period or
	 * colon. This still matches the original snippet's own default content
	 * ("Q. "/"A. ", "Ｑ．"/"Ａ．") since a required-looking period there is
	 * just the first (and in practice only) character the optional group
	 * consumes.
	 *
	 * @param string $q_prefix Question marker text, e.g. "Q".
	 * @param string $a_prefix Answer marker text, e.g. "A".
	 * @return string Full preg pattern including delimiters and flags.
	 */
	private static function build_pattern( $q_prefix, $a_prefix ) {
		$q = preg_quote( $q_prefix, '/' );
		$a = preg_quote( $a_prefix, '/' );

		return '/<strong>\s*' . $q . '[\.．:：]?\s*(.+?)<\/strong>\s*(?:<br\s*\/?\s*>)?\s*' . $a . '[\.．:：]?\s*(.+?)(?:<\/p>|$)/su';
	}

	/**
	 * Extract candidate Q&A pairs from raw post content, applying the same
	 * per-item safety filters as the original snippet (minimum question/
	 * answer length, capped at the configured maximum count).
	 *
	 * @param string $content  Raw post_content.
	 * @param array  $settings The 'schema' settings branch.
	 * @return array[] List of array( 'q' => string, 'a' => string ), already trimmed and tag-stripped.
	 */
	public static function extract_qas( $content, $settings ) {
		self::$last_extraction_issue = '';
		$faq                         = array();

		if ( ! is_string( $content ) || '' === $content ) {
			return $faq;
		}

		if ( strlen( $content ) > self::MAX_CONTENT_BYTES ) {
			self::$last_extraction_issue = 'content_too_large';
			set_transient( 'phcite_schema_skip_notice', 'content_too_large', 5 * MINUTE_IN_SECONDS );
			return $faq;
		}

		$pattern = self::build_pattern( $settings['q_prefix'], $settings['a_prefix'] );
		$matched = preg_match_all( $pattern, $content, $matches, PREG_SET_ORDER );

		if ( false === $matched || PREG_NO_ERROR !== preg_last_error() ) {
			self::$last_extraction_issue = 'regex_error';
			set_transient( 'phcite_schema_skip_notice', 'regex_error', 5 * MINUTE_IN_SECONDS );
			return $faq;
		}

		if ( 0 === $matched ) {
			return $faq;
		}

		$min_q_len = (int) $settings['min_q_len'];
		$min_a_len = (int) $settings['min_a_len'];
		$max_faq   = (int) $settings['max_faq'];

		foreach ( $matches as $qa ) {
			$q = trim( html_entity_decode( wp_strip_all_tags( $qa[1] ), ENT_QUOTES, 'UTF-8' ) );
			$a = trim( html_entity_decode( wp_strip_all_tags( $qa[2] ), ENT_QUOTES, 'UTF-8' ) );

			if ( mb_strlen( $q ) < $min_q_len || mb_strlen( $a ) < $min_a_len ) {
				continue;
			}

			$faq[] = array(
				'q' => $q,
				'a' => $a,
			);

			if ( count( $faq ) >= $max_faq ) {
				break;
			}
		}

		return $faq;
	}

	/**
	 * Machine-readable reason the most recent extract_qas() call returned no
	 * results due to a content/engine issue (too large, regex failure)
	 * rather than simply finding no Q&A pairs.
	 *
	 * @return string '' | 'content_too_large' | 'regex_error'.
	 */
	public static function get_last_extraction_issue() {
		return self::$last_extraction_issue;
	}

	/**
	 * Known reasons FAQPage output would be (or was) skipped for a given
	 * post, checked in order. Returns '' if output should proceed.
	 *
	 * @param int    $post_id  Post ID.
	 * @param string $content  Raw post_content.
	 * @param array  $settings The 'schema' settings branch.
	 * @return string Machine-readable skip reason, or '' if none apply.
	 */
	public static function get_skip_reason( $post_id, $content, $settings ) {
		if ( empty( $settings['enabled'] ) ) {
			return 'disabled';
		}

		if ( ! in_array( get_post_type( $post_id ), (array) $settings['post_types'], true ) ) {
			return 'post_type';
		}

		if ( post_password_required( $post_id ) ) {
			return 'password_protected';
		}

		if ( ! empty( $settings['exclude_categories'] ) && has_category( $settings['exclude_categories'], $post_id ) ) {
			return 'excluded_category';
		}

		if ( self::has_existing_faq_jsonld( $content ) ) {
			return 'existing_jsonld';
		}

		if ( self::has_known_faq_block( $content ) ) {
			return 'existing_faq_block';
		}

		$faq = self::extract_qas( $content, $settings );

		$issue = self::get_last_extraction_issue();
		if ( '' !== $issue ) {
			return $issue; // 'content_too_large' | 'regex_error'.
		}

		if ( count( $faq ) < (int) $settings['min_faq'] ) {
			return 'too_few_qas';
		}

		return '';
	}

	/**
	 * Whether the content already contains a FAQPage JSON-LD script tag
	 * (dual-output guard).
	 *
	 * @param string $content Raw post_content.
	 * @return bool
	 */
	public static function has_existing_faq_jsonld( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		return (bool) preg_match( '/<script[^>]*application\/ld\+json[^>]*>.*?FAQPage.*?<\/script>/is', $content );
	}

	/**
	 * Whether the content contains a known third-party FAQ block that
	 * likely already outputs its own FAQPage schema (dual-output guard).
	 *
	 * @param string $content Raw post_content.
	 * @return bool
	 */
	public static function has_known_faq_block( $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			return false;
		}

		return false !== strpos( $content, '<!-- wp:yoast/faq-block' )
			|| false !== strpos( $content, '<!-- wp:rank-math/faq-block' );
	}

	/**
	 * wp_footer callback: outputs FAQPage JSON-LD for the current singular
	 * post, if applicable.
	 */
	public static function output_schema() {
		$settings = PHCITE_Settings::get_settings()['schema'];

		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! is_singular( (array) $settings['post_types'] ) ) {
			return;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		if ( post_password_required( $post_id ) ) {
			return;
		}

		if ( ! empty( $settings['exclude_categories'] ) && has_category( $settings['exclude_categories'], $post_id ) ) {
			return;
		}

		$content = get_post_field( 'post_content', $post_id );
		if ( ! $content ) {
			return;
		}

		if ( self::has_existing_faq_jsonld( $content ) || self::has_known_faq_block( $content ) ) {
			return;
		}

		$faq = self::extract_qas( $content, $settings );
		if ( count( $faq ) < (int) $settings['min_faq'] ) {
			return;
		}

		$main_entity = array();
		foreach ( $faq as $qa ) {
			$main_entity[] = array(
				'@type'          => 'Question',
				'name'           => $qa['q'],
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => $qa['a'],
				),
			);
		}

		$json = array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $main_entity,
		);

		echo '<script type="application/ld+json">'
			. wp_json_encode( $json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG )
			. '</script>' . "\n";
	}
}
