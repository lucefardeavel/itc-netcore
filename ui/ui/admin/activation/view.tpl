{include file="sections/header.tpl"}
{* WP5A: activation state and timeline (backend states only; the activation UX is WP6A). *}
<div class="row">
    <div class="col-md-10">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Activation')} {$nuxr_activation['reference']|escape}</div>
            <div class="panel-body">
                <table class="table table-condensed">
                    <tr><th>{Lang::T('Status')}</th><td><strong>{$nuxr_activation['status']|escape}</strong></td></tr>
                    <tr><th>{Lang::T('Action')}</th><td>{$nuxr_activation['action']|escape}</td></tr>
                    <tr><th>{Lang::T('Customer ID')}</th><td>{$nuxr_activation['customer_id']}</td></tr>
                    <tr><th>{Lang::T('Plan ID')}</th><td>{$nuxr_activation['plan_id']}</td></tr>
                    {if $nuxr_activation['error_code']}<tr><th>{Lang::T('Error')}</th><td>{$nuxr_activation['error_code']|escape}: {$nuxr_activation['error_message']|escape}</td></tr>{/if}
                    {if $nuxr_activation['legacy_invoice']}<tr><th>{Lang::T('Invoice')}</th><td>{$nuxr_activation['legacy_invoice']|escape}</td></tr>{/if}
                </table>
                {if $nuxr_activation['status'] eq 'RECONCILIATION_REQUIRED' && $nuxr_activation['error_code'] eq 'EXTERNAL_STATE_AMBIGUOUS'}
                    <div class="alert alert-danger">{Lang::T('The current RADIUS state differs from both the activation target and the state captured before provisioning. No funds were released automatically; the reservation remains held. Operator review is required: roll back to restore the captured prior state, or correct RADIUS and reconcile again.')}</div>
                {elseif $nuxr_activation['status'] eq 'ROLLBACK_PENDING' && ($nuxr_activation['error_code'] eq 'PRIOR_SECRET_UNRESTORABLE' || $nuxr_activation['error_code'] eq 'PRIOR_STATE_NOT_CAPTURED')}
                    <div class="alert alert-danger">{Lang::T('The exact prior authentication state cannot be restored automatically. RADIUS was not changed, nothing was released and the reservation remains held. Operator review is required.')}</div>
                {elseif $nuxr_activation['status'] eq 'RECONCILIATION_REQUIRED'}
                    <div class="alert alert-warning">{Lang::T('The outcome is being checked. Do not repeat the activation; an administrator reconciles it. The reserved funds stay held until then.')}</div>
                {/if}
                {if $nuxr_can_reconcile && ($nuxr_activation['status'] eq 'RECONCILIATION_REQUIRED' || $nuxr_activation['status'] eq 'VERIFIED' || $nuxr_activation['status'] eq 'ROLLBACK_PENDING')}
                    <form method="post" action="{Text::url('activation/reconcile/', $nuxr_activation['reference'])}" style="display:inline">
                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                        <button class="btn btn-warning btn-sm" type="submit">{Lang::T('Reconcile')}</button>
                    </form>
                    <form method="post" action="{Text::url('activation/rollback/', $nuxr_activation['reference'])}" style="display:inline">
                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                        <button class="btn btn-danger btn-sm" type="submit" onclick="return ask(this, '{Lang::T('Restore the RADIUS state captured before this activation and, once verified, release the reserved funds?')}')">{Lang::T('Roll back')}</button>
                    </form>
                {/if}
                {if $nuxr_can_reverse && ($nuxr_activation['status'] eq 'COMPLETED' || $nuxr_activation['status'] eq 'REVERSAL_PENDING')}
                    {* WP5B (OD-11): Admin / SuperAdmin reversal of a settled activation; a reason is required *}
                    <form method="post" action="{Text::url('activation/reverse/', $nuxr_activation['reference'])}" class="form-inline" style="margin-top:8px">
                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                        <input type="text" name="reason" class="form-control input-sm" required minlength="3" maxlength="255" placeholder="{Lang::T('Reason')}">
                        <button class="btn btn-danger btn-sm" type="submit" onclick="return ask(this, '{Lang::T('Reverse this completed activation: restore the previous RADIUS state, credit the reseller and claw back the commissions?')}')">{if $nuxr_activation['status'] eq 'REVERSAL_PENDING'}{Lang::T('Resume reversal')}{else}{Lang::T('Reverse')}{/if}</button>
                    </form>
                {/if}
                <h4>{Lang::T('Timeline')}</h4>
                <table class="table table-bordered table-condensed">
                    <thead><tr><th>#</th><th>{Lang::T('Event')}</th><th>{Lang::T('From')}</th><th>{Lang::T('To')}</th><th>{Lang::T('Result')}</th><th>{Lang::T('Error')}</th><th>{Lang::T('Time (UTC)')}</th></tr></thead>
                    <tbody>
                        {foreach $nuxr_events as $e}
                            <tr><td>{$e['sequence_no']}</td><td>{$e['event_type']|escape}</td><td>{$e['from_state']|escape}</td><td>{$e['to_state']|escape}</td>
                                <td>{$e['provisioning_result']|escape}</td><td>{$e['error_code']|escape}</td><td>{$e['created_at']|escape}</td></tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
{include file="sections/footer.tpl"}
