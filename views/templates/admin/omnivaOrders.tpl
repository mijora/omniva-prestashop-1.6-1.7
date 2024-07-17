<style>
    .tab-content {
        border: 1px solid #ddd;
        padding: 10px;
    }
</style>

<div class="panel col-lg-12">
    <div class="panel-heading">
        <h4>{l s='Omniva manifest:' mod='omnivaltshipping'} {$manifestNum}</h4>
    </div>
    <button type="button" class="btn btn-default" data-toggle="modal" data-target="#myModal"
        title="{l s='Courier call' mod='omnivaltshipping'}" style="position:absolute; right:10px">
        <i class="fa fa fa-send-o"></i>{l s='Courier call' mod='omnivaltshipping'}
    </button>


    <ul class="nav nav-tabs">
        <li class="active"><a href="#tab-general" data-toggle="tab">{l s='New orders' mod='omnivaltshipping'}</a></li>
        <li><a href="#tab-data" data-toggle="tab">{l s='Awaiting' mod='omnivaltshipping'}</a></li>
        <li><a href="#tab-sent-orders" data-toggle="tab">{l s='Completed' mod='omnivaltshipping'}</a></li>
        <li><a href="#tab-search" data-toggle="tab">{l s='Search' mod='omnivaltshipping'}</a></li>
    </ul>
    <div class="tab-content">
        <!-- New Orders -->
        <div class="tab-pane active" id="tab-general">
            {if $newOrders != null}
                <h4 style="display: inline:block;vertical-align: baseline;">{l s='New orders' mod='omnivaltshipping'}</h4>
                <a id="print-manifest" href="" class="btn btn-default btn-xs action-call float-right pull-right"
                    target='_blank' title="{l s='Generate a manifest and move to Completed tab all orders that have a label' mod='omnivaltshipping'}">{l s='Send all Orders with label (Generate manifest)' mod='omnivaltshipping'}</a>
                <table class="table order">
                    <thead>
                        {include file="./_partials/orders_table_header.tpl" select_all=true}
                    </thead>
                    <tbody>
                        {assign var=result value=''}
                        {foreach $newOrders as $order}
                            <tr>
                                <td><input type="checkbox" class="selected-orders" value="{$order.id_order}" /></td>
                                <td><a href="{$orderLink}&id_order={$order.id_order}">{$order.id_order}</a></td>
                                <td>{$order.firstname} {$order.lastname}</td>
                                <td>
                                    {if $order.tracking_numbers}
                                        {implode(', ', json_decode($order.tracking_numbers))}
                                    {/if}
                                </td>
                                <td>{$order.date_upd}</td>
                                <td>{$order.total_paid|round:2}</td>
                                <td>
                                    {if $order.tracking_numbers == null}
                                        <a href="{$generateLabelsLink}{$order.id_order}"
                                            class="btn btn-info btn-xs">{l s='Generate Labels' mod='omnivaltshipping'}</a>
                                        <a href="{$orderSkip}{$order.id_order}"
                                            class="btn btn-danger btn-xs">{l s='Skip' mod='omnivaltshipping'}</a>
                                    {else}
                                        <a href="{$labelsLink}&id_order={$order.id_order}" class="btn btn-success btn-xs"
                                            target="_blank">{l s='Labels' mod='omnivaltshipping'}</a>
                                    {/if}
                                </td>
                                {$result = "{$result},{$order.id_order}"}
                                {$manifest = $order.manifest}
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
                <a id="print-labels" href="" class="btn btn-default btn-xs action-call"
                    target='_blank'>{l s='Labels' mod='omnivaltshipping'}</a>
                <a id="print-manifest" href="" class="btn btn-default btn-xs action-call float-right pull-right"
                    target='_blank' title="{l s='Generate a manifest and move to Completed tab all orders that have a label' mod='omnivaltshipping'}">{l s='Send all Orders with label (Generate manifest)' mod='omnivaltshipping'}</a>
                <hr />
                <br />
            {else}
                <p class="text-center">{l s='There are no orders' mod='omnivaltshipping'}</p>
            {/if}
            <div class="text-center">
                {$finished_pagination_content}
            </div>
        </div>

        <!--/New Orders -- Skipped Orders -->
        <div class="tab-pane" id="tab-data">
            {if $skippedOrders != null}
                <h4 style="display: inline:block;vertical-align: baseline;">{l s='Skipped orders' mod='omnivaltshipping'}
                </h4>
                <table class="table order">
                    <thead>
                        {include file="./_partials/orders_table_header.tpl"}
                    </thead>
                    <tbody>
                        {foreach $skippedOrders as $order}
                            <tr>
                                <td><a href="{$orderLink}&id_order={$order.id_order}">{$order.id_order}</a></td>
                                <td>{$order.firstname} {$order.lastname}</td>
                                <td>{$order.tracking_number}</td>
                                <td>{$order.date_upd}</td>
                                <td>{$order.total_paid|round:2}</td>
                                <td>
                                    <a href="{$cancelSkip}{$order.id_order}"
                                        class="btn btn-danger btn-xs">{l s='Add to manifest' mod='omnivaltshipping'}</a>
                                </td>
                            </tr>
                        {/foreach}
                    </tbody>
                </table>
                <br />
                <hr />
                <br />
            {else}
                <p class="text-center">
                    {l s='There are no orders' mod='omnivaltshipping'}
                </p>
            {/if}
        </div>
        <!--/ Skipped Orders -->
        <!-- Completed Orders -->
        <div class="tab-pane" id="tab-sent-orders">
            {if isset($orders[0]['manifest']) && $orders[0]['manifest']}
                {assign var=hasOrder value=$orders[0]['manifest']+1}
            {else}
                {assign var=hasOrder value=null}
            {/if}
            {if $orders != null}

                <h4>{l s='Generated' mod='omnivaltshipping'}</h4>
                {assign var=newPage value=null}
                {assign var=result value=''}
                {foreach $orders as $order}
                    {if (isset($manifestOrd) && $order.manifest != $manifestOrd) || $newPage == null}
                        {assign var=newPage value=true}
                        </table>
                        <br>
                        <table class="table order">
                            <thead>
                                {include file="./_partials/orders_table_header.tpl" select_all=true}
                            </thead>
                            <tbody>
                    {/if}
                            <tr>
                                <td><input type="checkbox" class="selected-orders" value="{$order.id_order}" /></td>
                                <td><a href="{$orderLink}&id_order={$order.id_order}">{$order.id_order}</a></td>
                                <td>{$order.firstname} {$order.lastname}</td>
                                <td>
                                    {if $order.tracking_numbers}
                                        {implode(', ', json_decode($order.tracking_numbers))}
                                    {/if}
                                </td>
                                <td>{$order.date_upd}</td>
                                <td>{$order.total_paid|round:2}</td>
                                <td>
                                    <a href="{$labelsLink}&id_order={$order.id_order}&history={$order.history}"
                                        class="btn btn-success btn-xs" target="_blank">{l s='Labels' mod='omnivaltshipping'}</a>
                                </td>
                                {$result = "{$result},{$order.id_order}"}
                                {$manifestOrd = $order.manifest}
                            </tr>
                {/foreach}
            {/if}
            {if $orders != null}
                    </tbody>
                </table>
                <br>
                <a id="print-labels" href="" class="btn btn-default btn-xs action-call"
                    target='_blank'>{l s='Labels' mod='omnivaltshipping'}</a><br>
                <div class="text-center">
                    {$generated_pagination_content}
                </div>
            {/if}
        </div>
        <!--/ Completed Orders -->
        <!--/ Completed Orders -- Tab search -->
        <div class="tab-pane" id="tab-search">
            <table class="table">
                <thead>
                    {include file="./_partials/orders_table_header.tpl"}
                    <tr class="nodrag nodrop filter row_hover">
                        <th class="text-center"></th>
                        <th class="text-center">
                            <input type="text" class="filter" name="customer" value="">
                        </th>
                        <th>
                            <input type="text" class="filter" name="tracking_nr" value="">
                        </th>
                        <th class="text-center">
                            <input class="datetimepicker" name="input-date-added" type="text">
                            <script type="text/javascript">
                                $(document).ready(function() {
                                    $(".datetimepicker").datepicker({
                                        prevText: '',
                                        nextText: '',
                                        dateFormat: 'yy-mm-dd'
                                    });
                                });
                            </script>
                        </th>
                        <th class="text-center"></th>
                        <th class="actions"><a id="button-search" class="btn btn-default btn-xs">
                                {l s='Search' mod='omnivaltshipping'}
                            </a>
                        </th>
                    </tr>
                </thead>
                <tbody id="searchTable">
                    <tr>
                        <td colspan='6'>{l s='Search' mod='omnivaltshipping'}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>


    <!-- Modal Courier call-->
    <div id="myModal" class="modal fade" role="dialog">
        <div class="modal-dialog">
            <!-- Modal content-->
            <div class="modal-content">
                <form class="form-horizontal">
                    <div class="modal-header">
                        <button type="button" class="close" data-dismiss="modal">&times;</button>
                        <h4 class="modal-title">{l s='Final shipment - courier call.' mod='omnivaltshipping'}
                        </h4>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <strong>{l s='Important!' mod='omnivaltshipping'}</strong>
                            {l s='Latest time on which courier call can be made is 3 p.m. If you call courier later, we do not guarantee that shipment will be picked up.' mod='omnivaltshipping'}
                            <br />
                            <strong>{l s='Address and contact data' mod='omnivaltshipping'}</strong>
                            {l s='can be changed in Omnivalt module settings.' mod='omnivaltshipping'}
                        </div>
                        <h4>{l s='Data to be sent' mod='omnivaltshipping'}</h4>
                        <b>{l s='Sender:' mod='omnivaltshipping'}</b> {$sender}<br>
                        <b>{l s='Phone:' mod='omnivaltshipping'}</b> {$phone}<br>
                        <b>{l s='Zipcode:' mod='omnivaltshipping'}</b> {$postcode}<br>
                        <b>{l s='Address:' mod='omnivaltshipping'}</b> {$address}<br><br>
                        <div id="alertList"></div>
                        <div class="omnivalt-courier-calls" {if empty($courier_calls)}style="display:none"{/if}>
                            <b>{l s='Scheduled courier arrivals:' mod='omnivaltshipping'}</b><br>
                            <table id="omnivalt-courier-calls-list" class="table" style="width:auto;">
                                {if !empty($courier_calls)}
                                    {foreach $courier_calls as $courier_call}
                                        <tr>
                                            <td><small>{$courier_call['start_date']}</small></td>
                                            <td>{$courier_call['start_time']}</td>
                                            <td>
                                            {if $courier_call['end_date'] != $courier_call['start_date']}
                                                <small>{$courier_call['end_date']}</small>
                                            {/if}
                                            </td>
                                            <td>{$courier_call['end_time']}</td>
                                            <td><button type="button" class="btn btn-danger btn-xs" data-callid="{$courier_call['id']}" title="{l s='Cancel this courier call' mod='omnivaltshipping'}">&times;</button></td>
                                        </tr>
                                    {/foreach}
                                {/if}
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="submit" id="requestOmnivaltCourier"
                            class="btn btn-default">{l s='Send' mod='omnivaltshipping'}</button>
                        <button type="button" class="btn btn-default"
                            data-dismiss="modal">{l s='Cancel' mod='omnivaltshipping'}</button>
                        <button type="button" id="modalOmnivaltClose" class="btn btn-default"
                            data-dismiss="modal" style="display:none">{l s='Close' mod='omnivaltshipping'}</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
<!--/ Modal Courier call-->