<?php
/**
 * File integrity scanning helper functions.
 *
 * Computes SHA-256 hashes for WordPress files, compares against stored
 * baselines, quarantines suspicious files, and fetches official WP core
 * checksums. Used by the Security module (Sentinel agent) for integrity
 * monitoring and malware response.
 *
 * @package    WPClaw
 * @subpackage WPClaw/helpers
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.1.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Maximum number of files to scan per invocation.
 *
 * Prevents memory exhaustion on very large installations.
 *
 * @since 1.1.0
 * @var int
 */
define( 'WP_CLAW_FILE_SCAN_LIMIT', 50000 );

/**
 * Maximum file size in bytes to hash (5 MB).
 *
 * Files larger than this are skipped to avoid blocking PHP execution.
 *
 * @since 1.1.0
 * @var int
 */
define( 'WP_CLAW_FILE_SIZE_LIMIT', 5 * 1024 * 1024 );

/**
 * Allowed file extensions for hashing.
 *
 * Only these types are scanned — binary assets, images, etc. are skipped.
 *
 * @since 1.1.0
 * @var array
 */
define( 'WP_CLAW_SCANNABLE_EXTENSIONS', array( 'php', 'js', 'css', 'html' ) );

/**
 * Compute SHA-256 hashes for all scannable files in a given scope.
 *
 * Walks the directory tree for the requested scope using
 * RecursiveDirectoryIterator. Only files matching the allowed extensions
 * and under the size limit are hashed. Scanning stops after
 * WP_CLAW_FILE_SCAN_LIMIT files to prevent memory exhaustion.
 *
 * @since 1.1.0
 *
 * @param string $scope One of 'core', 'plugin', 'theme', or 'all'.
 *
 * @return array Associative array of relative_path => sha256_hash.
 */
function wp_claw_compute_file_hashes( string $scope ): array {
	$directories = wp_claw_get_scope_directories( $scope );

	if ( empty( $directories ) ) {
		wp_claw_log_warning( 'File scanner: invalid scope provided.', array( 'scope' => $scope ) );
		return array();
	}

	$hashes     = array();
	$file_count = 0;
	$abspath    = untrailingslashit( ABSPATH );

	foreach ( $directories as $dir ) {
		if ( ! is_dir( $dir ) ) {
			continue;
		}

		try {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
				RecursiveIteratorIterator::LEAVES_ONLY
			);
		} catch ( \UnexpectedValueException $e ) {
			wp_claw_log_warning( 'File scanner: cannot read directory.', array( 'dir' => $dir, 'error' => $e->getMessage() ) );
			continue;
		}

		foreach ( $iterator as $file ) {
			if ( $file_count >= WP_CLAW_FILE_SCAN_LIMIT ) {
				wp_claw_log_warning( 'File scanner: hit scan limit.', array( 'limit' => WP_CLAW_FILE_SCAN_LIMIT ) );
				break 2;
			}

			if ( ! $file->isFile() ) {
				continue;
			}

			// Check extension.
			$extension = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
			if ( ! in_array( $extension, WP_CLAW_SCANNABLE_EXTENSIONS, true ) ) {
				continue;
			}

			// Skip oversized files.
			if ( $file->getSize() > WP_CLAW_FILE_SIZE_LIMIT ) {
				continue;
			}

			$absolute_path = $file->getRealPath();
			if ( false === $absolute_path ) {
				continue;
			}

			$relative_path = ltrim( str_replace( $abspath, '', $absolute_path ), DIRECTORY_SEPARATOR );
			$hash          = hash_file( 'sha256', $absolute_path );

			if ( false !== $hash ) {
				$hashes[ $relative_path ] = $hash;
				++$file_count;
			}
		}
	}

	wp_claw_log_debug( 'File scanner: hashing complete.', array( 'scope' => $scope, 'files' => $file_count ) );

	return $hashes;
}

/**
 * Compare current file hashes against the stored baseline.
 *
 * Queries the wp_claw_file_hashes table for the given scope and compares
 * with a fresh scan. Returns arrays of modified, new, and deleted files.
 *
 * @since 1.1.0
 *
 * @param string $scope One of 'core', 'plugin', 'theme', or 'all'.
 *
 * @return array {
 *     @type array $modified Files with changed hashes. Each entry has file_path, old_hash, new_hash.
 *     @type array $new      Files not present in the baseline. Each entry has file_path, new_hash.
 *     @type array $deleted  Baseline files no longer on disk. Each entry has file_path, old_hash.
 * }
 */
function wp_claw_compare_file_hashes( string $scope ): array {
	global $wpdb;

	$table_name = $wpdb->prefix . 'wp_claw_file_hashes';

	$current_hashes = wp_claw_compute_file_hashes( $scope );

	// Fetch stored baseline from the database.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT file_path, file_hash FROM {$table_name} WHERE scope = %s",
			$scope
		),
		ARRAY_A
	);

	$stored_hashes = array();
	if ( is_array( $rows ) ) {
		foreach ( $rows as $row ) {
			$stored_hashes[ $row['file_path'] ] = $row['file_hash'];
		}
	}

	$result = array(
		'modified' => array(),
		'new'      => array(),
		'deleted'  => array(),
	);

	// Find modified and new files.
	foreach ( $current_hashes as $file_path => $new_hash ) {
		if ( isset( $stored_hashes[ $file_path ] ) ) {
			if ( $stored_hashes[ $file_path ] !== $new_hash ) {
				$result['modified'][] = array(
					'file_path' => $file_path,
					'old_hash'  => $stored_hashes[ $file_path ],
					'new_hash'  => $new_hash,
				);
			}
		} else {
			$result['new'][] = array(
				'file_path' => $file_path,
				'new_hash'  => $new_hash,
			);
		}
	}

	// Find deleted files.
	foreach ( $stored_hashes as $file_path => $old_hash ) {
		if ( ! isset( $current_hashes[ $file_path ] ) ) {
			$result['deleted'][] = array(
				'file_path' => $file_path,
				'old_hash'  => $old_hash,
			);
		}
	}

	wp_claw_log_debug(
		'File scanner: comparison complete.',
		array(
			'scope'    => $scope,
			'modified' => count( $result['modified'] ),
			'new'      => count( $result['new'] ),
			'deleted'  => count( $result['deleted'] ),
		)
	);

	return $result;
}

/**
 * Quarantine a suspicious file by renaming it.
 *
 * SECURITY: Only files within wp-content/plugins/ or wp-content/themes/
 * can be quarantined. Core WordPress files (wp-includes/, wp-admin/) are
 * restricted to prevent accidental breakage — use wp_claw_get_wp_core_checksums()
 * for core integrity verification instead.
 *
 * Uses WP_Filesystem for all file operations. The file is renamed to
 * {filename}.quarantine to neutralize it while preserving evidence.
 *
 * @since 1.1.0
 *
 * @param string $file_path Absolute path to the file to quarantine.
 *
 * @return array|\WP_Error Array with quarantined_path, original_path, replaced on success.
 *                         WP_Error on failure or path restriction.
 */
function wp_claw_quarantine_file( string $file_path ): array {
	// Resolve to real path to prevent directory traversal.
	$real_path = realpath( $file_path );
	if ( false === $real_path || ! file_exists( $real_path ) ) {
		return new \WP_Error(
			'wp_claw_file_not_found',
			__( 'File does not exist.', 'claw-agent' ),
			array( 'status' => 404 )
		);
	}

	// Hard-coded path restriction: only wp-content/plugins/ and wp-content/themes/.
	$allowed_dirs = array(
		realpath( WP_CONTENT_DIR . '/plugins' ),
		realpath( WP_CONTENT_DIR . '/themes' ),
	);

	$is_allowed = false;
	foreach ( $allowed_dirs as $allowed_dir ) {
		if ( false !== $allowed_dir && 0 === strpos( $real_path, $allowed_dir . DIRECTORY_SEPARATOR ) ) {
			$is_allowed = true;
			break;
		}
	}

	if ( ! $is_allowed ) {
		return new \WP_Error(
			'wp_claw_path_restricted',
			__( 'Quarantine is restricted to wp-content/plugins/ and wp-content/themes/ only.', 'claw-agent' ),
			array( 'status' => 403 )
		);
	}

	// Initialise WP_Filesystem.
	if ( ! function_exists( 'WP_Filesystem' ) ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
	}
	WP_Filesystem();
	global $wp_filesystem;

	if ( ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
		return new \WP_Error(
			'wp_claw_filesystem_error',
			__( 'Could not initialise WP_Filesystem.', 'claw-agent' ),
			array( 'status' => 500 )
		);
	}

	$quarantine_path = $real_path . '.quarantine';

	// Rename the file to quarantine it.
	$moved = $wp_filesystem->move( $real_path, $quarantine_path, true );
	if ( ! $moved ) {
		return new \WP_Error(
			'wp_claw_quarantine_failed',
			__( 'Failed to rename file to quarantine.', 'claw-agent' ),
			array( 'status' => 500 )
		);
	}

	wp_claw_log( 'File quarantined.', 'warning', array( 'original' => $real_path, 'quarantined' => $quarantine_path ) );

	// Attempt to find a clean replacement from WP core checksums.
	$replaced  = false;
	$abspath   = untrailingslashit( ABSPATH );
	$rel_path  = ltrim( str_replace( $abspath, '', $real_path ), DIRECTORY_SEPARATOR );
	$checksums = wp_claw_get_wp_core_checksums();

	if ( ! empty( $checksums ) && isset( $checksums[ $rel_path ] ) ) {
		wp_claw_log_debug( 'Core checksum exists for quarantined file — manual replacement recommended.', array( 'file' => $rel_path ) );
	}

	return array(
		'quarantined_path' => $quarantine_path,
		'original_path'    => $real_path,
		'replaced'         => $replaced,
	);
}

/**
 * Fetch official WordPress core file checksums.
 *
 * Queries the WordPress.org checksums API for the current WP version.
 * Results are cached in a transient for 24 hours to minimise API calls.
 * Returns MD5 checksums as provided by WordPress.org.
 *
 * @since 1.1.0
 *
 * @return array Associative array of relative_path => md5_hash, or empty array on failure.
 */
function wp_claw_get_wp_core_checksums(): array {
	$cached = get_transient( 'wp_claw_core_checksums' );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	global $wp_version;

	$url = add_query_arg(
		array(
			'version' => $wp_version,
			'locale'  => 'en_US',
		),
		'https://api.wordpress.org/core/checksums/1.0/'
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 10,
		)
	);

	if ( is_wp_error( $response ) ) {
		wp_claw_log_warning( 'Failed to fetch WP core checksums.', array( 'error' => $response->get_error_message() ) );
		return array();
	}

	$code = wp_remote_retrieve_response_code( $response );
	if ( 200 !== $code ) {
		wp_claw_log_warning( 'WP core checksums API returned non-200.', array( 'status' => $code ) );
		return array();
	}

	$body = wp_remote_retrieve_body( $response );
	$data = json_decode( $body, true );

	if ( ! is_array( $data ) || empty( $data['checksums'] ) || ! is_array( $data['checksums'] ) ) {
		wp_claw_log_warning( 'WP core checksums API returned invalid data.' );
		return array();
	}

	$checksums = $data['checksums'];

	set_transient( 'wp_claw_core_checksums', $checksums, DAY_IN_SECONDS );

	wp_claw_log_debug( 'WP core checksums fetched and cached.', array( 'files' => count( $checksums ) ) );

	return $checksums;
}

/**
 * Get directory paths for a given scan scope.
 *
 * Maps scope identifiers to their corresponding filesystem directories.
 * For 'core' scope, returns wp-includes/ and wp-admin/ under ABSPATH.
 * For 'all' scope, returns all three scopes combined.
 *
 * @since 1.1.0
 *
 * @param string $scope One of 'core', 'plugin', 'theme', or 'all'.
 *
 * @return array List of absolute directory paths to scan.
 */
function wp_claw_get_scope_directories( string $scope ): array {
	$scope_map = array(
		'core'   => array(
			ABSPATH . 'wp-includes',
			ABSPATH . 'wp-admin',
		),
		'plugin' => array( WP_PLUGIN_DIR ),
		'theme'  => array( get_theme_root() ),
	);

	if ( 'all' === $scope ) {
		return array_merge( $scope_map['core'], $scope_map['plugin'], $scope_map['theme'] );
	}

	return isset( $scope_map[ $scope ] ) ? $scope_map[ $scope ] : array();
}
