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
                <li><div class="nuxr-mcard nuxr-mcard-form">
                    <span class="nuxr-mcard-main">
                        <span class="nuxr-mcard-title">{if $p.base_cost !== null}<a href="{Text::url('pricing/plan/', $p.plan_id)}">{$p.plan_name|escape}</a>{else}{$p.plan_name|escape}{/if} <span class="nuxr-muted">{$p.plan_type|escape}</span></span>
                        <span class="nuxr-mcard-sub">{Lang::T('Default retail (plan price)')}: <bdi dir="ltr">{$p.legacy_price|escape}</bdi></span>
                        <span class="nuxr-mcard-sub">{Lang::T('Provider base cost')}: {if $p.base_cost !== null}<bdi dir="ltr">{NuxrUi::money($p.base_cost)|escape}</bdi>{else}{Lang::T('not set - not resellable')}{/if}
                            · {if $p.resale_enabled}{call name=nuxr_badge tone='success' label=Lang::T('Reseller commerce on')}{elseif $p.base_cost !== null}{call name=nuxr_badge tone='neutral' label=Lang::T('Reseller commerce off') icon='fa-power-off'}{/if}</span>
                    </span>
                    {if $nuxr_plans.can_base}
                        <div class="nuxr-actions">
                            <details class="nuxr-inline-edit"><summary class="nuxr-btn">{Lang::T('Provider base cost')}</summary>
                                <form method="post" action="{Text::url('pricing/base-cost-post')}" class="nuxr-fields">
                                    <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="plan_id" value="{$p.plan_id|escape}">
                                    <label for="nuxr-b-{$p.plan_id|escape}">{Lang::T('Provider base cost')}</label>
                                    <input id="nuxr-b-{$p.plan_id|escape}" name="cost" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" maxlength="20" required value="{$p.base_cost|default:''|escape}">
                                    <input name="reason" class="form-control nuxr-input" maxlength="255" placeholder="{Lang::T('Reason (optional)')}" aria-label="{Lang::T('Reason (optional)')}">
                                    <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Save')}</button>
                                </form></details>
                            {if $p.base_cost !== null}
                                <form method="post" action="{Text::url('pricing/resale-post')}">
                                    <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="plan_id" value="{$p.plan_id|escape}">
                                    <input type="hidden" name="enabled" value="{if $p.resale_enabled}0{else}1{/if}">
                                    <button type="submit" class="nuxr-btn">{if $p.resale_enabled}{Lang::T('Turn reseller commerce off')}{else}{Lang::T('Turn reseller commerce on')}{/if}</button>
                                </form>
                            {/if}
                        </div>
                    {/if}
                </div></li>
            {/foreach}
        </ul>
        {include file="admin/nuxr/list-pager.tpl" p=$l pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><strong>{Lang::T('No plans.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
