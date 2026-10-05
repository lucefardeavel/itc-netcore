{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{include file="admin/nuxr/pricing-row.tpl"}
{* WP6B: one reseller's pricing catalog (bounded page; the accepted pricing projection per row). *}
{assign var=c value=$nuxr_cat}
<div class="nuxr-page-head">
    <span class="nuxr-sub" role="status">{$c.target.fullname|escape} <bdi dir="ltr" class="nuxr-muted">{$c.target.username|escape}</bdi>
        · <strong><bdi dir="ltr">{$c.total|escape}</bdi></strong> {Lang::T('resellable plans')}</span>
    <a class="nuxr-btn" href="{Text::url('pricing/catalog/', $c.target.id, '&format=csv')}"><i class="fa fa-download" aria-hidden="true"></i>{Lang::T('Export CSV')}</a>
</div>
<div class="nuxr-alert is-info" role="note"><i class="fa fa-info-circle" aria-hidden="true"></i>
    <div><strong>{Lang::T('Wholesale (reseller cost)')}</strong>: {Lang::T('what this reseller pays from its wallet for one activation.')}
        <strong>{Lang::T('Retail (customer price)')}</strong>: {Lang::T('what the subscriber pays.')}</div></div>
<section class="nuxr-card">
    {if $c.rows}
        <div class="nuxr-adaptive is-lg">
        <div class="nuxr-table-wrap">
            <table class="nuxr-table">
                <thead><tr><th>{Lang::T('Plan')}</th><th>{Lang::T('Wholesale (reseller cost)')}</th><th>{Lang::T('Retail (customer price)')}</th>
                    {if $c.relation eq 'child' || $c.relation eq 'descendant'}<th>{Lang::T('Your margin')}</th>{/if}
                    {if $c.relation eq 'global'}<th>{Lang::T('Provider base cost')}</th>{/if}
                    <th>{Lang::T('Availability')}</th><th>{Lang::T('Saleable')}</th><th><span class="sr-only">{Lang::T('Edit')}</span></th></tr></thead>
                <tbody>
                    {foreach $c.rows as $r}
                        <tr>
                            <td><a href="{Text::url('pricing/plan/', $r.plan_id)}">{$r.plan_name|escape}</a><br><span class="nuxr-muted">{$r.plan_type|escape}</span></td>
                            <td><bdi dir="ltr">{NuxrUi::money($r.effective_wholesale)|escape}</bdi><br><small class="nuxr-muted">{call name=nuxr_wsource src=$r.wholesale_source}</small></td>
                            <td><bdi dir="ltr">{NuxrUi::money($r.effective_retail)|escape}</bdi><br><small class="nuxr-muted">{call name=nuxr_rsource src=$r.retail_source}</small></td>
                            {if $c.relation eq 'child' || $c.relation eq 'descendant'}<td><bdi dir="ltr">{NuxrUi::money($r.viewer_margin)|escape}</bdi></td>{/if}
                            {if $c.relation eq 'global'}<td><bdi dir="ltr">{NuxrUi::money($r.base_cost|default:'')|escape}</bdi></td>{/if}
                            <td>{call name=nuxr_avail r=$r}</td>
                            <td>{call name=nuxr_saleable r=$r}</td>
                            <td>{call name=nuxr_pricing_edits r=$r o=$c.offers back='catalog' mid=$c.target.id pid=$r.plan_id}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        <ul class="nuxr-mlist">
            {foreach $c.rows as $r}
                <li><div class="nuxr-mcard nuxr-mcard-form">
                    <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{$r.plan_name|escape}</span>
                        <span class="nuxr-mcard-sub">{Lang::T('Wholesale (reseller cost)')}: <bdi dir="ltr">{NuxrUi::money($r.effective_wholesale)|escape}</bdi> · {call name=nuxr_wsource src=$r.wholesale_source}</span>
                        <span class="nuxr-mcard-sub">{Lang::T('Retail (customer price)')}: <bdi dir="ltr">{NuxrUi::money($r.effective_retail)|escape}</bdi> · {call name=nuxr_rsource src=$r.retail_source}</span>
                        {if $r.viewer_margin}<span class="nuxr-mcard-sub">{Lang::T('Your margin')}: <bdi dir="ltr">{NuxrUi::money($r.viewer_margin)|escape}</bdi></span>{/if}
                        <span class="nuxr-mcard-sub">{call name=nuxr_avail r=$r} {call name=nuxr_saleable r=$r}</span></span>
                    <div class="nuxr-actions">{call name=nuxr_pricing_edits r=$r o=$c.offers back='catalog' mid=$c.target.id pid=$r.plan_id}</div>
                </div></li>
            {/foreach}
        </ul>
        </div>
        {include file="admin/nuxr/list-pager.tpl" p=$c pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><i class="fa fa-tags" aria-hidden="true"></i><strong>{Lang::T('No resellable plans yet.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
