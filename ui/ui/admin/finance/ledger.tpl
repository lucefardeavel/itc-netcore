{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: one wallet's immutable ledger, newest first, server-side filters / pages; CSV uses the same query (date range
   of at most 366 days, at most 20,000 rows). *}
{assign var=o value=$nuxr_ledger.owner}
<div class="nuxr-page-head">
    <span class="nuxr-sub" role="status"><a href="{Text::url('finance/wallet/', $o.id)}">{$o.fullname|escape}</a> · <strong><bdi dir="ltr">{$nuxr_ledger.total|escape}</bdi></strong> {Lang::T('entries')}</span>
    <a class="nuxr-btn" href="{$nuxr_csv|escape}"><i class="fa fa-download" aria-hidden="true"></i>{Lang::T('Export CSV')}</a>
</div>
<div class="nuxr-alert is-info" role="note"><i class="fa fa-lock" aria-hidden="true"></i><div>{Lang::T('Ledger entries are permanent. Corrections are made by a new compensating entry.')}</div></div>
<form class="nuxr-card nuxr-card-body nuxr-form" method="get" action="{$app_url}/">
    <input type="hidden" name="_route" value="finance/ledger/{$o.id|escape}">
    <div class="nuxr-filters" style="margin-bottom:0">
        <label for="nuxr-l-from">{Lang::T('From (UTC)')}</label>
        <input id="nuxr-l-from" class="form-control nuxr-input ltr" dir="ltr" type="date" name="from" value="{$nuxr_filters.from|default:''|escape}" style="width:auto">
        <label for="nuxr-l-to">{Lang::T('To (UTC)')}</label>
        <input id="nuxr-l-to" class="form-control nuxr-input ltr" dir="ltr" type="date" name="to" value="{$nuxr_filters.to|default:''|escape}" style="width:auto">
        <label class="sr-only" for="nuxr-l-type">{Lang::T('Type')}</label>
        <select id="nuxr-l-type" name="type" class="form-control nuxr-input" style="width:auto">
            <option value="">{Lang::T('Any type')}</option>
            {foreach NuxrUi::LEDGER_TYPE as $k => $label}<option value="{$k|escape}" {if ($nuxr_filters.type|default:'') eq $k}selected{/if}>{Lang::T($label)}</option>{/foreach}
        </select>
        <label class="sr-only" for="nuxr-l-dir">{Lang::T('Direction')}</label>
        <select id="nuxr-l-dir" name="direction" class="form-control nuxr-input" style="width:auto">
            <option value="">{Lang::T('Credit and debit')}</option>
            <option value="CREDIT" {if ($nuxr_filters.direction|default:'') eq 'CREDIT'}selected{/if}>{Lang::T('Credit')}</option>
            <option value="DEBIT" {if ($nuxr_filters.direction|default:'') eq 'DEBIT'}selected{/if}>{Lang::T('Debit')}</option>
        </select>
        <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-filter" aria-hidden="true"></i>{Lang::T('Apply')}</button>
    </div>
</form>
<section class="nuxr-card">
    {if $nuxr_ledger.rows}
        {include file="admin/nuxr/ledger-rows.tpl" rows=$nuxr_ledger.rows}
        {include file="admin/nuxr/list-pager.tpl" p=$nuxr_ledger pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><i class="fa fa-list" aria-hidden="true"></i><strong>{Lang::T('No ledger entries match these filters.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
