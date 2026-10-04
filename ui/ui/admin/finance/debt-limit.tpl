{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B (OD-2): administrative accounting operation - the reseller's debt line (absolute value). Never "edit balance".
   The review shows the current and proposed debt line and the projected available credit; the accepted finance
   service refuses a decrease below the active exposure. *}
{assign var=w value=$nuxr_form.wallet}
<section class="nuxr-card nuxr-op is-admin" aria-labelledby="nuxr-dl-t">
    <div class="nuxr-card-head"><h2 id="nuxr-dl-t"><i class="fa fa-balance-scale" aria-hidden="true"></i>{Lang::T('Debt line')}</h2>
        {call name=nuxr_badge tone='warning' label=Lang::T('Administrative accounting operation') icon='fa-balance-scale'}</div>
    <div class="nuxr-card-body">
        {if $nuxr_error|default:''}<div class="nuxr-alert is-danger" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i><div>{$nuxr_error|escape}</div></div>{/if}
        <p>{Lang::T('Reseller')}: <strong>{$w.fullname|escape}</strong> <bdi dir="ltr" class="nuxr-muted">{$w.username|escape}</bdi></p>
        {include file="admin/nuxr/wallet-money.tpl" w=$w}
        <form method="post" action="{Text::url('finance/debt-limit-review')}" class="nuxr-form nuxr-fields" style="margin-top:14px">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <input type="hidden" name="manager_id" value="{$w.id|escape}">
            <label for="nuxr-limit">{Lang::T('New debt line')} <span aria-hidden="true">*</span></label>
            <input id="nuxr-limit" name="limit" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" autocomplete="off" required maxlength="20" value="{$nuxr_values.limit|default:$w.debt_limit|escape}">
            <label for="nuxr-reason">{Lang::T('Reason')} <span aria-hidden="true">*</span></label>
            <input id="nuxr-reason" name="reason" class="form-control nuxr-input" required minlength="3" maxlength="255" value="{$nuxr_values.reason|default:''|escape}">
            <div class="nuxr-step-nav"><a class="nuxr-btn" href="{Text::url('finance/wallet/', $w.id)}">{Lang::T('Cancel')}</a>
                <button type="submit" class="nuxr-btn nuxr-btn-primary"><i class="fa fa-eye" aria-hidden="true"></i>{Lang::T('Review')}</button></div>
        </form>
    </div>
</section>
{include file="sections/footer.tpl"}
