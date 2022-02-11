$(document).ready(() => {
    $('.showall').hide();
    $('.pagination_next b').hide();
    $('.pagination_previous b').hide();

    $('.select-all').on('click', function () {
        let checked = $(this).prop('checked');
        $(this).closest('table').find('.selected-orders').prop('checked', checked);
    });

    $('.action-call').on('click', function (e) {
        let ids = [];
        $(this).closest('.tab-pane').find('.selected-orders:checked').each(function () {
            ids.push($(this).val());
        });
        if (ids.length == 0 && this.id == 'print-labels') {
            alert(check_orders);
            return false;
        } else {
            let link = this.id == 'print-labels' ? bulkLabelsLink : manifestLink;
            $(this).attr('href', `${link}&order_ids=${ids.join(',')}`);
        }

    });

    /* Start courier call */
    $('#requestOmnivaltQourier').on('click', function (e) {
        e.preventDefault();
        $.ajax({
            url: carrier_cal_url,
            type: 'get',
            beforeSend: function () {
                $('#alertList').empty();
            },
            success: function (data) {
                if (data == '1') {
                    $('#alertList').append(
                        `<div class="alert alert-success" id="remove2">
                            <strong>${finished_trans}</strong>${message_sent_trans}
                        </div>`
                    );
                } else {
                    $('#alertList').append(
                        `<div class="alert alert-danger" id="remove2">
                                ${incorrect_response_trans}
                        </div>`
                    );
                }

                setTimeout(function () {
                    $('#remove2').remove();
                    $('#myModal').modal('hide');
                }, 3000);

            },
            error: function (xhr, ajaxOptions, thrownError) {
                alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
            }
        });
    });
    /*/End of courier call */
    var params ={};
    window.location.search
        .replace(/[?&]+([^=&]+)=([^&]*)/gi, function (str, key, value) {
                params[key] = value;
            }
        );
    if (params['tab'] == 'completed')
        $('[href="#tab-sent-orders"]').trigger('click');

    /* Search script */
    $('#button-search').on('click', function () {
        var tracking = $('input[name="tracking_nr"]').val();
        var customer = $('input[name="customer"]').val();
        var dateAdd = $('input[name="input-date-added"]').val();
        $.ajax({
            url: ajaxCall,
            type: 'post',
            dataType: 'json',
            data: {
                'tracking_nr' : tracking,
                'customer' : customer,
                'input-date-added' : dateAdd,
            },
            beforeSend: function () {
                $('#searchTable').empty();
            },
            success: function (data) {
                if (data != null && data[0] && Object.keys(data[0]).length > 0) {
                    datas = data;
                    for (data of datas) {
                        $('#searchTable').append(`
                            <tr>
                                <td class='left'>${data['id_order']}</td>
                                <td><a href='${orderLink}&id_order=${data['id_order']}' target='_blank'>${data['full_name']}</a></td>
                                <td>${data['tracking_number']}</a></td>
                                <td>${data['date_add']}</td>
                                <td>${data['total_paid_tax_incl']}</td>
                                <td><a href='${bulkLabelsLink}&order_ids=${data['id_order']}' class='btn btn-default btn-xs' target='_blank'>${labels_trans}</a></td>
                            </tr>`
                        );
                    }
                } else
                    $('#searchTable').append(`<tr><td colspan='6'>${not_found_trans}</td>`);
            },
            error: function (xhr, ajaxOptions, thrownError) {
            }
        });
    });
});