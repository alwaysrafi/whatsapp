/**
 * DBB Management Plugin Admin Scripts
 */
(function($) {
    'use strict';

    // Document ready
    $(document).ready(function() {
        // Initialize modals
        initModals();
        
        // Initialize account management features
        initAccountManager();
        
        // Initialize service management
        initServiceManager();
        
        // Initialize WooCommerce order page features
        initWooCommerceOrderPage();
    });

    /**
     * Initialize modal functionality
     */
    function initModals() {
        // Open modal
        $(document).on('click', '.open-modal', function(e) {
                    e.preventDefault();
            var targetModal = $(this).data('modal');
            $('#' + targetModal).fadeIn(300);
        });

        // Close modal on X click
        $(document).on('click', '.wsm-modal-close, .wsm-modal-cancel', function() {
            $(this).closest('.wsm-modal').fadeOut(300);
        });

        // Close modal on outside click
        $(document).on('click', '.wsm-modal', function(e) {
            if ($(e.target).hasClass('wsm-modal')) {
                $(this).fadeOut(300);
            }
        });
    }

    /**
     * Initialize account manager features
     */
    function initAccountManager() {
        // Edit account button
        $(document).on('click', '.wsm-edit-account', function() {
            var postId = $(this).data('post-id');
            
            // Show loading state
            var $button = $(this);
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Loading...');
            
            // Get account details via AJAX
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wsm_get_account_details',
                    post_id: postId,
                    nonce: dbbAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Populate modal with account details
                        var account = response.data;
                        var $modal = $('#wsm-edit-account-modal');
                        
                        $modal.find('#edit_account_id').val(account.id);
                        $modal.find('#edit_account_email').val(account.email);
                        $modal.find('#edit_account_hash').val(account.hash);
                        $modal.find('#edit_account_expiry_date').val(account.expiry_date);
                        $modal.find('#edit_account_slots').val(account.slots);
                        
                        // Show last update information
                        var lastUpdated = account.last_updated ? new Date(account.last_updated).toLocaleDateString('en-GB', {
                            day: 'numeric',
                            month: 'long',
                            year: 'numeric'
                        }) : 'Never';
                        var lastUpdateReason = account.last_update_reason || 'No previous updates';
                        $modal.find('#edit_last_updated').text(lastUpdated);
                        $modal.find('#edit_update_reason').val('').attr('placeholder', 'Last update reason: ' + lastUpdateReason);
                        
                        // Show modal
                        $modal.fadeIn(300);
                    } else {
                        alert('Error: ' + response.data);
                    }
                    
                    // Reset button state
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-edit"></span> Edit Account');
                },
                error: function() {
                    alert('Error: Could not load account details');
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-edit"></span> Edit Account');
                }
            });
        });
        
        // Edit order button
        $(document).on('click', '.wsm-edit-order', function() {
            var orderId = $(this).data('order-id');
            var metaKeys = $(this).data('meta-keys');
            
            // Show loading state
            var $button = $(this);
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Loading...');
            
            // Get order details via AJAX
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wsm_get_order_details',
                    order_id: orderId,
                    meta_keys: metaKeys,
                    nonce: dbbAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Populate modal with order details
                        var orderDetails = response.data;
                        var $modal = $('#wsm-edit-order-modal');
                        
                        $modal.find('#edit_order_id').val(orderDetails.id);
                        
                        // Populate meta fields
                        if (orderDetails.meta) {
                            for (var key in orderDetails.meta) {
                                $modal.find('#edit_' + key.replace(/\s+/g, '_').toLowerCase()).val(orderDetails.meta[key]);
                            }
                        }
                        
                        // Show modal
                        $modal.fadeIn(300);
                    } else {
                        alert('Error: ' + response.data);
                    }
                    
                    // Reset button state
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-edit"></span> Edit');
                },
                error: function() {
                    alert('Error: Could not load order details');
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-edit"></span> Edit');
                }
            });
        });
        
        // Remove order button
        $(document).on('click', '.wsm-remove-order', function() {
            var orderId = $(this).data('order-id');
            var postId = $(this).data('post-id');
            
            if (!confirm('Are you sure you want to remove this order from the account?')) {
                return;
            }
            
            // Show loading state
            var $button = $(this);
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Removing...');
            
            // Remove order via AJAX
            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'wsm_remove_order',
                    order_id: orderId,
                    post_id: postId,
                    nonce: dbbAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        // Remove row from table
                        $button.closest('tr').fadeOut(300, function() {
                            $(this).remove();
                            
                            // Update slots count
                            var $accountRow = $button.closest('tbody');
                            var totalSlots = parseInt($accountRow.find('.dashicons-groups').parent().text().match(/Total Slots: (\d+)/)[1]);
                            var usedSlots = parseInt($accountRow.find('.dashicons-yes').parent().text().match(/Used: (\d+)/)[1]) - 1;
                            var remainingSlots = totalSlots - usedSlots;
                            
                            $accountRow.find('.dashicons-yes').parent().html(
                                '<span class="dashicons dashicons-groups"></span> Total Slots: ' + totalSlots + ' | ' +
                                '<span class="dashicons dashicons-yes"></span> Used: ' + usedSlots + ' | ' +
                                '<span class="dashicons dashicons-marker"></span> Remaining: ' + remainingSlots
                            );
                            
                            // Show no orders message if this was the last one
                            if ($accountRow.find('.order-row').length === 0) {
                                var colSpan = $accountRow.find('tr:eq(2) th').length;
                                $accountRow.append(
                                    '<tr><td colspan="' + colSpan + '">' +
                                    '<span class="dashicons dashicons-info"></span> No orders associated with this account.' +
                                    '</td></tr>'
                                );
                            }
                        });
                    } else {
                        alert('Error: ' + response.data);
                        $button.prop('disabled', false).html('<span class="dashicons dashicons-no"></span> Remove');
                    }
                },
                error: function() {
                    alert('Error: Could not remove order');
                    $button.prop('disabled', false).html('<span class="dashicons dashicons-no"></span> Remove');
                }
            });
        });
        
        // Search functionality
        $(document).on('input', '#wsm-search-input', function() {
            var searchTerm = $(this).val().toLowerCase();
            
            $('.account-group').each(function() {
                var $accountGroup = $(this);
                var email = $accountGroup.find('tr:eq(1) td:first').text().toLowerCase();
                var shown = false;
                
                if (email.indexOf(searchTerm) > -1) {
                    $accountGroup.show();
                    $accountGroup.find('.order-row').removeClass('hidden-by-search');
                    return;
                }
                
                $accountGroup.find('.order-row').each(function() {
                    var $row = $(this);
                    var rowText = $row.text().toLowerCase();
                    
                    if (rowText.indexOf(searchTerm) > -1) {
                        $row.removeClass('hidden-by-search');
                        shown = true;
                    } else {
                        $row.addClass('hidden-by-search');
                    }
                });
                
                if (shown) {
                    $accountGroup.show();
                } else {
                    $accountGroup.hide();
                }
            });
        });
        
        // Sorting functionality
        $(document).on('click', '.sortable-header', function() {
            var $header = $(this);
            var sortType = $header.data('sort-type') || 'string';
            var sortDirection = $header.hasClass('sort-asc') ? 'desc' : 'asc';
            
            // Update header classes
            $('.sortable-header').removeClass('sort-asc sort-desc');
            $header.addClass('sort-' + sortDirection);
            
            // Sort the accounts
            var $accountGroups = $('.account-group').get();
            $accountGroups.sort(function(a, b) {
                var aValue = $(a).find('tr:eq(1) td:eq(' + $header.index() + ')').text();
                var bValue = $(b).find('tr:eq(1) td:eq(' + $header.index() + ')').text();
                
                if (sortType === 'date') {
                    aValue = new Date(aValue);
                    bValue = new Date(bValue);
                } else if (sortType === 'number') {
                    aValue = parseFloat(aValue);
                    bValue = parseFloat(bValue);
                }
                
                if (sortDirection === 'asc') {
                    return aValue > bValue ? 1 : -1;
                } else {
                    return aValue < bValue ? 1 : -1;
                }
            });
            
            // Reappend sorted accounts
            $.each($accountGroups, function(index, item) {
                $('.netflix-manager-table').append(item);
            });
        });
    }
    
    /**
     * Initialize service management features
     */
    function initServiceManager() {
        // Remove service confirmation
        $(document).on('click', '.wsm-remove-service', function(e) {
            e.preventDefault();
            
            var serviceKey = $(this).data('service-key');
            if (!serviceKey) {
                alert('Error: No service selected');
                return;
            }
            
            // Show confirmation modal
            $('#wsm-remove-service-modal').fadeIn(300);
            
            // Store service key for confirmation
            $('.wsm-confirm-remove-service').data('service-key', serviceKey);
        });
        
        // Confirm remove service
        $(document).on('click', '.wsm-confirm-remove-service', function() {
            var serviceKey = $(this).data('service-key');
            if (!serviceKey) {
                alert('Error: No service selected');
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
                    nonce: dbbAdmin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        window.location.href = response.data.redirect;
                    } else {
                        alert('Error: ' + response.data);
                        $button.prop('disabled', false).text('Yes, Remove Service');
                        $('#wsm-remove-service-modal').fadeOut(300);
                    }
                },
                error: function(xhr, status, error) {
                    alert('Error processing request: ' + error);
                    $button.prop('disabled', false).text('Yes, Remove Service');
                    $('#wsm-remove-service-modal').fadeOut(300);
                }
            });
        });
    }
    
    /**
     * Initialize WooCommerce order page features
     */
    function initWooCommerceOrderPage() {
        // Initialize Select2 for service selector if exists
        if ($('#wsm-service-selector').length) {
            $('#wsm-service-selector').select2({
                width: '100%',
                placeholder: 'Select a service'
            });
        }
        
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
            
            // Show loading state
            var $button = $(this);
            $button.prop('disabled', true).html('<span class="dashicons dashicons-update-alt"></span> Adding...');
            
            // Add new service row via AJAX
            $.post(ajaxurl, {
                action: 'wsm_get_service_row',
                service_key: serviceKey,
                order_id: $('input#post_ID').val(),
                nonce: dbbAdmin.nonce
            }, function(response) {
                if (response.success) {
                    $('#wsm-service-accounts').append(response.data);
                    // Reinitialize Select2 for new dropdowns
                    $('.wsm-account-select').select2({
                        width: '100%'
                    });
                } else {
                    alert('Error: ' + response.data);
                }
                
                // Reset service selector
                $selector.val('').trigger('change');
                
                // Reset button state
                $button.prop('disabled', false).html('<span class="dashicons dashicons-plus-alt2"></span> Add Service');
    });
}); 
        
        // Remove service row
        $(document).on('click', '.wsm-remove-service-row', function() {
            if (confirm('Are you sure you want to remove this service?')) {
                $(this).closest('.wsm-service-row').remove();
            }
        });
    }

})(jQuery); 

/**
 * Redeem Key Manager Module
 */
(function($) {
    'use strict';

    // Only run on redeem key manager pages
    if (window.location.href.indexOf('redeem_key_manager') === -1) {
        return;
    }

    // Initialize tabs if needed
    $('.nav-tab-wrapper .nav-tab').on('click', function(e) {
        e.preventDefault();
        
        var tab = $(this).attr('href').split('tab=')[1];
        window.location.href = $(this).attr('href');
    });

    // Product variation selector
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
    
    // Confirmation for deleting key sets
    $('.rkm-manage-keys form').on('submit', function(e) {
        return confirm('Are you sure you want to delete this key set? This action cannot be undone.');
    });
    
})(jQuery); 

/**
 * Sales Monitor Module
 */
(function($) {
    'use strict';

    // Only run on sales monitor pages
    if (window.location.href.indexOf('sales_monitor') === -1) {
        return;
    }

    // Initialize tabs if needed
    $('.nav-tab-wrapper .nav-tab').on('click', function(e) {
        e.preventDefault();
        
        var tab = $(this).attr('href').split('tab=')[1];
        window.location.href = $(this).attr('href');
    });

    // Product variation selector
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
    
    // Confirmation for deleting costs and expenses
    $('.sm-products form, .sm-expenses form').on('submit', function(e) {
        return confirm('Are you sure you want to delete this item? This action cannot be undone.');
    });
    
    // Initialize date pickers
    if ($('#start_date, #end_date').length) {
        $('#start_date, #end_date').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true
        });
    }
    
    // Initialize expense date picker
    if ($('#expense_date').length) {
        $('#expense_date').datepicker({
            dateFormat: 'yy-mm-dd',
            changeMonth: true,
            changeYear: true
        });
    }
    
})(jQuery); 