{*
* 2007-2014 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
*         DISCLAIMER   *
* *************************************** */
/* Do not edit or add to this file if you wish to upgrade Prestashop to newer
* versions in the future.
* ****************************************************
*}
<script>
    var omnivalt_current_country = '{$omniva_current_country}';
    var omnivalt_postcode = '{$omniva_postcode}';
    var omnivalt_show_map = {$omniva_map};
    var omnivalt_autoselect = {$autoselect};
</script>
<script>
    var omnivalt_terminals = {$terminals_list|@json_encode nofilter};
</script>
<script>
    {if $ps_version == '1.6'}
        $(document).ready(function(){
            launch_omniva();
        });
    {/if}
</script>
<div id="omnivalt_parcel_terminal_carrier_details" class="select-omnivalt theme-{$select_block_theme}" style="display: none; margin-top: 10px;">
    <select class="" name="omnivalt_parcel_terminal" style = "width:100%;">{$parcel_terminals nofilter}</select>

    <style>
        {literal}
            #omnivalt_parcel_terminal_carrier_details{ margin-bottom: 5px }
        {/literal}
    </style>
{if $omniva_map != false } 
  <button type="button" id="show-omniva-map" class="btn btn-basic btn-sm omniva-btn" style = "display: none;">{l s='Show parcel terminals map' mod='omnivaltshipping'} <img src = "{$module_url}views/img/{$marker_img}" title = "{l s='Show parcel terminals map' mod='omnivaltshipping'}"/></button>
{/if}
</div>