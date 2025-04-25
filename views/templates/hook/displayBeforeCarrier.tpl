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
    {if $ps_version == '1.6'}
        $(document).ready(function(){
            launch_omniva();
        });
    {/if}
</script>