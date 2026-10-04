{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: one reseller wallet (finance read model): identity and state, the four money figures, latest movements and
   the actions the actor may be offered (each re-checked by its own route and service). *}
{assign var=w value=$nuxr_page.wallet}
<section class="nuxr-card" aria-labelledby="nuxr-wallet-t">
    <div class="nuxr-card-head">
        <h2 id="nuxr-wallet-t">{$w.fullname|escape} <bdi dir="ltr" class="nuxr-muted">{$w.username|escape}</bdi></h2>
        {if $w.status neq 'Active'}{call name=nuxr_badge tone='danger' label=Lang::T('Inactive') icon='fa-ban'}{elseif $w.frozen}{call name=nuxr_badge tone='warning' label=Lang::T('Frozen by an upstream reseller') icon='fa-pause'}{else}{call name=nuxr_badge tone='success' label=Lang::T('Active')}{/if}
    </div>
    <div class="nuxr-card-body">
        <dl class="nuxr-kv">
            <div><dt>{Lang::T('Parent reseller')}</dt><dd>{if $w.parent}<bdi dir="ltr">{NuxrUi::party($w.parent)|escape}</bdi>{else}{Lang::T('Top level')}{/if}</dd></div>
            {if !$w.enrolled}<div><dt>{Lang::T('Hierarchy')}</dt><dd>{Lang::T('Not enrolled')}</dd></div>{/if}
            {if $w.updated_at}<div><dt>{Lang::T('Last change (UTC)')}</dt><dd><bdi dir="ltr">{$w.updated_at|truncate:19:'':true|escape}</bdi></dd></div>{/if}
        </dl>
        {if $w.frozen}<div class="nuxr-alert is-warning" role="status" style="margin-top:12px"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
            <div>{Lang::T('This reseller or an upstream reseller is inactive; money operations are paused. Balances remain visible.')}</div></div>{/if}
        <div style="margin-top:12px">{include file="admin/nuxr/wallet-money.tpl" w=$w}</div>
    </div>
    <div class="nuxr-card-body nuxr-actions" style="border-top:1px solid var(--nx-border)">
        {if in_array('send', $nuxr_page.actions) || in_array('send_from_parent', $nuxr_page.actions)}
            <a class="nuxr-btn nuxr-btn-primary" href="{Text::url('finance/send&to=', $w.id)}"><i class="fa fa-exchange" aria-hidden="true"></i>{if in_array('send', $nuxr_page.actions)}{Lang::T('Send to this reseller')}{else}{Lang::T('Transfer from its parent')}{/if}</a>
        {/if}
        {if in_array('fund', $nuxr_page.actions)}
            <a class="nuxr-btn nuxr-btn-primary" href="{Text::url('finance/funding/', $w.id, '&op=fund')}"><i class="fa fa-plus-circle" aria-hidden="true"></i>{Lang::T('Provider funding')}</a>
            <a class="nuxr-btn" href="{Text::url('finance/funding/', $w.id, '&op=withdraw')}"><i class="fa fa-minus-circle" aria-hidden="true"></i>{Lang::T('Provider withdrawal')}</a>
        {/if}
        <a class="nuxr-btn" href="{Text::url('finance/ledger/', $w.id)}"><i class="fa fa-list" aria-hidden="true"></i>{Lang::T('Ledger')}</a>
        <a class="nuxr-btn" href="{Text::url('finance/transfers&manager=', $w.id)}"><i class="fa fa-exchange" aria-hidden="true"></i>{Lang::T('Transfers')}</a>
    </div>
</section>
<section class="nuxr-card" aria-labelledby="nuxr-recent">
    <div class="nuxr-card-head"><h2 id="nuxr-recent">{Lang::T('Latest movements')}</h2><a href="{Text::url('finance/ledger/', $w.id)}">{Lang::T('All')}</a></div>
    {if $nuxr_page.recent}{include file="admin/nuxr/ledger-rows.tpl" rows=$nuxr_page.recent}
    {else}<div class="nuxr-empty"><i class="fa fa-list" aria-hidden="true"></i><strong>{Lang::T('No movements yet.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
