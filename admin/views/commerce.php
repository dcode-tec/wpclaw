<?php
/**
 * Commerce & CRM dashboard admin view — agent-first layout centred on Hugo's reports.
 *
 * @package    WPClaw
 * @subpackage WPClaw/admin/views
 * @author     dcode technologies <dev@d-code.lu>
 * @copyright  2026 dcode technologies S.a r.l.
 * @license    GPL-2.0-or-later
 * @since      1.2.0
 */

defined( 'ABSPATH' ) || exit;
// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedVariableFound -- template variables in included view file.

global $wpdb;

$plugin       = \WPClaw\WP_Claw::get_instance();
$commerce_mod = $plugin->get_module( 'commerce' );
$crm_mod      = $plugin->get_module( 'crm' );

// If both modules are missing, show enable notice and bail.
if ( null === $commerce_mod && null === $crm_mod ) {
	?>
	<div class="wpc-admin-wrap">
		<h1 class="wpc-section-heading"><?php esc_html_e( 'Commerce & CRM', 'claw-agent' ); ?></h1>
		<div class="wpc-alert-banner">
			<p>
				<?php
				printf(
					/* translators: %s: URL to modules settings page */
					esc_html__( 'The Commerce and CRM modules are not enabled. %s to activate them.', 'claw-agent' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wp-claw-modules' ) ) . '">' . esc_html__( 'Go to Modules', 'claw-agent' ) . '</a>'
				);
				?>
			</p>
		</div>
	</div>
	<?php
	return;
}

$com        = null !== $commerce_mod ? $commerce_mod->get_state() : array();
$woo_active = isset( $com['available'] ) && $com['available'];
$crm        = null !== $crm_mod ? $crm_mod->get_state() : array();
$crm_active = null !== $crm_mod;

/** @return string Formatted price (WooCommerce-aware). */
$wp_claw_format_price = function ( $value ) {
	if ( class_exists( 'WooCommerce' ) && function_exists( 'wc_price' ) ) {
		return wp_strip_all_tags( wc_price( (float) $value ) );
	}
	return number_format( (float) $value, 2 ) . ' EUR';
};

/** @return string "X ago" or "---". */
$wp_claw_time_ago = function ( $timestamp ) {
	if ( ! $timestamp ) { return '---'; }
	/* translators: %s: human-readable time difference */
	return sprintf( __( '%s ago', 'claw-agent' ), human_time_diff( $timestamp ) );
};

$daily_revenue    = isset( $com['daily_revenue'] ) ? (float) $com['daily_revenue'] : 0.0;
$daily_orders     = isset( $com['daily_orders'] ) ? (int) $com['daily_orders'] : 0;
$avg_order_value  = $daily_orders > 0 ? $daily_revenue / $daily_orders : 0.0;
$abandoned_count  = isset( $com['abandoned_carts_count'] ) ? (int) $com['abandoned_carts_count'] : 0;
$abandoned_value  = isset( $com['abandoned_carts_value'] ) ? (float) $com['abandoned_carts_value'] : 0.0;
$fraud_flags      = isset( $com['fraud_flags_today'] ) ? (int) $com['fraud_flags_today'] : 0;

// Pending email drafts count — direct query for accuracy.
$drafts_table = $wpdb->prefix . 'wp_claw_email_drafts';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$pending_drafts = (int) $wpdb->get_var(
	$wpdb->prepare(
		'SELECT COUNT(*) FROM %i WHERE status = %s',
		$drafts_table,
		'draft'
	)
);

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
$email_drafts = $wpdb->get_results(
	$wpdb->prepare(
		'SELECT * FROM %i WHERE status = %s ORDER BY created_at DESC LIMIT %d',
		$drafts_table,
		'draft',
		20
	)
);

$pipeline_stages   = isset( $crm['pipeline_stages'] ) ? (array) $crm['pipeline_stages'] : array();
$total_leads       = isset( $crm['total_leads'] ) ? (int) $crm['total_leads'] : 0;
$customer_segments = isset( $com['customer_segments'] ) ? (array) $com['customer_segments'] : array();

// Product data (requires WooCommerce).
$products_data = array();
$low_stock     = array();
if ( $woo_active && function_exists( 'wc_get_products' ) ) {
	// Recent products (last 20, sorted by date).
	$recent_products = wc_get_products( array(
		'limit'   => 20,
		'orderby' => 'date',
		'order'   => 'DESC',
		'status'  => 'publish',
	) );
	foreach ( $recent_products as $product ) {
		$products_data[] = array(
			'id'       => $product->get_id(),
			'name'     => $product->get_name(),
			'price'    => $product->get_price(),
			'stock'    => $product->get_stock_quantity(),
			'status'   => $product->get_stock_status(),
			'type'     => $product->get_type(),
			'modified' => $product->get_date_modified() ? $product->get_date_modified()->date( 'Y-m-d' ) : '',
		);
	}

	// Low stock products.
	$low_threshold = (int) get_option( 'woocommerce_notify_low_stock_amount', 2 );
	$low_manual    = wc_get_products( array(
		'limit'        => 10,
		'manage_stock' => true,
		'orderby'      => 'meta_value_num',
		'meta_key'     => '_stock', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
		'order'        => 'ASC',
	) );
	foreach ( $low_manual as $product ) {
		$qty = $product->get_stock_quantity();
		if ( null !== $qty && $qty <= $low_threshold && $qty >= 0 ) {
			$low_stock[] = array(
				'id'        => $product->get_id(),
				'name'      => $product->get_name(),
				'stock'     => $qty,
				'threshold' => $low_threshold,
			);
		}
	}
}

// Nonce for "Request CRM Review" button.
$crm_review_nonce = wp_create_nonce( 'wp_claw_create_task' );
?>
<div class="wpc-admin-wrap">

	<h1 class="wpc-section-heading"><?php esc_html_e( 'Commerce & CRM', 'claw-agent' ); ?></h1>

	<?php settings_errors( 'wp_claw_messages' ); ?>

	<?php if ( null !== $commerce_mod && ! $woo_active ) : ?>
	<div class="wpc-alert-banner">
		<p>
			<?php
			$woo_message = isset( $com['message'] ) ? sanitize_text_field( $com['message'] ) : '';
			if ( '' !== $woo_message ) {
				echo esc_html( $woo_message );
			} else {
				esc_html_e( 'WooCommerce is not active. Commerce features are unavailable, but CRM data is shown below.', 'claw-agent' );
			}
			?>
		</p>
	</div>
	<?php endif; ?>

	<!-- 1. Agent Status Bar -->
	<section class="wpc-card" style="display:flex;align-items:center;justify-content:space-between;padding:16px 20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;gap:12px;">
			<?php echo wp_claw_agent_avatar( 'Hugo', 40 ); ?>
			<div>
				<strong style="font-size:1rem;"><?php esc_html_e( 'Hugo — Commerce Lead', 'claw-agent' ); ?></strong>
				<span class="wpc-badge wpc-badge--active" style="margin-left:8px;"><?php esc_html_e( 'Active', 'claw-agent' ); ?></span>
				<br>
				<span style="font-size:0.75rem;color:#9ca3af;" id="wpc-commerce-last-check"></span>
			</div>
		</div>
		<button
			type="button"
			id="wpc-commerce-request-review"
			class="wpc-btn wpc-btn--primary"
			data-nonce="<?php echo esc_attr( $crm_review_nonce ); ?>"
			style="padding:10px 20px;border-radius:8px;font-weight:600;"
		>
			<?php esc_html_e( 'Request CRM Review', 'claw-agent' ); ?>
		</button>
	</section>

	<!-- 2. Latest Commerce Report (hero) -->
	<section class="wpc-card">
		<h3><?php esc_html_e( 'Latest Commerce & CRM Report', 'claw-agent' ); ?></h3>
		<div id="wpc-commerce-report">
			<p class="wpc-empty-state"><?php esc_html_e( "Loading Hugo's latest analysis...", 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- 3. KPI Cards -->
	<section class="wpc-kpi-grid--6">

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( $wp_claw_format_price( $daily_revenue ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Daily Revenue', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $daily_orders ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Daily Orders', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card">
			<span class="wpc-kpi-value"><?php echo esc_html( $wp_claw_format_price( $avg_order_value ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Avg Order Value', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $abandoned_count > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $abandoned_count ) ); ?></span>
			<span class="wpc-kpi-label">
				<?php esc_html_e( 'Abandoned Carts', 'claw-agent' ); ?>
				<?php if ( $abandoned_value > 0 ) : ?>
					<br><small><?php echo esc_html( $wp_claw_format_price( $abandoned_value ) ); ?></small>
				<?php endif; ?>
			</span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $pending_drafts > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $pending_drafts ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Pending Email Drafts', 'claw-agent' ); ?></span>
		</article>

		<article class="wpc-kpi-card <?php echo esc_attr( $fraud_flags > 0 ? 'wpc-kpi-card--alert' : '' ); ?>">
			<span class="wpc-kpi-value"><?php echo esc_html( number_format_i18n( $fraud_flags ) ); ?></span>
			<span class="wpc-kpi-label"><?php esc_html_e( 'Fraud Alerts Today', 'claw-agent' ); ?></span>
		</article>

	</section>

	<!-- 4. Products -->
	<?php if ( $woo_active ) : ?>
	<section class="wpc-card" style="margin-top:20px;margin-bottom:20px;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
			<h2 class="wpc-section-heading" style="margin:0;"><?php esc_html_e( 'Products', 'claw-agent' ); ?></h2>
			<button type="button" class="wpc-btn wpc-btn--primary wpc-request-scan"
				data-agent="commerce"
				data-title="Create draft product"
				data-description="Ask the site owner what product they want to create. Then use commerce_create_draft_product to create a WooCommerce draft product with the provided name, description, and price. DO NOT publish it — save as draft only."
				data-task-key="commerce_commerce_create_product">
				<?php esc_html_e( 'Ask Hugo to Create Product', 'claw-agent' ); ?>
			</button>
		</div>

		<?php if ( ! empty( $low_stock ) ) : ?>
		<div style="background:#fef3c7;border:1px solid #fbbf24;border-radius:8px;padding:12px 16px;margin-bottom:16px;">
			<strong style="color:#92400e;"><?php esc_html_e( 'Low Stock Alerts', 'claw-agent' ); ?></strong>
			<ul style="margin:8px 0 0;padding-left:20px;">
				<?php foreach ( $low_stock as $ls ) : ?>
				<li style="color:#92400e;">
					<a href="<?php echo esc_url( get_edit_post_link( $ls['id'] ) ); ?>" style="color:#92400e;text-decoration:underline;">
						<?php echo esc_html( $ls['name'] ); ?>
					</a>
					— <?php echo esc_html( sprintf( /* translators: 1: stock quantity, 2: low stock threshold */ __( '%1$d in stock (threshold: %2$d)', 'claw-agent' ), $ls['stock'], $ls['threshold'] ) ); ?>
				</li>
				<?php endforeach; ?>
			</ul>
		</div>
		<?php endif; ?>

		<?php if ( ! empty( $products_data ) ) : ?>
		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Product', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Type', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Price', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Stock', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Modified', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $products_data as $prod ) : ?>
				<tr>
					<td>
						<a href="<?php echo esc_url( get_edit_post_link( $prod['id'] ) ); ?>" style="color:#4f46e5;">
							<?php echo esc_html( mb_strimwidth( $prod['name'], 0, 40, '...' ) ); ?>
						</a>
					</td>
					<td><?php echo esc_html( $prod['type'] ); ?></td>
					<td data-inline-edit="product_price"
						data-target-id="<?php echo esc_attr( $prod['id'] ); ?>"
						data-current-value="<?php echo esc_attr( $prod['price'] ?? '' ); ?>"
						style="cursor:pointer;" title="<?php esc_attr_e( 'Click to edit price', 'claw-agent' ); ?>">
						<?php echo esc_html( $wp_claw_format_price( $prod['price'] ) ); ?>
					</td>
					<td data-inline-edit="product_stock"
						data-target-id="<?php echo esc_attr( $prod['id'] ); ?>"
						data-current-value="<?php echo esc_attr( $prod['stock'] ?? '' ); ?>"
						style="cursor:pointer;" title="<?php esc_attr_e( 'Click to edit stock', 'claw-agent' ); ?>">
						<?php if ( null === $prod['stock'] ) : ?>
							<span class="wpc-badge wpc-badge--idle"><?php esc_html_e( 'N/A', 'claw-agent' ); ?></span>
						<?php elseif ( $prod['stock'] <= 0 ) : ?>
							<span class="wpc-badge wpc-badge--failed"><?php echo esc_html( $prod['stock'] ); ?></span>
						<?php else : ?>
							<span class="wpc-badge wpc-badge--done"><?php echo esc_html( $prod['stock'] ); ?></span>
						<?php endif; ?>
					</td>
					<td><?php echo esc_html( $prod['modified'] ); ?></td>
					<td>
						<button type="button" class="wpc-btn wpc-btn--small wpc-request-scan"
							data-agent="commerce"
							data-title="<?php echo esc_attr( sprintf( /* translators: %s: product name */ __( 'Improve product description: %s', 'claw-agent' ), $prod['name'] ) ); ?>"
							data-description="<?php echo esc_attr( sprintf( /* translators: 1: product ID, 2: product name */ __( "Rewrite the product description for product ID %1\$d ('%2\$s'). Make it SEO-optimized, compelling, and include key features. Save as a draft suggestion — do NOT update the product directly.", 'claw-agent' ), $prod['id'], $prod['name'] ) ); ?>"
							data-task-key="<?php echo esc_attr( 'commerce_commerce_improve_' . $prod['id'] ); ?>">
							<?php esc_html_e( 'Improve Description', 'claw-agent' ); ?>
						</button>
					</td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php else : ?>
		<p class="wpc-empty-state">
			<?php esc_html_e( 'No products found. Add products in WooCommerce to see them here.', 'claw-agent' ); ?>
		</p>
		<?php endif; ?>
	</section>
	<?php endif; ?>

	<!-- 5. Commerce Activity History -->
	<section class="wpc-card">
		<h3><?php esc_html_e( 'Commerce & CRM Activity', 'claw-agent' ); ?></h3>
		<div id="wpc-commerce-history">
			<p class="wpc-empty-state"><?php esc_html_e( 'Loading activity...', 'claw-agent' ); ?></p>
		</div>
	</section>

	<!-- 5. Email Draft Approval -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading">
			<?php esc_html_e( 'Email Draft Approval', 'claw-agent' ); ?>
			<?php if ( $pending_drafts > 0 ) : ?>
				<span class="wpc-badge wpc-badge--pending"><?php echo esc_html( number_format_i18n( $pending_drafts ) ); ?></span>
			<?php endif; ?>
		</h2>

		<?php if ( empty( $email_drafts ) ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'When Hugo detects leads needing follow-up, email drafts appear here for your approval.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<table class="wpc-detail-table">
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Recipient', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Email', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Subject', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Language', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Created', 'claw-agent' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Actions', 'claw-agent' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $email_drafts as $draft ) : ?>
						<?php
						$draft_id       = isset( $draft->id ) ? absint( $draft->id ) : 0;
						$draft_name     = isset( $draft->recipient_name ) ? sanitize_text_field( (string) $draft->recipient_name ) : '';
						$draft_email    = isset( $draft->recipient_email ) ? sanitize_email( (string) $draft->recipient_email ) : '';
						$draft_subject  = isset( $draft->subject ) ? sanitize_text_field( (string) $draft->subject ) : '';
						$draft_body     = isset( $draft->body ) ? (string) $draft->body : '';
						$draft_language = isset( $draft->language ) ? sanitize_text_field( (string) $draft->language ) : '';
						$draft_created  = isset( $draft->created_at ) ? strtotime( $draft->created_at ) : 0;
						?>
					<tr>
						<td><?php echo esc_html( $draft_name ? $draft_name : '---' ); ?></td>
						<td><?php echo esc_html( $draft_email ? $draft_email : '---' ); ?></td>
						<td><?php echo esc_html( $draft_subject ); ?></td>
						<td><?php echo esc_html( strtoupper( $draft_language ) ); ?></td>
						<td><?php echo esc_html( $wp_claw_time_ago( $draft_created ) ); ?></td>
						<td>
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-expand-toggle"
								aria-expanded="false"
								data-target="wpc-draft-preview-<?php echo esc_attr( $draft_id ); ?>"
							>
								<?php esc_html_e( 'Preview', 'claw-agent' ); ?>
							</button>
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-btn--success wpc-admin-email-approve"
								data-id="<?php echo esc_attr( $draft_id ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_email_draft_' . $draft_id ) ); ?>"
							>
								<?php esc_html_e( 'Approve', 'claw-agent' ); ?>
							</button>
							<button
								type="button"
								class="wpc-btn wpc-btn--sm wpc-btn--danger wpc-admin-email-reject"
								data-id="<?php echo esc_attr( $draft_id ); ?>"
								data-nonce="<?php echo esc_attr( wp_create_nonce( 'wp_claw_email_draft_' . $draft_id ) ); ?>"
							>
								<?php esc_html_e( 'Reject', 'claw-agent' ); ?>
							</button>
						</td>
					</tr>
					<tr class="wpc-expandable-row" id="wpc-draft-preview-<?php echo esc_attr( $draft_id ); ?>">
						<td colspan="6">
							<div class="wpc-expandable-row__content">
								<?php echo wp_kses_post( $draft_body ); ?>
							</div>
						</td>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>
	</section>

	<!-- Lead Pipeline -->
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Lead Pipeline', 'claw-agent' ); ?></h2>

		<?php if ( ! $crm_active ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'Enable the CRM module to see lead pipeline data.', 'claw-agent' ); ?></p>
			</div>
		<?php elseif ( $total_leads < 1 ) : ?>
			<div class="wpc-empty-state">
				<p><?php esc_html_e( 'No leads captured yet. Connect a contact form (WPForms, Gravity Forms, or Contact Form 7) to start capturing leads. Hugo will score and manage them automatically.', 'claw-agent' ); ?></p>
			</div>
		<?php else : ?>
			<?php
			// Map task statuses to consolidated pipeline segments.
			$status_to_segment = array(
				'new' => 'new', 'pending' => 'new',
				'qualified' => 'qualified', 'running' => 'qualified',
				'proposal' => 'proposal',
				'done' => 'won', 'won' => 'won',
				'failed' => 'lost', 'lost' => 'lost',
			);
			$segments = array( 'new' => 0, 'qualified' => 0, 'proposal' => 0, 'won' => 0, 'lost' => 0 );
			foreach ( $pipeline_stages as $skey => $sval ) {
				$skey = sanitize_key( (string) $skey );
				$dest = isset( $status_to_segment[ $skey ] ) ? $status_to_segment[ $skey ] : 'new';
				$segments[ $dest ] += absint( $sval );
			}
			?>

			<div class="wpc-pipeline-bar">
				<?php foreach ( $segments as $seg_name => $seg_count ) :
					if ( $seg_count < 1 ) { continue; }
					$pct = round( ( $seg_count / $total_leads ) * 100, 1 );
					?>
					<div class="wpc-pipeline-bar__segment --<?php echo esc_attr( $seg_name ); ?>"
						style="width: <?php echo esc_attr( $pct ); ?>%"
						title="<?php echo esc_attr( ucfirst( $seg_name ) . ': ' . $seg_count ); ?>"></div>
				<?php endforeach; ?>
			</div>

			<div class="wpc-tier-badges" style="margin-top: 12px;">
				<?php foreach ( $segments as $seg_key => $seg_val ) :
					$dot_color = 'won' === $seg_key ? 'green' : ( 'lost' === $seg_key ? 'red' : 'yellow' );
					?>
					<span class="wpc-badge">
						<span class="wpc-status-dot wpc-status-dot--<?php echo esc_attr( $dot_color ); ?>"></span>
						<?php echo esc_html( ucfirst( $seg_key ) ); ?>
						<strong><?php echo esc_html( number_format_i18n( $seg_val ) ); ?></strong>
					</span>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</section>

	<!-- Customer Segments -->
	<?php
	$has_real_segments = false;
	if ( $woo_active && ! empty( $customer_segments ) ) {
		foreach ( $customer_segments as $seg ) {
			if ( ! empty( $seg['name'] ) && $seg['name'] !== 'Unknown' && ( isset( $seg['count'] ) && (int) $seg['count'] > 0 ) ) {
				$has_real_segments = true;
				break;
			}
		}
	}
	if ( $has_real_segments ) : ?>
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Customer Segments', 'claw-agent' ); ?></h2>

		<?php
		$total_segment_count = 0;
		foreach ( $customer_segments as $segment ) {
			$total_segment_count += isset( $segment['count'] ) ? absint( $segment['count'] ) : 0;
		}
		?>

		<table class="wpc-detail-table">
			<thead>
				<tr>
					<th scope="col"><?php esc_html_e( 'Segment', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Count', 'claw-agent' ); ?></th>
					<th scope="col"><?php esc_html_e( 'Percentage', 'claw-agent' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $customer_segments as $segment ) : ?>
					<?php
					$seg_name  = isset( $segment['name'] ) ? sanitize_text_field( (string) $segment['name'] ) : __( 'Unknown', 'claw-agent' );
					$seg_count = isset( $segment['count'] ) ? absint( $segment['count'] ) : 0;
					$seg_pct   = $total_segment_count > 0 ? round( ( $seg_count / $total_segment_count ) * 100, 1 ) : 0;
					?>
				<tr>
					<td><?php echo esc_html( $seg_name ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $seg_count ) ); ?></td>
					<td><?php echo esc_html( $seg_pct . '%' ); ?></td>
				</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	</section>
	<?php elseif ( $woo_active ) : ?>
	<section class="wpc-card">
		<h2 class="wpc-section-heading"><?php esc_html_e( 'Customer Segments', 'claw-agent' ); ?></h2>
		<p class="wpc-empty-state"><?php esc_html_e( 'Customer segmentation will appear here after Hugo processes enough order data. Segments include: one-time, repeat, loyal, at-risk, and dormant customers.', 'claw-agent' ); ?></p>
	</section>
	<?php endif; ?>

</div>

<script>
( function () {
	'use strict';

	var wpClawCommerceConfig = {
		apiBase:   <?php echo wp_json_encode( esc_url_raw( rest_url( 'wp-claw/v1' ) ) ); ?>,
		nonce:     <?php echo wp_json_encode( wp_create_nonce( 'wp_rest' ) ); ?>,
		taskNonce: <?php echo wp_json_encode( $crm_review_nonce ); ?>
	};

	/** Set the text content of an element by ID. */
	function setText( id, val ) {
		var el = document.getElementById( id );
		if ( el ) { el.textContent = String( val == null ? '' : val ); }
	}

	/** Replace element children with a DocumentFragment. */
	function setContent( id, frag ) {
		var el = document.getElementById( id );
		if ( ! el ) { return; }
		while ( el.firstChild ) { el.removeChild( el.firstChild ); }
		el.dataset.loaded = 'true';
		el.appendChild( frag );
	}

	/** Build an empty-state paragraph node. */
	function emptyState( msg ) {
		var frag = document.createDocumentFragment();
		var p    = document.createElement( 'p' );
		p.className   = 'wpc-empty-state';
		p.textContent = msg;
		frag.appendChild( p );
		return frag;
	}

	/** Shared fetch with WP REST nonce. */
	function wpClawFetch( path, opts ) {
		return fetch(
			wpClawCommerceConfig.apiBase + path,
			Object.assign(
				{ headers: { 'X-WP-Nonce': wpClawCommerceConfig.nonce, 'Content-Type': 'application/json' } },
				opts || {}
			)
		);
	}

	/** Build a report card using safe DOM — no innerHTML on data. */
	function buildReportCard( report ) {
		var frag = document.createDocumentFragment();
		var card = document.createElement( 'div' );
		card.className = 'wpc-report-card';

		var header = document.createElement( 'div' );
		header.className = 'wpc-report-card__header';

		var titleEl = document.createElement( 'strong' );
		titleEl.className   = 'wpc-report-card__title';
		titleEl.textContent = report.title || '';
		header.appendChild( titleEl );

		if ( report.status ) {
			var badge = document.createElement( 'span' );
			badge.className   = 'wpc-badge wpc-badge--done';
			badge.textContent = report.status;
			header.appendChild( badge );
		}

		if ( report.created_at ) {
			var dateEl = document.createElement( 'span' );
			dateEl.className   = 'wpc-report-card__date';
			dateEl.textContent = new Date( report.created_at ).toLocaleString();
			header.appendChild( dateEl );
		}
		card.appendChild( header );

		var body = report.content || report.summary || '';
		if ( body ) {
			var bodyDiv = document.createElement( 'div' );
			bodyDiv.className = 'wpc-report-card__body';
			var pre = document.createElement( 'pre' );
			pre.className   = 'wpc-report-pre';
			pre.textContent = body;
			bodyDiv.appendChild( pre );
			card.appendChild( bodyDiv );
		}

		frag.appendChild( card );
		return frag;
	}

	/** Build activity history table using safe DOM — no innerHTML on data. */
	function buildHistoryTable( reports ) {
		var frag  = document.createDocumentFragment();
		var table = document.createElement( 'table' );
		table.className = 'wpc-detail-table';

		var thead = document.createElement( 'thead' );
		var hrow  = document.createElement( 'tr' );
		[
			<?php echo wp_json_encode( __( 'Date', 'claw-agent' ) ); ?>,
			<?php echo wp_json_encode( __( 'Report', 'claw-agent' ) ); ?>,
			<?php echo wp_json_encode( __( 'Status', 'claw-agent' ) ); ?>
		].forEach( function ( label ) {
			var th = document.createElement( 'th' );
			th.setAttribute( 'scope', 'col' );
			th.textContent = label;
			hrow.appendChild( th );
		} );
		thead.appendChild( hrow );
		table.appendChild( thead );

		var tbody = document.createElement( 'tbody' );
		reports.forEach( function ( r ) {
			var row = document.createElement( 'tr' );

			var tdDate = document.createElement( 'td' );
			tdDate.textContent = r.created_at ? new Date( r.created_at ).toLocaleString() : '---';
			row.appendChild( tdDate );

			var tdTitle = document.createElement( 'td' );
			tdTitle.textContent = r.title || '---';
			row.appendChild( tdTitle );

			var tdStatus = document.createElement( 'td' );
			var cls  = 'done' === r.status ? 'wpc-badge--done' : ( 'failed' === r.status ? 'wpc-badge--error' : 'wpc-badge--pending' );
			var sbadge = document.createElement( 'span' );
			sbadge.className   = 'wpc-badge ' + cls;
			sbadge.textContent = r.status || '---';
			tdStatus.appendChild( sbadge );
			row.appendChild( tdStatus );

			tbody.appendChild( row );
		} );
		table.appendChild( tbody );
		frag.appendChild( table );
		return frag;
	}

	function loadCommerceReport() {
		wpClawFetch( '/reports?agent=commerce&limit=1' )
			.then( function ( res ) { return res.ok ? res.json() : Promise.reject( res.status ); } )
			.then( function ( data ) {
				var list = Array.isArray( data ) ? data : ( data.reports || [] );
				setContent( 'wpc-commerce-report', list.length
					? buildReportCard( list[0] )
					: emptyState( <?php echo wp_json_encode( __( 'No reports yet. Hugo will publish his first analysis shortly.', 'claw-agent' ) ); ?> )
				);
			} )
			.catch( function () {
				setContent( 'wpc-commerce-report', emptyState( <?php echo wp_json_encode( __( 'Could not load report — check gateway connection.', 'claw-agent' ) ); ?> ) );
			} );
	}

	function loadCommerceHistory() {
		wpClawFetch( '/reports?agent=commerce&since=30d&limit=10' )
			.then( function ( res ) { return res.ok ? res.json() : Promise.reject( res.status ); } )
			.then( function ( data ) {
				var list = Array.isArray( data ) ? data : ( data.reports || [] );
				setContent( 'wpc-commerce-history', list.length
					? buildHistoryTable( list )
					: emptyState( <?php echo wp_json_encode( __( 'No activity recorded yet.', 'claw-agent' ) ); ?> )
				);
			} )
			.catch( function () {
				setContent( 'wpc-commerce-history', emptyState( <?php echo wp_json_encode( __( 'Could not load activity — check gateway connection.', 'claw-agent' ) ); ?> ) );
			} );
	}

	function loadAgentStatus() {
		wpClawFetch( '/agents' )
			.then( function ( res ) { return res.ok ? res.json() : Promise.reject( res.status ); } )
			.then( function ( data ) {
				var agents = Array.isArray( data ) ? data : ( data.agents || [] );
				var hugo   = null;
				for ( var i = 0; i < agents.length; i++ ) {
					var a = agents[ i ];
					if ( a && ( 'commerce' === a.id || 'hugo' === a.id || ( a.name && /hugo/i.test( a.name ) ) ) ) {
						hugo = a;
						break;
					}
				}
				if ( hugo && ( hugo.last_heartbeat || hugo.last_active ) ) {
					var ago = Math.round( ( Date.now() - new Date( hugo.last_heartbeat || hugo.last_active ).getTime() ) / 60000 );
					var label = ago < 1
						? <?php echo wp_json_encode( __( 'just now', 'claw-agent' ) ); ?>
						: ago + ' ' + <?php echo wp_json_encode( __( 'min ago', 'claw-agent' ) ); ?>;
					setText( 'wpc-commerce-last-check', <?php echo wp_json_encode( __( 'Last check: ', 'claw-agent' ) ); ?> + label );
				} else {
					setText( 'wpc-commerce-last-check', <?php echo wp_json_encode( __( 'Status unknown', 'claw-agent' ) ); ?> );
				}
			} )
			.catch( function () {
				setText( 'wpc-commerce-last-check', <?php echo wp_json_encode( __( 'Gateway offline', 'claw-agent' ) ); ?> );
			} );
	}

	var reviewBtn = document.getElementById( 'wpc-commerce-request-review' );
	if ( reviewBtn ) {
		reviewBtn.addEventListener( 'click', function () {
			reviewBtn.disabled    = true;
			reviewBtn.textContent = <?php echo wp_json_encode( __( 'Sending...', 'claw-agent' ) ); ?>;

			wpClawFetch( '/create-task', {
				method: 'POST',
				body: JSON.stringify( {
					agent:       'commerce',
					title:       <?php echo wp_json_encode( __( 'Manual CRM review', 'claw-agent' ) ); ?>,
					priority:    'high',
					description: <?php echo wp_json_encode( __( 'Admin requested a CRM and commerce review. Check abandoned carts, review lead pipeline health, score unscored leads, check order trends. Report all findings.', 'claw-agent' ) ); ?>,
					_wpnonce:    wpClawCommerceConfig.taskNonce
				} )
			} )
				.then( function ( res ) { return res.ok ? res.json() : Promise.reject( res.status ); } )
				.then( function () {
					reviewBtn.textContent = <?php echo wp_json_encode( __( 'Task created', 'claw-agent' ) ); ?>;
					setTimeout( function () {
						reviewBtn.disabled    = false;
						reviewBtn.textContent = <?php echo wp_json_encode( __( 'Request CRM Review', 'claw-agent' ) ); ?>;
					}, 3000 );
				} )
				.catch( function () {
					reviewBtn.textContent = <?php echo wp_json_encode( __( 'Failed — retry?', 'claw-agent' ) ); ?>;
					reviewBtn.disabled    = false;
				} );
		} );
	}

	loadCommerceReport();
	loadCommerceHistory();
	loadAgentStatus();
}() );
</script>
