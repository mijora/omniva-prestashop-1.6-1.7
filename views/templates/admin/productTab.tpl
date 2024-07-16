<style>
    .omniva-info label {
        font-weight: normal;
        margin-bottom: 0;
    }
    .omniva-info input {
        margin-left: 10px;
        margin-top: 0;
    }
    div.omniva-info {
        display: flex;
        align-content: center;
    }
</style>

<div class="panel product-tab">
    <h3 class="tab">{l s='Omniva Shipping' mod='omnivaltshipping'}</h3>
    <div class="form-group omniva-info">
        <label for="omnivaltshipping_is_18_plus">
            {l s='Is product for 18+?' mod='omnivaltshipping'}
        </label>
        <input type="checkbox"
               name="omnivaltshipping_is_18_plus"
               id="omnivaltshipping_is_18_plus"
                {if $is18Plus} checked {/if}>
    </div>
    <div class="form-group omniva-info">
        <label for="omnivaltshipping_fragile">
            {l s='Fragile' mod='omnivaltshipping'}
        </label>
        <input type="checkbox"
               name="omnivaltshipping_fragile"
               id="omnivaltshipping_fragile"
                {if $is18Plus} checked {/if}>
    </div>

    <div class="panel-footer">
        <button type="submit" name="submitAddproductAndStay" class="btn btn-default pull-right">
            <i class="process-icon-save"></i>
            {l s='Save' mod='omnivaltshipping'}
        </button>
    </div>
</div>