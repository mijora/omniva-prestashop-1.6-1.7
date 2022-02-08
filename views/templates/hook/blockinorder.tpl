<script type="text/javascript">
    var id_order = "{$order_id}";
</script>
<div class="tab-content omnivalt">
    <div class="panel">
        <div class="panel-heading">
            <i class="icon-tags"></i> OMNIVALT
        </div>
        <div class="row">
            <div class="col-lg-12">
                <div class="omnivalt_order_config"
                     style="border:1px solid #eee; border-radius: 4px; padding:4px; margin-bottom:8px;">
                    <form action="{$moduleurl}" method="post" id="omnivaltOrderSubmitForm">
                        <div class="col-md-6">
                            <div class="field-row">
                                <span>
                                    {l s="Packets" mod='omnivaltshipping'}:
                                </span>
                                <span>
                                    <input type="text" name="packs" value="1"/>
                                </span>
                            </div>
                            <div class="field-row">
                                <span>
                                    {l s="Weight" mod='omnivaltshipping'}:
                                </span>
                                <span>
                                    <input type="text" name="weight" value="{$total_weight}"/>
                                </span>
                            </div>
                            <div class="field-row row">
                                <div class="col-sm-6">
                                    <span>{l s="C.O.D." mod='omnivaltshipping'}: </span>
                                    <span>
                              <select name="is_cod">
                                 <option value="0">{l s='No' mod='omnivaltshipping'}</option>
                                 <option value="1" {if $is_cod} selected="selected" {/if}>{l s='Yes' mod='omnivaltshipping'}</option>
                              </select>
                           </span>
                                </div>
                                <div class="col-sm-6">{l s="C.O.D. amount" mod='omnivaltshipping'}:
                                    <input type="text" name="cod_amount" value="{$total_paid_tax_incl}"/>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="field-row omnivalt-carrier">{l s='Carrier' mod='omnivaltshipping'}:
                                <select name="carrier" class="chosen">
                                    {$carriers}
                                </select>
                            </div>
                            <div class="field-row omnivalt-terminal">{l s='Parcel terminal' mod='omnivaltshipping'}:
                                <select name="parcel_terminal" class="chosen">
                                    {$parcel_terminals}
                                </select>
                            </div>
                        </div>
                        <div class="clearfix"></div>
                        <div class="response">
                            {if $error != ''}
                                <div class="alert alert-danger">{$error}</div>
                            {/if}
                        </div>
                        <div class="clearfix"></div>
                        <button type="button" name="omnivalt_save" style="float:left; margin:5px;"
                                id="omnivaltOrderSubmitBtn" class="btn btn-default">
                            <i class="icon-save"></i> {l s="Save"}
                        </button>
                    </form>
                    <form method="POST" action="{$printlabelsurl}" id="omnivaltOrderPrintLabelsForm" target="_blank"
                          style="display:inlne-block; margin:5px;">
                        <button type="submit" name="omnivalt_printlabel" id="omnivaltOrderPrintLabels"
                                class="btn btn-default">
                            <i class="icon-tag"></i> {l s="Generate label" mod='omnivaltshipping'}
                        </button>
                    </form>
                    {if $label_url != ''}
                        <a href="{$label_url}" target="_blank" id="omnivalt_print_btn"
                           style="display:inlne-block; margin:5px;" class="btn btn-default" mod='omnivaltshipping'>
                            <i class="icon-print"></i> {l s="Print label" mod='omnivaltshipping'}
                        </a>
                    {/if}
                </div>
            </div>
        </div>
    </div>
</div>