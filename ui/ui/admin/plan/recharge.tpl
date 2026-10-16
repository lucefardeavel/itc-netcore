{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Recharge Account')}</div>
            <div class="panel-body">
                <form class="form-horizontal" method="post" role="form" action="{Text::url('')}plan/recharge-confirm">
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Select Account')}</label>
                        <div class="col-md-6">
                            <select {if $cust}{else}id="personSelect" {/if}aria-label="{Lang::T('Select Account')}" class="form-control select2"
                                name="id_customer" style="width: 100%"
                                data-placeholder="{Lang::T('Select a customer')}...">
                                {if $cust}
                                    <option value="{$cust['id']}">{$cust['username']|escape} &bull; {$cust['fullname']|escape} &bull;
                                        {$cust['email']|escape}</option>
                                {/if}
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Type')}</label>
                        <div class="col-md-6">
                            <label><input type="radio" id="Hot" name="type" value="Hotspot">
                                {Lang::T('Hotspot Plans')}</label>
                            <label><input type="radio" id="POE" name="type" value="PPPOE">
                                {Lang::T('PPPOE Plans')}</label>
                            <label><input type="radio" id="VPN" name="type" value="VPN"> {Lang::T('VPN Plans')}</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="server" class="col-md-2 control-label">{Lang::T('Routers')}</label>
                        <div class="col-md-6">
                            <select id="server" data-type="server" name="server" class="form-control select2">
                                <option value=''>{Lang::T('Select Routers')}</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="plan" class="col-md-2 control-label">{Lang::T('Service Plan')}</label>
                        <div class="col-md-6">
                            <select id="plan" name="plan" class="form-control select2">
                                <option value=''>{Lang::T('Select Plans')}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="nxf-using" class="col-md-2 control-label">{Lang::T('Using')}</label>
                        <div class="col-md-6">
                            <select id="nxf-using" name="using" class="form-control">
                                {foreach $usings as $using}
                                    <option value="{trim($using)}">{trim(ucWords($using))}</option>
                                {/foreach}
                                {if $_c['enable_balance'] eq 'yes'}
                                    <option value="balance">{Lang::T('Customer Balance')}</option>
                                {/if}
                                {if in_array($_admin['user_type'],['SuperAdmin','Admin'])}
                                    <option value="zero">{$_c['currency_code']} 0</option>
                                {/if}
                            </select>
                        </div>
                        <p class="help-block col-md-4">{Lang::T('Postpaid Recharge for the first time use')}
                            {$_c['currency_code']} 0</p>
                    </div>
                    {include file="admin/nuxr/actionbar.tpl" nuxr_submit='Recharge' nuxr_icon='fa-refresh' nuxr_ask='Continue the Recharge process' nuxr_ask_q=1 nuxr_cancel='customers/list'}
                </form>
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}