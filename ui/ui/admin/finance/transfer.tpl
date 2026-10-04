{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: one transfer (operation record) with its ledger legs inside the actor's scope and its reversal links. *}
{assign var=t value=$nuxr_transfer}
<section class="nuxr-card" aria-labelledby="nuxr-trf-t">
    <div class="nuxr-card-head"><h2 id="nuxr-trf-t">{Lang::T(NuxrUi::transferType($t.operation_type))}</h2>
        {if $t.status eq 'REVERSED'}{call name=nuxr_badge tone='neutral' label=Lang::T('Reversed') icon='fa-history'}{elseif $t.reverses_transfer_id}{call name=nuxr_badge tone='info' label=Lang::T('Reversal') icon='fa-undo'}{else}{call name=nuxr_badge tone='success' label=Lang::T('Completed')}{/if}</div>
    <div class="nuxr-card-body">
        <dl class="nuxr-kv">
            <div><dt>{Lang::T('Reference')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$t.reference|escape}</bdi></dd></div>
            <div><dt>{Lang::T('Amount')}</dt><dd><strong><bdi dir="ltr">{NuxrUi::money($t.amount)|escape}</bdi></strong></dd></div>
            <div><dt>{Lang::T('From')}</dt><dd>{if $t.from}<bdi dir="ltr">{NuxrUi::party($t.from)|escape}</bdi>{else}{Lang::T('Provider')}{/if}</dd></div>
            <div><dt>{Lang::T('To')}</dt><dd>{if $t.to}<bdi dir="ltr">{NuxrUi::party($t.to)|escape}</bdi>{else}{Lang::T('Provider')}{/if}</dd></div>
            <div><dt>{Lang::T('By')}</dt><dd><bdi dir="ltr">{NuxrUi::party($t.operator)|escape}</bdi></dd></div>
            <div><dt>{Lang::T('Reason')}</dt><dd>{$t.reason|escape}</dd></div>
            {if $t.external_reference}<div><dt>{Lang::T('External reference')}</dt><dd><bdi dir="ltr" class="nuxr-mono">{$t.external_reference|escape}</bdi></dd></div>{/if}
            <div><dt>{Lang::T('Time (UTC)')}</dt><dd><bdi dir="ltr">{$t.created_at|escape}</bdi></dd></div>
            {if $t.reverses_transfer_id}<div><dt>{Lang::T('Reverses transfer')}</dt><dd><a href="{Text::url('finance/transfer/', $t.reverses_transfer_id)}"><bdi dir="ltr">#{$t.reverses_transfer_id|escape}</bdi></a></dd></div>{/if}
            {if $t.reversed_by}<div><dt>{Lang::T('Reversed by')}</dt><dd><a href="{Text::url('finance/transfer/', $t.reversed_by.id)}"><bdi dir="ltr" class="nuxr-mono">{$t.reversed_by.reference|escape}</bdi></a></dd></div>{/if}
        </dl>
    </div>
</section>
<section class="nuxr-card" aria-labelledby="nuxr-legs">
    <div class="nuxr-card-head"><h2 id="nuxr-legs">{Lang::T('Ledger entries')}</h2></div>
    {if $t.legs}{include file="admin/nuxr/ledger-rows.tpl" rows=$t.legs}
    {else}<div class="nuxr-empty"><strong>{Lang::T('No entries in your scope.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
