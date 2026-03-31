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
		return __( 'Backup', 'claw-agent' );
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
			'create_targeted_snapshot',
			'restore_snapshot',
			'list_snapshots',
			'cleanup_expired_snapshots',
			'create_file_backup',
			'get_backup_retention',
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

			case 'create_targeted_snapshot':
				return $this->action_create_targeted_snapshot( $params );

			case 'restore_snapshot':
				return $this->action_restore_snapshot( $params );

			case 'list_snapshots':
				return $this->action_list_snapshots( $params );

			case 'cleanup_expired_snapshots':
				return $this->action_cleanup_expired_snapshots( $params );

			case 'create_file_backup':
				return $this->action_create_file_backup( $params );

			case 'get_backup_retention':
				return $this->action_get_backup_retention( $params );

			default:
				return new \WP_Error(
					'wp_claw_backup_unknown_action',
					/* translators: %s: action name */
					sprintf( esc_html__( 'Unknown backup action: %s', 'claw-agent' ), esc_html( $action ) ),
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
		global $wpdb;

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

		// Snapshot state.
		$snapshots_table = $wpdb->prefix . 'wp_claw_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh state snapshot needed for sync.
		$active_snapshots = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$snapshots_table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prefix is safe.
				'active'
			)
		);

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- fresh state snapshot needed for sync.
		$oldest_snapshot_time = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT MIN(created_at) FROM {$snapshots_table} WHERE status = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prefix is safe.
				'active'
			)
		);

		$oldest_snapshot_hours = 0;
		if ( $oldest_snapshot_time ) {
			$oldest_snapshot_hours = round( ( time() - strtotime( $oldest_snapshot_time ) ) / 3600, 1 );
		}

		// File backup state.
		$wp_filesystem     = $this->get_wp_filesystem();
		$file_backup_exists = false;
		$last_file_backup   = '';

		if ( ! is_wp_error( $wp_filesystem ) ) {
			$backup_root = $this->get_backup_root();
			$file_backup = trailingslashit( $backup_root ) . 'wp-content-backup.zip';

			if ( $wp_filesystem->exists( $file_backup ) ) {
				$file_backup_exists = true;
				$last_file_backup   = gmdate( 'Y-m-d H:i:s', $wp_filesystem->mtime( $file_backup ) );
			}
		}

		return array(
			'last_backup_at'       => $last_time,
			'backup_count'         => $count,
			'total_size_bytes'     => $total_size,
			'active_snapshots'     => $active_snapshots,
			'oldest_snapshot_hours' => $oldest_snapshot_hours,
			'file_backup_exists'   => $file_backup_exists,
			'last_file_backup_at'  => $last_file_backup,
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
					esc_html__( 'Failed to create backup root directory.', 'claw-agent' ),
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
				esc_html__( 'Failed to create backup subdirectory.', 'claw-agent' ),
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
				esc_html__( 'Failed to gzip-compress the database dump.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		$gz_file = trailingslashit( $backup_dir ) . 'database.sql.gz';
		if ( ! $wp_filesystem->put_contents( $gz_file, $gz_data, FS_CHMOD_FILE ) ) {
			return new \WP_Error(
				'wp_claw_backup_write_failed',
				esc_html__( 'Failed to write backup file.', 'claw-agent' ),
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
			'message'    => __( 'Backup created successfully.', 'claw-agent' ),
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
			esc_html__( 'Restore requires CONFIRM approval', 'claw-agent' ),
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
				esc_html__( 'timestamp parameter is required.', 'claw-agent' ),
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
				sprintf( esc_html__( 'Backup directory not found for timestamp: %s', 'claw-agent' ), esc_html( $timestamp ) ),
				array( 'status' => 404 )
			);
		}

		if ( ! $wp_filesystem->exists( $gz_file ) ) {
			return new \WP_Error(
				'wp_claw_backup_file_missing',
				esc_html__( 'Backup database.sql.gz file not found.', 'claw-agent' ),
				array( 'status' => 404 )
			);
		}

		$file_size = $wp_filesystem->size( $gz_file );
		if ( ! $file_size || $file_size < 20 ) {
			return new \WP_Error(
				'wp_claw_backup_file_empty',
				esc_html__( 'Backup file is empty or too small to be valid.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		// Read the first 4 KB to verify the gzip header decodes.
		$raw_sample = $wp_filesystem->get_contents( $gz_file );
		if ( false === $raw_sample ) {
			return new \WP_Error(
				'wp_claw_backup_read_failed',
				esc_html__( 'Failed to read backup file for verification.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		$decoded = @gzdecode( substr( $raw_sample, 0, 4096 ) );
		if ( false === $decoded ) {
			return new \WP_Error(
				'wp_claw_backup_corrupt',
				esc_html__( 'Backup file failed gzip decode check — file may be corrupt.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		return array(
			'success'    => true,
			'timestamp'  => $timestamp,
			'size_bytes' => $file_size,
			'valid'      => true,
			'message'    => __( 'Backup verified successfully.', 'claw-agent' ),
		);
	}

	/**
	 * Create a targeted snapshot of specific tables and files.
	 *
	 * Snapshots are lightweight, time-limited backups tied to a specific agent
	 * action. Each snapshot has a unique ID, stores only the requested tables
	 * and files, and expires after 72 hours for automatic cleanup.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params {
	 *   @type string   $snapshot_id         Required. UUID string identifying the snapshot.
	 *   @type string   $agent               Required. Agent name requesting the snapshot.
	 *   @type string   $action_description  Required. Description of the action being snapshotted.
	 *   @type string[] $tables              Optional. Array of table names to export.
	 *   @type string[] $file_paths          Optional. Array of file paths to copy.
	 * }
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array|\WP_Error
	 */
	private function action_create_targeted_snapshot( array $params ) {
		global $wpdb;

		$snapshot_id = isset( $params['snapshot_id'] ) ? sanitize_text_field( wp_unslash( $params['snapshot_id'] ) ) : '';
		if ( '' === $snapshot_id ) {
			return new \WP_Error(
				'wp_claw_backup_missing_snapshot_id',
				esc_html__( 'snapshot_id parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$agent = isset( $params['agent'] ) ? sanitize_text_field( wp_unslash( $params['agent'] ) ) : '';
		if ( '' === $agent ) {
			return new \WP_Error(
				'wp_claw_backup_missing_agent',
				esc_html__( 'agent parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$action_description = isset( $params['action_description'] ) ? sanitize_text_field( wp_unslash( $params['action_description'] ) ) : '';
		if ( '' === $action_description ) {
			return new \WP_Error(
				'wp_claw_backup_missing_action_description',
				esc_html__( 'action_description parameter is required.', 'claw-agent' ),
				array( 'status' => 422 )
			);
		}

		$tables    = isset( $params['tables'] ) && is_array( $params['tables'] ) ? array_map( 'sanitize_text_field', $params['tables'] ) : array();
		$file_paths = isset( $params['file_paths'] ) && is_array( $params['file_paths'] ) ? array_map( 'sanitize_text_field', $params['file_paths'] ) : array();

		$wp_filesystem = $this->get_wp_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		$backup_root  = $this->get_backup_root();
		$snapshot_dir = trailingslashit( $backup_root ) . 'snapshots/' . $snapshot_id;

		// Ensure parent directories exist.
		$snapshots_root = trailingslashit( $backup_root ) . 'snapshots';
		if ( ! $wp_filesystem->is_dir( $snapshots_root ) ) {
			if ( ! wp_mkdir_p( $snapshots_root ) ) {
				return new \WP_Error(
					'wp_claw_backup_mkdir_failed',
					esc_html__( 'Failed to create snapshots directory.', 'claw-agent' ),
					array( 'status' => 500 )
				);
			}
			$this->write_protection_files( $wp_filesystem, $snapshots_root );
		}

		if ( ! wp_mkdir_p( $snapshot_dir ) ) {
			return new \WP_Error(
				'wp_claw_backup_mkdir_failed',
				esc_html__( 'Failed to create snapshot directory.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		$this->write_protection_files( $wp_filesystem, $snapshot_dir );

		// Export requested tables.
		$tables_count = 0;
		foreach ( $tables as $table_name ) {
			$table_name = sanitize_key( $table_name );
			if ( '' === $table_name ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- targeted table export for snapshot.
			$rows = $wpdb->get_results(
				$wpdb->prepare( 'SELECT * FROM %i', $table_name ),
				ARRAY_A
			);

			if ( null === $rows ) {
				continue;
			}

			$sql = '-- Snapshot of table: ' . esc_sql( $table_name ) . "\n";
			$sql .= '-- Created: ' . gmdate( 'Y-m-d H:i:s' ) . " UTC\n\n";

			foreach ( $rows as $row ) {
				$values = array_map(
					static function ( $value ): string {
						if ( null === $value ) {
							return 'NULL';
						}
						return "'" . str_replace( array( '\\', "'" ), array( '\\\\', "\\'" ), (string) $value ) . "'";
					},
					$row
				);
				$sql .= 'INSERT INTO `' . esc_sql( $table_name ) . '` VALUES (' . implode( ', ', $values ) . ");\n";
			}

			$gz_data = gzencode( $sql, 6 );
			if ( false !== $gz_data ) {
				$gz_file = trailingslashit( $snapshot_dir ) . $table_name . '.sql.gz';
				$wp_filesystem->put_contents( $gz_file, $gz_data, FS_CHMOD_FILE );
				++$tables_count;
			}
		}

		// Copy requested files.
		$files_count = 0;
		foreach ( $file_paths as $file_path ) {
			$file_path = wp_normalize_path( $file_path );

			// Security: only allow files within ABSPATH.
			if ( 0 !== strpos( $file_path, wp_normalize_path( ABSPATH ) ) ) {
				continue;
			}

			if ( ! $wp_filesystem->exists( $file_path ) || $wp_filesystem->is_dir( $file_path ) ) {
				continue;
			}

			$dest = trailingslashit( $snapshot_dir ) . basename( $file_path );
			if ( $wp_filesystem->copy( $file_path, $dest ) ) {
				++$files_count;
			}
		}

		// Record metadata in the snapshots table.
		$snapshots_table = $wpdb->prefix . 'wp_claw_snapshots';
		$now             = current_time( 'mysql', true );
		$expires_at      = gmdate( 'Y-m-d H:i:s', time() + ( 72 * HOUR_IN_SECONDS ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery -- intentional INSERT into WP-Claw custom table.
		$wpdb->insert(
			$snapshots_table,
			array(
				'snapshot_id'        => $snapshot_id,
				'agent'              => $agent,
				'action_description' => $action_description,
				'path'               => $snapshot_dir,
				'tables_count'       => $tables_count,
				'files_count'        => $files_count,
				'status'             => 'active',
				'created_at'         => $now,
				'expires_at'         => $expires_at,
			),
			array( '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s' )
		);

		wp_claw_log(
			'Backup: targeted snapshot created.',
			'info',
			array(
				'snapshot_id'  => $snapshot_id,
				'agent'        => $agent,
				'tables_count' => $tables_count,
				'files_count'  => $files_count,
			)
		);

		return array(
			'success'      => true,
			'snapshot_id'  => $snapshot_id,
			'path'         => $snapshot_dir,
			'tables_count' => $tables_count,
			'files_count'  => $files_count,
			'expires_at'   => $expires_at,
			'message'      => __( 'Targeted snapshot created successfully.', 'claw-agent' ),
		);
	}

	/**
	 * Restore a snapshot — always blocked; requires CONFIRM proposal approval.
	 *
	 * Snapshot restoration is a high-risk destructive operation. The 403
	 * WP_Error signals that this action must be routed through the CONFIRM
	 * proposal tier, identical to restore_backup.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters from the agent.
	 *
	 * @return \WP_Error Always returns a 403.
	 */
	private function action_restore_snapshot( array $params ) {
		return new \WP_Error(
			'wp_claw_backup_confirm_required',
			esc_html__( 'Snapshot restore requires CONFIRM approval', 'claw-agent' ),
			array( 'status' => 403 )
		);
	}

	/**
	 * List all active snapshots from the database.
	 *
	 * Queries the wp_claw_snapshots table for entries with status 'active'
	 * and returns them ordered newest first.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters from the agent. Currently unused.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array|\WP_Error
	 */
	private function action_list_snapshots( array $params ) {
		global $wpdb;

		$snapshots_table = $wpdb->prefix . 'wp_claw_snapshots';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live query; agent needs fresh state.
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT snapshot_id, agent, action_description, path, tables_count, files_count, status, created_at, expires_at FROM {$snapshots_table} WHERE status = %s ORDER BY created_at DESC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prefix is safe.
				'active'
			),
			ARRAY_A
		);

		if ( null === $rows ) {
			return new \WP_Error(
				'wp_claw_db_error',
				esc_html__( 'Database error fetching snapshots.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		$snapshots = array();
		foreach ( $rows as $row ) {
			$snapshots[] = array(
				'snapshot_id'        => sanitize_text_field( $row['snapshot_id'] ),
				'agent'              => sanitize_text_field( $row['agent'] ),
				'action_description' => sanitize_text_field( $row['action_description'] ),
				'path'               => sanitize_text_field( $row['path'] ),
				'tables_count'       => absint( $row['tables_count'] ),
				'files_count'        => absint( $row['files_count'] ),
				'created_at'         => sanitize_text_field( $row['created_at'] ),
				'expires_at'         => sanitize_text_field( $row['expires_at'] ),
			);
		}

		return array(
			'success'   => true,
			'snapshots' => $snapshots,
			'count'     => count( $snapshots ),
		);
	}

	/**
	 * Clean up expired snapshots.
	 *
	 * Queries snapshots where expires_at has passed, deletes their directories
	 * via WP_Filesystem, and updates status to 'cleaned'.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters from the agent. Currently unused.
	 *
	 * @global \wpdb $wpdb WordPress database abstraction object.
	 *
	 * @return array|\WP_Error
	 */
	private function action_cleanup_expired_snapshots( array $params ) {
		global $wpdb;

		$wp_filesystem = $this->get_wp_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		$snapshots_table = $wpdb->prefix . 'wp_claw_snapshots';
		$now             = current_time( 'mysql', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live query; cleanup needs fresh state.
		$expired = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT snapshot_id, path FROM {$snapshots_table} WHERE status = %s AND expires_at < %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- prefix is safe.
				'active',
				$now
			),
			ARRAY_A
		);

		if ( null === $expired ) {
			return new \WP_Error(
				'wp_claw_db_error',
				esc_html__( 'Database error fetching expired snapshots.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		$cleaned = 0;

		foreach ( $expired as $row ) {
			$path = sanitize_text_field( $row['path'] );

			if ( $wp_filesystem->is_dir( $path ) ) {
				$wp_filesystem->delete( $path, true );
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- intentional UPDATE for cleanup.
			$wpdb->update(
				$snapshots_table,
				array( 'status' => 'cleaned' ),
				array( 'snapshot_id' => sanitize_text_field( $row['snapshot_id'] ) ),
				array( '%s' ),
				array( '%s' )
			);

			++$cleaned;
		}

		wp_claw_log(
			'Backup: cleaned up expired snapshots.',
			'info',
			array( 'cleaned_count' => $cleaned )
		);

		return array(
			'success'       => true,
			'cleaned_count' => $cleaned,
			'message'       => sprintf(
				/* translators: %d: number of snapshots cleaned */
				__( '%d expired snapshots cleaned up.', 'claw-agent' ),
				$cleaned
			),
		);
	}

	/**
	 * Create a compressed file backup of the wp-content directory.
	 *
	 * Backs up plugins, themes, and mu-plugins directories. Optionally
	 * includes the uploads directory. Stores the ZIP in the backup root.
	 *
	 * @since 1.1.0
	 *
	 * @param array $params {
	 *   @type bool $include_uploads Whether to include wp-content/uploads. Default false.
	 * }
	 *
	 * @return array|\WP_Error
	 */
	private function action_create_file_backup( array $params ) {
		$include_uploads = ! empty( $params['include_uploads'] );

		$wp_filesystem = $this->get_wp_filesystem();
		if ( is_wp_error( $wp_filesystem ) ) {
			return $wp_filesystem;
		}

		$backup_root = $this->get_backup_root();

		// Ensure backup root exists.
		if ( ! $wp_filesystem->is_dir( $backup_root ) ) {
			if ( ! wp_mkdir_p( $backup_root ) ) {
				return new \WP_Error(
					'wp_claw_backup_mkdir_failed',
					esc_html__( 'Failed to create backup root directory.', 'claw-agent' ),
					array( 'status' => 500 )
				);
			}
			$this->write_protection_files( $wp_filesystem, $backup_root );
		}

		$zip_file = trailingslashit( $backup_root ) . 'wp-content-backup.zip';

		if ( ! class_exists( 'ZipArchive' ) ) {
			return new \WP_Error(
				'wp_claw_backup_zip_unavailable',
				esc_html__( 'ZipArchive PHP extension is not available.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		$zip = new \ZipArchive();
		$res = $zip->open( $zip_file, \ZipArchive::CREATE | \ZipArchive::OVERWRITE );

		if ( true !== $res ) {
			return new \WP_Error(
				'wp_claw_backup_zip_create_failed',
				esc_html__( 'Failed to create ZIP archive.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		$content_dir = trailingslashit( WP_CONTENT_DIR );
		$dirs_to_backup = array( 'plugins', 'themes', 'mu-plugins' );

		if ( $include_uploads ) {
			$dirs_to_backup[] = 'uploads';
		}

		foreach ( $dirs_to_backup as $dir_name ) {
			$dir_path = $content_dir . $dir_name;

			if ( ! is_dir( $dir_path ) ) {
				continue;
			}

			$iterator = new \RecursiveIteratorIterator(
				new \RecursiveDirectoryIterator( $dir_path, \RecursiveDirectoryIterator::SKIP_DOTS ),
				\RecursiveIteratorIterator::LEAVES_ONLY
			);

			foreach ( $iterator as $file ) {
				if ( $file->isDir() ) {
					continue;
				}

				$real_path     = $file->getRealPath();
				$relative_path = $dir_name . '/' . substr( $real_path, strlen( $dir_path ) + 1 );

				$zip->addFile( $real_path, $relative_path );
			}
		}

		$zip->close();

		$file_size = filesize( $zip_file );

		wp_claw_log(
			'Backup: file backup created.',
			'info',
			array(
				'path'             => $zip_file,
				'size_bytes'       => $file_size,
				'include_uploads'  => $include_uploads,
			)
		);

		return array(
			'success'         => true,
			'path'            => $zip_file,
			'size_bytes'      => $file_size,
			'include_uploads' => $include_uploads,
			'message'         => __( 'File backup created successfully.', 'claw-agent' ),
		);
	}

	/**
	 * Return current backup retention settings.
	 *
	 * Reads the daily and weekly retention values from wp_options with
	 * sensible defaults (30 days daily, 90 days weekly).
	 *
	 * @since 1.1.0
	 *
	 * @param array $params Action parameters from the agent. Currently unused.
	 *
	 * @return array
	 */
	private function action_get_backup_retention( array $params ): array {
		$daily_retention  = absint( get_option( 'wp_claw_backup_daily_retention', 30 ) );
		$weekly_retention = absint( get_option( 'wp_claw_backup_weekly_retention', 90 ) );

		return array(
			'success'          => true,
			'daily_retention'  => $daily_retention ? $daily_retention : 30,
			'weekly_retention' => $weekly_retention ? $weekly_retention : 90,
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
				esc_html__( 'WP_Filesystem could not be initialized.', 'claw-agent' ),
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

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- custom table query.
		$tables = $wpdb->get_col( 'SHOW TABLES' );

		if ( empty( $tables ) ) {
			return new \WP_Error(
				'wp_claw_backup_no_tables',
				esc_html__( 'No tables found in database.', 'claw-agent' ),
				array( 'status' => 500 )
			);
		}

		if ( count( $tables ) > self::MAX_TABLES ) {
			return new \WP_Error(
				'wp_claw_backup_too_many_tables',
				/* translators: %d: table count */
				sprintf( esc_html__( 'Database has %1$d tables — exceeds maximum of %2$d for backup.', 'claw-agent' ), count( $tables ), self::MAX_TABLES ),
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

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.DirectDatabaseQuery.SchemaChange
			$create_row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );

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
