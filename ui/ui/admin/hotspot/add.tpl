{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Add Service Package')}</div>
            <div class="panel-body">
                <form class="form-horizontal" method="post" role="form" action="{Text::url('services/add-post')}">
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Status')}
                            <a tabindex="0" class="btn btn-link btn-xs" role="button" data-toggle="popover" aria-label="{Lang::T("Customer cannot buy disabled Package, but admin can recharge it, use it if you want only admin recharge it")}"
                                data-trigger="focus" data-container="body"
                                data-content="{Lang::T("Customer cannot buy disabled Package, but admin can recharge it, use it if you want only admin recharge it")}">?</a>
                        </label>
                        <div class="col-md-10">
                            <label class="radio-inline"><input type="radio" name="enabled" value="1" checked> {Lang::T('Active')}</label>
                            <label class="radio-inline"><input type="radio" name="enabled" value="0"> {Lang::T('Not Active')}</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Type')}
                            <a tabindex="0" class="btn btn-link btn-xs" role="button" data-toggle="popover" aria-label="{Lang::T("Postpaid will have fix expired date")}"
                                data-trigger="focus" data-container="body"
                                data-content="{Lang::T("Postpaid will have fix expired date")}">?</a>
                        </label>
                        <div class="col-md-10">
                            <label class="radio-inline"><input type="radio" name="prepaid" onclick="prePaid()" value="yes" checked> {Lang::T('Prepaid')}</label>
                            <label class="radio-inline"><input type="radio" name="prepaid" onclick="postPaid()" value="no"> {Lang::T('Postpaid')}</label>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Package Type')}
                            <a tabindex="0" class="btn btn-link btn-xs" role="button" data-toggle="popover" aria-label="{Lang::T("Personal Plan will only show to personal Customer, Business package will only show to Business Customer")}"
                                data-trigger="focus" data-container="body"
                                data-content="{Lang::T("Personal Plan will only show to personal Customer, Business package will only show to Business Customer")}">?</a>
                        </label>
                        <div class="col-md-10">
                            <label class="radio-inline"><input type="radio" name="plan_type" value="Personal" checked> {Lang::T('Personal')}</label>
                            <label class="radio-inline"><input type="radio" name="plan_type" value="Business"> {Lang::T('Business')}</label>
                        </div>
                    </div>
                    {if $_c['radius_enable']}
                        <div class="form-group">
                            <label class="col-md-2 control-label">Radius
                                <a tabindex="0" class="btn btn-link btn-xs" role="button" data-toggle="popover" aria-label="{Lang::T("If you enable Radius, choose device to radius, except if you have custom device.")}"
                                    data-trigger="focus" data-container="body"
                                    data-content="{Lang::T("If you enable Radius, choose device to radius, except if you have custom device.")}">?</a>
                            </label>
                            <div class="col-md-6">
                                <label class="radio-inline">
                                    <input type="checkbox" name="radius" onclick="isRadius(this)" value="1">
                                    {Lang::T('Radius Package')}
                                </label>
                            </div>
                            <p class="help-block col-md-4">{Lang::T('Cannot be change after saved')}</p>
                        </div>
                    {/if}
                    <div class="form-group">
                        <label for="device" class="col-md-2 control-label">{Lang::T('Device')}
                            <a tabindex="0" class="btn btn-link btn-xs" role="button" data-toggle="popover" aria-label="{Lang::T("This Device are the logic how PHPNuxBill Communicate with Mikrotik or other Devices")}"
                                data-trigger="focus" data-container="body"
                                data-content="{Lang::T("This Device are the logic how PHPNuxBill Communicate with Mikrotik or other Devices")}">?</a>
                        </label>
                        <div class="col-md-6">
                            <select class="form-control" id="device" name="device">
                                {foreach $devices as $dev}
                                    <option value="{$dev}">{$dev}</option>
                                {/foreach}
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="name" class="col-md-2 control-label">{Lang::T('Package Name')}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="name" name="name" maxlength="40">
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Package Type')}</label>
                        <div class="col-md-10">
                            <label class="radio-inline"><input type="radio" id="Unlimited" name="typebp" value="Unlimited" checked> {Lang::T('Unlimited')}</label>
                            <label class="radio-inline"><input type="radio" id="Limited" name="typebp" value="Limited"> {Lang::T('Limited')}</label>
                        </div>
                    </div>
                    <div style="display:none;" id="Type">
                        <div class="form-group">
                            <label class="col-md-2 control-label">{Lang::T('Limit Type')}</label>
                            <div class="col-md-10">
                                <label class="radio-inline"><input type="radio" id="Time_Limit" name="limit_type" value="Time_Limit" checked> {Lang::T('Time Limit')}</label>
                                <label class="radio-inline"><input type="radio" id="Data_Limit" name="limit_type" value="Data_Limit"> {Lang::T('Data Limit')}</label>
                                <label class="radio-inline"><input type="radio" id="Both_Limit" name="limit_type" value="Both_Limit"> {Lang::T('Both Limit')}</label>
                            </div>
                        </div>
                    </div>
                    <div style="display:none;" id="TimeLimit">
                        <div class="form-group">
                            <label for="time_limit" class="col-md-2 control-label">{Lang::T('Time Limit')}</label>
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="time_limit" name="time_limit" value="0">
                            </div>
                            <div class="col-md-2">
                                <select class="form-control" id="time_unit" name="time_unit" aria-label="{Lang::T('Time Limit')} ({Lang::T('Unit')})">
                                    <option value="Hrs">{Lang::T('Hrs')}</option>
                                    <option value="Mins">{Lang::T('Mins')}</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div style="display:none;" id="DataLimit">
                        <div class="form-group">
                            <label for="data_limit" class="col-md-2 control-label">{Lang::T('Data Limit')}</label>
                            <div class="col-md-4">
                                <input type="text" class="form-control" id="data_limit" name="data_limit" value="0">
                            </div>
                            <div class="col-md-2">
                                <select class="form-control" id="data_unit" name="data_unit" aria-label="{Lang::T('Data Limit')} ({Lang::T('Unit')})">
                                    <option value="MB">MBs</option>
                                    <option value="GB">GBs</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="id_bw" class="col-md-2 control-label"><a
                                href="{Text::url('bandwidth/add')}">{Lang::T('Bandwidth Name')}</a></label>
                        <div class="col-md-6">
                            <select id="id_bw" name="id_bw" class="form-control select2">
                                <option value="">{Lang::T('Select Bandwidth')}...</option>
                                {foreach $d as $ds}
                                    <option value="{$ds['id']}">{$ds['name_bw']|escape}</option>
                                {/foreach}
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="nxf-price" class="col-md-2 control-label">{Lang::T('Package Price')}</label>
                        <div class="col-md-6">
                            <div class="input-group">
                                <span class="input-group-addon">{$_c['currency_code']}</span>
                                <input id="nxf-price" type="number" class="form-control" name="price" required>
                            </div>
                        </div>
                        {if $_c['enable_tax'] == 'yes'}
                            {if $_c['tax_rate'] == 'custom'}
                                <p class="help-block col-md-4">{number_format($_c['custom_tax_rate'], 2)} % {Lang::T('Tax Rates
                            will be added')}</p>
                            {else}
                                <p class="help-block col-md-4">{number_format($_c['tax_rate'] * 100, 2)} % {Lang::T('Tax Rates
                            will be added')}</p>
                            {/if}
                        {/if}
                    </div>
                    <div class="form-group">
                        <label for="sharedusers" class="col-md-2 control-label">{Lang::T('Shared Users')}
                            <a tabindex="0" class="btn btn-link btn-xs" role="button" data-toggle="popover" aria-label="{Lang::T("How many devices can online in one Customer account")}"
                                data-trigger="focus" data-container="body"
                                data-content="{Lang::T("How many devices can online in one Customer account")}">?</a>
                        </label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="sharedusers" name="sharedusers" value="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="validity" class="col-md-2 control-label">{Lang::T('Package Validity')}</label>
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="validity" name="validity">
                        </div>
                        <div class="col-md-2">
                            <select class="form-control" id="validity_unit" name="validity_unit" aria-label="{Lang::T('Validity')} ({Lang::T('Unit')})">
                            </select>
                        </div>
                        <p class="help-block col-md-4">{Lang::T('1 Period = 1 Month, Expires the 20th of each month')}
                        </p>
                    </div>
                    <div class="form-group hidden" id="expired_date">
                        <label for="nxf-expired_date" class="col-md-2 control-label">{Lang::T('Expired Date')}
                            <a tabindex="0" class="btn btn-link btn-xs" role="button" data-toggle="popover" aria-label="{Lang::T("Expired will be this date every month")}"
                                data-trigger="focus" data-container="body"
                                data-content="{Lang::T("Expired will be this date every month")}">?</a>
                        </label>
                        <div class="col-md-6">
                            <input id="nxf-expired_date" type="number" class="form-control" name="expired_date" maxlength="2" value="20"
                                min="1" max="28" step="1">
                        </div>
                    </div>
                    <span id="routerChoose" class="">
                        <div class="form-group">
                            <label for="routers" class="col-md-2 control-label"><a
                                    href="{Text::url('routers/add')}">{Lang::T('Router Name')}</a></label>
                            <div class="col-md-6">
                                <select id="routers" name="routers" required class="form-control select2">
                                    <option value=''>{Lang::T('Select Routers')}</option>
                                    {foreach $r as $rs}
                                        <option value="{$rs['name']|escape}">{$rs['name']|escape}</option>
                                    {/foreach}
                                </select>
                                <p class="help-block">{Lang::T('Cannot be change after saved')}</p>
                            </div>
                        </div>
                    </span>
        {* WP6C: the shared action row - a direct child of the form, sticky above the bottom navigation on phones *}
        <div class="nuxr-step-nav nuxr-actionbar-form">
            <a class="nuxr-btn" href="{Text::url('services/hotspot')}">{Lang::T('Cancel')}</a>
            <button class="nuxr-btn nuxr-btn-primary" data-nuxr-ask="{Lang::T("Continue the Hotspot Package creation process?")|escape}"
                                type="submit"><i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Save Changes')}</button>
        </div>
                </form>
            </div>
        </div>
    </div>
</div>
<script>
    var preOpt = `<option value="Mins">{Lang::T('Mins')}</option>
    <option value="Hrs">{Lang::T('Hrs')}</option>
    <option value="Days">{Lang::T('Days')}</option>
    <option value="Months">{Lang::T('Months')}</option>`;
    var postOpt = `<option value="Period">{Lang::T('Period')}</option>`;
    function prePaid() {
        $("#validity_unit").html(preOpt);
        $('#expired_date').addClass('hidden');
    }

    function postPaid() {
        $("#validity_unit").html(postOpt);
        $("#expired_date").removeClass('hidden');
    }
    document.addEventListener("DOMContentLoaded", function(event) {
        prePaid()
    })
</script>
{if $_c['radius_enable']}
    {literal}
        <script>
            function isRadius(cek) {
                if (cek.checked) {
                    $("#routerChoose").addClass('hidden');
                    document.getElementById("routers").required = false;
                } else {
                    document.getElementById("routers").required = true;
                    $("#routerChoose").removeClass('hidden');
                }
            }
        </script>
    {/literal}
{/if}

{include file="sections/footer.tpl"}