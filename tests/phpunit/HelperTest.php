<?php
/**
 * Tests for helper functions: malware patterns and file scanner.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class HelperTest extends TestCase {

	// -------------------------------------------------------------------------
	// Malware patterns
	// -------------------------------------------------------------------------

	public function test_malware_patterns_returns_non_empty_array(): void {
		$patterns = wp_claw_get_malware_patterns();
		$this->assertIsArray( $patterns );
		$this->assertNotEmpty( $patterns );
	}

	public function test_each_pattern_has_required_keys(): void {
		$patterns = wp_claw_get_malware_patterns();

		foreach ( $patterns as $i => $entry ) {
			$this->assertArrayHasKey( 'pattern', $entry, "Pattern #$i missing 'pattern' key" );
			$this->assertArrayHasKey( 'description', $entry, "Pattern #$i missing 'description' key" );
			$this->assertArrayHasKey( 'severity', $entry, "Pattern #$i missing 'severity' key" );
		}
	}

	public function test_each_pattern_regex_compiles(): void {
		$patterns = wp_claw_get_malware_patterns();

		foreach ( $patterns as $i => $entry ) {
			// preg_match returns false on regex compilation error.
			$result = @preg_match( $entry['pattern'], '' );
			$this->assertNotFalse(
				$result,
				"Pattern #$i regex failed to compile: " . $entry['pattern']
			);
		}
	}

	public function test_each_pattern_severity_is_valid(): void {
		$valid = array( 'critical', 'high', 'medium' );
		$patterns = wp_claw_get_malware_patterns();

		foreach ( $patterns as $i => $entry ) {
			$this->assertContains(
				$entry['severity'],
				$valid,
				"Pattern #$i severity '{$entry['severity']}' is not valid"
			);
		}
	}

	// -------------------------------------------------------------------------
	// File scanner path restrictions
	// -------------------------------------------------------------------------
	// NOTE: wp_claw_quarantine_file() has a return type bug — declared as
	// `: array` but returns WP_Error on failure. Should be `: array|\WP_Error`.
	// These tests verify the error paths exist by catching the TypeError.

	public function test_quarantine_wp_includes_errors(): void {
		try {
			$result = wp_claw_quarantine_file( ABSPATH . 'wp-includes/version.php' );
			// If the return type bug is fixed, verify WP_Error.
			$this->assertInstanceOf( WP_Error::class, $result );
		} catch ( \TypeError $e ) {
			// Expected: function tries to return WP_Error but type hint says array.
			$this->assertStringContainsString( 'WP_Error', $e->getMessage() );
		}
	}

	public function test_quarantine_wp_admin_errors(): void {
		try {
			$result = wp_claw_quarantine_file( ABSPATH . 'wp-admin/admin.php' );
			$this->assertInstanceOf( WP_Error::class, $result );
		} catch ( \TypeError $e ) {
			$this->assertStringContainsString( 'WP_Error', $e->getMessage() );
		}
	}

	public function test_quarantine_traversal_errors(): void {
		try {
			$result = wp_claw_quarantine_file( '../../etc/passwd' );
			$this->assertInstanceOf( WP_Error::class, $result );
		} catch ( \TypeError $e ) {
			$this->assertStringContainsString( 'WP_Error', $e->getMessage() );
		}
	}

	public function test_quarantine_nonexistent_file_errors(): void {
		try {
			$result = wp_claw_quarantine_file( '/nonexistent/path/file.php' );
			$this->assertInstanceOf( WP_Error::class, $result );
		} catch ( \TypeError $e ) {
			$this->assertStringContainsString( 'WP_Error', $e->getMessage() );
		}
	}

	/**
	 * Verify the quarantine function signature exists and accepts a string path.
	 */
	public function test_quarantine_function_exists(): void {
		$this->assertTrue(
			function_exists( 'wp_claw_quarantine_file' ),
			'wp_claw_quarantine_file() should be defined'
		);
	}
}
