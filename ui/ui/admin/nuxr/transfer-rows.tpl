{* WP6B: transfer rows (newest first): table on >= 768px, cards on phone. *}
<div class="nuxr-adaptive is-lg">
<div class="nuxr-table-wrap">
    <table class="nuxr-table">
        <thead><tr><th>{Lang::T('Time (UTC)')}</th><th>{Lang::T('Reference')}</th><th>{Lang::T('Type')}</th><th>{Lang::T('From')}</th><th>{Lang::T('To')}</th><th>{Lang::T('Amount')}</th><th>{Lang::T('Status')}</th></tr></thead>
        <tbody>
            {foreach $rows as $t}
                <tr>
                    <td><bdi dir="ltr">{$t.created_at|escape}</bdi></td>
                    <td><a href="{Text::url('finance/transfer/', $t.id)}"><bdi dir="ltr" class="nuxr-mono">{$t.reference|escape}</bdi></a></td>
                    <td>{Lang::T(NuxrUi::transferType($t.operation_type))}</td>
                    <td>{if $t.from}<bdi dir="ltr">{NuxrUi::party($t.from)|escape}</bdi>{else}<span class="nuxr-muted">{Lang::T('Provider')}</span>{/if}</td>
                    <td>{if $t.to}<bdi dir="ltr">{NuxrUi::party($t.to)|escape}</bdi>{else}<span class="nuxr-muted">{Lang::T('Provider')}</span>{/if}</td>
                    <td><bdi dir="ltr">{NuxrUi::money($t.amount)|escape}</bdi></td>
                    <td>{if $t.status eq 'REVERSED'}{call name=nuxr_badge tone='neutral' label=Lang::T('Reversed') icon='fa-history'}{elseif $t.reverses_transfer_id}{call name=nuxr_badge tone='info' label=Lang::T('Reversal') icon='fa-undo'}{else}{call name=nuxr_badge tone='success' label=Lang::T('Completed')}{/if}</td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>
<ul class="nuxr-mlist">
    {foreach $rows as $t}
        <li>
            <a class="nuxr-mcard" href="{Text::url('finance/transfer/', $t.id)}">
                <span class="nuxr-mcard-main">
                    <span class="nuxr-mcard-title">{Lang::T(NuxrUi::transferType($t.operation_type))}</span>
                    <span class="nuxr-mcard-sub"><bdi dir="ltr">{if $t.from}{NuxrUi::party($t.from)|escape}{else}{Lang::T('Provider')}{/if} → {if $t.to}{NuxrUi::party($t.to)|escape}{else}{Lang::T('Provider')}{/if}</bdi></span>
                </span>
                <span class="nuxr-mcard-end"><bdi dir="ltr">{NuxrUi::money($t.amount)|escape}</bdi><bdi dir="ltr" class="nuxr-muted">{$t.created_at|truncate:10:'':true|escape}</bdi></span>
            </a>
        </li>
    {/foreach}
</ul>
</div>
