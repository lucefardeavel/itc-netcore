{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6A activation / renew / change plan (WP5A backend). The confirmation shows the LIVE server quote
   (ActivationPreviewService, OD-B): wholesale and retail as separate domains, available credit for Admin and the
   paying Agent, "credit sufficient" only for Sales. The confirm carries the quote fingerprint, never money; the server
   re-derives everything and refuses a changed quote (ACTIVATION_PREVIEW_CHANGED). The browser is never money authority. *}
<ol class="nuxr-stepper" aria-label="{Lang::T('Steps')}">
    <li class="{if $nuxr_customer}is-done{else}is-current{/if}">{Lang::T('Subscriber')}</li>
    <li class="{if $nuxr_preview}is-done{elseif $nuxr_customer}is-current{/if}">{Lang::T('Plan')}</li>
    <li class="{if $nuxr_preview}is-current{/if}">{Lang::T('Confirm')}</li>
</ol>

{if isset($nuxr_error)}
    <div class="nuxr-alert is-danger" role="alert"><i class="fa fa-times-circle" aria-hidden="true"></i><div>{$nuxr_error|escape}</div></div>
{/if}

{if !$nuxr_customer}
    {* step 1: customer picker (scoped server-side search; only customers this actor may activate) *}
    <section class="nuxr-card" aria-labelledby="nuxr-pick-t">
        <div class="nuxr-card-head"><h2 id="nuxr-pick-t">{Lang::T('Choose the subscriber')}</h2></div>
        <div class="nuxr-card-body">
            <form method="get" action="{$app_url}/" class="nuxr-filters nuxr-form" role="search" style="margin:0">
                <input type="hidden" name="_route" value="activation/create">
                <label class="sr-only" for="nuxr-pick-q">{Lang::T('Search subscribers')}</label>
                <input id="nuxr-pick-q" type="search" name="q" value="{$nuxr_q|escape}" class="form-control nuxr-input" style="flex:1 1 240px" minlength="2" maxlength="64" required
                    placeholder="{Lang::T('Name, username, phone, PPPoE, IP or #ID')}" autofocus>
                {if $nuxr_plan_cards}
                    <label class="sr-only" for="nuxr-pick-plan">{Lang::T('Plan')}</label>
                    <select id="nuxr-pick-plan" name="plan" class="form-control nuxr-input" style="flex:1 1 200px">
                        <option value="">{Lang::T('Any plan')}</option>
                        {foreach $nuxr_plan_cards as $p}<option value="{$p.id}" {if $p.id eq $nuxr_plan_id}selected{/if}>{$p.name_plan|escape} ({$p.type|escape}){if $p.speed} · {$p.speed|escape}{/if}</option>{/foreach}
                    </select>
                {/if}
                <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i>{Lang::T('Search')}</button>
            </form>
        </div>
        {if $nuxr_q}
            {if $nuxr_picked|default:[]}
                <ul class="nuxr-mlist" style="display:block">
                    {foreach $nuxr_picked as $c}
                        <li><a class="nuxr-mcard" href="{Text::url('activation/create')}&customer={$c.id}{if $nuxr_plan_id}&plan={$nuxr_plan_id}{/if}">
                            {call name=nuxr_initial who=$c.fullname}
                            <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{$c.fullname|escape}</span>
                                <span class="nuxr-mcard-sub"><bdi dir="ltr">{$c.username|escape}</bdi>{if $c.phonenumber} · <bdi dir="ltr">{$c.phonenumber|escape}</bdi>{/if}</span></span>
                            <span class="nuxr-mcard-end">{call name=nuxr_customer_status status=$c.status}<i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></span>
                        </a></li>
                    {/foreach}
                </ul>
            {else}
                <div class="nuxr-empty"><i class="fa fa-search" aria-hidden="true"></i><strong>{Lang::T('No subscriber you can activate matches this search.')}</strong>
                    <span>{Lang::T('Only reseller-owned subscribers in your scope can be activated here.')}</span></div>
            {/if}
        {/if}
    </section>
{else}
    <section class="nuxr-card">
        <div class="nuxr-card-body" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap">
            {call name=nuxr_initial who=$nuxr_customer.fullname}
            <div style="flex:1 1 180px;min-width:0"><strong>{$nuxr_customer.fullname|escape}</strong><br><bdi dir="ltr" class="nuxr-muted">{$nuxr_customer.username|escape}</bdi></div>
            <a class="nuxr-btn" href="{Text::url('customers/view/', $nuxr_customer.id)}">{Lang::T('Subscriber')}</a>
            <a class="nuxr-btn" href="{Text::url('activation/create')}">{Lang::T('Change')}</a>
        </div>
    </section>

    {* step 2: plan (the actor's saleable catalog; no price is shown from the legacy plan table) *}
    <section class="nuxr-card" aria-labelledby="nuxr-plan-t">
        <div class="nuxr-card-head"><h2 id="nuxr-plan-t">{Lang::T('Plan')}</h2></div>
        {if $nuxr_plan_cards}
            <form method="get" action="{$app_url}/" class="nuxr-card-body" data-nuxr-autosubmit>
                <input type="hidden" name="_route" value="activation/create">
                <input type="hidden" name="customer" value="{$nuxr_customer.id}">
                <label for="nuxr-plan-select">{Lang::T('Plan')}</label>
                <select id="nuxr-plan-select" name="plan" class="form-control nuxr-input" required>
                    <option value="">{Lang::T('Choose a plan')}</option>
                    {foreach $nuxr_plan_cards as $p}<option value="{$p.id}" {if $p.id eq $nuxr_plan_id}selected{/if}>{$p.name_plan|escape} ({$p.type|escape}){if $p.speed} · {$p.speed|escape}{/if}{if $p.validity} · {if $p.validity_unit eq 'Days'}{NuxrUi::days($p.validity)|escape}{else}{$p.validity|escape} {Lang::T($p.validity_unit)}{/if}{/if}</option>{/foreach}
                </select>
                <button class="nuxr-btn nuxr-btn-primary" type="submit" style="margin-top:12px">{Lang::T('Review')}</button>
            </form>
        {else}
            <div class="nuxr-empty"><i class="fa fa-cube" aria-hidden="true"></i><strong>{Lang::T('No plan is available for this subscriber.')}</strong>
                <span>{Lang::T('Plans appear here when they are priced and enabled for the subscriber\'s reseller.')}</span></div>
        {/if}
    </section>
{/if}

{if $nuxr_preview}
    {* step 3: live confirmation *}
    {assign var=q value=$nuxr_preview}
    <section class="nuxr-card" aria-labelledby="nuxr-confirm-t">
        <div class="nuxr-card-head"><h2 id="nuxr-confirm-t">{Lang::T('Confirm')} · {Lang::T(NuxrUi::actionLabel($q.action))}</h2>
            <span class="nuxr-muted" style="font-size:13px">{Lang::T('Quoted at')} <bdi dir="ltr">{$q.quoted_at|escape}</bdi></span></div>
        <div class="nuxr-card-body">
            <p style="margin-top:0">
                {if $q.action eq 'NEW'}{Lang::T('A new service period starts now.')}
                {elseif $q.action eq 'RENEW'}{Lang::T('Same plan: the new period continues from the current expiry.')}
                {elseif $q.action eq 'CHANGE_PLAN'}{Lang::T('Plan change: takes effect immediately, the full new period is charged, no proration.')}
                {else}{Lang::T('The expired service is reactivated; a new period starts now.')}{/if}
            </p>
            {* UX-15: the paid time a plan change replaces, from the quote's own dates (display only: never posted, never read by the server) *}
            {if $q.action eq 'CHANGE_PLAN'}{assign var=nuxr_lost value=NuxrUi::lostDays($q.current_expiry, $q.start)}{else}{assign var=nuxr_lost value=null}{/if}
            {if $nuxr_lost}
                <div class="nuxr-alert is-warning" role="alert" id="nuxr-change-loss" style="margin:0 0 12px"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <div><strong>{Lang::T('Remaining paid time will be lost')}</strong> {NuxrUi::lostDaysNotice($nuxr_lost, $q.current_expiry)|escape}</div></div>
            {/if}
            <dl class="nuxr-kv">
                <div><dt>{Lang::T('Plan')}</dt><dd>{$q.plan_name|escape}</dd></div>
                <div><dt>{Lang::T('Current expiry')}</dt><dd>{if $q.current_expiry}<bdi dir="ltr">{$q.current_expiry|escape}</bdi>{else}-{/if}</dd></div>
                <div><dt>{Lang::T('New period')}</dt><dd><bdi dir="ltr">{$q.start|escape} → {$q.expiry|escape}</bdi></dd></div>
                {if $q.full && $q.payer}<div><dt>{Lang::T('Paid from the wallet of')}</dt><dd><bdi dir="ltr">{$q.payer|escape}</bdi></dd></div>{/if}
            </dl>
            <div class="nuxr-money-grid" style="margin-top:14px">
                {if $q.full}
                    {call name=nuxr_money domain='wholesale' label=Lang::T('Wholesale - reseller cost') amount=$q.wholesale note=Lang::T('Charged to the reseller wallet')}
                {/if}
                {call name=nuxr_money domain='retail' label=Lang::T('Retail - customer price') amount=$q.retail note=Lang::T('What the subscriber pays')}
                {if $q.full}
                    {call name=nuxr_money domain='credit' label=Lang::T('Available credit now') amount=$q.available_before}
                    {call name=nuxr_money domain='credit' label=Lang::T('Available credit after') amount=$q.available_after}
                {/if}
            </div>
            {if $q.credit_sufficient}
                <p class="nuxr-muted" style="margin:12px 0 0">{call name=nuxr_badge tone='success' label=Lang::T('Credit sufficient: yes')} {Lang::T('The credit is checked again when you confirm.')}</p>
            {else}
                <div class="nuxr-alert is-danger" role="alert" style="margin:12px 0 0"><i class="fa fa-ban" aria-hidden="true"></i>
                    <div><strong>{Lang::T('Credit sufficient: no')}</strong>{Lang::T('The reseller\'s available credit does not cover this activation. Nothing can be activated until credit is added.')}</div></div>
            {/if}
        </div>
        <form method="post" action="{Text::url('activation/create-post')}" class="nuxr-step-nav" id="nuxr-confirm-form" data-nuxr-critical>
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <input type="hidden" name="customer_id" value="{$nuxr_customer_id}">
            <input type="hidden" name="plan_id" value="{$nuxr_plan_id}">
            <input type="hidden" name="expected_action" value="{$q.action|escape}">
            <input type="hidden" name="nonce" value="{$nuxr_nonce}">
            <input type="hidden" name="preview" value="{$q.fingerprint|escape}">
            <a class="nuxr-btn" href="{Text::url('activation/create')}&customer={$nuxr_customer_id}&plan={$nuxr_plan_id}"><i class="fa fa-refresh" aria-hidden="true"></i>{Lang::T('Refresh quote')}</a>
            <button class="nuxr-btn nuxr-btn-primary" type="submit" {if !$q.credit_sufficient}disabled{/if}><i class="fa fa-bolt" aria-hidden="true"></i>{Lang::T('Confirm activation')}</button>
        </form>
        <p class="nuxr-muted nuxr-card-body" id="nuxr-confirm-note" style="margin:0;padding-top:0" hidden role="status">{Lang::T('Submitting - do not close this page. The result opens when the network has answered.')}</p>
    </section>
{/if}
<script>
    (function () {
        var f = document.querySelector('[data-nuxr-autosubmit]');
        if (f) { f.addEventListener('change', function (e) { if (e.target.name === 'plan' && e.target.value) { f.submit(); } }); }
        var c = document.getElementById('nuxr-confirm-form'), note = document.getElementById('nuxr-confirm-note');
        if (c && note) { c.addEventListener('submit', function () { note.hidden = false; }); }
    })();
</script>
{include file="sections/footer.tpl"}
