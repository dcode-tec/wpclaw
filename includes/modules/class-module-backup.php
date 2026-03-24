<?php
/**
 * Backup module.
 *
 * @package    WPClaw
 * @subpackage WPClaw/modules
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

namespace WPClaw\Modules;

use WPClaw\Module_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Backup module — Sentinel agent manages WordPress database backups.
 *
 * Backups are stored in wp-content/uploads/wp-claw-backups/{timestamp}/ as
 * gzip-compressed SQL exports. Each backup directory is protected with an
 * .htaccess (deny from all) and a placeholder index.php to prevent
 * directory listing and direct download. All filesystem operations use the
 * WP_Filesystem API — never raw PHP file functions.
 *
 * Restore operations are intentionally gated: a 403 WP_Error is returned
 * so the REST bridge routes the request through the CONFIRM proposal tier.
 *
 * @since 1.0.0
 */
class Module_Backup extends Module_Base {

	/**
	 * Backup root directory name inside wp-content/uploads/.
	 *
	 * @since 1.0.0
	 * @var   string
	 */
	const BACKUP_DIR_NAME = 'wp-claw-backups';

	/**
	 * Default number of days to retain backups.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const DEFAULT_RETENTION_DAYS = 7;

	/**
	 * Maximum number of tables exported in a single pass before aborting.
	 *
	 * Prevents PHP memory exhaustion on very large databases.
	 *
	 * @since 1.0.0
	 * @var   int
	 */
	const MAX_TABLES = 200;

	// -------------------------------------------------------------------------
	// Module contract implementation
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'backup';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Backup', 'wp-claw' );
	}

	/**
	 * Return the Klawty agent responsible for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'sentinel';
	}

	/**
	 * Return the allowlisted actions for this module.
	 *
	 * @since 1.0.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'create_backup',
			'list_backups',
			'restore_backup',
			'delete_old_backups',
			'verify_backup',
		);
	}

	/**
	 * Handle an inbound agent action.
	 *
	 * @since 1.0.0
	 *
	 * @param string $action The action to perform.
	 * @param array  $params Parameters sent by the agent.
	 *
	 * @return array|\WP_Error
	 */
	public function handle_action( string $action, array $params ) {
		switch ( $action ) {
			case 'create_backup':
				return $this->action_create_backup( $params );

			case 'list_backups':
				return $this->action_list_backups( $params );

			case 'restore_backup':
				return $this->action_restore_backup( $params );

			case 'delete_old_backups':
				return $this->action_delete_old_backups( $params );

			case 'verify_backup':
				return $this->action_verify_backup( $params );

			default:
				return new \WP_Error(
					'wp_claw_backup_unknown_action',
					/* translators: %s: action name */
					sprintf( esc_html__( 'Unknown backup action: %s', 'wp-claw' ), esc_html( $action ) ),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Return a state snapshot for the Sentinel agent.
	 *
	 * Provides last backup timestamp, total backup count, and cumulative
	 * size in bytes so the agent can assess backup health at a glance.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	public function get_state(): array {
		$backups    = $this->get_backup_list();
		$count      = count( $backups );
		$total_size = 0;
		$last_time  = '';

		foreach ( $backups as $backup ) {
			$total_size += (int) ( $backup['size'] ?? 0 );

			if ( empty( $last_time ) || $backup['timestamp'] > $last_time ) {
				$last_time = $backup['timestamp'];
			}
		}

		return array(
			'last_backup_at'   => $last_time,
			'backup_count'     => $count,
			'total_size_bytes' => $total_size,
		);
	}

	/**
	 * Register WordPress hooks for this module.
	 *
	 * The Backup module is entirely cron-driven — no real-time hooks needed.
	 * Cron events (wp_claw_backup) are registered by class-cron.php.
	 *
	 * @since 1.0.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// No hooks required — all operations are cron-initiated or agent-triggered.
	}

	// -------------------------------------------------------------------------
	// Action handlers
	// -------------------------------------------------------------------------

	/**
	 * Create a full database backup.
	 *
	 * Exports every table in the WordPress database via $wpdb->get_results(),
	 * generates a SQL dump string, gzip-compresses it, and saves it to
	 * wp-content/uploads/wp-claw-backups/{timestamp}/database.sql.gz.
	 * A .htaccess and index.php are also written to protect the directory.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_create_backup( array $params ) {
		global $wpdb;

		$wp_filesystem = $this->get_wp_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		$backup_root = $this->get_backup_root();
		$timestamp   = gmdate( 'Y-m-d_H-i-s' );
		$backup_dir  = trailingslashit( $backup_root ) . $timestamp;

		// Ensure parent directory exists.
		if ( ! $wp_filesystem->is_dir( $backup_root ) ) {
			if ( ! wp_mkdir_p( $backup_root ) ) {
				return new \WP_Error(
					'wp_claw_backup_mkdir_failed',
					esc_html__( 'Failed to create backup root directory.', 'wp-claw' ),
					array( 'status' => 500 )
				);
			}

			// Protect backup root from directory listing.
			$this->write_protection_files( $wp_filesystem, $backup_root );
		}

		// Create timestamped backup subdirectory.
		if ( ! wp_mkdir_p( $backup_dir ) ) {
			return new \WP_Error(
				'wp_claw_backup_mkdir_failed',
				esc_html__( 'Failed to create backup subdirectory.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		$this->write_protection_files( $wp_filesystem, $backup_dir );

		// Build the SQL dump.
		$sql = $this->build_sql_dump();
		if ( is_wp_error( $sql ) ) {
			return $sql;
		}

		// gzip-compress and write.
		$gz_data = gzencode( $sql, 6 );
		if ( false === $gz_data ) {
			return new \WP_Error(
				'wp_claw_backup_gzencode_failed',
				esc_html__( 'Failed to gzip-compress the database dump.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		$gz_file = trailingslashit( $backup_dir ) . 'database.sql.gz';
		if ( ! $wp_filesystem->put_contents( $gz_file, $gz_data, FS_CHMOD_FILE ) ) {
			return new \WP_Error(
				'wp_claw_backup_write_failed',
				esc_html__( 'Failed to write backup file.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		// Record backup metadata.
		$meta = array(
			'timestamp'   => $timestamp,
			'created_at'  => gmdate( 'Y-m-d H:i:s' ),
			'size_bytes'  => strlen( $gz_data ),
			'table_count' => $this->count_tables_in_dump( $sql ),
			'wp_version'  => get_bloginfo( 'version' ),
		);

		update_option( 'wp_claw_last_backup', $meta, false );

		return array(
			'success'    => true,
			'timestamp'  => $timestamp,
			'backup_dir' => $backup_dir,
			'gz_file'    => $gz_file,
			'size_bytes' => $meta['size_bytes'],
			'message'    => __( 'Backup created successfully.', 'wp-claw' ),
		);
	}

	/**
	 * List all existing backup directories.
	 *
	 * Uses WP_Filesystem->dirlist() to enumerate timestamped subdirectories
	 * in the backup root. Returns metadata for each backup found.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_list_backups( array $params ) {
		$backups = $this->get_backup_list();

		return array(
			'success' => true,
			'backups' => $backups,
			'count'   => count( $backups ),
		);
	}

	/**
	 * Restore a backup — always blocked; requires CONFIRM proposal approval.
	 *
	 * Restoration is a high-risk destructive operation. The 403 WP_Error
	 * signals to the REST bridge that this action must be routed through
	 * the CONFIRM proposal tier and approved by a site admin before
	 * execution is permitted.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return \WP_Error Always returns a 403.
	 */
	private function action_restore_backup( array $params ) {
		return new \WP_Error(
			'wp_claw_backup_confirm_required',
			esc_html__( 'Restore requires CONFIRM approval', 'wp-claw' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * Delete backup directories older than the retention period.
	 *
	 * Default retention is 7 days. Pass $params['retention_days'] (int)
	 * to override. Skips the most recent backup regardless of age to
	 * ensure at least one backup is always available.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return array|\WP_Error
	 */
	private function action_delete_old_backups( array $params ) {
		$wp_filesystem = $this->get_wp_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		$retention_days = isset( $params['retention_days'] )
			? max( 1, absint( $params['retention_days'] ) )
			: self::DEFAULT_RETENTION_DAYS;

		$cutoff  = strtotime( "-{$retention_days} days" );
		$backups = $this->get_backup_list();
		$deleted = array();
		$kept    = array();

		// Always keep the most recent backup.
		usort(
			$backups,
			static function ( array $a, array $b ): int {
				return strcmp( $b['timestamp'], $a['timestamp'] );
			}
		);

		foreach ( $backups as $index => $backup ) {
			$backup_time = strtotime( str_replace( '_', ' ', $backup['timestamp'] ) );

			// Always retain the most recent backup (index 0).
			if ( 0 === $index || false === $backup_time || $backup_time >= $cutoff ) {
				$kept[] = $backup['timestamp'];
				continue;
			}

			$backup_dir = trailingslashit( $this->get_backup_root() ) . $backup['timestamp'];

			if ( $wp_filesystem->is_dir( $backup_dir ) ) {
				// Remove backup file first, then directory.
				$gz_file = trailingslashit( $backup_dir ) . 'database.sql.gz';
				if ( $wp_filesystem->exists( $gz_file ) ) {
					$wp_filesystem->delete( $gz_file );
				}

				$htaccess = trailingslashit( $backup_dir ) . '.htaccess';
				if ( $wp_filesystem->exists( $htaccess ) ) {
					$wp_filesystem->delete( $htaccess );
				}

				$index_file = trailingslashit( $backup_dir ) . 'index.php';
				if ( $wp_filesystem->exists( $index_file ) ) {
					$wp_filesystem->delete( $index_file );
				}

				$wp_filesystem->rmdir( $backup_dir );
				$deleted[] = $backup['timestamp'];
			}
		}

		return array(
			'success'        => true,
			'deleted'        => $deleted,
			'deleted_count'  => count( $deleted ),
			'kept'           => $kept,
			'retention_days' => $retention_days,
		);
	}

	/**
	 * Verify a backup file exists and can be decoded.
	 *
	 * Confirms the backup directory exists, the database.sql.gz file is
	 * present and non-empty, and that gzdecode() succeeds on the first
	 * 4 KB of the file to validate the gzip header.
	 *
	 * @since 1.0.0
	 *
	 * @param array $params Action parameters. Requires 'timestamp' key.
	 *
	 * @return array|\WP_Error
	 */
	private function action_verify_backup( array $params ) {
		if ( empty( $params['timestamp'] ) ) {
			return new \WP_Error(
				'wp_claw_backup_missing_timestamp',
				esc_html__( 'timestamp parameter is required.', 'wp-claw' ),
				array( 'status' => 400 )
			);
		}

		$wp_filesystem = $this->get_wp_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		// Sanitize timestamp: only allow Y-m-d_H-i-s format characters.
		$timestamp  = preg_replace( '/[^0-9\-_]/', '', sanitize_text_field( $params['timestamp'] ) );
		$backup_dir = trailingslashit( $this->get_backup_root() ) . $timestamp;
		$gz_file    = trailingslashit( $backup_dir ) . 'database.sql.gz';

		if ( ! $wp_filesystem->is_dir( $backup_dir ) ) {
			return new \WP_Error(
				'wp_claw_backup_not_found',
				/* translators: %s: timestamp */
				sprintf( esc_html__( 'Backup directory not found for timestamp: %s', 'wp-claw' ), esc_html( $timestamp ) ),
				array( 'status' => 404 )
			);
		}

		if ( ! $wp_filesystem->exists( $gz_file ) ) {
			return new \WP_Error(
				'wp_claw_backup_file_missing',
				esc_html__( 'Backup database.sql.gz file not found.', 'wp-claw' ),
				array( 'status' => 404 )
			);
		}

		$file_size = $wp_filesystem->size( $gz_file );
		if ( ! $file_size || $file_size < 20 ) {
			return new \WP_Error(
				'wp_claw_backup_file_empty',
				esc_html__( 'Backup file is empty or too small to be valid.', 'wp-claw' ),
				array( 'status' => 422 )
			);
		}

		// Read the first 4 KB to verify the gzip header decodes.
		$raw_sample = $wp_filesystem->get_contents( $gz_file );
		if ( false === $raw_sample ) {
			return new \WP_Error(
				'wp_claw_backup_read_failed',
				esc_html__( 'Failed to read backup file for verification.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		$decoded = @gzdecode( substr( $raw_sample, 0, 4096 ) );
		if ( false === $decoded ) {
			return new \WP_Error(
				'wp_claw_backup_corrupt',
				esc_html__( 'Backup file failed gzip decode check — file may be corrupt.', 'wp-claw' ),
				array( 'status' => 422 )
			);
		}

		return array(
			'success'    => true,
			'timestamp'  => $timestamp,
			'size_bytes' => $file_size,
			'valid'      => true,
			'message'    => __( 'Backup verified successfully.', 'wp-claw' ),
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Initialize and return the WP_Filesystem instance.
	 *
	 * Calls WP_Filesystem() with direct credentials so no FTP prompting
	 * occurs in server-side contexts. Returns a WP_Error if initialization
	 * fails (e.g., filesystem method not available).
	 *
	 * @since 1.0.0
	 *
	 * @return \WP_Filesystem_Base|\WP_Error
	 */
	private function get_wp_filesystem() {
		global $wp_filesystem;

		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$initialized = WP_Filesystem( false, '', true );

		if ( ! $initialized || ! $wp_filesystem instanceof \WP_Filesystem_Base ) {
			return new \WP_Error(
				'wp_claw_backup_filesystem_init_failed',
				esc_html__( 'WP_Filesystem could not be initialized.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		return $wp_filesystem;
	}

	/**
	 * Return the absolute path to the backup root directory.
	 *
	 * @since 1.0.0
	 *
	 * @return string
	 */
	private function get_backup_root(): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . self::BACKUP_DIR_NAME;
	}

	/**
	 * Write .htaccess and index.php to a directory to block public access.
	 *
	 * @since 1.0.0
	 *
	 * @param \WP_Filesystem_Base $fs  Initialized WP_Filesystem instance.
	 * @param string              $dir Absolute path to the directory to protect.
	 *
	 * @return void
	 */
	private function write_protection_files( \WP_Filesystem_Base $fs, string $dir ): void {
		$htaccess = trailingslashit( $dir ) . '.htaccess';
		if ( ! $fs->exists( $htaccess ) ) {
			$fs->put_contents( $htaccess, "deny from all\n", FS_CHMOD_FILE );
		}

		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! $fs->exists( $index_file ) ) {
			$fs->put_contents(
				$index_file,
				"<?php\n// Silence is golden.\n",
				FS_CHMOD_FILE
			);
		}
	}

	/**
	 * Build a SQL dump string of all WordPress database tables.
	 *
	 * Iterates over every table returned by SHOW TABLES. For each table,
	 * generates a DROP TABLE IF EXISTS statement, a CREATE TABLE statement
	 * (via SHOW CREATE TABLE), and INSERT statements for all rows. Aborts
	 * with a WP_Error if the table count exceeds MAX_TABLES to prevent
	 * memory exhaustion.
	 *
	 * @since 1.0.0
	 *
	 * @return string|\WP_Error SQL dump string on success, WP_Error on failure.
	 */
	private function build_sql_dump() {
		global $wpdb;

		$tables = $wpdb->get_col( 'SHOW TABLES' );

		if ( empty( $tables ) ) {
			return new \WP_Error(
				'wp_claw_backup_no_tables',
				esc_html__( 'No tables found in database.', 'wp-claw' ),
				array( 'status' => 500 )
			);
		}

		if ( count( $tables ) > self::MAX_TABLES ) {
			return new \WP_Error(
				'wp_claw_backup_too_many_tables',
				/* translators: %d: table count */
				sprintf( esc_html__( 'Database has %1$d tables — exceeds maximum of %2$d for backup.', 'wp-claw' ), count( $tables ), self::MAX_TABLES ),
				array( 'status' => 500 )
			);
		}

		$sql  = "-- WP-Claw Database Backup\n";
		$sql .= '-- Generated: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n";
		$sql .= '-- WordPress version: ' . esc_attr( get_bloginfo( 'version' ) ) . "\n";
		$sql .= "-- -------------------------------------------------------\n\n";
		$sql .= "SET FOREIGN_KEY_CHECKS=0;\n\n";

		foreach ( $tables as $table ) {
			$table = (string) $table;

			// DROP + CREATE TABLE.
			$sql .= 'DROP TABLE IF EXISTS `' . esc_sql( $table ) . "`;\n";

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
			$create_row = $wpdb->get_row(
				$wpdb->prepare( 'SHOW CREATE TABLE %i', $table ),
				ARRAY_N
			);

			if ( $create_row ) {
				$sql .= $create_row[1] . ";\n\n";
			}

			// INSERT rows.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.DirectQuery
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i', $table ),
				ARRAY_A
			);

			if ( ! empty( $rows ) ) {
				foreach ( $rows as $row ) {
					$values = array_map(
						static function ( $value ): string {
							if ( null === $value ) {
								return 'NULL';
							}
							// Escape single quotes and backslashes.
							return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value ) . "'";
						},
						$row
					);

					$sql .= 'INSERT INTO `' . esc_sql( $table ) . '` VALUES (' . implode( ', ', $values ) . ");\n";
				}

				$sql .= "\n";
			}
		}

		$sql .= "SET FOREIGN_KEY_CHECKS=1;\n";

		return $sql;
	}

	/**
	 * Count the number of tables represented in a SQL dump string.
	 *
	 * Uses a simple regex count of DROP TABLE occurrences as a proxy
	 * for the number of tables dumped — suitable for metadata only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $sql SQL dump string.
	 *
	 * @return int
	 */
	private function count_tables_in_dump( string $sql ): int {
		return (int) preg_match_all( '/^DROP TABLE IF EXISTS/m', $sql );
	}

	/**
	 * Build the backup list from the backup root directory.
	 *
	 * Returns an array of backup metadata arrays, each with 'timestamp',
	 * 'path', and 'size' keys. Returns an empty array if the backup root
	 * does not exist or WP_Filesystem cannot be initialized.
	 *
	 * @since 1.0.0
	 *
	 * @return array
	 */
	private function get_backup_list(): array {
		$wp_filesystem = $this->get_wp_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return array();
		}

		$backup_root = $this->get_backup_root();

		if ( ! $wp_filesystem->is_dir( $backup_root ) ) {
			return array();
		}

		$entries = $wp_filesystem->dirlist( $backup_root, false, false );

		if ( ! is_array( $entries ) ) {
			return array();
		}

		$backups = array();

		foreach ( $entries as $entry_name => $entry_data ) {
			// Only consider directories that look like timestamps (Y-m-d_H-i-s).
			if ( 'd' !== $entry_data['type'] ) {
				continue;
			}

			if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}_\d{2}-\d{2}-\d{2}$/', $entry_name ) ) {
				continue;
			}

			$gz_file = trailingslashit( $backup_root ) . $entry_name . '/database.sql.gz';
			$gz_size = $wp_filesystem->exists( $gz_file ) ? $wp_filesystem->size( $gz_file ) : 0;

			$backups[] = array(
				'timestamp' => $entry_name,
				'path'      => trailingslashit( $backup_root ) . $entry_name,
				'size'      => (int) $gz_size,
			);
		}

		// Sort newest first.
		usort(
			$backups,
			static function ( array $a, array $b ): int {
				return strcmp( $b['timestamp'], $a['timestamp'] );
			}
		);

		return $backups;
	}
}
