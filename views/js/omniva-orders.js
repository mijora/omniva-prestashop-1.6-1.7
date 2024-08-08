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
    $('#requestOmnivaltCourier').on('click', function (e) {
        e.preventDefault();
        $.ajax({
            url: carrier_cal_url,
            type: 'get',
            dataType: 'json',
            beforeSend: function () {
                $('#alertList').empty();
            },
            success: function (data) {
                let hide_after = 3000;
                if (data == '1')
                {
                    $('#alertList').append(
                        `<div class="alert alert-success" id="remove2">
                            <strong>${finished_trans}</strong> ${message_sent_trans}
                        </div>`
                    );
                }
                else if(typeof data.error !== 'undefined')
                {
                    $('#alertList').append(
                        `<div class="alert alert-danger" id="remove2">
                                ${data.error}
                        </div>`
                    );
                }
                else if(typeof data.call_id !== 'undefined')
                {
                    $('#alertList').append(
                        `<div class="alert alert-success" id="remove2">
                            <strong>${finished_trans}</strong> ${courier_call_success} (ID: ${data.call_id}). ${courier_arrival_between} ${data.start_time} - ${data.end_time}.
                        </div>`
                    );
                    hide_after = 0;
                    $('#myModal .modal-footer button').hide();
                    $('#modalOmnivaltClose').show();
                    $('.omnivalt-courier-calls').show();
                    setTimeout(function () {
                        let splited_start_time = data.start_time.split(" ");
                        let splited_end_time = data.end_time.split(" ");
                        let row = `<tr>
                            <td><small>${splited_start_time[0]}</small></td>
                            <td>${splited_start_time[1]}</td>
                            <td>`;
                        if (splited_start_time[0] !== splited_end_time[0]) {
                            row += `<small>${splited_end_time[0]}</small>`
                        }
                        row += `</td>
                            <td>${splited_end_time[1]}</td>
                            <td><button class="btn btn-danger btn-xs" type="button" data-callid="${data.call_id}">&times;</button></td>
                        </tr>`;
                        if ($('#omnivalt-courier-calls-list > tbody > tr').length) {
                            $('#omnivalt-courier-calls-list tr:last').after(row);
                        } else {
                            $('#omnivalt-courier-calls-list').append('<tbody/>').append(row);
                        }
                    }, 1000);
                }
                else
                {
                    $('#alertList').append(
                        `<div class="alert alert-danger" id="remove2">
                                ${incorrect_response_trans}
                        </div>`
                    );
                }

                if (hide_after > 0) {
                    setTimeout(function () {
                        $('#remove2').remove();
                        $('#myModal').modal('hide');
                    }, hide_after);
                }
            },
            error: function (xhr, ajaxOptions, thrownError) {
                alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
            }
        });
    });

    $('#modalOmnivaltClose').on('click', function (e) {
        e.preventDefault();
        $('#myModal .modal-footer button').show();
        $('#modalOmnivaltClose').hide();
        $('#remove2').remove();
    });

    $(document).on('click', '#omnivalt-courier-calls-list button', function (e) {
        e.preventDefault();
        let call_id = $(this).attr('data-callid');
        let row = $(this).closest('tr');
        if (call_id) {
            $.ajax({
                url: cancel_courier_call + call_id,
                type: 'get',
                dataType: 'json',
                beforeSend: function () {
                    $('#alertList').empty();
                },
                success: function (data) {
                    if (data) {
                        row.find('td').css('background-color', '#f9000052');
                        setTimeout(function () {
                            row.remove();
                        }, 1000);
                    }
                },
                error: function (xhr, ajaxOptions, thrownError) {
                    alert(thrownError + "\r\n" + xhr.statusText + "\r\n" + xhr.responseText);
                }
            });
        }
    });
    /* End of courier call */

    var params ={};
    window.location.search
        .replace(/[?&]+([^=&]+)=([^&]*)/gi, function (str, key, value) {
                params[key] = value;
            }
        );
    if (params['tab'] == 'completed')
        $('[href="#tab-sent-orders"]').trigger('click');

    if (params['tab'] == 'new')
        $('[href="#tab-general"]').trigger('click');

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
                                <td class='left'><a href='${orderLink}&id_order=${data['id_order']}' target='_blank'>${data['id_order']}</a></td>
                                <td>${data['full_name']}</td>
                                <td>${data['tracking_numbers']}</td>
                                <td>${data['date_add']}</td>
                                <td>${data['total_paid_tax_incl']}</td>
                                <td><a href='${labelsLink}&id_order=${data['id']}&history=${data['history']}' class='btn btn-default btn-xs' target='_blank'>${labels_trans}</a></td>
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