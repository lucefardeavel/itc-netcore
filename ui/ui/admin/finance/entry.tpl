{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: one immutable ledger entry with its links (transfer and its reversal, activation, reversed entry). *}
{assign var=e value=$nuxr_entry}
<section class="nuxr-card" aria-labelledby="nuxr-entry-t">
    <div class="nuxr-card-head"><h2 id="nuxr-entry-t">{Lang::T(NuxrUi::ledgerType($e.entry_type))|escape} <bdi dir="ltr" class="nuxr-muted">#{$e.id|escape}</bdi></h2>
        {if $e.direction eq 'CREDIT'}{call name=nuxr_badge tone='success' label=Lang::T('Credit') icon='fa-arrow-down'}{else}{call name=nuxr_badge tone='warning' label=Lang::T('Debit') icon='fa-arrow-up'}{/if}</div>
    <div class="nuxr-card-body">
        <dl class="nuxr-kv">
            <div><dt>{Lang::T('Wallet')}</dt><dd><a href="{Text::url('finance/wallet/', $e.owner.id)}">{$e.owner.fullname|escape}</a> <bdi dir="ltr" class="nuxr-muted">{$e.owner.username|escape}</bdi></dd></div>
            <div><dt>{Lang::T('Amount')}</dt><dd><strong><bdi dir="ltr">{if $e.direction eq 'CREDIT'}+{else}−{/if}{NuxrUi::money($e.amount)|escape}</bdi></strong></dd></div>
            <div><dt>{Lang::T('Balance before')} → {Lang::T('after')}</dt><dd><bdi dir="ltr">{NuxrUi::money($e.balance_before)|escape} → {NuxrUi::money($e.balance_after)|escape}</bdi></dd></div>
            <div><dt>{Lang::T('Time (UTC)')}</dt><dd><bdi dir="ltr">{$e.created_at|escape}</bdi></dd></div>
            {if $e.counterparty}<div><dt>{Lang::T('Counterparty')}</dt><dd><bdi dir="ltr">{NuxrUi::party($e.counterparty)|escape}</bdi></dd></div>{/if}
            {if $e.operator}<div><dt>{Lang::T('By')}</dt><dd><bdi dir="ltr">{NuxrUi::party($e.operator)|escape}</bdi></dd></div>{/if}
            {if $e.reason}<div><dt>{Lang::T('Reason')}</dt><dd>{$e.reason|escape}</dd></div>{/if}
            {if $e.activation_reference}<div><dt>{Lang::T('Activation')}</dt><dd><a href="{Text::url('activation/view/', $e.activation_reference)}"><bdi dir="ltr" class="nuxr-mono">{$e.activation_reference|escape}</bdi></a></dd></div>{/if}
            {if $e.transfer}<div><dt>{Lang::T('Transfer')}</dt><dd><a href="{Text::url('finance/transfer/', $e.transfer.id)}"><bdi dir="ltr" class="nuxr-mono">{$e.transfer.reference|escape}</bdi></a> · {Lang::T(NuxrUi::transferType($e.transfer.operation_type))|escape}{if $e.transfer.status eq 'REVERSED'} · {Lang::T('Reversed')}{/if}</dd></div>{/if}
            {if $e.reverses_entry_id}<div><dt>{Lang::T('Reverses entry')}</dt><dd><a href="{Text::url('finance/entry/', $e.reverses_entry_id)}"><bdi dir="ltr">#{$e.reverses_entry_id|escape}</bdi></a></dd></div>{/if}
            {if $e.reversed_by_entry_id}<div><dt>{Lang::T('Reversed by entry')}</dt><dd><a href="{Text::url('finance/entry/', $e.reversed_by_entry_id)}"><bdi dir="ltr">#{$e.reversed_by_entry_id|escape}</bdi></a></dd></div>{/if}
        </dl>
        <p class="nuxr-muted" style="margin-top:12px"><i class="fa fa-lock" aria-hidden="true"></i> {Lang::T('Ledger entries are permanent. Corrections are made by a new compensating entry.')}</p>
    </div>
</section>
{include file="sections/footer.tpl"}
