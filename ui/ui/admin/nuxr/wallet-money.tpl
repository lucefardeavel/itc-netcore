{* WP6B: a wallet's money block from the finance read model (server strings; never computed here). An absent wallet is
   shown as "no wallet yet", never as an established zero balance. *}
{if !$w.exists}
    <div class="nuxr-alert is-info" role="status"><i class="fa fa-info-circle" aria-hidden="true"></i>
        <div><strong>{Lang::T('No wallet yet')}</strong><br>{Lang::T('A wallet opens with the first provider funding, a positive debt line, or a transfer from the parent reseller.')}</div></div>
{/if}
<div class="nuxr-money-grid">
    {call name=nuxr_money domain='credit' label=Lang::T('Available credit') amount=$w.available note=Lang::T('Balance + debt line - reserved')}
    {if $w.balance_negative}{call name=nuxr_money domain='credit' label=Lang::T('Balance') amount=$w.balance note=Lang::T('Using the debt line')}
    {else}{call name=nuxr_money domain='credit' label=Lang::T('Balance') amount=$w.balance}{/if}
    {call name=nuxr_money domain='credit' label=Lang::T('Debt line') amount=$w.debt_limit}
    {call name=nuxr_money domain='credit' label=Lang::T('Reserved for activations in progress') amount=$w.reserved}
</div>
