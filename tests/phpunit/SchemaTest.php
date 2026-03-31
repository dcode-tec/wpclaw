<?php
/**
 * Tests that the activator creates exactly the expected database tables.
 *
 * Parses class-activator.php as a string to verify SQL schema correctness.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;

class SchemaTest extends TestCase {

	/**
	 * Raw content of class-activator.php.
	 *
	 * @var string
	 */
	private static $activator_source;

	public static function set_up_before_class(): void {
		self::$activator_source = file_get_contents( WP_CLAW_PLUGIN_DIR . 'includes/class-activator.php' );
	}

	/**
	 * Verify exactly 10 CREATE TABLE statements exist.
	 */
	public function test_exactly_ten_create_tables(): void {
		$count = preg_match_all( '/CREATE\s+TABLE\b/i', self::$activator_source );
		$this->assertSame( 10, $count, 'Activator should contain exactly 10 CREATE TABLE statements' );
	}

	/**
	 * Data provider for expected table names.
	 *
	 * @return array
	 */
	public function table_name_provider(): array {
		return array(
			'tasks'           => array( 'wp_claw_tasks' ),
			'proposals'       => array( 'wp_claw_proposals' ),
			'analytics'       => array( 'wp_claw_analytics' ),
			'command_log'     => array( 'wp_claw_command_log' ),
			'file_hashes'     => array( 'wp_claw_file_hashes' ),
			'ab_tests'        => array( 'wp_claw_ab_tests' ),
			'abandoned_carts' => array( 'wp_claw_abandoned_carts' ),
			'email_drafts'    => array( 'wp_claw_email_drafts' ),
			'cwv_history'     => array( 'wp_claw_cwv_history' ),
			'snapshots'       => array( 'wp_claw_snapshots' ),
		);
	}

	/**
	 * @dataProvider table_name_provider
	 */
	public function test_table_name_present( string $table_name ): void {
		$this->assertStringContainsString(
			$table_name,
			self::$activator_source,
			"Table '$table_name' should be present in activator SQL"
		);
	}

	/**
	 * Verify each CREATE TABLE has a PRIMARY KEY.
	 */
	public function test_all_tables_have_primary_key(): void {
		// Split the source by CREATE TABLE and check each block.
		$parts = preg_split( '/CREATE\s+TABLE\b/i', self::$activator_source );
		// First element is before the first CREATE TABLE — skip it.
		array_shift( $parts );

		$this->assertCount( 10, $parts, 'Should have 10 table definition blocks' );

		foreach ( $parts as $i => $block ) {
			$this->assertStringContainsString(
				'PRIMARY KEY',
				$block,
				"Table block #" . ( $i + 1 ) . " should contain PRIMARY KEY"
			);
		}
	}

	/**
	 * Spot-check critical columns in key tables.
	 */
	public function test_tasks_table_has_critical_columns(): void {
		$this->assertStringContainsString( 'task_id', self::$activator_source );
		$this->assertStringContainsString( 'agent', self::$activator_source );
		$this->assertStringContainsString( 'module', self::$activator_source );
		$this->assertStringContainsString( 'status', self::$activator_source );
	}

	public function test_file_hashes_table_has_critical_columns(): void {
		$this->assertStringContainsString( 'file_path', self::$activator_source );
		$this->assertStringContainsString( 'file_hash', self::$activator_source );
		$this->assertStringContainsString( 'scope', self::$activator_source );
	}

	public function test_ab_tests_table_has_critical_columns(): void {
		$this->assertStringContainsString( 'variant_a_title', self::$activator_source );
		$this->assertStringContainsString( 'variant_b_title', self::$activator_source );
		$this->assertStringContainsString( 'impressions_a', self::$activator_source );
		$this->assertStringContainsString( 'clicks_a', self::$activator_source );
		$this->assertStringContainsString( 'winner', self::$activator_source );
	}

	public function test_abandoned_carts_table_has_critical_columns(): void {
		$this->assertStringContainsString( 'session_id', self::$activator_source );
		$this->assertStringContainsString( 'cart_contents', self::$activator_source );
		$this->assertStringContainsString( 'cart_total', self::$activator_source );
		$this->assertStringContainsString( 'email_step', self::$activator_source );
		$this->assertStringContainsString( 'recovered_at', self::$activator_source );
	}

	public function test_cwv_history_table_has_critical_columns(): void {
		$this->assertStringContainsString( 'lcp_ms', self::$activator_source );
		$this->assertStringContainsString( 'inp_ms', self::$activator_source );
		$this->assertStringContainsString( 'cls_score', self::$activator_source );
		$this->assertStringContainsString( 'ttfb_ms', self::$activator_source );
	}

	public function test_snapshots_table_has_critical_columns(): void {
		$this->assertStringContainsString( 'snapshot_id', self::$activator_source );
		$this->assertStringContainsString( 'snapshot_type', self::$activator_source );
		$this->assertStringContainsString( 'tables_count', self::$activator_source );
		$this->assertStringContainsString( 'expires_at', self::$activator_source );
	}

	public function test_email_drafts_table_has_critical_columns(): void {
		$this->assertStringContainsString( 'recipient_email', self::$activator_source );
		$this->assertStringContainsString( 'subject', self::$activator_source );
		$this->assertStringContainsString( 'body', self::$activator_source );
		$this->assertStringContainsString( 'language', self::$activator_source );
	}
}
