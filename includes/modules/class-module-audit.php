<?php
/**
 * Site Audit module.
 *
 * @package    WPClaw
 * @subpackage WPClaw/modules
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.1.0
 */

namespace WPClaw\Modules;

use WPClaw\Module_Base;

defined( 'ABSPATH' ) || exit;

/**
 * Site Audit module — managed by the Architect agent.
 *
 * Provides comprehensive site health auditing: WordPress/PHP/MySQL version
 * checks, plugin update status, disk usage, database statistics, SSL
 * certificate inspection, weekly cross-module reports, and backup
 * integrity verification.
 *
 * @since 1.1.0
 */
class Module_Audit extends Module_Base {

	/**
	 * Option key for the last audit timestamp.
	 *
	 * @since 1.1.0
	 * @var   string
	 */
	const OPT_LAST_AUDIT = 'wp_claw_last_audit';

	/**
	 * Option key for the last backup metadata.
	 *
	 * @since 1.1.0
	 * @var   string
	 */
	const OPT_LAST_BACKUP = 'wp_claw_last_backup';

	// -------------------------------------------------------------------------
	// Contract implementation.
	// -------------------------------------------------------------------------

	/**
	 * Return the module slug.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_slug(): string {
		return 'audit';
	}

	/**
	 * Return the module display name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_name(): string {
		return __( 'Site Audit', 'claw-agent' );
	}

	/**
	 * Return the responsible Klawty agent name.
	 *
	 * @since 1.1.0
	 *
	 * @return string
	 */
	public function get_agent(): string {
		return 'architect';
	}

	/**
	 * Return the allowlisted action strings for this module.
	 *
	 * @since 1.1.0
	 *
	 * @return string[]
	 */
	public function get_allowed_actions(): array {
		return array(
			'run_site_audit',
			'get_plugin_versions',
			'get_plugin_updates',
			'get_disk_usage',
			'get_database_size',
			'get_ssl_info',
			'get_weekly_report',
			'check_backup_integrity',
		);
	}

	/**
	 * Handle an inbound agent action.
	 *
	 * @since 1.1.0
	 *
	 * @param string $action The action to perform.
	 * @param array  $params Parameters sent by the agent.
	 *
	 * @return array|\WP_Error
	 */
	public function handle_action( string $action, array $params ) {
		switch ( $action ) {
			case 'run_site_audit':
				return $this->action_run_site_audit();

			case 'get_plugin_versions':
				return $this->action_get_plugin_versions();

			case 'get_plugin_updates':
				return $this->action_get_plugin_updates();

			case 'get_disk_usage':
				return $this->action_get_disk_usage();

			case 'get_database_size':
				return $this->action_get_database_size();

			case 'get_ssl_info':
				return $this->action_get_ssl_info();

			case 'get_weekly_report':
				return $this->action_get_weekly_report();

			case 'check_backup_integrity':
				return $this->action_check_backup_integrity();

			default:
				return new \WP_Error(
					'wp_claw_audit_unknown_action',
					sprintf(
						/* translators: %s: action name */
						__( 'Unknown Audit action: %s', 'claw-agent' ),
						esc_html( $action )
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Return the current audit state of the WordPress site.
	 *
	 * Provides the Architect agent with a snapshot for decision-making:
	 * core versions, pending update count, disk usage, database size,
	 * SSL days remaining, and last audit timestamp.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	public function get_state(): array {
		$update_count = 0;
		$update_data  = get_site_transient( 'update_plugins' );
		if ( $update_data && ! empty( $update_data->response ) ) {
			$update_count = count( $update_data->response );
		}

		$disk_usage = $this->calculate_disk_usage();
		$db_size    = $this->calculate_database_size();
		$ssl_info   = $this->fetch_ssl_info();

		return array(
			'wordpress_version'   => get_bloginfo( 'version' ),
			'php_version'         => PHP_VERSION,
			'plugin_update_count' => $update_count,
			'disk_usage_mb'       => $disk_usage['total_mb'],
			'database_size_mb'    => $db_size['total_mb'],
			'ssl_days_remaining'  => isset( $ssl_info['days_remaining'] ) ? $ssl_info['days_remaining'] : null,
			'last_audit_at'       => get_option( self::OPT_LAST_AUDIT, '' ),
		);
	}

	/**
	 * Register WordPress action hooks for this module.
	 *
	 * The Audit module has no automatic hooks — it is triggered
	 * exclusively by cron events and direct agent actions.
	 *
	 * @since 1.1.0
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		// No hooks — cron/action-driven only.
	}

	// -------------------------------------------------------------------------
	// Action handlers.
	// -------------------------------------------------------------------------

	/**
	 * Run a comprehensive site health audit.
	 *
	 * Collects WordPress version, PHP version, MySQL version, active plugin
	 * count, active theme, SSL status, disk usage, database size, backup
	 * status, pending updates, memory limit, max execution time, and
	 * multisite status. Records the audit timestamp.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_run_site_audit(): array {
		global $wpdb;

		// Ensure plugin functions are available.
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$active_plugins = get_option( 'active_plugins', array() );
		$theme          = wp_get_theme();
		$ssl_info       = $this->fetch_ssl_info();
		$disk_usage     = $this->calculate_disk_usage();
		$db_size        = $this->calculate_database_size();
		$backup_meta    = get_option( self::OPT_LAST_BACKUP, array() );

		$update_data    = get_site_transient( 'update_plugins' );
		$pending_count  = ( $update_data && ! empty( $update_data->response ) )
			? count( $update_data->response )
			: 0;

		// Record audit timestamp.
		$timestamp = current_time( 'mysql', true );
		update_option( self::OPT_LAST_AUDIT, $timestamp );

		wp_claw_log( 'Site audit completed.', 'info' );

		return array(
			'success' => true,
			'data'    => array(
				'wordpress_version'  => get_bloginfo( 'version' ),
				'php_version'        => PHP_VERSION,
				'mysql_version'      => $wpdb->db_version(),
				'active_plugins'     => count( $active_plugins ),
				'active_theme'       => $theme->get( 'Name' ),
				'ssl_valid'          => ! empty( $ssl_info['valid'] ),
				'disk_usage_mb'      => $disk_usage['total_mb'],
				'database_size_mb'   => $db_size['total_mb'],
				'backup_status'      => ! empty( $backup_meta ) ? 'available' : 'none',
				'pending_updates'    => $pending_count,
				'memory_limit'       => ini_get( 'memory_limit' ),
				'max_execution_time' => (int) ini_get( 'max_execution_time' ),
				'is_multisite'       => is_multisite(),
				'audited_at'         => $timestamp,
			),
		);
	}

	/**
	 * Return all installed plugins with version information.
	 *
	 * Lists every plugin with its slug, name, current version, active
	 * status, whether an update is available, and the available update
	 * version.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_get_plugin_versions(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$all_plugins    = get_plugins();
		$active_plugins = get_option( 'active_plugins', array() );
		$update_data    = get_site_transient( 'update_plugins' );
		$updates        = ( $update_data && ! empty( $update_data->response ) )
			? $update_data->response
			: array();

		$result = array();
		foreach ( $all_plugins as $file => $plugin_data ) {
			$has_update     = isset( $updates[ $file ] );
			$update_version = $has_update && isset( $updates[ $file ]->new_version )
				? $updates[ $file ]->new_version
				: '';

			$result[] = array(
				'slug'             => $file,
				'name'             => $plugin_data['Name'],
				'version'          => $plugin_data['Version'],
				'active'           => in_array( $file, $active_plugins, true ),
				'update_available' => $has_update,
				'update_version'   => $update_version,
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'plugins' => $result,
				'total'   => count( $result ),
			),
		);
	}

	/**
	 * Return only plugins with available updates.
	 *
	 * Filters the full plugin list to entries where update_available
	 * is true. Includes changelog URL if available in the update data.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_get_plugin_updates(): array {
		$versions_result = $this->action_get_plugin_versions();

		if ( ! isset( $versions_result['data']['plugins'] ) ) {
			return $versions_result;
		}

		$update_data = get_site_transient( 'update_plugins' );
		$updates     = ( $update_data && ! empty( $update_data->response ) )
			? $update_data->response
			: array();

		$with_updates = array();
		foreach ( $versions_result['data']['plugins'] as $plugin ) {
			if ( ! $plugin['update_available'] ) {
				continue;
			}

			$changelog_url = '';
			if ( isset( $updates[ $plugin['slug'] ] ) && isset( $updates[ $plugin['slug'] ]->url ) ) {
				$changelog_url = $updates[ $plugin['slug'] ]->url;
			}

			$plugin['changelog_url'] = $changelog_url;
			$with_updates[]          = $plugin;
		}

		return array(
			'success' => true,
			'data'    => array(
				'plugins' => $with_updates,
				'total'   => count( $with_updates ),
			),
		);
	}

	/**
	 * Return WordPress directory sizes.
	 *
	 * Calculates the size of uploads, plugins, and themes directories
	 * using the WordPress core get_dirsize() function.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_get_disk_usage(): array {
		$usage = $this->calculate_disk_usage();

		return array(
			'success' => true,
			'data'    => $usage,
		);
	}

	/**
	 * Return database table statistics.
	 *
	 * Uses SHOW TABLE STATUS to compute total database size, table count,
	 * largest table, revision count, transient count, and autoload size.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_get_database_size(): array {
		$db_info = $this->calculate_database_size();

		return array(
			'success' => true,
			'data'    => $db_info,
		);
	}

	/**
	 * Return SSL certificate details for the site.
	 *
	 * Connects to the site's own URL via stream_socket_client() and
	 * parses the SSL certificate to extract issuer, expiry, and
	 * chain completeness.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_get_ssl_info(): array {
		$ssl_info = $this->fetch_ssl_info();

		return array(
			'success' => true,
			'data'    => $ssl_info,
		);
	}

	/**
	 * Generate a cross-module weekly report.
	 *
	 * Aggregates state from all enabled modules and queries the tasks
	 * and proposals tables for activity completed in the last 7 days.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_get_weekly_report(): array {
		global $wpdb;

		// Gather state from all enabled modules.
		$plugin       = \WPClaw\WP_Claw::get_instance();
		$modules      = $plugin->get_enabled_modules();
		$module_states = array();

		foreach ( $modules as $slug => $module ) {
			$module_states[ $slug ] = $module->get_state();
		}

		// Query tasks completed this week.
		$tasks_table = $wpdb->prefix . 'claw_tasks';
		$week_ago    = gmdate( 'Y-m-d H:i:s', time() - WEEK_IN_SECONDS );

		$tasks_completed = 0;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$task_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$tasks_table} WHERE status = %s AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'done',
				$week_ago
			)
		);
		if ( null !== $task_count ) {
			$tasks_completed = (int) $task_count;
		}

		// Query proposals resolved this week.
		$proposals_table    = $wpdb->prefix . 'claw_proposals';
		$proposals_approved = 0;
		$proposals_rejected = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$approved_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$proposals_table} WHERE status = %s AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'approved',
				$week_ago
			)
		);
		if ( null !== $approved_count ) {
			$proposals_approved = (int) $approved_count;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rejected_count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$proposals_table} WHERE status = %s AND created_at > %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				'rejected',
				$week_ago
			)
		);
		if ( null !== $rejected_count ) {
			$proposals_rejected = (int) $rejected_count;
		}

		return array(
			'success' => true,
			'data'    => array(
				'modules'                => $module_states,
				'tasks_completed_week'   => $tasks_completed,
				'proposals_approved_week' => $proposals_approved,
				'proposals_rejected_week' => $proposals_rejected,
				'generated_at'           => current_time( 'mysql', true ),
			),
		);
	}

	/**
	 * Verify latest backup exists and is valid.
	 *
	 * Reads the backup metadata from wp_options, checks the file exists
	 * at the stored path, and verifies gzip header bytes if found.
	 *
	 * @since 1.1.0
	 *
	 * @return array
	 */
	private function action_check_backup_integrity(): array {
		$backup_meta = get_option( self::OPT_LAST_BACKUP, array() );

		if ( empty( $backup_meta ) || ! is_array( $backup_meta ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'exists'   => false,
					'path'     => '',
					'verified' => false,
					'error'    => __( 'No backup metadata found.', 'claw-agent' ),
				),
			);
		}

		$path       = isset( $backup_meta['path'] ) ? sanitize_text_field( $backup_meta['path'] ) : '';
		$created_at = isset( $backup_meta['created_at'] ) ? sanitize_text_field( $backup_meta['created_at'] ) : '';

		if ( '' === $path ) {
			return array(
				'success' => true,
				'data'    => array(
					'exists'   => false,
					'path'     => '',
					'verified' => false,
					'error'    => __( 'Backup path is empty.', 'claw-agent' ),
				),
			);
		}

		// Check file existence via WP_Filesystem.
		global $wp_filesystem;
		if ( ! function_exists( 'WP_Filesystem' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		WP_Filesystem();

		if ( ! $wp_filesystem || ! $wp_filesystem->exists( $path ) ) {
			return array(
				'success' => true,
				'data'    => array(
					'exists'     => false,
					'path'       => $path,
					'created_at' => $created_at,
					'verified'   => false,
					'error'      => __( 'Backup file not found at stored path.', 'claw-agent' ),
				),
			);
		}

		$size_bytes = $wp_filesystem->size( $path );

		// Read first 4KB and verify gzip header (first 2 bytes = 0x1f 0x8b).
		$header   = $wp_filesystem->get_contents( $path );
		$verified = false;

		if ( false !== $header && strlen( $header ) >= 2 ) {
			// Check gzip magic number.
			$byte_one = ord( $header[0] );
			$byte_two = ord( $header[1] );
			$verified = ( 0x1f === $byte_one && 0x8b === $byte_two );
		}

		return array(
			'success' => true,
			'data'    => array(
				'exists'              => true,
				'path'                => $path,
				'size_bytes'          => $size_bytes,
				'created_at'          => $created_at,
				'verified'            => $verified,
				'verification_method' => 'gzip_header',
			),
		);
	}

	// -------------------------------------------------------------------------
	// Internal helpers.
	// -------------------------------------------------------------------------

	/**
	 * Calculate disk usage for WordPress content directories.
	 *
	 * @since 1.1.0
	 *
	 * @return array { uploads_bytes: int, plugins_bytes: int, themes_bytes: int, total_bytes: int, total_mb: float }
	 */
	private function calculate_disk_usage(): array {
		$uploads_dir = WP_CONTENT_DIR . '/uploads';
		$plugins_dir = WP_CONTENT_DIR . '/plugins';
		$themes_dir  = WP_CONTENT_DIR . '/themes';

		$uploads_bytes = function_exists( 'get_dirsize' ) ? (int) get_dirsize( $uploads_dir ) : 0;
		$plugins_bytes = function_exists( 'get_dirsize' ) ? (int) get_dirsize( $plugins_dir ) : 0;
		$themes_bytes  = function_exists( 'get_dirsize' ) ? (int) get_dirsize( $themes_dir ) : 0;
		$total_bytes   = $uploads_bytes + $plugins_bytes + $themes_bytes;

		return array(
			'uploads_bytes' => $uploads_bytes,
			'plugins_bytes' => $plugins_bytes,
			'themes_bytes'  => $themes_bytes,
			'total_bytes'   => $total_bytes,
			'total_mb'      => round( $total_bytes / 1048576, 2 ),
		);
	}

	/**
	 * Calculate database size and statistics.
	 *
	 * @since 1.1.0
	 *
	 * @return array { total_bytes: int, total_mb: float, table_count: int, largest_table: array, revision_count: int, transient_count: int, autoload_bytes: int }
	 */
	private function calculate_database_size(): array {
		global $wpdb;

		$total_bytes   = 0;
		$table_count   = 0;
		$largest_name  = '';
		$largest_size  = 0;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$tables = $wpdb->get_results( 'SHOW TABLE STATUS', ARRAY_A );

		if ( is_array( $tables ) ) {
			$table_count = count( $tables );

			foreach ( $tables as $table ) {
				$data_length  = isset( $table['Data_length'] ) ? (int) $table['Data_length'] : 0;
				$index_length = isset( $table['Index_length'] ) ? (int) $table['Index_length'] : 0;
				$table_size   = $data_length + $index_length;
				$total_bytes += $table_size;

				if ( $table_size > $largest_size ) {
					$largest_size = $table_size;
					$largest_name = isset( $table['Name'] ) ? $table['Name'] : '';
				}
			}
		}

		// Count revisions.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$revision_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s",
				'revision'
			)
		);

		// Count transients.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$transient_count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_%'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		// Autoload size.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$autoload_bytes = (int) $wpdb->get_var(
			"SELECT SUM(LENGTH(option_value)) FROM {$wpdb->options} WHERE autoload = 'yes'" // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		);

		return array(
			'total_bytes'     => $total_bytes,
			'total_mb'        => round( $total_bytes / 1048576, 2 ),
			'table_count'     => $table_count,
			'largest_table'   => array(
				'name'  => $largest_name,
				'bytes' => $largest_size,
			),
			'revision_count'  => $revision_count,
			'transient_count' => $transient_count,
			'autoload_bytes'  => $autoload_bytes,
		);
	}

	/**
	 * Fetch SSL certificate information for the site.
	 *
	 * Connects to the site's own URL via stream_socket_client() with SSL
	 * context to retrieve and parse the certificate. Returns an error
	 * array on failure (e.g., localhost, no SSL, connection refused).
	 *
	 * @since 1.1.0
	 *
	 * @return array { valid: bool, issuer?: string, expires_at?: string, days_remaining?: int, chain_complete?: bool, error?: string }
	 */
	private function fetch_ssl_info(): array {
		$site_url = get_site_url();
		$parsed   = wp_parse_url( $site_url );

		if ( empty( $parsed['host'] ) || ( isset( $parsed['scheme'] ) && 'https' !== $parsed['scheme'] ) ) {
			return array(
				'valid' => false,
				'error' => __( 'Site is not using HTTPS.', 'claw-agent' ),
			);
		}

		$host = $parsed['host'];
		$port = isset( $parsed['port'] ) ? (int) $parsed['port'] : 443;

		try {
			$context = stream_context_create(
				array(
					'ssl' => array(
						'capture_peer_cert'       => true,
						'capture_peer_cert_chain' => true,
						'verify_peer'             => false, // We inspect, not enforce.
						'verify_peer_name'        => false,
					),
				)
			);

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_stream_socket_client
			$client = @stream_socket_client(
				'ssl://' . $host . ':' . $port,
				$errno,
				$errstr,
				10,
				STREAM_CLIENT_CONNECT,
				$context
			);

			if ( ! $client ) {
				return array(
					'valid' => false,
					'error' => sprintf(
						/* translators: 1: error number, 2: error message */
						__( 'SSL connection failed: [%1$d] %2$s', 'claw-agent' ),
						$errno,
						sanitize_text_field( $errstr )
					),
				);
			}

			$context_params = stream_context_get_params( $client );
			fclose( $client );

			if ( empty( $context_params['options']['ssl']['peer_certificate'] ) ) {
				return array(
					'valid' => false,
					'error' => __( 'No peer certificate returned.', 'claw-agent' ),
				);
			}

			$cert = openssl_x509_parse( $context_params['options']['ssl']['peer_certificate'] );

			if ( ! is_array( $cert ) ) {
				return array(
					'valid' => false,
					'error' => __( 'Failed to parse SSL certificate.', 'claw-agent' ),
				);
			}

			$expires_at     = isset( $cert['validTo_time_t'] ) ? (int) $cert['validTo_time_t'] : 0;
			$days_remaining = $expires_at > 0 ? (int) floor( ( $expires_at - time() ) / DAY_IN_SECONDS ) : 0;

			$issuer = '';
			if ( isset( $cert['issuer']['O'] ) ) {
				$issuer = $cert['issuer']['O'];
			} elseif ( isset( $cert['issuer']['CN'] ) ) {
				$issuer = $cert['issuer']['CN'];
			}

			$chain_complete = ! empty( $context_params['options']['ssl']['peer_certificate_chain'] )
				&& count( $context_params['options']['ssl']['peer_certificate_chain'] ) > 1;

			return array(
				'valid'          => ( $days_remaining > 0 ),
				'issuer'         => sanitize_text_field( $issuer ),
				'expires_at'     => gmdate( 'Y-m-d H:i:s', $expires_at ),
				'days_remaining' => $days_remaining,
				'chain_complete' => $chain_complete,
			);
		} catch ( \Exception $e ) {
			return array(
				'valid' => false,
				'error' => sanitize_text_field( $e->getMessage() ),
			);
		}
	}
}
