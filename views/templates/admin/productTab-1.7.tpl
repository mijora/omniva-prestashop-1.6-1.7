<style>
    div.omniva-info {
        margin-bottom: 5px;
    }
    .omniva-info input {
        margin-right: 5px;
        margin-top: 0;
        vertical-align: baseline;
    }
    .omniva-info label {
        font-weight: normal;
        margin-bottom: 0;
        vertical-align: top;
    }
</style>

<div class="panel product-tab">
    <h3 class="tab">{l s='Omniva Shipping' mod='omnivaltshipping'}</h3>
    <h4>{l s='Product additional attributes' mod='omnivaltshipping'}</h4>
    <div class="form-group omniva-info">
        <input type="checkbox"
               name="omnivaltshipping_is_18_plus"
               id="omnivaltshipping_is_18_plus"
                {if $is18Plus} checked {/if}>
        <label for="omnivaltshipping_is_18_plus">
            {l s='For adult only (18+)' mod='omnivaltshipping'}
            <div class="small no-padding">{l s='When delivering a shipment with this product, need request a document' mod='omnivaltshipping'}</div>
        </label>
    </div>
    <div class="form-group omniva-info">
        <input type="checkbox"
               name="omnivaltshipping_is_fragile"
               id="omnivaltshipping_is_fragile"
                {if $isFragile} checked {/if}>
        <label for="omnivaltshipping_is_fragile">
            {l s='Fragile' mod='omnivaltshipping'}
        </label>
    </div>
</div>