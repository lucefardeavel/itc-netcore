{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B (OD-2): administrative accounting operation - an explicit Credit Adjustment or Debit Adjustment of an existing
   wallet (never "edit balance"; never opens a wallet). Reason and generated reference are permanent. *}
{assign var=w value=$nuxr_form.wallet}
{assign var=op value=$nuxr_form.op}
<section class="nuxr-card nuxr-op is-admin" aria-labelledby="nuxr-adj-t">
    <div class="nuxr-card-head"><h2 id="nuxr-adj-t"><i class="fa {if $op eq 'credit'}fa-plus-square{else}fa-minus-square{/if}" aria-hidden="true"></i>{if $op eq 'credit'}{Lang::T('Credit adjustment')}{else}{Lang::T('Debit adjustment')}{/if}</h2>
        {call name=nuxr_badge tone='warning' label=Lang::T('Administrative accounting operation') icon='fa-balance-scale'}</div>
    <div class="nuxr-card-body">
        {if $nuxr_error|default:''}<div class="nuxr-alert is-danger" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i><div>{$nuxr_error|escape}</div></div>{/if}
        <p>{Lang::T('Reseller')}: <strong>{$w.fullname|escape}</strong> <bdi dir="ltr" class="nuxr-muted">{$w.username|escape}</bdi></p>
        {include file="admin/nuxr/wallet-money.tpl" w=$w}
        <p class="nuxr-muted">{Lang::T('A correction of the wallet by a new ledger entry. It is not provider funding and it never opens a wallet.')}</p>
        <form method="post" action="{Text::url('finance/adjust-review')}" class="nuxr-form nuxr-fields">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <input type="hidden" name="manager_id" value="{$w.id|escape}">
            <input type="hidden" name="op" value="{$op|escape}">
            <label for="nuxr-amount">{Lang::T('Amount')} <span aria-hidden="true">*</span></label>
            <input id="nuxr-amount" name="amount" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" autocomplete="off" required maxlength="20" value="{$nuxr_values.amount|default:''|escape}" placeholder="0.00">
            <label for="nuxr-reason">{Lang::T('Reason')} <span aria-hidden="true">*</span></label>
            <input id="nuxr-reason" name="reason" class="form-control nuxr-input" required minlength="3" maxlength="255" value="{$nuxr_values.reason|default:''|escape}">
            <label for="nuxr-ext">{Lang::T('External reference (optional)')}</label>
            <input id="nuxr-ext" name="external_reference" class="form-control nuxr-input ltr" dir="ltr" maxlength="100" value="{$nuxr_values.external_reference|default:''|escape}">
            <div class="nuxr-step-nav"><a class="nuxr-btn" href="{Text::url('finance/wallet/', $w.id)}">{Lang::T('Cancel')}</a>
                <button type="submit" class="nuxr-btn nuxr-btn-primary"><i class="fa fa-eye" aria-hidden="true"></i>{Lang::T('Review')}</button></div>
        </form>
    </div>
</section>
{include file="sections/footer.tpl"}
