{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6C (owner amendment A): actions follow the central route permission (RouteMap via NuxrUi::allowed), never role names *}
{assign var=nuxr_can value=['recharge' => NuxrUi::allowed('plan/recharge'), 'edit' => NuxrUi::allowed('plan/edit'), 'delete' => NuxrUi::allowed('plan/delete', 'POST'),
    'extend' => NuxrUi::allowed('plan/extend', 'POST'), 'plans' => NuxrUi::allowed('services/edit'), 'routers' => NuxrUi::allowed('routers/list')]}

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                {if NuxrUi::allowed('plan/sync', 'POST')}
                    <div class="btn-group pull-right">
                        <a data-method="post" class="btn btn-primary btn-xs" title="save" href="{Text::url('')}plan/sync"
                            onclick="return ask(this, '{Lang::T("This will sync dan send Customer active package to Mikrotik")}?')"><span
                                class="glyphicon glyphicon-refresh" aria-hidden="true"></span> {Lang::T("Sync")}</a>
                    </div>
                    {* <div class="btn-group pull-right">
                    <a class="btn btn-info btn-xs" title="save" href="{Text::url('plan/csv',$append_url)}"
                        onclick="return ask(this, '{Lang::T("This will export to CSV?")}')"><span class="glyphicon glyphicon-download"
                            aria-hidden="true"></span> CSV</a>
                </div> *}
                {/if}
                &nbsp;
                {Lang::T('Active Customers')}
            </div>
            {* WP6C: search stays visible; the location / plan / status filters use the WP6A filter disclosure (open when a
               filter is active); same form, same field names, same POST *}
            <form id="site-search" method="post" action="{Text::url('')}plan/list/">
                <div class="panel-body nuxr-legacy-filters">
                    <div class="nuxr-filters">
                        <label class="sr-only" for="nxf-ac-search">{Lang::T("Search")}</label>
                        <input type="search" id="nxf-ac-search" name="search" class="form-control nuxr-input" autocomplete="off"
                            placeholder="{Lang::T("Search")}..." value="{$search|escape}">
                        <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i>{Lang::T("Search")}</button>
                        {if $search || $router || $plan || ($status && $status neq '-')}<a class="nuxr-btn" href="{Text::url('plan/list')}"><i class="fa fa-times" aria-hidden="true"></i>{Lang::T("Clear")}</a>{/if}
                        {if $nuxr_can.recharge}<a href="{Text::url('plan/recharge')}" class="nuxr-btn"><i class="fa fa-refresh" aria-hidden="true"></i>{Lang::T("Recharge Account")}</a>{/if}
                    </div>
                    <details class="nuxr-filter-more" {if $router || $plan || ($status && $status neq '-')}open{/if}>
                        <summary class="nuxr-btn"><i class="fa fa-sliders" aria-hidden="true"></i>{Lang::T('Filters')}</summary>
                        <div class="nuxr-filters" style="margin:10px 0 0">
                            <label class="sr-only" for="router">{Lang::T("Location")}</label>
                            <select class="form-control" id="router" name="router">
                                <option value="">{Lang::T("Location")}</option>
                                {foreach $routers as $r}
                                    <option value="{$r|escape}" {if $router eq $r }selected{/if}>{$r|escape}</option>
                                {/foreach}
                            </select>
                            <label class="sr-only" for="plan">{Lang::T("Plan Name")}</label>
                            <select class="form-control" id="plan" name="plan">
                                <option value="">{Lang::T("Plan Name")}</option>
                                {foreach $plans as $p}
                                    <option value="{$p['id']|escape}" {if $plan eq $p['id'] }selected{/if}>{$p['name_plan']|escape}</option>
                                {/foreach}
                            </select>
                            <label class="sr-only" for="status">{Lang::T("Status")}</label>
                            <select class="form-control" id="status" name="status">
                                <option value="-">{Lang::T("Status")}</option>
                                <option value="on" {if $status eq 'on' }selected{/if}>{Lang::T("Active")}</option>
                                <option value="off" {if $status eq 'off' }selected{/if}>{Lang::T("Expired")}</option>
                            </select>
                            <button class="nuxr-btn nuxr-btn-primary" type="submit">{Lang::T("Apply")}</button>
                        </div>
                    </details>
                </div>
            </form>
            {* WP6C AdaptiveDataView: the dense table where it fits; cards (primary information first, row menu) when the
               component is narrow - the same rows, one query *}
            <div class="nuxr-adaptive is-lg">
            <div class="table-responsive nuxr-table-wrap">
                <div style="margin-left: 5px; margin-right: 5px;">&nbsp;
                    <table id="datatable" class="table table-bordered table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>{Lang::T("Username")}</th>
                                <th>{Lang::T("Plan Name")}</th>
                                <th>{Lang::T("Type")}</th>
                                <th>{Lang::T("Created On")}</th>
                                <th>{Lang::T("Expires On")}</th>
                                <th>{Lang::T("Method")}</th>
                                <th>{if $nuxr_can.routers}<a href="{Text::url('')}routers/list">{Lang::T("Location")}</a>{else}{Lang::T("Location")}{/if}</th>
                                <th>{Lang::T("Manage")}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $d as $ds}
                                <tr {if $ds['status']=='off' }class="danger" {/if}>
                                    <td>
                                        {if $ds['customer_id'] == '0'}
                                            <a
                                                href="{Text::url('plan/voucher/&search=')}{$ds['username']}">{$ds['username']}</a>
                                        {else}
                                            <a href="{Text::url('customers/viewu/')}{$ds['username']}">{$ds['username']}</a>
                                        {/if}
                                    </td>
                                    <td>
                                        {if $ds['type'] == 'Hotspot'}
                                            {if $nuxr_can.plans}<a href="{Text::url('')}services/edit/{$ds['plan_id']}">{$ds['namebp']}</a>{else}{$ds['namebp']}{/if}
                                            <span
                                                api-get-text="{Text::url('')}autoload/customer_is_active/{$ds['username']}/{$ds['plan_id']}"></span>
                                        {elseif $ds['type'] == 'PPPOE'}
                                            {if $nuxr_can.plans}<a href="{Text::url('')}services/pppoe-edit/{$ds['plan_id']}">{$ds['namebp']}</a>{else}{$ds['namebp']}{/if}
                                            <span
                                                api-get-text="{Text::url('')}autoload/customer_is_active/{$ds['username']}/{$ds['plan_id']}"></span>
                                        {elseif $ds['type'] == 'VPN'}
                                            {if $nuxr_can.plans}<a href="{Text::url('')}services/vpn-edit/{$ds['plan_id']}">{$ds['namebp']}</a>{else}{$ds['namebp']}{/if}
                                        {/if}

                                    </td>
                                    <td>{$ds['type']}</td>
                                    <td>{Lang::dateAndTimeFormat($ds['recharged_on'],$ds['recharged_time'])}</td>
                                    <td>{Lang::dateAndTimeFormat($ds['expiration'],$ds['time'])}</td>
                                    <td>{$ds['method']}</td>
                                    <td>{$ds['routers']}</td>
                                    <td>
                                        {if $nuxr_can.edit}<a href="{Text::url('')}plan/edit/{$ds['id']}" class="btn btn-warning btn-xs"
                                            style="color: black;">{Lang::T("Edit")}</a>{/if}
                                        {if $nuxr_can.delete}
                                            <a data-method="post" href="{Text::url('')}plan/delete/{$ds['id']}" id="{$ds['id']}"
                                                onclick="return ask(this, '{Lang::T("Delete")}?')"
                                                class="btn btn-danger btn-xs" aria-label="{Lang::T('Delete')}"><i class="glyphicon glyphicon-trash" aria-hidden="true"></i></a>
                                        {/if}
                                        {if $ds['status']=='off' && $_c['extend_expired'] && $nuxr_can.extend}
                                            <a href="javascript:extend('{$ds['id']}')"
                                                class="btn btn-info btn-xs">{Lang::T("Extend")}</a>
                                        {/if}
                                    </td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
            <ul class="nuxr-mlist">
                {foreach $d as $ds}
                    <li><div class="nuxr-mcard is-wrap">
                        {call name=nuxr_initial who=$ds['username']}
                        <a class="nuxr-mcard-main" href="{if $ds['customer_id'] == '0'}{Text::url('plan/voucher/&search=')}{$ds['username']|escape:'url'}{else}{Text::url('customers/viewu/')}{$ds['username']|escape:'url'}{/if}">
                            <span class="nuxr-mcard-title"><bdi dir="ltr">{$ds['username']|escape}</bdi></span>
                            <span class="nuxr-mcard-sub">{$ds['namebp']|escape} · {$ds['type']|escape}</span>
                            <span class="nuxr-mcard-sub">{Lang::T("Expires On")} <bdi dir="ltr">{Lang::dateAndTimeFormat($ds['expiration'],$ds['time'])|escape}</bdi>{if $ds['routers']} · <bdi dir="ltr">{$ds['routers']|escape}</bdi>{/if}</span>
                            <span class="nuxr-mcard-sub">{if $ds['status'] eq 'on'}{call name=nuxr_badge tone='success' label=Lang::T('Active') icon='fa-check-circle'}{else}{call name=nuxr_badge tone='danger' label=Lang::T('Expired') icon='fa-clock-o'}{/if}</span>
                        </a>
                        {if $nuxr_can.edit || $nuxr_can.delete || ($ds['status']=='off' && $_c['extend_expired'] && $nuxr_can.extend)}
                            <details class="nuxr-rowmenu">
                                <summary class="nuxr-icon-btn" aria-label="{Lang::T('Actions')}: {$ds['username']|escape}"><i class="fa fa-ellipsis-v" aria-hidden="true"></i></summary>
                                <div class="nuxr-rowmenu-panel">
                                    {if $nuxr_can.edit}<a href="{Text::url('plan/edit/')}{$ds['id']|escape:'url'}"><i class="fa fa-pencil" aria-hidden="true"></i>{Lang::T("Edit")}</a>{/if}
                                    {if $ds['status']=='off' && $_c['extend_expired'] && $nuxr_can.extend}<a href="javascript:extend('{$ds['id']|escape:'javascript'}')"><i class="fa fa-calendar-plus-o" aria-hidden="true"></i>{Lang::T("Extend")}</a>{/if}
                                    {if $nuxr_can.delete}<a data-method="post" href="{Text::url('plan/delete/')}{$ds['id']|escape:'url'}" class="is-danger"
                                        onclick="return ask(this, '{Lang::T("Delete")|escape:'javascript'}?')"><i class="fa fa-trash" aria-hidden="true"></i>{Lang::T("Delete")}</a>{/if}
                                </div>
                            </details>
                        {/if}
                    </div></li>
                {foreachelse}
                    <li><div class="nuxr-empty"><i class="fa fa-users" aria-hidden="true"></i><strong>{Lang::T('No records.')}</strong></div></li>
                {/foreach}
            </ul>
            </div>
            {include file="pagination.tpl"}
        </div>
    </div>
</div>

<script>
    function extend(idP) {
        var res = prompt("Extend for many days?", "3");
        if (res) {
            if (confirm("Extend for " + res + " days?")) {
                nuxrPost("{Text::url('plan/extend/')}" + idP + "/" + res + "{Text::isQA('? or &')}stoken={App::getToken()}");
            }
        }
    }
</script>

{include file="sections/footer.tpl"}
