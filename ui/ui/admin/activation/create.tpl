{include file="sections/header.tpl"}
{* WP5A: minimal staff activation form. The server derives the action, period and prices; nothing here is authoritative. *}
<div class="row">
    <div class="col-md-8">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Activation')}</div>
            <div class="panel-body">
                {if isset($nuxr_error)}<div class="alert alert-danger">{$nuxr_error|escape}</div>{/if}
                <form class="form-horizontal" method="get" action="{Text::url('activation/create')}">
                    <input type="hidden" name="_route" value="activation/create">
                    <div class="form-group">
                        <label class="col-md-3 control-label">{Lang::T('Customer ID')}</label>
                        <div class="col-md-6"><input type="number" min="1" class="form-control" name="customer" value="{$nuxr_customer_id}"></div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label">{Lang::T('Plan')}</label>
                        <div class="col-md-6">
                            <select class="form-control" name="plan">
                                {foreach $nuxr_plans as $p}
                                    <option value="{$p['id']}" {if $p['id'] eq $nuxr_plan_id}selected{/if}>{$p['name_plan']|escape} ({$p['type']|escape})</option>
                                {/foreach}
                            </select>
                            {if !$nuxr_plans}<p class="help-block">{Lang::T('No plan is available for activation here. Enter the customer ID and press Review to list the plans available for that customer.')}</p>{/if}
                        </div>
                    </div>
                    <div class="form-group"><div class="col-md-offset-3 col-md-6"><button class="btn btn-default" type="submit">{Lang::T('Review')}</button></div></div>
                </form>
                {if $nuxr_preview}
                    <hr>
                    <table class="table table-condensed">
                        <tr><th>{Lang::T('Customer')}</th><td>{$nuxr_preview['customer_username']|escape}</td></tr>
                        <tr><th>{Lang::T('Plan')}</th><td>{$nuxr_preview['plan_name']|escape}</td></tr>
                        <tr><th>{Lang::T('Action')}</th><td>{$nuxr_preview['action']|escape}</td></tr>
                        <tr><th>{Lang::T('Starts')}</th><td>{$nuxr_preview['start']|escape}</td></tr>
                        <tr><th>{Lang::T('Expires')}</th><td>{$nuxr_preview['expiry']|escape}</td></tr>
                    </table>
                    <form method="post" action="{Text::url('activation/create-post')}">
                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                        <input type="hidden" name="customer_id" value="{$nuxr_customer_id}">
                        <input type="hidden" name="plan_id" value="{$nuxr_plan_id}">
                        <input type="hidden" name="expected_action" value="{$nuxr_preview['action']|escape}">
                        <input type="hidden" name="nonce" value="{$nuxr_nonce}">
                        <input type="hidden" name="preview" value="{$nuxr_preview['fingerprint']|escape}">
                        <button class="btn btn-primary" type="submit">{Lang::T('Confirm activation')}</button>
                    </form>
                {/if}
            </div>
        </div>
    </div>
</div>
{include file="sections/footer.tpl"}
