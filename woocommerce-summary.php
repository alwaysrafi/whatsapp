<?php
/*
Plugin Name: DBB Management
Description: Complete management solution for streaming services and account sharing
Version: 1.0
Author: Your Name
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DBB_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DBB_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DBB_PLUGIN_ASSETS_URL', DBB_PLUGIN_URL . 'assets/');
define('DBB_PLUGIN_ASSETS_DIR', DBB_PLUGIN_DIR . 'assets/');

// Include required files - commented out missing files
// require_once DBB_PLUGIN_DIR . 'includes/whatsapp-web-automation.php';

// Include global WhatsApp fix to ensure functionality works from all WordPress pages
// require_once DBB_PLUGIN_DIR . 'global-whatsapp-fix.php';

/**
 * Direct WhatsApp sending function - Override for problematic class method
 */
function direct_send_whatsapp($phone, $message) {
    $server_url = 'http://host.docker.internal:3001';
    
    // Format phone number
    $clean_phone = preg_replace('/[^\d]/', '', $phone);
    if (!empty($clean_phone) && !str_starts_with($clean_phone, '880') && strlen($clean_phone) == 11) {
        $clean_phone = '880' . substr($clean_phone, 1);
    }
    $formatted_phone = '+' . $clean_phone;
    
    // Clean and escape the message to prevent JSON issues
    $clean_message = trim($message);
    
    // Remove any null bytes and control characters except newlines and tabs
    $clean_message = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $clean_message);
    
    // Ensure proper UTF-8 encoding
    $clean_message = mb_convert_encoding($clean_message, 'UTF-8', 'UTF-8');
    
    // Prepare the data array
    $data = array(
        'phone' => $formatted_phone,
        'message' => $clean_message,
        'delay' => 2000
    );
    
    // Use JSON_UNESCAPED_UNICODE to handle special characters properly
    $json_data = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    
    // Double-check JSON encoding was successful
    if ($json_data === false) {
        return array('success' => false, 'message' => 'Failed to encode message data: ' . json_last_error_msg());
    }
    
    // Send message
    $response = wp_remote_post($server_url . '/send-message', array(
        'body' => $json_data,
        'headers' => array('Content-Type' => 'application/json'),
        'timeout' => 30
    ));
    
    if (is_wp_error($response)) {
        return array('success' => false, 'message' => 'Connection error: ' . $response->get_error_message());
    }
    
    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    
    if ($data && $data['success']) {
        return array('success' => true, 'message' => 'Message sent successfully', 'message_id' => $data['message_id'] ?? null);
    } else {
        return array('success' => false, 'message' => $data['message'] ?? 'Unknown error');
    }
}

/**
 * Override the global WhatsApp function with working direct method
 */
if (!function_exists('dbb_send_whatsapp_web_message_override')) {
    function dbb_send_whatsapp_web_message_override($phone_number, $message, $order_id = null) {
        // Check if enabled
        if (!get_option('dbb_whatsapp_web_enabled', false)) {
            return array('success' => false, 'message' => 'WhatsApp Web automation is disabled');
        }
        
        if (!get_option('dbb_whatsapp_web_auto_send_accounts', false)) {
            return array('success' => false, 'message' => 'Auto-send account messages is disabled');
        }
        
        // Validate inputs
        if (empty($phone_number) || empty(trim($phone_number))) {
            return array('success' => false, 'message' => 'Phone number is required');
        }
        
        if (empty($message) || empty(trim($message))) {
            return array('success' => false, 'message' => 'Message is required');
        }
        
        return direct_send_whatsapp($phone_number, $message);
    }
}

// Initialize WhatsApp integration classes
function dbb_init_whatsapp_integration() {
    // Initialize WhatsApp Web Automation
    if (class_exists('DBB_WhatsApp_Web_Automation')) {
        new DBB_WhatsApp_Web_Automation();
    }
}
add_action('plugins_loaded', 'dbb_init_whatsapp_integration');

// Create necessary folders on activation
register_activation_hook(__FILE__, 'dbb_plugin_activation');

function dbb_plugin_activation() {
    // Create includes directory if it doesn't exist
    $includes_dir = DBB_PLUGIN_DIR . 'includes';
    if (!file_exists($includes_dir)) {
        wp_mkdir_p($includes_dir);
    }
    
    // Create assets directory if it doesn't exist
    $assets_dir = DBB_PLUGIN_ASSETS_DIR;
    if (!file_exists($assets_dir)) {
        wp_mkdir_p($assets_dir);
    }
    
    // Create subdirectories in assets
    $dirs = array('css', 'js', 'images');
    foreach ($dirs as $dir) {
        $path = $assets_dir . $dir;
        if (!file_exists($path)) {
            wp_mkdir_p($path);
        }
    }
    
    // Create database tables
    dbb_create_database_tables();
}

/**
 * Create database tables for the plugin
 */
function dbb_create_database_tables() {
    global $wpdb;
    
    $charset_collate = $wpdb->get_charset_collate();
    
    // WhatsApp logs table
    $table_name = $wpdb->prefix . 'dbb_whatsapp_logs';
    
    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        phone_number varchar(20) NOT NULL,
        message text NOT NULL,
        method varchar(20) NOT NULL DEFAULT 'web',
        status varchar(20) NOT NULL DEFAULT 'pending',
        order_id bigint(20) DEFAULT NULL,
        error_message text DEFAULT NULL,
        timestamp datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY phone_number (phone_number),
        KEY order_id (order_id),
        KEY status (status),
        KEY timestamp (timestamp)
    ) $charset_collate;";
    
    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}

// Add main menu and submenus
function dbb_add_main_menu() {
    // Add main menu page - use read capability which admins always have
    add_menu_page(
        'DBB Management',
        'DBB Management',
        'read',
        'dbb-management',
        'dbb_render_main_page',
        'dashicons-admin-generic',
        30
    );
    
    // Add dashboard submenu (this will be the first item)
    add_submenu_page(
        'dbb-management',
        'Dashboard',
        'Dashboard',
        'read',
        'dbb-management',
        'dbb_render_main_page'
    );
}
add_action('admin_menu', 'dbb_add_main_menu');

// Remove only external plugin menus, not our own submenus
function dbb_remove_plugin_menus() {
    // Remove any WooCommerce related submenu that we don't want
    remove_submenu_page('woocommerce', 'woocomerce-summary');
    
    // Get all registered services and remove their individual menu items
    // (but keep them accessible through our main dashboard)
    $services = get_option('wsm_registered_services', array());
    foreach ($services as $service_key => $service) {
        remove_menu_page($service['slug']);
    }
}
// Run late to ensure we remove menus after they've been added
add_action('admin_menu', 'dbb_remove_plugin_menus', 999);

function dbb_render_main_page() {
    // Log access attempt
    error_log('DBB Management page accessed by user: ' . get_current_user_id());
    error_log('User can manage_options: ' . (current_user_can('manage_options') ? 'yes' : 'no'));
    
    // Check user capabilities - allow manage_options
    if (!current_user_can('manage_options')) {
        wp_die(__('Sorry, you are not allowed to access this page. You must be an administrator.', 'dbb-management'), 'Insufficient Permissions', array('response' => 403));
    }
    
    // Get the current page parameter
    $page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : 'dbb-management';
    $service = isset($_GET['service']) ? sanitize_text_field($_GET['service']) : '';
    
    // Handle direct page access (account-manager, redeem_key_manager, etc.)
    if ($page === 'account-manager') {
        $service = 'account_manager';
    } elseif ($page === 'redeem-key-manager') {
        $service = 'redeem_key_manager';
    } elseif ($page === 'sales-monitor') {
        $service = 'sales_monitor';
    }
    
    // Handle Account Manager routing
    if ($page === 'account-manager' || $service === 'account_manager' || $service === 'add-service' || $service === 'edit-service') {
        if ($service === 'add-service') {
            // Render add service page
            if (function_exists('wsm_render_add_service_page')) {
                wsm_render_add_service_page();
                return;
            } else {
                echo '<div class="wrap"><h1>Add Service</h1><p>Account manager module is not available.</p></div>';
                return;
            }
        } elseif ($service === 'edit-service') {
            // Render edit service page
            if (function_exists('wsm_render_edit_service_page')) {
                wsm_render_edit_service_page();
                return;
            } else {
                echo '<div class="wrap"><h1>Edit Service</h1><p>Account manager module is not available.</p></div>';
                return;
            }
        } else {
            // Render main account manager page
            if (function_exists('wsm_render_main_page')) {
                wsm_render_main_page();
                return;
            } else {
                echo '<div class="wrap"><h1>Account Manager</h1><p>Account manager module is not available.</p></div>';
                return;
            }
        }
    }
    
    // Handle specific service pages (netflix, disney+, etc.)
    if (!empty($service) && $service !== 'account_manager' && $service !== 'add-service') {
        // Check if this is a registered service
        $services = get_option('wsm_registered_services', array());
        if (isset($services[$service])) {
            // Render service-specific page
            if (function_exists('wsm_render_service_page')) {
                wsm_render_service_page();
                return;
            }
        }
    }
    
    // Handle Redeem Key Manager
    if ($page === 'redeem_key_manager' || $service === 'redeem_key_manager') {
        if (class_exists('DBB_Redeem_Key_Manager')) {
            $redeem_manager = new DBB_Redeem_Key_Manager();
            $redeem_manager->render_redeem_key_page();
            return;
        }
    }
    
    // Handle Sales Monitor
    if ($page === 'sales_monitor' || $service === 'sales_monitor') {
        if (class_exists('DBB_Sales_Monitor')) {
            $sales_monitor = new DBB_Sales_Monitor();
            $sales_monitor->render_sales_monitor_page();
            return;
        }
    }
    
    // Handle Mail Reader
    if ($page === 'mail_reader' || $service === 'mail_reader') {
        if (class_exists('DBB_Mail_Reader')) {
            $mail_reader = new DBB_Mail_Reader();
            $mail_reader->render_mail_reader_page();
            return;
        }
    }
    
    // Handle WhatsApp Web Automation
    if ($page === 'whatsapp_web_auto' || $service === 'whatsapp_web_auto') {
        if (class_exists('DBB_WhatsApp_Web_Automation')) {
            $whatsapp_web = new DBB_WhatsApp_Web_Automation();
            $whatsapp_web->render_whatsapp_web_page();
            return;
        }
    }
    
    // Otherwise, render the main dashboard
    dbb_render_dashboard();
}

/**
 * Render the modern dashboard
 */
function dbb_render_dashboard() {
    // Include the clean dashboard template
    include(DBB_PLUGIN_DIR . 'includes/dashboard-template.php');
}

/**
 * Get available modules (legacy - kept for compatibility)
 */
function dbb_get_available_modules() {
    return apply_filters('dbb_modules', array(
        'account_manager' => array(
            'name' => 'Account Manager',
            'description' => 'Manage streaming accounts, subscriptions, and user assignments',
            'slug' => 'account-manager',
            'icon' => 'account-manager-icon',
            'dashicon' => 'dashicons-admin-users'
        ),
        'redeem_key_manager' => array(
            'name' => 'Redeem Key Manager',
            'description' => 'Manage product redeem keys and automatically deliver to customers',
            'slug' => 'redeem-key-manager',
            'icon' => 'redeem-key-icon',
            'dashicon' => 'dashicons-key'
        ),
        'sales_monitor' => array(
            'name' => 'Sales Monitor',
            'description' => 'Track product costs, profits, sales reports, and expenses',
            'slug' => 'sales-monitor',
            'icon' => 'sales-monitor-icon',
            'dashicon' => 'dashicons-chart-line'
        ),
        'whatsapp_web_auto' => array(
            'name' => 'WhatsApp Web Automation',
            'description' => 'Automated WhatsApp messaging with QR code login and real-time sending',
            'slug' => 'whatsapp-web-auto',
            'icon' => 'whatsapp-web-icon',
            'dashicon' => 'dashicons-smartphone'
        )
    ));
}

// Include account manager module
require_once DBB_PLUGIN_DIR . 'includes/account-manager.php';

// Include redeem key manager module
require_once DBB_PLUGIN_DIR . 'includes/redeem-key-manager.php';

// Include sales monitor module
require_once DBB_PLUGIN_DIR . 'includes/sales-monitor.php';

// Include account display module
require_once DBB_PLUGIN_DIR . 'includes/account-display.php';

// Include breadcrumbs module
require_once DBB_PLUGIN_DIR . 'includes/breadcrumbs.php';

// Include template functions - DEPRECATED (Corona template removed)
// require_once DBB_PLUGIN_DIR . 'includes/template-functions.php';

// Include account manager functions
require_once DBB_PLUGIN_DIR . 'includes/account-manager-functions.php';

// Include WhatsApp integration module
require_once DBB_PLUGIN_DIR . 'includes/whatsapp-integration.php';

// Initialize order handling
function dbb_init_order_handling() {
    // Hook into order creation
    add_action('woocommerce_new_order', 'dbb_handle_new_order', 10, 1);
    
    // Hook into order status changes
    add_action('woocommerce_order_status_changed', 'wsm_handle_order_status_change', 10, 3);
    
    // Hook into order payment complete
    add_action('woocommerce_payment_complete', 'dbb_handle_payment_complete', 10, 1);

    // Add checkout validation
    add_action('woocommerce_checkout_process', 'dbb_validate_checkout', 10);
    
    // Add error handling for checkout
    add_action('woocommerce_checkout_order_processed', 'dbb_handle_checkout_order', 10, 3);
    
    // Ensure database tables exist
    dbb_ensure_database_tables();
}
add_action('init', 'dbb_init_order_handling');

/**
 * Ensure database tables exist (run on every init to be safe)
 */
function dbb_ensure_database_tables() {
    global $wpdb;
    
    $table_name = $wpdb->prefix . 'dbb_whatsapp_logs';
    
    // Check if table exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") == $table_name;
    
    if (!$table_exists) {
        dbb_create_database_tables();
    }
}

// Validate checkout process
function dbb_validate_checkout() {
    try {
        // Skip validation if we're in admin or during AJAX
        if (is_admin() || wp_doing_ajax()) {
            return;
        }
        
        // Get cart items
        if (!WC()->cart || WC()->cart->is_empty()) {
            return;
        }
        
        $cart_items = WC()->cart->get_cart();
        
        // Get all registered services (cached)
        $services = wp_cache_get('wsm_registered_services');
        if (false === $services) {
            $services = get_option('wsm_registered_services', array());
            wp_cache_set('wsm_registered_services', $services, '', 300); // Cache for 5 minutes
        }
        
        if (empty($services)) {
            return;
        }
        
        foreach ($cart_items as $cart_item) {
            $product_id = $cart_item['product_id'];
            
            // Check if this product needs account assignment
            $needs_account = get_post_meta($product_id, '_needs_account', true);
            
            if ($needs_account === 'yes') {
                // Only check one service to avoid heavy queries
                foreach ($services as $service_key => $service) {
                    // Quick check for available accounts (limit to 1 for performance)
                    $available_count = wp_cache_get('available_accounts_' . $service_key);
                    if (false === $available_count) {
                        global $wpdb;
                        $available_count = $wpdb->get_var($wpdb->prepare("
                            SELECT COUNT(p.ID) 
                            FROM {$wpdb->posts} p 
                            INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
                            WHERE p.post_type = %s 
                            AND p.post_status = 'publish' 
                            AND pm.meta_key = '_summary_slots' 
                            AND CAST(pm.meta_value AS UNSIGNED) > 0
                            LIMIT 1
                        ", $service['slug']));
                        
                        wp_cache_set('available_accounts_' . $service_key, $available_count, '', 60); // Cache for 1 minute
                    }
                    
                    if ($available_count == 0) {
                        wc_add_notice(sprintf(
                            __('Sorry, there are no available accounts for %s. Please try again later.', 'dbb-management'),
                            $service['name']
                        ), 'error');
                        return; // Exit early to prevent multiple error messages
                    }
                    
                    break; // Only check first service to avoid performance issues
                }
            }
        }
    } catch (Exception $e) {
        // Log error but don't break checkout
        if (function_exists('dbb_log')) {
            dbb_log('Error in checkout validation: ' . $e->getMessage(), 'error');
        }
        // Don't add notice to avoid breaking checkout
    }
}

// Handle checkout order processing
function dbb_handle_checkout_order($order_id, $posted_data, $order) {
    try {
        if (!$order || !$order_id) {
            return; // Don't throw exception, just return
        }

        // Get all registered services (cached)
        $services = wp_cache_get('wsm_registered_services');
        if (false === $services) {
            $services = get_option('wsm_registered_services', array());
            wp_cache_set('wsm_registered_services', $services, '', 300);
        }
        
        if (empty($services)) {
            return;
        }
        
        // Initialize order meta efficiently
        $meta_updates = array();
        foreach ($services as $service_key => $service) {
            $meta_updates['_linked_' . $service_key . '_post'] = '';
        }

        // Check if this is a subscription order
        if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id)) {
            $meta_updates['_is_subscription_order'] = 'yes';
        }
        
        // Batch update meta
        foreach ($meta_updates as $key => $value) {
            update_post_meta($order_id, $key, $value);
        }

        // Log successful order creation (only if debug is enabled)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            dbb_log('Order ' . $order_id . ' created successfully', 'info');
        }
        
    } catch (Exception $e) {
        // Log the error but don't break checkout
        if (function_exists('dbb_log')) {
            dbb_log('Error in checkout order handler: ' . $e->getMessage(), 'error');
        }
        
        // Add order note without throwing exception
        if ($order) {
            $order->add_order_note('Error during order processing: ' . $e->getMessage());
        }
        
        // Don't throw the exception to avoid breaking checkout
    }
}

// Handle new order creation
function dbb_handle_new_order($order_id) {
    // Early exit if during checkout or AJAX to prevent interference
    if (is_checkout() || wp_doing_ajax()) {
        return;
    }
    
    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            return; // Don't throw exception
        }

        // Get all registered services (cached)
        $services = wp_cache_get('wsm_registered_services');
        if (false === $services) {
            $services = get_option('wsm_registered_services', array());
            wp_cache_set('wsm_registered_services', $services, '', 300);
        }
        
        if (empty($services)) {
            return;
        }
        
        // Initialize order meta efficiently
        $meta_updates = array();
        foreach ($services as $service_key => $service) {
            $meta_updates['_linked_' . $service_key . '_post'] = '';
        }

        // Check if this is a subscription order
        if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id)) {
            $meta_updates['_is_subscription_order'] = 'yes';
        }
        
        // Batch update meta
        foreach ($meta_updates as $key => $value) {
            update_post_meta($order_id, $key, $value);
        }

        // Log successful order creation (only if debug is enabled)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            dbb_log('Order ' . $order_id . ' initialized successfully', 'info');
        }
        
    } catch (Exception $e) {
        // Log the error but don't break the process
        if (function_exists('dbb_log')) {
            dbb_log('Error in new order handler: ' . $e->getMessage(), 'error');
        }
        
        // Add order note without throwing exception
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note('Error during order initialization: ' . $e->getMessage());
        }
    }
}

// Handle payment completion
function dbb_handle_payment_complete($order_id) {
    // Early exit if during checkout or AJAX to prevent interference
    if (is_checkout() || wp_doing_ajax()) {
        return;
    }
    
    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            return; // Don't throw exception
        }

        // Get all registered services (cached)
        $services = wp_cache_get('wsm_registered_services');
        if (false === $services) {
            $services = get_option('wsm_registered_services', array());
            wp_cache_set('wsm_registered_services', $services, '', 300);
        }
        
        if (empty($services)) {
            return;
        }
        
        foreach ($services as $service_key => $service) {
            // Get linked account ID
            $linked_post_id = get_post_meta($order_id, '_linked_' . $service_key . '_post', true);
            
            if ($linked_post_id) {
                // Send account details email (only if function exists)
                if (function_exists('wsm_send_account_assignment_email')) {
                    wsm_send_account_assignment_email($order_id, $service_key, $linked_post_id);
                }
                
                // If this is a subscription order, ensure the subscription has the account linked
                if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id)) {
                    $subscriptions = wcs_get_subscriptions_for_order($order_id);
                    foreach ($subscriptions as $subscription) {
                        if (function_exists('dbb_copy_account_data_to_subscription')) {
                            dbb_copy_account_data_to_subscription($subscription->get_id(), $order_id);
                        }
                    }
                }
            }
        }

        // Log successful payment completion (only if debug is enabled)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            dbb_log('Payment completed for order ' . $order_id, 'info');
        }
        
    } catch (Exception $e) {
        // Log the error but don't break the process
        if (function_exists('dbb_log')) {
            dbb_log('Error in payment completion handler: ' . $e->getMessage(), 'error');
        }
        
        // Add order note without throwing exception
        if ($order) {
            $order->add_order_note('Error during payment completion: ' . $e->getMessage());
        }
    }
}

// Add debug logging function
function dbb_log($message, $type = 'info') {
    if (defined('WP_DEBUG') && WP_DEBUG === true) {
        error_log('DBB Management [' . strtoupper($type) . ']: ' . $message);
    }
}

// Enqueue admin scripts and styles
function dbb_enqueue_admin_scripts($hook) {
    $screen = get_current_screen();
    
    // Only load on our plugin pages - NOT on WooCommerce pages
    if (strpos($hook, 'dbb-management') === false && 
        strpos($hook, 'account-manager') === false &&
        strpos($hook, 'redeem-key-manager') === false &&
        strpos($hook, 'sales-monitor') === false &&
        strpos($hook, 'dbb-whatsapp') === false &&
        strpos($hook, 'add-new-service') === false) {
        return;
    }
    
    // Enqueue minimal dashboard CSS with cache-busting version
    wp_enqueue_style(
        'dbb-minimal-dashboard', 
        DBB_PLUGIN_ASSETS_URL . 'css/minimal-dashboard.css', 
        array(), 
        filemtime(DBB_PLUGIN_ASSETS_DIR . 'css/minimal-dashboard.css')
    );
    
    // Only enqueue minimal custom admin styles if needed
    if (file_exists(DBB_PLUGIN_ASSETS_DIR . 'css/admin-style.css')) {
        wp_enqueue_style('dbb-admin-style', DBB_PLUGIN_ASSETS_URL . 'css/admin-style.css', array('dbb-minimal-dashboard'), '1.0.0');
    }
    
    // Enqueue common scripts only on plugin pages
    if (file_exists(DBB_PLUGIN_ASSETS_DIR . 'js/admin-script.js')) {
        wp_enqueue_script('dbb-admin-script', DBB_PLUGIN_ASSETS_URL . 'js/admin-script.js', array('jquery'), '1.0.0', true);
        
        // Localize script
        wp_localize_script('dbb-admin-script', 'dbbAdmin', array(
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dbb_admin_nonce')
        ));
    }
    
    // Enqueue Select2 if on WooCommerce order or subscription pages
    if ($screen->id === 'woocommerce_page_wc-orders' || 
        $screen->id === 'shop_order' || 
        $screen->id === 'shop_subscription' ||
        strpos($hook, 'subscription') !== false) {
        wp_enqueue_script('select2');
        wp_enqueue_style('select2');
        
        // Add custom CSS for subscription account manager
        wp_add_inline_style('dbb-admin-style', '
            /* Legacy styles - keeping for backward compatibility */
            .wsm-services-container {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 4px;
                padding: 20px;
                margin: 20px 0;
            }
            
            /* New simplified subscription account manager styles */
            .wsm-subscription-accounts {
                background: #fff;
                border: 1px solid #ddd;
                border-radius: 6px;
                padding: 20px;
                margin: 20px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            
            .wsm-section-title {
                margin: 0 0 20px 0;
                font-size: 16px;
                font-weight: 600;
                color: #1d2327;
                display: flex;
                align-items: center;
                gap: 8px;
                border-bottom: 1px solid #e1e5e9;
                padding-bottom: 12px;
            }
            
            .wsm-section-title .dashicons {
                color: #2271b1;
                font-size: 18px;
            }
            
            .wsm-accounts-grid {
                display: grid;
                gap: 16px;
                margin-bottom: 16px;
            }
            
            .wsm-simple-service {
                display: flex;
                align-items: center;
                gap: 12px;
                padding: 16px;
                background: #f8f9fa;
                border: 1px solid #e1e5e9;
                border-radius: 6px;
                transition: all 0.2s ease;
            }
            
            .wsm-simple-service:hover {
                background: #f1f3f4;
                border-color: #2271b1;
                box-shadow: 0 2px 4px rgba(34, 113, 177, 0.1);
            }
            
            .wsm-service-badge {
                display: flex;
                align-items: center;
                justify-content: center;
                width: 40px;
                height: 40px;
                border-radius: 50%;
                color: white;
                font-weight: 600;
                font-size: 14px;
                flex-shrink: 0;
                box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            }
            
            .wsm-service-info {
                flex: 1;
                min-width: 0;
            }
            
            .wsm-service-label {
                font-weight: 600;
                color: #1d2327;
                margin-bottom: 4px;
                font-size: 14px;
            }
            
            .wsm-account-selector {
                flex: 2;
                min-width: 200px;
            }
            
            .wsm-account-selector select {
                width: 100%;
                padding: 8px 12px;
                border: 1px solid #ddd;
                border-radius: 4px;
                background: white;
                font-size: 14px;
                transition: border-color 0.2s ease;
            }
            
            .wsm-account-selector select:focus {
                border-color: #2271b1;
                box-shadow: 0 0 0 1px #2271b1;
                outline: none;
            }
            
            .wsm-help-text {
                margin: 0;
                padding: 12px 16px;
                background: #f0f6fc;
                border: 1px solid #d0d7de;
                border-radius: 4px;
                color: #656d76;
                font-size: 13px;
                display: flex;
                align-items: center;
                gap: 8px;
            }
            
            .wsm-help-text .dashicons {
                color: #2271b1;
                font-size: 16px;
            }
            
            /* Responsive design */
            @media (max-width: 782px) {
                .wsm-simple-service {
                    flex-direction: column;
                    align-items: stretch;
                    gap: 12px;
                }
                
                .wsm-service-info {
                    text-align: center;
                }
                
                .wsm-account-selector {
                    min-width: auto;
                }
            }
            
            /* Select2 styling for subscription pages */
            .wsm-subscription-accounts .select2-container {
                width: 100% !important;
            }
            
            .wsm-subscription-accounts .select2-selection {
                border: 1px solid #ddd !important;
                border-radius: 4px !important;
                padding: 4px 8px !important;
                min-height: 36px !important;
            }
            
            .wsm-subscription-accounts .select2-selection:focus-within {
                border-color: #2271b1 !important;
                box-shadow: 0 0 0 1px #2271b1 !important;
            }
        ');
    }
}
add_action('admin_enqueue_scripts', 'dbb_enqueue_admin_scripts');

// Helper function to render the account manager main page
function wsm_render_account_main_page() {
    // Alias for the account manager main page in includes/account-manager.php
    if (function_exists('wsm_render_main_page')) {
        wsm_render_main_page();
    } else {
        echo '<div class="wrap"><p>Account Manager module not found.</p></div>';
    }
}

