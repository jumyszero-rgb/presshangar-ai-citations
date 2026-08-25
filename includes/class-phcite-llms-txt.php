<?php
/**
 * llms.txt generator: builds and physically writes a Markdown llms.txt file
 * at ABSPATH, via PHCITE_Filewriter (backups, atomic write, manual-copy
 * fallback all included there). Unlike robots.txt, llms.txt has no
 * "virtual" mode — it is a physical-only, PressHangar AI Citations-owned file.
 *
 * @package PressHangar AI Citations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCITE_Llms_Txt
 */
class PHCITE_Llms_Txt {

	/**
	 * Build the full Markdown content for llms.txt from current site info
	 * and the 'llms' settings branch.
	 *
	 * @param array $settings The 'llms' settings branch.
	 * @return string
	 */
	public static function build_content( $settings ) {
		$lines = array();

		$lines[] = '# ' . wp_strip_all_tags( get_bloginfo( 'name' ) );
		$lines[] = '';

		$tagline = wp_strip_all_tags( get_bloginfo( 'description' ) );
		if ( '' !== $tagline ) {
			$lines[] = '> ' . $tagline;
			$lines[] = '';
		}

		$description = trim( (string) ( isset( $settings['description'] ) ? $settings['description'] : '' ) );
		if ( '' !== $description ) {
			$lines[] = $description;
			$lines[] = '';
		}

		$pages_raw = isset( $settings['pages'] ) ? (string) $settings['pages'] : '';
		$urls      = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $pages_raw ) ) );

		if ( ! empty( $urls ) ) {
			$lines[] = '## Pages';
			$lines[] = '';
			foreach ( $urls as $url ) {
				$lines[] = '- [' . $url . '](' . $url . ')';
			}
			$lines[] = '';
		}

		return trim( implode( "\n", $lines ) ) . "\n";
	}

	/**
	 * Generate (or regenerate) the physical llms.txt file from current
	 * settings.
	 *
	 * @return true|WP_Error
	 */
	public static function generate() {
		$settings = PHCITE_Settings::get_settings()['llms'];
		$content  = self::build_content( $settings );

		return PHCITE_Filewriter::write( 'llms.txt', $content );
	}

	/**
	 * Delete the physical llms.txt file (after backing it up).
	 *
	 * @return true|WP_Error
	 */
	public static function remove() {
		return PHCITE_Filewriter::delete( 'llms.txt' );
	}

	/**
	 * Whether a physical llms.txt currently exists.
	 *
	 * @return bool
	 */
	public static function exists() {
		return PHCITE_Filewriter::exists( 'llms.txt' );
	}
}
