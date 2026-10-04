{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: transfers whose source or destination wallet is in the actor's scope; server filters / pages; CSV uses the same
   query (date range of at most 366 days, at most 20,000 rows). *}
<div class="nuxr-page-head">
    <span class="nuxr-sub" role="status"><strong><bdi dir="ltr">{$nuxr_list.total|escape}</bdi></strong> {Lang::T('transfers')}</span>
    <a class="nuxr-btn" href="{$nuxr_csv|escape}"><i class="fa fa-download" aria-hidden="true"></i>{Lang::T('Export CSV')}</a>
</div>
<form class="nuxr-card nuxr-card-body nuxr-form" method="get" action="{$app_url}/">
    <input type="hidden" name="_route" value="finance/transfers">
    {if $nuxr_filters.manager|default:''}<input type="hidden" name="manager" value="{$nuxr_filters.manager|escape}">{/if}
    <div class="nuxr-filters" style="margin-bottom:0">
        <label for="nuxr-t-from">{Lang::T('From (UTC)')}</label>
        <input id="nuxr-t-from" class="form-control nuxr-input ltr" dir="ltr" type="date" name="from" value="{$nuxr_filters.from|default:''|escape}" style="width:auto">
        <label for="nuxr-t-to">{Lang::T('To (UTC)')}</label>
        <input id="nuxr-t-to" class="form-control nuxr-input ltr" dir="ltr" type="date" name="to" value="{$nuxr_filters.to|default:''|escape}" style="width:auto">
        <label class="sr-only" for="nuxr-t-type">{Lang::T('Type')}</label>
        <select id="nuxr-t-type" name="type" class="form-control nuxr-input" style="width:auto">
            <option value="">{Lang::T('Any type')}</option>
            {foreach NuxrUi::TRANSFER_TYPE as $k => $label}<option value="{$k|escape}" {if ($nuxr_filters.type|default:'') eq $k}selected{/if}>{Lang::T($label)|escape}</option>{/foreach}
        </select>
        <label class="sr-only" for="nuxr-t-ref">{Lang::T('Reference')}</label>
        <input id="nuxr-t-ref" class="form-control nuxr-input ltr" dir="ltr" name="reference" value="{$nuxr_filters.reference|default:''|escape}" maxlength="64" placeholder="{Lang::T('Exact reference')}" style="width:auto">
        <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-filter" aria-hidden="true"></i>{Lang::T('Apply')}</button>
    </div>
</form>
<section class="nuxr-card">
    {if $nuxr_list.rows}
        {include file="admin/nuxr/transfer-rows.tpl" rows=$nuxr_list.rows}
        {include file="admin/nuxr/list-pager.tpl" p=$nuxr_list pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><i class="fa fa-exchange" aria-hidden="true"></i><strong>{Lang::T('No transfers match these filters.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
