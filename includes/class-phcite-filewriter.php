<?php
/**
 * Physical file writer: the common, safety-critical foundation used to read,
 * write, backup, and restore the handful of well-known ABSPATH-root files
 * this plugin manages (robots.txt, llms.txt).
 *
 * All actual file I/O (read, write, delete) goes through the WordPress
 * Filesystem API ($wp_filesystem), initialized on demand via WP_Filesystem()
 * in its default ("direct") context — this plugin never prompts for FTP/SSH
 * credentials; if WP_Filesystem() can't initialize (or a write otherwise
 * fails), every entry point here falls back to the same WP_Error() +
 * manual-copy-textarea path already used for a plain permissions failure, so
 * behavior is unaffected either way. Path *validation* (get_target_path())
 * still uses PHP's own realpath()/file_exists()/dirname(), since there is no
 * WP_Filesystem equivalent for realpath-based canonicalization and these are
 * read-only introspection, not the write operations Plugin Check flags.
 *
 * Every public write/delete entry point here:
 *  - only ever targets an explicit allow-list of filenames,
 *  - resolves the target with realpath() and refuses to proceed if it does
 *    not land inside ABSPATH,
 *  - takes a timestamped backup before overwriting or removing an existing
 *    file (capped at MAX_BACKUPS, oldest pruned first),
 *  - skips the backup+write cycle entirely when the new content is
 *    byte-identical to what's already on disk,
 *  - writes via $wp_filesystem->put_contents() — WP_Filesystem has no
 *    portable atomic temp-file+rename primitive across its direct/FTP/SSH2
 *    methods, so the mandatory backup-before-write above is the safety net:
 *    if a write fails partway, the prior content is always recoverable from
 *    the backup just taken,
 *  - returns a WP_Error (never a fatal) on any failure so callers can show
 *    an admin notice and fall back to the manual-copy textarea.
 *
 * @package PressHangar AI Citations
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class PHCITE_Filewriter
 */
class PHCITE_Filewriter {

	/**
	 * Filenames this plugin is ever allowed to touch, resolved against
	 * ABSPATH. No other filename may be passed to any method here.
	 */
	const ALLOWED_FILES = array( 'robots.txt', 'llms.txt' );

	/**
	 * Maximum number of timestamped backups kept per managed file. Oldest
	 * backups beyond this cap are pruned after every new backup.
	 */
	const MAX_BACKUPS = 10;

	/**
	 * Subdirectory of the uploads directory backups are stored in.
	 */
	const BACKUP_SUBDIR = 'cite-pilot-backups';

	/**
	 * Managed block markers. Content between these two lines (inclusive) is
	 * owned by PressHangar AI Citations; everything else in a file is left untouched.
	 */
	const MARKER_BEGIN = '# BEGIN PressHangar AI Citations';
	const MARKER_END   = '# END PressHangar AI Citations';

	/**
	 * Validate that $filename is one of the explicitly allowed managed
	 * files.
	 *
	 * @param string $filename Bare filename (no path separators).
	 * @return bool
	 */
	private static function is_allowed_filename( $filename ) {
		return is_string( $filename ) && in_array( $filename, self::ALLOWED_FILES, true );
	}

	/**
	 * Ensure the WordPress Filesystem API is initialized and return the
	 * global $wp_filesystem instance. Always initializes in the default
	 * ("direct") context — this plugin never collects FTP/SSH credentials;
	 * on hosts where the direct method isn't available, WP_Filesystem()
	 * simply fails to initialize and every caller here falls back to its
	 * existing WP_Error + manual-copy-textarea path.
	 *
	 * @return WP_Filesystem_Base|null
	 */
	private static function get_filesystem() {
		global $wp_filesystem;

		if ( ! ( $wp_filesystem instanceof WP_Filesystem_Base ) ) {
			if ( ! function_exists( 'WP_Filesystem' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}
			WP_Filesystem();
		}

		return ( $wp_filesystem instanceof WP_Filesystem_Base ) ? $wp_filesystem : null;
	}

	/**
	 * Resolve and validate the absolute, realpath-checked target path for a
	 * managed filename directly under ABSPATH.
	 *
	 * Refuses (returns WP_Error) unless the resolved path is provably inside
	 * ABSPATH, whether or not the file currently exists.
	 *
	 * @param string $filename One of self::ALLOWED_FILES.
	 * @return string|WP_Error Absolute path, or WP_Error on failure.
	 */
	public static function get_target_path( $filename ) {
		if ( ! self::is_allowed_filename( $filename ) ) {
			return new WP_Error( 'phcite_invalid_file', __( 'PressHangar AI Citations only manages robots.txt and llms.txt; refusing to touch any other file.', 'presshangar-ai-citations' ) );
		}

		$abspath_real = realpath( ABSPATH );
		if ( false === $abspath_real ) {
			return new WP_Error( 'phcite_no_abspath', __( 'Could not resolve the WordPress root directory.', 'presshangar-ai-citations' ) );
		}
		$abspath_real = rtrim( $abspath_real, '/\\' );

		$candidate = rtrim( ABSPATH, '/\\' ) . DIRECTORY_SEPARATOR . $filename;

		if ( file_exists( $candidate ) ) {
			$real = realpath( $candidate );
			if ( false === $real ) {
				return new WP_Error( 'phcite_path_unresolvable', __( 'Could not resolve the target file path.', 'presshangar-ai-citations' ) );
			}
			if ( $real !== $abspath_real . DIRECTORY_SEPARATOR . $filename ) {
				return new WP_Error( 'phcite_path_escape', __( 'Refusing to write outside the WordPress root directory.', 'presshangar-ai-citations' ) );
			}
			return $real;
		}

		// File does not exist yet: validate the parent directory itself
		// resolves to ABSPATH (the filename has no path separators and is
		// drawn from a fixed allow-list, so this is a defense-in-depth
		// check rather than a real traversal vector).
		$parent_real = realpath( dirname( $candidate ) );
		if ( false === $parent_real || rtrim( $parent_real, '/\\' ) !== $abspath_real ) {
			return new WP_Error( 'phcite_path_escape', __( 'Refusing to write outside the WordPress root directory.', 'presshangar-ai-citations' ) );
		}

		return $candidate;
	}

	/**
	 * Whether a managed file currently physically exists.
	 *
	 * @param string $filename One of self::ALLOWED_FILES.
	 * @return bool
	 */
	public static function exists( $filename ) {
		$path = self::get_target_path( $filename );

		return ! is_wp_error( $path ) && file_exists( $path ) && is_file( $path );
	}

	/**
	 * Read the current contents of a managed file.
	 *
	 * @param string $filename One of self::ALLOWED_FILES.
	 * @return string|false File contents, or false if it doesn't exist or can't be read.
	 */
	public static function read( $filename ) {
		$path = self::get_target_path( $filename );
		if ( is_wp_error( $path ) || ! file_exists( $path ) ) {
			return false;
		}

		$fs = self::get_filesystem();
		if ( null === $fs ) {
			return false;
		}

		$contents = $fs->get_contents( $path );

		return false === $contents ? false : $contents;
	}

	/**
	 * Best-effort pre-check for whether ABSPATH looks writable. Not
	 * authoritative — the actual write attempt in write() is — but useful
	 * to decide up front whether to show the manual-copy fallback.
	 *
	 * @return bool
	 */
	public static function is_root_writable() {
		$fs = self::get_filesystem();
		if ( null === $fs ) {
			return false;
		}

		return $fs->is_writable( rtrim( ABSPATH, '/\\' ) );
	}

	/**
	 * Write content to a managed file, backing up any existing content
	 * first. Skips the backup+write cycle entirely if the file already
	 * holds byte-identical content.
	 *
	 * @param string $filename One of self::ALLOWED_FILES.
	 * @param string $content  New file contents.
	 * @return true|WP_Error True on success, WP_Error (with the intended
	 *                        content attached as error data under
	 *                        'content') on failure.
	 */
	public static function write( $filename, $content ) {
		$path = self::get_target_path( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		$content = (string) $content;

		$fs = self::get_filesystem();
		if ( null === $fs ) {
			return new WP_Error(
				'phcite_not_writable',
				sprintf(
					/* translators: %s: filename, e.g. robots.txt. */
					__( 'The WordPress filesystem API could not be initialized, so %s could not be saved automatically. Use the text below to copy it manually via FTP or your host\'s file manager.', 'presshangar-ai-citations' ),
					$filename
				),
				array( 'content' => $content )
			);
		}

		if ( file_exists( $path ) ) {
			$current_content = $fs->get_contents( $path );
			if ( false !== $current_content && $current_content === $content ) {
				return true; // Already exactly this content: skip the backup/write cycle entirely (avoids backup churn).
			}

			$backup_result = self::backup( $filename );
			if ( is_wp_error( $backup_result ) ) {
				return new WP_Error(
					'phcite_backup_failed',
					sprintf(
						/* translators: %s: backup error message. */
						__( 'Could not back up the existing file before writing, so nothing was changed: %s', 'presshangar-ai-citations' ),
						$backup_result->get_error_message()
					),
					array( 'content' => $content )
				);
			}
		}

		$dir = dirname( $path );
		if ( ! $fs->is_writable( $dir ) ) {
			return new WP_Error(
				'phcite_not_writable',
				sprintf(
					/* translators: %s: filename, e.g. robots.txt. */
					__( 'The WordPress root directory is not writable, so %s could not be saved automatically. Use the text below to copy it manually via FTP or your host\'s file manager.', 'presshangar-ai-citations' ),
					$filename
				),
				array( 'content' => $content )
			);
		}

		$written = $fs->put_contents( $path, $content, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );

		if ( ! $written ) {
			return new WP_Error(
				'phcite_write_failed',
				sprintf(
					/* translators: %s: filename, e.g. robots.txt. */
					__( 'Writing %s failed. If it was partially written, restore the backup just taken above. Use the text below to copy it manually via FTP or your host\'s file manager.', 'presshangar-ai-citations' ),
					$filename
				),
				array( 'content' => $content )
			);
		}

		return true;
	}

	/**
	 * Delete a managed file after backing it up.
	 *
	 * @param string $filename One of self::ALLOWED_FILES.
	 * @return true|WP_Error True on success (or if the file was already
	 *                        absent), WP_Error on failure.
	 */
	public static function delete( $filename ) {
		$path = self::get_target_path( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! file_exists( $path ) ) {
			return true;
		}

		$backup_result = self::backup( $filename );
		if ( is_wp_error( $backup_result ) ) {
			return $backup_result;
		}

		$fs = self::get_filesystem();
		if ( null === $fs ) {
			return new WP_Error(
				'phcite_delete_failed',
				sprintf(
					/* translators: %s: filename, e.g. llms.txt. */
					__( 'Could not delete %s: the WordPress filesystem API could not be initialized. It may need to be removed manually via FTP or your host\'s file manager.', 'presshangar-ai-citations' ),
					$filename
				)
			);
		}

		if ( ! $fs->delete( $path, false, 'f' ) ) {
			return new WP_Error(
				'phcite_delete_failed',
				sprintf(
					/* translators: %s: filename, e.g. llms.txt. */
					__( 'Could not delete %s. It may need to be removed manually via FTP or your host\'s file manager.', 'presshangar-ai-citations' ),
					$filename
				)
			);
		}

		return true;
	}

	/**
	 * Absolute path to the backup directory for managed files, creating it
	 * (with basic protection against directory listing / execution) if
	 * needed.
	 *
	 * @return string|WP_Error
	 */
	private static function get_backup_dir() {
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['error'] ) ) {
			return new WP_Error( 'phcite_no_uploads_dir', $upload_dir['error'] );
		}

		$dir = trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_SUBDIR;

		if ( ! file_exists( $dir ) ) {
			wp_mkdir_p( $dir );
		}

		$fs = self::get_filesystem();

		if ( ! is_dir( $dir ) || null === $fs || ! $fs->is_writable( $dir ) ) {
			return new WP_Error( 'phcite_backup_dir_unwritable', __( 'The backup directory could not be created or is not writable.', 'presshangar-ai-citations' ) );
		}

		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			$fs->put_contents( $index_file, "<?php\n// Silence is golden.\n", defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		}

		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			$fs->put_contents( $htaccess_file, "Options -Indexes\nRequire all denied\ndeny from all\n", defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 );
		}

		return trailingslashit( $dir );
	}

	/**
	 * Back up the current contents of a managed file into the backups
	 * directory with a timestamped filename, then prune old backups beyond
	 * self::MAX_BACKUPS.
	 *
	 * @param string $filename One of self::ALLOWED_FILES.
	 * @return true|WP_Error
	 */
	public static function backup( $filename ) {
		$path = self::get_target_path( $filename );
		if ( is_wp_error( $path ) ) {
			return $path;
		}

		if ( ! file_exists( $path ) ) {
			return true; // Nothing to back up.
		}

		$backup_dir = self::get_backup_dir();
		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$fs = self::get_filesystem();
		if ( null === $fs ) {
			return new WP_Error( 'phcite_backup_read_failed', __( 'Could not read the existing file to back it up: the WordPress filesystem API could not be initialized.', 'presshangar-ai-citations' ) );
		}

		$content = $fs->get_contents( $path );
		if ( false === $content ) {
			return new WP_Error( 'phcite_backup_read_failed', __( 'Could not read the existing file to back it up.', 'presshangar-ai-citations' ) );
		}

		$backup_filename = $filename . '.' . gmdate( 'Ymd-His' ) . '.bak';
		$backup_path     = $backup_dir . $backup_filename;

		// Guard against two backups landing in the same second.
		$suffix = 1;
		while ( file_exists( $backup_path ) ) {
			$backup_filename = $filename . '.' . gmdate( 'Ymd-His' ) . '-' . $suffix . '.bak';
			$backup_path     = $backup_dir . $backup_filename;
			++$suffix;
		}

		if ( ! $fs->put_contents( $backup_path, $content, defined( 'FS_CHMOD_FILE' ) ? FS_CHMOD_FILE : 0644 ) ) {
			return new WP_Error( 'phcite_backup_write_failed', __( 'Could not write the backup file.', 'presshangar-ai-citations' ) );
		}

		self::prune_backups( $filename );

		return true;
	}

	/**
	 * List existing backups for a managed file, newest first.
	 *
	 * @param string $filename One of self::ALLOWED_FILES.
	 * @return array[] Each entry: array( 'file' => string, 'time' => int, 'size' => int ).
	 */
	public static function list_backups( $filename ) {
		if ( ! self::is_allowed_filename( $filename ) ) {
			return array();
		}

		$backup_dir = self::get_backup_dir();
		if ( is_wp_error( $backup_dir ) || ! is_dir( $backup_dir ) ) {
			return array();
		}

		$prefix  = $filename . '.';
		$entries = array();

		$scanned = scandir( $backup_dir );
		if ( false === $scanned ) {
			return array();
		}

		foreach ( $scanned as $entry ) {
			if ( 0 !== strpos( $entry, $prefix ) || '.bak' !== substr( $entry, -4 ) ) {
				continue;
			}
			$full = $backup_dir . $entry;
			if ( ! is_file( $full ) ) {
				continue;
			}
			$entries[] = array(
				'file' => $entry,
				'time' => (int) filemtime( $full ),
				'size' => (int) filesize( $full ),
			);
		}

		usort(
			$entries,
			static function ( $a, $b ) {
				return $b['time'] <=> $a['time'];
			}
		);

		return $entries;
	}

	/**
	 * Delete backups beyond self::MAX_BACKUPS for a given managed file,
	 * oldest first.
	 *
	 * @param string $filename One of self::ALLOWED_FILES.
	 */
	private static function prune_backups( $filename ) {
		$backups = self::list_backups( $filename ); // Newest first.

		if ( count( $backups ) <= self::MAX_BACKUPS ) {
			return;
		}

		$backup_dir = self::get_backup_dir();
		if ( is_wp_error( $backup_dir ) ) {
			return;
		}

		$fs = self::get_filesystem();
		if ( null === $fs ) {
			return;
		}

		$to_remove = array_slice( $backups, self::MAX_BACKUPS );
		foreach ( $to_remove as $backup ) {
			$fs->delete( $backup_dir . $backup['file'], false, 'f' );
		}
	}

	/**
	 * Restore a managed file from one of its backups. The current content
	 * is itself backed up first (via write()), so a restore is never
	 * destructive.
	 *
	 * @param string $filename        One of self::ALLOWED_FILES.
	 * @param string $backup_filename Backup filename as returned by list_backups().
	 * @return true|WP_Error
	 */
	public static function restore_backup( $filename, $backup_filename ) {
		if ( ! self::is_allowed_filename( $filename ) ) {
			return new WP_Error( 'phcite_invalid_file', __( 'PressHangar AI Citations only manages robots.txt and llms.txt; refusing to touch any other file.', 'presshangar-ai-citations' ) );
		}

		// The backup filename must be an exact basename match against one of
		// this file's own backups — no path separators, no traversal.
		$backup_filename = basename( (string) $backup_filename );
		$valid_names     = wp_list_pluck( self::list_backups( $filename ), 'file' );

		if ( ! in_array( $backup_filename, $valid_names, true ) ) {
			return new WP_Error( 'phcite_invalid_backup', __( 'That backup could not be found.', 'presshangar-ai-citations' ) );
		}

		$backup_dir = self::get_backup_dir();
		if ( is_wp_error( $backup_dir ) ) {
			return $backup_dir;
		}

		$real_backup_dir = realpath( untrailingslashit( $backup_dir ) );
		$backup_path     = $backup_dir . $backup_filename;
		$real_backup     = realpath( $backup_path );

		if ( false === $real_backup || false === $real_backup_dir || 0 !== strpos( $real_backup, $real_backup_dir ) ) {
			return new WP_Error( 'phcite_path_escape', __( 'Refusing to restore from a file outside the backups directory.', 'presshangar-ai-citations' ) );
		}

		$fs = self::get_filesystem();
		if ( null === $fs ) {
			return new WP_Error( 'phcite_backup_read_failed', __( 'Could not read the backup file: the WordPress filesystem API could not be initialized.', 'presshangar-ai-citations' ) );
		}

		$content = $fs->get_contents( $backup_path );
		if ( false === $content ) {
			return new WP_Error( 'phcite_backup_read_failed', __( 'Could not read the backup file.', 'presshangar-ai-citations' ) );
		}

		return self::write( $filename, $content );
	}

	/**
	 * Insert or, if already present, replace the PressHangar AI Citations managed block
	 * within $content. Idempotent: calling this twice in a row with the
	 * same $block_body produces byte-identical output. All content outside
	 * the managed block is left untouched.
	 *
	 * Refuses to touch the content (returning a WP_Error instead) if the
	 * markers are present in a malformed state — a BEGIN with no matching
	 * END after it, or an END with no BEGIN before it — since blindly
	 * appending in that state would either duplicate a BEGIN marker or
	 * silently drop content between a stray marker and the next save.
	 *
	 * @param string $content    Full existing file content (may be empty).
	 * @param string $block_body Inner block content, without markers.
	 * @return string|WP_Error Updated full content, or WP_Error (with the
	 *                          original $content attached as error data
	 *                          under 'content') if the existing markers are
	 *                          malformed.
	 */
	public static function upsert_block( $content, $block_body ) {
		$content    = (string) $content;
		$block_body = trim( (string) $block_body, "\n" );

		$begin_pos = strpos( $content, self::MARKER_BEGIN );
		$end_pos   = strpos( $content, self::MARKER_END );

		$malformed = ( false !== $begin_pos && ( false === $end_pos || $end_pos < $begin_pos ) )
			|| ( false === $begin_pos && false !== $end_pos );

		if ( $malformed ) {
			return new WP_Error(
				'phcite_malformed_block',
				__( 'A PressHangar AI Citations marker ("# BEGIN PressHangar AI Citations" or "# END PressHangar AI Citations") was found without its matching pair, so the file was not changed. Remove the stray marker line manually via FTP or your host\'s file manager, then try again.', 'presshangar-ai-citations' ),
				array( 'content' => $content )
			);
		}

		$block = self::MARKER_BEGIN . "\n" . $block_body . "\n" . self::MARKER_END;

		if ( self::has_block( $content ) ) {
			$pattern = '/\R*' . preg_quote( self::MARKER_BEGIN, '/' ) . '.*?' . preg_quote( self::MARKER_END, '/' ) . '\R*/s';
			$new     = preg_replace( $pattern, "\n\n" . $block . "\n", $content, 1 );

			return self::normalize_trailing( $new );
		}

		$trimmed = rtrim( $content, "\n" );
		$sep     = ( '' === trim( $trimmed ) ) ? '' : $trimmed . "\n\n";

		return $sep . $block . "\n";
	}

	/**
	 * Remove the PressHangar AI Citations managed block (and its markers) from $content.
	 * Idempotent: a no-op if no block is present. All other content is
	 * left untouched.
	 *
	 * @param string $content Full existing file content.
	 * @return string Updated full content.
	 */
	public static function remove_block( $content ) {
		$content = (string) $content;
		$pattern = '/\R*' . preg_quote( self::MARKER_BEGIN, '/' ) . '.*?' . preg_quote( self::MARKER_END, '/' ) . '\R*/s';

		$new = preg_replace( $pattern, "\n", $content, 1 );

		return self::normalize_trailing( $new );
	}

	/**
	 * Whether $content currently contains a well-formed managed block: both
	 * markers present, with BEGIN appearing before END (an ordered pair) —
	 * not merely both substrings present anywhere in the content.
	 *
	 * @param string $content Full file content.
	 * @return bool
	 */
	public static function has_block( $content ) {
		$content = (string) $content;

		$begin_pos = strpos( $content, self::MARKER_BEGIN );
		$end_pos   = strpos( $content, self::MARKER_END );

		return false !== $begin_pos && false !== $end_pos && $end_pos > $begin_pos;
	}

	/**
	 * Normalize leading/trailing blank lines produced by block insertion or
	 * removal: no leading blank lines, exactly one trailing newline (or an
	 * entirely empty string if nothing is left).
	 *
	 * @param string $content Content to normalize.
	 * @return string
	 */
	private static function normalize_trailing( $content ) {
		$content = ltrim( (string) $content, "\n" );

		if ( '' === trim( $content ) ) {
			return '';
		}

		return rtrim( $content, "\n" ) . "\n";
	}
}
