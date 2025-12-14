<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Account Manager Module - Additional Functions
 * Contains supporting functions for the account manager module
 */

// AJAX handler for getting service row HTML
add_action('wp_ajax_wsm_get_service_row', 'wsm_get_service_row_handler');
function wsm_get_service_row_handler() {
    check_ajax_referer('wsm_get_service_row', 'nonce');
    
    $service_key = isset($_POST['service_key']) ? sanitize_text_field($_POST['service_key']) : '';
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    if (empty($service_key) || empty($order_id)) {
        wp_send_json_error('Missing required parameters');
    }
    
    $services = get_option('wsm_registered_services', array());
    if (!isset($services[$service_key])) {
        wp_send_json_error('Service not found');
    }
    
    ob_start();
    wsm_render_service_account_row($service_key, $services[$service_key], $order_id);
    $html = ob_get_clean();
    
    wp_send_json_success($html);
}

// Render Add New Service page
function wsm_render_add_service_page() {
    if (isset($_POST['wsm_add_service'])) {
        $service_name = sanitize_text_field($_POST['service_name']);
        $service_slug = sanitize_title($service_name) . '-manager';
        $service_color = sanitize_hex_color($_POST['service_color']);
        
        // Get meta keys and trim whitespace
        $meta_keys = array();
        if (!empty($_POST['meta_keys'])) {
            foreach ($_POST['meta_keys'] as $key) {
                $sanitized_key = trim(sanitize_text_field($key));
                if (!empty($sanitized_key)) {
                    $meta_keys[] = $sanitized_key;
                }
            }
        }
        
        // Get selected product IDs
        $product_ids = array();
        if (!empty($_POST['product_ids'])) {
            foreach ($_POST['product_ids'] as $product_id) {
                $product_id = intval($product_id);
                if ($product_id > 0) {
                    $product_ids[] = $product_id;
                }
            }
        }
        
        // Get existing services
        $services = get_option('wsm_registered_services', array());
        
        // Add new service
        $services[$service_slug] = array(
            'name' => $service_name,
            'icon' => sanitize_title($service_name) . '-icon',
            'color' => $service_color,
            'slug' => $service_slug,
            'meta_keys' => $meta_keys,
            'product_ids' => $product_ids
        );
        
        // Save updated services
        update_option('wsm_registered_services', $services);
        
        // Register new post type for the service
        wsm_register_service_post_type($service_slug, $service_name);
        
        echo '<div class="notice notice-success"><p>Service added successfully!</p></div>';
    }
    ?>
    <div class="wrap">
        <?php dbb_display_breadcrumbs(); ?>
        <h1>Add New Service</h1>
    <form method="post" action="">
        <table class="form-table">
            <tr>
                    <th><label for="service_name">Service Name</label></th>
                    <td>
                        <input type="text" name="service_name" id="service_name" class="regular-text" required>
                        <p class="description">Enter the name of the streaming service (e.g., Disney+, Hulu, etc.)</p>
                    </td>
            </tr>
            <tr>
                    <th><label for="service_color">Service Color</label></th>
                    <td>
                        <input type="color" name="service_color" id="service_color" value="#2271b1" required>
                        <p class="description">Choose a color for the service icon and UI elements</p>
                    </td>
            </tr>
            <tr>
                    <th><label>Order Meta Keys</label></th>
                    <td>
                        <div id="meta-keys-container">
                            <div class="meta-key-row">
                                <input type="text" name="meta_keys[]" class="regular-text" placeholder="Enter WooCommerce order meta key">
                                <button type="button" class="button add-meta-key">Add Another Meta Key</button>
                            </div>
                        </div>
                        <p class="description">Enter the WooCommerce order meta keys to display in the accounts view (e.g., Profile Name, Profile Pin)</p>
                    </td>
            </tr>
            <tr>
                    <th><label for="product_ids">Link to Products</label></th>
                    <td>
                        <select name="product_ids[]" id="product_ids" class="wsm-product-selector" multiple style="width: 100%; min-height: 200px;">
                            <?php
                            $products = wc_get_products(array(
                                'limit' => -1,
                                'status' => 'publish',
                                'orderby' => 'name',
                                'order' => 'ASC'
                            ));
                            foreach ($products as $product) {
                                echo '<option value="' . esc_attr($product->get_id()) . '">' . esc_html($product->get_name()) . ' (#' . $product->get_id() . ')</option>';
                            }
                            ?>
                        </select>
                        <p class="description">Select which WooCommerce products should use this service for account management. Hold Ctrl (Windows) or Cmd (Mac) to select multiple products.</p>
                    </td>
            </tr>
        </table>
        <p class="submit">
                <input type="submit" name="wsm_add_service" class="button button-primary" value="Add Service">
        </p>
    </form>
    </div>
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Add meta key field
        $('.add-meta-key').on('click', function() {
            var newRow = $('<div class="meta-key-row"></div>');
            newRow.append('<input type="text" name="meta_keys[]" class="regular-text" placeholder="Enter WooCommerce order meta key">');
            newRow.append('<button type="button" class="button remove-meta-key">Remove</button>');
            $('#meta-keys-container').append(newRow);
        });
        
        // Remove meta key field
        $(document).on('click', '.remove-meta-key', function() {
            $(this).closest('.meta-key-row').remove();
        });
        
        // WhatsApp handlers removed
    });
    </script>
    <?php
}

// Render Edit Service page
function wsm_render_edit_service_page() {
    $service_key = isset($_GET['service_key']) ? sanitize_text_field($_GET['service_key']) : '';
    
    if (empty($service_key)) {
        echo '<div class="wrap dbb-wrap"><div class="notice notice-error"><p>No service specified.</p></div></div>';
        return;
    }
    
    $services = get_option('wsm_registered_services', array());
    
    if (!isset($services[$service_key])) {
        echo '<div class="wrap dbb-wrap"><div class="notice notice-error"><p>Service not found.</p></div></div>';
        return;
    }
    
    $service = $services[$service_key];
    
    // Handle form submission
    if (isset($_POST['wsm_update_service'])) {
        $service_name = sanitize_text_field($_POST['service_name']);
        $service_color = sanitize_hex_color($_POST['service_color']);
        
        // Get meta keys and trim whitespace
        $meta_keys = array();
        if (!empty($_POST['meta_keys'])) {
            foreach ($_POST['meta_keys'] as $key) {
                $sanitized_key = trim(sanitize_text_field($key));
                if (!empty($sanitized_key)) {
                    $meta_keys[] = $sanitized_key;
                }
            }
        }
        
        // Get selected product IDs
        $product_ids = array();
        if (!empty($_POST['product_ids'])) {
            foreach ($_POST['product_ids'] as $product_id) {
                $product_id = intval($product_id);
                if ($product_id > 0) {
                    $product_ids[] = $product_id;
                }
            }
        }
        
        // Update service
        $services[$service_key]['name'] = $service_name;
        $services[$service_key]['color'] = $service_color;
        $services[$service_key]['meta_keys'] = $meta_keys;
        $services[$service_key]['product_ids'] = $product_ids;
        
        // Save updated services
        update_option('wsm_registered_services', $services);
        
        // Refresh service data
        $service = $services[$service_key];
        
        echo '<div class="notice notice-success"><p>Service updated successfully!</p></div>';
    }
    ?>
    <div class="wrap dbb-wrap">
        <div class="dbb-header">
            <h1>
                <span class="dashicons dashicons-edit"></span>
                Edit Service: <?php echo esc_html($service['name']); ?>
            </h1>
            <p>Update service settings and linked products</p>
        </div>
        
        <div class="dbb-card">
            <div class="dbb-card-body">
                <?php dbb_display_breadcrumbs(); ?>
                
                <form method="post" action="">
                    <table class="form-table">
                        <tr>
                            <th><label for="service_name">Service Name</label></th>
                            <td>
                                <input type="text" name="service_name" id="service_name" class="regular-text" value="<?php echo esc_attr($service['name']); ?>" required>
                                <p class="description">The display name of the streaming service</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="service_color">Service Color</label></th>
                            <td>
                                <input type="color" name="service_color" id="service_color" value="<?php echo esc_attr($service['color']); ?>" required>
                                <p class="description">Choose a color for the service icon and UI elements</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label>Order Meta Keys</label></th>
                            <td>
                                <div id="meta-keys-container">
                                    <?php 
                                    if (!empty($service['meta_keys'])) {
                                        foreach ($service['meta_keys'] as $index => $meta_key) {
                                            ?>
                                            <div class="meta-key-row" style="margin-bottom: 10px;">
                                                <input type="text" name="meta_keys[]" class="regular-text" value="<?php echo esc_attr($meta_key); ?>" placeholder="Enter WooCommerce order meta key">
                                                <?php if ($index === 0): ?>
                                                    <button type="button" class="button add-meta-key">Add Another Meta Key</button>
                                                <?php else: ?>
                                                    <button type="button" class="button remove-meta-key">Remove</button>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                        }
                                    } else {
                                        ?>
                                        <div class="meta-key-row">
                                            <input type="text" name="meta_keys[]" class="regular-text" placeholder="Enter WooCommerce order meta key">
                                            <button type="button" class="button add-meta-key">Add Another Meta Key</button>
                                        </div>
                                        <?php
                                    }
                                    ?>
                                </div>
                                <p class="description">Enter the WooCommerce order meta keys to display in the accounts view (e.g., Profile Name, Profile Pin)</p>
                            </td>
                        </tr>
                        <tr>
                            <th><label for="product_ids">Link to Products</label></th>
                            <td>
                                <select name="product_ids[]" id="product_ids" class="wsm-product-selector" multiple style="width: 100%; min-height: 200px;">
                                    <?php
                                    $products = wc_get_products(array(
                                        'limit' => -1,
                                        'status' => 'publish',
                                        'orderby' => 'name',
                                        'order' => 'ASC'
                                    ));
                                    $selected_products = isset($service['product_ids']) ? $service['product_ids'] : array();
                                    foreach ($products as $product) {
                                        $selected = in_array($product->get_id(), $selected_products) ? 'selected' : '';
                                        echo '<option value="' . esc_attr($product->get_id()) . '" ' . $selected . '>' . esc_html($product->get_name()) . ' (#' . $product->get_id() . ')</option>';
                                    }
                                    ?>
                                </select>
                                <p class="description">Select which WooCommerce products should use this service for account management. Hold Ctrl (Windows) or Cmd (Mac) to select multiple products.</p>
                                <?php if (!empty($selected_products)): ?>
                                    <div style="margin-top: 10px; padding: 10px; background: #f0f6fc; border-left: 3px solid #2271b1;">
                                        <strong>Currently linked to <?php echo count($selected_products); ?> product(s):</strong>
                                        <ul style="margin: 5px 0 0 20px;">
                                            <?php foreach ($selected_products as $product_id): 
                                                $product = wc_get_product($product_id);
                                                if ($product) {
                                                    echo '<li>' . esc_html($product->get_name()) . ' (#' . $product_id . ')</li>';
                                                }
                                            endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    </table>
                    <p class="submit">
                        <input type="submit" name="wsm_update_service" class="button button-primary" value="Update Service">
                        <a href="<?php echo admin_url('admin.php?page=dbb-management&service=account_manager'); ?>" class="button">Cancel</a>
                    </p>
                </form>
            </div>
        </div>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Add meta key field
        $(document).on('click', '.add-meta-key', function() {
            var newRow = $('<div class="meta-key-row" style="margin-bottom: 10px;"></div>');
            newRow.append('<input type="text" name="meta_keys[]" class="regular-text" placeholder="Enter WooCommerce order meta key">');
            newRow.append('<button type="button" class="button remove-meta-key">Remove</button>');
            $('#meta-keys-container').append(newRow);
        });
        
        // Remove meta key field
        $(document).on('click', '.remove-meta-key', function() {
            $(this).closest('.meta-key-row').remove();
        });
    });
    </script>
    <?php
}

// Register post type for a service
function wsm_register_service_post_type($service_slug, $service_name) {
    $args = array(
        'public' => false,           // Changed to false to hide completely from public
        'label'  => $service_name . ' Manager',
        'supports' => array('title'),
        'menu_icon' => 'dashicons-media-text',
        'show_ui' => true,
        'show_in_menu' => false,     // Ensure it doesn't appear in admin menu
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
    register_post_type($service_slug, $args);
}

// Add AJAX handler for removing service
add_action('wp_ajax_wsm_remove_service', 'wsm_remove_service_handler');
function wsm_remove_service_handler() {
    // Verify nonce and capabilities
    if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wsm_remove_service')) {
        wp_send_json_error('Invalid security token');
    }
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $service_key = isset($_POST['service_key']) ? sanitize_text_field($_POST['service_key']) : '';
    if (empty($service_key)) {
        wp_send_json_error('Service key is required');
    }
    
    // Get registered services
    $services = get_option('wsm_registered_services', array());
    if (!isset($services[$service_key])) {
        wp_send_json_error('Service not found');
    }
    
    $service = $services[$service_key];
    
    // Get all posts for this service
    $posts = get_posts(array(
        'post_type' => $service['slug'],
        'posts_per_page' => -1,
        'post_status' => 'any'
    ));
    
    // Remove service links and posts
    foreach ($posts as $post) {
        $order_ids = get_post_meta($post->ID, '_summary_order_ids', true);
        if (!empty($order_ids)) {
            $order_ids_array = explode(',', $order_ids);
            foreach ($order_ids_array as $order_id) {
                delete_post_meta($order_id, '_linked_' . $service_key . '_post');
            }
        }
        wp_delete_post($post->ID, true);
    }
    
    // Remove service from registered services
    unset($services[$service_key]);
    update_option('wsm_registered_services', $services);
    
    // Remove service options
    delete_option('wsm_' . $service_key . '_settings');
    
    wp_send_json_success(array(
        'message' => 'Service successfully removed',
        'redirect' => admin_url('admin.php?page=dbb-management&service=account_manager')
    ));
}

// Add the missing function for managing posts tab
function wsm_render_manage_posts_tab($service_slug) {
    // Handle form submission
    if (isset($_POST['wsm_add_account'])) {
        if (!isset($_POST['wsm_nonce']) || !wp_verify_nonce($_POST['wsm_nonce'], 'wsm_add_account')) {
            wp_die('Invalid nonce');
        }

        $email = sanitize_email($_POST['account_email']);
        $hash = sanitize_text_field($_POST['account_hash']);
        $expiry_date = sanitize_text_field($_POST['expiry_date']);
        $slots = intval($_POST['slots']);

        // Check if account already exists
        $existing_account = get_posts(array(
            'post_type' => $service_slug,
            'meta_key' => '_summary_email',
            'meta_value' => $email,
            'posts_per_page' => 1,
            'suppress_filters' => true
        ));

        if (!empty($existing_account)) {
            echo '<div class="notice notice-error"><p>An account with this email already exists!</p></div>';
            return;
        }

        // Create new post
        $post_data = array(
            'post_title'    => $email,
            'post_status'   => 'publish',
            'post_type'     => $service_slug
        );

        $post_id = wp_insert_post($post_data);

        if (!is_wp_error($post_id)) {
            // Add post meta
            update_post_meta($post_id, '_summary_email', $email);
            update_post_meta($post_id, '_summary_hash', $hash);
            update_post_meta($post_id, '_summary_expiry_date', $expiry_date);
            update_post_meta($post_id, '_summary_slots', $slots);

            echo '<div class="notice notice-success"><p>Account added successfully!</p></div>';
            
            // If this account is assigned to existing orders, send notifications to customers
            if (isset($_POST['notify_customers']) && $_POST['notify_customers'] === '1' && isset($_POST['linked_orders'])) {
                $linked_orders = explode(',', sanitize_text_field($_POST['linked_orders']));
                if (!empty($linked_orders)) {
                    foreach ($linked_orders as $order_id) {
                        if (empty($order_id)) continue;
                        
                        $order_id = (int) trim($order_id);
                        $order = wc_get_order($order_id);
                        if (!$order) continue;
                        
                        // Get the service key from the post type
                        $post_type = get_post_type($post_id);
                        $service_key = '';
                        
                        $services = get_option('wsm_registered_services', array());
                        foreach ($services as $key => $service) {
                            if ($service['slug'] === $post_type) {
                                $service_key = $key;
                                break;
                            }
                        }
                        
                        if (!empty($service_key)) {
                            // Link the account to the order
                            update_post_meta($order_id, '_linked_' . $service_key . '_post', $post_id);
                            
                            // Add order ID to the account's order list
                            $order_ids = get_post_meta($post_id, '_summary_order_ids', true);
                            $order_ids_array = $order_ids ? explode(',', $order_ids) : array();
                            
                            if (!in_array($order_id, $order_ids_array)) {
                                $order_ids_array[] = $order_id;
                                update_post_meta($post_id, '_summary_order_ids', implode(',', $order_ids_array));
                                
                                // Send notification email
                                wsm_send_account_assignment_email($order_id, $service_key, $post_id);
                            }
                        }
                    }
                }
            }
            
            // Redirect to prevent form resubmission
            wp_redirect(add_query_arg('message', 'account_added', remove_query_arg('wsm_add_account')));
            exit;
        } else {
            echo '<div class="notice notice-error"><p>Error adding account: ' . $post_id->get_error_message() . '</p></div>';
        }
    }

    // Display success message if redirected
    if (isset($_GET['message']) && $_GET['message'] === 'account_added') {
        echo '<div class="notice notice-success"><p>Account added successfully!</p></div>';
    }

    // Display the form
    ?>
    <div class="wrap wsm-add-account-form">
        <form method="post" action="">
            <?php wp_nonce_field('wsm_add_account', 'wsm_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="account_email">Email</label></th>
                    <td>
                        <input type="email" name="account_email" id="account_email" class="regular-text" required autocomplete="off">
                        <p class="description">Enter the account email address</p>
                    </td>
            </tr>
                <tr>
                    <th><label for="account_hash">Password</label></th>
                    <td>
                        <input type="text" name="account_hash" id="account_hash" class="regular-text" required autocomplete="off">
                        <p class="description">Enter the account password</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="expiry_date">Expiry Date</label></th>
                    <td>
                        <input type="date" name="expiry_date" id="expiry_date" class="regular-text" required>
                        <p class="description">Enter the account expiry date</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="slots">Total Slots</label></th>
                    <td>
                        <input type="number" name="slots" id="slots" class="regular-text" min="1" value="1" required>
                        <p class="description">Enter the total number of available slots for this account</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="linked_orders">Link to Existing Orders</label></th>
                    <td>
                        <input type="text" name="linked_orders" id="linked_orders" class="regular-text" placeholder="Enter order IDs separated by commas">
                        <p class="description">Enter order IDs to link this account to (comma separated)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="notify_customers">Notify Customers</label></th>
                    <td>
                        <input type="checkbox" name="notify_customers" id="notify_customers" value="1">
                        <label for="notify_customers">Send email notifications to customers about their assigned accounts</label>
                        <p class="description">This will send email notifications to customers of linked orders with account details</p>
                    </td>
                </tr>
    </table>
            <p class="submit">
                <input type="submit" name="wsm_add_account" class="button button-primary" value="Add Account">
            </p>
        </form>
    </div>
    <?php
}

// Modify the view posts tab to use dynamic meta keys
function wsm_render_view_posts_tab($service_slug) {
    // Get service details
    $services = wsm_get_available_services();
    $current_service = null;
    
    foreach ($services as $service) {
        if ($service['slug'] === $service_slug) {
            $current_service = $service;
            break;
        }
    }
    
    if (!$current_service) {
        echo '<div class="notice notice-error"><p>Service not found.</p></div>';
        return;
    }
    
    // Get all posts for this service
    $posts = get_posts(array(
        'post_type' => $service_slug,
        'posts_per_page' => -1,
        'orderby' => 'title',
        'order' => 'ASC',
        'suppress_filters' => true
    ));

    if (empty($posts)) {
        echo '<div class="notice notice-warning"><p>No accounts found for this service.</p></div>';
        return;
    }

    // Add search and sort controls
    ?>
    <div class="wsm-controls-wrapper">
        <div class="wsm-search-wrapper">
            <input type="text" id="wsm-search-input" placeholder="Search accounts and orders..." class="regular-text">
            <span class="wsm-search-icon dashicons dashicons-search"></span>
        </div>
    </div>
    
    <!-- Display the accounts table -->
    <table class="netflix-manager-table">
        <?php foreach ($posts as $post): 
            $email = get_post_meta($post->ID, '_summary_email', true);
            $hash = get_post_meta($post->ID, '_summary_hash', true);
            $expiry_date = get_post_meta($post->ID, '_summary_expiry_date', true);
            $slots = get_post_meta($post->ID, '_summary_slots', true);
            $order_ids = get_post_meta($post->ID, '_summary_order_ids', true);
            
            // Calculate remaining slots
            $total_slots = intval($slots);
            $used_slots = $order_ids ? count(explode(',', $order_ids)) : 0;
            $remaining_slots = max(0, $total_slots - $used_slots);
        ?>
            <tbody class="account-group">
                <!-- Account Header Row -->
                <tr class="account-header">
                    <td colspan="2"><span class="dashicons dashicons-admin-users"></span> Email and Password</td>
                    <td class="sortable-header sort-indicator"><span class="dashicons dashicons-calendar-alt"></span> Expiry Date</td>
                    <td colspan="4"><span class="dashicons dashicons-info"></span> Account Details</td>
                    <td>
                        <button type="button" 
                                class="button button-small button-edit-account wsm-edit-account" 
                                data-post-id="<?php echo esc_attr($post->ID); ?>">
                            <span class="dashicons dashicons-edit"></span> Edit Account
                        </button>
                    </td>
                </tr>
                <tr>
                    <td colspan="2"><?php echo esc_html($email . ' - ' . $hash); ?></td>
                    <td><?php echo esc_html(date_i18n('j F Y', strtotime($expiry_date))); ?></td>
                    <td colspan="5">
                        <span class="dashicons dashicons-groups"></span> Total Slots: <?php echo esc_html($slots); ?> | 
                        <span class="dashicons dashicons-yes"></span> Used: <?php echo esc_html($used_slots); ?> | 
                        <span class="dashicons dashicons-marker"></span> Remaining: <?php echo esc_html($remaining_slots); ?>
                        <?php
                        // Display last update information
                        $last_updated = get_post_meta($post->ID, '_last_updated', true);
                        $last_update_reason = get_post_meta($post->ID, '_last_update_reason', true);
                        if ($last_updated) {
                            echo '<div class="last-update-info">';
                            echo '<span class="dashicons dashicons-update"></span> Last Updated: ' . esc_html(date_i18n('j F Y', strtotime($last_updated)));
                            if ($last_update_reason) {
                                echo ' - Reason: ' . esc_html($last_update_reason);
                            }
                            echo '</div>';
                        }
                        ?>
                    </td>
                </tr>
                
                <!-- Orders Header Row -->
                <tr>
                    <?php 
                    // Dynamic headers based on meta keys
                    if (!empty($current_service['meta_keys'])) {
                        foreach ($current_service['meta_keys'] as $meta_key) {
                            echo '<th><span class="dashicons dashicons-admin-users"></span> ' . esc_html($meta_key) . '</th>';
                        }
                    }
                    ?>
                    <th><span class="dashicons dashicons-calendar-alt"></span> Subscription Start Date</th>
                    <th><span class="dashicons dashicons-calendar"></span> Subscription End Date</th>
                    <th><span class="dashicons dashicons-businessman"></span> Custom Name</th>
                    <th><span class="dashicons dashicons-email"></span> Customer Email</th>
                    <th><span class="dashicons dashicons-phone"></span> Customer Phone</th>
                    <th><span class="dashicons dashicons-cart"></span> Order ID</th>
                    <th><span class="dashicons dashicons-admin-tools"></span> Action</th>
                </tr>
                <?php 
                if ($order_ids) {
                    $order_ids_array = explode(',', $order_ids);
                    foreach ($order_ids_array as $order_id) {
                        $order = wc_get_order($order_id);
                        if ($order) {
                            $profile_details = wsm_get_order_profile_details($order_id, $service_slug);
                            $subscription_dates = wsm_get_subscription_dates($order_id);
                            ?>
                            <tr class="order-row">
                                <?php
                                // Dynamic meta values
                                if (!empty($current_service['meta_keys'])) {
                                    foreach ($current_service['meta_keys'] as $meta_key) {
                                        echo '<td class="meta-value-' . sanitize_html_class($meta_key) . '">' . 
                                             esc_html($profile_details[$meta_key] ?? 'N/A') . 
                                             '</td>';
                                    }
                                }
                                ?>
                                <td><?php echo esc_html(date_i18n('j F Y', strtotime($subscription_dates['start']))); ?></td>
                                <td><?php echo esc_html(date_i18n('j F Y', strtotime($subscription_dates['end']))); ?></td>
                                <td><?php echo esc_html($order->get_formatted_billing_full_name()); ?></td>
                                <td><?php echo esc_html($order->get_billing_email()); ?></td>
                                <td>
                                    <?php 
                                    $phone = $order->get_billing_phone();
                                    if ($phone) {
                                        echo '<span style="margin-right: 8px;">' . esc_html($phone) . '</span>';
                                        $clean_phone = preg_replace('/[^\d]/', '', $phone);
                                        if (strlen($clean_phone) >= 10) {
                                            $customer_name = $order->get_billing_first_name();
                                            $message = "Hi {$customer_name}! 👋\n\nYour {$current_service['name']} account details:\n\nOrder #{$order->get_order_number()}\n\nPlease check your email for complete credentials.\n\nThank you! 🎉";
                                            $wa_url = 'https://wa.me/' . $clean_phone . '?text=' . urlencode($message);
                                            echo '<a href="' . esc_url($wa_url) . '" target="_blank" class="button button-small" style="color: #25D366; border-color: #25D366;" title="Send WhatsApp Message">';
                                            echo '<span class="dashicons dashicons-whatsapp"></span>';
                                            echo '</a>';
                                        }
                                    } else {
                                        echo '<span style="color: #999;">No Phone</span>';
                                    }
                                    ?>
                                </td>
                                <td><a href="<?php echo esc_url($order->get_edit_order_url()); ?>" target="_blank"><span class="dashicons dashicons-visibility"></span> #<?php echo $order->get_order_number(); ?></a></td>
                                <td>
                                    <button type="button" 
                                            class="button button-small button-edit wsm-edit-order" 
                                            data-order-id="<?php echo esc_attr($order_id); ?>"
                                            data-meta-keys='<?php echo esc_attr(json_encode($current_service['meta_keys'])); ?>'>
                                        <span class="dashicons dashicons-edit"></span> Edit
                                    </button>
                                    <button type="button" 
                                            class="button button-small button-remove wsm-remove-order" 
                                            data-order-id="<?php echo esc_attr($order_id); ?>"
                                            data-post-id="<?php echo esc_attr($post->ID); ?>">
                                        <span class="dashicons dashicons-no"></span> Remove
                                    </button>
                                </td>
                            </tr>
                            <?php
                        }
                    }
                } else {
                    ?>
                    <tr>
                        <td colspan="<?php echo count($current_service['meta_keys'] ?? []) + 7; ?>">
                            <span class="dashicons dashicons-info"></span> No orders associated with this account.
                        </td>
                    </tr>
                    <?php
                }
                ?>
                <!-- Spacer row between accounts -->
                <tr>
                    <td colspan="<?php echo count($current_service['meta_keys'] ?? []) + 7; ?>" style="height: 40px; background-color: white;"></td>
                </tr>
            </tbody>
        <?php endforeach; ?>
    </table>
    <?php
}

// Save linked account when order is updated
function wsm_save_linked_account($order_id) {
    try {
    // Get all registered services
    $services = get_option('wsm_registered_services', array());
    
    foreach ($services as $service_key => $service) {
        // Get the selected account ID from POST data
        $linked_post_id = isset($_POST['linked_' . $service_key . '_post']) ? absint($_POST['linked_' . $service_key . '_post']) : '';
        
        // Get the previous linked account ID
        $previous_linked_id = get_post_meta($order_id, '_linked_' . $service_key . '_post', true);
        
        // If there's a previous link and it's different from the new one, remove the order ID from its meta
        if ($previous_linked_id && $previous_linked_id != $linked_post_id) {
            $previous_order_ids = get_post_meta($previous_linked_id, '_summary_order_ids', true);
            if ($previous_order_ids) {
                $order_ids_array = explode(',', $previous_order_ids);
                $order_ids_array = array_diff($order_ids_array, array($order_id));
                update_post_meta($previous_linked_id, '_summary_order_ids', implode(',', $order_ids_array));
            }
        }
        
        // Update the order's linked account
        if ($linked_post_id) {
                // Verify the account exists
                $account = get_post($linked_post_id);
                if (!$account) {
                    throw new Exception(sprintf('Account ID %d not found for service %s', $linked_post_id, $service_key));
                }

                // Check if account has available slots
                $slots = get_post_meta($linked_post_id, '_summary_slots', true);
                $current_orders = get_post_meta($linked_post_id, '_summary_order_ids', true);
                $current_order_count = $current_orders ? count(explode(',', $current_orders)) : 0;
                
                if ($current_order_count >= $slots) {
                    throw new Exception(sprintf('Account ID %d has no available slots for service %s', $linked_post_id, $service_key));
                }

            update_post_meta($order_id, '_linked_' . $service_key . '_post', $linked_post_id);
            
            // Add order ID to the account's order list
            $order_ids = get_post_meta($linked_post_id, '_summary_order_ids', true);
            $order_ids_array = $order_ids ? explode(',', $order_ids) : array();
            
            if (!in_array($order_id, $order_ids_array)) {
                $order_ids_array[] = $order_id;
                update_post_meta($linked_post_id, '_summary_order_ids', implode(',', $order_ids_array));
                
                // Send email notification to customer if this is a new assignment
                wsm_send_account_assignment_email($order_id, $service_key, $linked_post_id);
                    
                    // If this is a subscription order, ensure the subscription has the account linked
                    if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id)) {
                        $subscriptions = wcs_get_subscriptions_for_order($order_id);
                        foreach ($subscriptions as $subscription) {
                            dbb_copy_account_data_to_subscription($subscription->get_id(), $order_id);
                        }
                    }
            }
        } else {
            delete_post_meta($order_id, '_linked_' . $service_key . '_post');
        }
    }

        // Log successful account linking
        dbb_log(sprintf('Successfully linked accounts for order %d', $order_id));
        
    } catch (Exception $e) {
        // Log the error
        dbb_log(sprintf('Error linking accounts for order %d: %s', $order_id, $e->getMessage()), 'error');
        
        // Add order note
        $order = wc_get_order($order_id);
        if ($order) {
            $order->add_order_note('Error linking accounts: ' . $e->getMessage());
        }
        
        // Throw the exception to be caught by WooCommerce
        throw $e;
    }
}

// Hook into order creation and updates
add_action('woocommerce_new_order', 'wsm_save_linked_account', 10, 1);
add_action('woocommerce_update_order', 'wsm_save_linked_account', 10, 1);

// Send email notification when an account is assigned to a customer
function wsm_send_account_assignment_email($order_id, $service_key, $account_id) {
    // Get order
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    // Check if order is completed or processing
    $valid_statuses = apply_filters('wsm_account_email_order_statuses', array('completed', 'processing'));
    if (!in_array($order->get_status(), $valid_statuses)) {
        return;
    }
    
    // Get service details
    $services = get_option('wsm_registered_services', array());
    if (!isset($services[$service_key])) {
        return;
    }
    $service = $services[$service_key];
    
    // Get account details
    $email = get_post_meta($account_id, '_summary_email', true);
    $hash = get_post_meta($account_id, '_summary_hash', true);
    
    // Get subscription data
    $subscription_id = null;
    $subscription = null;
    $start_date = '';
    $next_payment = '';
    $end_date = '';
    
    // Check if WooCommerce Subscriptions is active
    if (function_exists('wcs_get_subscriptions_for_order')) {
        $subscriptions = wcs_get_subscriptions_for_order($order_id);
        if (!empty($subscriptions)) {
            // Get the first subscription
            $subscription = reset($subscriptions);
            $subscription_id = $subscription->get_id();
            
            // Get subscription dates
            $start_date = $subscription->get_date('start');
            $next_payment = $subscription->get_date('next_payment');
            $end_date = $subscription->get_date('end');
        }
    }
    
    // Format dates
    $formatted_start = !empty($start_date) ? date_i18n(get_option('date_format'), strtotime($start_date)) : 'N/A';
    $formatted_next = !empty($next_payment) ? date_i18n(get_option('date_format'), strtotime($next_payment)) : 'N/A';
    $formatted_end = !empty($end_date) ? date_i18n(get_option('date_format'), strtotime($end_date)) : 'N/A';
    
    // Get recipient email
    $recipient = $order->get_billing_email();
    
    // Email subject
    $subject = sprintf(__('Your %s Account Details - Order #%s', 'dbb-management'), 
               $service['name'], $order->get_order_number());
    
    // Email heading
    $heading = sprintf(__('Your %s Account is Ready!', 'dbb-management'), $service['name']);
    
    // Start building email content
    $message = '<p>' . sprintf(__('Thank you for your order. Your %s account has been assigned to you.', 'dbb-management'), 
               $service['name']) . '</p>';
    
    $message .= '<h2>' . __('Account Details', 'dbb-management') . '</h2>';
    
    // Account details table
    $message .= '<table class="td" cellspacing="0" cellpadding="6" style="width: 100%; border: 1px solid #e5e5e5;" border="1">';
    
    // Login email row
    $message .= '<tr>';
    $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . __('Login Email:', 'dbb-management') . '</th>';
    $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;"><strong>' . esc_html($email) . '</strong></td>';
    $message .= '</tr>';
    
    // Password row
    $message .= '<tr>';
    $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . __('Password:', 'dbb-management') . '</th>';
    $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;"><strong>' . esc_html($hash) . '</strong></td>';
    $message .= '</tr>';
    
    // Add subscription dates if available
    if ($subscription) {
        $message .= '<tr>';
        $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . __('Start Date:', 'dbb-management') . '</th>';
        $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . esc_html($formatted_start) . '</td>';
        $message .= '</tr>';
        
        if (!empty($next_payment)) {
            $message .= '<tr>';
            $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . __('Next Payment:', 'dbb-management') . '</th>';
            $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . esc_html($formatted_next) . '</td>';
            $message .= '</tr>';
        }
        
        if (!empty($end_date)) {
            $message .= '<tr>';
            $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . __('End Date:', 'dbb-management') . '</th>';
            $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . esc_html($formatted_end) . '</td>';
            $message .= '</tr>';
        }
    }
    
    $message .= '</table>';
    
    // Add information about where to view the account details
    if ($subscription) {
        $message .= '<p>' . __('You can also view your account details at any time by visiting your subscription page:', 'dbb-management') . '</p>';
        $message .= '<p><a href="' . esc_url($subscription->get_view_order_url()) . '">' . __('View Subscription', 'dbb-management') . '</a></p>';
    } else {
        $message .= '<p>' . __('You can also view your account details at any time by visiting your order page:', 'dbb-management') . '</p>';
        $message .= '<p><a href="' . esc_url($order->get_view_order_url()) . '">' . __('View Order', 'dbb-management') . '</a></p>';
    }
    
    // Additional information
    $message .= '<p>' . __('If you have any questions or need assistance with your account, please contact our support team.', 'dbb-management') . '</p>';
    
    // Email templates
    $headers = array('Content-Type: text/html; charset=UTF-8');
    
    // Send the email
    $sent = wp_mail($recipient, $subject, $message, $headers);
    
    // WhatsApp integration removed
    
    // Add a note to the order
    $order->add_order_note(
        sprintf(__('Account credentials for %s service sent to customer via email.', 'dbb-management'), $service['name'])
    );
    
    return $sent;
}

// Add AJAX handler for getting account details
add_action('wp_ajax_wsm_get_account_details', 'wsm_get_account_details_handler');
function wsm_get_account_details_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    
    if (empty($post_id)) {
        wp_send_json_error('Missing post ID');
    }
    
    $post = get_post($post_id);
    
    if (!$post) {
        wp_send_json_error('Account not found');
    }
    
    $account_details = array(
        'id' => $post->ID,
        'email' => get_post_meta($post_id, '_summary_email', true),
        'hash' => get_post_meta($post_id, '_summary_hash', true),
        'expiry_date' => get_post_meta($post_id, '_summary_expiry_date', true),
        'slots' => get_post_meta($post_id, '_summary_slots', true),
        'last_updated' => get_post_meta($post_id, '_last_updated', true),
        'last_update_reason' => get_post_meta($post_id, '_last_update_reason', true)
    );
    
    wp_send_json_success($account_details);
}

// Add AJAX handler for updating account details
add_action('wp_ajax_wsm_update_account', 'wsm_update_account_handler');
function wsm_update_account_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    
    if (empty($post_id)) {
        wp_send_json_error('Missing post ID');
    }
    
    // Update account details
    if (isset($_POST['email'])) {
        update_post_meta($post_id, '_summary_email', sanitize_email($_POST['email']));
    }
    
    if (isset($_POST['hash'])) {
        update_post_meta($post_id, '_summary_hash', sanitize_text_field($_POST['hash']));
    }
    
    if (isset($_POST['expiry_date'])) {
        update_post_meta($post_id, '_summary_expiry_date', sanitize_text_field($_POST['expiry_date']));
    }
    
    if (isset($_POST['slots'])) {
        update_post_meta($post_id, '_summary_slots', intval($_POST['slots']));
    }
    
    // Save update reason and timestamp
    if (isset($_POST['update_reason'])) {
        $current_time = current_time('mysql');
        $update_reason = sanitize_textarea_field($_POST['update_reason']);
        
        // Save the update reason with timestamp
        $update_history = get_post_meta($post_id, '_update_history', true);
        if (!is_array($update_history)) {
            $update_history = array();
        }
        
        $update_history[] = array(
            'timestamp' => $current_time,
            'reason' => $update_reason,
            'user_id' => get_current_user_id()
        );
        
        update_post_meta($post_id, '_update_history', $update_history);
        update_post_meta($post_id, '_last_updated', $current_time);
        update_post_meta($post_id, '_last_update_reason', $update_reason);
    }
    
    // Update post title if email changed
    if (isset($_POST['email'])) {
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => sanitize_email($_POST['email'])
        ));
    }
    
    wp_send_json_success('Account updated successfully');
}

// Add AJAX handler for getting order details
add_action('wp_ajax_wsm_get_order_details', 'wsm_get_order_details_handler');
function wsm_get_order_details_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $meta_keys = isset($_POST['meta_keys']) ? json_decode(stripslashes($_POST['meta_keys']), true) : array();
    
    if (empty($order_id)) {
        wp_send_json_error('Missing order ID');
    }
    
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error('Order not found');
    }
    
    // Get meta values
    $meta_values = array();
    foreach ($meta_keys as $meta_key) {
        // Get from order item meta
        global $wpdb;
        $query = $wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->prefix}woocommerce_order_itemmeta 
            WHERE order_item_id IN (
                SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items 
                WHERE order_id = %d
            ) AND meta_key = %s",
            $order_id, 
            $meta_key
        );
        
        $meta_value = $wpdb->get_var($query);
        $meta_values[$meta_key] = $meta_value;
    }
    
    wp_send_json_success(array(
        'id' => $order_id,
        'meta' => $meta_values
    ));
}

// Add AJAX handler for updating order details
add_action('wp_ajax_wsm_update_order_details', 'wsm_update_order_details_handler');
function wsm_update_order_details_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    
    if (empty($order_id)) {
        wp_send_json_error('Missing order ID');
    }
    
    // Get order
    $order = wc_get_order($order_id);
    
    if (!$order) {
        wp_send_json_error('Order not found');
    }
    
    // Update order meta values
    $meta_updates = array();
    foreach ($_POST as $key => $value) {
        // Skip non-meta fields
        if (in_array($key, array('action', 'nonce', 'order_id'))) {
            continue;
        }
        
        // Convert edit_profile_name to Profile Name
        if (strpos($key, 'edit_') === 0) {
            $meta_key = substr($key, 5); // Remove 'edit_' prefix
            $meta_key = str_replace('_', ' ', $meta_key);
            $meta_key = ucwords($meta_key);
            
            // Update order item meta
            global $wpdb;
            $query = $wpdb->prepare(
                "UPDATE {$wpdb->prefix}woocommerce_order_itemmeta 
                SET meta_value = %s
                WHERE order_item_id IN (
                    SELECT order_item_id FROM {$wpdb->prefix}woocommerce_order_items 
                    WHERE order_id = %d
                ) AND meta_key = %s",
                sanitize_text_field($value),
                $order_id, 
                $meta_key
            );
            
            $result = $wpdb->query($query);
            
            if ($result !== false) {
                $meta_updates[] = $meta_key;
            }
        }
    }
    
    if (empty($meta_updates)) {
        wp_send_json_error('No updates were made');
    } else {
        wp_send_json_success('Order details updated successfully: ' . implode(', ', $meta_updates));
    }
}

// Add AJAX handler for removing an order from an account
add_action('wp_ajax_wsm_remove_order', 'wsm_remove_order_handler');
function wsm_remove_order_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $order_id = isset($_POST['order_id']) ? intval($_POST['order_id']) : 0;
    $post_id = isset($_POST['post_id']) ? intval($_POST['post_id']) : 0;
    
    if (empty($order_id) || empty($post_id)) {
        wp_send_json_error('Missing required parameters');
    }
    
    // Get all registered services
    $services = get_option('wsm_registered_services', array());
    $service_key = '';
    
    // Find the service this post belongs to
    foreach ($services as $key => $service) {
        $post = get_post($post_id);
        if ($post && $post->post_type === $service['slug']) {
            $service_key = $key;
            break;
        }
    }
    
    if (empty($service_key)) {
        wp_send_json_error('Service not found for this account');
    }
    
    // Get current order IDs
    $order_ids = get_post_meta($post_id, '_summary_order_ids', true);
    if (empty($order_ids)) {
        wp_send_json_error('No orders associated with this account');
    }
    
    // Remove order ID from list
    $order_ids_array = explode(',', $order_ids);
    $order_ids_array = array_diff($order_ids_array, array($order_id));
    
    // Update post meta
    update_post_meta($post_id, '_summary_order_ids', implode(',', $order_ids_array));
    
    // Delete order meta
    delete_post_meta($order_id, '_linked_' . $service_key . '_post');
    
    wp_send_json_success('Order successfully removed from account');
} 

// Handle order status changes
function wsm_handle_order_status_change($order_id, $old_status, $new_status) {
    try {
        // Get the order
        $order = wc_get_order($order_id);
        if (!$order) {
            throw new Exception('Order not found');
        }

        // Get all registered services
        $services = get_option('wsm_registered_services', array());
        
        // Check if this is a subscription order
        $is_subscription = false;
        if (function_exists('wcs_order_contains_subscription')) {
            $is_subscription = wcs_order_contains_subscription($order_id);
        }

        // Handle completed orders
        if ($new_status === 'completed') {
            foreach ($services as $service_key => $service) {
                // Get linked account ID
                $linked_post_id = get_post_meta($order_id, '_linked_' . $service_key . '_post', true);
                
                if ($linked_post_id) {
                    // Send account details email
                    wsm_send_account_assignment_email($order_id, $service_key, $linked_post_id);
                    
                    // If this is a subscription order, ensure the subscription has the account linked
                    if ($is_subscription && function_exists('wcs_get_subscriptions_for_order')) {
                        $subscriptions = wcs_get_subscriptions_for_order($order_id);
                        foreach ($subscriptions as $subscription) {
                            dbb_copy_account_data_to_subscription($subscription->get_id(), $order_id);
                        }
                    }
                }
            }
        }

        // Log successful status change
        dbb_log(sprintf('Successfully processed status change for order %d from %s to %s', 
            $order_id, $old_status, $new_status));
        
    } catch (Exception $e) {
        // Log the error
        dbb_log(sprintf('Error processing status change for order %d: %s', 
            $order_id, $e->getMessage()), 'error');
        
        // Add order note
        if ($order) {
            $order->add_order_note('Error processing status change: ' . $e->getMessage());
        }
    }
}

// Hook into order status changes
add_action('woocommerce_order_status_changed', 'wsm_handle_order_status_change', 10, 3);

// AJAX handler to get subscription service row
function wsm_get_subscription_service_row_handler() {
    // Verify nonce
    if (!wp_verify_nonce($_POST['nonce'], 'wsm_get_subscription_service_row')) {
        wp_die('Security check failed');
    }
    
    $service_key = sanitize_text_field($_POST['service_key']);
    $subscription_id = intval($_POST['subscription_id']);
    
    // Get service details
    $services = get_option('wsm_registered_services', array());
    if (!isset($services[$service_key])) {
        wp_send_json_error('Service not found');
    }
    
    $service = $services[$service_key];
    
    // Generate the service row HTML
    ob_start();
    wsm_render_subscription_service_account_row($service_key, $service, $subscription_id);
    $html = ob_get_clean();
    
    wp_send_json_success($html);
}
add_action('wp_ajax_wsm_get_subscription_service_row', 'wsm_get_subscription_service_row_handler');

// Function to save linked account for subscriptions
function wsm_save_linked_subscription_account($subscription_id) {
    // Check if we have any linked account data to save
    $services = get_option('wsm_registered_services', array());
    $has_changes = false;
    
    foreach ($services as $service_key => $service) {
        $field_name = 'linked_' . $service_key . '_post';
        
        if (isset($_POST[$field_name])) {
            $new_linked_post_id = intval($_POST[$field_name]);
            $current_linked_post_id = get_post_meta($subscription_id, '_' . $field_name, true);
            
            // If there's a change
            if ($new_linked_post_id != $current_linked_post_id) {
                $has_changes = true;
                
                // Remove subscription from old account if exists
                if ($current_linked_post_id) {
                    $old_order_ids = get_post_meta($current_linked_post_id, '_summary_order_ids', true);
                    if ($old_order_ids) {
                        $old_order_ids_array = explode(',', $old_order_ids);
                        $old_order_ids_array = array_filter($old_order_ids_array, function($id) use ($subscription_id) {
                            return intval($id) !== $subscription_id;
                        });
                        update_post_meta($current_linked_post_id, '_summary_order_ids', implode(',', $old_order_ids_array));
                    }
                }
                
                // Add subscription to new account if selected
                if ($new_linked_post_id) {
                    // Verify the account exists
                    $account_post = get_post($new_linked_post_id);
                    if (!$account_post || $account_post->post_type !== $service['slug']) {
                        continue; // Skip invalid account
                    }
                    
                    // Check if account has available slots
                    $slots = get_post_meta($new_linked_post_id, '_summary_slots', true);
                    $order_ids = get_post_meta($new_linked_post_id, '_summary_order_ids', true);
                    $order_ids_array = $order_ids ? explode(',', $order_ids) : array();
                    $used_slots = count($order_ids_array);
                    
                    if ($used_slots >= intval($slots)) {
                        // No available slots, skip this assignment
                        continue;
                    }
                    
                    // Add subscription to account's order list
                    if (!in_array($subscription_id, $order_ids_array)) {
                        $order_ids_array[] = $subscription_id;
                        update_post_meta($new_linked_post_id, '_summary_order_ids', implode(',', $order_ids_array));
                    }
                    
                    // Link account to subscription
                    update_post_meta($subscription_id, '_' . $field_name, $new_linked_post_id);
                    
                    // Send email notification if subscription is active
                    $subscription = wcs_get_subscription($subscription_id);
                    if ($subscription && in_array($subscription->get_status(), array('active', 'pending-cancel'))) {
                        wsm_send_subscription_account_assignment_email($subscription_id, $service_key, $new_linked_post_id);
                    }
                } else {
                    // Remove the link
                    delete_post_meta($subscription_id, '_' . $field_name);
                }
            }
        }
    }
    
    return $has_changes;
}

// Function to send account assignment email for subscriptions
function wsm_send_subscription_account_assignment_email($subscription_id, $service_key, $account_id) {
    // Get subscription
    $subscription = wcs_get_subscription($subscription_id);
    if (!$subscription) {
        return;
    }
    
    // Check if subscription is in valid status
    $valid_statuses = apply_filters('wsm_subscription_account_email_statuses', array('active', 'pending-cancel'));
    if (!in_array($subscription->get_status(), $valid_statuses)) {
        return;
    }
    
    // Get service details
    $services = get_option('wsm_registered_services', array());
    if (!isset($services[$service_key])) {
        return;
    }
    $service = $services[$service_key];
    
    // Get account details
    $email = get_post_meta($account_id, '_summary_email', true);
    $hash = get_post_meta($account_id, '_summary_hash', true);
    
    // Get subscription dates
    $start_date = $subscription->get_date('start');
    $next_payment = $subscription->get_date('next_payment');
    $end_date = $subscription->get_date('end');
    
    // Format dates
    $formatted_start = !empty($start_date) ? date_i18n('j F Y', strtotime($start_date)) : 'N/A';
    $formatted_next = !empty($next_payment) ? date_i18n('j F Y', strtotime($next_payment)) : 'N/A';
    $formatted_end = !empty($end_date) ? date_i18n('j F Y', strtotime($end_date)) : 'N/A';
    
    // Get recipient email
    $recipient_email = $subscription->get_billing_email();
    if (empty($recipient_email)) {
        return;
    }
    
    // Email subject
    $subject = sprintf(__('Your %s Account Details - Subscription #%s', 'dbb-management'), 
                      $service['name'], $subscription->get_id());
    
    // Email message
    $message = '<html><body>';
    $message .= '<h2>' . sprintf(__('Your %s Account Details', 'dbb-management'), esc_html($service['name'])) . '</h2>';
    $message .= '<p>' . sprintf(__('Hello %s,', 'dbb-management'), esc_html($subscription->get_formatted_billing_full_name())) . '</p>';
    $message .= '<p>' . sprintf(__('Your %s account has been assigned to your subscription. Here are your login details:', 'dbb-management'), esc_html($service['name'])) . '</p>';
    
    $message .= '<table style="border-collapse: collapse; width: 100%; margin: 20px 0;">';
    $message .= '<tr>';
    $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f9fa;">' . __('Email:', 'dbb-management') . '</th>';
    $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . esc_html($email) . '</td>';
    $message .= '</tr>';
    $message .= '<tr>';
    $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f9fa;">' . __('Password:', 'dbb-management') . '</th>';
    $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . esc_html($hash) . '</td>';
    $message .= '</tr>';
    $message .= '<tr>';
    $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f9fa;">' . __('Start Date:', 'dbb-management') . '</th>';
    $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . esc_html($formatted_start) . '</td>';
    $message .= '</tr>';
    
    if (!empty($next_payment)) {
        $message .= '<tr>';
        $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f9fa;">' . __('Next Payment:', 'dbb-management') . '</th>';
        $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . esc_html($formatted_next) . '</td>';
        $message .= '</tr>';
    }
    
    if (!empty($end_date)) {
        $message .= '<tr>';
        $message .= '<th style="text-align: left; border: 1px solid #e5e5e5; padding: 12px; background-color: #f8f9fa;">' . __('End Date:', 'dbb-management') . '</th>';
        $message .= '<td style="text-align: left; border: 1px solid #e5e5e5; padding: 12px;">' . esc_html($formatted_end) . '</td>';
        $message .= '</tr>';
    }
    
    $message .= '</table>';
    
    // Add information about where to view the account details
    $message .= '<p>' . __('You can view your account details at any time by visiting your subscription page:', 'dbb-management') . '</p>';
    $message .= '<p><a href="' . esc_url($subscription->get_view_order_url()) . '">' . __('View Subscription', 'dbb-management') . '</a></p>';
    
    // Additional information
    $message .= '<p>' . __('Please keep this information secure and do not share it with others.', 'dbb-management') . '</p>';
    $message .= '<p>' . __('If you have any questions, please contact our support team.', 'dbb-management') . '</p>';
    $message .= '</body></html>';
    
    // Email headers
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_option('blogname') . ' <' . get_option('admin_email') . '>'
    );
    
    // Send email
    $sent = wp_mail($recipient_email, $subject, $message, $headers);
    
    // WhatsApp integration removed
    
    return $sent;
}

// Hook to handle subscription meta saving
function wsm_handle_subscription_meta_save($subscription_id) {
    // Check if this is a subscription save action
    if (!isset($_POST['subscription_status']) && !isset($_POST['linked_netflix_post'])) {
        return;
    }
    
    // Save linked accounts
    wsm_save_linked_subscription_account($subscription_id);
}
add_action('woocommerce_subscription_details_table', 'wsm_handle_subscription_meta_save', 10, 1);

// Add hook to handle subscription form processing
function wsm_process_subscription_form() {
    // Check if this is a subscription update
    if (isset($_POST['wcs_meta_nonce']) && wp_verify_nonce($_POST['wcs_meta_nonce'], 'wcs_subscription_meta')) {
        if (isset($_POST['subscription_id'])) {
            $subscription_id = intval($_POST['subscription_id']);
            wsm_save_linked_subscription_account($subscription_id);
        }
    }
}
add_action('admin_init', 'wsm_process_subscription_form');

// Better hook for subscription updates
function wsm_handle_subscription_update($subscription_id) {
    // Only process if we have account assignment data
    $services = get_option('wsm_registered_services', array());
    $has_account_data = false;
    
    foreach ($services as $service_key => $service) {
        if (isset($_POST['linked_' . $service_key . '_post'])) {
            $has_account_data = true;
            break;
        }
    }
    
    if ($has_account_data) {
        wsm_save_linked_subscription_account($subscription_id);
    }
}
add_action('woocommerce_process_subscription_meta', 'wsm_handle_subscription_update', 10, 1);

// Hook for when subscription status changes
function wsm_handle_subscription_status_change($subscription, $new_status, $old_status) {
    // If subscription becomes active and has linked accounts, send notification
    if ($new_status === 'active' && $old_status !== 'active') {
        $subscription_id = $subscription->get_id();
        $services = get_option('wsm_registered_services', array());
        
        foreach ($services as $service_key => $service) {
            $linked_post_id = get_post_meta($subscription_id, '_linked_' . $service_key . '_post', true);
            if ($linked_post_id) {
                wsm_send_subscription_account_assignment_email($subscription_id, $service_key, $linked_post_id);
            }
        }
    }
}
add_action('woocommerce_subscription_status_updated', 'wsm_handle_subscription_status_change', 10, 3); 