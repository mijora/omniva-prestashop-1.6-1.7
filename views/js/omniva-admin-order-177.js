$(document).ready(function () {
    let omnivaltPanel = $('.omniva-block');

    $('#omniva-carrier').on('change', function () {
        for (var key in omnivalt_methods) {
            if ($(this).val() != omnivalt_methods[key].carrier_id) {
                continue;
            }

            $('.omniva-terminal-block').addClass('d-none');
            $('.omniva-cod-block').addClass('d-none');
            $('.omniva-additionalservices-block').addClass('d-none');

            if (key == 'pt') {
                $('.omniva-terminal-block').removeClass('d-none');
            }
            if (! omnivalt_methods[key].is_international) {
                $('.omniva-cod-block').removeClass('d-none');
                $('.omniva-additionalservices-block').removeClass('d-none');
            }
        }
    });
    $('#omniva-carrier').trigger('change');

    function disableButton(id, status) {
        omnivaltPanel[0].querySelector(id).disabled = status;
    }

    function cleanResponse() {
        $('.omniva-response')
            .removeClass(['alert-danger', 'alert-warning', 'alert-success'])
            .addClass('d-none')
            .html('');
    }

    function showResponse(msg, type) {
        cleanResponse();
        $('.omniva-response')
            .removeClass('d-none')
            .addClass(type)
            .html(msg);
    }

    function getErrorText(error_type) {
        switch(error_type) {
            case "parsererror":
                return omnivalt_text.ajax_parsererror;
            default:
                return omnivalt_text.ajax_unknownerror;
        }
    }

    function labelOrderInfo() {
        disableButton('#omnivaltOrderPrintLabels', true);

        let formData = $("#omnivaltOrderSubmitForm")
            .serialize() + '&' + $.param({
            'ajax': "1",
            'id_order': id_order
        });

        $.ajax({
            type: "POST",
            url: printLabelsUrl,
            async: false,
            dataType: "json",
            data: formData,
            success: function (res) {
                disableButton('#omnivaltOrderPrintLabels', false);

                if (typeof res.error !== "undefined") {
                    showResponse(res.error, 'alert-danger');
                    return;
                }

                showResponse(success_add_trans, 'alert-success');

                setTimeout(function () {
                    window.location.href = location.href
                }, 1000);
            },
            error: function (res, error_type) {
                showResponse(getErrorText(error_type), "alert-danger");
                disableButton('#omnivaltOrderPrintLabels', false);
            }
        });
    }

    function saveOrderInfo() {
        disableButton('#omnivaltOrderSubmitBtn', true);
        var formData = $("#omnivaltOrderSubmitForm")
            .serialize() + '&' + $.param({
            ajax: "1",
            order_id: id_order,
        });


        $.ajax({
            type: "POST",
            url: moduleUrl,
            async: false,
            dataType: "json",
            data: formData,
            success: function (res) {
                disableButton('#omnivaltOrderSubmitBtn', false);

                if (typeof res.error !== "undefined") {
                    showResponse(res.error, 'alert-danger');
                    return;
                }

                showResponse(omnivalt_text.save_success, 'alert-success');

                $("#omnivalt_print_btn").addClass('d-none');
            },
            error: function (res, error_type) {
                showResponse(getErrorText(error_type), "alert-danger");
                disableButton('#omnivaltOrderSubmitBtn', false);
            }
        });
    }

    $("#omnivaltOrderPrintLabels").unbind('click').bind('click', function (e) {
        disableButton('#omnivaltOrderPrintLabels', true);
        e.preventDefault();
        e.stopPropagation();
        labelOrderInfo();

        return false;
    });

    $("#omnivaltOrderSubmitBtn").unbind('click').bind('click', function (e) {
        disableButton('#omnivaltOrderSubmitBtn', true);
        e.preventDefault();
        e.stopPropagation();
        saveOrderInfo();

        return false;
    });
});
