{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6A dashboard foundation (OD-L): scoped counts and short lists for every role; the legacy global widgets (charts)
   are a separate Admin tab so the landing page stays light (OD-H). *}
{if $nuxr_widgets_available}
    <nav class="nuxr-tabs" aria-label="{Lang::T('Dashboard views')}" style="max-width:420px">
        <a href="{Text::url('dashboard')}"{if !$nuxr_widgets_view} aria-current="page"{/if}>{Lang::T('Overview')}</a>
        <a href="{Text::url('dashboard')}&widgets=1"{if $nuxr_widgets_view} aria-current="page"{/if}>{Lang::T('Charts and widgets')}</a>
    </nav>
{/if}
{if !$nuxr_widgets_view}
    {assign var=h value=$nuxr_home}
    {if $nuxr_setup|default:[]}{include file="admin/nuxr/setup-guide.tpl"}{/if}
    {if $_nav.quick|default:[]}
        <div class="nuxr-actions" style="margin-bottom:14px">
            {foreach $_nav.quick as $q}<a class="nuxr-btn{if $q@first} nuxr-btn-primary{/if}" href="{Text::url($q.route)}"><i class="fa {$q.icon}" aria-hidden="true"></i>{Lang::T($q.label)}</a>{/foreach}
        </div>
    {/if}
    {if $h.customers}
        <h2 class="nuxr-section-title">{Lang::T('Subscribers')}</h2>
        <div class="nuxr-stats">
            <a class="nuxr-stat" href="{Text::url('customers/list')}&filter=All"><span class="nuxr-stat-value"><bdi dir="ltr">{$h.customers.total}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-users" aria-hidden="true"></i>{Lang::T('All subscribers')}</span></a>
            <a class="nuxr-stat" href="{Text::url('customers/list')}&filter=All&service=active"><span class="nuxr-stat-value"><bdi dir="ltr">{$h.customers.active}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-check-circle" aria-hidden="true"></i>{Lang::T('Active service')}</span></a>
            <a class="nuxr-stat{if $h.customers.expiring} is-warning{/if}" href="{Text::url('customers/list')}&filter=All&service=expiring"><span class="nuxr-stat-value"><bdi dir="ltr">{$h.customers.expiring}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-exclamation-circle" aria-hidden="true"></i>{Lang::T('Expiring within 7 days')}</span></a>
            <a class="nuxr-stat" href="{Text::url('customers/list')}&filter=All&service=expired"><span class="nuxr-stat-value"><bdi dir="ltr">{$h.customers.expired}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-clock-o" aria-hidden="true"></i>{Lang::T('Expired')}</span></a>
        </div>
    {/if}
    {if $h.activations}
        <h2 class="nuxr-section-title">{Lang::T('Activations')}</h2>
        <div class="nuxr-stats">
            <a class="nuxr-stat{if $h.activations.attention} is-warning{/if}" href="{Text::url('activation/list')}&group=attention"><span class="nuxr-stat-value"><bdi dir="ltr">{$h.activations.attention}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>{Lang::T('Needs attention')}</span></a>
            <a class="nuxr-stat" href="{Text::url('activation/list')}&group=processing"><span class="nuxr-stat-value"><bdi dir="ltr">{$h.activations.processing}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-spinner" aria-hidden="true"></i>{Lang::T('In progress')}</span></a>
            <a class="nuxr-stat" href="{Text::url('activation/list')}&group=completed"><span class="nuxr-stat-value"><bdi dir="ltr">{$h.activations.completed}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Completed, last 7 days')}</span></a>
            <a class="nuxr-stat" href="{Text::url('activation/list')}&group=failed"><span class="nuxr-stat-value"><bdi dir="ltr">{$h.activations.failed}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-times" aria-hidden="true"></i>{Lang::T('Failed, last 7 days')}</span></a>
        </div>
    {/if}
    {* WP6B (OD-3): finance tiles (WalletDesk::dashboard; server strings from the finance read model) *}
    {if $nuxr_finance}
        {assign var=fin value=$nuxr_finance}
        <h2 class="nuxr-section-title">{Lang::T('Wallet')}</h2>
        {if $fin.mode eq 'own'}
            <section class="nuxr-card nuxr-finance-tile" aria-labelledby="nuxr-home-wallet">
                <div class="nuxr-card-head"><h3 id="nuxr-home-wallet">{Lang::T('Available credit')}</h3>
                    {if $fin.wallet.frozen}{call name=nuxr_badge tone='warning' label=Lang::T('Money operations paused') icon='fa-pause'}{/if}</div>
                <div class="nuxr-card-body">
                    {if $fin.wallet.exists}<p class="nuxr-stat-value" style="margin:0"><bdi dir="ltr">{NuxrUi::money($fin.wallet.available)|escape}</bdi></p>
                    {else}<p class="nuxr-muted" style="margin:0">{Lang::T('No wallet yet')}</p>{/if}
                </div>
                <div class="nuxr-card-body nuxr-actions" style="border-top:1px solid var(--nx-border)">
                    {if $fin.can_send}<a class="nuxr-btn nuxr-btn-primary" href="{Text::url('finance/send')}"><i class="fa fa-exchange" aria-hidden="true"></i>{Lang::T('Send')}</a>{/if}
                    {if $fin.ledger}<a class="nuxr-btn" href="{Text::url('finance/ledger/', $fin.wallet.id)}"><i class="fa fa-book" aria-hidden="true"></i>{Lang::T('Ledger')}</a>{/if}
                    <a class="nuxr-btn" href="{Text::url('finance/overview')}"><i class="fa fa-credit-card" aria-hidden="true"></i>{Lang::T('Wallet')}</a>
                </div>
            </section>
        {else}
            <div class="nuxr-stats">
                <a class="nuxr-stat{if $fin.exhausted} is-warning{/if}" href="{Text::url('finance/wallets&wallet=exhausted')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$fin.exhausted|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-ban" aria-hidden="true"></i>{Lang::T('Wallets with no available credit')}</span></a>
                <a class="nuxr-stat" href="{Text::url('finance/wallets&wallet=negative')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$fin.negative|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-minus-circle" aria-hidden="true"></i>{Lang::T('Using the debt line')}</span></a>
                <a class="nuxr-stat" href="{Text::url('finance/overview')}"><span class="nuxr-stat-value"><bdi dir="ltr">{NuxrUi::money($fin.reserved_total)|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-lock" aria-hidden="true"></i>{Lang::T('Reserved for activations in progress')}</span></a>
                {if $fin.stale !== null}<a class="nuxr-stat{if $fin.stale} is-warning{/if}" href="{Text::url('operations/activations')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$fin.stale|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-stethoscope" aria-hidden="true"></i>{Lang::T('Operations: stale activations')}</span></a>{/if}
            </div>
        {/if}
    {/if}
    {if $h.customers || $h.activations}
        <div class="nuxr-grid nuxr-grid-2">
            {if $h.activations}
                <section class="nuxr-card" aria-labelledby="nuxr-home-attention">
                    <div class="nuxr-card-head"><h3 id="nuxr-home-attention">{Lang::T('Needs attention')}</h3><a href="{Text::url('activation/list')}&group=attention">{Lang::T('All')}</a></div>
                    {if $h.attention}
                        {include file="admin/nuxr/activation-rows.tpl" nuxr_rows=$h.attention nuxr_show_customer=true nuxr_compact=true}
                    {else}
                        <div class="nuxr-empty"><i class="fa fa-check-circle" aria-hidden="true"></i><span>{Lang::T('Nothing needs attention.')}</span></div>
                    {/if}
                </section>
            {/if}
            {if $h.customers}
                <section class="nuxr-card" aria-labelledby="nuxr-home-expiring">
                    <div class="nuxr-card-head"><h3 id="nuxr-home-expiring">{Lang::T('Expiring soon')}</h3><a href="{Text::url('customers/list')}&filter=All&service=expiring">{Lang::T('All')}</a></div>
                    {if $h.expiring}
                        <ul class="nuxr-mlist" style="display:block">
                            {foreach $h.expiring as $c}
                                <li><a class="nuxr-mcard" href="{Text::url('customers/view/', $c.id)}">
                                    {call name=nuxr_initial who=$c.fullname}
                                    <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{$c.fullname|escape}</span><span class="nuxr-mcard-sub"><bdi dir="ltr">{$c.username|escape}</bdi> · {$c.plan|escape}</span></span>
                                    <span class="nuxr-mcard-end">{call name=nuxr_badge tone='warning' label=Lang::T('Expiring') icon='fa-exclamation-circle'}<bdi dir="ltr">{$c.expiration|escape}</bdi></span>
                                </a></li>
                            {/foreach}
                        </ul>
                    {else}
                        <div class="nuxr-empty"><i class="fa fa-calendar" aria-hidden="true"></i><span>{Lang::T('No service expires in the next 7 days.')}</span></div>
                    {/if}
                </section>
            {/if}
        </div>
    {/if}
    {if !$h.customers && !$h.activations}
        <div class="nuxr-card"><div class="nuxr-empty"><i class="fa fa-user" aria-hidden="true"></i>
            <strong>{Lang::T('No data views are assigned to this role.')}</strong>
            <span>{Lang::T('Use the menu for your profile and password.')}</span></div></div>
    {/if}
{else}

{function showWidget pos=0}
    {foreach $widgets as $w}
        {if $w['position'] == $pos}
            {$w['content']}
        {/if}
    {/foreach}
{/function}

{assign dtipe value="dashboard_`$tipeUser`"}

{assign rows explode(".", $_c[$dtipe])}
{assign pos 1}
{foreach $rows as $cols}
    {if $cols == 12}
        <div class="row">
            <div class="col-md-12">
                {showWidget widgets=$widgets pos=$pos}
            </div>
        </div>
        {assign pos value=$pos+1}
    {else}
        {assign colss explode(",", $cols)}
        <div class="row">
            {foreach $colss as $c}
                <div class="col-md-{$c}">
                    {showWidget widgets=$widgets pos=$pos}
                </div>
                {assign pos value=$pos+1}
            {/foreach}
        </div>
    {/if}
{/foreach}

{/if}
{include file="sections/footer.tpl"}
