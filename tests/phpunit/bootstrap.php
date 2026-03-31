<?php
/**
 * Minimal WordPress stubs for standalone testing.
 *
 * These allow us to require plugin files and test their structure
 * without a full WordPress installation.
 *
 * @package WPClaw\Tests
 */

define( 'ABSPATH', dirname( dirname( __DIR__ ) ) . '/' );
define( 'WP_PLUGIN_DIR', ABSPATH . 'wp-content/plugins' );
define( 'WP_CONTENT_DIR', ABSPATH . 'wp-content' );
define( 'WPINC', 'wp-includes' );
define( 'DAY_IN_SECONDS', 86400 );
define( 'HOUR_IN_SECONDS', 3600 );
define( 'WEEK_IN_SECONDS', 604800 );
define( 'COOKIEPATH', '/' );
define( 'COOKIE_DOMAIN', '' );
define( 'WP_DEBUG_LOG', false );

// Stub WordPress functions that get called at file load time.
// These return safe defaults — they are NOT functional.

if ( ! function_exists( 'plugin_dir_path' ) ) {
	function plugin_dir_path( $file ) {
		return dirname( $file ) . '/';
	}
}
if ( ! function_exists( 'plugin_dir_url' ) ) {
	function plugin_dir_url( $file ) {
		return 'http://example.com/wp-content/plugins/' . basename( dirname( $file ) ) . '/';
	}
}
if ( ! function_exists( 'plugin_basename' ) ) {
	function plugin_basename( $file ) {
		return basename( dirname( $file ) ) . '/' . basename( $file );
	}
}
if ( ! function_exists( 'register_activation_hook' ) ) {
	function register_activation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'register_deactivation_hook' ) ) {
	function register_deactivation_hook( $file, $callback ) {}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback, $priority = 10, $args = 1 ) {}
}
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $hook, $callback, $priority = 10, $args = 1 ) {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $text, $domain = 'default' ) {
		return $text;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $default;
	}
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		return true;
	}
}
if ( ! function_exists( 'add_option' ) ) {
	function add_option( $key, $value = '', $deprecated = '', $autoload = 'yes' ) {
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		return true;
	}
}
if ( ! function_exists( 'get_transient' ) ) {
	function get_transient( $key ) {
		return false;
	}
}
if ( ! function_exists( 'set_transient' ) ) {
	function set_transient( $key, $value, $expiration = 0 ) {
		return true;
	}
}
if ( ! function_exists( 'delete_transient' ) ) {
	function delete_transient( $key ) {
		return true;
	}
}
if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( $key ) );
	}
}
if ( ! function_exists( 'sanitize_textarea_field' ) ) {
	function sanitize_textarea_field( $str ) {
		return trim( strip_tags( (string) $str ) );
	}
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $email ) {
		return filter_var( $email, FILTER_SANITIZE_EMAIL );
	}
}
if ( ! function_exists( 'sanitize_user' ) ) {
	function sanitize_user( $username ) {
		return $username;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $val ) {
		return abs( (int) $val );
	}
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		return sprintf(
			'%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ),
			mt_rand( 0, 0xffff ),
			mt_rand( 0, 0x0fff ) | 0x4000,
			mt_rand( 0, 0x3fff ) | 0x8000,
			mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff ), mt_rand( 0, 0xffff )
		);
	}
}
if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}
if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_attr' ) ) {
	function esc_attr( $text ) {
		return htmlspecialchars( (string) $text, ENT_QUOTES, 'UTF-8' );
	}
}
if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $url ) {
		return filter_var( $url, FILTER_SANITIZE_URL );
	}
}
if ( ! function_exists( 'wp_kses_post' ) ) {
	function wp_kses_post( $data ) {
		return $data;
	}
}
if ( ! function_exists( 'is_email' ) ) {
	function is_email( $email ) {
		return filter_var( $email, FILTER_VALIDATE_EMAIL ) ? $email : false;
	}
}
if ( ! function_exists( 'current_user_can' ) ) {
	function current_user_can( $capability ) {
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 1;
	}
}
if ( ! function_exists( 'current_time' ) ) {
	function current_time( $type, $gmt = 0 ) {
		return 'mysql' === $type ? gmdate( 'Y-m-d H:i:s' ) : time();
	}
}
if ( ! function_exists( 'wp_next_scheduled' ) ) {
	function wp_next_scheduled( $hook ) {
		return false;
	}
}
if ( ! function_exists( 'wp_schedule_event' ) ) {
	function wp_schedule_event( $time, $recurrence, $hook ) {
		return true;
	}
}
if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
	function wp_clear_scheduled_hook( $hook ) {
		return 0;
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'test-salt-' . $scheme;
	}
}
if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth );
	}
}
if ( ! function_exists( 'wp_remote_request' ) ) {
	function wp_remote_request( $url, $args = array() ) {
		return new WP_Error( 'http_request_failed', 'Stubbed' );
	}
}
if ( ! function_exists( 'wp_remote_get' ) ) {
	function wp_remote_get( $url, $args = array() ) {
		return new WP_Error( 'http_request_failed', 'Stubbed' );
	}
}
if ( ! function_exists( 'wp_remote_retrieve_response_code' ) ) {
	function wp_remote_retrieve_response_code( $response ) {
		return 200;
	}
}
if ( ! function_exists( 'wp_remote_retrieve_body' ) ) {
	function wp_remote_retrieve_body( $response ) {
		return '';
	}
}
if ( ! function_exists( 'wp_remote_retrieve_header' ) ) {
	function wp_remote_retrieve_header( $response, $header ) {
		return '';
	}
}
if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $args, $url = '' ) {
		return $url . '?' . http_build_query( $args );
	}
}
if ( ! function_exists( 'rest_ensure_response' ) ) {
	function rest_ensure_response( $data ) {
		return $data;
	}
}
if ( ! function_exists( 'get_bloginfo' ) ) {
	function get_bloginfo( $show ) {
		return 'version' === $show ? '6.8' : '';
	}
}
if ( ! function_exists( 'get_site_url' ) ) {
	function get_site_url() {
		return 'https://example.com';
	}
}
if ( ! function_exists( 'get_site_transient' ) ) {
	function get_site_transient( $key ) {
		return false;
	}
}
if ( ! function_exists( 'is_ssl' ) ) {
	function is_ssl() {
		return true;
	}
}
if ( ! function_exists( 'get_post_types' ) ) {
	function get_post_types( $args = array(), $output = 'names' ) {
		return array( 'post', 'page' );
	}
}
if ( ! function_exists( 'wp_count_posts' ) ) {
	function wp_count_posts( $type = 'post' ) {
		return (object) array( 'publish' => 10, 'draft' => 2 );
	}
}
if ( ! function_exists( 'get_stylesheet' ) ) {
	function get_stylesheet() {
		return 'twentytwentyfour';
	}
}
if ( ! function_exists( 'get_theme_root' ) ) {
	function get_theme_root() {
		return ABSPATH . 'wp-content/themes';
	}
}
if ( ! function_exists( 'flush_rewrite_rules' ) ) {
	function flush_rewrite_rules() {}
}
if ( ! function_exists( 'is_admin' ) ) {
	function is_admin() {
		return false;
	}
}
if ( ! function_exists( 'is_singular' ) ) {
	function is_singular() {
		return false;
	}
}
if ( ! function_exists( 'get_the_ID' ) ) {
	function get_the_ID() {
		return 0;
	}
}
if ( ! function_exists( 'untrailingslashit' ) ) {
	function untrailingslashit( $string ) {
		return rtrim( $string, '/\\' );
	}
}
if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $string ) {
		return rtrim( $string, '/\\' ) . '/';
	}
}
if ( ! function_exists( 'wp_timezone' ) ) {
	function wp_timezone() {
		return new DateTimeZone( 'UTC' );
	}
}
if ( ! function_exists( 'wp_die' ) ) {
	function wp_die( $message = '', $title = '', $args = array() ) {}
}
if ( ! function_exists( 'wp_upload_dir' ) ) {
	function wp_upload_dir() {
		return array(
			'basedir' => '/tmp/wp-uploads',
			'baseurl' => 'http://example.com/wp-content/uploads',
			'path'    => '/tmp/wp-uploads',
			'url'     => 'http://example.com/wp-content/uploads',
		);
	}
}
if ( ! function_exists( 'wp_unschedule_event' ) ) {
	function wp_unschedule_event( $timestamp, $hook ) {
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $id ) {
		return null;
	}
}
if ( ! function_exists( 'get_plugins' ) ) {
	function get_plugins() {
		return array();
	}
}
if ( ! function_exists( 'get_mu_plugins' ) ) {
	function get_mu_plugins() {
		return array();
	}
}
if ( ! function_exists( 'get_plugin_updates' ) ) {
	function get_plugin_updates() {
		return array();
	}
}

// WP_Error stub.
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;

		public function __construct( $code = '', $message = '', $data = '' ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}

		public function get_error_code() {
			return $this->code;
		}

		public function get_error_message() {
			return $this->message;
		}

		public function get_error_data() {
			return $this->data;
		}
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return $thing instanceof WP_Error;
	}
}

// WP_REST_Request stub.
if ( ! class_exists( 'WP_REST_Request' ) ) {
	class WP_REST_Request {
		private $params = array();

		public function get_json_params() {
			return $this->params;
		}

		public function get_param( $key ) {
			return $this->params[ $key ] ?? null;
		}
	}
}

// WP_Filesystem_Base stub.
if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
	class WP_Filesystem_Base {}
}

// Define plugin constants (same as wp-claw.php).
define( 'WP_CLAW_VERSION', '1.1.0' );
define( 'WP_CLAW_DB_VERSION', '1.1.0' );
define( 'WP_CLAW_PLUGIN_FILE', dirname( dirname( __DIR__ ) ) . '/wp-claw.php' );
define( 'WP_CLAW_PLUGIN_DIR', dirname( dirname( __DIR__ ) ) . '/' );
define( 'WP_CLAW_PLUGIN_URL', 'http://example.com/wp-content/plugins/wp-claw/' );
define( 'WP_CLAW_PLUGIN_BASENAME', 'wp-claw/wp-claw.php' );

// Autoload composer dependencies.
require_once dirname( dirname( __DIR__ ) ) . '/vendor/autoload.php';

// Load helper files that modules depend on.
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/logger.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/encryption.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/sanitization.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/capabilities.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/malware-patterns.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/helpers/file-scanner.php';

// Load core classes.
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-api-client.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-module-base.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-activator.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/class-deactivator.php';

// Load all module classes.
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-seo.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-security.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-content.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-crm.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-commerce.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-performance.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-forms.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-analytics.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-backup.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-social.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-chat.php';
require_once WP_CLAW_PLUGIN_DIR . 'includes/modules/class-module-audit.php';
