<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Get statistics
global $wpdb;

// Get total orders count (optimized - direct database query)
$total_orders_count = $wpdb->get_var("
    SELECT COUNT(*) 
    FROM {$wpdb->prefix}posts 
    WHERE post_type = 'shop_order' 
    AND post_status IN ('wc-processing', 'wc-completed')
");

// Get total sales (optimized - direct database query)
$total_sales = $wpdb->get_var("
    SELECT SUM(meta_value) 
    FROM {$wpdb->prefix}postmeta 
    WHERE meta_key = '_order_total' 
    AND post_id IN (
        SELECT ID FROM {$wpdb->prefix}posts 
        WHERE post_type = 'shop_order' 
        AND post_status IN ('wc-processing', 'wc-completed')
    )
");

// Get active subscriptions count
$active_subscriptions = 0;
if (class_exists('WC_Subscriptions')) {
    $active_subscriptions = $wpdb->get_var("
        SELECT COUNT(*) 
        FROM {$wpdb->prefix}posts 
        WHERE post_type = 'shop_subscription' 
        AND post_status = 'wc-active'
    ");
}

// Get total products
$total_products = wp_count_posts('product')->publish;

// Add body class to scope styles to this plugin page only
add_filter('admin_body_class', function($classes) {
    return $classes . ' dbb-plugin-page dbb-minimal-ui';
});
?>
<!-- DBB Minimal Dashboard -->
<div class="dbb-dashboard-wrapper">
    <div class="dbb-header">
        <h1>Dashboard</h1>
        <p>Welcome back, <?php echo esc_html(wp_get_current_user()->display_name); ?>! Here's your business overview.</p>
    </div>
    
    <div class="dbb-content">
        <!-- Stats Cards -->
        <div class="dbb-stats-grid">
            <div class="dbb-stat-card">
                <span class="dbb-stat-label">Total Orders</span>
                <div class="dbb-stat-value"><?php echo number_format($total_orders_count); ?> <span class="dbb-stat-icon">📦</span></div>
            </div>
            
            <div class="dbb-stat-card">
                <span class="dbb-stat-label">Total Sales</span>
                <div class="dbb-stat-value">৳<?php echo number_format($total_sales, 0); ?> <span class="dbb-stat-icon">💰</span></div>
            </div>
            
            <div class="dbb-stat-card">
                <span class="dbb-stat-label">Subscriptions</span>
                <div class="dbb-stat-value"><?php echo number_format($active_subscriptions); ?> <span class="dbb-stat-icon">🔄</span></div>
            </div>
            
            <div class="dbb-stat-card">
                <span class="dbb-stat-label">Products</span>
                <div class="dbb-stat-value"><?php echo number_format($total_products); ?> <span class="dbb-stat-icon">📋</span></div>
            </div>
        </div>
        
        <!-- Modules Section -->
        <div class="dbb-modules-section">
            <h2 class="dbb-section-title">Quick Access</h2>
            
            <div class="dbb-modules-grid">
                <a href="<?php echo admin_url('admin.php?page=dbb-management&service=account_manager'); ?>" class="dbb-module-card">
                    <span class="dbb-module-arrow">→</span>
                    <div class="dbb-module-icon">👥</div>
                    <h3 class="dbb-module-title">Account Manager</h3>
                    <p class="dbb-module-description">Manage streaming accounts and subscriptions</p>
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=dbb-management&service=redeem_key_manager'); ?>" class="dbb-module-card">
                    <span class="dbb-module-arrow">→</span>
                    <div class="dbb-module-icon">🔑</div>
                    <h3 class="dbb-module-title">Redeem Keys</h3>
                    <p class="dbb-module-description">Generate and manage redemption keys</p>
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=dbb-management&service=sales_monitor'); ?>" class="dbb-module-card">
                    <span class="dbb-module-arrow">→</span>
                    <div class="dbb-module-icon">📊</div>
                    <h3 class="dbb-module-title">Sales Monitor</h3>
                    <p class="dbb-module-description">Track costs, sales reports, and expenses</p>
                </a>
                
                <a href="<?php echo admin_url('admin.php?page=dbb-whatsapp-settings'); ?>" class="dbb-module-card">
                    <span class="dbb-module-arrow">→</span>
                    <div class="dbb-module-icon">💬</div>
                    <h3 class="dbb-module-title">WhatsApp</h3>
                    <p class="dbb-module-description">WhatsApp notification settings</p>
                </a>
            </div>
        </div>
    </div>
</div>
