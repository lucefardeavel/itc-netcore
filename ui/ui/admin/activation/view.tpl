{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6A activation status: persisted backend state only (ActivationReadService, display fields). Progress is the event
   timeline, never simulated. "Released" / "refunded" only when the reservation proves it. UNKNOWN / reconciliation:
   do not repeat. Operator actions (Admin / SuperAdmin) are the accepted WP5A / WP5B routes behind confirmations. *}
{assign var=a value=$nuxr_activation}
{assign var=st value=$a.status}
{assign var=inflight value=!$a.terminal && !in_array($st, ['RECONCILIATION_REQUIRED', 'ROLLBACK_PENDING', 'REVERSAL_PENDING'])}

<div id="nuxr-act-status" {if $inflight}data-nuxr-poll{/if}>
    <section class="nuxr-card">
        <div class="nuxr-card-body" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;justify-content:space-between">
            <div>
                <div class="nuxr-muted" style="font-size:13px">{Lang::T(NuxrUi::actionLabel($a.action))} · <bdi dir="ltr" class="nuxr-mono">{$a.reference|escape}</bdi></div>
                <div style="font-size:18px;font-weight:700">{$a.customer_fullname|escape} <span class="nuxr-muted" style="font-weight:400">· {$a.plan_name|escape}</span></div>
            </div>
            {call name=nuxr_state state=$st}
        </div>
        <div class="nuxr-card-body" style="border-top:1px solid var(--nx-border)">
            <ol class="nuxr-stepper" aria-label="{Lang::T('Progress')}" style="margin:0">
                {foreach $nuxr_progress as $s}
                    <li class="is-{$s.state|escape}"{if $s.state eq 'current'} aria-current="step"{/if}>{Lang::T($s.label)}{if $s.state eq 'failed'} <span class="sr-only">({Lang::T('stopped')})</span>{/if}</li>
                {/foreach}
            </ol>
        </div>
    </section>

    {* outcome: one plain-language message per persisted state *}
    {if $inflight}
        <div class="nuxr-alert is-info" role="status"><i class="fa fa-spinner" aria-hidden="true"></i>
            <div><strong>{Lang::T('Processing')}</strong>{Lang::T('Do not repeat this activation. This page updates when the state changes.')}
                {if $a.credit eq 'reserved'}<br>{Lang::T('The cost is reserved from the reseller credit; it is not charged yet.')}{/if}</div></div>
    {elseif $st eq 'COMPLETED'}
        <div class="nuxr-alert is-success" role="status"><i class="fa fa-check-circle" aria-hidden="true"></i>
            <div><strong>{Lang::T('Completed')}</strong>{if $a.period_expiry}{Lang::T('Service active until')} <bdi dir="ltr">{$a.period_expiry|escape}</bdi>.{/if}
                {if $a.credit eq 'charged'} {Lang::T('The wholesale cost was charged.')}{/if}</div></div>
    {elseif $st eq 'FAILED' || $st eq 'CANCELLED'}
        <div class="nuxr-alert is-danger" role="status"><i class="fa fa-times-circle" aria-hidden="true"></i>
            <div><strong>{if $st eq 'FAILED'}{Lang::T('Not activated')}{else}{Lang::T('Cancelled')}{/if}</strong>
                {if NuxrUi::errorText($a.error_code)}{Lang::T(NuxrUi::errorText($a.error_code))}{else}{Lang::T('The network did not apply the change.')}{/if}
                {if $a.credit eq 'released'}<br>{Lang::T('The reserved credit was released; nothing was charged.')}{/if}</div></div>
    {elseif $st eq 'RECONCILIATION_REQUIRED' && $a.error_code eq 'EXTERNAL_STATE_AMBIGUOUS'}
        <div class="nuxr-alert is-danger" role="alert"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
            <div><strong>{Lang::T('Operator review required')}</strong>{Lang::T('The current RADIUS state differs from both the activation target and the state captured before provisioning. No funds were released automatically; the reservation remains held. Operator review is required: roll back to restore the captured prior state, or correct RADIUS and reconcile again.')}</div></div>
    {elseif $st eq 'RECONCILIATION_REQUIRED'}
        <div class="nuxr-alert is-warning" role="alert"><i class="fa fa-question-circle" aria-hidden="true"></i>
            <div><strong>{Lang::T('Checking - reconciliation required')}</strong>{Lang::T('The outcome is being checked. Do not repeat the activation; an administrator reconciles it. The reserved funds stay held until then.')}</div></div>
    {elseif $st eq 'ROLLBACK_PENDING' && ($a.error_code eq 'PRIOR_SECRET_UNRESTORABLE' || $a.error_code eq 'PRIOR_STATE_NOT_CAPTURED')}
        <div class="nuxr-alert is-danger" role="alert"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
            <div><strong>{Lang::T('Operator review required')}</strong>{Lang::T('The exact prior authentication state cannot be restored automatically. RADIUS was not changed, nothing was released and the reservation remains held. Operator review is required.')}</div></div>
    {elseif $st eq 'ROLLBACK_PENDING'}
        <div class="nuxr-alert is-warning" role="alert"><i class="fa fa-undo" aria-hidden="true"></i>
            <div><strong>{Lang::T('Rollback pending')}</strong>{Lang::T('Restoring the previous network state. The reserved credit stays held until the rollback is verified.')}</div></div>
    {elseif $st eq 'ROLLED_BACK'}
        <div class="nuxr-alert is-info" role="status"><i class="fa fa-undo" aria-hidden="true"></i>
            <div><strong>{Lang::T('Rolled back')}</strong>{Lang::T('The previous network state was restored.')}
                {if $a.credit eq 'released'} {Lang::T('The reserved credit was released; nothing was charged.')}{/if}</div></div>
    {elseif $st eq 'REVERSAL_PENDING'}
        <div class="nuxr-alert is-warning" role="alert"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
            <div><strong>{Lang::T('Reversal pending')}</strong>{Lang::T('A reversal started and must be resumed by an administrator. Nothing has been refunded yet.')}</div></div>
    {elseif $st eq 'REVERSED'}
        <div class="nuxr-alert is-info" role="status"><i class="fa fa-history" aria-hidden="true"></i>
            <div><strong>{Lang::T('Reversed')}</strong>{Lang::T('The completed activation was reversed and the previous state restored.')}
                {if $a.credit eq 'refunded'} {Lang::T('The wholesale cost was refunded and the commissions were reversed.')}{/if}</div></div>
    {/if}
</div>

<div class="nuxr-grid nuxr-grid-360">
    <section class="nuxr-card" aria-labelledby="nuxr-act-details">
        <div class="nuxr-card-head"><h3 id="nuxr-act-details">{Lang::T('Details')}</h3></div>
        <div class="nuxr-card-body">
            <dl class="nuxr-kv">
                <div><dt>{Lang::T('Subscriber')}</dt><dd><a href="{Text::url('customers/view/', $a.customer_id)}">{$a.customer_fullname|escape}</a><br><bdi dir="ltr" class="nuxr-muted">{$a.customer_username|escape}</bdi></dd></div>
                <div><dt>{Lang::T('Plan')}</dt><dd>{$a.plan_name|escape}</dd></div>
                {if $a.period_expiry}<div><dt>{Lang::T('Service period')}</dt><dd><bdi dir="ltr">{$a.period_start|escape} → {$a.period_expiry|escape}</bdi></dd></div>{/if}
                {if $a.manager_username}<div><dt>{Lang::T('Paid by (reseller)')}</dt><dd><bdi dir="ltr">{$a.manager_username|escape}</bdi></dd></div>{/if}
                {if $a.actor_username}<div><dt>{Lang::T('Requested by')}</dt><dd><bdi dir="ltr">{$a.actor_username|escape}</bdi></dd></div>{/if}
                {if $a.legacy_invoice}<div><dt>{Lang::T('Invoice')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$a.legacy_invoice|escape}</bdi></dd></div>{/if}
                <div><dt>{Lang::T('Created (UTC)')}</dt><dd><bdi dir="ltr">{$a.created_at|truncate:19:'':true|escape}</bdi></dd></div>
            </dl>
            <div class="nuxr-money-grid" style="margin-top:12px">
                {if $a.wholesale}{call name=nuxr_money domain='wholesale' label=Lang::T('Wholesale - reseller cost') amount=$a.wholesale}{/if}
                {if $a.retail}{call name=nuxr_money domain='retail' label=Lang::T('Retail - customer price') amount=$a.retail}{/if}
            </div>
        </div>
        {if $nuxr_actions}
            <div class="nuxr-card-body nuxr-actions" style="border-top:1px solid var(--nx-border)">
                {* WP6B slice 0: only the operations the accepted primitives accept in this state (ActivationOperatorService) *}
                {if in_array('reconcile', $nuxr_actions)}
                    <form method="post" action="{Text::url('activation/reconcile/', $a.reference)}">
                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                        <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i>{Lang::T('Reconcile')}</button>
                    </form>
                {/if}
                {if in_array('rollback', $nuxr_actions)}
                    <button type="button" class="nuxr-btn nuxr-btn-danger" data-nuxr-open="nuxr-rollback-dialog"><i class="fa fa-undo" aria-hidden="true"></i>{if $st eq 'ROLLBACK_PENDING'}{Lang::T('Retry roll back')}{else}{Lang::T('Roll back')}{/if}</button>
                {/if}
                {if in_array('reverse', $nuxr_actions)}
                    <button type="button" class="nuxr-btn nuxr-btn-danger" data-nuxr-open="nuxr-reverse-dialog"><i class="fa fa-history" aria-hidden="true"></i>{if $st eq 'REVERSAL_PENDING'}{Lang::T('Resume reversal')}{else}{Lang::T('Reverse')}{/if}</button>
                {/if}
            </div>
        {/if}
    </section>

    <section class="nuxr-card" aria-labelledby="nuxr-act-timeline">
        <div class="nuxr-card-head"><h3 id="nuxr-act-timeline">{Lang::T('Timeline')}</h3><span class="nuxr-muted" style="font-size:13px">UTC</span></div>
        <div class="nuxr-card-body">
            <ol class="nuxr-timeline">
                {foreach $nuxr_events as $e}
                    {assign var=tone value=NuxrUi::tone($e.to_state)}
                    <li><span class="nuxr-timeline-dot is-{if $e.error_code}danger{elseif $tone eq 'processing'}info{else}{$tone}{/if}" aria-hidden="true"><i class="fa {if $e.error_code}fa-exclamation{else}fa-check{/if}"></i></span>
                        <div><strong>{Lang::T(NuxrUi::stateLabel($e.to_state))}</strong>{if $e.provisioning_result} · <span class="nuxr-muted">{$e.provisioning_result|escape}</span>{/if}
                            <time><bdi dir="ltr">{$e.created_at|truncate:19:'':true|escape}</bdi></time></div></li>
                {/foreach}
            </ol>
            <details>
                <summary class="nuxr-btn">{Lang::T('Technical details')}</summary>
                <div class="nuxr-table-wrap" style="display:block;overflow-x:auto;margin-top:8px">
                    <table class="nuxr-table">
                        <thead><tr><th>#</th><th>{Lang::T('Event')}</th><th>{Lang::T('From')}</th><th>{Lang::T('To')}</th><th>{Lang::T('Result')}</th><th>{Lang::T('Code')}</th></tr></thead>
                        <tbody>{foreach $nuxr_events as $e}<tr><td>{$e.sequence_no}</td><td><bdi dir="ltr">{$e.event_type|escape}</bdi></td><td><bdi dir="ltr">{$e.from_state|escape}</bdi></td>
                            <td><bdi dir="ltr">{$e.to_state|escape}</bdi></td><td>{$e.provisioning_result|escape}</td><td><bdi dir="ltr">{$e.error_code|escape}</bdi></td></tr>{/foreach}</tbody>
                    </table>
                </div>
                {if $a.error_code}<p class="nuxr-ref" style="margin-top:8px">{Lang::T('Reference code')}: <bdi dir="ltr">{$a.error_code|escape}</bdi></p>{/if}
            </details>
        </div>
    </section>
</div>

{include file="admin/nuxr/settlement-card.tpl" s=$nuxr_settlement}
{if in_array('rollback', $nuxr_actions)}
    <dialog id="nuxr-rollback-dialog" class="nuxr-sheet" aria-labelledby="nuxr-rollback-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-rollback-t">{Lang::T('Roll back')}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <form method="post" action="{Text::url('activation/rollback/', $a.reference)}">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <div class="nuxr-dialog-body"><div class="nuxr-alert is-danger"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <div>{Lang::T('Restore the RADIUS state captured before this activation and, once verified, release the reserved funds?')}
                    {if $st neq 'ROLLBACK_PENDING'}<br><strong>{Lang::T('Roll back is one-way: after a roll back attempt this activation can never be settled. The reserved funds stay held until the restore is verified.')}</strong>{/if}</div></div>
                <label for="nuxr-rollback-confirm" style="margin-top:10px">{Lang::T('Type the reference to confirm')}: <bdi dir="ltr" class="nuxr-mono">{$a.reference|escape}</bdi></label>
                <input id="nuxr-rollback-confirm" class="form-control nuxr-input ltr" dir="ltr" autocomplete="off" data-nuxr-match="{$a.reference|escape}">
            </div>
            <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close autofocus>{Lang::T('Cancel')}</button>
                <button type="submit" class="nuxr-btn nuxr-btn-danger-solid" data-nuxr-match-submit disabled>{if $st eq 'ROLLBACK_PENDING'}{Lang::T('Retry roll back')}{else}{Lang::T('Roll back')}{/if}</button></div>
        </form>
    </dialog>
{/if}
{if in_array('reverse', $nuxr_actions)}
    <dialog id="nuxr-reverse-dialog" class="nuxr-sheet" aria-labelledby="nuxr-reverse-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-reverse-t">{if $st eq 'REVERSAL_PENDING'}{Lang::T('Resume reversal')}{else}{Lang::T('Reverse')}{/if}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <form method="post" action="{Text::url('activation/reverse/', $a.reference)}" class="nuxr-form">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <div class="nuxr-dialog-body">
                <div class="nuxr-alert is-danger"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                    <div>{Lang::T('Reverse this completed activation: restore the previous RADIUS state, credit the reseller and claw back the commissions?')}</div></div>
                <label for="nuxr-reverse-reason">{Lang::T('Reason')} <span aria-hidden="true">*</span></label>
                <input id="nuxr-reverse-reason" name="reason" class="form-control nuxr-input" required minlength="3" maxlength="255">
                <label for="nuxr-reverse-confirm" style="margin-top:10px">{Lang::T('Type the reference to confirm')}: <bdi dir="ltr" class="nuxr-mono">{$a.reference|escape}</bdi></label>
                <input id="nuxr-reverse-confirm" class="form-control nuxr-input ltr" dir="ltr" autocomplete="off" data-nuxr-match="{$a.reference|escape}">
            </div>
            <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close autofocus>{Lang::T('Cancel')}</button>
                <button type="submit" class="nuxr-btn nuxr-btn-danger-solid" data-nuxr-match-submit disabled>{if $st eq 'REVERSAL_PENDING'}{Lang::T('Resume reversal')}{else}{Lang::T('Reverse')}{/if}</button></div>
        </form>
    </dialog>
{/if}
{include file="sections/footer.tpl"}
