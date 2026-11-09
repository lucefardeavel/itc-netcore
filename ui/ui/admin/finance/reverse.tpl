{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B (OD-2): administrative reversal of ONE existing reversible transfer (no generic ledger reversal). The original
   is shown; the accepted finance service posts the compensating operation and keeps the original entries. *}
{assign var=p value=$nuxr_form.preview}
<section class="nuxr-card nuxr-op is-admin" aria-labelledby="nuxr-rev-t">
    <div class="nuxr-card-head"><h2 id="nuxr-rev-t"><i class="fa fa-history" aria-hidden="true"></i>{Lang::T('Transfer reversal')}</h2>
        {call name=nuxr_badge tone='warning' label=Lang::T('Administrative accounting operation') icon='fa-balance-scale'}</div>
    <div class="nuxr-card-body">
        {if $nuxr_error|default:''}<div class="nuxr-alert is-danger" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i><div>{$nuxr_error|escape}</div></div>{/if}
        <dl class="nuxr-kv">
            <div><dt>{Lang::T('Original transfer')}</dt><dd><a href="{Text::url('finance/transfer/', $p.transfer_id)}"><bdi dir="ltr" class="nuxr-mono">{$p.reference|escape}</bdi></a></dd></div>
            <div><dt>{Lang::T('Type')}</dt><dd>{Lang::T(NuxrUi::transferType($p.operation_type))|escape}</dd></div>
            <div><dt>{Lang::T('Amount')}</dt><dd><strong><bdi dir="ltr">{NuxrUi::money($p.amount)|escape}</bdi></strong></dd></div>
            <div><dt>{Lang::T('From')}</dt><dd>{if $p.from}<bdi dir="ltr">{NuxrUi::party($p.from)|escape}</bdi>{else}{Lang::T('Provider')}{/if}</dd></div>
            <div><dt>{Lang::T('To')}</dt><dd>{if $p.to}<bdi dir="ltr">{NuxrUi::party($p.to)|escape}</bdi>{else}{Lang::T('Provider')}{/if}</dd></div>
            <div><dt>{Lang::T('Time')}</dt><dd><bdi dir="ltr">{NuxrUi::localTime($p.created_at)|escape}</bdi></dd></div>
            <div><dt>{Lang::T('Original reason')}</dt><dd>{$p.reason|escape}</dd></div>
        </dl>
        {if $p.refusal}
            <div class="nuxr-alert is-info" role="status" style="margin-top:12px"><i class="fa fa-info-circle" aria-hidden="true"></i><div>{Lang::T(NuxrUi::errorText($p.refusal))|escape}</div></div>
        {else}
            <p style="margin-top:12px">{Lang::T('Compensating operation')}: <strong>{Lang::T(NuxrUi::transferType($p.compensating_type))|escape}</strong>. {Lang::T('The original entries stay unchanged; a new compensating entry is posted.')}</p>
            <form method="post" action="{Text::url('finance/reverse-review')}" class="nuxr-form nuxr-fields">
                <input type="hidden" name="csrf_token" value="{$csrf_token}">
                <input type="hidden" name="transfer_id" value="{$p.transfer_id|escape}">
                <label for="nuxr-reason">{Lang::T('Reason')} <span aria-hidden="true">*</span></label>
                <input id="nuxr-reason" name="reason" class="form-control nuxr-input" required minlength="3" maxlength="255" value="{$nuxr_values.reason|default:''|escape}">
                <div class="nuxr-step-nav"><a class="nuxr-btn" href="{Text::url('finance/transfer/', $p.transfer_id)}">{Lang::T('Cancel')}</a>
                    <button type="submit" class="nuxr-btn nuxr-btn-primary"><i class="fa fa-eye" aria-hidden="true"></i>{Lang::T('Review')}</button></div>
            </form>
        {/if}
    </div>
</section>
{include file="sections/footer.tpl"}
