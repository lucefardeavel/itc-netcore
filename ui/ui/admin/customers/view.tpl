{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6A Subscriber 360 (customers/view/{id}[/overview|activations|invoices|order]). Only available truth is shown; actions
   are rendered from the accepted capabilities and re-authorized server-side. No Disconnect / Suspend (OD-E: no accepted
   backend). Dangerous actions live apart, behind confirmation dialogs. *}
{assign var=nuxr_owned value=$d['manager_id'] neq null && $d['manager_id'] neq ''}
<div class="{if $nuxr_can_activate}nuxr-has-actionbar{/if}">

{if $nuxr_created}
    <div class="nuxr-alert is-success" role="status"><i class="fa fa-check-circle" aria-hidden="true"></i>
        <div><strong>{Lang::T('Subscriber created.')}</strong>
            {if $nuxr_can_activate}{Lang::T('Activate the service now, or later from this page.')}{/if}</div>
    </div>
{/if}
{if $nuxr_flags.in_flight}
    <div class="nuxr-alert is-info" role="status"><i class="fa fa-spinner" aria-hidden="true"></i>
        <div><strong>{Lang::T('An activation is in progress.')}</strong>
            {call name=nuxr_state state=$nuxr_flags.in_flight.status}
            <a href="{Text::url('activation/view/', $nuxr_flags.in_flight.reference)}"><bdi dir="ltr">{$nuxr_flags.in_flight.reference|escape}</bdi></a>
            {Lang::T('Do not start another one; open its status instead.')}</div>
    </div>
{/if}
{if $nuxr_flags.reprovision_unconfirmed}
    <div class="nuxr-alert is-warning" role="status"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
        <div><strong>{Lang::T('Network change not confirmed')}</strong>
            {Lang::T('The last change could not be confirmed on the network and was not retried. An administrator must synchronize this subscriber.')}</div>
    </div>
{/if}

<div class="nuxr-grid nuxr-grid-360">
    {* ---------- summary column ---------- *}
    <div>
        <section class="nuxr-card" aria-labelledby="nuxr-identity">
            <div class="nuxr-card-body" style="display:flex;gap:14px;align-items:center">
                {call name=nuxr_initial who=$d['fullname']}
                <div style="min-width:0">
                    <h2 id="nuxr-identity" style="margin:0;font-size:18px;font-weight:700;overflow-wrap:anywhere">{$d['fullname']|escape}</h2>
                    <div><bdi dir="ltr" class="nuxr-mono">{$d['username']|escape}</bdi> <span class="nuxr-muted">· #<bdi dir="ltr">{$d['id']}</bdi></span></div>
                    <div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap">{call name=nuxr_customer_status status=$d['status']}
                        <span class="nuxr-badge is-neutral"><i class="fa fa-wifi" aria-hidden="true"></i>{Lang::T($d['service_type'])}</span></div>
                </div>
            </div>
            <div class="nuxr-card-body" style="border-top:1px solid var(--nx-border)">
                <dl class="nuxr-kv">
                    {if $d['phonenumber']}<div><dt>{Lang::T('Phone')}</dt><dd><a href="tel:{$d['phonenumber']|escape}"><bdi dir="ltr">{$d['phonenumber']|escape}</bdi></a></dd></div>{/if}
                    {if $d['email']}<div><dt>{Lang::T('Email')}</dt><dd><bdi dir="ltr">{$d['email']|escape}</bdi></dd></div>{/if}
                    {if $d['pppoe_username']}<div><dt>{Lang::T('PPPoE username')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$d['pppoe_username']|escape}</bdi></dd></div>{/if}
                    {if $d['pppoe_ip']}<div><dt>{Lang::T('Static IP')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$d['pppoe_ip']|escape}</bdi></dd></div>{/if}
                    {if $d['address']}<div><dt>{Lang::T('Address')}</dt><dd>{$d['address']|escape}</dd></div>{/if}
                    <div><dt>{Lang::T('Account type')}</dt><dd>{Lang::T($d['account_type'])}</dd></div>
                    <div><dt>{Lang::T('Created')}</dt><dd><bdi dir="ltr">{$d['created_at']|escape}</bdi></dd></div>
                    {if $nuxr_creator|default:''}<div><dt>{Lang::T('Created by')}</dt><dd><bdi dir="ltr">{$nuxr_creator|escape}</bdi></dd></div>{/if}
                    {if $d['last_login']}<div><dt>{Lang::T('Last Login')}</dt><dd><bdi dir="ltr">{$d['last_login']|escape}</bdi></dd></div>{/if}
                    <div><dt>{Lang::T('Auto Renewal')}</dt><dd>{if $d['auto_renewal']}{Lang::T('Yes')}{else}{Lang::T('No')}{/if}</dd></div>
                    {if $_c['enable_balance'] eq 'yes'}<div><dt>{Lang::T('Customer balance (legacy deposit)')}</dt><dd><bdi dir="ltr">{Lang::moneyFormat($d['balance'])}</bdi></dd></div>{/if}
                    {if $d['coordinates']}<div><dt>{Lang::T('Location')}</dt><dd><a href="https://www.google.com/maps/dir//{$d['coordinates']|escape:'url'}/" target="_blank" rel="noopener noreferrer">{Lang::T('Get Directions')}</a></dd></div>{/if}
                </dl>
                {if in_array($_admin['user_type'], ['SuperAdmin', 'Admin'])}
                    <details style="margin-top:8px">
                        <summary class="nuxr-btn">{Lang::T('Show credentials')}</summary>
                        <dl class="nuxr-kv" style="margin-top:8px">
                            <div><dt>{Lang::T('Password')}</dt><dd><input type="password" value="{$d['password']|escape}" readonly class="form-control nuxr-input ltr" dir="ltr"
                                onclick="this.type = this.type === 'password' ? 'text' : 'password'" aria-label="{Lang::T('Password')}"></dd></div>
                            {if $d['pppoe_password']}<div><dt>{Lang::T('PPPoE password')}</dt><dd><input type="password" value="{$d['pppoe_password']|escape}" readonly class="form-control nuxr-input ltr" dir="ltr"
                                onclick="this.type = this.type === 'password' ? 'text' : 'password'" aria-label="{Lang::T('PPPoE password')}"></dd></div>{/if}
                        </dl>
                    </details>
                {/if}
            </div>
        </section>

        {* ownership: owner / Sales; mutations only for the capabilities that allow them (re-authorized and audited) *}
        <section class="nuxr-card" aria-labelledby="nuxr-ownership">
            <div class="nuxr-card-head"><h3 id="nuxr-ownership">{Lang::T('Ownership')}</h3></div>
            <div class="nuxr-card-body">
                <dl class="nuxr-kv">
                    <div><dt>{Lang::T('Owner (reseller)')}</dt><dd>{if $nuxr_owner}<bdi dir="ltr">{$nuxr_owner['username']|escape}</bdi>{else}{Lang::T('Unassigned (legacy)')}{/if}</dd></div>
                    <div><dt>{Lang::T('Sales')}</dt><dd>{if $nuxr_sales}<bdi dir="ltr">{$nuxr_sales['username']|escape}</bdi>{else}-{/if}</dd></div>
                </dl>
                {if $_can['customer.assign_sales'] && $nuxr_owned && $nuxr_sales_options|default:[]}
                    <form method="post" action="{Text::url('reseller/customer-assign-sales')}" class="nuxr-filters nuxr-form" style="margin:12px 0 0">
                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                        <input type="hidden" name="customer_id" value="{$d['id']}">
                        <label class="sr-only" for="nuxr-sales-select">{Lang::T('Sales')}</label>
                        <select id="nuxr-sales-select" name="sales_id" class="form-control nuxr-input" style="flex:1 1 140px">
                            {foreach $nuxr_sales_options as $o}<option value="{$o['id']}" {if $o['id'] eq $d['assigned_sales_id']}selected{/if}>{$o['username']|escape}</option>{/foreach}
                        </select>
                        <button class="nuxr-btn" type="submit">{Lang::T('Assign Sales')}</button>
                    </form>
                    {if $d['assigned_sales_id']}
                        <form method="post" action="{Text::url('reseller/customer-clear-sales')}" style="margin-top:8px">
                            <input type="hidden" name="csrf_token" value="{$csrf_token}">
                            <input type="hidden" name="customer_id" value="{$d['id']}">
                            <button class="nuxr-btn" type="submit">{Lang::T('Clear Sales')}</button>
                        </form>
                    {/if}
                {/if}
                {if $_can['customer.transfer_owner'] && $nuxr_owned && $nuxr_owner_options|default:[]}
                    <button type="button" class="nuxr-btn" style="margin-top:8px" data-nuxr-open="nuxr-transfer-dialog">{Lang::T('Transfer owner')}</button>
                {/if}
                {if $_can['customer.assign_legacy_owner'] && !$nuxr_owned && $nuxr_owner_options|default:[]}
                    <button type="button" class="nuxr-btn" style="margin-top:8px" data-nuxr-open="nuxr-assign-owner-dialog">{Lang::T('Assign owner')}</button>
                {/if}
            </div>
        </section>
    </div>

    {* ---------- main column (first on phone / narrow tablet: service before profile details) ---------- *}
    <div class="nuxr-360-main">
        <section class="nuxr-card" aria-labelledby="nuxr-service">
            <div class="nuxr-card-head">
                <h3 id="nuxr-service">{Lang::T('Service')}</h3>
                {if $nuxr_can_activate}
                    <a class="nuxr-btn nuxr-btn-primary nuxr-hide-phone" href="{Text::url('activation/create')}&customer={$d['id']}"{if $nuxr_flags.in_flight} aria-disabled="true" tabindex="-1" onclick="return false"{/if}>
                        <i class="fa fa-bolt" aria-hidden="true"></i>{if $packages}{Lang::T('Renew / change plan')}{else}{Lang::T('Activate')}{/if}</a>
                {/if}
            </div>
            <div class="nuxr-card-body">
                {if $packages}
                    {foreach $packages as $package}
                        {assign var=pdays value=NuxrUi::daysUntil($package['expiration'])}
                        <div style="{if !$package@last}padding-bottom:12px;margin-bottom:12px;border-bottom:1px solid var(--nx-border){/if}">
                            <div style="display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;align-items:center">
                                <strong style="font-size:16px">{$package['namebp']|escape}</strong>
                                {if $package['status'] neq 'on' || $pdays === null || $pdays < 0}{call name=nuxr_badge tone='danger' label=Lang::T('Expired') icon='fa-clock-o'}
                                {elseif $pdays <= 7}{call name=nuxr_badge tone='warning' label=Lang::T('Expiring') icon='fa-exclamation-circle'}
                                {else}{call name=nuxr_badge tone='success' label=Lang::T('Active')}{/if}
                            </div>
                            <dl class="nuxr-kv" style="margin-top:6px">
                                <div><dt>{Lang::T('Expires')}</dt><dd><bdi dir="ltr">{$package['expiration']|escape} {$package['time']|escape}</bdi>
                                    {if $pdays !== null && $package['status'] eq 'on'}<span class="nuxr-muted"> · {if $pdays >= 0}{$pdays} {Lang::T('days left')}{else}{Lang::T('expired')}{/if}</span>{/if}</dd></div>
                                <div><dt>{Lang::T('Speed')}</dt><dd>{$package['name_bw']|escape}</dd></div>
                                <div><dt>{Lang::T('Type')}</dt><dd>{$package['type']|escape} · {if $package['prepaid'] eq 'yes'}{Lang::T('Prepaid')}{else}{Lang::T('Postpaid')}{/if}</dd></div>
                            </dl>
                            {if $_can['customer.deactivate'] && $package['status'] eq 'on'}
                                <button type="button" class="nuxr-btn nuxr-btn-danger" style="margin-top:8px" data-nuxr-open="nuxr-deactivate-{$package['plan_id']}">{Lang::T('Deactivate plan')}</button>
                                <dialog id="nuxr-deactivate-{$package['plan_id']}" class="nuxr-sheet" aria-labelledby="nuxr-deact-t-{$package['plan_id']}">
                                    <div class="nuxr-sheet-head"><h2 id="nuxr-deact-t-{$package['plan_id']}">{Lang::T('Deactivate plan')}</h2>
                                        <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
                                    <form method="post" action="{Text::url('customers/deactivate/', $d['id'], '/', $package['plan_id'], '&token=', $csrf_token)}">
                                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                                        <div class="nuxr-dialog-body"><div class="nuxr-alert is-danger"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                                            <div>{Lang::T('This ends the plan now and makes it expired. Internet access stops for this plan.')}<br><strong>{$package['namebp']|escape}</strong></div></div></div>
                                        <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close autofocus>{Lang::T('Cancel')}</button>
                                            <button type="submit" class="nuxr-btn nuxr-btn-danger-solid">{Lang::T('Deactivate plan')}</button></div>
                                    </form>
                                </dialog>
                            {/if}
                        </div>
                    {/foreach}
                {else}
                    <div class="nuxr-empty" style="padding:14px"><i class="fa fa-plug" aria-hidden="true"></i><strong>{Lang::T('No service yet.')}</strong>
                        {if $nuxr_can_activate}<span>{Lang::T('Activate a plan to start the service.')}</span>{/if}</div>
                {/if}
                {if !$nuxr_owned && $_can['service.recharge']}
                    <a class="nuxr-btn" style="margin-top:10px" href="{Text::url('plan/recharge/', $d['id'])}">{Lang::T('Recharge (legacy customer)')}</a>
                {/if}
            </div>
        </section>

        {* OD-D: read-only session state from the FreeRADIUS accounting table; never a live NAS call *}
        <section class="nuxr-card" aria-labelledby="nuxr-session">
            <div class="nuxr-card-head"><h3 id="nuxr-session">{Lang::T('Connection')}</h3>
                {if $nuxr_session.state eq 'online'}{call name=nuxr_badge tone='success' label=Lang::T('Online') icon='fa-circle'}
                {elseif $nuxr_session.state eq 'stale'}{call name=nuxr_badge tone='warning' label=Lang::T('Status unknown') icon='fa-question-circle'}
                {elseif $nuxr_session.state eq 'offline'}{call name=nuxr_badge tone='neutral' label=Lang::T('Offline') icon='fa-circle-o'}
                {elseif $nuxr_session.state eq 'never'}{call name=nuxr_badge tone='neutral' label=Lang::T('No session yet') icon='fa-circle-o'}
                {else}{call name=nuxr_badge tone='neutral' label=Lang::T('Unavailable') icon='fa-ban'}{/if}
            </div>
            <div class="nuxr-card-body">
                {if in_array($nuxr_session.state, ['online', 'stale', 'offline'])}
                    {if $nuxr_session.state eq 'stale'}<p class="nuxr-muted" style="margin:0 0 6px">{Lang::T('A session is open but has not been updated recently; the subscriber may be offline.')}</p>{/if}
                    <dl class="nuxr-kv">
                        {if $nuxr_session.state neq 'offline'}<div><dt>{Lang::T('Online since')}</dt><dd><bdi dir="ltr">{$nuxr_session.started|escape}</bdi></dd></div>{/if}
                        <div><dt>{Lang::T('Last seen')}</dt><dd><bdi dir="ltr">{if $nuxr_session.stopped}{$nuxr_session.stopped|escape}{else}{$nuxr_session.last_update|escape}{/if}</bdi></dd></div>
                        {if $nuxr_session.framed_ip}<div><dt>{Lang::T('IP address')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$nuxr_session.framed_ip|escape}</bdi></dd></div>{/if}
                        {if $nuxr_session.mac}<div><dt>{Lang::T('MAC')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$nuxr_session.mac|escape}</bdi></dd></div>{/if}
                        {if isset($nuxr_session.nas)}<div><dt>{Lang::T('NAS')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$nuxr_session.nas|escape}</bdi></dd></div>{/if}
                        <div><dt>{Lang::T('Session time')}</dt><dd><bdi dir="ltr">{NuxrUi::duration($nuxr_session.session_seconds)}</bdi></dd></div>
                        <div><dt>{Lang::T('Download / upload')}</dt><dd><bdi dir="ltr">{NuxrUi::bytes($nuxr_session.download)} / {NuxrUi::bytes($nuxr_session.upload)}</bdi></dd></div>
                    </dl>
                {elseif $nuxr_session.state eq 'never'}
                    <p class="nuxr-muted" style="margin:0">{Lang::T('No RADIUS session has been recorded for this subscriber.')}</p>
                {else}
                    <p class="nuxr-muted" style="margin:0">{Lang::T('Session data is not available (RADIUS accounting is not configured or not readable). Online status is unknown.')}</p>
                {/if}
            </div>
        </section>

        <nav class="nuxr-tabs" aria-label="{Lang::T('Subscriber sections')}">
            <a href="{Text::url('customers/view/', $d['id'], '/overview')}"{if $v eq 'overview'} aria-current="page"{/if}>{Lang::T('Overview')}</a>
            {if $nuxr_can_activations}<a href="{Text::url('customers/view/', $d['id'], '/activations')}"{if $v eq 'activations'} aria-current="page"{/if}>{Lang::T('Activations')}</a>{/if}
            <a href="{Text::url('customers/view/', $d['id'], '/invoices')}"{if $v eq 'invoices'} aria-current="page"{/if}>{Lang::T('Invoices')}</a>
            <a href="{Text::url('customers/view/', $d['id'], '/order')}"{if $v eq 'order'} aria-current="page"{/if}>{Lang::T('Orders')}</a>
        </nav>

        <section class="nuxr-card">
            {if $v eq 'overview'}
                <div class="nuxr-card-head"><h3>{Lang::T('Recent activations')}</h3>
                    {if $nuxr_can_activations}<a href="{Text::url('customers/view/', $d['id'], '/activations')}">{Lang::T('All activations')}</a>{/if}</div>
                {if $nuxr_recent|default:[]}
                    {include file="admin/nuxr/activation-rows.tpl" nuxr_rows=$nuxr_recent nuxr_show_customer=false}
                {else}
                    <div class="nuxr-empty"><i class="fa fa-bolt" aria-hidden="true"></i><span>{Lang::T('No reseller activations for this subscriber.')}</span></div>
                {/if}
                {if Lang::arrayCount($customFields)}
                    <div class="nuxr-card-body" style="border-top:1px solid var(--nx-border)">
                        <h4 class="nuxr-section-title" style="margin-top:0">{Lang::T('Attributes')}</h4>
                        <dl class="nuxr-kv">{foreach $customFields as $f}<div><dt>{$f['field_name']|escape}</dt><dd>{$f['field_value']|escape}</dd></div>{/foreach}</dl>
                    </div>
                {/if}
            {elseif $v eq 'activations'}
                {if $nuxr_activations.rows|default:[]}
                    {include file="admin/nuxr/activation-rows.tpl" nuxr_rows=$nuxr_activations.rows nuxr_show_customer=false}
                    {if $nuxr_activations.pages > 1}
                        <nav class="nuxr-pager" aria-label="{Lang::T('Pages')}">
                            {if $nuxr_activations.page > 1}<a class="nuxr-btn" href="{Text::url('customers/view/', $d['id'], '/activations')}&page={$nuxr_activations.page - 1}">{Lang::T('Previous')}</a>{/if}
                            <span class="nuxr-muted">{Lang::T('Page')} <bdi dir="ltr">{$nuxr_activations.page} / {$nuxr_activations.pages}</bdi></span>
                            {if $nuxr_activations.page < $nuxr_activations.pages}<a class="nuxr-btn" href="{Text::url('customers/view/', $d['id'], '/activations')}&page={$nuxr_activations.page + 1}">{Lang::T('Next')}</a>{/if}
                        </nav>
                    {/if}
                {else}
                    <div class="nuxr-empty"><i class="fa fa-bolt" aria-hidden="true"></i><span>{Lang::T('No reseller activations for this subscriber.')}</span></div>
                {/if}
            {elseif $v eq 'invoices'}
                {if Lang::arrayCount($activation)}
                    <div class="nuxr-adaptive"><div class="nuxr-table-wrap"><table class="nuxr-table">
                        <thead><tr><th>{Lang::T('Invoice')}</th><th>{Lang::T('Plan')}</th><th class="is-num">{Lang::T('Customer price (retail)')}</th><th>{Lang::T('Created')}</th><th>{Lang::T('Expires')}</th><th>{Lang::T('Method')}</th></tr></thead>
                        <tbody>{foreach $activation as $ds}<tr>
                            <td>{if $_can['invoice.read']}<a href="{Text::url('plan/view/', $ds['id'])}"><bdi dir="ltr">{$ds['invoice']|escape}</bdi></a>{else}<bdi dir="ltr">{$ds['invoice']|escape}</bdi>{/if}</td>
                            <td>{$ds['plan_name']|escape}</td><td class="is-num"><bdi dir="ltr">{Lang::moneyFormat($ds['price'])}</bdi></td>
                            <td><bdi dir="ltr">{$ds['recharged_on']|escape} {$ds['recharged_time']|escape}</bdi></td><td><bdi dir="ltr">{$ds['expiration']|escape} {$ds['time']|escape}</bdi></td>
                            <td><bdi dir="ltr">{$ds['method']|escape}</bdi></td></tr>{/foreach}</tbody>
                    </table></div>
                    <ul class="nuxr-mlist">{foreach $activation as $ds}<li><div class="nuxr-mcard">
                        <span class="nuxr-mcard-main"><span class="nuxr-mcard-title"><bdi dir="ltr">{$ds['invoice']|escape}</bdi></span><span class="nuxr-mcard-sub">{$ds['plan_name']|escape} · <bdi dir="ltr">{$ds['recharged_on']|escape}</bdi></span></span>
                        <span class="nuxr-mcard-end"><bdi dir="ltr">{Lang::moneyFormat($ds['price'])}</bdi><span>{Lang::T('retail')}</span></span></div></li>{/foreach}</ul></div>
                    {include file="admin/nuxr/pager.tpl"}
                {else}
                    <div class="nuxr-empty"><i class="fa fa-file-text-o" aria-hidden="true"></i><span>{Lang::T('No invoices yet.')}</span></div>
                {/if}
            {else}
                {if Lang::arrayCount($order)}
                    <ul class="nuxr-mlist" style="display:block">{foreach $order as $ds}<li><div class="nuxr-mcard">
                        <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{$ds['plan_name']|escape}</span>
                            <span class="nuxr-mcard-sub">{$ds['gateway']|escape} · <bdi dir="ltr">{$ds['created_date']|escape}</bdi></span></span>
                        <span class="nuxr-mcard-end">
                            {if $ds['status']==1}{call name=nuxr_badge tone='warning' label=Lang::T('Unpaid')}
                            {elseif $ds['status']==2}{call name=nuxr_badge tone='success' label=Lang::T('Paid')}
                            {elseif $ds['status']==3}{call name=nuxr_badge tone='danger' label=Lang::T('Failed')}
                            {elseif $ds['status']==4}{call name=nuxr_badge tone='neutral' label=Lang::T('Cancelled')}
                            {elseif $ds['status']==5}{call name=nuxr_badge tone='warning' label=Lang::T('Held - under review') icon='fa-pause'}{/if}
                            <bdi dir="ltr">{Lang::moneyFormat($ds['price'])}</bdi></span></div></li>{/foreach}</ul>
                    {include file="admin/nuxr/pager.tpl"}
                {else}
                    <div class="nuxr-empty"><i class="fa fa-shopping-cart" aria-hidden="true"></i><span>{Lang::T('No orders.')}</span></div>
                {/if}
            {/if}
        </section>

        {* secondary and administrative actions: only what this actor may do; dangerous ones separated *}
        {if $_can['message.send'] || $_can['customer.update'] || $_can['customer.sync'] || $_can['customer.impersonate'] || $_can['customer.delete']}
            <section class="nuxr-card" aria-labelledby="nuxr-actions-t">
                <div class="nuxr-card-head"><h3 id="nuxr-actions-t">{Lang::T('More actions')}</h3></div>
                <div class="nuxr-card-body nuxr-actions">
                    {if $_can['message.send']}<a class="nuxr-btn" href="{Text::url('message/send/', $d['id'])}"><i class="fa fa-comment" aria-hidden="true"></i>{Lang::T('Send message')}</a>{/if}
                    {if $_can['customer.update']}<a class="nuxr-btn" href="{Text::url('customers/edit/', $d['id'], '&token=', $csrf_token)}"><i class="fa fa-pencil" aria-hidden="true"></i>{Lang::T('Edit')}</a>{/if}
                    {if $_can['customer.sync']}<button type="button" class="nuxr-btn" data-nuxr-open="nuxr-sync-dialog"><i class="fa fa-refresh" aria-hidden="true"></i>{Lang::T('Synchronize')}</button>{/if}
                    {if $_can['customer.impersonate']}
                        <form method="post" action="{Text::url('customers/login/', $d['id'], '&token=', $csrf_token)}" target="_blank" style="display:inline">
                            <input type="hidden" name="csrf_token" value="{$csrf_token}">
                            <button type="submit" class="nuxr-btn"><i class="fa fa-sign-in" aria-hidden="true"></i>{Lang::T('Login as Customer')}</button>
                        </form>
                    {/if}
                </div>
                {if $_can['customer.delete']}
                    <div class="nuxr-card-body" style="border-top:1px solid var(--nx-border)">
                        <h4 class="nuxr-section-title" style="margin-top:0;color:var(--nx-danger)">{Lang::T('Danger zone')}</h4>
                        <button type="button" class="nuxr-btn nuxr-btn-danger" data-nuxr-open="nuxr-delete-dialog"><i class="fa fa-trash" aria-hidden="true"></i>{Lang::T('Delete subscriber')}</button>
                    </div>
                {/if}
            </section>
        {/if}
    </div>
</div>

{if $nuxr_can_activate}
    <div class="nuxr-actionbar nuxr-only-phone">
        <a class="nuxr-btn nuxr-btn-primary" href="{Text::url('activation/create')}&customer={$d['id']}"{if $nuxr_flags.in_flight} aria-disabled="true" tabindex="-1" onclick="return false"{/if}>
            <i class="fa fa-bolt" aria-hidden="true"></i>{if $packages}{Lang::T('Renew / change plan')}{else}{Lang::T('Activate')}{/if}</a>
    </div>
{/if}
</div>

{if $_can['customer.sync']}
    <dialog id="nuxr-sync-dialog" class="nuxr-sheet" aria-labelledby="nuxr-sync-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-sync-t">{Lang::T('Synchronize')}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <form method="post" action="{Text::url('customers/sync/', $d['id'], '&token=', $csrf_token)}">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <div class="nuxr-dialog-body"><p>{Lang::T('Write this subscriber\'s current service to the network again. The result is shown when it finishes; if it cannot be confirmed it is not retried automatically.')}</p></div>
            <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close>{Lang::T('Cancel')}</button>
                <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Synchronize')}</button></div>
        </form>
    </dialog>
{/if}
{if $_can['customer.delete']}
    <dialog id="nuxr-delete-dialog" class="nuxr-sheet" aria-labelledby="nuxr-delete-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-delete-t">{Lang::T('Delete subscriber')}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <form method="post" action="{Text::url('customers/delete/', $d['id'], '&token=', $csrf_token)}">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <div class="nuxr-dialog-body">
                <div class="nuxr-alert is-danger"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <div><strong>{Lang::T('This permanently deletes the subscriber and removes the service from the network.')}</strong>
                        {Lang::T('It is refused while financial or activation history references the subscriber.')}</div></div>
                <label for="nuxr-delete-confirm">{Lang::T('Type the username to confirm')}: <bdi dir="ltr" class="nuxr-mono">{$d['username']|escape}</bdi></label>
                <input id="nuxr-delete-confirm" class="form-control nuxr-input ltr" dir="ltr" autocomplete="off" data-nuxr-match="{$d['username']|escape}">
            </div>
            <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close autofocus>{Lang::T('Cancel')}</button>
                <button type="submit" class="nuxr-btn nuxr-btn-danger-solid" data-nuxr-match-submit disabled>{Lang::T('Delete subscriber')}</button></div>
        </form>
    </dialog>
{/if}
{if $_can['customer.transfer_owner'] && $nuxr_owned && $nuxr_owner_options|default:[]}
    <dialog id="nuxr-transfer-dialog" class="nuxr-sheet" aria-labelledby="nuxr-transfer-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-transfer-t">{Lang::T('Transfer owner')}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <form method="post" action="{Text::url('reseller/customer-transfer')}" class="nuxr-form">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <input type="hidden" name="customer_id" value="{$d['id']}">
            <div class="nuxr-dialog-body">
                <p>{Lang::T('Future operations use the new owner\'s wallet and prices. History stays with the original owner. A Sales that does not belong to the new owner is cleared.')}</p>
                <label for="nuxr-transfer-owner">{Lang::T('New owner (reseller)')}</label>
                <select id="nuxr-transfer-owner" name="manager_id" class="form-control nuxr-input">
                    {foreach $nuxr_owner_options as $o}{if $o['id'] neq $d['manager_id']}<option value="{$o['id']}">{$o['username']|escape} | {$o['fullname']|escape}</option>{/if}{/foreach}
                </select>
            </div>
            <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close>{Lang::T('Cancel')}</button>
                <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Transfer owner')}</button></div>
        </form>
    </dialog>
{/if}
{if $_can['customer.assign_legacy_owner'] && !$nuxr_owned && $nuxr_owner_options|default:[]}
    <dialog id="nuxr-assign-owner-dialog" class="nuxr-sheet" aria-labelledby="nuxr-assign-owner-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-assign-owner-t">{Lang::T('Assign owner')}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <form method="post" action="{Text::url('reseller/customer-assign-owner')}" class="nuxr-form">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <input type="hidden" name="customer_ids[]" value="{$d['id']}">
            <div class="nuxr-dialog-body">
                <p>{Lang::T('After assignment this subscriber is served through reseller activations only; legacy recharge is no longer available for it.')}</p>
                <label for="nuxr-assign-owner">{Lang::T('Owner (reseller)')}</label>
                <select id="nuxr-assign-owner" name="manager_id" class="form-control nuxr-input">
                    {foreach $nuxr_owner_options as $o}<option value="{$o['id']}">{$o['username']|escape} | {$o['fullname']|escape}</option>{/foreach}
                </select>
            </div>
            <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close>{Lang::T('Cancel')}</button>
                <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Assign owner')}</button></div>
        </form>
    </dialog>
{/if}
{include file="sections/footer.tpl"}
