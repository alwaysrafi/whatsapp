<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * DBB Management Breadcrumbs
 * Provides breadcrumb navigation for the DBB Management plugin
 */

// Function to display breadcrumbs in account manager pages
function dbb_display_breadcrumbs() {
    // Get the current page
    $current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
    
    // Get the current service from query string
    $service = isset($_GET['service']) ? sanitize_text_field($_GET['service']) : '';
    
    // Get all registered services
    $services = wsm_get_available_services();
    $current_service = null;
    
    // Find the current service details
    if (!empty($service) && isset($services[$service])) {
        $current_service = $services[$service];
    } else {
        // Check if the current page is a service slug
        foreach ($services as $key => $service_data) {
            if ($service_data['slug'] === $current_page) {
                $current_service = $service_data;
                $service = $key;
                break;
            }
        }
    }
    
    // Get all modules
    $modules = dbb_get_available_modules();
    
    // Start breadcrumbs markup
    ?>
    <div class="dbb-breadcrumbs">
        <a href="<?php echo admin_url('admin.php?page=dbb-management'); ?>">DBB Management</a>
        
        <?php if ($service === 'account_manager' || $current_page === 'account-manager'): ?>
            <span class="separator"> &rsaquo; </span>
            <span class="current">Account Manager</span>
            
        <?php elseif (!empty($current_service)): ?>
            <span class="separator"> &rsaquo; </span>
            <a href="<?php echo admin_url('admin.php?page=account-manager'); ?>">Account Manager</a>
            <span class="separator"> &rsaquo; </span>
            <span class="current"><?php echo esc_html($current_service['name']); ?></span>
            
        <?php elseif ($current_page === 'add-new-service'): ?>
            <span class="separator"> &rsaquo; </span>
            <a href="<?php echo admin_url('admin.php?page=account-manager'); ?>">Account Manager</a>
            <span class="separator"> &rsaquo; </span>
            <span class="current">Add New Service</span>
            
        <?php elseif ($service === 'redeem_key_manager' || $current_page === 'redeem-key-manager'): ?>
            <span class="separator"> &rsaquo; </span>
            <span class="current">Redeem Key Manager</span>
            
        <?php elseif ($service === 'sales_monitor' || $current_page === 'sales-monitor'): ?>
            <span class="separator"> &rsaquo; </span>
            <span class="current">Sales Monitor</span>
            
        <?php elseif ($service === 'mail_reader'): ?>
            <span class="separator"> &rsaquo; </span>
            <span class="current">Mail Reader</span>
            
        <?php elseif (!empty($service) && isset($modules[$service])): ?>
            <span class="separator"> &rsaquo; </span>
            <span class="current"><?php echo esc_html($modules[$service]['name']); ?></span>
            
        <?php endif; ?>
    </div>
    <?php
}

// Add CSS for breadcrumbs
function dbb_breadcrumbs_styles() {
    ?>
    <style type="text/css">
        .dbb-breadcrumbs {
            margin: 10px 0 20px;
            font-size: 14px;
            color: #555;
            display: flex;
            align-items: center;
            flex-wrap: wrap;
        }
        .dbb-breadcrumbs a {
            text-decoration: none;
            color: #2271b1;
        }
        .dbb-breadcrumbs a:hover {
            color: #135e96;
            text-decoration: underline;
        }
        .dbb-breadcrumbs .separator {
            margin: 0 8px;
            color: #757575;
        }
        .dbb-breadcrumbs .current {
            font-weight: 600;
            color: #3c434a;
        }
    </style>
    <?php
}
add_action('admin_head', 'dbb_breadcrumbs_styles'); 