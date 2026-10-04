{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B review step (no write). Figures are informational (the finance preview read model); the accepted finance service re-checks
   atomically when confirmed. The hidden fields are bound by the confirm token: any change is refused. *}
{assign var=r value=$nuxr_review}
{assign var=p value=$r.preview}
<section class="nuxr-card nuxr-op is-{$r.op|escape}" aria-labelledby="nuxr-review-t">
    <div class="nuxr-card-head"><h2 id="nuxr-review-t">
        {if $r.kind eq 'funding'}{if $r.op eq 'fund'}{Lang::T('Review provider funding')}{else}{Lang::T('Review provider withdrawal')}{/if}
        {elseif $r.kind eq 'send'}{Lang::T('Review transfer to child reseller')}
        {elseif $r.kind eq 'debt_limit'}{Lang::T('Review debt line change')}
        {elseif $r.kind eq 'adjust'}{if $r.op eq 'credit'}{Lang::T('Review credit adjustment')}{else}{Lang::T('Review debit adjustment')}{/if}
        {elseif $r.kind eq 'reverse'}{Lang::T('Review transfer reversal')}{/if}</h2>
        {if $r.kind eq 'debt_limit' || $r.kind eq 'adjust' || $r.kind eq 'reverse'}{call name=nuxr_badge tone='warning' label=Lang::T('Administrative accounting operation') icon='fa-balance-scale'}{/if}</div>
    <div class="nuxr-card-body">
        {if $p.hint|default:null}<div class="nuxr-alert is-warning" role="status"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
            <div>{Lang::T('With the current figures the system is likely to refuse this')}: {Lang::T(NuxrUi::errorText($p.hint))|escape}</div></div>{/if}
        {if !empty($r.blocked)}<div class="nuxr-alert is-info" role="status"><i class="fa fa-info-circle" aria-hidden="true"></i><div>{Lang::T($r.blocked)|escape}</div></div>{/if}
        {if $r.kind eq 'reverse'}
            {assign var=x value=$p}
            <p>{Lang::T('Original transfer')}: <bdi dir="ltr" class="nuxr-mono">{$x.reference|escape}</bdi> · {Lang::T(NuxrUi::transferType($x.operation_type))|escape} · <bdi dir="ltr">{NuxrUi::money($x.amount)|escape}</bdi></p>
            <p>{Lang::T('Compensating operation')}: <strong>{Lang::T(NuxrUi::transferType($x.compensating_type))|escape}</strong></p>
            <dl class="nuxr-kv">
                {if $x.debit}<div><dt>{Lang::T('Debited')} · {$x.debit.wallet.fullname|escape}</dt><dd>{Lang::T('Available credit')} <bdi dir="ltr">{NuxrUi::money($x.debit.available[0])|escape} → {NuxrUi::money($x.debit.available[1])|escape}</bdi></dd></div>{/if}
                {if $x.credit}<div><dt>{Lang::T('Credited')} · {$x.credit.wallet.fullname|escape}</dt><dd>{Lang::T('Balance')} <bdi dir="ltr">{NuxrUi::money($x.credit.balance[0])|escape} → {NuxrUi::money($x.credit.balance[1])|escape}</bdi></dd></div>{/if}
            </dl>
        {elseif $r.kind eq 'send'}
            <p class="nuxr-transfer-line"><strong>{Lang::T('You are sending')} <bdi dir="ltr">{NuxrUi::money($p.amount)|escape}</bdi></strong>
                {Lang::T('from')} <strong>{$r.from.fullname|escape}</strong> <bdi dir="ltr" class="nuxr-muted">{$r.from.username|escape}</bdi>
                {Lang::T('to')} <strong>{$r.to.fullname|escape}</strong> <bdi dir="ltr" class="nuxr-muted">{$r.to.username|escape}</bdi>.
                {Lang::T('This is a balance transfer, not a sale.')}</p>
            <dl class="nuxr-kv">
                <div><dt>{Lang::T('From - available credit')}</dt><dd><bdi dir="ltr">{NuxrUi::money($p.from_available[0])|escape} → {NuxrUi::money($p.from_available[1])|escape}</bdi></dd></div>
                <div><dt>{Lang::T('To - balance')}</dt><dd><bdi dir="ltr">{NuxrUi::money($p.to_balance[0])|escape} → {NuxrUi::money($p.to_balance[1])|escape}</bdi>{if $p.opens_wallet} · {Lang::T('opens its wallet')}{/if}</dd></div>
            </dl>
        {else}
            <p>{Lang::T('Reseller')}: <strong>{$r.wallet.fullname|escape}</strong> <bdi dir="ltr" class="nuxr-muted">{$r.wallet.username|escape}</bdi></p>
            <dl class="nuxr-kv">
                {if $r.kind eq 'debt_limit'}
                    <div><dt>{Lang::T('Balance')}</dt><dd><bdi dir="ltr">{NuxrUi::money($p.balance)|escape}</bdi></dd></div>
                    <div><dt>{Lang::T('Reserved for activations in progress')}</dt><dd><bdi dir="ltr">{NuxrUi::money($p.reserved)|escape}</bdi></dd></div>
                    <div><dt>{Lang::T('Debt line')}</dt><dd><bdi dir="ltr">{NuxrUi::money($p.debt_limit[0])|escape} → {NuxrUi::money($p.debt_limit[1])|escape}</bdi>{if $p.opens_wallet} · {Lang::T('opens the wallet')}{/if}</dd></div>
                {else}
                    <div><dt>{Lang::T('Amount')}</dt><dd><strong><bdi dir="ltr">{NuxrUi::money($p.amount)|escape}</bdi></strong></dd></div>
                    <div><dt>{Lang::T('Balance')}</dt><dd><bdi dir="ltr">{NuxrUi::money($p.balance[0])|escape} → {NuxrUi::money($p.balance[1])|escape}</bdi>{if $p.opens_wallet|default:false} · {Lang::T('opens the wallet')}{/if}</dd></div>
                {/if}
                <div><dt>{Lang::T('Available credit')}</dt><dd><bdi dir="ltr">{NuxrUi::money($p.available[0])|escape} → {NuxrUi::money($p.available[1])|escape}</bdi></dd></div>
            </dl>
        {/if}
        <dl class="nuxr-kv" style="margin-top:10px">
            <div><dt>{Lang::T('Reason')}</dt><dd>{$r.fields.reason|escape}</dd></div>
            {if $r.fields.external|default:''}<div><dt>{Lang::T('External reference')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$r.fields.external|escape}</bdi></dd></div>{/if}
            {if $r.fields.reference|default:''}<div><dt>{Lang::T('Operation reference')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$r.fields.reference|escape}</bdi></dd></div>{/if}
        </dl>
        <p class="nuxr-muted"><i class="fa fa-info-circle" aria-hidden="true"></i> {Lang::T('These figures are informational. The server re-checks the credit and every rule when you confirm.')}</p>
        <form method="post" action="{Text::url($r.post_route|default:'')}" class="nuxr-form" style="margin-top:12px">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            {foreach $r.fields as $k => $v}<input type="hidden" name="{$k|escape}" value="{$v|escape}">{/foreach}
            <input type="hidden" name="token" value="{$r.token|escape}">
            {if $r.typed}
                <label for="nuxr-typed">{if $r.kind eq 'reverse'}{Lang::T('Type the original transfer reference to confirm')}{else}{Lang::T('Type the reseller username to confirm')}{/if}: <bdi dir="ltr" class="nuxr-mono">{$r.typed|escape}</bdi></label>
                <input id="nuxr-typed" name="confirm" class="form-control nuxr-input ltr" dir="ltr" autocomplete="off" required data-nuxr-match="{$r.typed|escape}">
            {/if}
            <div class="nuxr-step-nav"><a class="nuxr-btn" href="{Text::url('finance')}">{Lang::T('Cancel')}</a>
                {if empty($r.blocked)}<button type="submit" class="nuxr-btn {if $r.op eq 'withdraw' || $r.op eq 'debit' || $r.kind eq 'reverse'}nuxr-btn-danger-solid{else}nuxr-btn-primary{/if}"{if $r.typed} data-nuxr-match-submit disabled{/if}>
                    <i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Confirm')}</button>{/if}</div>
        </form>
    </div>
</section>
{include file="sections/footer.tpl"}
