<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Sales Monitor Module
 * Handles product costing, profit tracking, sales reports, and expense management
 */

// Register Custom Post Type for Product Costs
function sm_register_custom_post_type() {
    $args = array(
        'public' => false,
        'label'  => 'Product Costs',
        'supports' => array('title'),
        'menu_icon' => 'dashicons-chart-line',
        'show_ui' => true,
        'show_in_menu' => false,
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
    register_post_type('product-costs', $args);

    // Register post meta
    register_post_meta('product-costs', '_product_id', array(
        'type' => 'number',
        'single' => true,
        'show_in_rest' => true,
    ));
    register_post_meta('product-costs', '_variation_id', array(
        'type' => 'number',
        'single' => true,
        'show_in_rest' => true,
    ));
    register_post_meta('product-costs', '_cost', array(
        'type' => 'number',
        'single' => true,
        'show_in_rest' => true,
    ));
    register_post_meta('product-costs', '_profit', array(
        'type' => 'number',
        'single' => true,
        'show_in_rest' => true,
    ));
    register_post_meta('product-costs', '_is_subscription', array(
        'type' => 'boolean',
        'single' => true,
        'show_in_rest' => true,
    ));
    register_post_meta('product-costs', '_subscription_period', array(
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
    ));
}
add_action('init', 'sm_register_custom_post_type', 0);

// Register submenu under DBB Management
function sm_add_submenu_item() {
    add_submenu_page(
        'dbb-management',
        'Sales Monitor',
        'Sales Monitor',
        'manage_options',
        'sales-monitor',
        'sm_render_main_page'
    );
}
add_action('admin_menu', 'sm_add_submenu_item');

// Main Sales Monitor page
function sm_render_main_page() {
    // Check if a specific tab is requested
    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'products';
    ?>
    <div class="wrap dbb-wrap">
        <!-- Header -->
        <div class="dbb-header">
            <h1>
                <span class="dashicons dashicons-chart-line"></span>
                Sales Monitor
            </h1>
            <p>Track product costs, profits, sales reports, and expenses</p>
        </div>
        
        <div class="dbb-card">
            <div class="dbb-card-body">
            <?php dbb_display_breadcrumbs(); ?>
        
        <nav class="nav-tab-wrapper">
            <a href="<?php echo admin_url('admin.php?page=dbb-management&service=sales_monitor&tab=products'); ?>" class="nav-tab <?php echo $tab === 'products' ? 'nav-tab-active' : ''; ?>">Product Costs</a>
            <a href="<?php echo admin_url('admin.php?page=dbb-management&service=sales_monitor&tab=reports'); ?>" class="nav-tab <?php echo $tab === 'reports' ? 'nav-tab-active' : ''; ?>">Sales Reports</a>
            <a href="<?php echo admin_url('admin.php?page=dbb-management&service=sales_monitor&tab=expenses'); ?>" class="nav-tab <?php echo $tab === 'expenses' ? 'nav-tab-active' : ''; ?>">Expenses</a>
        </nav>
        
        <div class="tab-content">
            <?php
            if ($tab === 'products') {
                sm_render_products_tab();
            } elseif ($tab === 'reports') {
                sm_render_reports_tab();
            } elseif ($tab === 'expenses') {
                sm_render_expenses_tab();
            }
            ?>
        </div>
        </div>
        </div>
    </div>
    <?php
}

// AJAX handler for getting product variations
add_action('wp_ajax_sm_get_variations', 'sm_get_variations_handler');
function sm_get_variations_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if (empty($product_id)) {
        wp_send_json_error('No product ID provided');
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error('Product not found');
    }
    
    $options = '<option value="">Select a Variation</option>';
    
    if ($product->is_type('variable') || $product->is_type('variable-subscription')) {
        $variations = $product->get_available_variations();
        foreach ($variations as $variation) {
            $variation_obj = wc_get_product($variation['variation_id']);
            $attributes = $variation_obj->get_variation_attributes();
            $attr_names = array();
            foreach ($attributes as $attr_name => $attr_value) {
                $taxonomy = str_replace('attribute_', '', $attr_name);
                $term_name = get_term_by('slug', $attr_value, $taxonomy);
                $attr_names[] = $term_name ? $term_name->name : $attr_value;
            }
            $variation_name = implode(' - ', $attr_names);
            $options .= '<option value="' . esc_attr($variation['variation_id']) . '">' . esc_html($variation_name) . '</option>';
        }
    }
    
    wp_send_json_success($options);
}

// Render the Products tab
function sm_render_products_tab() {
    // Handle cost deletion
    if (isset($_POST['delete_cost'])) {
        $cost_id = intval($_POST['delete_cost']);
        if (current_user_can('delete_post', $cost_id)) {
            wp_delete_post($cost_id, true);
            echo '<div class="notice notice-success"><p>Product cost deleted successfully!</p></div>';
        } else {
            echo '<div class="notice notice-error"><p>You do not have permission to delete this cost.</p></div>';
        }
    }

    // Handle form submission
    if (isset($_POST['submit_cost'])) {
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $cost = isset($_POST['cost']) ? floatval($_POST['cost']) : 0;
        $is_subscription = isset($_POST['is_subscription']) ? true : false;
        $subscription_period = isset($_POST['subscription_period']) ? sanitize_text_field($_POST['subscription_period']) : '';
        
        if (empty($product_id)) {
            echo '<div class="notice notice-error"><p>Please select a product.</p></div>';
        } else {
            // Get sales price from product
            $product = wc_get_product($variation_id ? $variation_id : $product_id);
            $sales_price = $product ? $product->get_price() : 0;
            
            if ($sales_price <= $cost) {
                echo '<div class="notice notice-error"><p>Cost must be less than the product price.</p></div>';
            } else {
                // Calculate profit
                $profit = $sales_price - $cost;
                
                // Create or update product cost
                $post_id = wp_insert_post(array(
                    'post_title' => 'Cost for ' . get_the_title($product_id) . ($variation_id ? ' (Variation #' . $variation_id . ')' : ''),
                    'post_type' => 'product-costs',
                    'post_status' => 'publish'
                ));
                
                if ($post_id) {
                    update_post_meta($post_id, '_product_id', $product_id);
                    update_post_meta($post_id, '_variation_id', $variation_id);
                    update_post_meta($post_id, '_cost', $cost);
                    update_post_meta($post_id, '_sales_price', $sales_price);
                    update_post_meta($post_id, '_profit', $profit);
                    update_post_meta($post_id, '_is_subscription', $is_subscription);
                    update_post_meta($post_id, '_subscription_period', $subscription_period);
                    
                    echo '<div class="notice notice-success"><p>Product cost saved successfully!</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p>Failed to save product cost.</p></div>';
                }
            }
        }
    }
    
    // Get all product costs
    $costs = get_posts(array(
        'post_type' => 'product-costs',
        'posts_per_page' => -1,
        'order' => 'DESC',
        'orderby' => 'date'
    ));
    
    // Get WooCommerce products
    $products = wc_get_products(array(
        'limit' => -1,
        'status' => 'publish',
        'type' => array('simple', 'variable', 'subscription', 'variable-subscription')
    ));
    ?>
    <div class="sm-products">
        <h2>Product Costs and Profits</h2>
        
        <form method="post" action="" class="sm-cost-form">
            <table class="form-table">
                <tr>
                    <th><label for="product_id">Product</label></th>
                    <td>
                        <select name="product_id" id="product_id" class="regular-text" required>
                            <option value="">Select a Product</option>
                            <?php
                            foreach ($products as $product) {
                                $product_type = $product->get_type();
                                $type_label = '';
                                if ($product_type === 'subscription') {
                                    $type_label = ' (Subscription)';
                                } elseif ($product_type === 'variable-subscription') {
                                    $type_label = ' (Variable Subscription)';
                                }
                                echo '<option value="' . esc_attr($product->get_id()) . '">' . esc_html($product->get_name() . $type_label) . '</option>';
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="variation_id">Variation (Optional)</label></th>
                    <td>
                        <select name="variation_id" id="variation_id" class="regular-text">
                            <option value="">Select a Variation</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="is_subscription">Subscription Product</label></th>
                    <td>
                        <input type="checkbox" name="is_subscription" id="is_subscription" value="1">
                        <p class="description">Check if this is a subscription product</p>
                    </td>
                </tr>
                <tr class="subscription-period-row" style="display: none;">
                    <th><label for="subscription_period">Subscription Period</label></th>
                    <td>
                        <select name="subscription_period" id="subscription_period" class="regular-text">
                            <option value="day">Daily</option>
                            <option value="week">Weekly</option>
                            <option value="month">Monthly</option>
                            <option value="year">Yearly</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="cost">Cost</label></th>
                    <td>
                        <input type="number" name="cost" id="cost" class="regular-text" step="0.01" min="0" required>
                        <p class="description">Enter the cost of the product. Profit will be calculated automatically.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Sales Price</label></th>
                    <td>
                        <strong id="sales_price_display">-</strong>
                        <p class="description">Sales price is taken from the product's price in WooCommerce.</p>
                    </td>
                </tr>
                <tr>
                    <th><label>Profit</label></th>
                    <td>
                        <span id="profit_display">-</span>
                        <p class="description">Profit is calculated automatically (Sales Price - Cost).</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_cost" class="button button-primary" value="Save Cost">
            </p>
        </form>
        
        <h3>Current Product Costs</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Product</th>
                    <th>Variation</th>
                    <th>Type</th>
                    <th>Cost</th>
                    <th>Sales Price</th>
                    <th>Profit</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($costs)) {
                    echo '<tr><td colspan="7">No product costs found.</td></tr>';
                } else {
                    foreach ($costs as $cost) {
                        $product_id = get_post_meta($cost->ID, '_product_id', true);
                        $variation_id = get_post_meta($cost->ID, '_variation_id', true);
                        $cost_value = get_post_meta($cost->ID, '_cost', true);
                        $sales_price = get_post_meta($cost->ID, '_sales_price', true);
                        $profit = get_post_meta($cost->ID, '_profit', true);
                        $is_subscription = get_post_meta($cost->ID, '_is_subscription', true);
                        $subscription_period = get_post_meta($cost->ID, '_subscription_period', true);
                        
                        $product = wc_get_product($product_id);
                        $product_name = $product ? $product->get_name() : 'Product Not Found';
                        
                        $variation_name = '';
                        if ($variation_id) {
                            $variation = wc_get_product($variation_id);
                            if ($variation) {
                                $attributes = $variation->get_variation_attributes();
                                $attr_names = array();
                                foreach ($attributes as $attr_name => $attr_value) {
                                    $taxonomy = str_replace('attribute_', '', $attr_name);
                                    $term_name = get_term_by('slug', $attr_value, $taxonomy);
                                    $attr_names[] = $term_name ? $term_name->name : $attr_value;
                                }
                                $variation_name = implode(' - ', $attr_names);
                            }
                        }
                        
                        $type_label = $is_subscription ? 'Subscription (' . ucfirst($subscription_period) . ')' : 'Regular';
                        ?>
                        <tr id="cost-<?php echo esc_attr($cost->ID); ?>">
                            <td><?php echo esc_html($product_name); ?></td>
                            <td><?php echo esc_html($variation_name); ?></td>
                            <td><?php echo esc_html($type_label); ?></td>
                            <td><?php echo wc_price($cost_value); ?></td>
                            <td><?php echo wc_price($sales_price); ?></td>
                            <td><?php echo wc_price($profit); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=dbb-management&service=sales_monitor&tab=products&edit=' . $cost->ID); ?>" class="button button-small">Edit</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this cost?');">
                                    <input type="hidden" name="delete_cost" value="<?php echo esc_attr($cost->ID); ?>">
                                    <button type="submit" class="button button-small button-link-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Show/hide subscription period based on checkbox
        $('#is_subscription').on('change', function() {
            if ($(this).is(':checked')) {
                $('.subscription-period-row').show();
            } else {
                $('.subscription-period-row').hide();
            }
        });
        
        var currentPrice = 0;
        var currentCostId = null;
        
        function checkProductExists() {
            var product_id = $('#product_id').val();
            var variation_id = $('#variation_id').val() || 0;
            
            if (!product_id) return;
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sm_check_product_exists',
                    product_id: product_id,
                    variation_id: variation_id,
                    nonce: dbbAdmin.nonce
                },
                success: function(response) {
                    if (response.success && response.data.exists) {
                        $('.sm-duplicate-warning').remove();
                        var warningHtml = '<div class="notice notice-warning sm-duplicate-warning">' +
                            '<p>' + response.data.message + '</p>' +
                            '<p><a href="#cost-' + response.data.cost_id + '" class="button">View Existing Entry</a></p>' +
                            '</div>';
                        $('.sm-cost-form').prepend(warningHtml);
                        
                        // Optionally disable the submit button
                        $('input[name="submit_cost"]').prop('disabled', true);
                    } else {
                        $('.sm-duplicate-warning').remove();
                        $('input[name="submit_cost"]').prop('disabled', false);
                    }
                }
            });
        }
        
        // When product is selected, check if it exists
        $('#product_id').on('change', function() {
            var product_id = $(this).val();
            if (!product_id) {
                $('#variation_id').html('<option value="">Select a Variation</option>');
                $('#sales_price_display').text('-');
                $('#profit_display').text('-');
                currentPrice = 0;
                $('.sm-duplicate-warning').remove();
                $('input[name="submit_cost"]').prop('disabled', false);
                return;
            }
            
            // Check if product exists
            checkProductExists();
            
            // Get product price
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sm_get_product_price',
                    product_id: product_id,
                    nonce: dbbAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#sales_price_display').text(response.data.price);
                        currentPrice = parseFloat(response.data.raw_price);
                        updateProfit();
                    }
                }
            });
            
            // Get variations
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sm_get_variations',
                    product_id: product_id,
                    nonce: dbbAdmin.nonce
                },
                beforeSend: function() {
                    $('#variation_id').html('<option value="">Loading variations...</option>');
                },
                success: function(response) {
                    if (response.success) {
                        $('#variation_id').html(response.data);
                    } else {
                        $('#variation_id').html('<option value="">No variations found</option>');
                    }
                },
                error: function() {
                    $('#variation_id').html('<option value="">Error loading variations</option>');
                }
            });
        });
        
        // When variation is selected, check if it exists
        $('#variation_id').on('change', function() {
            checkProductExists();
            
            var variation_id = $(this).val();
            if (!variation_id) {
                var product_id = $('#product_id').val();
                if (product_id) {
                    $('#product_id').trigger('change');
                }
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'sm_get_variation_price',
                    variation_id: variation_id,
                    nonce: dbbAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#sales_price_display').text(response.data.price);
                        currentPrice = parseFloat(response.data.raw_price);
                        updateProfit();
                    }
                }
            });
        });
        
        // Calculate profit when cost changes
        $('#cost').on('input', function() {
            updateProfit();
        });
        
        function updateProfit() {
            var cost = parseFloat($('#cost').val()) || 0;
            var profit = currentPrice - cost;
            
            if (cost <= 0) {
                $('#cost').addClass('error');
                $('#profit_display').text('Please enter a valid cost');
            } else if (cost >= currentPrice) {
                $('#cost').addClass('error');
                $('#profit_display').text('Cost must be less than sales price');
            } else {
                $('#cost').removeClass('error');
                $('#profit_display').text(profit.toFixed(2));
            }
        }
    });
    </script>
    <?php
}

// AJAX handler for getting product price
add_action('wp_ajax_sm_get_product_price', 'sm_get_product_price_handler');
function sm_get_product_price_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    if (empty($product_id)) {
        wp_send_json_error('No product ID provided');
    }
    
    $product = wc_get_product($product_id);
    if (!$product) {
        wp_send_json_error('Product not found');
    }
    
    $price = $product->get_price();
    $formatted_price = html_entity_decode(wp_strip_all_tags(wc_price($price)));
    
    wp_send_json_success(array(
        'price' => $formatted_price,
        'raw_price' => $price
    ));
}

// AJAX handler for getting variation price
add_action('wp_ajax_sm_get_variation_price', 'sm_get_variation_price_handler');
function sm_get_variation_price_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    if (empty($variation_id)) {
        wp_send_json_error('No variation ID provided');
    }
    
    $variation = wc_get_product($variation_id);
    if (!$variation) {
        wp_send_json_error('Variation not found');
    }
    
    $price = $variation->get_price();
    $formatted_price = html_entity_decode(wp_strip_all_tags(wc_price($price)));
    
    wp_send_json_success(array(
        'price' => $formatted_price,
        'raw_price' => $price
    ));
}

// Render the Reports tab
function sm_render_reports_tab() {
    // Handle messages
    if (isset($_GET['message'])) {
        if ($_GET['message'] === 'deleted') {
            echo '<div class="notice notice-success"><p>Sales report has been moved to trash.</p></div>';
        } elseif ($_GET['message'] === 'updated') {
            echo '<div class="notice notice-success"><p>Sales report has been updated.</p></div>';
        }
    }

    // Get date range
    $start_date = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : date('Y-m-d', strtotime('-30 days'));
    $end_date = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : date('Y-m-d');
    
    // Get sales data
    $sales_data = sm_get_sales_data($start_date, $end_date);
    ?>
    <div class="sm-reports">
        <h2>Sales Reports</h2>
        
        <form method="get" action="" class="sm-date-filter">
            <input type="hidden" name="page" value="dbb-management">
            <input type="hidden" name="service" value="sales_monitor">
            <input type="hidden" name="tab" value="reports">
            
            <table class="form-table">
                <tr>
                    <th><label for="start_date">Start Date</label></th>
                    <td>
                        <input type="date" name="start_date" id="start_date" value="<?php echo esc_attr($start_date); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="end_date">End Date</label></th>
                    <td>
                        <input type="date" name="end_date" id="end_date" value="<?php echo esc_attr($end_date); ?>" required>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" class="button button-primary" value="Generate Report">
            </p>
        </form>
        
        <div class="sm-report-summary">
            <h3>Sales Summary</h3>
            <div class="sm-summary-grid">
                <div class="sm-summary-card">
                    <h4>Total Sales</h4>
                    <p class="sm-summary-value"><?php echo wc_price($sales_data['total_sales']); ?></p>
                </div>
                <div class="sm-summary-card">
                    <h4>Total Cost</h4>
                    <p class="sm-summary-value"><?php echo wc_price($sales_data['total_cost']); ?></p>
                </div>
                <div class="sm-summary-card">
                    <h4>Total Profit</h4>
                    <p class="sm-summary-value"><?php echo wc_price($sales_data['total_profit']); ?></p>
                </div>
                <div class="sm-summary-card">
                    <h4>Total Orders</h4>
                    <p class="sm-summary-value"><?php echo esc_html($sales_data['total_orders']); ?></p>
                </div>
            </div>
        </div>
        
        <div class="sm-report-details">
            <h3>Sales Details</h3>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Order ID</th>
                        <th>Product</th>
                        <th>Variation</th>
                        <th>Quantity</th>
                        <th>Total</th>
                        <th>Cost</th>
                        <th>Profit</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    if (empty($sales_data['details'])) {
                        echo '<tr><td colspan="9">No sales data found for the selected period.</td></tr>';
                    } else {
                        foreach ($sales_data['details'] as $sale) {
                            ?>
                            <tr id="sale-<?php echo esc_attr($sale['order_id']); ?>">
                                <td><?php echo esc_html($sale['date']); ?></td>
                                <td><a href="<?php echo admin_url('post.php?post=' . $sale['order_id'] . '&action=edit'); ?>">#<?php echo esc_html($sale['order_id']); ?></a></td>
                                <td><?php echo esc_html($sale['product_name']); ?></td>
                                <td><?php echo esc_html($sale['variation_name']); ?></td>
                                <td><?php echo esc_html($sale['quantity']); ?></td>
                                <td><?php echo wc_price($sale['total']); ?></td>
                                <td class="editable" data-field="cost"><?php echo wc_price($sale['cost']); ?></td>
                                <td class="editable" data-field="profit"><?php echo wc_price($sale['profit']); ?></td>
                                <td>
                                    <button type="button" class="button button-small edit-sale" data-id="<?php echo esc_attr($sale['order_id']); ?>">Edit</button>
                                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this sales report?');">
                                        <input type="hidden" name="action" value="delete_sales_report">
                                        <input type="hidden" name="report_id" value="<?php echo esc_attr($sale['order_id']); ?>">
                                        <input type="hidden" name="delete_report" value="1">
                                        <?php wp_nonce_field('delete_sales_report'); ?>
                                        <button type="submit" class="button button-small button-link-delete">Delete</button>
                                    </form>
                                </td>
                            </tr>
                            <?php
                        }
                    }
                    ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Edit Sale Modal -->
    <div id="edit-sale-modal" class="sm-modal" style="display: none;">
        <div class="sm-modal-content">
            <span class="sm-modal-close">&times;</span>
            <h3>Edit Sales Report</h3>
            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="edit-sale-form">
                <input type="hidden" name="action" value="update_sales_report">
                <input type="hidden" name="order_id" value="">
                <?php wp_nonce_field('update_sales_report'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="edit_cost">Cost</label></th>
                        <td>
                            <input type="number" name="total_cost" id="edit_cost" step="0.01" min="0" required>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_profit">Profit</label></th>
                        <td>
                            <input type="number" name="total_profit" id="edit_profit" step="0.01" min="0" required>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="update_report" class="button button-primary" value="Update Report">
                </p>
            </form>
        </div>
    </div>

    <style>
    .sm-modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
    }
    
    .sm-modal-content {
        background-color: #fefefe;
        margin: 15% auto;
        padding: 20px;
        border: 1px solid #888;
        width: 50%;
        position: relative;
        border-radius: 4px;
    }
    
    .sm-modal-close {
        position: absolute;
        right: 10px;
        top: 5px;
        font-size: 20px;
        cursor: pointer;
    }
    
    .editable {
        cursor: pointer;
    }
    
    .editable:hover {
        background-color: #f0f0f1;
    }
    </style>

    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // Edit sale button click
        $('.edit-sale').on('click', function() {
            var row = $(this).closest('tr');
            var orderId = $(this).data('id');
            var cost = row.find('td[data-field="cost"]').text().replace(/[^0-9.-]+/g, '');
            var profit = row.find('td[data-field="profit"]').text().replace(/[^0-9.-]+/g, '');
            
            $('#edit-sale-modal input[name="order_id"]').val(orderId);
            $('#edit_cost').val(cost);
            $('#edit_profit').val(profit);
            
            $('#edit-sale-modal').show();
        });
        
        // Close modal
        $('.sm-modal-close').on('click', function() {
            $('#edit-sale-modal').hide();
        });
        
        // Close modal when clicking outside
        $(window).on('click', function(e) {
            if ($(e.target).hasClass('sm-modal')) {
                $('.sm-modal').hide();
            }
        });
        
        // Prevent modal close when clicking inside
        $('.sm-modal-content').on('click', function(e) {
            e.stopPropagation();
        });
    });
    </script>
    <?php
}

// Render the Expenses tab
function sm_render_expenses_tab() {
    // Handle form submission
    if (isset($_POST['submit_expense'])) {
        $expense_name = sanitize_text_field($_POST['expense_name']);
        $expense_amount = floatval($_POST['expense_amount']);
        $expense_date = sanitize_text_field($_POST['expense_date']);
        $expense_category = sanitize_text_field($_POST['expense_category']);
        
        if (empty($expense_name) || empty($expense_amount) || empty($expense_date)) {
            echo '<div class="notice notice-error"><p>Please fill in all required fields.</p></div>';
        } else {
            // Create expense
            $post_id = wp_insert_post(array(
                'post_title' => $expense_name,
                'post_type' => 'expense',
                'post_status' => 'publish'
            ));
            
            if ($post_id) {
                update_post_meta($post_id, '_expense_amount', $expense_amount);
                update_post_meta($post_id, '_expense_date', $expense_date);
                update_post_meta($post_id, '_expense_category', $expense_category);
                
                echo '<div class="notice notice-success"><p>Expense added successfully!</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Failed to add expense.</p></div>';
            }
        }
    }
    
    // Get all expenses
    $expenses = get_posts(array(
        'post_type' => 'expense',
        'posts_per_page' => -1,
        'order' => 'DESC',
        'orderby' => 'date'
    ));
    ?>
    <div class="sm-expenses">
        <h2>Expenses</h2>
        
        <form method="post" action="" class="sm-expense-form">
            <table class="form-table">
                <tr>
                    <th><label for="expense_name">Expense Name</label></th>
                    <td>
                        <input type="text" name="expense_name" id="expense_name" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="expense_amount">Amount</label></th>
                    <td>
                        <input type="number" name="expense_amount" id="expense_amount" class="regular-text" step="0.01" min="0" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="expense_date">Date</label></th>
                    <td>
                        <input type="date" name="expense_date" id="expense_date" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="expense_category">Category</label></th>
                    <td>
                        <select name="expense_category" id="expense_category" class="regular-text">
                            <option value="general">General</option>
                            <option value="marketing">Marketing</option>
                            <option value="utilities">Utilities</option>
                            <option value="rent">Rent</option>
                            <option value="salaries">Salaries</option>
                            <option value="other">Other</option>
                        </select>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_expense" class="button button-primary" value="Add Expense">
            </p>
        </form>
        
        <h3>Current Expenses</h3>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Name</th>
                    <th>Category</th>
                    <th>Amount</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($expenses)) {
                    echo '<tr><td colspan="5">No expenses found.</td></tr>';
                } else {
                    foreach ($expenses as $expense) {
                        $amount = get_post_meta($expense->ID, '_expense_amount', true);
                        $date = get_post_meta($expense->ID, '_expense_date', true);
                        $category = get_post_meta($expense->ID, '_expense_category', true);
                        ?>
                        <tr>
                            <td><?php echo esc_html($date); ?></td>
                            <td><?php echo esc_html($expense->post_title); ?></td>
                            <td><?php echo esc_html(ucfirst($category)); ?></td>
                            <td><?php echo wc_price($amount); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=dbb-management&service=sales_monitor&tab=expenses&edit=' . $expense->ID); ?>" class="button button-small">Edit</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this expense?');">
                                    <input type="hidden" name="delete_expense" value="<?php echo esc_attr($expense->ID); ?>">
                                    <button type="submit" class="button button-small button-link-delete">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php
                    }
                }
                ?>
            </tbody>
        </table>
    </div>
    <?php
}

// Helper function to get sales data
function sm_get_sales_data($start_date, $end_date) {
    global $wpdb;
    
    $data = array(
        'total_sales' => 0,
        'total_cost' => 0,
        'total_profit' => 0,
        'total_orders' => 0,
        'subscription_revenue' => 0,
        'details' => array()
    );
    
    // Get completed orders within date range
    $orders = wc_get_orders(array(
        'status' => 'completed',
        'date_created' => $start_date . '...' . $end_date,
        'limit' => -1
    ));
    
    foreach ($orders as $order) {
        $data['total_orders']++;
        
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $variation_id = $item->get_variation_id();
            $quantity = $item->get_quantity();
            $total = $item->get_total();
            
            // Get product cost
            $cost_post = get_posts(array(
                'post_type' => 'product-costs',
                'posts_per_page' => 1,
                'meta_query' => array(
                    'relation' => 'AND',
                    array(
                        'key' => '_product_id',
                        'value' => $product_id,
                        'compare' => '='
                    ),
                    array(
                        'key' => '_variation_id',
                        'value' => $variation_id ? $variation_id : 0,
                        'compare' => '='
                    )
                )
            ));
            
            $cost = 0;
            $is_subscription = false;
            $subscription_period = '';
            
            if (!empty($cost_post)) {
                $cost = get_post_meta($cost_post[0]->ID, '_cost', true) * $quantity;
                $is_subscription = get_post_meta($cost_post[0]->ID, '_is_subscription', true);
                $subscription_period = get_post_meta($cost_post[0]->ID, '_subscription_period', true);
            }
            
            $profit = $total - $cost;
            
            $data['total_sales'] += $total;
            $data['total_cost'] += $cost;
            $data['total_profit'] += $profit;
            
            if ($is_subscription) {
                $data['subscription_revenue'] += $total;
            }
            
            $data['details'][] = array(
                'date' => $order->get_date_created()->date_i18n(get_option('date_format')),
                'order_id' => $order->get_id(),
                'product_name' => $item->get_name(),
                'variation_name' => $variation_id ? wc_get_product($variation_id)->get_name() : '',
                'quantity' => $quantity,
                'total' => $total,
                'cost' => $cost,
                'profit' => $profit,
                'is_subscription' => $is_subscription,
                'subscription_period' => $subscription_period
            );
        }
    }
    
    return $data;
}

// Register the Sales Monitor in DBB modules
function sm_register_in_dbb_modules($modules) {
    $modules['sales_monitor'] = array(
        'name' => 'Sales Monitor',
        'description' => 'Track product costs, profits, sales reports, and expenses',
        'slug' => 'sales-monitor',
        'icon' => 'sales-monitor-icon',
        'dashicon' => 'dashicons-chart-line'
    );
    return $modules;
}
add_filter('dbb_modules', 'sm_register_in_dbb_modules');

// Create necessary post types on plugin activation
register_activation_hook(DBB_PLUGIN_DIR . 'woocommerce-summary.php', 'sm_plugin_activation');
function sm_plugin_activation() {
    // Register post type for expenses
    $args = array(
        'public' => false,
        'label'  => 'Expenses',
        'supports' => array('title'),
        'show_ui' => false,
        'show_in_menu' => false,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'has_archive' => false,
        'rewrite' => false
    );
    register_post_type('expense', $args);
    
    // Flush rewrite rules to enable the post types
    flush_rewrite_rules();
}

// Add this function to check if product cost exists
function sm_check_product_cost_exists($product_id, $variation_id = 0) {
    $args = array(
        'post_type' => 'product-costs',
        'posts_per_page' => 1,
        'meta_query' => array(
            'relation' => 'AND',
            array(
                'key' => '_product_id',
                'value' => $product_id,
                'compare' => '='
            ),
            array(
                'key' => '_variation_id',
                'value' => $variation_id,
                'compare' => '='
            )
        )
    );
    
    $existing_costs = get_posts($args);
    return !empty($existing_costs) ? $existing_costs[0] : false;
}

// Add AJAX handler to check for existing product
add_action('wp_ajax_sm_check_product_exists', 'sm_check_product_exists_handler');
function sm_check_product_exists_handler() {
    check_ajax_referer('dbb_admin_nonce', 'nonce');
    
    $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
    $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
    
    $existing_cost = sm_check_product_cost_exists($product_id, $variation_id);
    
    if ($existing_cost) {
        $product = wc_get_product($product_id);
        $product_name = $product ? $product->get_name() : 'Product';
        if ($variation_id) {
            $variation = wc_get_product($variation_id);
            $variation_name = $variation ? ' - ' . implode(', ', $variation->get_variation_attributes()) : '';
            $product_name .= $variation_name;
        }
        
        $cost = get_post_meta($existing_cost->ID, '_cost', true);
        $sales_price = get_post_meta($existing_cost->ID, '_sales_price', true);
        
        wp_send_json_success(array(
            'exists' => true,
            'message' => sprintf('This product (%s) already exists in the cost inventory with Cost: %s and Sales Price: %s', 
                $product_name, 
                wc_price($cost), 
                wc_price($sales_price)
            ),
            'cost_id' => $existing_cost->ID
        ));
    } else {
        wp_send_json_success(array(
            'exists' => false
        ));
    }
}

// Add this function to handle sales report deletion
function sm_delete_sales_report() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    if (isset($_POST['delete_report']) && isset($_POST['report_id'])) {
        $report_id = intval($_POST['report_id']);
        $order = wc_get_order($report_id);
        
        if ($order) {
            // Don't actually delete the order, just update its status
            $order->update_status('trash', 'Sales report moved to trash');
            wp_redirect(add_query_arg(['message' => 'deleted'], wp_get_referer()));
            exit;
        }
    }
}
add_action('admin_post_delete_sales_report', 'sm_delete_sales_report');

// Add this function to handle sales report updates
function sm_update_sales_report() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized access');
    }

    if (isset($_POST['update_report'])) {
        $order_id = intval($_POST['order_id']);
        $order = wc_get_order($order_id);
        
        if ($order) {
            // Update order meta data
            if (isset($_POST['total_cost'])) {
                $order->update_meta_data('_total_cost', floatval($_POST['total_cost']));
            }
            if (isset($_POST['total_profit'])) {
                $order->update_meta_data('_total_profit', floatval($_POST['total_profit']));
            }
            $order->save();
            
            wp_redirect(add_query_arg(['message' => 'updated'], wp_get_referer()));
            exit;
        }
    }
}
add_action('admin_post_update_sales_report', 'sm_update_sales_report');
