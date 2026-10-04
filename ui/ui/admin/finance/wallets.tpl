{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: scoped wallet list with server-side filters, pagination and CSV export (same scope and query). *}
<div class="nuxr-page-head">
    <span class="nuxr-sub" role="status"><strong><bdi dir="ltr">{$nuxr_list.total|escape}</bdi></strong> {Lang::T('resellers')}</span>
    <a class="nuxr-btn" href="{$nuxr_csv|escape}"><i class="fa fa-download" aria-hidden="true"></i>{Lang::T('Export CSV')}</a>
</div>
<form class="nuxr-card nuxr-card-body nuxr-form" method="get" action="{$app_url}/" role="search">
    <input type="hidden" name="_route" value="finance/wallets">
    <div class="nuxr-filters" style="margin-bottom:0">
        <label class="sr-only" for="nuxr-w-q">{Lang::T('Search resellers')}</label>
        <input id="nuxr-w-q" class="form-control nuxr-input" style="flex:1 1 220px" type="search" name="q" value="{$nuxr_filters.q|default:''|escape}" maxlength="64" placeholder="{Lang::T('Username or name')}">
        <label class="sr-only" for="nuxr-w-status">{Lang::T('Account status')}</label>
        <select id="nuxr-w-status" name="status" class="form-control nuxr-input" style="width:auto">
            <option value="">{Lang::T('Any status')}</option>
            {foreach ['Active', 'Inactive'] as $s}<option value="{$s|escape}" {if ($nuxr_filters.status|default:'') eq $s}selected{/if}>{Lang::T($s)|escape}</option>{/foreach}
        </select>
        <label class="sr-only" for="nuxr-w-wallet">{Lang::T('Wallet')}</label>
        <select id="nuxr-w-wallet" name="wallet" class="form-control nuxr-input" style="width:auto">
            <option value="">{Lang::T('Any wallet state')}</option>
            {foreach ['with' => 'Has a wallet', 'none' => 'No wallet yet', 'negative' => 'Using the debt line', 'exhausted' => 'No available credit'] as $k => $label}
                <option value="{$k|escape}" {if ($nuxr_filters.wallet|default:'') eq $k}selected{/if}>{Lang::T($label)|escape}</option>{/foreach}
        </select>
        <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i>{Lang::T('Search')}</button>
    </div>
</form>
<section class="nuxr-card">
    {if $nuxr_list.rows}
        {include file="admin/finance/wallet-rows.tpl" rows=$nuxr_list.rows}
        {include file="admin/nuxr/list-pager.tpl" p=$nuxr_list pager_url=$nuxr_base}
    {else}<div class="nuxr-empty"><i class="fa fa-credit-card" aria-hidden="true"></i><strong>{Lang::T('No resellers match these filters.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
