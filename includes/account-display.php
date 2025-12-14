<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Display account details on WooCommerce subscription page
 */

// Add account details to subscription view
function dbb_display_account_details($subscription) {
    // Early exit if not on frontend or if subscription is invalid
    if (is_admin() || !$subscription || !is_object($subscription)) {
        return;
    }
    
    // Early exit if this is during checkout process
    if (is_checkout() || wp_doing_ajax()) {
        return;
    }
    
    try {
    // Check if subscription is active before displaying account details
    $valid_statuses = apply_filters('dbb_valid_subscription_statuses', array('active', 'pending-cancel'));
    $current_status = $subscription->get_status();
    
    // Check subscription dates for validity
    $is_expired = false;
    $has_payment_issue = false;
    $end_date = $subscription->get_date('end');
    $next_payment = $subscription->get_date('next_payment');
    
    // Check if subscription has ended
    if (!empty($end_date) && strtotime($end_date) < time()) {
        $is_expired = true;
    }
    
    // Check if next payment is overdue by more than 3 days (grace period)
    if (!empty($next_payment) && strtotime($next_payment) + (3 * DAY_IN_SECONDS) < time()) {
        $is_expired = true;
    }
    
    // Check for payment failures
    $payment_failures = 0;
    if (function_exists('wcs_get_failed_payment_count')) {
        $payment_failures = wcs_get_failed_payment_count($subscription);
        if ($payment_failures >= 2) { // 2 or more payment failures
            $has_payment_issue = true;
        }
    }
    
    // Don't show account details if subscription is not active, expired, or has payment issues
    if (!in_array($current_status, $valid_statuses) || $is_expired || $has_payment_issue) {
        $reason = 'status';
        
        if (!in_array($current_status, $valid_statuses)) {
            $reason = 'status';
        } elseif ($is_expired) {
            $reason = 'expiration';
        } elseif ($has_payment_issue) {
            $reason = 'payment';
        }
        
        echo '<div class="woocommerce-info dbb-info-notice">';
        
        if ($reason === 'status') {
            echo '<p>' . esc_html__('Account details are only available for active subscriptions. Your subscription status is: ', 'dbb-management') . 
                 '<strong>' . esc_html(wcs_get_subscription_status_name($current_status)) . '</strong></p>';
        } elseif ($reason === 'expiration') {
            echo '<p>' . esc_html__('Your subscription has expired. Account details are no longer available.', 'dbb-management') . '</p>';
        } elseif ($reason === 'payment') {
            echo '<p>' . esc_html__('There are payment issues with your subscription. Account details are temporarily unavailable.', 'dbb-management') . '</p>';
        }
        
        echo '<p>' . esc_html__('Please contact support if you believe this is an error.', 'dbb-management') . '</p>';
        echo '</div>';
        
        // Add some CSS for the notice
        ?>
        <style>
            .dbb-info-notice {
                margin: 20px 0;
                padding: 15px;
                background-color: #f8f9fa;
                border-left: 5px solid #ffb900; /* Yellow warning color */
                color: #444;
            }
            .dbb-info-notice p {
                margin: 0 0 10px 0;
            }
            .dbb-info-notice p:last-child {
                margin-bottom: 0;
            }
        </style>
        <?php
        
        return;
    }
    
    // Get all registered services
    $services = get_option('wsm_registered_services', array());
        if (empty($services)) {
            return;
        }
    
    // Get subscription ID
    $subscription_id = $subscription->get_id();
        if (!$subscription_id) {
            return;
        }
    
    echo '<h2>Your Account Details</h2>';
    
    $found_accounts = false;
    
    foreach ($services as $service_key => $service) {
        // Get linked account ID - first check subscription meta
        $linked_post_id = get_post_meta($subscription_id, '_linked_' . $service_key . '_post', true);
        
        // If not found on subscription, check related order
        if (!$linked_post_id) {
            // Get the order associated with this subscription
            $order_id = $subscription->get_parent_id();
            if ($order_id) {
                $linked_post_id = get_post_meta($order_id, '_linked_' . $service_key . '_post', true);
            }
        }
        
        if ($linked_post_id) {
            $found_accounts = true;
            
            // Get account details
            $email = get_post_meta($linked_post_id, '_summary_email', true);
            $hash = get_post_meta($linked_post_id, '_summary_hash', true);
            
            // Get order meta values - first try subscription ID
            $profile_details = wsm_get_order_profile_details($subscription_id, $service['slug']);
            
            // If no details found, try the parent order
            if (empty($profile_details) && $subscription->get_parent_id()) {
                $profile_details = wsm_get_order_profile_details($subscription->get_parent_id(), $service['slug']);
            }
            
            // Get subscription start and end dates
            $start_date = $subscription->get_date('start');
            $next_payment = $subscription->get_date('next_payment');
            $end_date = $subscription->get_date('end');
            
            // Format the dates
                $formatted_start = !empty($start_date) ? date_i18n('j F Y', strtotime($start_date)) : 'N/A';
                $formatted_next = !empty($next_payment) ? date_i18n('j F Y', strtotime($next_payment)) : 'N/A';
                $formatted_end = !empty($end_date) ? date_i18n('j F Y', strtotime($end_date)) : 'N/A';
            
            echo '<div class="dbb-account-wrapper">';
            echo '<h3 class="dbb-service-title" style="color: ' . esc_attr($service['color']) . ';">' . esc_html($service['name']) . ' Account Details</h3>';
            
            echo '<table class="dbb-account-details-table">';
            
            // Login Email row
            echo '<tr class="dbb-detail-row">';
            echo '<th class="dbb-detail-label">Login Email:</th>';
            echo '<td class="dbb-copyable-field">';
            echo '<span class="dbb-credential">' . esc_html($email) . '</span>';
            echo '<button type="button" class="dbb-copy-btn" data-copy="' . esc_attr($email) . '">Copy</button>';
            echo '</td>';
            echo '</tr>';
            
            // Password row
            echo '<tr class="dbb-detail-row">';
            echo '<th class="dbb-detail-label">Password:</th>';
            echo '<td class="dbb-copyable-field">';
            echo '<span class="dbb-credential">' . esc_html($hash) . '</span>';
            echo '<button type="button" class="dbb-copy-btn" data-copy="' . esc_attr($hash) . '">Copy</button>';
            echo '</td>';
            echo '</tr>';
            
            // Subscription Start Date row
            echo '<tr class="dbb-detail-row">';
            echo '<th class="dbb-detail-label">Start Date:</th>';
            echo '<td>' . esc_html($formatted_start) . '</td>';
            echo '</tr>';
            
            // Subscription Next Payment Date row (if available)
            if (!empty($next_payment)) {
                echo '<tr class="dbb-detail-row">';
                echo '<th class="dbb-detail-label">Next Payment:</th>';
                echo '<td>' . esc_html($formatted_next) . '</td>';
                echo '</tr>';
            }
            
            // Subscription End Date row (if available)
            if (!empty($end_date)) {
                echo '<tr class="dbb-detail-row">';
                echo '<th class="dbb-detail-label">End Date:</th>';
                echo '<td>' . esc_html($formatted_end) . '</td>';
                echo '</tr>';
            }
            
            // Display additional meta values if they exist
            if (!empty($service['meta_keys']) && !empty($profile_details)) {
                foreach ($service['meta_keys'] as $meta_key) {
                    if (isset($profile_details[$meta_key])) {
                        echo '<tr class="dbb-detail-row">';
                        echo '<th class="dbb-detail-label">' . esc_html($meta_key) . ':</th>';
                        echo '<td>' . esc_html($profile_details[$meta_key]) . '</td>';
                        echo '</tr>';
                    }
                }
            }
            
            echo '</table>';
            echo '</div>';
        }
    }
    
    if (!$found_accounts) {
        echo '<p>No account details available for this subscription.</p>';
        }
        
    } catch (Exception $e) {
        // Log error but don't break the page
        if (function_exists('dbb_log')) {
            dbb_log('Error in dbb_display_account_details: ' . $e->getMessage(), 'error');
        }
        return;
    }
    
    // Add CSS for styling
    ?>
    <style>
        h2 {
            font-size: 24px;
            margin-top: 40px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        .dbb-account-wrapper {
            margin-bottom: 30px;
            background: #f8f9fa;
            border-radius: 5px;
            padding: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }
        .dbb-service-title {
            font-size: 18px;
            padding: 15px;
            margin: 0;
            font-weight: 600;
            border-bottom: 1px solid #eee;
        }
        .dbb-account-details-table {
            width: 100%;
            border-collapse: collapse;
            margin: 0;
        }
        .dbb-detail-row {
            border-bottom: 1px solid #eee;
        }
        .dbb-detail-row:last-child {
            border-bottom: none;
        }
        .dbb-detail-label {
            padding: 12px 15px;
            text-align: left;
            width: 180px;
            background-color: #f5f5f5;
            font-weight: normal;
            color: #555;
            border-right: 1px solid #eee;
        }
        .dbb-copyable-field {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 15px;
            background-color: #fff;
        }
        .dbb-account-details-table td {
            padding: 12px 15px;
            background-color: #fff;
        }
        .dbb-credential {
            font-family: monospace;
            word-break: break-all;
        }
        .dbb-copy-btn {
            background-color: #f0f0f0;
            border: 1px solid #ddd;
            border-radius: 3px;
            padding: 3px 8px;
            font-size: 12px;
            cursor: pointer;
            margin-left: 10px;
            transition: all 0.2s ease;
        }
        .dbb-copy-btn:hover {
            background-color: #e0e0e0;
        }
        .dbb-copy-btn.copied {
            background-color: #4CAF50;
            color: white;
            border-color: #4CAF50;
        }
    </style>
    
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        // Get all copy buttons
        var copyButtons = document.querySelectorAll('.dbb-copy-btn');
        
        // Add click event to each button
        copyButtons.forEach(function(button) {
            button.addEventListener('click', function() {
                // Get the text to copy
                var textToCopy = this.getAttribute('data-copy');
                
                // Use the modern Clipboard API if available
                if (navigator.clipboard && window.isSecureContext) {
                    // Use the Clipboard API
                    navigator.clipboard.writeText(textToCopy).then(function() {
                        showCopiedFeedback(button);
                    }).catch(function(err) {
                        console.error('Could not copy text: ', err);
                        fallbackCopyMethod(textToCopy, button);
                    });
                } else {
                    // Fall back to the old method
                    fallbackCopyMethod(textToCopy, button);
                }
            });
        });
        
        // Fallback copy method using execCommand
        function fallbackCopyMethod(text, button) {
            // Create a temporary textarea element
            var textarea = document.createElement('textarea');
            textarea.value = text;
            textarea.setAttribute('readonly', '');
            textarea.style.position = 'absolute';
            textarea.style.left = '-9999px';
            document.body.appendChild(textarea);
            
            // Select and copy the text
            textarea.select();
            try {
                var success = document.execCommand('copy');
                if (success) {
                    showCopiedFeedback(button);
                } else {
                    console.error('Failed to copy');
                }
            } catch (err) {
                console.error('Error during copy', err);
            }
            
            // Remove the temporary textarea
            document.body.removeChild(textarea);
        }
        
        // Show copied feedback on button
        function showCopiedFeedback(button) {
            // Change button text and style temporarily
            var originalText = button.textContent;
            button.textContent = 'Copied!';
            button.classList.add('copied');
            
            // Reset button after 2 seconds
            setTimeout(function() {
                button.textContent = originalText;
                button.classList.remove('copied');
            }, 2000);
        }
    });
    </script>
    <?php
}

// Function to check if WooCommerce Subscriptions is active
function dbb_is_subscriptions_active() {
    return class_exists('WC_Subscriptions') && function_exists('wcs_get_subscriptions_for_order');
}

// Add a subscription notice to orders with subscriptions
function dbb_add_subscription_notice($order) {
    // Early exit if during checkout, admin, or AJAX
    if (is_checkout() || is_admin() || wp_doing_ajax()) {
        return;
    }
    
    if (!$order) {
        return;
    }

    try {
    // Only show for completed orders
    if ($order->get_status() !== 'completed') {
        return;
    }
    
    // Check if this order has related subscriptions
    $subscriptions = array();
    
    // Check if WooCommerce Subscriptions is active and get related subscriptions
    if (function_exists('wcs_get_subscriptions_for_order')) {
        $subscriptions = wcs_get_subscriptions_for_order($order->get_id());
    }
    
    if (!empty($subscriptions)) {
        echo '<div class="woocommerce-info dbb-subscription-notice">';
        echo '<p>' . esc_html__('Your account details for streaming services can be found on your subscription page:', 'dbb-management') . '</p>';
        echo '<ul class="dbb-subscription-links">';
        
        foreach ($subscriptions as $subscription) {
                if (!$subscription) {
                    continue;
                }
                
                $view_url = $subscription->get_view_order_url();
                if ($view_url) {
                    echo '<li><a href="' . esc_url($view_url) . '">' . 
                 esc_html__('View Subscription', 'dbb-management') . ' #' . esc_html($subscription->get_id()) . '</a></li>';
                }
        }
        
        echo '</ul>';
        echo '</div>';
        
        // Add some CSS for the notice
        ?>
        <style>
            .dbb-subscription-notice {
                margin: 20px 0;
                background-color: #f8f9fa;
                padding: 15px;
                border-left: 5px solid #2271b1;
            }
            .dbb-subscription-links {
                margin: 10px 0 0 20px;
                list-style-type: disc;
            }
            .dbb-subscription-links li {
                margin-bottom: 5px;
            }
        </style>
        <?php
        }
    } catch (Exception $e) {
        // Log error but don't break the page
        if (function_exists('dbb_log')) {
            dbb_log('Error in dbb_add_subscription_notice: ' . $e->getMessage(), 'error');
        }
    }
}

// Hook into WooCommerce Subscriptions view
add_action('woocommerce_subscription_details_after_subscription_table', 'dbb_display_account_details', 10, 1);

// Hook into WooCommerce order view to add subscription notice
add_action('woocommerce_order_details_after_order_table', 'dbb_add_subscription_notice', 10, 1); 

// Add hook for order status changes
add_action('woocommerce_order_status_changed', function($order_id, $old_status, $new_status) {
    // Early exit if during checkout or AJAX to prevent interference
    if (is_checkout() || wp_doing_ajax()) {
        return;
    }
    
    try {
        $order = wc_get_order($order_id);
        if (!$order) {
            return;
        }

        // If order is completed, ensure account data is properly linked
        if ($new_status === 'completed') {
            // Get all registered services
            $services = get_option('wsm_registered_services', array());
            if (empty($services)) {
                return;
            }
            
            foreach ($services as $service_key => $service) {
                $linked_post_id = get_post_meta($order_id, '_linked_' . $service_key . '_post', true);
                
                if ($linked_post_id) {
                    // If this is a subscription order, ensure subscription has the account linked
                    if (function_exists('wcs_order_contains_subscription') && wcs_order_contains_subscription($order_id)) {
                        $subscriptions = wcs_get_subscriptions_for_order($order_id);
                        foreach ($subscriptions as $subscription) {
                            dbb_copy_account_data_to_subscription($subscription->get_id(), $order_id);
                        }
                    }
                }
            }
        }
    } catch (Exception $e) {
        // Log error but don't break the process
        if (function_exists('dbb_log')) {
            dbb_log('Error in order status change handler: ' . $e->getMessage(), 'error');
        }
    }
}, 30, 3); // Increased priority to run later

// Add debug logging function if not already defined
if (!function_exists('dbb_log')) {
    function dbb_log($message, $type = 'info') {
        if (defined('WP_DEBUG') && WP_DEBUG === true) {
            error_log('DBB Management [' . strtoupper($type) . ']: ' . $message);
        }
    }
} 