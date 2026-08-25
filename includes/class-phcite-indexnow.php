<?php
/**
 * IndexNow support: hosts the IndexNow key verification file virtually (no
 * physical file needed), auto-submits published/updated URLs to the
 * IndexNow API on a short delay so publishing is never blocked, supports a
 * manual submit form, and keeps a short log of recent submissions.
 *
 * IndexNow (https://www.indexnow.org/) is a shared protocol used by Bing,
 * Yandex, and a handful of other search engines to let sites push URLs for
 * near-immediate crawling, instead of waiting to be recrawled. In keeping
 * with this plugin's honesty principle: Google does not currently
 * participate in IndexNow, and the admin UI says so plainly.
 *
 * @package PressHangar AI Citations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCITE_Indexnow
 */
class PHCITE_Indexnow {

	/**
	 * Option name for the pending-submission queue (array of URL strings).
	 */
	const OPTION_QUEUE = 'phcite_indexnow_queue';

	/**
	 * Option name for the submission log (array of recent attempts).
	 */
	const OPTION_LOG = 'phcite_indexnow_log';

	/**
	 * Cron hook used to flush the queue a few minutes after the first URL
	 * is enqueued, so a publish/update request is never held up waiting on
	 * an external HTTP call.
	 */
	const CRON_FLUSH = 'phcite_indexnow_flush';

	/**
	 * How long to wait, from the first URL being enqueued, before flushing
	 * the queue.
	 */
	const FLUSH_DELAY = 5 * MINUTE_IN_SECONDS;

	/**
	 * Maximum number of URLs kept in the auto-submit queue at once. Once
	 * full, the oldest URLs are dropped to make room for newly published/
	 * updated ones.
	 */
	const MAX_QUEUE = 200;

	/**
	 * Maximum number of URLs sent in a single IndexNow API submission.
	 */
	const MAX_BATCH = 100;

	/**
	 * Maximum number of recent submissions kept in the log.
	 */
	const MAX_LOG = 50;

	/**
	 * IndexNow API endpoint.
	 */
	const API_URL = 'https://api.indexnow.org/indexnow';

	/**
	 * Hook registration.
	 */
	public static function init() {
		add_action( 'parse_request', array( __CLASS__, 'maybe_serve_key_file' ) );
		add_action( 'transition_post_status', array( __CLASS__, 'handle_status_transition' ), 10, 3 );
		add_action( self::CRON_FLUSH, array( __CLASS__, 'flush_queue' ) );
	}

	/**
	 * Public post types auto-submission applies to. Filterable; the result
	 * is still restricted to post types that currently exist and are
	 * publicly viewable, regardless of what the filter returns.
	 *
	 * @return string[]
	 */
	public static function get_target_post_types() {
		$types = apply_filters( 'phcite_indexnow_post_types', array( 'post', 'page' ) );
		$types = array_filter(
			(array) $types,
			static function ( $post_type ) {
				$obj = get_post_type_object( $post_type );

				return $obj && is_post_type_viewable( $obj );
			}
		);

		return array_values( array_unique( $types ) );
	}

	/**
	 * Generate a fresh 32-character hex IndexNow key.
	 *
	 * @return string
	 */
	public static function generate_key() {
		return bin2hex( random_bytes( 16 ) );
	}

	/**
	 * The full key file URL IndexNow (and the admin "verify" link) expects,
	 * e.g. https://example.com/{key}.txt.
	 *
	 * @param string $key IndexNow key.
	 * @return string
	 */
	public static function get_key_url( $key ) {
		return home_url( '/' . $key . '.txt' );
	}

	/**
	 * parse_request callback: virtually serve the IndexNow key verification
	 * file at "/{key}.txt" — an exact path match only, never a prefix,
	 * suffix, or wildcard match, and the response body is never anything
	 * but the bare key string. Works regardless of whether the site's root
	 * directory is writable, since no physical file is ever created.
	 *
	 * @param WP $wp Main WP request object.
	 */
	public static function maybe_serve_key_file( $wp ) {
		$settings = PHCITE_Settings::get_settings()['indexnow'];

		if ( empty( $settings['enabled'] ) || empty( $settings['key'] ) ) {
			return;
		}

		$requested = self::get_requested_path( $wp );
		$expected  = $settings['key'] . '.txt';

		if ( $expected !== $requested ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo esc_html( $settings['key'] );
		exit;
	}

	/**
	 * Resolve the current request's path relative to the site root, with no
	 * leading/trailing slash and no query string. Prefers $wp->request (set
	 * by WP::parse_request() from the rewrite rules); falls back to parsing
	 * REQUEST_URI directly for sites using plain (non-pretty) permalinks,
	 * where $wp->request is often left empty for a path that matches no
	 * registered rewrite rule.
	 *
	 * @param WP $wp Main WP request object.
	 * @return string
	 */
	private static function get_requested_path( $wp ) {
		$requested = isset( $wp->request ) ? trim( (string) $wp->request, '/' ) : '';

		if ( '' !== $requested || empty( $_SERVER['REQUEST_URI'] ) ) {
			return $requested;
		}

		$uri_path  = wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH );
		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$uri_path  = is_string( $uri_path ) ? $uri_path : '';
		$home_path = is_string( $home_path ) ? $home_path : '/';

		if ( 0 !== strpos( $uri_path, $home_path ) ) {
			return '';
		}

		return trim( substr( $uri_path, strlen( $home_path ) ), '/' );
	}

	/**
	 * transition_post_status callback: enqueue a post's permalink for
	 * IndexNow submission when it's published for the first time, or
	 * re-saved while already published — each gated by its own toggle.
	 *
	 * @param string  $new_status New post status.
	 * @param string  $old_status Previous post status.
	 * @param WP_Post $post       The post being transitioned.
	 */
	public static function handle_status_transition( $new_status, $old_status, $post ) {
		if ( 'publish' !== $new_status ) {
			return;
		}

		$settings = PHCITE_Settings::get_settings()['indexnow'];
		if ( empty( $settings['enabled'] ) ) {
			return;
		}

		if ( ! ( $post instanceof WP_Post ) || ! in_array( $post->post_type, self::get_target_post_types(), true ) ) {
			return;
		}

		$is_new_publish = ( 'publish' !== $old_status );
		$is_update      = ( 'publish' === $old_status );

		if ( $is_new_publish && empty( $settings['auto_publish'] ) ) {
			return;
		}
		if ( $is_update && empty( $settings['auto_update'] ) ) {
			return;
		}

		$url = get_permalink( $post );
		if ( ! $url ) {
			return;
		}

		self::enqueue_url( $url );
	}

	/**
	 * Add a URL to the auto-submit queue (deduplicated, capped at
	 * self::MAX_QUEUE, oldest dropped first) and make sure a flush is
	 * scheduled.
	 *
	 * @param string $url Absolute URL on this site.
	 */
	public static function enqueue_url( $url ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			return;
		}

		$queue = get_option( self::OPTION_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}

		if ( ! in_array( $url, $queue, true ) ) {
			$queue[] = $url;
		}

		if ( count( $queue ) > self::MAX_QUEUE ) {
			$queue = array_slice( $queue, count( $queue ) - self::MAX_QUEUE );
		}

		update_option( self::OPTION_QUEUE, $queue, false );

		if ( ! wp_next_scheduled( self::CRON_FLUSH ) ) {
			wp_schedule_single_event( time() + self::FLUSH_DELAY, self::CRON_FLUSH );
		}
	}

	/**
	 * Cron callback: send up to self::MAX_BATCH queued URLs to IndexNow in
	 * one request, and re-schedule another flush if URLs remain. Runs
	 * entirely inside WP-Cron, decoupled from any publish/update request —
	 * that decoupling, not a non-blocking HTTP call, is what keeps
	 * publishing from ever waiting on IndexNow. The HTTP call itself blocks
	 * (with a short timeout) only within this background request, so its
	 * response code can actually be logged.
	 */
	public static function flush_queue() {
		$queue = get_option( self::OPTION_QUEUE, array() );
		if ( ! is_array( $queue ) || empty( $queue ) ) {
			return;
		}

		$batch     = array_slice( $queue, 0, self::MAX_BATCH );
		$remaining = array_slice( $queue, self::MAX_BATCH );

		update_option( self::OPTION_QUEUE, $remaining, false );

		self::submit_urls( $batch );

		if ( ! empty( $remaining ) && ! wp_next_scheduled( self::CRON_FLUSH ) ) {
			wp_schedule_single_event( time() + self::FLUSH_DELAY, self::CRON_FLUSH );
		}
	}

	/**
	 * Build the JSON-ready request body IndexNow expects for a batch of
	 * URLs.
	 *
	 * @param string[] $urls     URLs to submit.
	 * @param array    $settings The 'indexnow' settings branch (must include a non-empty 'key').
	 * @return array
	 */
	public static function build_request_body( $urls, $settings ) {
		return array(
			'host'        => (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ),
			'key'         => $settings['key'],
			'keyLocation' => self::get_key_url( $settings['key'] ),
			'urlList'     => array_values( $urls ),
		);
	}

	/**
	 * Filter a list of raw URL strings down to valid, on-this-site URLs
	 * only, deduplicated and capped at self::MAX_BATCH. Used by both the
	 * manual-submit form and (defensively) anywhere else URLs are accepted
	 * from outside this class.
	 *
	 * @param string[] $urls Raw URL strings (e.g. one per textarea line).
	 * @return string[]
	 */
	public static function filter_own_host_urls( array $urls ) {
		$home_host = wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		$home_host = is_string( $home_host ) ? strtolower( $home_host ) : '';

		$valid = array();

		foreach ( $urls as $url ) {
			$url = esc_url_raw( trim( (string) $url ) );
			if ( '' === $url ) {
				continue;
			}

			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( ! is_string( $host ) || '' === $host || strtolower( $host ) !== $home_host ) {
				continue;
			}

			$valid[] = $url;
		}

		$valid = array_values( array_unique( $valid ) );

		return array_slice( $valid, 0, self::MAX_BATCH );
	}

	/**
	 * Submit a batch of URLs to the IndexNow API and log the result.
	 * Tolerant of failure: a network error or non-2xx response is logged
	 * and swallowed, never thrown.
	 *
	 * @param string[] $urls URLs to submit (already validated/filtered by the caller).
	 * @return bool True on an apparent 2xx response, false otherwise.
	 */
	public static function submit_urls( $urls ) {
		$urls = array_values( array_filter( array_map( 'esc_url_raw', (array) $urls ) ) );
		if ( empty( $urls ) ) {
			return false;
		}

		$settings = PHCITE_Settings::get_settings()['indexnow'];
		if ( empty( $settings['key'] ) ) {
			self::log_submission( count( $urls ), __( 'no key configured', 'presshangar-ai-citations' ) );
			return false;
		}

		$body = self::build_request_body( $urls, $settings );

		$response = wp_remote_post(
			self::API_URL,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			self::log_submission( count( $urls ), $response->get_error_message() );
			return false;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		self::log_submission( count( $urls ), (string) $code );

		return $code >= 200 && $code < 300;
	}

	/**
	 * Record a submission attempt in the log (newest first, capped at
	 * self::MAX_LOG).
	 *
	 * @param int    $count  Number of URLs in the batch.
	 * @param string $result HTTP status code as a string, or an error message.
	 */
	private static function log_submission( $count, $result ) {
		$log = get_option( self::OPTION_LOG, array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}

		array_unshift(
			$log,
			array(
				'time'   => time(),
				'count'  => (int) $count,
				'result' => (string) $result,
			)
		);

		$log = array_slice( $log, 0, self::MAX_LOG );

		update_option( self::OPTION_LOG, $log, false );
	}

	/**
	 * Get the submission log, newest first.
	 *
	 * @return array[]
	 */
	public static function get_log() {
		$log = get_option( self::OPTION_LOG, array() );

		return is_array( $log ) ? $log : array();
	}

	/**
	 * Number of URLs currently waiting in the auto-submit queue.
	 *
	 * @return int
	 */
	public static function get_queue_count() {
		$queue = get_option( self::OPTION_QUEUE, array() );

		return is_array( $queue ) ? count( $queue ) : 0;
	}
}
