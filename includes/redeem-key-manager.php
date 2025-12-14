<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Redeem Key Manager Module
 * Handles functionality related to redeem key management for WooCommerce products
 */

// Register Custom Post Type for Redeem Keys
function rkm_register_custom_post_type() {
    $args = array(
        'public' => false,
        'label'  => 'Redeem Keys',
        'supports' => array('title'),
        'menu_icon' => 'dashicons-key',
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
    register_post_type('redeem-keys', $args);

    // Register post meta
    register_post_meta('redeem-keys', '_product_id', array(
        'type' => 'number',
        'single' => true,
        'show_in_rest' => true,
    ));
    register_post_meta('redeem-keys', '_variation_id', array(
        'type' => 'number',
        'single' => true,
        'show_in_rest' => true,
    ));
    register_post_meta('redeem-keys', '_keys', array(
        'type' => 'string',
        'single' => true,
        'show_in_rest' => true,
    ));
}
add_action('init', 'rkm_register_custom_post_type', 0);

// Add product meta field to mark products as redeem key products
function rkm_register_product_meta() {
    register_post_meta('product', '_rkm_is_redeem_key_product', array(
        'type' => 'boolean',
        'single' => true,
        'show_in_rest' => true,
    ));
}
add_action('init', 'rkm_register_product_meta');

// Add checkbox to product edit page
function rkm_add_product_redeem_key_checkbox() {
    add_meta_box(
        'rkm_redeem_key_meta',
        '🔑 Redeem Key Settings',
        'rkm_render_redeem_key_meta_box',
        'product',
        'side',
        'default'
    );
}
add_action('add_meta_boxes', 'rkm_add_product_redeem_key_checkbox');

// Render the meta box
function rkm_render_redeem_key_meta_box($post) {
    $is_redeem_key = get_post_meta($post->ID, '_rkm_is_redeem_key_product', true);
    wp_nonce_field('rkm_save_nonce', 'rkm_nonce');
    ?>
    <div style="padding: 10px 0;">
        <label>
            <input type="checkbox" name="_rkm_is_redeem_key_product" value="1" <?php checked($is_redeem_key, 1); ?> />
            <strong>This is a Redeem Key Product</strong>
        </label>
        <p class="description" style="margin-top: 10px;">
            Check this box to make this product available for redeem key management. It will only appear in the Redeem Key Manager import interface.
        </p>
    </div>
    <?php
}

// Save the meta box
function rkm_save_product_meta($post_id) {
    if (!isset($_POST['rkm_nonce']) || !wp_verify_nonce($_POST['rkm_nonce'], 'rkm_save_nonce')) {
        return;
    }
    
    $is_redeem_key = isset($_POST['_rkm_is_redeem_key_product']) ? 1 : 0;
    update_post_meta($post_id, '_rkm_is_redeem_key_product', $is_redeem_key);
}
add_action('save_post_product', 'rkm_save_product_meta');

// Function to update product stock based on available keys
function rkm_update_product_stock($product_id, $variation_id, $available_keys_count) {
    $target_product_id = $variation_id ? $variation_id : $product_id;
    
    if (!$target_product_id) {
        return false;
    }
    
    $product = wc_get_product($target_product_id);
    if (!$product) {
        return false;
    }
    
    // Update stock quantity to match available keys
    $product->set_stock_quantity($available_keys_count);
    
    // Automatically manage stock
    $product->set_manage_stock(true);
    
    // Set backorders to not allowed
    $product->set_backorders('no');
    
    // Save the product
    return $product->save();
}

// Function to sync all product stocks with available keys
function rkm_sync_all_stocks() {
    $key_sets = get_posts(array(
        'post_type' => 'redeem-keys',
        'posts_per_page' => -1
    ));
    
    foreach ($key_sets as $key_set) {
        $product_id = get_post_meta($key_set->ID, '_product_id', true);
        $variation_id = get_post_meta($key_set->ID, '_variation_id', true);
        $keys = get_post_meta($key_set->ID, '_keys', true);
        
        $keys_array = !empty($keys) ? array_filter(array_map('trim', explode("\n", $keys))) : array();
        $total_keys = count($keys_array);
        
        // Get delivered keys count
        $delivered_keys = get_post_meta($key_set->ID, '_delivered_keys', true);
        $delivered_array = !empty($delivered_keys) ? json_decode($delivered_keys, true) : array();
        $delivered_count = count($delivered_array);
        
        // Calculate available keys
        $available_count = $total_keys - $delivered_count;
        
        // Update stock
        rkm_update_product_stock($product_id, $variation_id, $available_count);
    }
}

// Register submenu under DBB Management
function rkm_add_submenu_item() {
    add_submenu_page(
        'dbb-management',          // Parent slug 
        'Redeem Key Manager',
        'Redeem Key Manager',
        'manage_options',
        'redeem-key-manager',
        'rkm_render_main_page'
    );
}
add_action('admin_menu', 'rkm_add_submenu_item');

// Enqueue modern styles
function rkm_enqueue_styles() {
    $screen = get_current_screen();
    if ($screen && strpos($screen->base, 'redeem') !== false) {
        wp_enqueue_style(
            'rkm-modern-styles',
            DBB_PLUGIN_ASSETS_URL . 'css/redeem-key-modern.css',
            array(),
            '1.0'
        );
    }
}
add_action('admin_enqueue_scripts', 'rkm_enqueue_styles');

// Main Redeem Key Manager page
function rkm_render_main_page() {
    // Check if a specific tab is requested
    $tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'manage';
    ?>
    <div class="wrap dbb-wrap">
        <!-- Header -->
        <div class="dbb-header">
            <h1>
                <span class="dashicons dashicons-admin-network"></span>
                Redeem Key Manager
            </h1>
            <p>Manage product redeem keys and automatically sync inventory with your sales</p>
        </div>
        
        <div class="dbb-alert dbb-alert-success">
            ✅ <strong>Auto-sync enabled:</strong> Stock updates automatically when you add keys and when they're delivered to customers.
        </div>
        
        <div class="dbb-card">
            <div class="dbb-card-body">
        
        <nav class="nav-tab-wrapper">
            <a href="<?php echo admin_url('admin.php?page=dbb-management&service=redeem_key_manager&tab=manage'); ?>" class="nav-tab <?php echo $tab === 'manage' ? 'nav-tab-active' : ''; ?>">📋 Manage Keys</a>
            <a href="<?php echo admin_url('admin.php?page=dbb-management&service=redeem_key_manager&tab=import'); ?>" class="nav-tab <?php echo $tab === 'import' ? 'nav-tab-active' : ''; ?>">➕ Import Keys</a>
            <a href="<?php echo admin_url('admin.php?page=dbb-management&service=redeem_key_manager&tab=delivered'); ?>" class="nav-tab <?php echo $tab === 'delivered' ? 'nav-tab-active' : ''; ?>">✓ Delivered Keys</a>
        </nav>
        
        <div class="tab-content">
            <?php
            if ($tab === 'manage') {
                rkm_render_manage_tab();
            } elseif ($tab === 'import') {
                rkm_render_import_tab();
            } elseif ($tab === 'delivered') {
                rkm_render_delivered_tab();
            }
            ?>
        </div>
        </div>
        </div>
    </div>
    <?php
}

// Render the Manage Keys tab
function rkm_render_manage_tab() {
    // Handle manual stock sync
    if (isset($_POST['sync_all_stocks'])) {
        rkm_sync_all_stocks();
        echo '<div class="notice notice-success"><p>✅ <strong>Synced!</strong> All product stocks have been updated with available keys.</p></div>';
    }
    
    // Handle key deletion if requested
    if (isset($_POST['delete_key_set']) && isset($_POST['key_post_id'])) {
        $post_id = intval($_POST['key_post_id']);
        if (current_user_can('delete_post', $post_id)) {
            // Get product info before deletion
            $product_id = get_post_meta($post_id, '_product_id', true);
            $variation_id = get_post_meta($post_id, '_variation_id', true);
            
            // Delete the post
            wp_delete_post($post_id, true);
            
            // Set stock to 0 for the product
            rkm_update_product_stock($product_id, $variation_id, 0);
            
            echo '<div class="notice notice-success"><p>Key set deleted successfully! Product stock set to 0.</p></div>';
        }
    }
    
    // Get all key sets
    $key_sets = get_posts(array(
        'post_type' => 'redeem-keys',
        'posts_per_page' => -1,
        'order' => 'DESC',
        'orderby' => 'date'
    ));
    ?>
    <div class="rkm-form-section">
        <h2>Your Redeem Key Sets</h2>
        
        <form method="post" style="margin-bottom: 20px;">
            <button type="submit" name="sync_all_stocks" class="button button-primary">
                🔄 Sync All Stock Now
            </button>
        </form>
    </div>
    
    <div class="rkm-form-section">
        <h2>📊 Manage Keys</h2>
        
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th class="column-product">Product</th>
                    <th class="column-variation">Variation</th>
                    <th class="column-total-keys">Total Keys</th>
                    <th class="column-available">Available</th>
                    <th class="column-stock">Current Stock</th>
                    <th class="column-delivered">Delivered</th>
                    <th class="column-actions">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($key_sets)) {
                    echo '<tr><td colspan="7" style="text-align: center; padding: 40px 20px;"><strong>No key sets found.</strong><br>Please import keys first using the Import tab.</td></tr>';
                } else {
                    foreach ($key_sets as $key_set) {
                        $product_id = get_post_meta($key_set->ID, '_product_id', true);
                        $variation_id = get_post_meta($key_set->ID, '_variation_id', true);
                        $keys = get_post_meta($key_set->ID, '_keys', true);
                        $keys_array = !empty($keys) ? array_filter(array_map('trim', explode("\n", $keys))) : array();
                        $total_keys = count($keys_array);
                        
                        $delivered_keys = get_post_meta($key_set->ID, '_delivered_keys', true);
                        $delivered_array = !empty($delivered_keys) ? json_decode($delivered_keys, true) : array();
                        $delivered_count = count($delivered_array);
                        $available_count = $total_keys - $delivered_count;
                        
                        // Get current product stock
                        $target_product_id = $variation_id ? $variation_id : $product_id;
                        $target_product = wc_get_product($target_product_id);
                        $current_stock = $target_product ? $target_product->get_stock_quantity() : 'N/A';
                        
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
                        
                        // Determine stock status
                        $stock_class = $available_count > 0 ? 'status-in-stock' : 'status-out-of-stock';
                        $stock_text = $available_count > 0 ? $current_stock : '<span style="color: red;">Out of Stock</span>';
                        ?>
                        <tr>
                            <td><?php echo esc_html($product_name); ?></td>
                            <td><?php echo esc_html($variation_name); ?></td>
                            <td><?php echo esc_html($total_keys); ?></td>
                            <td><?php echo esc_html($available_count); ?></td>
                            <td class="<?php echo esc_attr($stock_class); ?>"><?php echo wp_kses_post($stock_text); ?></td>
                            <td><?php echo esc_html($delivered_count); ?></td>
                            <td>
                                <a href="<?php echo admin_url('admin.php?page=dbb-management&service=redeem_key_manager&tab=import&edit=' . $key_set->ID); ?>" class="button button-small">Edit</a>
                                <form method="post" style="display:inline;" onsubmit="return confirm('Are you sure you want to delete this key set?');">
                                    <input type="hidden" name="key_post_id" value="<?php echo esc_attr($key_set->ID); ?>">
                                    <button type="submit" name="delete_key_set" class="button button-small button-link-delete">Delete</button>
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

// Render the Import Keys tab
function rkm_render_import_tab() {
    $edit_id = isset($_GET['edit']) ? intval($_GET['edit']) : 0;
    $is_edit = $edit_id > 0;
    
    // Handle form submission
    if (isset($_POST['submit_keys'])) {
        $product_id = isset($_POST['product_id']) ? intval($_POST['product_id']) : 0;
        $variation_id = isset($_POST['variation_id']) ? intval($_POST['variation_id']) : 0;
        $keys = isset($_POST['keys']) ? sanitize_textarea_field($_POST['keys']) : '';
        
        if (empty($product_id)) {
            echo '<div class="notice notice-error"><p><strong>Error:</strong> Please select a product.</p></div>';
        } elseif (empty($keys)) {
            echo '<div class="notice notice-error"><p><strong>Error:</strong> Please enter at least one key.</p></div>';
        } else {
            // Count available keys (excluding empty lines)
            $keys_array = array_filter(array_map('trim', explode("\n", $keys)));
            $available_keys_count = count($keys_array);
            
            if ($is_edit) {
                // Update existing key set
                update_post_meta($edit_id, '_product_id', $product_id);
                update_post_meta($edit_id, '_variation_id', $variation_id);
                update_post_meta($edit_id, '_keys', $keys);
                
                // Update stock for this product
                rkm_update_product_stock($product_id, $variation_id, $available_keys_count);
                
                echo '<div class="notice notice-success"><p><strong>Updated!</strong> ' . $available_keys_count . ' keys imported and stock synchronized.</p></div>';
            } else {
                // Create new key set
                $post_id = wp_insert_post(array(
                    'post_title' => 'Keys for ' . get_the_title($product_id) . ($variation_id ? ' (Variation #' . $variation_id . ')' : ''),
                    'post_type' => 'redeem-keys',
                    'post_status' => 'publish'
                ));
                
                if ($post_id) {
                    update_post_meta($post_id, '_product_id', $product_id);
                    update_post_meta($post_id, '_variation_id', $variation_id);
                    update_post_meta($post_id, '_keys', $keys);
                    
                    // Update stock for this product
                    rkm_update_product_stock($product_id, $variation_id, $available_keys_count);
                    
                    echo '<div class="notice notice-success"><p><strong>Success!</strong> ' . $available_keys_count . ' keys imported and stock synchronized.</p></div>';
                } else {
                    echo '<div class="notice notice-error"><p><strong>Error:</strong> Failed to create key set.</p></div>';
                }
            }
        }
    }
    
    // Get data for editing if in edit mode
    $edit_product_id = 0;
    $edit_variation_id = 0;
    $edit_keys = '';
    
    if ($is_edit) {
        $post = get_post($edit_id);
        if ($post && $post->post_type === 'redeem-keys') {
            $edit_product_id = get_post_meta($edit_id, '_product_id', true);
            $edit_variation_id = get_post_meta($edit_id, '_variation_id', true);
            $edit_keys = get_post_meta($edit_id, '_keys', true);
        }
    }
    
    // Get WooCommerce products that are marked as redeem key products
    $product_ids = get_posts(array(
        'post_type' => 'product',
        'posts_per_page' => -1,
        'fields' => 'ids',
        'meta_query' => array(
            array(
                'key' => '_rkm_is_redeem_key_product',
                'value' => 1,
                'compare' => '='
            )
        )
    ));
    
    $products = array();
    if (!empty($product_ids)) {
        $products = wc_get_products(array(
            'include' => $product_ids,
            'limit' => -1,
            'status' => 'publish',
            'type' => array('simple', 'variable')
        ));
    }
    ?>
    <div class="rkm-form-section">
        <h2><?php echo $is_edit ? '✏️ Edit' : '➕ Import'; ?> Redeem Keys</h2>
        
        <?php if (empty($products) && !$is_edit) { ?>
            <div class="notice notice-warning">
                <p>
                    <strong>No Redeem Key Products Available</strong><br>
                    To use the Redeem Key Manager, first mark products as "Redeem Key Products" in their product settings.
                </p>
                <p><a href="<?php echo admin_url('edit.php?post_type=product'); ?>" class="button button-primary">Go to Products</a></p>
            </div>
        <?php } ?>
        
        <form method="post" action="">
            <table class="form-table">
                <tr>
                    <th><label for="product_id">Product <span style="color: red;">*</span></label></th>
                    <td>
                        <select name="product_id" id="product_id" class="regular-text" required <?php echo empty($products) && !$is_edit ? 'disabled' : ''; ?>>
                            <option value="">Select a Redeem Key Product</option>
                            <?php
                            foreach ($products as $product) {
                                echo '<option value="' . esc_attr($product->get_id()) . '"' . selected($edit_product_id, $product->get_id(), false) . '>' . esc_html($product->get_name()) . '</option>';
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
                            <!-- This will be populated via AJAX when a product is selected -->
                            <?php
                            if ($edit_product_id && $edit_variation_id) {
                                $product = wc_get_product($edit_product_id);
                                if ($product && $product->is_type('variable')) {
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
                                        echo '<option value="' . esc_attr($variation['variation_id']) . '"' . selected($edit_variation_id, $variation['variation_id'], false) . '>' . esc_html($variation_name) . '</option>';
                                    }
                                }
                            }
                            ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="keys">Redeem Keys</label></th>
                    <td>
                        <textarea name="keys" id="keys" rows="10" class="large-text" required><?php echo esc_textarea($edit_keys); ?></textarea>
                        <p class="description">Enter one key per line. Each key will be assigned to an order when the order status changes to "completed".</p>
                    </td>
                </tr>
            </table>
            
            <p class="submit">
                <input type="submit" name="submit_keys" class="button button-primary" value="<?php echo $is_edit ? 'Update Keys' : 'Import Keys'; ?>">
            </p>
        </form>
    </div>
    
    <script type="text/javascript">
    jQuery(document).ready(function($) {
        // When product is selected, fetch variations
        $('#product_id').on('change', function() {
            var product_id = $(this).val();
            if (!product_id) {
                $('#variation_id').html('<option value="">Select a Variation</option>');
                return;
            }
            
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'rkm_get_variations',
                    product_id: product_id,
                    nonce: dbbAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#variation_id').html(response.data);
                    }
                }
            });
        });
    });
    </script>
    <?php
}

// Render the Delivered Keys tab
function rkm_render_delivered_tab() {
    // Get all delivered keys
    global $wpdb;
    
    $delivered_keys = $wpdb->get_results(
        "SELECT p.ID, pm1.meta_value as order_id, pm2.meta_value as key_value, p.post_date as delivered_date
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_order_id'
        JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_key_value'
        WHERE p.post_type = 'delivered-key'
        ORDER BY p.post_date DESC"
    );
    ?>
    <div class="rkm-form-section">
        <h2>✅ Delivered Keys</h2>
        
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th class="column-order">Order ID</th>
                    <th class="column-date">Order Date</th>
                    <th class="column-customer">Customer</th>
                    <th class="column-product">Product</th>
                    <th class="column-key">Redeem Key</th>
                    <th class="column-delivered">Delivered Date</th>
                </tr>
            </thead>
            <tbody>
                <?php
                if (empty($delivered_keys)) {
                    echo '<tr><td colspan="6" style="text-align: center; padding: 40px 20px;"><strong>No delivered keys found.</strong><br>Keys will appear here after customers purchase products.</td></tr>';
                } else {
                    foreach ($delivered_keys as $key) {
                        $order = wc_get_order($key->order_id);
                        if (!$order) {
                            continue;
                        }
                        
                        $order_date = $order->get_date_created()->date_i18n('j F Y');
                        $customer_name = $order->get_billing_first_name() . ' ' . $order->get_billing_last_name();
                        $customer_email = $order->get_billing_email();
                        
                        // Get product name from order items
                        $product_name = '';
                        foreach ($order->get_items() as $item) {
                            $product_name = $item->get_name();
                            break; // Just get the first item
                        }
                        
                        $delivered_date = date_i18n('j F Y', strtotime($key->delivered_date));
                        ?>
                        <tr>
                            <td><a href="<?php echo admin_url('post.php?post=' . $key->order_id . '&action=edit'); ?>">#<?php echo esc_html($key->order_id); ?></a></td>
                            <td><?php echo esc_html($order_date); ?></td>
                            <td><?php echo esc_html($customer_name); ?> (<?php echo esc_html($customer_email); ?>)</td>
                            <td><?php echo esc_html($product_name); ?></td>
                            <td><?php echo esc_html($key->key_value); ?></td>
                            <td><?php echo esc_html($delivered_date); ?></td>
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

// AJAX handler for getting product variations
add_action('wp_ajax_rkm_get_variations', 'rkm_get_variations_handler');
function rkm_get_variations_handler() {
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
    
    if ($product->is_type('variable')) {
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

// Register the Redeem Key Manager in DBB modules
function rkm_register_in_dbb_modules($modules) {
    $modules['redeem_key_manager'] = array(
        'name' => 'Redeem Key Manager',
        'description' => 'Manage product redeem keys and automatically deliver to customers',
        'slug' => 'redeem-key-manager',
        'icon' => 'redeem-key-icon',
        'dashicon' => 'dashicons-key'
    );
    return $modules;
}
add_filter('dbb_modules', 'rkm_register_in_dbb_modules');

// Assign redeem key when order status changes to completed
add_action('woocommerce_order_status_completed', 'rkm_assign_redeem_key');
function rkm_assign_redeem_key($order_id) {
    // Get the order
    $order = wc_get_order($order_id);
    if (!$order) {
        return;
    }
    
    // Check if this order already has a key assigned
    $existing_key = get_post_meta($order_id, '_redeem_key', true);
    if (!empty($existing_key)) {
        // Key already assigned, no need to assign again
        return;
    }
    
    // Loop through order items to find products that need keys
    foreach ($order->get_items() as $item) {
        $product_id = $item->get_product_id();
        $variation_id = $item->get_variation_id();
        
        // Find a key set that matches this product and variation
        $key_sets = get_posts(array(
            'post_type' => 'redeem-keys',
            'posts_per_page' => -1,
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
        
        if (empty($key_sets)) {
            continue; // No matching key set found for this product
        }
        
        $key_set = $key_sets[0]; // Use the first matching key set
        $keys = get_post_meta($key_set->ID, '_keys', true);
        $keys_array = !empty($keys) ? explode("\n", $keys) : array();
        
        if (empty($keys_array)) {
            continue; // No keys available in this set
        }
        
        // Get delivered keys
        $delivered_keys = get_post_meta($key_set->ID, '_delivered_keys', true);
        $delivered_array = !empty($delivered_keys) ? json_decode($delivered_keys, true) : array();
        
        // Find available keys (not yet delivered)
        $available_keys = array_diff($keys_array, array_keys($delivered_array));
        
        if (empty($available_keys)) {
            continue; // No available keys left
        }
        
        // Get the first available key
        $key = reset($available_keys);
        
        // Create a record of the delivered key
        $delivered_key_id = wp_insert_post(array(
            'post_title' => 'Redeem Key for Order #' . $order_id,
            'post_type' => 'delivered-key',
            'post_status' => 'publish'
        ));
        
        if ($delivered_key_id) {
            // Save key details
            update_post_meta($delivered_key_id, '_order_id', $order_id);
            update_post_meta($delivered_key_id, '_product_id', $product_id);
            update_post_meta($delivered_key_id, '_variation_id', $variation_id);
            update_post_meta($delivered_key_id, '_key_value', $key);
            
            // Save key to order
            update_post_meta($order_id, '_redeem_key', $key);
            
            // Update the delivered keys for this key set
            if (!is_array($delivered_array)) {
                $delivered_array = array();
            }
            $delivered_array[$key] = array(
                'order_id' => $order_id,
                'date' => current_time('mysql')
            );
            update_post_meta($key_set->ID, '_delivered_keys', json_encode($delivered_array));
            
            // Calculate remaining available keys and update stock
            $total_keys = count(array_filter(array_map('trim', explode("\n", $keys))));
            $delivered_count = count($delivered_array);
            $available_count = $total_keys - $delivered_count;
            rkm_update_product_stock($product_id, $variation_id, $available_count);
            
            // Add a note to the order
            $order->add_order_note(sprintf(
                __('Redeem key assigned: %s', 'dbb-management'),
                $key
            ));
            
            // Only process one key per order
            break;
        }
    }
}

// Display redeem key in order emails and thank you page
add_action('woocommerce_email_order_details', 'rkm_add_redeem_key_to_email', 20, 4);
function rkm_add_redeem_key_to_email($order, $sent_to_admin, $plain_text, $email) {
    // Only show in customer emails and for completed orders
    if ($sent_to_admin || $order->get_status() !== 'completed') {
        return;
    }
    
    $redeem_key = get_post_meta($order->get_id(), '_redeem_key', true);
    if (empty($redeem_key)) {
        return;
    }
    
    if ($plain_text) {
        echo "\n\n" . __('Your Redeem Key:', 'dbb-management') . " " . $redeem_key . "\n\n";
    } else {
        echo '<h2>' . __('Your Redeem Key', 'dbb-management') . '</h2>';
        echo '<p style="font-size: 16px; margin-bottom: 20px;"><strong>' . $redeem_key . '</strong></p>';
    }
}

// Display redeem key on thank you page
add_action('woocommerce_thankyou', 'rkm_add_redeem_key_to_thankyou', 10, 1);
function rkm_add_redeem_key_to_thankyou($order_id) {
    $order = wc_get_order($order_id);
    if (!$order || $order->get_status() !== 'completed') {
        return;
    }
    
    $redeem_key = get_post_meta($order_id, '_redeem_key', true);
    if (empty($redeem_key)) {
        return;
    }
    
    echo '<h2>' . __('Your Redeem Key', 'dbb-management') . '</h2>';
    echo '<p style="font-size: 16px; margin-bottom: 20px; padding: 10px; background-color: #f8f8f8; border: 1px solid #ddd; border-radius: 4px;">';
    echo '<strong>' . $redeem_key . '</strong>';
    echo '</p>';
}

// Display redeem key in order admin page
add_action('woocommerce_admin_order_data_after_order_details', 'rkm_display_redeem_key_in_admin', 10, 1);
function rkm_display_redeem_key_in_admin($order) {
    $redeem_key = get_post_meta($order->get_id(), '_redeem_key', true);
    if (empty($redeem_key)) {
        return;
    }
    
    echo '<p class="form-field"><strong>' . __('Redeem Key:', 'dbb-management') . '</strong> ' . esc_html($redeem_key) . '</p>';
}

// Create the necessary database tables and post types on plugin activation
register_activation_hook(DBB_PLUGIN_DIR . 'woocommerce-summary.php', 'rkm_plugin_activation');
function rkm_plugin_activation() {
    // Register post type for delivered keys
    $args = array(
        'public' => false,
        'label'  => 'Delivered Keys',
        'supports' => array('title'),
        'show_ui' => false,
        'show_in_menu' => false,
        'publicly_queryable' => false,
        'exclude_from_search' => true,
        'has_archive' => false,
        'rewrite' => false
    );
    register_post_type('delivered-key', $args);
    
    // Flush rewrite rules to enable the post types
    flush_rewrite_rules();
} 