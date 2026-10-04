{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B finance home (WalletDesk::home): an Agent's own wallet and its direct children, or the scoped wallet list for
   Admin / SuperAdmin. All figures are server values from the finance read model. *}
{if $nuxr_home.mode eq 'own'}
    {assign var=w value=$nuxr_home.wallet}
    <section class="nuxr-card" aria-labelledby="nuxr-own-wallet">
        <div class="nuxr-card-head"><h2 id="nuxr-own-wallet">{Lang::T('My wallet')}</h2>
            {if $w.frozen}{call name=nuxr_badge tone='warning' label=Lang::T('Money operations paused') icon='fa-pause'}{/if}</div>
        <div class="nuxr-card-body">
            {if $w.frozen}<div class="nuxr-alert is-warning" role="status"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <div>{Lang::T('Your account or an upstream reseller is inactive; money operations are paused. Balances remain visible.')}</div></div>{/if}
            {include file="admin/nuxr/wallet-money.tpl" w=$w}
        </div>
        <div class="nuxr-card-body nuxr-actions" style="border-top:1px solid var(--nx-border)">
            {if $nuxr_home.can_send && !$w.frozen}<a class="nuxr-btn nuxr-btn-primary" href="{Text::url('finance/send')}"><i class="fa fa-exchange" aria-hidden="true"></i>{Lang::T('Send to a child reseller')}</a>{/if}
            <a class="nuxr-btn" href="{Text::url('finance/ledger/', $w.id)}"><i class="fa fa-list" aria-hidden="true"></i>{Lang::T('Ledger')}</a>
            <a class="nuxr-btn" href="{Text::url('finance/transfers')}"><i class="fa fa-exchange" aria-hidden="true"></i>{Lang::T('Transfers')}</a>
        </div>
    </section>
    <section class="nuxr-card" aria-labelledby="nuxr-recent">
        <div class="nuxr-card-head"><h2 id="nuxr-recent">{Lang::T('Latest movements')}</h2>
            <a href="{Text::url('finance/ledger/', $w.id)}">{Lang::T('All')}</a></div>
        {if $nuxr_home.recent}{include file="admin/nuxr/ledger-rows.tpl" rows=$nuxr_home.recent}
        {else}<div class="nuxr-empty"><i class="fa fa-list" aria-hidden="true"></i><strong>{Lang::T('No movements yet.')}</strong></div>{/if}
    </section>
    {if $nuxr_home.children.total > 0}
        <section class="nuxr-card" aria-labelledby="nuxr-children">
            <div class="nuxr-card-head"><h2 id="nuxr-children">{Lang::T('My child resellers')}</h2></div>
            {include file="admin/finance/wallet-rows.tpl" rows=$nuxr_home.children.rows}
        </section>
    {/if}
{else}
    {assign var=l value=$nuxr_home.list}
    <h2 class="nuxr-section-title">{Lang::T('Reseller wallets')}</h2>
    <div class="nuxr-stats">
        <a class="nuxr-stat" href="{Text::url('finance/wallets')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$l.counts.wallets|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-users" aria-hidden="true"></i>{Lang::T('Resellers')}</span></a>
        <a class="nuxr-stat{if $l.counts.negative} is-warning{/if}" href="{Text::url('finance/wallets&wallet=negative')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$l.counts.negative|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-minus-circle" aria-hidden="true"></i>{Lang::T('Using the debt line')}</span></a>
        <a class="nuxr-stat" href="{Text::url('finance/wallets&wallet=none')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$l.counts.none|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-circle-o" aria-hidden="true"></i>{Lang::T('No wallet yet')}</span></a>
        <div class="nuxr-stat"><span class="nuxr-stat-value"><bdi dir="ltr">{NuxrUi::money($l.reserved_total)|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-lock" aria-hidden="true"></i>{Lang::T('Reserved for activations in progress')}</span></div>
    </div>
    <section class="nuxr-card">
        <div class="nuxr-card-head"><h2>{Lang::T('Wallets')}</h2><a href="{Text::url('finance/wallets')}">{Lang::T('All')}</a></div>
        {if $l.rows}{include file="admin/finance/wallet-rows.tpl" rows=$l.rows}
        {else}<div class="nuxr-empty"><i class="fa fa-credit-card" aria-hidden="true"></i><strong>{Lang::T('No reseller wallets.')}</strong></div>{/if}
    </section>
    <div class="nuxr-actions"><a class="nuxr-btn" href="{Text::url('finance/transfers')}"><i class="fa fa-exchange" aria-hidden="true"></i>{Lang::T('Transfers')}</a></div>
{/if}
{include file="sections/footer.tpl"}
