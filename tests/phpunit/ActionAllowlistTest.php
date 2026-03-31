<?php
/**
 * Tests that each module exposes exactly the expected actions.
 *
 * @package WPClaw\Tests
 */

use Yoast\PHPUnitPolyfills\TestCases\TestCase;
use WPClaw\API_Client;

class ActionAllowlistTest extends TestCase {

	/**
	 * Create a module instance by class name.
	 *
	 * @param string $class_name Fully qualified class name.
	 *
	 * @return \WPClaw\Module_Base
	 */
	private function make_module( string $class_name ) {
		$api_client = new API_Client();
		return new $class_name( $api_client );
	}

	/**
	 * Helper: assert exact action list matches.
	 *
	 * @param string   $class_name       Module class.
	 * @param string[] $expected_actions  Expected actions.
	 */
	private function assert_actions( string $class_name, array $expected_actions ): void {
		$module  = $this->make_module( $class_name );
		$actual  = $module->get_allowed_actions();

		sort( $expected_actions );
		sort( $actual );

		$this->assertSame(
			$expected_actions,
			$actual,
			sprintf( '%s actions mismatch', $class_name )
		);
	}

	public function test_seo_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_SEO',
			array(
				'update_post_meta_title',
				'update_post_meta_description',
				'update_schema_markup',
				'generate_sitemap',
				'analyze_content',
				'suggest_internal_links',
				'update_robots_txt',
				'check_cannibalization',
				'detect_stale_content',
				'find_broken_links',
				'get_striking_distance',
				'create_ab_test',
				'get_ab_test_results',
				'end_ab_test',
			)
		);
	}

	public function test_security_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Security',
			array(
				'block_ip',
				'update_security_headers',
				'log_security_event',
				'run_file_integrity_check',
				'enable_brute_force_protection',
				'update_htaccess_rules',
				'get_login_attempts',
				'compute_file_hashes',
				'compare_file_hashes',
				'scan_malware_patterns',
				'quarantine_file',
				'deploy_security_headers',
				'check_ssl_certificate',
				'get_quarantined_files',
			)
		);
	}

	public function test_content_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Content',
			array(
				'create_draft_post',
				'update_post_content',
				'create_page',
				'translate_post',
				'generate_excerpt',
				'check_content_freshness',
				'update_stale_dates',
				'expand_thin_content',
			)
		);
	}

	public function test_crm_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_CRM',
			array(
				'capture_lead',
				'update_lead_status',
				'score_lead',
				'create_followup_task',
				'get_leads',
				'draft_followup_email',
				'get_followup_drafts',
				'approve_followup_draft',
				'reject_followup_draft',
				'get_pipeline_health',
			)
		);
	}

	public function test_commerce_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Commerce',
			array(
				'update_stock_alert',
				'update_product_price',
				'create_coupon',
				'get_orders',
				'get_products',
				'send_abandoned_cart_reminder',
				'update_product_description',
				'track_cart_state',
				'get_abandoned_carts',
				'mark_cart_recovered',
				'update_cart_email_step',
				'detect_fraud_signals',
				'get_daily_order_summary',
				'get_customer_segments',
				'set_product_stock_threshold',
			)
		);
	}

	public function test_performance_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Performance',
			array(
				'get_core_web_vitals',
				'run_db_cleanup',
				'optimize_images',
				'suggest_cache_strategy',
				'get_page_speed_data',
				'optimize_tables',
				'get_autoload_analysis',
				'store_pagespeed_data',
			)
		);
	}

	public function test_forms_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Forms',
			array(
				'create_form',
				'get_submissions',
				'update_form',
				'delete_submission',
			)
		);
	}

	public function test_analytics_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Analytics',
			array(
				'get_pageviews',
				'get_top_pages',
				'get_referrers',
				'get_device_breakdown',
				'generate_report',
				'detect_anomalies',
				'get_funnel_data',
				'get_top_content',
				'get_content_trends',
				'store_cwv_data',
				'get_cwv_trends',
			)
		);
	}

	public function test_backup_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Backup',
			array(
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
			)
		);
	}

	public function test_social_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Social',
			array(
				'create_social_post',
				'schedule_post',
				'get_scheduled_posts',
				'format_for_platform',
				'get_posting_history',
			)
		);
	}

	public function test_chat_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Chat',
			array(
				'get_product_catalog',
				'get_order_status',
				'search_knowledge_base',
				'capture_chat_lead',
				'escalate_to_human',
				'get_conversation_topics',
				'update_faq_entries',
				'get_escalation_queue',
				'set_escalation_sla',
			)
		);
	}

	public function test_audit_actions(): void {
		$this->assert_actions(
			'WPClaw\Modules\Module_Audit',
			array(
				'run_site_audit',
				'get_plugin_versions',
				'get_plugin_updates',
				'get_disk_usage',
				'get_database_size',
				'get_ssl_info',
				'get_weekly_report',
				'check_backup_integrity',
			)
		);
	}
}
