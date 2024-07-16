$(document).ready(function () {
    $('.omnivalt-carrier').on('change', 'select', function () {
        if ($(this).val() == omnivalt_terminal_carrier)
            $('.omnivalt-terminal').show();
        else
            $('.omnivalt-terminal').hide();
    });
    $('.omnivalt-carrier select').trigger('change');


    function labelOrderInfo() {
        $("#omnivaltOrderPrintLabels").attr('disabled', 'disabled');
        var formData = $("#omnivaltOrderPrintLabelsForm").serialize() + '&' + $.param({
            ajax: "1",
            'id_order': id_order,
        });

        $.ajax({
            type: "POST",
            url: printLabelsUrl,
            async: false,
            dataType: "json",
            data: formData,
            success: function (res) {
                if (typeof res.error !== "undefined") {
                    $("#omnivaltOrderSubmitForm").find('.response').html('<div class="alert alert-danger">' + res.error + '</div>');
                    $("#omnivaltOrderPrintLabels").removeAttr('disabled');
                } else {
                    $("#omnivaltOrderSubmitForm").find('.response').html(`<div class="alert alert-success">${success_add_trans}</div>`);
                    $("#omnivaltOrderPrintLabels").removeAttr('disabled');
                    window.location.href = location.href
                }
            },
            error: function (res) {

            }
        });
        return $("#omnivaltOrderPrintLabels").is(":disabled");
    }

    function saveOrderInfo() {
        $("#omnivaltOrderSubmitBtn").attr('disabled', 'disabled');
        var formData = $("#omnivaltOrderSubmitForm").serialize() + '&' + $.param({
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
                //disable the inputs
                if (typeof res.error !== "undefined") {
                    $("#omnivaltOrderSubmitForm").find('.response').html('<div class="alert alert-danger">' + res.error + '</div>');
                    $("#omnivaltOrderSubmitBtn").removeAttr('disabled');
                } else {
                    $("#omnivaltOrderSubmitForm").find('.response').html(`<div class="alert alert-success">${success_add_trans}</div>`);
                    $("#omnivaltOrderSubmitBtn").removeAttr('disabled');
                    $("#omnivalt_print_btn").hide();
                }


            },
            error: function (res) {

            }
        });
        return $("#omnivaltOrderSubmitBtn").is(":disabled");
    }

    $("#omnivaltOrderPrintLabels").unbind('click').bind('click', function (e) {
        $(this).attr('disabled', 'disabled');
        $("#omnivaltOrderSubmitForm").find('.response').html('');
        e.preventDefault();
        e.stopPropagation();
        labelOrderInfo();

        return false;
    });
    $("#omnivaltOrderSubmitBtn").unbind('click').bind('click', function (e) {
        $(this).attr('disabled', 'disabled');
        $("#omnivaltOrderSubmitForm").find('.response').html('');
        e.preventDefault();
        e.stopPropagation();
        saveOrderInfo();

        return false;
    });
});
