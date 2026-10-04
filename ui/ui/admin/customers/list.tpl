{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6A subscriber list: server-side scoped search and filters, server pagination (30), one dataset rendered as a dense
   table (>= 768px) and as cards (phone). Actions follow the accepted capabilities; nothing here authorizes. *}
<div class="nuxr-page-head">
    <span class="nuxr-sub" role="status">{if $search}{Lang::T('Results')}: {/if}<strong>{$nuxr_total}</strong> {Lang::T('subscribers')}</span>
    <div class="nuxr-actions">
        {if $_can['customer.create']}
            <a class="nuxr-btn nuxr-btn-primary" href="{Text::url('customers/add')}"><i class="fa fa-user-plus" aria-hidden="true"></i>{Lang::T('Add subscriber')}</a>
        {/if}
        {if $_can['customer.export']}
            <a class="nuxr-btn nuxr-hide-phone" data-method="post" href="{Text::url('customers/csv&token=', $csrf_token)}"><i class="fa fa-download" aria-hidden="true"></i>CSV</a>
        {/if}
    </div>
</div>

<form class="nuxr-card nuxr-card-body nuxr-form" method="get" action="{$app_url}/" role="search">
    <input type="hidden" name="_route" value="customers/list">
    {if $nuxr_owner}<input type="hidden" name="owner" value="{$nuxr_owner|escape}">{/if}
    {if $nuxr_sales}<input type="hidden" name="sales" value="{$nuxr_sales|escape}">{/if}
    {if $nuxr_owner || $nuxr_sales}
        <p class="nuxr-chip is-active" style="margin:0 0 10px"><i class="fa fa-filter" aria-hidden="true"></i>{if $nuxr_sales}{Lang::T('Customers assigned to one Sales account')}{else}{Lang::T('Customers of one reseller')}{/if}
            · <a href="{Text::url('customers/list')}">{Lang::T('Clear')}</a></p>
    {/if}
    <div class="nuxr-filters" style="margin-bottom:0">
        <label class="sr-only" for="nuxr-list-search">{Lang::T('Search subscribers')}</label>
        <input id="nuxr-list-search" class="form-control nuxr-input" style="flex:1 1 260px" type="search" name="search" value="{$search|escape}" maxlength="64"
            placeholder="{Lang::T('Name, username, phone, PPPoE, IP or #ID')}">
        <details class="nuxr-filter-more" {if $filter neq 'Active' || $nuxr_service || $order neq 'username'}open{/if}>
            <summary class="nuxr-btn"><i class="fa fa-sliders" aria-hidden="true"></i>{Lang::T('Filters')}</summary>
            <div class="nuxr-filters" style="margin:10px 0 0">
                <label class="sr-only" for="nuxr-f-status">{Lang::T('Account status')}</label>
                <select id="nuxr-f-status" name="filter" class="form-control nuxr-input" style="width:auto">
                    <option value="All" {if $filter eq 'All'}selected{/if}>{Lang::T('All accounts')}</option>
                    {foreach $statuses as $s}<option value="{$s|escape}" {if $filter eq $s}selected{/if}>{Lang::T($s)}</option>{/foreach}
                </select>
                <label class="sr-only" for="nuxr-f-service">{Lang::T('Service')}</label>
                <select id="nuxr-f-service" name="service" class="form-control nuxr-input" style="width:auto">
                    <option value="" {if !$nuxr_service}selected{/if}>{Lang::T('Any service state')}</option>
                    <option value="active" {if $nuxr_service eq 'active'}selected{/if}>{Lang::T('Active service')}</option>
                    <option value="expiring" {if $nuxr_service eq 'expiring'}selected{/if}>{Lang::T('Expiring within 7 days')}</option>
                    <option value="expired" {if $nuxr_service eq 'expired'}selected{/if}>{Lang::T('Expired')}</option>
                    <option value="none" {if $nuxr_service eq 'none'}selected{/if}>{Lang::T('No service')}</option>
                </select>
                <label class="sr-only" for="nuxr-f-order">{Lang::T('Sort by')}</label>
                <select id="nuxr-f-order" name="order" class="form-control nuxr-input" style="width:auto">
                    <option value="username" {if $order eq 'username'}selected{/if}>{Lang::T('Username')}</option>
                    <option value="fullname" {if $order eq 'fullname'}selected{/if}>{Lang::T('Full Name')}</option>
                    <option value="created_at" {if $order eq 'created_at'}selected{/if}>{Lang::T('Created Date')}</option>
                    <option value="status" {if $order eq 'status'}selected{/if}>{Lang::T('Status')}</option>
                </select>
                <select name="orderby" class="form-control nuxr-input" style="width:auto" aria-label="{Lang::T('Sort direction')}">
                    <option value="asc" {if $orderby eq 'asc'}selected{/if}>{Lang::T('Ascending')}</option>
                    <option value="desc" {if $orderby eq 'desc'}selected{/if}>{Lang::T('Descending')}</option>
                </select>
            </div>
        </details>
        <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i>{Lang::T('Search')}</button>
    </div>
</form>

<div class="nuxr-card">
    {if $d}
        <div class="nuxr-table-wrap">
            <table class="nuxr-table">
                <thead>
                    <tr>
                        {if $_can['message.send_selected']}<th style="width:36px"><input type="checkbox" id="nuxr-select-all" aria-label="{Lang::T('Select all on this page')}"></th>{/if}
                        <th>{Lang::T('Subscriber')}</th>
                        <th>{Lang::T('Username')}</th>
                        <th>{Lang::T('Phone')}</th>
                        <th>{Lang::T('Service')}</th>
                        <th>{Lang::T('Expires')}</th>
                        <th>{Lang::T('Account')}</th>
                        <th><span class="sr-only">{Lang::T('Actions')}</span></th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $d as $ds}
                        {assign var=p value=$nuxr_plans[$ds['id']]|default:null}
                        <tr>
                            {if $_can['message.send_selected']}<td><input type="checkbox" name="customer_ids[]" value="{$ds['id']}" aria-label="{Lang::T('Select')} {$ds['username']|escape}"></td>{/if}
                            <td><a href="{Text::url('customers/view/', $ds['id'])}">{$ds['fullname']|escape}</a></td>
                            <td><bdi dir="ltr" class="nuxr-mono">{$ds['username']|escape}</bdi>{if $ds['pppoe_username']}<br><bdi dir="ltr" class="nuxr-muted nuxr-mono" title="PPPoE">{$ds['pppoe_username']|escape}</bdi>{/if}{if $ds['pppoe_ip']}<br><bdi dir="ltr" class="nuxr-muted nuxr-mono">{$ds['pppoe_ip']|escape}</bdi>{/if}</td>
                            <td>{if $ds['phonenumber']}<a href="tel:{$ds['phonenumber']|escape}"><bdi dir="ltr">{$ds['phonenumber']|escape}</bdi></a>{/if}</td>
                            <td>{if $p}{$p.plan|escape}<br><span class="nuxr-muted">{$ds['service_type']|escape}</span>{else}<span class="nuxr-muted">{$ds['service_type']|escape}</span>{/if}</td>
                            <td>{call name=nuxr_plan_state p=$p}{if $p}<br><bdi dir="ltr" class="nuxr-muted">{$p.expiration|escape}</bdi>{/if}</td>
                            <td>{call name=nuxr_customer_status status=$ds['status']}</td>
                            <td class="is-num"><a class="nuxr-btn" href="{Text::url('customers/view/', $ds['id'])}">{Lang::T('Open')}</a></td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        <ul class="nuxr-mlist">
            {foreach $d as $ds}
                {assign var=p value=$nuxr_plans[$ds['id']]|default:null}
                <li>
                    <a class="nuxr-mcard" href="{Text::url('customers/view/', $ds['id'])}">
                        {call name=nuxr_initial who=$ds['fullname']}
                        <span class="nuxr-mcard-main">
                            <span class="nuxr-mcard-title">{$ds['fullname']|escape}</span>
                            <span class="nuxr-mcard-sub"><bdi dir="ltr">{$ds['username']|escape}</bdi>{if $p} · {$p.plan|escape}{/if}</span>
                            {if $ds['status'] neq 'Active'}<span class="nuxr-mcard-sub">{call name=nuxr_customer_status status=$ds['status']}</span>{/if}
                        </span>
                        <span class="nuxr-mcard-end">{call name=nuxr_plan_state p=$p}{if $p}<bdi dir="ltr">{$p.expiration|escape}</bdi>{/if}</span>
                    </a>
                </li>
            {/foreach}
        </ul>
        {if $_can['message.send_selected']}
            <div class="nuxr-card-body nuxr-hide-phone" style="border-top:1px solid var(--nx-border)">
                <button type="button" class="nuxr-btn" id="nuxr-message-selected"><i class="fa fa-comment" aria-hidden="true"></i>{Lang::T('Message selected')}</button>
            </div>
        {/if}
        {include file="admin/nuxr/pager.tpl"}
    {else}
        <div class="nuxr-empty">
            <i class="fa fa-users" aria-hidden="true"></i>
            <strong>{if $search}{Lang::T('No subscribers match this search.')}{else}{Lang::T('No subscribers here yet.')}{/if}</strong>
            <span>{if $search}{Lang::T('Try the username, phone, PPPoE username, IP or #ID, or show all accounts.')}{elseif $_can['customer.create']}{Lang::T('Add the first subscriber to get started.')}{/if}</span>
            {if $search || $filter neq 'Active' || $nuxr_service}<a class="nuxr-btn" href="{Text::url('customers/list')}&filter=All">{Lang::T('Show all accounts')}</a>{/if}
        </div>
    {/if}
</div>

{if $_can['message.send_selected']}
    <dialog id="nuxr-message-dialog" class="nuxr-sheet" aria-labelledby="nuxr-message-title">
        <div class="nuxr-sheet-head"><h2 id="nuxr-message-title">{Lang::T('Send Message')}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <div class="nuxr-dialog-body nuxr-form">
            <p id="nuxr-message-count" class="nuxr-muted"></p>
            <label for="nuxr-message-type">{Lang::T('Channel')}</label>
            <select id="nuxr-message-type" class="form-control nuxr-input">
                <option value="all">{Lang::T('All')}</option>
                <option value="email">{Lang::T('Email')}</option>
                <option value="inbox">{Lang::T('Inbox')}</option>
                <option value="sms">{Lang::T('SMS')}</option>
                <option value="wa">{Lang::T('WhatsApp')}</option>
            </select>
            <label for="nuxr-message-text" style="margin-top:10px">{Lang::T('Message')}</label>
            <textarea id="nuxr-message-text" class="form-control" rows="4" maxlength="1000"></textarea>
            <p id="nuxr-message-result" role="status" class="nuxr-muted"></p>
        </div>
        <div class="nuxr-dialog-foot">
            <button type="button" class="nuxr-btn" data-nuxr-close>{Lang::T('Cancel')}</button>
            <button type="button" class="nuxr-btn nuxr-btn-primary" id="nuxr-message-send">{Lang::T('Send Message')}</button>
        </div>
    </dialog>
    <script>
        (function () {
            var all = document.getElementById('nuxr-select-all');
            var boxes = function () { return Array.prototype.slice.call(document.querySelectorAll('input[name="customer_ids[]"]')); };
            if (all) { all.addEventListener('change', function () { boxes().forEach(function (b) { b.checked = all.checked; }); }); }
            var dlg = document.getElementById('nuxr-message-dialog');
            var open = document.getElementById('nuxr-message-selected');
            var result = document.getElementById('nuxr-message-result');
            if (!open || !dlg) { return; }
            open.addEventListener('click', function () {
                var ids = boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
                if (!ids.length) { Swal.fire({ icon: 'info', title: '{Lang::T('Select at least one subscriber first.')|escape:'javascript'}' }); return; }
                document.getElementById('nuxr-message-count').textContent = ids.length + ' {Lang::T('selected')|escape:'javascript'}';
                result.textContent = '';
                dlg.showModal();
            });
            document.getElementById('nuxr-message-send').addEventListener('click', function () {
                var btn = this, text = document.getElementById('nuxr-message-text').value.trim();
                var ids = boxes().filter(function (b) { return b.checked; }).map(function (b) { return b.value; });
                if (!text) { result.textContent = '{Lang::T('Please enter a message to send.')|escape:'javascript'}'; return; }
                var body = new URLSearchParams();
                ids.forEach(function (id) { body.append('customer_ids[]', id); });
                body.append('message_type', document.getElementById('nuxr-message-type').value);
                body.append('message', text);
                btn.disabled = true; btn.classList.add('is-pending');
                fetch('{Text::url('message/send_bulk_selected')}', { method: 'POST', credentials: 'same-origin', body: body })
                    .then(function (r) { return r.json(); })
                    .then(function (j) { result.textContent = j && j.status === 'success' ? '{Lang::T('Message sent successfully.')|escape:'javascript'}' : ((j && j.message) || '{Lang::T('Failed to send the message.')|escape:'javascript'}'); })
                    .catch(function () { result.textContent = '{Lang::T('Failed to send the message.')|escape:'javascript'}'; })
                    .then(function () { btn.disabled = false; btn.classList.remove('is-pending'); });
            });
        })();
    </script>
{/if}
{include file="sections/footer.tpl"}
