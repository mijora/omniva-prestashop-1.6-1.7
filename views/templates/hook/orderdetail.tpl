{if $show}
    <div class="box omnivalt-order-detail">
        <div class="header">
            <img src="{$logo}" alt="Omniva Logo" class="omnivalt-logo" />
        </div>
        {if isset($terminal_address) && $terminal_address}
            <p>{l s="Your order will be delivered to the parcel terminal" mod='omnivaltshipping'}: <b>{$terminal_address}</b></p>
        {/if}
        {if $tracking_info}
            <div>
                <h3 class="page-subheading">{l s='Tracking information' mod='omnivaltshipping'}</h3>
                {include file="./_partials/trackingtable.tpl"}
            </div>
        {/if}
    </div>
{/if}