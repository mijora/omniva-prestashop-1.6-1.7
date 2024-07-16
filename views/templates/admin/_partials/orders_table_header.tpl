<tr class="nodrag nodrop">
    {if isset($select_all) && $select_all}
        <th width='5%'>
            <span class="title_box"><input type="checkbox" class="select-all"/></span>
        </th>
    {/if}
    <th width='5%'>
        <span class="title_box active">{l s='Id' mod='omnivaltshipping'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Customer' mod='omnivaltshipping'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Tracking' mod='omnivaltshipping'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Update date' mod='omnivaltshipping'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Total' mod='omnivaltshipping'}</span>
    </th>
    <th width='15%'>
        <span class="title_box">{l s='Labels' mod='omnivaltshipping'}</span>
    </th>
</tr>