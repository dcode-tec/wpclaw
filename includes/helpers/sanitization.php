<?php
/**
 * Input sanitization helper functions.
 *
 * Provides whitelisted, typed sanitization for API responses, task data,
 * proposal data, chat messages, and per-module settings. All functions
 * return clean, safe values and strip unexpected keys.
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
 * Sanitize a raw API response array from the Klawty instance.
 *
 * Whitelists keys: status, message, data, error, agent, task_id, proposal_id,
 * version, timestamp. Strings pass through sanitize_text_field(). Integer
 * fields pass through absint(). HTML-bearing fields use wp_kses_post().
 * Unexpected keys are stripped.
 *
 * @since 1.0.0
 *
 * @param array $response Raw decoded API response.
 *
 * @return array Sanitized response with only whitelisted keys.
 */
function wp_claw_sanitize_api_response( array $response ): array {
	// Keys and their expected sanitization strategy.
	$text_fields    = array( 'status', 'message', 'error', 'agent', 'task_id', 'proposal_id', 'version', 'timestamp' );
	$integer_fields = array( 'code', 'count', 'total' );
	$html_fields    = array( 'description', 'details_html' );

	$clean = array();

	foreach ( $text_fields as $key ) {
		if ( array_key_exists( $key, $response ) ) {
			$clean[ $key ] = sanitize_text_field( (string) $response[ $key ] );
		}
	}

	foreach ( $integer_fields as $key ) {
		if ( array_key_exists( $key, $response ) ) {
			$clean[ $key ] = absint( $response[ $key ] );
		}
	}

	foreach ( $html_fields as $key ) {
		if ( array_key_exists( $key, $response ) ) {
			$clean[ $key ] = wp_kses_post( (string) $response[ $key ] );
		}
	}

	// 'agents' is a nested array of agent status objects — pass through for
	// the caller to sanitize per-field (admin views escape on output).
	if ( array_key_exists( 'agents', $response ) && is_array( $response['agents'] ) ) {
		$clean['agents'] = $response['agents'];
	}

	// 'proposals' is a nested array — same passthrough pattern.
	if ( array_key_exists( 'proposals', $response ) && is_array( $response['proposals'] ) ) {
		$clean['proposals'] = $response['proposals'];
	}

	// 'states' is used by the module-states admin AJAX endpoint.
	if ( array_key_exists( 'states', $response ) && is_array( $response['states'] ) ) {
		$clean['states'] = $response['states'];
	}

	// 'data' may be a nested array — recurse one level for simple scalar children,
	// or preserve as-is for complex structures (callers must sanitize deeper).
	if ( array_key_exists( 'data', $response ) && is_array( $response['data'] ) ) {
		$clean['data'] = array_map(
			function ( $value ) {
				if ( is_string( $value ) ) {
					return sanitize_text_field( $value );
				}
				if ( is_int( $value ) || is_float( $value ) ) {
					return $value;
				}
				if ( is_bool( $value ) ) {
					return $value;
				}
				// Nested arrays/objects are passed through; caller sanitizes.
				return $value;
			},
			$response['data']
		);
	}

	return $clean;
}

/**
 * Sanitize a task data array before database insertion or API dispatch.
 *
 * Expected fields: task_id, agent, module, action, status, details.
 * String fields use sanitize_text_field(). Status is validated against
 * the allowed enum. Details must be a valid JSON string.
 *
 * @since 1.0.0
 *
 * @param array $task Raw task data.
 *
 * @return array Sanitized task data with only known fields.
 */
function wp_claw_sanitize_task_data( array $task ): array {
	$allowed_statuses = array( 'pending', 'in_progress', 'review', 'done', 'failed', 'cancelled' );

	$clean = array();

	$text_fields = array( 'task_id', 'agent', 'module', 'action' );
	foreach ( $text_fields as $key ) {
		if ( array_key_exists( $key, $task ) ) {
			$clean[ $key ] = sanitize_text_field( (string) $task[ $key ] );
		}
	}

	// Status: validate against enum; default to 'pending' on invalid input.
	if ( array_key_exists( 'status', $task ) ) {
		$raw_status      = sanitize_text_field( (string) $task['status'] );
		$clean['status'] = in_array( $raw_status, $allowed_statuses, true ) ? $raw_status : 'pending';
	}

	// Details: must be valid JSON (object or array); reject otherwise.
	if ( array_key_exists( 'details', $task ) ) {
		$raw_details = $task['details'];

		if ( is_array( $raw_details ) ) {
			// Already decoded — re-encode to ensure consistent storage.
			$clean['details'] = wp_json_encode( $raw_details );
		} elseif ( is_string( $raw_details ) ) {
			$decoded = json_decode( $raw_details, true );
			if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
				$clean['details'] = $raw_details;
			} else {
				wp_claw_log_warning( 'wp_claw_sanitize_task_data: invalid JSON in details field — discarded.' );
				$clean['details'] = '{}';
			}
		}
	}

	return $clean;
}

/**
 * Sanitize a proposal data array before database insertion or API dispatch.
 *
 * Expected fields: proposal_id, agent, action, tier, status, details.
 * String fields use sanitize_text_field(). Status and tier are validated
 * against allowed enums. Details must be valid JSON.
 *
 * @since 1.0.0
 *
 * @param array $proposal Raw proposal data.
 *
 * @return array Sanitized proposal data with only known fields.
 */
function wp_claw_sanitize_proposal_data( array $proposal ): array {
	$allowed_statuses = array( 'pending', 'sentinel_approved', 'awaiting_human', 'executing', 'completed', 'rejected', 'rolled_back', 'expired', 'cancelled' );
	$allowed_tiers    = array( 'auto', 'auto+', 'propose', 'confirm', 'block' );

	$clean = array();

	$text_fields = array( 'proposal_id', 'agent', 'action' );
	foreach ( $text_fields as $key ) {
		if ( array_key_exists( $key, $proposal ) ) {
			$clean[ $key ] = sanitize_text_field( (string) $proposal[ $key ] );
		}
	}

	if ( array_key_exists( 'status', $proposal ) ) {
		$raw_status      = sanitize_text_field( (string) $proposal['status'] );
		$clean['status'] = in_array( $raw_status, $allowed_statuses, true ) ? $raw_status : 'pending';
	}

	if ( array_key_exists( 'tier', $proposal ) ) {
		$raw_tier      = sanitize_text_field( (string) $proposal['tier'] );
		$clean['tier'] = in_array( $raw_tier, $allowed_tiers, true ) ? $raw_tier : 'propose';
	}

	if ( array_key_exists( 'details', $proposal ) ) {
		$raw_details = $proposal['details'];

		if ( is_array( $raw_details ) ) {
			$clean['details'] = wp_json_encode( $raw_details );
		} elseif ( is_string( $raw_details ) ) {
			$decoded = json_decode( $raw_details, true );
			if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
				$clean['details'] = $raw_details;
			} else {
				wp_claw_log_warning( 'wp_claw_sanitize_proposal_data: invalid JSON in details field — discarded.' );
				$clean['details'] = '{}';
			}
		}
	}

	return $clean;
}

/**
 * Sanitize a visitor chat message before forwarding to the Concierge agent.
 *
 * Strips all HTML tags, removes null bytes, limits to 2000 characters.
 * Uses sanitize_textarea_field() to preserve newlines while removing
 * dangerous characters.
 *
 * @since 1.0.0
 *
 * @param string $message Raw visitor message.
 *
 * @return string Sanitized message, max 2000 characters.
 */
function wp_claw_sanitize_chat_message( string $message ): string {
	// Remove null bytes first to prevent bypass attempts.
	$message = str_replace( "\0", '', $message );

	// Strip all HTML tags.
	$message = wp_strip_all_tags( $message );

	// WordPress textarea sanitization — removes invalid UTF-8, extra whitespace.
	$message = sanitize_textarea_field( $message );

	// Hard cap at 2000 characters.
	if ( mb_strlen( $message ) > 2000 ) {
		$message = mb_substr( $message, 0, 2000 );
	}

	return $message;
}

/**
 * Sanitize module settings based on a per-module schema.
 *
 * Each module defines its own expected settings keys and validation rules.
 * Unknown keys are stripped. The default path generically sanitizes all
 * string values with sanitize_text_field().
 *
 * @since 1.0.0
 *
 * @param string $module   Module slug: 'seo', 'security', 'commerce', etc.
 * @param array  $settings Raw settings array submitted by the admin.
 *
 * @return array Sanitized settings for the given module.
 */
function wp_claw_sanitize_module_settings( string $module, array $settings ): array {
	$module = sanitize_key( $module );
	$clean  = array();

	switch ( $module ) {
		case 'seo':
			// Focus keywords: comma-separated text.
			if ( isset( $settings['focus_keywords'] ) ) {
				$clean['focus_keywords'] = sanitize_text_field( (string) $settings['focus_keywords'] );
			}
			// Meta title/description length limits.
			foreach ( array( 'title_length_min', 'title_length_max', 'description_length_min', 'description_length_max' ) as $key ) {
				if ( isset( $settings[ $key ] ) ) {
					$clean[ $key ] = absint( $settings[ $key ] );
				}
			}
			// Boolean toggles.
			foreach ( array( 'auto_meta', 'auto_schema', 'auto_sitemap', 'auto_internal_linking' ) as $key ) {
				if ( array_key_exists( $key, $settings ) ) {
					$clean[ $key ] = (bool) $settings[ $key ];
				}
			}
			// Sitemap ping URL.
			if ( isset( $settings['sitemap_ping_url'] ) ) {
				$clean['sitemap_ping_url'] = esc_url_raw( (string) $settings['sitemap_ping_url'] );
			}
			break;

		case 'security':
			// IP/CIDR whitelist and blacklist — validate each entry.
			foreach ( array( 'ip_whitelist', 'ip_blacklist' ) as $list_key ) {
				if ( isset( $settings[ $list_key ] ) && is_array( $settings[ $list_key ] ) ) {
					$clean[ $list_key ] = array_values(
						array_filter(
							array_map(
								function ( $entry ) {
									$entry = sanitize_text_field( (string) $entry );
									// Accept IPv4, IPv4 CIDR, IPv6, IPv6 CIDR.
									if ( preg_match( '/^[\da-fA-F.:\/]+$/', $entry ) ) {
										return $entry;
									}
									return null;
								},
								$settings[ $list_key ]
							)
						)
					);
				}
			}
			// Max login attempts before lockout.
			if ( isset( $settings['max_login_attempts'] ) ) {
				$val                         = absint( $settings['max_login_attempts'] );
				$clean['max_login_attempts'] = max( 1, min( 100, $val ) );
			}
			// Lockout duration in minutes.
			if ( isset( $settings['lockout_duration_minutes'] ) ) {
				$val                               = absint( $settings['lockout_duration_minutes'] );
				$clean['lockout_duration_minutes'] = max( 1, min( 10080, $val ) ); // Max 7 days.
			}
			// Boolean toggles.
			foreach ( array( 'enable_2fa', 'enable_file_integrity', 'enable_malware_scan', 'enable_security_headers' ) as $key ) {
				if ( array_key_exists( $key, $settings ) ) {
					$clean[ $key ] = (bool) $settings[ $key ];
				}
			}
			// Notification email.
			if ( isset( $settings['alert_email'] ) ) {
				$email = sanitize_email( (string) $settings['alert_email'] );
				if ( is_email( $email ) ) {
					$clean['alert_email'] = $email;
				}
			}
			break;

		case 'commerce':
			// Low stock threshold.
			if ( isset( $settings['low_stock_threshold'] ) ) {
				$clean['low_stock_threshold'] = absint( $settings['low_stock_threshold'] );
			}
			// Abandoned cart timeout in hours.
			if ( isset( $settings['abandoned_cart_hours'] ) ) {
				$val                           = absint( $settings['abandoned_cart_hours'] );
				$clean['abandoned_cart_hours'] = max( 1, min( 168, $val ) ); // 1h–7 days.
			}
			// Notification emails — comma-separated list.
			if ( isset( $settings['notification_emails'] ) ) {
				$raw_emails                   = explode( ',', (string) $settings['notification_emails'] );
				$valid_emails                 = array_filter(
					array_map(
						function ( $email ) {
							$email = sanitize_email( trim( $email ) );
							return is_email( $email ) ? $email : null;
						},
						$raw_emails
					)
				);
				$clean['notification_emails'] = implode( ',', $valid_emails );
			}
			// Dynamic pricing bounds.
			foreach ( array( 'price_floor_percent', 'price_ceiling_percent' ) as $key ) {
				if ( isset( $settings[ $key ] ) ) {
					$val           = (float) $settings[ $key ];
					$clean[ $key ] = max( 0.0, min( 500.0, $val ) );
				}
			}
			// Boolean toggles.
			foreach ( array( 'enable_abandoned_cart', 'enable_dynamic_pricing', 'enable_stock_alerts' ) as $key ) {
				if ( array_key_exists( $key, $settings ) ) {
					$clean[ $key ] = (bool) $settings[ $key ];
				}
			}
			break;

		default:
			// Generic fallback: sanitize all string values, preserve numbers and booleans.
			foreach ( $settings as $key => $value ) {
				$key = sanitize_key( (string) $key );
				if ( empty( $key ) ) {
					continue;
				}
				if ( is_string( $value ) ) {
					$clean[ $key ] = sanitize_text_field( $value );
				} elseif ( is_int( $value ) ) {
					$clean[ $key ] = (int) $value;
				} elseif ( is_float( $value ) ) {
					$clean[ $key ] = (float) $value;
				} elseif ( is_bool( $value ) ) {
					$clean[ $key ] = (bool) $value;
				}
				// Arrays and objects at root level are silently dropped in generic mode.
			}
			break;
	}

	return $clean;
}
