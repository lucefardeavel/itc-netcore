{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: Admin provider funding / withdrawal (wallet.fund_withdraw). Visually and verbally distinct from a reseller
   transfer. Values are presentation only: the review step re-reads the wallet and the accepted finance service
   re-checks everything on execute. *}
{assign var=w value=$nuxr_form.wallet}
{assign var=op value=$nuxr_form.op}
<section class="nuxr-card nuxr-op is-{$op|escape}" aria-labelledby="nuxr-fund-t">
    <div class="nuxr-card-head"><h2 id="nuxr-fund-t"><i class="fa {if $op eq 'fund'}fa-plus-circle{else}fa-minus-circle{/if}" aria-hidden="true"></i>
        {if $op eq 'fund'}{Lang::T('Provider funding (adds credit)')}{else}{Lang::T('Provider withdrawal (removes credit)')}{/if}</h2></div>
    <div class="nuxr-card-body">
        {if $nuxr_error|default:''}<div class="nuxr-alert is-danger" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i><div>{$nuxr_error|escape}</div></div>{/if}
        <p>{Lang::T('Reseller')}: <strong>{$w.fullname|escape}</strong> <bdi dir="ltr" class="nuxr-muted">{$w.username|escape}</bdi></p>
        {include file="admin/nuxr/wallet-money.tpl" w=$w}
        <form method="post" action="{Text::url('finance/funding-review')}" class="nuxr-form nuxr-fields" style="margin-top:14px">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <input type="hidden" name="manager_id" value="{$w.id|escape}">
            <input type="hidden" name="op" value="{$op|escape}">
            <label for="nuxr-amount">{Lang::T('Amount')} <span aria-hidden="true">*</span></label>
            <input id="nuxr-amount" name="amount" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" autocomplete="off" required maxlength="20"
                value="{$nuxr_values.amount|default:''|escape}" placeholder="0.00" aria-describedby="nuxr-amount-help">
            <small id="nuxr-amount-help" class="nuxr-muted">{Lang::T('Digits with up to two decimals, no thousands separator.')}</small>
            <label for="nuxr-reason">{Lang::T('Reason')} <span aria-hidden="true">*</span></label>
            <input id="nuxr-reason" name="reason" class="form-control nuxr-input" required minlength="3" maxlength="255" value="{$nuxr_values.reason|default:''|escape}" aria-describedby="nuxr-reason-help">
            <small id="nuxr-reason-help" class="nuxr-muted">{Lang::T('The reason is recorded permanently and is visible to the reseller in its ledger.')}</small>
            <label for="nuxr-ext">{Lang::T('External reference (optional)')}</label>
            <input id="nuxr-ext" name="external_reference" class="form-control nuxr-input ltr" dir="ltr" maxlength="100" value="{$nuxr_values.external_reference|default:''|escape}" placeholder="{Lang::T('Receipt or bank reference')}">
            <div class="nuxr-step-nav"><a class="nuxr-btn" href="{Text::url('finance/wallet/', $w.id)}">{Lang::T('Cancel')}</a>
                <button type="submit" class="nuxr-btn nuxr-btn-primary"><i class="fa fa-eye" aria-hidden="true"></i>{Lang::T('Review')}</button></div>
        </form>
    </div>
</section>
{include file="sections/footer.tpl"}
