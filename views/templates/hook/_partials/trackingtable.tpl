{foreach $tracking_info as $number => $info}
    <div class="omnivalt-tracking-info-block">
        <p><a href="{$tracking_url}{$number}" target="_blank">{$number}</a></p>
        <div class="table_block table-responsive">
            <table class="table table-striped table-bordered hidden-sm-down">
                <thead class="thead-default">
                    <tr>
                        <th class="last_item">{l s='Event ID' mod='omnivaltshipping'}</th>
                        <th class="last_item">{l s='Event' mod='omnivaltshipping'}</th>
                        <th class="item">{l s='Date' mod='omnivaltshipping'}</th>
                    </tr>
                </thead>
                <tbody>
                {foreach $info as $event}
                    <tr>
                        <td>{$event['eventId']}</td>
                        <td>{$event['eventName']}</td>
                        {assign var="cleanDate" value=$event['eventDate']|regex_replace:"/\.\d+$/":""}
                        <td>{$cleanDate|date_format:"%Y-%m-%d %H:%M:%S"}</td>
                    </tr>
                {/foreach}
                </tbody>
            </table>
        </div>
    </div>
{/foreach}