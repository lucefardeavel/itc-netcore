{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B finance reports from immutable history (ledger legs + sealed activation snapshots), server-side filters and
   pages; CSV = the same scoped query (date range of at most 366 days, at most 20,000 rows). An Agent sees only the legs
   of wallets in its own subtree; the provider settlement report exists only for Admin / SuperAdmin. *}
{assign var=r value=$nuxr_report}
<nav class="nuxr-filters" aria-label="{Lang::T('Finance reports')}">
    {foreach ['sales' => 'Activation sales', 'commissions' => 'Commissions', 'settlement' => 'Provider settlement', 'performance' => 'Reseller performance'] as $k => $label}
        {if in_array($k, $nuxr_reports)}<a class="nuxr-chip{if $nuxr_kind eq $k} is-active{/if}" href="{Text::url('finance/', $k)}"{if $nuxr_kind eq $k} aria-current="page"{/if}>{Lang::T($label)|escape}</a>{/if}
    {/foreach}
</nav>
<form class="nuxr-card nuxr-card-body nuxr-form" method="get" action="{$app_url}/">
    <input type="hidden" name="_route" value="finance/{$nuxr_kind|escape}">
    <div class="nuxr-filters" style="margin-bottom:0">
        <label for="nuxr-r-from">{Lang::T('From')}</label>
        <input id="nuxr-r-from" class="form-control nuxr-input ltr" dir="ltr" type="date" name="from" value="{$nuxr_filters.from|default:''|escape}" style="width:auto">
        <label for="nuxr-r-to">{Lang::T('To')}</label>
        <input id="nuxr-r-to" class="form-control nuxr-input ltr" dir="ltr" type="date" name="to" value="{$nuxr_filters.to|default:''|escape}" style="width:auto">
        {if $nuxr_filters.manager|default:''}<input type="hidden" name="manager" value="{$nuxr_filters.manager|escape}">{/if}
        <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-filter" aria-hidden="true"></i>{Lang::T('Apply')}</button>
        <a class="nuxr-btn" href="{$nuxr_csv|escape}"><i class="fa fa-download" aria-hidden="true"></i>{Lang::T('Export CSV')}</a>
    </div>
</form>
<section class="nuxr-card">
    <div class="nuxr-card-head"><h2>{if $nuxr_kind eq 'sales'}{Lang::T('Activation sales')}{elseif $nuxr_kind eq 'commissions'}{Lang::T('Commissions')}{elseif $nuxr_kind eq 'settlement'}{Lang::T('Provider settlement')}{else}{Lang::T('Reseller performance')}{/if}</h2>
        <span class="nuxr-muted"><bdi dir="ltr">{$r.total|escape}</bdi> {Lang::T('rows')}{if isset($r.sum)} · {Lang::T('Total')} <bdi dir="ltr">{NuxrUi::money($r.sum)|escape}</bdi>{/if}</span></div>
    {if $nuxr_kind eq 'settlement'}
        <div class="nuxr-card-body"><div class="nuxr-money-grid">
            {call name=nuxr_money domain='wholesale' label=Lang::T('Wholesale debited') amount=$r.totals.wholesale}
            {call name=nuxr_money domain='credit' label=Lang::T('Commissions') amount=$r.totals.commissions}
            {call name=nuxr_money domain='credit' label=Lang::T('Provider settlement (not a wallet)') amount=$r.totals.provider}
        </div></div>
    {/if}
    {if $r.rows}
        <div class="nuxr-adaptive is-lg">
        <div class="nuxr-table-wrap"><table class="nuxr-table">
            {if $nuxr_kind eq 'sales' || $nuxr_kind eq 'commissions'}
                <thead><tr><th>{Lang::T('Time')}</th><th>{Lang::T('Activation')}</th><th>{Lang::T('Plan')}</th><th>{if $nuxr_kind eq 'sales'}{Lang::T('Reseller')}{else}{Lang::T('Recipient')}{/if}</th>
                    {if $nuxr_kind eq 'commissions'}<th>{Lang::T('Sold by')}</th>{/if}
                    <th>{if $nuxr_kind eq 'sales'}{Lang::T('Wholesale debit (reseller cost)')}{else}{Lang::T('Commission')}{/if}</th>
                    {if $nuxr_kind eq 'sales'}<th>{Lang::T('Retail (customer price)')}</th>{/if}<th>{Lang::T('Status')}</th></tr></thead>
                <tbody>{foreach $r.rows as $x}<tr>
                    <td><bdi dir="ltr">{NuxrUi::localTime($x.created_at)|escape}</bdi></td>
                    <td><a href="{Text::url('activation/view/', $x.reference)}"><bdi dir="ltr" class="nuxr-mono">{$x.reference|escape}</bdi></a></td>
                    <td>{$x.plan|escape}</td><td><bdi dir="ltr">{NuxrUi::party($x.manager)|escape}</bdi></td>
                    {if $nuxr_kind eq 'commissions'}<td><bdi dir="ltr">{NuxrUi::party($x.seller)|escape}</bdi></td>{/if}
                    <td><bdi dir="ltr">{NuxrUi::money($x.amount)|escape}</bdi></td>
                    {if $nuxr_kind eq 'sales'}<td><bdi dir="ltr">{NuxrUi::money($x.retail)|escape}</bdi></td>{/if}
                    <td>{if $x.reversed}{call name=nuxr_badge tone='neutral' label=Lang::T('Reversed') icon='fa-history'}{else}{call name=nuxr_badge tone='success' label=Lang::T('Settled')}{/if}</td>
                </tr>{/foreach}</tbody>
            {elseif $nuxr_kind eq 'settlement'}
                <thead><tr><th>{Lang::T('Settled on')}</th><th>{Lang::T('Activation')}</th><th>{Lang::T('Reseller')}</th><th>{Lang::T('Wholesale debit (reseller cost)')}</th><th>{Lang::T('Commissions')}</th><th>{Lang::T('Provider settlement')}</th><th>{Lang::T('Conservation')}</th></tr></thead>
                <tbody>{foreach $r.rows as $x}<tr>
                    <td><bdi dir="ltr">{NuxrUi::localTime($x.settled_at)|escape}</bdi></td>
                    <td><a href="{Text::url('activation/view/', $x.reference)}"><bdi dir="ltr" class="nuxr-mono">{$x.reference|escape}</bdi></a>{if $x.status eq 'REVERSED'} {call name=nuxr_badge tone='neutral' label=Lang::T('Reversed') icon='fa-history'}{/if}</td>
                    <td><bdi dir="ltr">{NuxrUi::party($x.seller)|escape}</bdi></td>
                    <td><bdi dir="ltr">{NuxrUi::money($x.wholesale)|escape}</bdi></td><td><bdi dir="ltr">{NuxrUi::money($x.commissions)|escape}</bdi></td>
                    <td><bdi dir="ltr">{NuxrUi::money($x.provider_settlement)|escape}</bdi></td>
                    <td>{if $x.conserved === true}{call name=nuxr_badge tone='success' label=Lang::T('Conserved') icon='fa-check'}{elseif $x.conserved === false}{call name=nuxr_badge tone='danger' label=Lang::T('Inconsistent - contact support') icon='fa-exclamation-triangle'}{/if}</td>
                </tr>{/foreach}</tbody>
            {else}
                <thead><tr><th>{Lang::T('Reseller')}</th><th>{Lang::T('Activations')}</th><th>{Lang::T('Wholesale debited')}</th><th>{Lang::T('Commissions received')}</th><th>{Lang::T('Reversal entries')}</th></tr></thead>
                <tbody>{foreach $r.rows as $x}<tr>
                    <td><a href="{Text::url('reseller/detail/', $x.id)}">{$x.fullname|escape}</a> <bdi dir="ltr" class="nuxr-muted">{$x.username|escape}</bdi></td>
                    <td><bdi dir="ltr">{$x.activations|escape}</bdi></td><td><bdi dir="ltr">{NuxrUi::money($x.wholesale)|escape}</bdi></td>
                    <td><bdi dir="ltr">{NuxrUi::money($x.commissions)|escape}</bdi></td><td><bdi dir="ltr">{$x.reversals|escape}</bdi></td>
                </tr>{/foreach}</tbody>
            {/if}
        </table></div>
        <ul class="nuxr-mlist">
            {foreach $r.rows as $x}
                <li><div class="nuxr-mcard">
                    <span class="nuxr-mcard-main">
                        {if $nuxr_kind eq 'performance'}
                            <span class="nuxr-mcard-title">{$x.fullname|escape}</span>
                            <span class="nuxr-mcard-sub">{Lang::T('Activations')} <bdi dir="ltr">{$x.activations|escape}</bdi> · {Lang::T('Commissions received')} <bdi dir="ltr">{NuxrUi::money($x.commissions)|escape}</bdi></span>
                        {else}
                            <span class="nuxr-mcard-title"><bdi dir="ltr" class="nuxr-mono">{$x.reference|escape}</bdi></span>
                            <span class="nuxr-mcard-sub"><bdi dir="ltr">{NuxrUi::localTime($x.created_at|default:$x.settled_at, 16)|escape}</bdi> · <bdi dir="ltr">{NuxrUi::party($x.manager|default:$x.seller)|escape}</bdi></span>
                        {/if}
                    </span>
                    <span class="nuxr-mcard-end"><bdi dir="ltr">{if $nuxr_kind eq 'performance'}{NuxrUi::money($x.wholesale)|escape}{elseif $nuxr_kind eq 'settlement'}{NuxrUi::money($x.provider_settlement)|escape}{else}{NuxrUi::money($x.amount)|escape}{/if}</bdi></span>
                </div></li>
            {/foreach}
        </ul>
        </div>
        {include file="admin/nuxr/list-pager.tpl" p=$r pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><i class="fa fa-bar-chart" aria-hidden="true"></i><strong>{Lang::T('Nothing in this period.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
