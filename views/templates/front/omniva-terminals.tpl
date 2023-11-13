<option value="">{l s='Select parcel terminal' mod='omnivaltshipping'}</option>
{if isset($grouped_options) && $grouped_options}
{foreach $grouped_options as $city => $locs}
    {foreach $locs as $key => $loc}
        <option value="{$key}" {if $key == $selected}selected{/if}  class="omnivaOption">{$loc}</option>
    {/foreach}
{/foreach}
{/if}