﻿$(document).ready(function () {
    if (help_class_name == 'AdminOrders') {
        if(typeof(omnivaltIsPS177Plus) !== 'undefined' && omnivaltIsPS177Plus)
        {
            var bulk_dropdown = $('#order_grid .js-bulk-actions-btn + .dropdown-menu');
            bulk_dropdown.append(`<button id="order_grid_bulk_action_omniva_bulk_labels" class="dropdown-item js-bulk-modal-form-submit-btn" type="button" onclick="sendOmnivaltBulkAction177('${omnivalt_admin_action_labels}');">
                                ${omnivalt_bulk_labels}</button>`);
            bulk_dropdown.append(`<button id="order_grid_bulk_action_omniva_bulk_manifests" class="dropdown-item js-bulk-modal-form-submit-btn" type="button" onclick="sendOmnivaltBulkAction177('${omnivalt_admin_action_manifests}');">
                                ${omnivalt_bulk_manifests}</button>`);
        }
        else
        {
            var bulk_dropdown = $('.bulk-actions ul.dropdown-menu');
            bulk_dropdown.append('<li><a href="#" onclick="sendOmnivaltBulkAction($(this).closest(\'form\').get(0), \'' + omnivalt_admin_action_labels + '\',$(this),true);"><i class="icon-cloud-download"></i>&nbsp;' + omnivalt_bulk_labels + '</a></li>');
            bulk_dropdown.append('<li><a href="#" onclick="sendOmnivaltBulkAction($(this).closest(\'form\').get(0), \'' + omnivalt_admin_action_manifests + '\',$(this),true);"><i class="icon-cloud-download"></i>&nbsp;' + omnivalt_bulk_manifests + '</a></li>');
        }
    }
    if($('#omnivalt_api_country').val() == 'lt')
    {
        $('#omnivalt_send_off option[value="po"], #omnivalt_send_off option[value="lc"]').hide();
        $('#omnivalt_ee_service_on').closest('.form-group').hide();
        $('#omnivalt_fi_service_on').closest('.form-group').hide();
    }
    $('#omnivalt_api_country').on('change', function () {
        if($(this).val() == 'lt')
        {
            $('#omnivalt_send_off option[value="po"], #omnivalt_send_off option[value="lc"]').hide();
            $('#omnivalt_ee_service_on').closest('.form-group').hide();
            $('#omnivalt_fi_service_on').closest('.form-group').hide();
        }
        else
        {
            $('#omnivalt_send_off option').show();
            $('#omnivalt_ee_service_on').closest('.form-group').show();
            $('#omnivalt_fi_service_on').closest('.form-group').show();
        }
    });

    $(window).bind('hashchange', function () {
        var hash = window.location.hash.slice(1);
        if (hash === 'dev') {
            omnivaltShowDevButtons();
        } else {
            omnivaltShowDevButtons(false);
        }
    });
    $(window).trigger('hashchange');
});

function omnivaltShowDevButtons(show = true) {
    if (show) {
        $('.omniva-devtool').removeClass('hidden');
    } else {
        $('.omniva-devtool').addClass('hidden');
    }
}

function sendOmnivaltBulkAction(form, action, object, reload) {
    var order_ids = '';
    $("input[name='orderBox[]']:checked").each(function (index) {
        order_ids += $(this).val() + ',';
    });
    if (order_ids) {
        object.attr('href', action + '&order_ids=' + order_ids);
        object.attr('target', '_blank');
        if (reload) {
            setTimeout(function () {
                window.location.href = location.href;
            }, 5000);
        }
    } else {
        alert('Select orders');
    }
    return false;
}


function sendOmnivaltBulkAction177(action) {
    var order_ids = '';
    $("input[name='order_orders_bulk[]']:checked").each(function (index) {
        order_ids += $(this).val() + ',';
    });
    if (order_ids) {
        window.open(action + '&order_ids=' + order_ids, '_blank');
    } else {
        alert('Select orders');
    }
}

function omivaltshippingForceTerminalUpdate(button) {
    const form = document.createElement('form');
    form.action = button.href;
    form.method = "post";
    form.enctype = "multipart/form-data";
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'forceUpdateTerminals';
    input.value = '1';
    form.append(input);
    document.body.append(form);
    form.submit();
}

function omivaltshippingForceSendStatistics(button) {
    const form = document.createElement('form');
    form.action = button.href;
    form.method = "post";
    form.enctype = "multipart/form-data";
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'forceSendStatistics';
    input.value = '1';
    form.append(input);
    document.body.append(form);
    form.submit();
}