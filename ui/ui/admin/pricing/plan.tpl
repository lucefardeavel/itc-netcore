{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{include file="admin/nuxr/pricing-row.tpl"}
{* WP6B: one plan across resellers (bounded page; the accepted pricing projection per row). Admin: every reseller;
   Agent: itself and its direct children. *}
{assign var=g value=$nuxr_grid}
<div class="nuxr-page-head">
    <span class="nuxr-sub" role="status"><strong>{$g.plan.name|escape}</strong> · {$g.plan.type|escape} · <bdi dir="ltr">{$g.total|escape}</bdi> {Lang::T('resellers')}</span>
</div>
<section class="nuxr-card">
    {if $g.rows}
        <div class="nuxr-table-wrap">
            <table class="nuxr-table">
                <thead><tr><th>{Lang::T('Reseller')}</th><th>{Lang::T('Wholesale (reseller cost)')}</th><th>{Lang::T('Retail (customer price)')}</th><th>{Lang::T('Your margin')}</th><th>{Lang::T('Availability')}</th><th>{Lang::T('Saleable')}</th><th><span class="sr-only">{Lang::T('Edit')}</span></th></tr></thead>
                <tbody>
                    {foreach $g.rows as $r}
                        <tr>
                            <td><a href="{Text::url('pricing/catalog/', $r.manager_id)}">{$r.fullname|escape}</a><br><bdi dir="ltr" class="nuxr-muted">{$r.username|escape}</bdi></td>
                            <td><bdi dir="ltr">{NuxrUi::money($r.effective_wholesale)|escape}</bdi><br><small class="nuxr-muted">{call name=nuxr_wsource src=$r.wholesale_source}</small></td>
                            <td><bdi dir="ltr">{NuxrUi::money($r.effective_retail)|escape}</bdi><br><small class="nuxr-muted">{call name=nuxr_rsource src=$r.retail_source}</small></td>
                            <td>{if $r.viewer_margin}<bdi dir="ltr">{NuxrUi::money($r.viewer_margin)|escape}</bdi>{/if}</td>
                            <td>{call name=nuxr_avail r=$r}</td>
                            <td>{call name=nuxr_saleable r=$r}</td>
                            <td>{call name=nuxr_pricing_edits r=$r o=$r.offers back='plan' mid=$r.manager_id pid=$g.plan.id}</td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        <ul class="nuxr-mlist">
            {foreach $g.rows as $r}
                <li><div class="nuxr-mcard nuxr-mcard-form">
                    <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{$r.fullname|escape}</span>
                        <span class="nuxr-mcard-sub">{Lang::T('Wholesale (reseller cost)')}: <bdi dir="ltr">{NuxrUi::money($r.effective_wholesale)|escape}</bdi> · {call name=nuxr_wsource src=$r.wholesale_source}</span>
                        <span class="nuxr-mcard-sub">{Lang::T('Retail (customer price)')}: <bdi dir="ltr">{NuxrUi::money($r.effective_retail)|escape}</bdi></span>
                        <span class="nuxr-mcard-sub">{call name=nuxr_avail r=$r} {call name=nuxr_saleable r=$r}</span></span>
                    <div class="nuxr-actions">{call name=nuxr_pricing_edits r=$r o=$r.offers back='plan' mid=$r.manager_id pid=$g.plan.id}</div>
                </div></li>
            {/foreach}
        </ul>
        {include file="admin/nuxr/list-pager.tpl" p=$g pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><strong>{Lang::T('No resellers.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
