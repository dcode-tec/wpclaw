<?php
/**
 * Tests frontend JS/CSS files contain expected features.
 *
 * Reads files as strings and verifies key patterns are present.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class FrontendTest extends TestCase {

	/**
	 * @var string
	 */
	private static $chat_js;

	/**
	 * @var string
	 */
	private static $chat_css;

	/**
	 * @var string
	 */
	private static $public_js;

	public static function set_up_before_class(): void {
		self::$chat_js   = file_get_contents( WP_CLAW_PLUGIN_DIR . 'public/js/wp-claw-chat.js' );
		self::$chat_css  = file_get_contents( WP_CLAW_PLUGIN_DIR . 'public/css/wp-claw-chat.css' );
		self::$public_js = file_get_contents( WP_CLAW_PLUGIN_DIR . 'public/js/wp-claw-public.js' );
	}

	// -------------------------------------------------------------------------
	// Chat JS — GDPR consent
	// -------------------------------------------------------------------------

	public function test_chat_js_has_gdpr_consent(): void {
		$this->assertStringContainsString(
			'wp-claw-chat-consent',
			self::$chat_js,
			'Chat JS should contain GDPR consent overlay class'
		);
	}

	// -------------------------------------------------------------------------
	// Chat JS — page context injection
	// -------------------------------------------------------------------------

	public function test_chat_js_has_page_context(): void {
		$this->assertStringContainsString(
			'page_context',
			self::$chat_js,
			'Chat JS should inject page_context with messages'
		);
	}

	// -------------------------------------------------------------------------
	// Chat JS — session message limit
	// -------------------------------------------------------------------------

	public function test_chat_js_has_message_count(): void {
		$this->assertStringContainsString(
			'messageCount',
			self::$chat_js,
			'Chat JS should track messageCount for session limits'
		);
	}

	// -------------------------------------------------------------------------
	// Chat CSS — consent styles
	// -------------------------------------------------------------------------

	public function test_chat_css_has_consent_styles(): void {
		$this->assertStringContainsString(
			'.wp-claw-chat-consent',
			self::$chat_css,
			'Chat CSS should contain .wp-claw-chat-consent styles'
		);
	}

	// -------------------------------------------------------------------------
	// Public JS — funnel tracking
	// -------------------------------------------------------------------------

	public function test_public_js_has_cart_view_tracking(): void {
		$this->assertStringContainsString(
			'cart_view',
			self::$public_js,
			'Public JS should track cart_view funnel events'
		);
	}

	public function test_public_js_has_checkout_view_tracking(): void {
		$this->assertStringContainsString(
			'checkout_view',
			self::$public_js,
			'Public JS should track checkout_view funnel events'
		);
	}

	public function test_public_js_has_purchase_tracking(): void {
		$this->assertStringContainsString(
			'purchase',
			self::$public_js,
			'Public JS should track purchase funnel events'
		);
	}
}
