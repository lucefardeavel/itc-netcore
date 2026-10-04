{* WP6B: immutable ledger rows (newest first): table on >= 768px, cards on phone. Direction is word + sign + icon,
   never colour alone. Parties outside the viewer's scope are role labels only (Format::party). *}
<div class="nuxr-table-wrap">
    <table class="nuxr-table">
        <thead><tr><th>{Lang::T('Time (UTC)')}</th><th>{Lang::T('Type')}</th><th>{Lang::T('Amount')}</th><th>{Lang::T('Balance before')} → {Lang::T('after')}</th><th>{Lang::T('Linked to')}</th><th>{Lang::T('Counterparty')}</th><th>{Lang::T('By')}</th><th>{Lang::T('Reason')}</th></tr></thead>
        <tbody>
            {foreach $rows as $e}
                <tr>
                    <td><a href="{Text::url('finance/entry/', $e.id)}"><bdi dir="ltr">{$e.created_at|escape}</bdi></a></td>
                    <td>{Lang::T(NuxrUi::ledgerType($e.entry_type))}</td>
                    <td class="nuxr-amount is-{$e.direction|lower|escape}"><i class="fa {if $e.direction eq 'CREDIT'}fa-arrow-down{else}fa-arrow-up{/if}" aria-hidden="true"></i>
                        <span class="nuxr-dir-word">{if $e.direction eq 'CREDIT'}{Lang::T('Credit')}{else}{Lang::T('Debit')}{/if}</span>
                        <bdi dir="ltr">{if $e.direction eq 'CREDIT'}+{else}−{/if}{NuxrUi::money($e.amount)|escape}</bdi></td>
                    <td><bdi dir="ltr" class="nuxr-muted">{NuxrUi::money($e.balance_before)|escape} → {NuxrUi::money($e.balance_after)|escape}</bdi></td>
                    <td>{if $e.activation_reference}<a href="{Text::url('activation/view/', $e.activation_reference)}"><bdi dir="ltr" class="nuxr-mono">{$e.activation_reference|escape}</bdi></a>
                        {elseif $e.transfer_id}<a href="{Text::url('finance/transfer/', $e.transfer_id)}">{Lang::T('Transfer')} <bdi dir="ltr">#{$e.transfer_id|escape}</bdi></a>
                        {elseif $e.reverses_entry_id}<a href="{Text::url('finance/entry/', $e.reverses_entry_id)}">{Lang::T('Reverses entry')} <bdi dir="ltr">#{$e.reverses_entry_id|escape}</bdi></a>{/if}</td>
                    <td>{if $e.counterparty}<bdi dir="ltr">{NuxrUi::party($e.counterparty)|escape}</bdi>{/if}</td>
                    <td>{if $e.operator}<bdi dir="ltr">{NuxrUi::party($e.operator)|escape}</bdi>{else}<span class="nuxr-muted">{if $e.actor_type eq 'SYSTEM'}{Lang::T('System')}{elseif $e.actor_type eq 'CRON'}{Lang::T('Scheduled task')}{elseif $e.actor_type eq 'CUSTOMER'}{Lang::T('Customer')}{else}{Lang::T('Staff')}{/if}</span>{/if}</td>
                    <td>{$e.reason|escape}</td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>
<ul class="nuxr-mlist">
    {foreach $rows as $e}
        <li>
            <a class="nuxr-mcard" href="{Text::url('finance/entry/', $e.id)}">
                <span class="nuxr-mcard-main">
                    <span class="nuxr-mcard-title">{Lang::T(NuxrUi::ledgerType($e.entry_type))}</span>
                    <span class="nuxr-mcard-sub"><bdi dir="ltr">{$e.created_at|truncate:16:'':true|escape}</bdi>{if $e.reason} · {$e.reason|truncate:40|escape}{/if}</span>
                </span>
                <span class="nuxr-mcard-end nuxr-amount is-{$e.direction|lower|escape}"><span class="nuxr-dir-word">{if $e.direction eq 'CREDIT'}{Lang::T('Credit')}{else}{Lang::T('Debit')}{/if}</span>
                    <bdi dir="ltr">{if $e.direction eq 'CREDIT'}+{else}−{/if}{NuxrUi::money($e.amount)|escape}</bdi></span>
            </a>
        </li>
    {/foreach}
</ul>
