/**
 * Kiyoh Admin Configuration JavaScript
 *
 * @author    Kiyoh
 * @copyright 2024 Kiyoh
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License 3.0 (AFL-3.0)
 */

$(document).ready(function() {
    // Initialize form behavior
    initializeFormBehavior();
    
    // Load dynamic data
    loadOrderStatuses();
    loadCustomerGroups();
    loadProductTypes();
});

/**
 * Initialize form behavior and event handlers
 */
function initializeFormBehavior() {
    // Platform change handler
    $('#KIYOH_PLATFORM').on('change', function() {
        updatePlatformSpecificFields();
    });
    
    // Auto-sync toggle handler
    $('#KIYOH_AUTO_SYNC_on').on('change', function() {
        toggleProductSyncFields($(this).is(':checked'));
    });
    
    // Language auto-detection toggle handler
    $('#KIYOH_LANGUAGE_AUTO_on').on('change', function() {
        toggleLanguageFields($(this).is(':checked'));
    });
    
    // Initialize field states
    updatePlatformSpecificFields();
    toggleProductSyncFields($('#KIYOH_AUTO_SYNC_on').is(':checked'));
    toggleLanguageFields($('#KIYOH_LANGUAGE_AUTO_on').is(':checked'));
}

/**
 * Update platform-specific field descriptions and validation
 */
function updatePlatformSpecificFields() {
    var platform = $('#KIYOH_PLATFORM').val();
    var platformName = platform === 'kiyoh' ? 'Kiyoh.com' : 'Klantenvertellen.nl';
    
    // Update field descriptions
    $('#KIYOH_LOCATION_ID').siblings('.help-block').text(
        'Your ' + platformName + ' Location ID'
    );
    $('#KIYOH_API_KEY').siblings('.help-block').text(
        'Your ' + platformName + ' API Key'
    );
}

/**
 * Toggle product sync related fields
 */
function toggleProductSyncFields(enabled) {
    var fields = ['#KIYOH_EXCLUDED_TYPES', '#KIYOH_EXCLUDED_CODES'];
    
    fields.forEach(function(field) {
        if (enabled) {
            $(field).prop('disabled', false).closest('.form-group').show();
        } else {
            $(field).prop('disabled', true).closest('.form-group').hide();
        }
    });
}

/**
 * Toggle language-related fields
 */
function toggleLanguageFields(autoDetect) {
    if (autoDetect) {
        $('#KIYOH_FALLBACK_LANGUAGE').closest('.form-group').show();
    } else {
        $('#KIYOH_FALLBACK_LANGUAGE').closest('.form-group').hide();
    }
}

/**
 * Test API connection
 */
function testApiConnection() {
    var platform = $('#KIYOH_PLATFORM').val();
    var locationId = $('#KIYOH_LOCATION_ID').val();
    var apiKey = $('#KIYOH_API_KEY').val();
    
    if (!platform || !locationId || !apiKey) {
        showNotification('error', 'Please fill in all required fields before testing the connection.');
        return;
    }
    
    // Show loading state
    var $button = $('button[name="test_connection"]');
    var originalText = $button.text();
    $button.text('Testing...').prop('disabled', true);
    
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: true,
            action: 'test_connection',
            platform: platform,
            location_id: locationId,
            api_key: apiKey
        },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                showNotification('success', 'API connection successful! Connected to ' + response.platform + ' (Location: ' + response.location_id + ')');
            } else {
                showNotification('error', 'API connection failed: ' + response.message);
            }
        },
        error: function(xhr, status, error) {
            showNotification('error', 'Connection test failed: ' + error);
        },
        complete: function() {
            // Restore button state
            $button.text(originalText).prop('disabled', false);
        }
    });
}

/**
 * Load order statuses dynamically
 */
function loadOrderStatuses() {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: true,
            action: 'get_order_statuses'
        },
        dataType: 'json',
        success: function(response) {
            if (response.statuses) {
                updateSelectOptions('#KIYOH_TRIGGER_STATUS', response.statuses, 'id', 'name');
            }
        },
        error: function(xhr, status, error) {
            console.error('Failed to load order statuses:', error);
        }
    });
}

/**
 * Load customer groups dynamically
 */
function loadCustomerGroups() {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: true,
            action: 'get_customer_groups'
        },
        dataType: 'json',
        success: function(response) {
            if (response.groups) {
                updateSelectOptions('#KIYOH_EXCLUDED_GROUPS', response.groups, 'id', 'name');
            }
        },
        error: function(xhr, status, error) {
            console.error('Failed to load customer groups:', error);
        }
    });
}

/**
 * Load product types dynamically
 */
function loadProductTypes() {
    $.ajax({
        url: window.location.href,
        type: 'POST',
        data: {
            ajax: true,
            action: 'get_product_types'
        },
        dataType: 'json',
        success: function(response) {
            if (response.types) {
                // Add product type suggestions to excluded types field
                addProductTypeSuggestions(response.types);
            }
        },
        error: function(xhr, status, error) {
            console.error('Failed to load product types:', error);
        }
    });
}

/**
 * Update select options dynamically
 */
function updateSelectOptions(selector, options, idField, nameField) {
    var $select = $(selector);
    if ($select.length === 0) return;
    
    // Clear existing options except the first (placeholder)
    $select.find('option:not(:first)').remove();
    
    // Add new options
    options.forEach(function(option) {
        $select.append(
            $('<option></option>')
                .attr('value', option[idField])
                .text(option[nameField])
        );
    });
}

/**
 * Add product type suggestions
 */
function addProductTypeSuggestions(types) {
    var $field = $('#KIYOH_EXCLUDED_TYPES');
    if ($field.length === 0) return;
    
    // Create a suggestion list
    var suggestions = types.map(function(type) {
        return type.reference;
    }).filter(function(ref) {
        return ref && ref.length > 0;
    });
    
    if (suggestions.length > 0) {
        var helpText = $field.siblings('.help-block').text();
        helpText += ' Available types: ' + suggestions.slice(0, 10).join(', ');
        if (suggestions.length > 10) {
            helpText += '...';
        }
        $field.siblings('.help-block').text(helpText);
    }
}

/**
 * Show notification message
 */
function showNotification(type, message) {
    var alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
    var iconClass = type === 'success' ? 'check' : 'warning-sign';
    
    var notification = $('<div class="alert ' + alertClass + ' alert-dismissible" role="alert">' +
        '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
        '<span aria-hidden="true">&times;</span>' +
        '</button>' +
        '<i class="icon-' + iconClass + '"></i> ' + message +
        '</div>');
    
    // Remove existing notifications
    $('.alert').remove();
    
    // Add new notification at the top of the content
    $('.panel:first').before(notification);
    
    // Auto-hide success notifications after 5 seconds
    if (type === 'success') {
        setTimeout(function() {
            notification.fadeOut();
        }, 5000);
    }
    
    // Scroll to top to show notification
    $('html, body').animate({ scrollTop: 0 }, 300);
}

/**
 * Form validation before submission
 */
function validateForm() {
    var errors = [];
    
    // Check required fields
    if (!$('#KIYOH_PLATFORM').val()) {
        errors.push('Platform is required');
    }
    
    if (!$('#KIYOH_LOCATION_ID').val()) {
        errors.push('Location ID is required');
    }
    
    if (!$('#KIYOH_API_KEY').val()) {
        errors.push('API Key is required');
    }
    
    // Validate numeric fields
    var delayDays = parseInt($('#KIYOH_DELAY_DAYS').val());
    if (isNaN(delayDays) || delayDays < 0) {
        errors.push('Delay days must be a non-negative number');
    }
    
    var maxProducts = parseInt($('#KIYOH_MAX_PRODUCTS').val());
    if (isNaN(maxProducts) || maxProducts < 1 || maxProducts > 10) {
        errors.push('Maximum products must be between 1 and 10');
    }
    
    if (errors.length > 0) {
        showNotification('error', 'Please fix the following errors:<br>• ' + errors.join('<br>• '));
        return false;
    }
    
    return true;
}

// Attach form validation to submit
$(document).on('submit', 'form', function(e) {
    if ($(this).is('[data-kiyoh-connect]')) {
        return;
    }
    if (!validateForm()) {
        e.preventDefault();
        return false;
    }
});