<?php
/**
 * Structured logging helper functions.
 *
 * Writes timestamped, levelled log entries to the WordPress debug log.
 * All writes are gated on WP_DEBUG_LOG to prevent production noise.
 *
 * @package    WPClaw
 * @subpackage WPClaw/helpers
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.0.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Log a message to the WordPress debug log.
 *
 * Entries are only written when WP_DEBUG_LOG is true.
 * Messages are truncated to 1000 characters. Context arrays are
 * JSON-encoded and appended. Sensitive data must never be passed
 * as message or context.
 *
 * Format: [2026-03-21T10:30:00+01:00] [WP-Claw] [INFO] Message here {"key":"value"}
 *
 * @since 1.0.0
 *
 * @param string $message The log message.
 * @param string $level   Log level: 'debug', 'info', 'warning', 'error'. Default 'info'.
 * @param array  $context Optional. Associative array of contextual data. Default [].
 *
 * @return void
 */
function wp_claw_log( string $message, string $level = 'info', array $context = array() ): void {
	if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
		return;
	}

	// Truncate long messages to prevent log file bloat.
	if ( strlen( $message ) > 1000 ) {
		$message = substr( $message, 0, 997 ) . '...';
	}

	$level = strtoupper( $level );

	// ISO 8601 timestamp with timezone offset.
	$timestamp = ( new DateTimeImmutable( 'now', wp_timezone() ) )->format( 'c' );

	$entry = sprintf( '[%s] [WP-Claw] [%s] %s', $timestamp, $level, $message );

	if ( ! empty( $context ) ) {
		$encoded = wp_json_encode( $context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
		if ( false !== $encoded ) {
			$entry .= ' ' . $encoded;
		}
	}

	// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- intentional debug logging.
	error_log( $entry );
}

/**
 * Log an error-level message.
 *
 * Shorthand for wp_claw_log( $message, 'error', $context ).
 *
 * @since 1.0.0
 *
 * @param string $message The error message.
 * @param array  $context Optional. Associative array of contextual data. Default [].
 *
 * @return void
 */
function wp_claw_log_error( string $message, array $context = array() ): void {
	wp_claw_log( $message, 'error', $context );
}

/**
 * Log a warning-level message.
 *
 * Shorthand for wp_claw_log( $message, 'warning', $context ).
 *
 * @since 1.0.0
 *
 * @param string $message The warning message.
 * @param array  $context Optional. Associative array of contextual data. Default [].
 *
 * @return void
 */
function wp_claw_log_warning( string $message, array $context = array() ): void {
	wp_claw_log( $message, 'warning', $context );
}

/**
 * Log a debug-level message.
 *
 * Only writes if both WP_DEBUG_LOG and WP_CLAW_DEBUG are true.
 * Use for verbose developer output that should not appear in production logs.
 *
 * @since 1.0.0
 *
 * @param string $message The debug message.
 * @param array  $context Optional. Associative array of contextual data. Default [].
 *
 * @return void
 */
function wp_claw_log_debug( string $message, array $context = array() ): void {
	if ( ! defined( 'WP_CLAW_DEBUG' ) || ! WP_CLAW_DEBUG ) {
		return;
	}

	wp_claw_log( $message, 'debug', $context );
}
