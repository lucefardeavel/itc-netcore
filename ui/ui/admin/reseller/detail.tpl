{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B reseller detail (web only): identity and state (Reseller\Domain\ResellerReadService), children and Sales,
   subscriber counts, the wallet card when wallet.read allows (finance read model through Backoffice). Status / move use
   the accepted WP2 actions behind confirmation dialogs; every action is re-authorized server-side. *}
{assign var=n value=$nuxr_node}
<section class="nuxr-card" aria-labelledby="nuxr-res-t">
    <div class="nuxr-card-head">
        <h2 id="nuxr-res-t">{call name=nuxr_initial who=$n.fullname} {$n.fullname|escape} <bdi dir="ltr" class="nuxr-muted">{$n.username|escape}</bdi></h2>
        {if $n.status neq 'Active'}{call name=nuxr_badge tone='danger' label=Lang::T('Inactive') icon='fa-ban'}{elseif $n.frozen}{call name=nuxr_badge tone='warning' label=Lang::T('Frozen by an upstream reseller') icon='fa-pause'}{else}{call name=nuxr_badge tone='success' label=Lang::T('Active')}{/if}
    </div>
    <div class="nuxr-card-body">
        <dl class="nuxr-kv">
            <div><dt>{Lang::T('Role')}</dt><dd>{if $n.user_type eq 'Agent'}{Lang::T('Reseller')}{else}{Lang::T('Sales')}{/if}</dd></div>
            <div><dt>{Lang::T('Parent reseller')}</dt><dd>{if $n.parent}{if $n.parent.id}<a href="{Text::url('reseller/detail/', $n.parent.id)}">{$n.parent.fullname|escape}</a>{else}{NuxrUi::party($n.parent)|escape}{/if}{else}{Lang::T('Top level')}{/if}</dd></div>
        </dl>
        {if $n.frozen}<div class="nuxr-alert is-warning" role="status" style="margin-top:12px"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
            <div>{Lang::T('This account or an upstream reseller is inactive; business changes are paused. Data stays visible.')}</div></div>{/if}
    </div>
    <div class="nuxr-card-body nuxr-actions" style="border-top:1px solid var(--nx-border)">
        {if $n.can_create_child}<a class="nuxr-btn" href="{Text::url('settings/users-add')}"><i class="fa fa-user-plus" aria-hidden="true"></i>{Lang::T('Add Agent / Sales')}</a>{/if}
        {if $n.can_status}<button type="button" class="nuxr-btn{if $n.status eq 'Active'} nuxr-btn-danger{/if}" data-nuxr-open="nuxr-status-dialog"><i class="fa {if $n.status eq 'Active'}fa-ban{else}fa-check{/if}" aria-hidden="true"></i>{if $n.status eq 'Active'}{Lang::T('Disable')}{else}{Lang::T('Reactivate')}{/if}</button>{/if}
        {if $n.can_move}<button type="button" class="nuxr-btn" data-nuxr-open="nuxr-move-dialog"><i class="fa fa-arrows" aria-hidden="true"></i>{Lang::T('Move')}</button>{/if}
        <a class="nuxr-btn" href="{Text::url('reseller/tree')}">{Lang::T('Reseller Tree')}</a>
    </div>
</section>

<section class="nuxr-card" aria-labelledby="nuxr-res-subs">
    <div class="nuxr-card-head"><h2 id="nuxr-res-subs">{if $n.user_type eq 'Agent'}{Lang::T('Subscribers owned')}{else}{Lang::T('Subscribers assigned')}{/if}</h2>
        <a href="{if $n.user_type eq 'Agent'}{Text::url('customers/list&filter=All&owner=', $n.id)}{else}{Text::url('customers/list&filter=All&sales=', $n.id)}{/if}">{Lang::T('All')}</a></div>
    <div class="nuxr-card-body">
        {if $n.user_type eq 'Agent'}{assign var=key value='owner'}{else}{assign var=key value='sales'}{/if}
        <div class="nuxr-stats">
            <a class="nuxr-stat" href="{Text::url('customers/list&filter=All&', $key, '=', $n.id)}"><span class="nuxr-stat-value"><bdi dir="ltr">{$n.services.total|escape}</bdi></span><span class="nuxr-stat-label">{Lang::T('All subscribers')}</span></a>
            <a class="nuxr-stat" href="{Text::url('customers/list&filter=All&service=active&', $key, '=', $n.id)}"><span class="nuxr-stat-value"><bdi dir="ltr">{$n.services.active|escape}</bdi></span><span class="nuxr-stat-label">{Lang::T('Active service')}</span></a>
            <a class="nuxr-stat{if $n.services.expiring} is-warning{/if}" href="{Text::url('customers/list&filter=All&service=expiring&', $key, '=', $n.id)}"><span class="nuxr-stat-value"><bdi dir="ltr">{$n.services.expiring|escape}</bdi></span><span class="nuxr-stat-label">{Lang::T('Expiring within 7 days')}</span></a>
            <a class="nuxr-stat" href="{Text::url('customers/list&filter=All&service=expired&', $key, '=', $n.id)}"><span class="nuxr-stat-value"><bdi dir="ltr">{$n.services.expired|escape}</bdi></span><span class="nuxr-stat-label">{Lang::T('Expired')}</span></a>
        </div>
        {if $n.user_type eq 'Agent' && $n.customers_subtree neq $n.customers_direct}<p class="nuxr-muted">{Lang::T('Including sub-resellers')}: <bdi dir="ltr">{$n.customers_subtree|escape}</bdi></p>{/if}
    </div>
</section>

{if $n.wallet}
    {assign var=w value=$n.wallet}
    <section class="nuxr-card" aria-labelledby="nuxr-res-wallet">
        <div class="nuxr-card-head"><h2 id="nuxr-res-wallet">{Lang::T('Credit')}</h2><a href="{Text::url('finance/wallet/', $w.id)}">{Lang::T('Open')}</a></div>
        <div class="nuxr-card-body">{include file="admin/nuxr/wallet-money.tpl" w=$w}</div>
        <div class="nuxr-card-body nuxr-actions" style="border-top:1px solid var(--nx-border)">
            {if in_array('send', $n.wallet_actions) || in_array('send_from_parent', $n.wallet_actions)}<a class="nuxr-btn nuxr-btn-primary" href="{Text::url('finance/send&to=', $w.id)}"><i class="fa fa-exchange" aria-hidden="true"></i>{if in_array('send', $n.wallet_actions)}{Lang::T('Send to this reseller')}{else}{Lang::T('Transfer from its parent')}{/if}</a>{/if}
            {if in_array('fund', $n.wallet_actions)}<a class="nuxr-btn" href="{Text::url('finance/funding/', $w.id, '&op=fund')}"><i class="fa fa-plus-circle" aria-hidden="true"></i>{Lang::T('Provider funding')}</a>{/if}
            <a class="nuxr-btn" href="{Text::url('finance/ledger/', $w.id)}"><i class="fa fa-list" aria-hidden="true"></i>{Lang::T('Ledger')}</a>
        </div>
    </section>
{/if}

{if $n.children}
    <section class="nuxr-card" aria-labelledby="nuxr-res-kids">
        <div class="nuxr-card-head"><h2 id="nuxr-res-kids">{Lang::T('Child accounts')}</h2></div>
        <ul class="nuxr-mlist" style="display:block">
            {foreach $n.children as $c}
                <li><a class="nuxr-mcard" href="{Text::url('reseller/detail/', $c.id)}">
                    {call name=nuxr_initial who=$c.fullname}
                    <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{$c.fullname|escape}</span>
                        <span class="nuxr-mcard-sub"><bdi dir="ltr">{$c.username|escape}</bdi> · {if $c.user_type eq 'Agent'}{Lang::T('Reseller')} · {Lang::T('Subscribers')} <bdi dir="ltr">{$c.customers_direct|escape}</bdi>{else}{Lang::T('Sales')} · {Lang::T('assigned')} <bdi dir="ltr">{$c.customers_assigned|escape}</bdi>{/if}</span></span>
                    <span class="nuxr-mcard-end">{if $c.status neq 'Active'}{call name=nuxr_badge tone='danger' label=Lang::T('Inactive') icon='fa-ban'}{elseif $c.frozen}{call name=nuxr_badge tone='warning' label=Lang::T('Frozen') icon='fa-pause'}{else}{call name=nuxr_badge tone='success' label=Lang::T('Active')}{/if}</span>
                </a></li>
            {/foreach}
        </ul>
    </section>
{/if}

{if $n.can_status}
    <dialog id="nuxr-status-dialog" class="nuxr-sheet" aria-labelledby="nuxr-status-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-status-t">{if $n.status eq 'Active'}{Lang::T('Disable')}{else}{Lang::T('Reactivate')}{/if}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <form method="post" action="{Text::url('reseller/status-post')}">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <input type="hidden" name="user_id" value="{$n.id|escape}">
            <input type="hidden" name="status" value="{if $n.status eq 'Active'}Inactive{else}Active{/if}">
            <div class="nuxr-dialog-body">{if $n.status eq 'Active'}<div class="nuxr-alert is-danger"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i><div>{Lang::T('Disable this account? Its descendants become read-only.')}</div></div>
                {else}<p>{Lang::T('Reactivate this account?')}</p>{/if}</div>
            <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close autofocus>{Lang::T('Cancel')}</button>
                <button type="submit" class="nuxr-btn {if $n.status eq 'Active'}nuxr-btn-danger-solid{else}nuxr-btn-primary{/if}">{if $n.status eq 'Active'}{Lang::T('Disable')}{else}{Lang::T('Reactivate')}{/if}</button></div>
        </form>
    </dialog>
{/if}
{if $n.can_move}
    <dialog id="nuxr-move-dialog" class="nuxr-sheet" aria-labelledby="nuxr-move-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-move-t">{Lang::T('Move')}</h2>
            <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
        <form method="post" action="{Text::url('reseller/move-post')}">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            <input type="hidden" name="node_id" value="{$n.id|escape}">
            <div class="nuxr-dialog-body">
                <div class="nuxr-alert is-warning"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i><div>{Lang::T('Move this account (and its subtree)?')} {Lang::T('Historical finance stays with the original resellers.')}</div></div>
                <label for="nuxr-move-parent">{Lang::T('New parent')}</label>
                <select id="nuxr-move-parent" name="parent_id" class="form-control nuxr-input">
                    {if $n.user_type eq 'Agent'}<option value="">{Lang::T('Top level')}</option>{/if}
                    {foreach $nuxr_agents as $ag}{if $ag['id'] neq $n.id}<option value="{$ag['id']|escape}">{$ag['username']|escape}</option>{/if}{/foreach}
                </select>
            </div>
            <div class="nuxr-dialog-foot"><button type="button" class="nuxr-btn" data-nuxr-close autofocus>{Lang::T('Cancel')}</button>
                <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Move')}</button></div>
        </form>
    </dialog>
{/if}
{include file="sections/footer.tpl"}
