{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: Admin / SuperAdmin pricing administration: provider base cost and reseller commerce per plan (accepted
   pricing setters). The legacy plan price is shown only as the default retail (customer price). *}
{assign var=l value=$nuxr_plans.list}
<div class="nuxr-page-head"><span class="nuxr-sub" role="status"><strong><bdi dir="ltr">{$l.total|escape}</bdi></strong> {Lang::T('plans')}</span></div>
<section class="nuxr-card">
    {if $l.rows}
        <ul class="nuxr-mlist" style="display:block">
            {foreach $l.rows as $p}
                <li><div class="nuxr-mcard is-wrap">
                    <span class="nuxr-mcard-main">
                        <span class="nuxr-mcard-title">{if $p.base_cost !== null}<a href="{Text::url('pricing/plan/', $p.plan_id)}">{$p.plan_name|escape}</a>{else}{$p.plan_name|escape}{/if} <span class="nuxr-muted">{Lang::T($p.plan_type)|escape}</span></span>
                        <span class="nuxr-mcard-sub">{Lang::T('Default retail (plan price)')}: <bdi dir="ltr">{$p.legacy_price|escape}</bdi></span>
                        <span class="nuxr-mcard-sub">{Lang::T('Provider base cost')}: {if $p.base_cost !== null}<bdi dir="ltr">{NuxrUi::money($p.base_cost)|escape}</bdi>{else}{Lang::T('not set - not resellable')}{/if}
                            · {if $p.resale_enabled}{call name=nuxr_badge tone='success' label=Lang::T('Reseller commerce on')}{elseif $p.base_cost !== null}{call name=nuxr_badge tone='neutral' label=Lang::T('Reseller commerce off') icon='fa-power-off'}{/if}</span>
                    </span>
                    {if $nuxr_plans.can_base}
                        <div class="nuxr-actions"><a class="nuxr-btn" href="#nuxr-pb-{$p.plan_id|escape}" data-nuxr-open="nuxr-pb-{$p.plan_id|escape}" role="button" aria-haspopup="dialog"><i class="fa fa-pencil" aria-hidden="true"></i>{Lang::T('Edit')}<span class="sr-only"> {$p.plan_name|escape}</span></a></div>
                    {/if}
                </div></li>
            {/foreach}
        </ul>
        {* WP6C (OD-2): one row-level Edit; the same accepted base-cost / reseller-commerce forms in one editing surface *}
        {if $nuxr_plans.can_base}{foreach $l.rows as $p}
            <dialog id="nuxr-pb-{$p.plan_id|escape}" class="nuxr-sheet nuxr-sheet-edit" aria-labelledby="nuxr-pb-{$p.plan_id|escape}-t">
                <div class="nuxr-sheet-head"><h2 id="nuxr-pb-{$p.plan_id|escape}-t">{Lang::T('Edit pricing')}<span class="nuxr-sheet-subtitle">{$p.plan_name|escape}</span></h2>
                    <a href="#" class="nuxr-icon-btn" data-nuxr-close role="button" aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></a></div>
                <div class="nuxr-sheet-body">
                    <dl class="nuxr-pe-now">
                        <div><dt>{Lang::T('Default retail (plan price)')}</dt><dd><bdi dir="ltr">{$p.legacy_price|escape}</bdi></dd></div>
                        <div><dt>{Lang::T('Provider base cost')}</dt><dd>{if $p.base_cost !== null}<bdi dir="ltr">{NuxrUi::money($p.base_cost)|escape}</bdi>{else}{Lang::T('not set - not resellable')}{/if}</dd></div>
                    </dl>
                    <form method="post" action="{Text::url('pricing/base-cost-post')}" class="nuxr-fields nuxr-pe-section">
                        <h3>{Lang::T('Provider base cost')}</h3>
                        <p class="nuxr-muted">{Lang::T('The lowest price the company takes from resellers for this plan. Resellers cannot sell the plan until it is set.')}</p>
                        <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="plan_id" value="{$p.plan_id|escape}">
                        <label for="nuxr-b-{$p.plan_id|escape}">{Lang::T('Provider base cost')}</label>
                        <input id="nuxr-b-{$p.plan_id|escape}" name="cost" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" maxlength="20" required value="{$p.base_cost|default:''|escape}">
                        <label for="nuxr-br-{$p.plan_id|escape}">{Lang::T('Reason (optional)')}</label>
                        <input id="nuxr-br-{$p.plan_id|escape}" name="reason" class="form-control nuxr-input" maxlength="255">
                        <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Save')}</button>
                    </form>
                    {if $p.base_cost !== null}
                        <form method="post" action="{Text::url('pricing/resale-post')}" class="nuxr-pe-section">
                            <h3>{Lang::T('Reseller commerce')}</h3>
                            <p class="nuxr-muted">{Lang::T('When on, resellers can activate this plan for their subscribers from their wallets.')}</p>
                            <p>{if $p.resale_enabled}{call name=nuxr_badge tone='success' label=Lang::T('Reseller commerce on')}{else}{call name=nuxr_badge tone='neutral' label=Lang::T('Reseller commerce off') icon='fa-power-off'}{/if}</p>
                            <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="plan_id" value="{$p.plan_id|escape}">
                            <input type="hidden" name="enabled" value="{if $p.resale_enabled}0{else}1{/if}">
                            <button type="submit" class="nuxr-btn">{if $p.resale_enabled}{Lang::T('Turn reseller commerce off')}{else}{Lang::T('Turn reseller commerce on')}{/if}</button>
                        </form>
                    {/if}
                </div>
            </dialog>
        {/foreach}{/if}
        {include file="admin/nuxr/list-pager.tpl" p=$l pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><strong>{Lang::T('No plans.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
