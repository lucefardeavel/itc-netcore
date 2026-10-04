{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B Operations Center - reseller-owned obligations that were not auto-renewed (no reseller auto-renew in V1).
   Informational only: there is no "resolved" state and no button; a renewal is a normal reseller activation. *}
{include file="admin/operations/tabs.tpl"}
{assign var=l value=$nuxr_renewals}
<section class="nuxr-card">
    <div class="nuxr-card-head"><h2>{Lang::T('Auto-renew not available')}</h2>{call name=nuxr_badge tone='info' label=Lang::T('Informational') icon='fa-info-circle'}</div>
    {if $l.rows}
        <ul class="nuxr-mlist" style="display:block">
            {foreach $l.rows as $x}
                <li><div class="nuxr-mcard nuxr-mcard-form">
                    <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{Lang::T('Subscriber')} <bdi dir="ltr">#{$x.customer_id|escape}</bdi></span>
                        <span class="nuxr-mcard-sub">{Lang::T('Obligation')} <bdi dir="ltr">{$x.obligation|escape}</bdi> · <bdi dir="ltr">{$x.at|truncate:16:'':true|escape}</bdi> (UTC)</span></span>
                    <div class="nuxr-actions"><a class="nuxr-btn" href="{Text::url('customers/view/', $x.customer_id)}">{Lang::T('Open subscriber')}</a></div>
                </div></li>
            {/foreach}
        </ul>
        {include file="admin/nuxr/list-pager.tpl" p=$l pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><strong>{Lang::T('No records.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
