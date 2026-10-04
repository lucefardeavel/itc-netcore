{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B reseller workspace - tree (Reseller\Domain\ResellerReadService; reseller.tree_read scope). Desktop: dense
   indented table. Phone: drill-down cards one level at a time with a breadcrumb (no squeezed table). This page is also
   an accepted API route, so it carries no money. Every action is re-authorized server-side. *}
{function nuxr_tree_status n=null}
    {if $n.status neq 'Active'}{call name=nuxr_badge tone='danger' label=Lang::T('Inactive') icon='fa-ban'}
    {elseif $n.frozen}{call name=nuxr_badge tone='warning' label=Lang::T('Frozen by an upstream reseller') icon='fa-pause'}
    {else}{call name=nuxr_badge tone='success' label=Lang::T('Active')}{/if}
{/function}
<div class="nuxr-page-head">
    <span class="nuxr-sub" role="status"><strong><bdi dir="ltr">{$nuxr_total|escape}</bdi></strong> {Lang::T('accounts in the hierarchy')}</span>
    {if $nuxr_can_create}<a class="nuxr-btn nuxr-btn-primary" href="{Text::url('settings/users-add')}"><i class="fa fa-user-plus" aria-hidden="true"></i>{Lang::T('Add Agent / Sales')}</a>{/if}
</div>

<section class="nuxr-card nuxr-tree" aria-labelledby="nuxr-tree-t">
    <div class="nuxr-card-head"><h2 id="nuxr-tree-t">{Lang::T('Reseller Tree')}</h2></div>
    {if $nuxr_tree}
        <div class="nuxr-table-wrap">
            <table class="nuxr-table">
                <thead><tr><th>{Lang::T('Account')}</th><th>{Lang::T('Role')}</th><th>{Lang::T('Status')}</th><th>{Lang::T('Subscribers')}</th><th>{Lang::T('Sales accounts')}</th><th><span class="sr-only">{Lang::T('Open')}</span></th></tr></thead>
                <tbody>
                    {foreach $nuxr_tree as $n}
                        <tr>
                            <td><span class="nuxr-tree-indent" style="padding-inline-start:{($n.level * 22)|escape}px">{if $n.level > 0}<i class="fa fa-level-up fa-rotate-90 nuxr-muted nuxr-dir-icon" aria-hidden="true"></i> {/if}<a href="{Text::url('reseller/detail/', $n.id)}">{$n.fullname|escape}</a></span>
                                <bdi dir="ltr" class="nuxr-muted">{$n.username|escape}</bdi></td>
                            <td>{if $n.user_type eq 'Agent'}{Lang::T('Reseller')}{else}{Lang::T('Sales')}{/if}</td>
                            <td>{call name=nuxr_tree_status n=$n}</td>
                            <td><bdi dir="ltr">{$n.customers_direct|escape}</bdi>{if $n.user_type eq 'Agent' && $n.customers_subtree neq $n.customers_direct} <span class="nuxr-muted">({Lang::T('subtree')} <bdi dir="ltr">{$n.customers_subtree|escape}</bdi>)</span>{/if}
                                {if $n.user_type neq 'Agent'}<span class="nuxr-muted"> · {Lang::T('assigned')} <bdi dir="ltr">{$n.customers_assigned|escape}</bdi></span>{/if}</td>
                            <td>{if $n.user_type eq 'Agent'}<bdi dir="ltr">{$n.sales_count|escape}</bdi>{/if}</td>
                            <td><a class="nuxr-btn" href="{Text::url('reseller/detail/', $n.id)}">{Lang::T('Open')}</a></td>
                        </tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        {* phone: one level at a time *}
        <div class="nuxr-mlist nuxr-drill">
            {if $nuxr_path}
                <nav class="nuxr-crumbs" aria-label="{Lang::T('Hierarchy path')}">
                    <a href="{Text::url('reseller/tree')}">{Lang::T('All')}</a>
                    {foreach $nuxr_path as $p}<i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i>{if $p.id eq $nuxr_focus}<span aria-current="page">{$p.fullname|escape}</span>{else}<a href="{Text::url('reseller/tree&node=', $p.id)}">{$p.fullname|escape}</a>{/if}{/foreach}
                </nav>
            {/if}
            <ul class="nuxr-mlist" style="display:block">
                {foreach $nuxr_level as $n}
                    <li>
                        <div class="nuxr-mcard">
                            {call name=nuxr_initial who=$n.fullname}
                            <a class="nuxr-mcard-main" href="{Text::url('reseller/detail/', $n.id)}">
                                <span class="nuxr-mcard-title">{$n.fullname|escape}</span>
                                <span class="nuxr-mcard-sub"><bdi dir="ltr">{$n.username|escape}</bdi> · {if $n.user_type eq 'Agent'}{Lang::T('Reseller')}{else}{Lang::T('Sales')}{/if} · {Lang::T('Subscribers')} <bdi dir="ltr">{$n.customers_direct|escape}</bdi></span>
                                <span class="nuxr-mcard-sub">{call name=nuxr_tree_status n=$n}</span>
                            </a>
                            {if $n.children_count > 0}<a class="nuxr-btn nuxr-drill-btn" href="{Text::url('reseller/tree&node=', $n.id)}" aria-label="{Lang::T('Show the accounts under')} {$n.fullname|escape}"><bdi dir="ltr">{$n.children_count|escape}</bdi><i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></a>{/if}
                        </div>
                    </li>
                {foreachelse}
                    <li><div class="nuxr-empty"><strong>{Lang::T('No accounts under this reseller.')}</strong></div></li>
                {/foreach}
            </ul>
        </div>
    {else}
        <div class="nuxr-empty"><i class="fa fa-sitemap" aria-hidden="true"></i><strong>{Lang::T('No accounts in the hierarchy yet.')}</strong></div>
    {/if}
</section>

{if $nuxr_can_place}
    <section class="nuxr-card" aria-labelledby="nuxr-unenrolled-t">
        <div class="nuxr-card-head"><h2 id="nuxr-unenrolled-t">{Lang::T('Not yet enrolled Agent/Sales accounts')}</h2></div>
        <div class="nuxr-card-body"><p class="nuxr-muted">{Lang::T('explicit onboarding; the legacy parent value is shown for reference only and is never applied automatically')}</p></div>
        <div class="nuxr-table-wrap">
            <table class="nuxr-table">
                <thead><tr><th>{Lang::T('Account')}</th><th>{Lang::T('Role')}</th><th>{Lang::T('Legacy parent id')}</th><th>{Lang::T('Place under')}</th></tr></thead>
                <tbody>
                    {foreach $nuxr_unenrolled as $u}
                        <tr>
                            <td>{$u['username']|escape} <small class="nuxr-muted">{$u['fullname']|escape}</small></td>
                            <td>{if $u['user_type'] eq 'Agent'}{Lang::T('Reseller')}{else}{Lang::T('Sales')}{/if}</td>
                            <td><bdi dir="ltr">{$u['root']|escape}</bdi></td>
                            <td>
                                <form method="post" action="{Text::url('reseller/place-post')}" class="nuxr-filters" style="margin:0">
                                    <input type="hidden" name="csrf_token" value="{$csrf_token}">
                                    <input type="hidden" name="user_id" value="{$u['id']|escape}">
                                    <label class="sr-only" for="nuxr-place-{$u['id']|escape}">{Lang::T('Place under')}</label>
                                    <select id="nuxr-place-{$u['id']|escape}" name="parent_id" class="form-control nuxr-input" style="width:auto">
                                        {if $u['user_type'] eq 'Agent'}<option value="">{Lang::T('Top level')}</option>{/if}
                                        {foreach $nuxr_agents as $ag}<option value="{$ag['id']|escape}">{$ag['username']|escape}</option>{/foreach}
                                    </select>
                                    <button class="nuxr-btn nuxr-btn-primary" type="submit">{Lang::T('Place')}</button>
                                </form>
                            </td>
                        </tr>
                    {foreachelse}
                        <tr><td colspan="4">{Lang::T('None')}</td></tr>
                    {/foreach}
                </tbody>
            </table>
        </div>
        <ul class="nuxr-mlist">
            {foreach $nuxr_unenrolled as $u}
                <li><div class="nuxr-mcard nuxr-mcard-form">
                    <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{$u['fullname']|escape}</span>
                        <span class="nuxr-mcard-sub"><bdi dir="ltr">{$u['username']|escape}</bdi> · {if $u['user_type'] eq 'Agent'}{Lang::T('Reseller')}{else}{Lang::T('Sales')}{/if} · {Lang::T('Legacy parent id')} <bdi dir="ltr">{$u['root']|escape}</bdi></span></span>
                    <form method="post" action="{Text::url('reseller/place-post')}" class="nuxr-fields">
                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                        <input type="hidden" name="user_id" value="{$u['id']|escape}">
                        <label for="nuxr-placem-{$u['id']|escape}">{Lang::T('Place under')}</label>
                        <select id="nuxr-placem-{$u['id']|escape}" name="parent_id" class="form-control nuxr-input">
                            {if $u['user_type'] eq 'Agent'}<option value="">{Lang::T('Top level')}</option>{/if}
                            {foreach $nuxr_agents as $ag}<option value="{$ag['id']|escape}">{$ag['username']|escape}</option>{/foreach}
                        </select>
                        <button class="nuxr-btn nuxr-btn-primary" type="submit">{Lang::T('Place')}</button>
                    </form>
                </div></li>
            {foreachelse}
                <li><div class="nuxr-empty"><strong>{Lang::T('None')}</strong></div></li>
            {/foreach}
        </ul>
    </section>
{/if}
{if $nuxr_verify}
    <section class="nuxr-card" aria-labelledby="nuxr-findings-t">
        <div class="nuxr-card-head"><h2 id="nuxr-findings-t">{Lang::T('Hierarchy consistency findings')}</h2></div>
        <ul class="nuxr-card-body">
            {foreach $nuxr_verify as $v}<li><bdi dir="ltr" class="nuxr-mono">{$v['code']|escape}</bdi> — <bdi dir="ltr">#{$v['user_id']|escape}</bdi> {$v['detail']|escape}</li>{/foreach}
        </ul>
    </section>
{/if}
{include file="sections/footer.tpl"}
