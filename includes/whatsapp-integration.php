<?php
// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

/**
 * WhatsApp Integration Module - Real WhatsApp Web via Node.js
 * Connects to actual WhatsApp Web using whatsapp-web.js library
 */

class DBB_WhatsApp_Integration {
    
    private $server_url = 'http://localhost:9000';
    
    public function __construct() {
        add_action('admin_menu', array($this, 'add_whatsapp_settings_page'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_init', array($this, 'ensure_server_running'));
        
        // AJAX actions
        add_action('wp_ajax_dbb_whatsapp_get_status', array($this, 'ajax_get_status'));
        add_action('wp_ajax_dbb_send_whatsapp_message', array($this, 'ajax_send_message'));
        add_action('wp_ajax_dbb_get_whatsapp_logs', array($this, 'ajax_get_logs'));
        add_action('wp_ajax_dbb_whatsapp_disconnect', array($this, 'ajax_disconnect'));
        add_action('wp_ajax_dbb_whatsapp_start_server', array($this, 'ajax_start_server'));
        add_action('wp_ajax_dbb_whatsapp_stop_server', array($this, 'ajax_stop_server'));
        add_action('wp_ajax_dbb_whatsapp_get_pm2_status', array($this, 'ajax_get_pm2_status'));
        
        // Auto-send hooks
        add_action('woocommerce_order_status_processing', array($this, 'send_order_processing_message'), 10, 1);
        add_action('woocommerce_order_status_completed', array($this, 'send_order_completed_message'), 10, 1);
    }
    
    /**
     * Ensure server is running
     */
    public function ensure_server_running() {
        if (get_option('dbb_whatsapp_server_running')) {
            return; // Server already running
        }
        
        // Check if server is actually running
        $status = $this->get_server_status();
        if (isset($status['status']) && $status['status'] !== 'server_offline') {
            update_option('dbb_whatsapp_server_running', true);
            return; // Server is running
        }
        
        // Server not running, start it
        dbb_start_whatsapp_server();
    }
    
    /**
     * Add WhatsApp settings page
     */
    public function add_whatsapp_settings_page() {
        add_submenu_page(
            'dbb-management',
            'WhatsApp Marketing',
            'WhatsApp Marketing',
            'manage_options',
            'dbb-whatsapp-settings',
            array($this, 'render_settings_page')
        );
    }
    
    /**
     * Register settings
     */
    public function register_settings() {
        register_setting('dbb_whatsapp_settings', 'dbb_whatsapp_enabled');
        register_setting('dbb_whatsapp_settings', 'dbb_whatsapp_business_name');
        register_setting('dbb_whatsapp_settings', 'dbb_whatsapp_auto_send_enabled');
        register_setting('dbb_whatsapp_settings', 'dbb_whatsapp_message_key_delivery');
        register_setting('dbb_whatsapp_settings', 'dbb_whatsapp_message_order_processing');
        register_setting('dbb_whatsapp_settings', 'dbb_whatsapp_message_order_completed');
    }
    
    /**
     * Get connection status from Node server
     */
    private function get_server_status() {
        $response = wp_remote_get($this->server_url . '/status', array(
            'timeout' => 5
        ));
        
        if (is_wp_error($response)) {
            return array(
                'status' => 'server_offline',
                'message' => 'WhatsApp server not running'
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        return json_decode($body, true);
    }
    
    /**
     * Render settings page
     */
    public function render_settings_page() {
        $enabled = get_option('dbb_whatsapp_enabled', false);
        $business_name = get_option('dbb_whatsapp_business_name', 'Your Business');
        $auto_send_enabled = get_option('dbb_whatsapp_auto_send_enabled', false);
        
        $msg_key_delivery = get_option('dbb_whatsapp_message_key_delivery', $this->get_default_key_delivery_message());
        $msg_order_processing = get_option('dbb_whatsapp_message_order_processing', $this->get_default_order_processing_message());
        $msg_order_completed = get_option('dbb_whatsapp_message_order_completed', $this->get_default_order_completed_message());
        
        $server_status = $this->get_server_status();
        $is_connected = isset($server_status['status']) && $server_status['status'] === 'connected';
        $is_waiting_scan = isset($server_status['status']) && $server_status['status'] === 'waiting_for_scan';
        ?>
        <div class="wrap dbb-wrap">
            <!-- Header -->
            <div class="dbb-header">
                <h1>
                    <span class="dashicons dashicons-whatsapp"></span>
                    WhatsApp Marketing
                </h1>
                <p>Connect your WhatsApp account and send automated messages to customers</p>
            </div>
            
            <!-- Server Status -->
            <div class="dbb-card">
                <div class="dbb-card-header">
                    <h2 class="dbb-card-title">🔌 Connection Status</h2>
                </div>
                <div class="dbb-card-body">
                
                <!-- PM2 Process Status -->
                <div id="pm2Status" style="margin-bottom: 15px; padding: 10px; background: #f3f4f6; border-radius: 4px; font-family: monospace; font-size: 12px;">
                    <strong>PM2 Process Status:</strong> <span id="pm2StatusText" style="color: #6b7280;">Loading...</span>
                </div>
                
                <!-- Status Display (Updated by JavaScript) -->
                <div id="statusDisplay" style="min-height: 100px;">
                    <p style="text-align: center; color: #999;">Loading status...</p>
                </div>
                
                <!-- QR Code Display -->
                <div id="qrCodeSection" style="display: none; text-align: center; margin: 20px 0; padding: 30px; background: linear-gradient(135deg, var(--dbb-primary) 0%, var(--dbb-secondary) 100%); border-radius: 12px; color: white; box-shadow: var(--dbb-shadow-lg);">
                    <h3 style="margin-bottom: 10px; font-size: 20px; font-weight: 600;">📱 Scan QR Code to Connect WhatsApp</h3>
                    <p style="margin-bottom: 20px; font-size: 14px; opacity: 0.95;">Point your phone's WhatsApp camera at this code</p>
                    
                    <div id="qrCanvas" style="display: inline-block; padding: 20px; background: white; border-radius: 12px; box-shadow: 0 10px 30px rgba(0,0,0,0.3); margin: 15px 0;"></div>
                    
                    <p style="font-size: 13px; margin-top: 20px; line-height: 1.6; opacity: 0.95;">
                        <strong>Steps to connect:</strong><br>
                        1️⃣ Open WhatsApp on your phone<br>
                        2️⃣ Go to Settings → Linked Devices → Link a Device<br>
                        3️⃣ Point camera at this QR code<br>
                        4️⃣ WhatsApp will connect automatically
                    </p>
                </div>
                </div>
            </div>
            
            <!-- Settings -->
            <div class="dbb-card">
                <div class="dbb-card-header">
                    <h2 class="dbb-card-title">⚙️ Settings</h2>
                </div>
                <div class="dbb-card-body">
                <form method="post" action="options.php">
                <?php settings_fields('dbb_whatsapp_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">Enable WhatsApp</th>
                        <td>
                            <label>
                                <input type="checkbox" name="dbb_whatsapp_enabled" value="1" <?php checked($enabled); ?>>
                                Enable WhatsApp messaging
                            </label>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Business Name</th>
                        <td>
                            <input type="text" name="dbb_whatsapp_business_name" value="<?php echo esc_attr($business_name); ?>" class="regular-text">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Auto-send</th>
                        <td>
                            <label>
                                <input type="checkbox" name="dbb_whatsapp_auto_send_enabled" value="1" <?php checked($auto_send_enabled); ?>>
                                Auto-send when orders/keys change
                            </label>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Save Settings', 'primary dbb-btn dbb-btn-primary'); ?>
            </form>
            </div>
            </div>
            
            <!-- Test Message -->
            <?php if ($is_connected) { ?>
                <div class="dbb-card">
                    <div class="dbb-card-header">
                        <h2 class="dbb-card-title">🧪 Test Message</h2>
                    </div>
                    <div class="dbb-card-body">
                    <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                        <input type="text" id="test_phone" placeholder="+8801618983123" class="dbb-form-control" style="flex: 1;">
                        <button type="button" id="send_test" class="dbb-btn dbb-btn-primary">Send Test</button>
                    </div>
                    <p id="test_message" style="display: none; margin: 10px 0; padding: 10px; border-radius: 4px;"></p>
                    </div>
                </div>
            <?php } ?>
            
            <!-- Message Templates -->
            <div class="dbb-card">
                <div class="dbb-card-header">
                    <h2 class="dbb-card-title">💬 Message Templates</h2>
                    <p style="margin: 10px 0 0 0; font-size: 13px; color: #6b7280;">Customize WhatsApp message templates with placeholders</p>
                </div>
                <div class="dbb-card-body">
                <form method="post" action="options.php">
                <?php settings_fields('dbb_whatsapp_settings'); ?>
                <table class="form-table">
                    <tr>
                        <th style="vertical-align: top; padding-top: 15px;">
                            <strong>🔑 Key Delivery</strong>
                            <p style="font-weight: normal; color: #6b7280; margin: 5px 0; font-size: 12px;">
                                Sent when redeem key is assigned to order
                            </p>
                        </th>
                        <td>
                            <textarea name="dbb_whatsapp_message_key_delivery" rows="5" class="large-text" style="font-family: monospace;"><?php echo esc_textarea($msg_key_delivery); ?></textarea>
                            <p class="description" style="margin-top: 8px;">
                                <strong>Available placeholders:</strong><br>
                                <code>{customer_name}</code> - Customer's name<br>
                                <code>{product_name}</code> - Product name<br>
                                <code>{order_id}</code> - Order ID number<br>
                                <code>{redeem_key}</code> - The redeem key value
                            </p>
                            <details style="margin-top: 10px; padding: 10px; background: #f0f9ff; border-left: 3px solid #3b82f6; border-radius: 4px;">
                                <summary style="cursor: pointer; font-weight: 600; color: #1e40af;">📝 Example Message</summary>
                                <pre style="margin: 10px 0 0 0; padding: 10px; background: white; border-radius: 4px; font-size: 13px; line-height: 1.6; white-space: pre-wrap;">Hello John Doe,

🎉 Your Netflix Premium order #12345 is ready!

Redeem Key: NETFLIX-ABC123-XYZ789

Thank you!</pre>
                            </details>
                        </td>
                    </tr>
                    <tr>
                        <th style="vertical-align: top; padding-top: 15px;">
                            <strong>📦 Order Processing</strong>
                            <p style="font-weight: normal; color: #6b7280; margin: 5px 0; font-size: 12px;">
                                Sent when order status changes to processing
                            </p>
                        </th>
                        <td>
                            <textarea name="dbb_whatsapp_message_order_processing" rows="6" class="large-text" style="font-family: monospace;"><?php echo esc_textarea($msg_order_processing); ?></textarea>
                            <p class="description" style="margin-top: 8px;">
                                <strong>Available placeholders:</strong><br>
                                <code>{customer_name}</code> - Customer's name<br>
                                <code>{order_id}</code> - Order ID number<br>
                                <code>{order_total}</code> - Total order amount<br>
                                <code>{order_items}</code> - List of ordered items
                            </p>
                            <details style="margin-top: 10px; padding: 10px; background: #fffbeb; border-left: 3px solid #f59e0b; border-radius: 4px;">
                                <summary style="cursor: pointer; font-weight: 600; color: #92400e;">📝 Example Message</summary>
                                <pre style="margin: 10px 0 0 0; padding: 10px; background: white; border-radius: 4px; font-size: 13px; line-height: 1.6; white-space: pre-wrap;">Hello John Doe,

📦 Your order #12345 is being processed!

Order Total: ৳1,299

We'll notify you once it's ready for delivery.

Thank you for shopping with us!</pre>
                            </details>
                        </td>
                    </tr>
                    <tr>
                        <th style="vertical-align: top; padding-top: 15px;">
                            <strong>✅ Order Completed</strong>
                            <p style="font-weight: normal; color: #6b7280; margin: 5px 0; font-size: 12px;">
                                Sent when order status changes to completed
                            </p>
                        </th>
                        <td>
                            <textarea name="dbb_whatsapp_message_order_completed" rows="6" class="large-text" style="font-family: monospace;"><?php echo esc_textarea($msg_order_completed); ?></textarea>
                            <p class="description" style="margin-top: 8px;">
                                <strong>Available placeholders:</strong><br>
                                <code>{customer_name}</code> - Customer's name<br>
                                <code>{order_id}</code> - Order ID number<br>
                                <code>{order_total}</code> - Total order amount<br>
                                <code>{order_items}</code> - List of ordered items
                            </p>
                            <details style="margin-top: 10px; padding: 10px; background: #f0fdf4; border-left: 3px solid #10b981; border-radius: 4px;">
                                <summary style="cursor: pointer; font-weight: 600; color: #065f46;">📝 Example Message</summary>
                                <pre style="margin: 10px 0 0 0; padding: 10px; background: white; border-radius: 4px; font-size: 13px; line-height: 1.6; white-space: pre-wrap;">Hello John Doe,

✅ Your order #12345 has been completed!

Order Total: ৳1,299
Items: Netflix Premium × 1, Disney+ Monthly × 1

Thank you for your purchase!</pre>
                            </details>
                        </td>
                    </tr>
                </table>
                
                <div style="background: #f9fafb; border-left: 4px solid #3b82f6; padding: 15px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 10px 0; color: #1e40af;">💡 Template Tips</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #374151; line-height: 1.8;">
                        <li>Use <code>{placeholders}</code> to insert dynamic data</li>
                        <li>Add emojis (🎉 ✅ 📦) to make messages more engaging</li>
                        <li>Keep messages concise and professional</li>
                        <li>Include order ID for customer reference</li>
                        <li>Add line breaks with Enter/Return for readability</li>
                    </ul>
                </div>
                
                <?php submit_button('Save Message Templates', 'primary dbb-btn dbb-btn-primary'); ?>
            </form>
            </div>
            </div>
            
            <!-- Logs -->
            <div class="dbb-card">
                <div class="dbb-card-header">
                    <h2 class="dbb-card-title">📜 Activity Log</h2>
                </div>
                <div class="dbb-card-body">
                <button type="button" id="load_logs" class="dbb-btn dbb-btn-secondary" style="margin-bottom: 15px;">Load Logs</button>
                <div id="logs_container"></div>
                </div>
            </div>
        </div>
        
        <style>
            .log-item { background: #f9fafb; border-left: 3px solid #10b981; padding: 12px; margin-bottom: 10px; border-radius: 4px; font-size: 13px; }
            .log-item.failed { border-left-color: #ef4444; }
            #test_message.success { background: #dcfce7; border-left: 4px solid #10b981; color: #166534; display: block !important; }
            #test_message.error { background: #fee2e2; border-left: 4px solid #ef4444; color: #991b1b; display: block !important; }
        </style>
        
        <script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>
        <script>
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        jQuery(document).ready(function($) {
            let qrRetries = 0;
            const maxRetries = 30;
            
            console.log('WhatsApp Settings Page Loaded');
            console.log('AJAX URL:', ajaxurl);
            
            // Render status display
            function renderStatus(status) {
                let html = '';
                
                if (status.status === 'server_offline') {
                    html = '<div style="background: #fee2e2; border-left: 4px solid #ef4444; padding: 15px; border-radius: 4px;">' +
                        '<p><strong>⚠️ Server Offline</strong></p>' +
                        '<p style="margin: 10px 0; color: #991b1b; font-size: 14px;">The WhatsApp server is not running.</p>' +
                        '<button type="button" id="start_server" class="button button-primary" style="margin-top: 10px;">▶️ Start Server</button>' +
                        '</div>';
                    $('#qrCodeSection').hide();
                } else if (status.status === 'waiting_for_scan') {
                    html = '<div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 15px; border-radius: 4px;">' +
                        '<p><strong>🔄 Waiting for QR Code Scan</strong></p>' +
                        '<p style="margin: 10px 0; color: #92400e; font-size: 14px;">Point your phone camera at the QR code below to connect.</p>' +
                        '<button type="button" id="stop_server" class="button" style="margin-top: 10px;">⏹️ Stop Server</button>' +
                        '</div>';
                    $('#qrCodeSection').show();
                } else if (status.status === 'connected' && status.user) {
                    var userInfo = status.user || 'Your WhatsApp';
                    html = '<div style="background: #dcfce7; border-left: 4px solid #10b981; padding: 15px; border-radius: 4px;">' +
                        '<p><strong>✅ WhatsApp Connected</strong></p>' +
                        '<p style="margin: 10px 0; color: #166534; font-size: 14px;">' +
                        'Account: <strong>' + userInfo + '</strong><br>' +
                        'Your WhatsApp is connected and ready to send messages!' +
                        '</p>' +
                        '<button type="button" id="disconnect_whatsapp" class="button" style="margin-top: 10px; margin-right: 10px;">🔓 Disconnect WhatsApp</button>' +
                        '<button type="button" id="stop_server" class="button" style="margin-top: 10px;">⏹️ Stop Server</button>' +
                        '</div>';
                    $('#qrCodeSection').hide();
                } else if (status.status === 'initializing') {
                    html = '<div style="background: #dbeafe; border-left: 4px solid #3b82f6; padding: 15px; border-radius: 4px;">' +
                        '<p><strong>🔄 Initializing...</strong></p>' +
                        '<p style="margin: 10px 0; color: #1e40af; font-size: 14px;">Server is starting up. Please wait...</p>' +
                        '</div>';
                    $('#qrCodeSection').hide();
                } else {
                    html = '<div style="background: #fee2e2; border-left: 4px solid #ef4444; padding: 15px; border-radius: 4px;">' +
                        '<p><strong>❌ Not Connected</strong></p>' +
                        '<p style="margin: 10px 0; color: #991b1b; font-size: 14px;">Status: ' + (status.status || 'unknown') + '</p>' +
                        '<button type="button" id="start_server" class="button button-primary" style="margin-top: 10px;">▶️ Start Server</button>' +
                        '</div>';
                    $('#qrCodeSection').hide();
                }
                
                $('#statusDisplay').html(html);
                
                // Re-bind disconnect button
                $('#disconnect_whatsapp').off('click').on('click', function(e) {
                    e.preventDefault();
                    if (confirm('Disconnect WhatsApp?')) {
                        $.post(ajaxurl, { action: 'dbb_whatsapp_disconnect', nonce: '<?php echo wp_create_nonce("dbb_whatsapp"); ?>' }, function() { 
                            location.reload(); 
                        });
                    }
                });
                
                // Re-bind start server button
                $('#start_server').off('click').on('click', function(e) {
                    e.preventDefault();
                    var $btn = $(this);
                    $btn.prop('disabled', true).text('⏳ Starting...');
                    
                    console.log('Start server button clicked');
                    
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: { 
                            action: 'dbb_whatsapp_start_server', 
                            nonce: '<?php echo wp_create_nonce("dbb_whatsapp"); ?>' 
                        },
                        timeout: 60000, // 60 second timeout
                        success: function(response) {
                            console.log('Start server response:', response);
                            if (response.success) {
                                alert('✅ ' + response.data);
                                setTimeout(function() {
                                    loadStatus();
                                }, 2000);
                            } else {
                                alert('❌ Failed to start server:\n\n' + (response.data || 'Unknown error'));
                                $btn.prop('disabled', false).text('▶️ Start Server');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Start server error:', status, error, xhr.responseText);
                            alert('❌ Request failed:\n\n' + status + ': ' + error + '\n\nCheck browser console for details');
                            $btn.prop('disabled', false).text('▶️ Start Server');
                        }
                    });
                });
                
                // Re-bind stop server button
                $('#stop_server').off('click').on('click', function(e) {
                    e.preventDefault();
                    console.log('Stop server button clicked');
                    if (confirm('Stop WhatsApp server? This will disconnect your WhatsApp.')) {
                        var $btn = $(this);
                        $btn.prop('disabled', true).text('⏳ Stopping...');
                        
                        console.log('Sending stop server request...');
                        $.post(ajaxurl, { 
                            action: 'dbb_whatsapp_stop_server', 
                            nonce: '<?php echo wp_create_nonce("dbb_whatsapp"); ?>' 
                        }, function(response) {
                            console.log('Stop server response:', response);
                            if (response.success) {
                                alert('Server stopped successfully');
                            }
                            setTimeout(function() {
                                loadStatus();
                            }, 1000);
                        }).fail(function(xhr, status, error) {
                            console.error('Stop server failed:', error);
                            alert('Failed to stop server: ' + error);
                            $btn.prop('disabled', false).text('⏹️ Stop Server');
                        });
                    }
                });
            }
            
            // Load PM2 status
            function loadPM2Status() {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dbb_whatsapp_get_pm2_status',
                        nonce: '<?php echo wp_create_nonce("dbb_whatsapp"); ?>'
                    },
                    success: function(response) {
                        if (response.success && response.data) {
                            var data = response.data;
                            var statusText = '';
                            
                            if (data.status === 'online') {
                                var uptime = data.uptime ? Math.floor((Date.now() - data.uptime) / 1000 / 60) : 0;
                                statusText = '<span style="color: #10b981;">● ONLINE</span> | ' +
                                           'Uptime: ' + uptime + ' min | ' +
                                           'CPU: ' + data.cpu + '% | ' +
                                           'RAM: ' + data.memory + ' MB | ' +
                                           'Restarts: ' + data.restarts;
                            } else if (data.status === 'stopped') {
                                statusText = '<span style="color: #ef4444;">● STOPPED</span> | Process not running';
                            } else {
                                statusText = '<span style="color: #f59e0b;">● ' + data.status.toUpperCase() + '</span>';
                            }
                            
                            $('#pm2StatusText').html(statusText);
                        }
                    },
                    error: function() {
                        $('#pm2StatusText').html('<span style="color: #6b7280;">Unable to fetch PM2 status</span>');
                    }
                });
            }
            
            // Load and display status
            function loadStatus() {
                console.log('Loading WhatsApp status...');
                loadPM2Status(); // Also load PM2 status
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'dbb_whatsapp_get_status',
                        nonce: '<?php echo wp_create_nonce("dbb_whatsapp"); ?>'
                    },
                    timeout: 5000,
                    success: function(response) {
                        console.log('Status response:', response);
                        if (response.success && response.data) {
                            qrRetries = 0;
                            renderStatus(response.data);
                            
                            // Handle QR code for waiting_for_scan
                            if (response.data.status === 'waiting_for_scan' && response.data.qr_code) {
                                var qrContainer = document.getElementById('qrCanvas');
                                if (qrContainer) {
                                    qrContainer.innerHTML = '';
                                    try {
                                        new QRCode(qrContainer, {
                                            text: response.data.qr_code,
                                            width: 300,
                                            height: 300,
                                            colorDark: '#000000',
                                            colorLight: '#ffffff',
                                            correctLevel: QRCode.CorrectLevel.H
                                        });
                                    } catch(e) {
                                        console.error('QR Code error:', e);
                                    }
                                }
                            }
                        } else {
                            console.error('Invalid status response:', response);
                            renderStatus({ status: 'server_offline' });
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Status load error:', status, error, xhr.responseText);
                        qrRetries++;
                        if (qrRetries <= maxRetries) {
                            renderStatus({ status: 'initializing' });
                        } else {
                            renderStatus({ status: 'server_offline' });
                        }
                    }
                });
            }
            
            // Helper function to escape HTML
            function esc_html(text) {
                return text
                    .replace(/&/g, '&amp;')
                    .replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;')
                    .replace(/'/g, '&#039;');
            }
            
            // Load status immediately
            loadStatus();
            
            // Refresh status every 2 seconds
            setInterval(loadStatus, 2000);
            
            $('#send_test').click(function(e) {
                e.preventDefault();
                var phone = $('#test_phone').val();
                if (!phone) { alert('Enter phone'); return; }
                console.log('Sending test message to:', phone);
                var btn = $(this);
                btn.prop('disabled',true).text('Sending...');
                $.post(ajaxurl, {
                    action: 'dbb_send_whatsapp_message',
                    phone_number: phone,
                    message: 'Test message from <?php echo esc_js($business_name); ?> ✅',
                    nonce: '<?php echo wp_create_nonce("dbb_whatsapp"); ?>'
                }, function(r) {
                    console.log('Send response:', r);
                    $('#test_message').removeClass('success error').addClass(r.success ? 'success' : 'error').text(r.success ? '✅ Sent!' : '❌ '+r.data).show();
                    btn.prop('disabled',false).text('Send Test');
                }).fail(function(xhr, status, error) {
                    console.error('Send failed:', status, error, xhr.responseText);
                    $('#test_message').removeClass('success error').addClass('error').text('❌ Error: ' + error).show();
                    btn.prop('disabled',false).text('Send Test');
                });
            });
            
            $('#load_logs').click(function() {
                var btn = $(this);
                btn.prop('disabled',true).text('Loading...');
                $.post(ajaxurl, { action: 'dbb_get_whatsapp_logs', nonce: '<?php echo wp_create_nonce("dbb_whatsapp"); ?>' }, function(r) {
                    $('#logs_container').html(r.data);
                    btn.prop('disabled',false).text('Load Logs');
                });
            });
            
            // Auto-refresh PM2 status every 5 seconds
            setInterval(function() {
                loadPM2Status();
            }, 5000);
        });
        </script>
        <?php
    }
    
    /**
     * AJAX: Get status
     */
    public function ajax_get_status() {
        check_ajax_referer('dbb_whatsapp', 'nonce');
        wp_send_json_success($this->get_server_status());
    }
    
    /**
     * AJAX: Send message
     */
    public function ajax_send_message() {
        check_ajax_referer('dbb_whatsapp', 'nonce');
        
        $phone = isset($_POST['phone_number']) ? sanitize_text_field($_POST['phone_number']) : '';
        $message = isset($_POST['message']) ? sanitize_textarea_field($_POST['message']) : '';
        
        if (!$phone || !$message) {
            wp_send_json_error('Phone and message required');
            return;
        }
        
        $response = wp_remote_post($this->server_url . '/send', array(
            'timeout' => 10,
            'body' => json_encode(array('phone' => $phone, 'message' => $message)),
            'headers' => array('Content-Type' => 'application/json')
        ));
        
        if (is_wp_error($response)) {
            $this->log_message($phone, $message, 'failed');
            wp_send_json_error('Server error: ' . $response->get_error_message());
            return;
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($body['success']) {
            $this->log_message($phone, $message, 'sent');
            wp_send_json_success('Message sent!');
        } else {
            $this->log_message($phone, $message, 'failed');
            wp_send_json_error($body['error']);
        }
    }
    
    /**
     * AJAX: Disconnect
     */
    public function ajax_disconnect() {
        check_ajax_referer('dbb_whatsapp', 'nonce');
        wp_remote_post($this->server_url . '/disconnect', array('timeout' => 5));
        wp_send_json_success('Disconnected');
    }
    
    /**
     * AJAX: Start Server
     */
    public function ajax_start_server() {
        check_ajax_referer('dbb_whatsapp', 'nonce');
        
        error_log('DBB WhatsApp: Start server request received');
        
        $plugin_dir = DBB_PLUGIN_DIR;
        $env_path = 'PATH=/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin PM2_HOME=' . escapeshellarg($plugin_dir . '.pm2');
        
        error_log('DBB WhatsApp: Plugin dir: ' . $plugin_dir);
        error_log('DBB WhatsApp: Current user: ' . get_current_user());
        error_log('DBB WhatsApp: PHP user: ' . posix_getpwuid(posix_geteuid())['name']);
        
        // Diagnostic: Check PM2 accessibility
        $pm2_test = shell_exec('which pm2 2>&1');
        error_log('DBB WhatsApp: PM2 path from which: ' . $pm2_test);
        
        $pm2_list_test = shell_exec('pm2 list 2>&1');
        error_log('DBB WhatsApp: PM2 list test output: ' . substr($pm2_list_test, 0, 500));
        
        // Check and fix permissions
        if (!is_writable($plugin_dir)) {
            error_log('DBB WhatsApp: Plugin directory not writable, attempting to fix permissions');
            @chmod($plugin_dir, 0755);
        }
        
        // Ensure required directories exist with proper permissions
        $required_dirs = array(
            $plugin_dir . '.wwebjs_auth',
            $plugin_dir . '.wwebjs_cache'
        );
        
        foreach ($required_dirs as $dir) {
            if (!file_exists($dir)) {
                @mkdir($dir, 0755, true);
                error_log('DBB WhatsApp: Created directory: ' . $dir);
            }
            @chmod($dir, 0755);
        }
        
        // Check if Node.js is installed
        $node_check = shell_exec($env_path . ' which node 2>/dev/null');
        error_log('DBB WhatsApp: Node check: ' . trim($node_check));
        if (empty(trim($node_check))) {
            error_log('DBB WhatsApp: Node.js not found');
            wp_send_json_error('Node.js is not installed. Install it with: curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash - && sudo apt-get install -y nodejs');
            return;
        }
        
        // Check if npm is installed
        $npm_check = shell_exec($env_path . ' which npm 2>/dev/null');
        error_log('DBB WhatsApp: npm check: ' . trim($npm_check));
        if (empty(trim($npm_check))) {
            error_log('DBB WhatsApp: npm not found');
            wp_send_json_error('npm is not installed. Install Node.js first: curl -fsSL https://deb.nodesource.com/setup_18.x | sudo -E bash - && sudo apt-get install -y nodejs');
            return;
        }
        
        // Install npm dependencies if node_modules doesn't exist
        if (!file_exists($plugin_dir . 'node_modules')) {
            error_log('DBB WhatsApp: Installing npm dependencies...');
            $npm_install = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " npm install 2>&1";
            $install_output = shell_exec($npm_install);
            error_log('DBB WhatsApp: npm install output: ' . substr($install_output, 0, 500));
            sleep(2); // Wait for installation
        } else {
            error_log('DBB WhatsApp: node_modules already exists');
        }
        
        // Find PM2 path
        $pm2_check = shell_exec($env_path . ' which pm2 2>/dev/null');
        $pm2_path = trim($pm2_check);
        
        if (empty($pm2_path)) {
            $common_paths = array('/usr/local/bin/pm2', '/opt/homebrew/bin/pm2', '/usr/bin/pm2', '/usr/lib/node_modules/pm2/bin/pm2');
            foreach ($common_paths as $path) {
                if (file_exists($path)) {
                    $pm2_path = $path;
                    break;
                }
            }
        }
        
        error_log('DBB WhatsApp: PM2 path: ' . $pm2_path);
        
        if (empty($pm2_path)) {
            error_log('DBB WhatsApp: PM2 not found, attempting to install');
            $pm2_install = $env_path . ' npm install -g pm2 2>&1';
            $install_result = shell_exec($pm2_install);
            error_log('DBB WhatsApp: PM2 install result: ' . substr($install_result, 0, 200));
            sleep(1);
            $pm2_check = shell_exec($env_path . ' which pm2 2>/dev/null');
            $pm2_path = trim($pm2_check);
            
            if (empty($pm2_path)) {
                error_log('DBB WhatsApp: CRITICAL - PM2 still not found after install attempt');
                wp_send_json_error('PM2 not found. Please install manually: npm install -g pm2');
                return;
            }
        }
        
        error_log('DBB WhatsApp: Using PM2 at: ' . $pm2_path);
        
        // Check if PM2 process already exists (including errored ones)
        $pm2_list = shell_exec('cd ' . escapeshellarg($plugin_dir) . ' && ' . $env_path . ' ' . escapeshellarg($pm2_path) . ' jlist 2>&1');
        $processes = json_decode($pm2_list, true);
        
        $has_online_process = false;
        $has_any_process = false;
        
        if (is_array($processes)) {
            foreach ($processes as $process) {
                if (isset($process['name']) && ($process['name'] === 'dbb-whatsapp-server' || $process['name'] === 'whatsapp-server')) {
                    $has_any_process = true;
                    $status = isset($process['pm2_env']['status']) ? $process['pm2_env']['status'] : 'unknown';
                    
                    if ($status === 'online') {
                        $has_online_process = true;
                        error_log('DBB WhatsApp: PM2 process ' . $process['name'] . ' already online');
                    }
                }
            }
        }
        
        // If we have an online process, check if it's actually responding
        if ($has_online_process) {
            $status = $this->get_server_status();
            if ($status['status'] !== 'server_offline') {
                error_log('DBB WhatsApp: Server already running and responding');
                wp_send_json_success('Server already running with PM2');
                return;
            } else {
                error_log('DBB WhatsApp: PM2 shows online but server not responding, cleaning up');
                $has_online_process = false;
            }
        }
        
        // Clean up ALL WhatsApp server processes (whatsapp-server and dbb-whatsapp-server)
        if ($has_any_process || !$has_online_process) {
            error_log('DBB WhatsApp: Cleaning up all WhatsApp server PM2 processes');
            shell_exec('cd ' . escapeshellarg($plugin_dir) . ' && ' . $env_path . ' ' . escapeshellarg($pm2_path) . ' delete whatsapp-server 2>&1');
            shell_exec('cd ' . escapeshellarg($plugin_dir) . ' && ' . $env_path . ' ' . escapeshellarg($pm2_path) . ' delete dbb-whatsapp-server 2>&1');
            sleep(1);
        }
        
        // Aggressively kill anything on port 9000
        error_log('DBB WhatsApp: Ensuring port 9000 is free');
        shell_exec("lsof -ti :9000 | xargs kill -9 2>/dev/null");
        shell_exec("fuser -k 9000/tcp 2>/dev/null");
        shell_exec("pkill -9 -f whatsapp-server.js 2>/dev/null");
        sleep(2);
        
        // Final port verification
        $port_check = shell_exec("lsof -i :9000 2>/dev/null");
        if (!empty(trim($port_check))) {
            error_log('DBB WhatsApp: WARNING - Port 9000 still in use after cleanup: ' . $port_check);
            wp_send_json_error('Port 9000 is in use and could not be freed. Please run: sudo fuser -k 9000/tcp && sudo pkill -9 -f whatsapp-server.js');
            return;
        }
        
        error_log('DBB WhatsApp: Port 9000 is now free');
        
        $server_file = $plugin_dir . 'whatsapp-server.js';
        
        if (!file_exists($server_file)) {
            error_log('DBB WhatsApp: whatsapp-server.js not found at: ' . $server_file);
            wp_send_json_error('whatsapp-server.js not found in plugin directory');
            return;
        }
        
        // Start with PM2
        $log_file = $plugin_dir . 'whatsapp-server.log';
        $error_log = $plugin_dir . 'whatsapp-server-error.log';
        
        $command = sprintf(
            'cd %s && %s %s start %s --name dbb-whatsapp-server --output %s --error %s --time 2>&1',
            escapeshellarg($plugin_dir),
            $env_path,
            escapeshellarg($pm2_path),
            escapeshellarg($server_file),
            escapeshellarg($log_file),
            escapeshellarg($error_log)
        );
        
        error_log('DBB WhatsApp: PM2 command: ' . $command);
        error_log('DBB WhatsApp: Starting with PM2...');
        $start_output = shell_exec($command);
        error_log('DBB WhatsApp: PM2 start output: ' . substr($start_output, 0, 500));
        
        if (empty($start_output)) {
            error_log('DBB WhatsApp: WARNING - PM2 start returned empty output');
        }
        
        // Save PM2 list
        $save_cmd = 'cd ' . escapeshellarg($plugin_dir) . ' && ' . $env_path . ' ' . escapeshellarg($pm2_path) . ' save 2>&1';
        error_log('DBB WhatsApp: PM2 save command: ' . $save_cmd);
        shell_exec($save_cmd);
        
        // Wait for server to start
        sleep(3);
        
        // Check PM2 status
        $pm2_status = shell_exec('cd ' . escapeshellarg($plugin_dir) . ' && ' . $env_path . ' ' . escapeshellarg($pm2_path) . ' jlist 2>&1');
        $processes = json_decode($pm2_status, true);
        
        $pm2_running = false;
        if (is_array($processes)) {
            foreach ($processes as $process) {
                if (isset($process['name']) && $process['name'] === 'dbb-whatsapp-server') {
                    $pm2_status_str = isset($process['pm2_env']['status']) ? $process['pm2_env']['status'] : 'unknown';
                    error_log('DBB WhatsApp: PM2 process status: ' . $pm2_status_str);
                    
                    if ($pm2_status_str === 'online') {
                        $pm2_running = true;
                    } elseif ($pm2_status_str === 'errored') {
                        error_log('DBB WhatsApp: PM2 process errored, checking logs');
                    }
                    break;
                }
            }
        }
        
        // Check if server is responding
        $status = $this->get_server_status();
        if ($status['status'] !== 'server_offline') {
            error_log('DBB WhatsApp: Server started successfully with PM2');
            wp_send_json_success('Server started successfully with PM2');
        } elseif ($pm2_running) {
            error_log('DBB WhatsApp: PM2 online but server not responding yet');
            wp_send_json_success('Server starting with PM2, please wait...');
        } else {
            // Show error logs
            if (file_exists($error_log)) {
                $log_tail = shell_exec("tail -30 " . escapeshellarg($error_log) . " 2>&1");
                error_log('DBB WhatsApp: Error logs: ' . $log_tail);
                
                $error_msg = 'Server failed to start. ';
                
                if (strpos($log_tail, 'ENOENT') !== false || strpos($log_tail, 'Could not find Chrome') !== false || strpos($log_tail, 'Chromium') !== false || strpos($log_tail, 'Failed to launch') !== false) {
                    $error_msg .= 'Chrome/Chromium not installed or not found. Run: sudo apt-get install -y chromium-browser';
                } elseif (strpos($log_tail, 'EACCES') !== false || strpos($log_tail, 'permission denied') !== false) {
                    $error_msg .= 'Permission denied. Run: sudo chmod -R 777 ' . $plugin_dir . '.wwebjs_auth ' . $plugin_dir . '.wwebjs_cache';
                } elseif (strpos($log_tail, 'EADDRINUSE') !== false) {
                    $error_msg .= 'Port 9000 already in use. Run: sudo fuser -k 9000/tcp';
                } else {
                    $error_msg .= 'Check logs: tail -50 ' . $error_log;
                }
                
                wp_send_json_error($error_msg . "\n\nRecent error:\n" . substr($log_tail, -800));
            } else {
                wp_send_json_error('Server failed to start. Check ' . $error_log . ' for details');
            }
        }
    }
    
    /**
     * AJAX: Stop Server
     */
    public function ajax_stop_server() {
        check_ajax_referer('dbb_whatsapp', 'nonce');
        
        error_log('DBB WhatsApp: Stop server request received');
        
        $plugin_dir = DBB_PLUGIN_DIR;
        error_log('DBB WhatsApp: Plugin dir: ' . $plugin_dir);
        $pid_file = $plugin_dir . 'whatsapp-server.pid';
        
        // Try to stop using PID file first
        if (file_exists($pid_file)) {
            $pid = trim(file_get_contents($pid_file));
            if (!empty($pid) && is_numeric($pid)) {
                error_log('DBB WhatsApp: Stopping server with PID: ' . $pid);
                shell_exec("kill $pid 2>/dev/null");
                unlink($pid_file);
                sleep(1);
                
                // Verify process is dead
                $check = shell_exec("ps -p $pid -o comm= 2>/dev/null");
                if (empty(trim($check))) {
                    error_log('DBB WhatsApp: Server stopped successfully');
                    wp_send_json_success('Server stopped successfully');
                    return;
                }
            }
        }
        
        // Fallback: Try PM2
        $env_path = 'PATH=/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin PM2_HOME=' . escapeshellarg($plugin_dir . '.pm2');
        
        // Find PM2 path
        $pm2_path = '/usr/local/bin/pm2';
        if (!file_exists($pm2_path)) {
            $paths = array('/opt/homebrew/bin/pm2', '/usr/bin/pm2', trim(shell_exec($env_path . ' which pm2 2>/dev/null')));
            foreach ($paths as $path) {
                $path = trim($path);
                if (!empty($path) && file_exists($path)) {
                    $pm2_path = $path;
                    break;
                }
            }
        }
        
        // Try with PM2 if found, otherwise use npx pm2
        // Always cd to plugin directory first to avoid PM2 uv_cwd error
        // Stop BOTH process names (whatsapp-server and dbb-whatsapp-server)
        // Use sudo to access root user's PM2 daemon
        if (file_exists($pm2_path)) {
            error_log('DBB WhatsApp: Using PM2 at: ' . $pm2_path);
            error_log('DBB WhatsApp: Stopping both process names...');
            
            // Stop dbb-whatsapp-server
            $command = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " " . escapeshellarg($pm2_path) . " stop dbb-whatsapp-server 2>&1";
            error_log('DBB WhatsApp: Command 1: ' . $command);
            $output1 = shell_exec($command);
            error_log('DBB WhatsApp: Output 1: ' . $output1);
            
            $command2 = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " " . escapeshellarg($pm2_path) . " delete dbb-whatsapp-server 2>&1";
            $output2 = shell_exec($command2);
            error_log('DBB WhatsApp: Output 2: ' . $output2);
            
            // Stop whatsapp-server (default name from manual start)
            $command3 = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " " . escapeshellarg($pm2_path) . " stop whatsapp-server 2>&1";
            $output3 = shell_exec($command3);
            error_log('DBB WhatsApp: Output 3: ' . $output3);
            
            $command4 = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " " . escapeshellarg($pm2_path) . " delete whatsapp-server 2>&1";
            $output4 = shell_exec($command4);
            error_log('DBB WhatsApp: Output 4: ' . $output4);
        } else {
            // Stop dbb-whatsapp-server
            $command = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " npx pm2 stop dbb-whatsapp-server 2>&1";
            shell_exec($command);
            
            $command2 = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " npx pm2 delete dbb-whatsapp-server 2>&1";
            shell_exec($command2);
            
            // Stop whatsapp-server (default name from manual start)
            $command3 = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " npx pm2 stop whatsapp-server 2>&1";
            shell_exec($command3);
            
            $command4 = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " npx pm2 delete whatsapp-server 2>&1";
            shell_exec($command4);
        }
        
        // Last resort: kill by port
        $kill_by_port = "kill \$(lsof -t -i:9000) 2>/dev/null || fuser -k 9000/tcp 2>/dev/null";
        error_log('DBB WhatsApp: Killing by port: ' . $kill_by_port);
        shell_exec($kill_by_port);
        
        error_log('DBB WhatsApp: Stop server completed');
        wp_send_json_success('Server stopped successfully');
    }
    
    /**
     * AJAX: Get PM2 Status
     */
    public function ajax_get_pm2_status() {
        check_ajax_referer('dbb_whatsapp', 'nonce');
        
        $plugin_dir = DBB_PLUGIN_DIR;
        $env_path = 'PATH=/usr/local/bin:/usr/bin:/bin:/opt/homebrew/bin PM2_HOME=' . escapeshellarg($plugin_dir . '.pm2');
        
        // Find PM2 path
        $pm2_path = '/usr/local/bin/pm2';
        if (!file_exists($pm2_path)) {
            $paths = array('/opt/homebrew/bin/pm2', '/usr/bin/pm2', trim(shell_exec($env_path . ' which pm2 2>/dev/null')));
            foreach ($paths as $path) {
                $path = trim($path);
                if (!empty($path) && file_exists($path)) {
                    $pm2_path = $path;
                    break;
                }
            }
        }
        
        // Get PM2 process list
        // Always cd to plugin directory first to avoid PM2 uv_cwd error
        $plugin_dir = DBB_PLUGIN_DIR;
        $command = '';
        if (file_exists($pm2_path)) {
            $command = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " " . escapeshellarg($pm2_path) . " jlist 2>&1";
        } else {
            // Try with npx pm2
            $command = "cd " . escapeshellarg($plugin_dir) . " && " . $env_path . " npx pm2 jlist 2>&1";
        }
        
        $output = shell_exec($command);
        $processes = json_decode($output, true);
        
        if (is_array($processes)) {
            foreach ($processes as $process) {
                // Check for BOTH process names
                if ($process['name'] === 'dbb-whatsapp-server' || $process['name'] === 'whatsapp-server') {
                    wp_send_json_success(array(
                        'status' => $process['pm2_env']['status'],
                        'uptime' => $process['pm2_env']['pm_uptime'],
                        'restarts' => $process['pm2_env']['restart_time'],
                        'cpu' => isset($process['monit']['cpu']) ? $process['monit']['cpu'] : 0,
                        'memory' => isset($process['monit']['memory']) ? round($process['monit']['memory'] / 1024 / 1024, 1) : 0,
                        'pid' => isset($process['pid']) ? $process['pid'] : 0,
                        'name' => $process['name']
                    ));
                    return;
                }
            }
        }
        
        wp_send_json_success(array('status' => 'stopped'));
    }
    
    /**
     * AJAX: Get logs
     */
    public function ajax_get_logs() {
        check_ajax_referer('dbb_whatsapp', 'nonce');
        global $wpdb;
        $logs = $wpdb->get_results("SELECT * FROM {$wpdb->options} WHERE option_name LIKE 'dbb_whatsapp_log_%' ORDER BY option_id DESC LIMIT 20");
        
        $html = $logs ? '' : '<p style="color: #999; text-align: center;">No logs yet</p>';
        foreach ($logs as $log) {
            $data = json_decode($log->option_value, true);
            if ($data) {
                $html .= '<div class="log-item ' . (strpos($data['status'], 'failed') !== false ? 'failed' : '') . '">
                    <strong>📱 ' . esc_html($data['phone']) . '</strong> <span style="float: right; font-size: 12px;">' . esc_html($data['status']) . '</span><br>
                    <small style="color: #999;">' . esc_html($data['timestamp']) . '</small><br>
                    <small style="color: #666;">' . esc_html(substr($data['message'], 0, 100)) . '</small>
                </div>';
            }
        }
        wp_send_json_success($html);
    }
    
    private function log_message($phone, $message, $status = 'sent') {
        update_option('dbb_whatsapp_log_' . time() . '_' . wp_rand(1000, 9999), json_encode(array(
            'phone' => $phone,
            'message' => $message,
            'status' => $status,
            'timestamp' => current_time('mysql')
        )), false);
    }
    
    /**
     * Send WhatsApp message via Node server
     */
    private function send_message($phone, $message) {
        $response = wp_remote_post($this->server_url . '/send', array(
            'timeout' => 10,
            'body' => json_encode(array('phone' => $phone, 'message' => $message)),
            'headers' => array('Content-Type' => 'application/json')
        ));
        
        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'error' => 'Server error: ' . $response->get_error_message()
            );
        }
        
        $body = json_decode(wp_remote_retrieve_body($response), true);
        return is_array($body) ? $body : array('success' => false, 'error' => 'Invalid response');
    }
    
    private function get_default_key_delivery_message() {
        return "Hello {customer_name},\n\n🎉 Your {product_name} order #{order_id} is ready!\n\nRedeem Key: {redeem_key}\n\nThank you!";
    }
    
    private function get_default_order_processing_message() {
        return "Hello {customer_name},\n\n📦 Your order #{order_id} is being processed!\n\nOrder Total: {order_total}\n\nWe'll notify you once it's ready for delivery.\n\nThank you for shopping with us!";
    }
    
    private function get_default_order_completed_message() {
        return "Hello {customer_name},\n\n✅ Your order #{order_id} has been completed!\n\nOrder Total: {order_total}\nItems: {order_items}\n\nThank you for your purchase!";
    }
    
    public function send_order_processing_message($order_id) {
        error_log("WhatsApp: send_order_processing_message called for order $order_id");
        
        if (!get_option('dbb_whatsapp_enabled')) {
            error_log("WhatsApp: Disabled - dbb_whatsapp_enabled is false");
            return;
        }
        
        if (!get_option('dbb_whatsapp_auto_send_enabled')) {
            error_log("WhatsApp: Auto-send disabled - dbb_whatsapp_auto_send_enabled is false");
            return;
        }
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("WhatsApp: Order $order_id not found");
            return;
        }
        
        // Get customer phone number
        $phone = $order->get_billing_phone();
        if (!$phone) {
            error_log("WhatsApp: No phone number for order $order_id");
            return;
        }
        
        error_log("WhatsApp: Sending to phone: $phone");
        
        // Get message template
        $message_template = get_option('dbb_whatsapp_message_order_processing');
        if (empty($message_template)) {
            $message_template = $this->get_default_order_processing_message();
        }
        
        // Replace placeholders
        $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $order_total = $order->get_total() . ' ' . $currency_symbol;
        
        $message = str_replace(
            array('{customer_name}', '{order_id}', '{order_total}'),
            array(
                $order->get_billing_first_name(),
                $order->get_id(),
                $order_total
            ),
            $message_template
        );
        
        error_log("WhatsApp: Message prepared: " . substr($message, 0, 100));
        
        // Send message
        $result = $this->send_message($phone, $message);
        
        error_log("WhatsApp: Send result: " . json_encode($result));
        
        // Log the attempt
        $status = $result['success'] ? 'sent' : 'failed';
        $this->log_message($phone, $message, $status);
        
        // Add order note
        if ($result['success']) {
            $order->add_order_note('WhatsApp notification sent to ' . $phone);
        } else {
            $order->add_order_note('WhatsApp notification failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }
    
    public function send_order_completed_message($order_id) {
        error_log("WhatsApp: send_order_completed_message called for order $order_id");
        
        if (!get_option('dbb_whatsapp_enabled')) return;
        if (!get_option('dbb_whatsapp_auto_send_enabled')) return;
        
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        // Get customer phone number
        $phone = $order->get_billing_phone();
        if (!$phone) return;
        
        // Get message template
        $message_template = get_option('dbb_whatsapp_message_order_completed');
        if (empty($message_template)) {
            $message_template = $this->get_default_order_completed_message();
        }
        
        // Get order items for details
        $items = array();
        foreach ($order->get_items() as $item) {
            $items[] = $item->get_name() . ' x' . $item->get_quantity();
        }
        $order_items = implode(', ', $items);
        
        // Replace placeholders
        $currency_symbol = html_entity_decode(get_woocommerce_currency_symbol(), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $order_total = $order->get_total() . ' ' . $currency_symbol;
        
        $message = str_replace(
            array('{customer_name}', '{order_id}', '{order_total}', '{order_items}'),
            array(
                $order->get_billing_first_name(),
                $order->get_id(),
                $order_total,
                $order_items
            ),
            $message_template
        );
        
        // Send message
        $result = $this->send_message($phone, $message);
        
        // Log the attempt
        $status = $result['success'] ? 'sent' : 'failed';
        $this->log_message($phone, $message, $status);
        
        // Add order note
        if ($result['success']) {
            $order->add_order_note('WhatsApp notification sent to ' . $phone);
        } else {
            $order->add_order_note('WhatsApp notification failed: ' . ($result['error'] ?? 'Unknown error'));
        }
    }
}

new DBB_WhatsApp_Integration();
