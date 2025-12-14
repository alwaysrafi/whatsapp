<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Account Manager Module
 * Handles all functionality related to account management
 */

// Get available services
function wsm_get_available_services() {
    return get_option('wsm_registered_services', array(
        'netflix' => array(
            'name' => 'Netflix',
            'icon' => 'netflix-icon',
            'color' => '#e50914',
            'slug' => 'netflix-manager',
            'product_ids' => array()
        )
    ));
}

// Helper function to get order profile details
function wsm_get_order_profile_details($order_id, $service_slug) {
    global $wpdb;
    
    // Get service settings
    $services = get_option('wsm_registered_services', array());
    $service = null;
    
    // Find the current service
    foreach ($services as $key => $service_data) {
        if ($service_data['slug'] === $service_slug) {
            $service = $service_data;
            break;
        }
    }
    
    if (!$service || empty($service['meta_keys'])) {
        return array();
    }
    
    // Get profile details from order item meta
    $meta_values = array();
    
    // Trim meta keys and prepare for query
    $trimmed_meta_keys = array_map('trim', $service['meta_keys']);
    $meta_keys_list = "'" . implode("','", array_map('esc_sql', $trimmed_meta_keys)) . "'";
    
    $query = $wpdb->prepare(
        "SELECT meta_key, meta_value 
        FROM {$wpdb->prefix}woocommerce_order_itemmeta 
        WHERE order_item_id IN (
            SELECT order_item_id 
            FROM {$wpdb->prefix}woocommerce_order_items 
            WHERE order_id = %d
        ) 
        AND meta_key IN ($meta_keys_list)",
        $order_id
    );
    
    $results = $wpdb->get_results($query);
    
    foreach ($results as $result) {
        // Use trimmed meta key as array key
        $trimmed_key = trim($result->meta_key);
        $meta_values[$trimmed_key] = $result->meta_value;
    }
    
    return $meta_values;
}

// Helper function to get subscription dates
function wsm_get_subscription_dates($order_id) {
    $start_date = '';
    $end_date = '';
    
    // Check if WooCommerce Subscriptions is active
    if (class_exists('WC_Subscriptions')) {
        // Get subscriptions for this order
        $subscriptions = wcs_get_subscriptions_for_order($order_id);
        
        if (!empty($subscriptions)) {
            // Get the first subscription (usually there's only one)
            $subscription = reset($subscriptions);
            
            // Get start date
            $start_date = $subscription->get_date('start');
            if ($start_date) {
                $start_date = date('Y-m-d', strtotime($start_date));
            }
            
            // Get next payment date as end date
            $end_date = $subscription->get_date('next_payment');
            if ($end_date) {
                $end_date = date('Y-m-d', strtotime($end_date));
            }
        }
    }
    
    // If no subscription dates found, use order dates
    if (empty($start_date) || empty($end_date)) {
        $order = wc_get_order($order_id);
        if ($order) {
            $start_date = $order->get_date_created()->date('Y-m-d');
            // Set end date to 30 days from start if no subscription
            $end_date = date('Y-m-d', strtotime($start_date . ' +30 days'));
        }
    }
    
    return array(
        'start' => $start_date,
        'end' => $end_date
    );
}

// Register Custom Post Type
function wsm_register_custom_post_type() {
    // Get all registered services
    $services = get_option('wsm_registered_services', array());
    
    // Register post type for each service
    foreach ($services as $service_key => $service) {
        $args = array(
            'public' => false,      // Changed to false to hide completely from public
            'label'  => $service['name'] . ' Manager',
            'supports' => array('title'),
            'menu_icon' => 'dashicons-media-text',
            'show_ui' => true,
            'show_in_menu' => false, // Ensure it doesn't appear in admin menu
            'show_in_nav_menus' => false,
            'publicly_queryable' => false,
            'exclude_from_search' => true,
            'has_archive' => false,
            'rewrite' => false,
            'capability_type' => 'post',
            'capabilities' => array(
                'create_posts' => 'manage_options',
                'edit_post' => 'manage_options',
                'edit_posts' => 'manage_options',
                'edit_others_posts' => 'manage_options',
                'publish_posts' => 'manage_options',
                'read_post' => 'manage_options',
                'read_private_posts' => 'manage_options',
                'delete_post' => 'manage_options',
                'delete_posts' => 'manage_options'
            )
        );
        register_post_type($service['slug'], $args);
    }
}
add_action('init', 'wsm_register_custom_post_type', 0);

// Add Account Manager Menu and Submenus
function wsm_add_menu_items() {
    // Get all registered services
    $services = wsm_get_available_services();
    $service_slug = 'account-manager';
    
    // Add submenu items for each service under DBB Management
    // These won't show in menu due to our removal function, but will be accessible via URL
    foreach ($services as $service_key => $service) {
        add_submenu_page(
            'dbb-management',        // Parent slug (only under DBB Management)
            $service['name'] . ' Manager',
            $service['name'],
            'manage_options',
            $service['slug'],
            'wsm_render_service_page'
        );
    }

    // Add "Add New Service" submenu under DBB Management
    add_submenu_page(
        'dbb-management',          // Parent slug (only under DBB Management)
        'Add New Service',
        'Add New Service',
        'manage_options',
        'add-new-service',
        'wsm_render_add_service_page'
    );
    
    // Add Account Manager as a submenu under DBB Management (this is the main page)
    add_submenu_page(
        'dbb-management',          // Parent slug (only under DBB Management)
        'Account Manager',
        'Account Manager',
        'manage_options',
        'account-manager',
        'wsm_render_main_page'
    );
}
add_action('admin_menu', 'wsm_add_menu_items');

// Main Account Manager page
function wsm_render_main_page() {
    // Get registered services
    $services = get_option('wsm_registered_services', array(
        'netflix' => array(
            'name' => 'Netflix',
            'icon' => 'netflix-icon',
            'color' => '#e50914',
            'slug' => 'netflix-manager'
        )
    ));
    ?>
    <div class="wrap dbb-wrap">
        <!-- Header -->
        <div class="dbb-header">
            <h1>
                <span class="dashicons dashicons-admin-users"></span>
                Account Manager
            </h1>
            <p>Manage streaming service accounts, subscriptions, and customer assignments</p>
        </div>
        
        <div class="dbb-card">
            <div class="dbb-card-body">
            <?php dbb_display_breadcrumbs(); ?>
            <h2>Available Services</h2>
            <div class="service-grid">
                <?php foreach ($services as $service_key => $service): ?>
                <div class="service-card">
                    <div class="service-icon <?php echo esc_attr($service['icon']); ?>">
                        <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="<?php echo esc_attr($service['color']); ?>">
                            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8zm-1-13h2v6h-2zm0 8h2v2h-2z"/>
                        </svg>
                    </div>
                    <h3><?php echo esc_html($service['name']); ?></h3>
                    <p>Manage <?php echo esc_html($service['name']); ?> accounts and subscriptions</p>
                    <?php
                    // Display linked products
                    if (!empty($service['product_ids'])) {
                        $product_count = count($service['product_ids']);
                        echo '<p class="service-products-count"><span class="dashicons dashicons-cart"></span> Linked to ' . $product_count . ' product' . ($product_count > 1 ? 's' : '') . '</p>';
                    }
                    ?>
                    <div class="service-card-actions">
                        <a href="<?php echo admin_url('admin.php?page=dbb-management&service=' . $service_key); ?>" class="button button-primary">Manage Accounts</a>
                        <a href="<?php echo admin_url('admin.php?page=dbb-management&service=edit-service&service_key=' . $service_key); ?>" class="button">Edit Service</a>
                    </div>
                </div>
                <?php endforeach; ?>
                
                <div class="service-card add-new-service">
                    <div class="service-icon add-icon">
                        <span class="dashicons dashicons-plus-alt"></span>
                    </div>
                    <h3>Add New Service</h3>
                    <p>Add a new streaming service to manage</p>
                    <a href="<?php echo admin_url('admin.php?page=dbb-management&service=add-service'); ?>" class="button button-primary">Add Service</a>
                </div>
            </div>
            </div>
        </div>
    </div>
    <?php
}

// Add remove service scripts
function wsm_add_remove_service_scripts() {
    ?>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        $('.wsm-remove-service').on('click', function(e) {
            e.preventDefault();
            
            var serviceKey = $(this).data('service-key');
            if (!serviceKey) {
                alert('Error: No service selected');
                return;
            }
            
            if (!confirm('Are you sure you want to remove this service? This will delete all accounts and remove all order associations. This action cannot be undone.')) {
                return;
            }
    
            var $button = $(this);
            $button.prop('disabled', true).text('Removing...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wsm_remove_service',
                    service_key: serviceKey,
                    nonce: '<?php echo wp_create_nonce("wsm_remove_service"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        alert('Service successfully removed');
                        window.location.href = response.data.redirect;
                    } else {
                        alert('Error: ' + response.data);
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Remove Service');
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error processing request: ' + error);
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-trash"></span> Remove Service');
                }
            });
        });
    });
    </script>
    <?php
}
add_action('admin_footer', 'wsm_add_remove_service_scripts');

// Render service page (generic handler for all services)
function wsm_render_service_page() {
    // Get the current service from the current page
    $current_page = $_GET['page'];
    
    // Get service details
    $services = wsm_get_available_services();
    $current_service = null;
    $service_slug = '';
    
    // Find the current service
    foreach ($services as $key => $service) {
        if ($service['slug'] === $current_page) {
            $current_service = $service;
            $service_slug = $key;
            break;
        }
    }
    
    if (!$current_service) {
        echo '<div class="wrap dbb-wrap"><div class="dbb-card"><div class="dbb-card-body"><h1>Service Not Found</h1><p>The requested service does not exist.</p></div></div></div>';
        return;
    }
    
    $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'manage_posts';
    ?>
    <div class="wrap dbb-wrap">
        <!-- Header -->
        <div class="dbb-header">
            <h1>
                <span class="dashicons dashicons-admin-users"></span>
                <?php echo esc_html($current_service['name']); ?> Manager
            </h1>
            <p>Manage <?php echo esc_html($current_service['name']); ?> accounts and customer subscriptions</p>
        </div>
        
        <div class="dbb-card">
            <div class="dbb-card-body">
            <?php dbb_display_breadcrumbs(); ?>
            <button type="button" 
                    class="dbb-btn dbb-btn-danger wsm-remove-service" 
                    data-service-key="<?php echo esc_attr($service_slug); ?>"
                    style="float: right;">
                <span class="dashicons dashicons-trash"></span>
                Remove Service
            </button>
            <div style="clear: both;"></div>
        
        <nav class="nav-tab-wrapper">
            <a href="<?php echo esc_url(admin_url('admin.php?page=dbb-management&service=' . $service_slug . '&tab=manage_posts')); ?>" class="nav-tab <?php echo $active_tab == 'manage_posts' ? 'nav-tab-active' : ''; ?>">Add <?php echo esc_html($current_service['name']); ?> Account</a>
            <a href="<?php echo esc_url(admin_url('admin.php?page=dbb-management&service=' . $service_slug . '&tab=view_posts')); ?>" class="nav-tab <?php echo $active_tab == 'view_posts' ? 'nav-tab-active' : ''; ?>">View <?php echo esc_html($current_service['name']); ?> Accounts</a>
        </nav>
        
        <div class="tab-content">
            <?php
            if ($active_tab == 'manage_posts') {
                wsm_render_manage_posts_tab($current_page);
            } else {
                wsm_render_view_posts_tab($current_page);
            }
            ?>
        </div>
        </div>
        </div>
    </div>

    <!-- Remove Service Confirmation Modal -->
    <div id="wsm-remove-service-modal" class="wsm-modal" style="display: none;">
        <div class="wsm-modal-content">
            <span class="wsm-modal-close">&times;</span>
            <h2>Remove Service</h2>
            <p>Are you sure you want to remove this service? This will:</p>
            <ul>
                <li>Delete all accounts under this service</li>
                <li>Remove all order associations</li>
                <li>Remove the service from the Account Manager</li>
            </ul>
            <p><strong>This action cannot be undone.</strong></p>
            <div class="form-actions">
                <button type="button" class="button button-primary wsm-confirm-remove-service">Yes, Remove Service</button>
                <button type="button" class="button wsm-modal-cancel">Cancel</button>
            </div>
        </div>
    </div>
    
    <!-- Edit Account Modal -->
    <div id="wsm-edit-account-modal" class="wsm-modal" style="display: none;">
        <div class="wsm-modal-content">
            <span class="wsm-modal-close">&times;</span>
            <h2>Edit Account</h2>
            <form id="wsm-edit-account-form">
                <input type="hidden" id="edit_account_id" name="post_id">
                <table class="form-table">
                    <tr>
                        <th><label for="edit_account_email">Email</label></th>
                        <td>
                            <input type="email" id="edit_account_email" name="email" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_account_hash">Password</label></th>
                        <td>
                            <input type="text" id="edit_account_hash" name="hash" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_account_expiry_date">Expiry Date</label></th>
                        <td>
                            <input type="date" id="edit_account_expiry_date" name="expiry_date" class="regular-text" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_account_slots">Total Slots</label></th>
                        <td>
                            <input type="number" id="edit_account_slots" name="slots" class="regular-text" min="1" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_update_reason">Update Reason</label></th>
                        <td>
                            <textarea id="edit_update_reason" name="update_reason" class="regular-text" rows="3" required></textarea>
                            <p class="description">Please provide a reason for updating this account</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label>Last Updated</label></th>
                        <td>
                            <span id="edit_last_updated" class="last-updated-time"></span>
                        </td>
                    </tr>
                </table>
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Update Account</button>
                    <button type="button" class="button wsm-modal-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <!-- Edit Order Modal -->
    <div id="wsm-edit-order-modal" class="wsm-modal" style="display: none;">
        <div class="wsm-modal-content">
            <span class="wsm-modal-close">&times;</span>
            <h2>Edit Order Details</h2>
            <form id="wsm-edit-order-form">
                <input type="hidden" id="edit_order_id" name="order_id">
                <table class="form-table">
                    <?php if (!empty($current_service['meta_keys'])): ?>
                        <?php foreach ($current_service['meta_keys'] as $meta_key): ?>
                            <?php $field_id = 'edit_' . strtolower(str_replace(' ', '_', $meta_key)); ?>
                            <tr>
                                <th><label for="<?php echo esc_attr($field_id); ?>"><?php echo esc_html($meta_key); ?></label></th>
                                <td>
                                    <input type="text" id="<?php echo esc_attr($field_id); ?>" name="<?php echo esc_attr($field_id); ?>" class="regular-text">
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </table>
                <div class="form-actions">
                    <button type="submit" class="button button-primary">Update Order</button>
                    <button type="button" class="button wsm-modal-cancel">Cancel</button>
                </div>
            </form>
        </div>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Handle account update form submission
        $('#wsm-edit-account-form').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            var $submitButton = $(this).find('button[type="submit"]');
            
            $submitButton.prop('disabled', true).text('Updating...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wsm_update_account',
                    nonce: dbbAdmin.nonce,
                    post_id: $('#edit_account_id').val(),
                    email: $('#edit_account_email').val(),
                    hash: $('#edit_account_hash').val(),
                    expiry_date: $('#edit_account_expiry_date').val(),
                    slots: $('#edit_account_slots').val(),
                    update_reason: $('#edit_update_reason').val()
                },
                success: function(response) {
                    if (response.success) {
                        alert('Account updated successfully');
                        $('#wsm-edit-account-modal').fadeOut(300);
                        // Reload page to show updated data
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                    $submitButton.prop('disabled', false).text('Update Account');
                },
                error: function() {
                    alert('Error processing request');
                    $submitButton.prop('disabled', false).text('Update Account');
                }
            });
        });
        
        // Handle order update form submission
        $('#wsm-edit-order-form').on('submit', function(e) {
            e.preventDefault();
            
            var formData = $(this).serialize();
            var $submitButton = $(this).find('button[type="submit"]');
            
            $submitButton.prop('disabled', true).text('Updating...');
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData + '&action=wsm_update_order_details&nonce=' + dbbAdmin.nonce,
                success: function(response) {
                    if (response.success) {
                        alert('Order details updated successfully');
                        $('#wsm-edit-order-modal').fadeOut(300);
                        // Reload page to show updated data
                        location.reload();
                    } else {
                        alert('Error: ' + response.data);
                    }
                    $submitButton.prop('disabled', false).text('Update Order');
                },
                error: function() {
                    alert('Error processing request');
                    $submitButton.prop('disabled', false).text('Update Order');
                }
            });
        });
        
        // Handle WhatsApp button clicks
        $(document).on('click', '.wsm-send-whatsapp', function() {
            var $button = $(this);
            var phone = $button.data('phone');
            var orderId = $button.data('order-id');
            var customerName = $button.data('customer-name');
            
            // Show WhatsApp modal
            var modal = $('<div class="wsm-whatsapp-modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">' +
                '<div class="wsm-whatsapp-modal-content" style="background-color: #fff; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; position: relative;">' +
                '<span class="wsm-whatsapp-close" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>' +
                '<h3><span class="dashicons dashicons-whatsapp"></span> Send WhatsApp Message to ' + customerName + '</h3>' +
                '<form id="wsm-whatsapp-form">' +
                '<table class="form-table">' +
                '<tr><th>Phone Number:</th><td><input type="text" id="whatsapp_phone" class="regular-text" value="' + phone + '" required></td></tr>' +
                '<tr><th>Message:</th><td><textarea id="whatsapp_message" rows="8" cols="50" class="large-text" required></textarea></td></tr>' +
                '</table>' +
                '<p class="submit">' +
                '<button type="submit" class="button button-primary">Send WhatsApp Message</button> ' +
                '<button type="button" class="button wsm-whatsapp-close">Cancel</button>' +
                '</p>' +
                '</form>' +
                '</div>' +
                '</div>');
            
            $('body').append(modal);
            modal.fadeIn(300);
            
            // Load default message template
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dbb_get_order_whatsapp_template',
                    order_id: orderId,
                    nonce: '<?php echo wp_create_nonce("dbb_whatsapp_template"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#whatsapp_message').val(response.data.message);
                    }
                }
            });
            
            // Close modal
            $('.wsm-whatsapp-close').on('click', function() {
                modal.fadeOut(300, function() {
                    modal.remove();
                });
            });
            
            // Send WhatsApp message
            $('#wsm-whatsapp-form').on('submit', function(e) {
                e.preventDefault();
                
                var phoneNumber = $('#whatsapp_phone').val();
                var message = $('#whatsapp_message').val();
                
                if (!phoneNumber || !message) {
                    alert('Please fill in all fields');
                    return;
                }
                
                var $submitBtn = $(this).find('button[type="submit"]');
                $submitBtn.prop('disabled', true).text('Sending...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dbb_send_whatsapp_message',
                        phone_number: phoneNumber,
                        message: message,
                        order_id: orderId,
                        nonce: '<?php echo wp_create_nonce("dbb_whatsapp_send"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.url) {
                                // WhatsApp Web - open URL
                                window.open(response.data.url, '_blank');
                                alert('WhatsApp Web opened. Please send the message manually.');
                            } else {
                                alert('WhatsApp message sent successfully!');
                            }
                            modal.fadeOut(300, function() {
                                modal.remove();
                            });
                        } else {
                            alert('Error: ' + response.data);
                        }
                        $submitBtn.prop('disabled', false).text('Send WhatsApp Message');
                    },
                    error: function() {
                        alert('Error sending WhatsApp message');
                        $submitBtn.prop('disabled', false).text('Send WhatsApp Message');
                    }
                });
            });
        });
    });
    </script>
    <?php
}

// Add service-specific field to WooCommerce order details page
function wsm_add_service_manager_to_order_data($order) {
    // Check if we're in admin
    if (!is_admin()) {
        return;
    }
    
    // Get all registered services
    $services = get_option('wsm_registered_services', array());
    
    // Get current order ID
    $order_id = $order->get_id();
    if (!$order_id) return;

    ?>
    <div class="form-field form-field-wide wsm-services-container">
        <h3>Account Manager Services</h3>
        <div id="wsm-service-accounts">
            <?php
            // Display existing linked services and accounts
            foreach ($services as $service_key => $service) {
                $linked_post_id = get_post_meta($order_id, '_linked_' . $service_key . '_post', true);
                if ($linked_post_id) {
                    wsm_render_service_account_row($service_key, $service, $order_id, $linked_post_id);
                }
            }
            ?>
        </div>

        <!-- Add New Service Button -->
        <div class="wsm-add-service-wrapper">
            <select id="wsm-service-selector" class="wc-enhanced-select">
                <option value="">-- Select Service --</option>
                <?php foreach ($services as $service_key => $service): ?>
                    <option value="<?php echo esc_attr($service_key); ?>"
                            data-name="<?php echo esc_attr($service['name']); ?>"
                            data-color="<?php echo esc_attr($service['color']); ?>">
                        <?php echo esc_html($service['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            <button type="button" class="button wsm-add-service-btn">
                <span class="dashicons dashicons-plus-alt2"></span> Add Service
            </button>
        </div>
    </div>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Initialize Select2 for service selector
        $('#wsm-service-selector').select2({
            width: '100%',
            placeholder: 'Select a service'
        });

        // Add new service row
        $('.wsm-add-service-btn').on('click', function() {
            var $selector = $('#wsm-service-selector');
            var serviceKey = $selector.val();
            
            if (!serviceKey) {
                alert('Please select a service first');
                return;
            }

            var $selectedOption = $selector.find('option:selected');
            var serviceName = $selectedOption.data('name');
            var serviceColor = $selectedOption.data('color');

            // Check if service is already added
            if ($('.wsm-service-row[data-service="' + serviceKey + '"]').length > 0) {
                alert('This service is already added');
                return;
            }

            // Add new service row via AJAX
            $.post(ajaxurl, {
                action: 'wsm_get_service_row',
                service_key: serviceKey,
                order_id: '<?php echo esc_js($order_id); ?>',
                nonce: '<?php echo wp_create_nonce("wsm_get_service_row"); ?>'
            }, function(response) {
                if (response.success) {
                    $('#wsm-service-accounts').append(response.data);
                    // Reinitialize Select2 for new dropdowns
                    $('.wsm-account-select').select2({
                        width: '100%'
                    });
                }
            });

            // Reset service selector
            $selector.val('').trigger('change');
        });

        // Remove service row
        $(document).on('click', '.wsm-remove-service-row', function() {
            if (confirm('Are you sure you want to remove this service?')) {
                $(this).closest('.wsm-service-row').remove();
            }
        });
    });
    </script>
    <?php
}

// Helper function to render a service account row
function wsm_render_service_account_row($service_key, $service, $order_id, $selected_post_id = '') {
    // Get all accounts for this service
    $service_posts = get_posts(array(
        'post_type' => $service['slug'],
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'suppress_filters' => true
    ));

    ?>
    <div class="wsm-service-row" data-service="<?php echo esc_attr($service_key); ?>">
        <button type="button" class="wsm-remove-service-row">
            <span class="dashicons dashicons-no-alt"></span>
        </button>
        
        <div class="wsm-service-header">
            <div class="wsm-service-icon" style="background-color: <?php echo esc_attr($service['color']); ?>">
                <span class="dashicons dashicons-admin-users"></span>
            </div>
            <span class="wsm-service-name"><?php echo esc_html($service['name']); ?></span>
        </div>

        <select name="linked_<?php echo esc_attr($service_key); ?>_post" 
                class="wsm-account-select wc-enhanced-select"
                data-placeholder="Select <?php echo esc_attr($service['name']); ?> Account">
            <option value=""><?php echo esc_html__('-- Select Account --', 'woocommerce'); ?></option>
            <?php 
            foreach ($service_posts as $service_post):
                $email = get_post_meta($service_post->ID, '_summary_email', true);
                $hash = get_post_meta($service_post->ID, '_summary_hash', true);
                $slots = get_post_meta($service_post->ID, '_summary_slots', true);
                $order_ids = get_post_meta($service_post->ID, '_summary_order_ids', true);
                $used_slots = $order_ids ? count(explode(',', $order_ids)) : 0;
                $remaining_slots = max(0, intval($slots) - $used_slots);
                
                // Skip if no slots available and not currently selected
                if ($remaining_slots <= 0 && $selected_post_id != $service_post->ID) {
                    continue;
                }
                
                $selected = selected($selected_post_id, $service_post->ID, false);
            ?>
                <option value="<?php echo esc_attr($service_post->ID); ?>" <?php echo $selected; ?>>
                    <?php echo esc_html($email . ' - ' . $hash . ' (Slots: ' . $remaining_slots . ' available)'); ?>
                </option>
            <?php endforeach; ?>
        </select>

        <div class="wsm-service-details">
            <span class="dashicons dashicons-info"></span>
            Select an account to link with this order. Only accounts with available slots are shown.
        </div>
    </div>
    <?php
}

// Add service-specific field to WooCommerce subscription details page
// This function has been replaced with wsm_add_subscription_account_manager_section
// which provides better integration with the subscription admin interface

// Helper function to render a simple service row for subscriptions  
// This function has been replaced with wsm_render_subscription_account_table_row
// which provides better integration with the subscription admin interface

// Register necessary hooks
// Removed: add_action('woocommerce_admin_order_data_after_billing_address', 'wsm_add_service_manager_to_order_data', 10, 1);
add_action('woocommerce_process_shop_order_meta', 'wsm_save_linked_account', 10, 1);

// Add hooks for WooCommerce Subscriptions admin pages - using proper subscription hooks
// add_action('woocommerce_subscription_details_after_subscription_table', 'wsm_add_subscription_account_manager_section', 10, 1); // This is for frontend, not admin
add_action('woocommerce_process_subscription_meta', 'wsm_save_subscription_meta_data', 10, 1);

// New subscription account manager section
function wsm_add_subscription_account_manager_section($subscription) {
    // Check if we're in admin
    if (!is_admin()) {
        return;
    }
    
    // Get all registered services
    $services = get_option('wsm_registered_services', array());
    if (empty($services)) {
        return;
    }
    
    // Get current subscription ID
    $subscription_id = $subscription->get_id();
    if (!$subscription_id) return;

    ?>
    <div class="woocommerce-subscription-account-manager">
        <?php wp_nonce_field('wsm_save_subscription_data', 'wsm_subscription_nonce'); ?>
        
        <h3 class="subscription-account-manager-title">
            <span class="dashicons dashicons-admin-users"></span>
            Account Manager
            <button type="button" class="button button-secondary wsm-toggle-edit-mode" style="float: right;">
                <span class="dashicons dashicons-edit"></span> Modify Assignments
            </button>
        </h3>
        
        <table class="subscription-account-table widefat">
            <thead>
                <tr>
                    <th>Service</th>
                    <th>Assigned Account</th>
                    <th class="wsm-edit-mode-only" style="display: none;">Change Assignment</th>
                </tr>
            </thead>
            <tbody>
                <?php
                foreach ($services as $service_key => $service) {
                    $linked_post_id = get_post_meta($subscription_id, '_linked_' . $service_key . '_post', true);
                    wsm_render_subscription_account_table_row($service_key, $service, $subscription_id, $linked_post_id);
                }
                ?>
            </tbody>
        </table>
        
        <div class="wsm-edit-mode-only subscription-account-actions" style="display: none;">
            <p class="description">
                <span class="dashicons dashicons-info"></span>
                Select accounts to assign to this subscription. Only accounts with available slots are shown.
            </p>
            <button type="submit" class="button button-primary">Save Account Assignments</button>
            <button type="button" class="button wsm-cancel-edit-mode">Cancel</button>
            <button type="button" class="button button-secondary wsm-send-whatsapp-notification" style="margin-left: 10px;">
                <span class="dashicons dashicons-whatsapp"></span> Send WhatsApp Notification
            </button>
        </div>
    </div>

    <style>
    .woocommerce-subscription-account-manager {
        margin: 20px 0;
        background: #fff;
        border: 1px solid #ddd;
        border-radius: 4px;
        overflow: hidden;
    }
    
    .subscription-account-manager-title {
        margin: 0;
        padding: 15px 20px;
        background: #f8f9fa;
        border-bottom: 1px solid #ddd;
        font-size: 14px;
        font-weight: 600;
        color: #1d2327;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .subscription-account-manager-title .dashicons {
        color: #2271b1;
        font-size: 16px;
    }
    
    .subscription-account-table {
        margin: 0;
        border: none;
        border-radius: 0;
    }
    
    .subscription-account-table th,
    .subscription-account-table td {
        padding: 12px 20px;
        border-bottom: 1px solid #f0f0f1;
        vertical-align: middle;
    }
    
    .subscription-account-table th {
        background: #f8f9fa;
        font-weight: 600;
        color: #1d2327;
    }
    
    .subscription-account-table tbody tr:last-child td {
        border-bottom: none;
    }
    
    .service-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 32px;
        height: 32px;
        border-radius: 50%;
        color: white;
        font-weight: 600;
        font-size: 12px;
        margin-right: 10px;
        vertical-align: middle;
    }
    
    .service-info {
        display: inline-flex;
        align-items: center;
    }
    
    .service-name {
        font-weight: 600;
        color: #1d2327;
    }
    
    .account-info {
        color: #50575e;
    }
    
    .account-info.no-account {
        color: #d63638;
        font-style: italic;
    }
    
    .account-selector {
        min-width: 250px;
    }
    
    .account-selector select {
        width: 100%;
        padding: 6px 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        background: white;
    }
    
    .account-selector select:focus {
        border-color: #2271b1;
        box-shadow: 0 0 0 1px #2271b1;
        outline: none;
    }
    
    .subscription-account-actions {
        padding: 15px 20px;
        background: #f8f9fa;
        border-top: 1px solid #ddd;
    }
    
    .subscription-account-actions .description {
        margin: 0 0 15px 0;
        display: flex;
        align-items: center;
        gap: 8px;
        color: #646970;
    }
    
    .subscription-account-actions .dashicons {
        color: #2271b1;
    }
    
    .wsm-edit-mode-only {
        display: none;
    }
    
    .wsm-edit-mode .wsm-edit-mode-only {
        display: table-cell !important;
    }
    
    .wsm-edit-mode .subscription-account-actions {
        display: block !important;
    }
    </style>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Toggle edit mode
        $('.wsm-toggle-edit-mode').on('click', function() {
            var $container = $('.woocommerce-subscription-account-manager');
            var $button = $(this);
            
            if ($container.hasClass('wsm-edit-mode')) {
                // Exit edit mode
                $container.removeClass('wsm-edit-mode');
                $button.html('<span class="dashicons dashicons-edit"></span> Modify Assignments');
                $('.wsm-edit-mode-only').hide();
            } else {
                // Enter edit mode
                $container.addClass('wsm-edit-mode');
                $button.html('<span class="dashicons dashicons-no-alt"></span> Cancel');
                $('.wsm-edit-mode-only').show();
                
                // Initialize Select2 if available
                if ($.fn.select2) {
                    $('.account-selector select').select2({
                        width: '100%',
                        placeholder: 'Select account...'
                    });
                }
            }
        });
        
        // Cancel edit mode
        $('.wsm-cancel-edit-mode').on('click', function() {
            $('.wsm-toggle-edit-mode').click();
        });
        
        // Send WhatsApp notification
        $('.wsm-send-whatsapp-notification').on('click', function() {
            var subscriptionId = '<?php echo esc_js($subscription_id); ?>';
            
            // Show WhatsApp modal
            var modal = $('<div class="wsm-whatsapp-modal" style="display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">' +
                '<div class="wsm-whatsapp-modal-content" style="background-color: #fff; margin: 5% auto; padding: 20px; border-radius: 8px; width: 80%; max-width: 600px; position: relative;">' +
                '<span class="wsm-whatsapp-close" style="color: #aaa; float: right; font-size: 28px; font-weight: bold; cursor: pointer;">&times;</span>' +
                '<h3><span class="dashicons dashicons-whatsapp"></span> Send WhatsApp Message</h3>' +
                '<form id="wsm-whatsapp-form">' +
                '<table class="form-table">' +
                '<tr><th>Phone Number:</th><td><input type="text" id="whatsapp_phone" class="regular-text" placeholder="+1234567890" required></td></tr>' +
                '<tr><th>Message:</th><td><textarea id="whatsapp_message" rows="8" cols="50" class="large-text" required></textarea></td></tr>' +
                '</table>' +
                '<p class="submit">' +
                '<button type="submit" class="button button-primary">Send WhatsApp Message</button> ' +
                '<button type="button" class="button wsm-whatsapp-close">Cancel</button>' +
                '</p>' +
                '</form>' +
                '</div>' +
                '</div>');
            
            $('body').append(modal);
            modal.fadeIn(300);
            
            // Load default message template
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'dbb_get_whatsapp_template',
                    subscription_id: subscriptionId,
                    nonce: '<?php echo wp_create_nonce("dbb_whatsapp_template"); ?>'
                },
                success: function(response) {
                    if (response.success) {
                        $('#whatsapp_phone').val(response.data.phone);
                        $('#whatsapp_message').val(response.data.message);
                    }
                }
            });
            
            // Close modal
            $('.wsm-whatsapp-close').on('click', function() {
                modal.fadeOut(300, function() {
                    modal.remove();
                });
            });
            
            // Send WhatsApp message
            $('#wsm-whatsapp-form').on('submit', function(e) {
                e.preventDefault();
                
                var phone = $('#whatsapp_phone').val();
                var message = $('#whatsapp_message').val();
                
                if (!phone || !message) {
                    alert('Please fill in all fields');
                    return;
                }
                
                var $submitBtn = $(this).find('button[type="submit"]');
                $submitBtn.prop('disabled', true).text('Sending...');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dbb_send_whatsapp_message',
                        phone_number: phone,
                        message: message,
                        order_id: subscriptionId,
                        nonce: '<?php echo wp_create_nonce("dbb_whatsapp_send"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            if (response.data.url) {
                                // WhatsApp Web - open URL
                                window.open(response.data.url, '_blank');
                                alert('WhatsApp Web opened. Please send the message manually.');
                            } else {
                                alert('WhatsApp message sent successfully!');
                            }
                            modal.fadeOut(300, function() {
                                modal.remove();
                            });
                        } else {
                            alert('Error: ' + response.data);
                        }
                        $submitBtn.prop('disabled', false).text('Send WhatsApp Message');
                    },
                    error: function() {
                        alert('Error sending WhatsApp message');
                        $submitBtn.prop('disabled', false).text('Send WhatsApp Message');
                    }
                });
            });
        });
    });
    </script>
    <?php
}

// Helper function to render subscription account table row
function wsm_render_subscription_account_table_row($service_key, $service, $subscription_id, $selected_post_id = '') {
    // Get all accounts for this service with available slots
    $service_posts = get_posts(array(
        'post_type' => $service['slug'],
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'suppress_filters' => true
    ));

    // Get service initials for badge
    $service_initials = '';
    $words = explode(' ', $service['name']);
    foreach ($words as $word) {
        $service_initials .= strtoupper(substr($word, 0, 1));
    }
    if (strlen($service_initials) > 3) {
        $service_initials = substr($service_initials, 0, 3);
    }

    // Get current account info
    $current_account_info = 'No account assigned';
    $current_account_class = 'no-account';
    
    if ($selected_post_id) {
        $email = get_post_meta($selected_post_id, '_summary_email', true);
        $hash = get_post_meta($selected_post_id, '_summary_hash', true);
        if ($email) {
            $current_account_info = $email . ($hash ? ' - ' . $hash : '');
            $current_account_class = '';
        }
    }

    ?>
    <tr>
        <td>
            <div class="service-info">
                <div class="service-badge" style="background-color: <?php echo esc_attr($service['color']); ?>">
                    <?php echo esc_html($service_initials); ?>
                </div>
                <span class="service-name"><?php echo esc_html($service['name']); ?></span>
            </div>
        </td>
        <td>
            <div class="account-info <?php echo esc_attr($current_account_class); ?>">
                <?php echo esc_html($current_account_info); ?>
            </div>
        </td>
        <td class="wsm-edit-mode-only" style="display: none;">
            <div class="account-selector">
                <select name="linked_<?php echo esc_attr($service_key); ?>_post">
                    <option value="">No account assigned</option>
                    <?php 
                    foreach ($service_posts as $service_post):
                        $email = get_post_meta($service_post->ID, '_summary_email', true);
                        $hash = get_post_meta($service_post->ID, '_summary_hash', true);
                        $slots = get_post_meta($service_post->ID, '_summary_slots', true);
                        $order_ids = get_post_meta($service_post->ID, '_summary_order_ids', true);
                        $used_slots = $order_ids ? count(explode(',', $order_ids)) : 0;
                        $remaining_slots = max(0, intval($slots) - $used_slots);
                        
                        // Skip if no slots available and not currently selected
                        if ($remaining_slots <= 0 && $selected_post_id != $service_post->ID) {
                            continue;
                        }
                        
                        $selected = selected($selected_post_id, $service_post->ID, false);
                        $display_text = $email . ($hash ? ' - ' . $hash : '') . ' (' . $remaining_slots . ' slots available)';
                    ?>
                        <option value="<?php echo esc_attr($service_post->ID); ?>" <?php echo $selected; ?>>
                            <?php echo esc_html($display_text); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </td>
    </tr>
    <?php
}

// Save subscription meta data
function wsm_save_subscription_meta_data($subscription_id) {
    // Verify nonce
    if (!isset($_POST['wsm_subscription_nonce']) || !wp_verify_nonce($_POST['wsm_subscription_nonce'], 'wsm_save_subscription_data')) {
        return;
    }
    
    // Get the subscription
    $subscription = wcs_get_subscription($subscription_id);
    if (!$subscription) {
        return;
    }
    
    // Process the form data (this will be handled by existing functions)
    wsm_handle_subscription_update($subscription_id);
}

// Add Account Manager module to the DBB modules list
function wsm_register_in_dbb_modules($modules) {
    $modules['account_manager'] = array(
        'name' => 'Account Manager',
        'description' => 'Manage streaming accounts, subscriptions, and user assignments',
        'slug' => 'account-manager',
        'icon' => 'account-manager-icon',
        'dashicon' => 'dashicons-admin-users'
    );
    
    return $modules;
}
add_filter('dbb_modules', 'wsm_register_in_dbb_modules'); 

// Also add hook for HPOS subscription processing
add_action('woocommerce_process_shop_subscription_meta', 'wsm_save_subscription_meta_data', 10, 1);
add_action('woocommerce_update_subscription', 'wsm_save_subscription_meta_data', 10, 1);

// Add hooks for WooCommerce Subscriptions admin pages - using proper admin hooks
add_action('woocommerce_admin_order_data_after_billing_address', 'wsm_add_subscription_account_manager_section_admin', 10, 1);
add_action('woocommerce_process_subscription_meta', 'wsm_save_subscription_meta_data', 10, 1);
add_action('woocommerce_process_shop_subscription_meta', 'wsm_save_subscription_meta_data', 10, 1);

// Admin version that only shows for subscriptions
function wsm_add_subscription_account_manager_section_admin($order) {
    // Only show for subscriptions, not regular orders
    if (!$order || $order->get_type() !== 'shop_subscription') {
        return;
    }
    
    // Call the main function
    wsm_add_subscription_account_manager_section($order);
} 