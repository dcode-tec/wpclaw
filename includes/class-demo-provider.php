<?php
/**
 * Demo data provider for WP Playground / demo mode.
 *
 * When `wp_claw_demo_mode` is enabled (set by blueprint.json setSiteOptions),
 * REST endpoints return mock data from this class instead of reaching out to
 * a live Klawty instance. This allows full UI exploration in WP Playground
 * without provisioning a real managed instance.
 *
 * All methods are static — no instantiation needed.
 *
 * @package    WPClaw
 * @subpackage WPClaw/includes
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.4.0
 */

namespace WPClaw;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper that supplies mock data when demo mode is active.
 *
 * @since 1.4.0
 */
class Demo_Provider {

	// -------------------------------------------------------------------------
	// Guard
	// -------------------------------------------------------------------------

	/**
	 * Return true when the site is running in demo mode.
	 *
	 * Checks the `wp_claw_demo_mode` option set by the WP Playground blueprint.
	 *
	 * @since 1.4.0
	 *
	 * @return bool
	 */
	public static function is_active(): bool {
		return (bool) get_option( 'wp_claw_demo_mode', false );
	}

	// -------------------------------------------------------------------------
	// Mock data factories
	// -------------------------------------------------------------------------

	/**
	 * Return mock agent team status data (6 agents).
	 *
	 * Agent slugs match the Klawty orchestrator identifiers used throughout
	 * the plugin (architect, scribe, sentinel, commerce, analyst, concierge).
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, mixed>
	 */
	public static function agents(): array {
		return array(
			'agents' => array(
				array(
					'id'               => 'architect',
					'name'             => 'Karim',
					'role'             => 'The Architect',
					'status'           => 'idle',
					'tasks_completed'  => 42,
					'uptime'           => '99.8%',
					'last_active'      => gmdate( 'c', strtotime( '-5 minutes' ) ),
					'current_task'     => null,
				),
				array(
					'id'               => 'scribe',
					'name'             => 'Lina',
					'role'             => 'The Scribe',
					'status'           => 'working',
					'tasks_completed'  => 87,
					'uptime'           => '99.6%',
					'last_active'      => gmdate( 'c', strtotime( '-1 minute' ) ),
					'current_task'     => 'Optimising meta descriptions for 12 product pages',
				),
				array(
					'id'               => 'sentinel',
					'name'             => 'Bastien',
					'role'             => 'The Sentinel',
					'status'           => 'idle',
					'tasks_completed'  => 31,
					'uptime'           => '100%',
					'last_active'      => gmdate( 'c', strtotime( '-2 hours' ) ),
					'current_task'     => null,
				),
				array(
					'id'               => 'commerce',
					'name'             => 'Hugo',
					'role'             => 'Commerce Lead',
					'status'           => 'working',
					'tasks_completed'  => 56,
					'uptime'           => '99.4%',
					'last_active'      => gmdate( 'c', strtotime( '-3 minutes' ) ),
					'current_task'     => 'Drafting abandoned-cart recovery email for 8 customers',
				),
				array(
					'id'               => 'analyst',
					'name'             => 'Selma',
					'role'             => 'The Analyst',
					'status'           => 'idle',
					'tasks_completed'  => 19,
					'uptime'           => '99.1%',
					'last_active'      => gmdate( 'c', strtotime( '-30 minutes' ) ),
					'current_task'     => null,
				),
				array(
					'id'               => 'concierge',
					'name'             => 'Marc',
					'role'             => 'The Concierge',
					'status'           => 'idle',
					'tasks_completed'  => 104,
					'uptime'           => '98.9%',
					'last_active'      => gmdate( 'c', strtotime( '-10 minutes' ) ),
					'current_task'     => null,
				),
			),
			'total'     => 6,
			'active'    => 2,
			'demo_mode' => true,
		);
	}

	/**
	 * Return 3 mock pending proposals.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, mixed>
	 */
	public static function proposals(): array {
		return array(
			'proposals' => array(
				array(
					'id'         => 'demo-prop-001',
					'agent'      => 'scribe',
					'title'      => 'Add schema markup to 5 product pages',
					'action'     => 'seo_schema_update',
					'status'     => 'pending_approval',
					'created_at' => gmdate( 'c', strtotime( '-15 minutes' ) ),
					'expires_at' => gmdate( 'c', strtotime( '+45 minutes' ) ),
					'summary'    => 'Lina has prepared JSON-LD Product schema for 5 pages currently missing structured data. This will improve Google Shopping visibility.',
				),
				array(
					'id'         => 'demo-prop-002',
					'agent'      => 'sentinel',
					'title'      => 'Block 3 suspicious IPs from brute-force attempts',
					'action'     => 'security_block_ips',
					'status'     => 'pending_approval',
					'created_at' => gmdate( 'c', strtotime( '-8 minutes' ) ),
					'expires_at' => gmdate( 'c', strtotime( '+52 minutes' ) ),
					'summary'    => 'Bastien detected repeated failed login attempts from 3 IP addresses. Blocking them will prevent further brute-force attacks.',
				),
				array(
					'id'         => 'demo-prop-003',
					'agent'      => 'scribe',
					'title'      => 'Publish draft: "Top 10 Tips for Online Shoppers"',
					'action'     => 'content_publish_draft',
					'status'     => 'pending_approval',
					'created_at' => gmdate( 'c', strtotime( '-3 minutes' ) ),
					'expires_at' => gmdate( 'c', strtotime( '+57 minutes' ) ),
					'summary'    => 'Lina has written a 1,200-word blog post targeting the keyword "tips for online shoppers" (1,200 monthly searches). Ready to publish.',
				),
			),
			'total'     => 3,
			'demo_mode' => true,
		);
	}

	/**
	 * Return mock system health status.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, mixed>
	 */
	public static function health(): array {
		return array(
			'status'        => 'healthy',
			'agents_active' => 6,
			'uptime'        => '99.4%',
			'last_check'    => gmdate( 'c' ),
			'version'       => defined( 'WP_CLAW_VERSION' ) ? WP_CLAW_VERSION : '1.4.0',
			'demo_mode'     => true,
			'services'      => array(
				array( 'name' => 'Agent Orchestrator', 'status' => 'healthy', 'latency_ms' => 42 ),
				array( 'name' => 'Task Queue',          'status' => 'healthy', 'latency_ms' => 8 ),
				array( 'name' => 'Memory Service',      'status' => 'healthy', 'latency_ms' => 15 ),
				array( 'name' => 'Circuit Breaker',     'status' => 'healthy', 'latency_ms' => 3 ),
			),
		);
	}

	/**
	 * Return 3 mock agent task chain steps for the chains list endpoint.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, mixed>
	 */
	public static function chains(): array {
		return array(
			'chains' => array(
				array(
					'id'          => 'demo-chain-001',
					'name'        => 'Full SEO Audit',
					'agent'       => 'scribe',
					'status'      => 'running',
					'created_at'  => gmdate( 'c', strtotime( '-20 minutes' ) ),
					'steps'       => array(
						array(
							'step'       => 1,
							'label'      => 'Crawl site for missing meta tags',
							'status'     => 'done',
							'finished_at' => gmdate( 'c', strtotime( '-15 minutes' ) ),
						),
						array(
							'step'   => 2,
							'label'  => 'Generate optimised meta descriptions',
							'status' => 'working',
						),
						array(
							'step'   => 3,
							'label'  => 'Submit schema proposals for approval',
							'status' => 'queued',
						),
					),
				),
			),
			'total'     => 1,
			'demo_mode' => true,
		);
	}

	/**
	 * Return a mock performance diagnostic report.
	 *
	 * Format mirrors the Slice 2 performance report structure so the dashboard
	 * can render it using the same template.
	 *
	 * @since 1.4.0
	 *
	 * @return array<string, mixed>
	 */
	public static function performance_report(): array {
		return array(
			'score'       => 78,
			'grade'       => 'C+',
			'generated_at' => gmdate( 'c' ),
			'demo_mode'   => true,
			'checks'      => array(
				array(
					'name'    => 'Page Speed (LCP)',
					'status'  => 'warning',
					'value'   => '3.2s',
					'target'  => '< 2.5s',
					'impact'  => 'high',
					'message' => 'Largest Contentful Paint is above the Good threshold. Optimise hero image.',
				),
				array(
					'name'    => 'Autoload Options Size',
					'status'  => 'ok',
					'value'   => '312 KB',
					'target'  => '< 800 KB',
					'impact'  => 'medium',
					'message' => 'Autoloaded options are within acceptable limits.',
				),
				array(
					'name'    => 'Database Table Overhead',
					'status'  => 'warning',
					'value'   => '18 MB overhead',
					'target'  => '< 5 MB',
					'impact'  => 'medium',
					'message' => 'Several tables have significant overhead. Running OPTIMIZE TABLE is recommended.',
				),
				array(
					'name'    => 'Object Cache',
					'status'  => 'ok',
					'value'   => 'Active (Redis)',
					'target'  => 'Active',
					'impact'  => 'high',
					'message' => 'Object cache is active and functioning correctly.',
				),
				array(
					'name'    => 'TTFB',
					'status'  => 'ok',
					'value'   => '210ms',
					'target'  => '< 800ms',
					'impact'  => 'high',
					'message' => 'Time to First Byte is excellent.',
				),
				array(
					'name'    => 'Cumulative Layout Shift (CLS)',
					'status'  => 'error',
					'value'   => '0.28',
					'target'  => '< 0.1',
					'impact'  => 'medium',
					'message' => 'High layout shift detected. Add explicit width/height to images and embeds.',
				),
			),
			'recommendations' => array(
				'Optimise the homepage hero image (convert to WebP, add width/height attributes) to reduce LCP by an estimated 0.8 s.',
				'Run database table optimisation on wp_postmeta and wp_options to recover 18 MB of table overhead.',
			),
		);
	}
}
