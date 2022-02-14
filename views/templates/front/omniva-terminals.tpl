<option value="">{l s='Select parcel terminal' mod='omnivaltshipping'}</option>
{foreach $grouped_options as $city => $locs}
    {foreach $locs as $key => $loc}
        <option value="{$key}" {if $key == $selected}selected{/if}  class="omnivaOption">{$loc}</option>
    {/foreach}
{/foreach}