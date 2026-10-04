{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6A (OD-C): read-only activation list (ActivationReadService: activation.read visibility, display fields only,
   server pagination). *}
<div class="nuxr-page-head">
    <span class="nuxr-sub" role="status"><strong>{$nuxr_list.total}</strong> {Lang::T('activations')}</span>
    {if isset($_nav.quick)}{foreach $_nav.quick as $q}{if $q.route eq 'activation/create'}
        <a class="nuxr-btn nuxr-btn-primary" href="{Text::url('activation/create')}"><i class="fa fa-bolt" aria-hidden="true"></i>{Lang::T('Activate / Renew')}</a>
    {/if}{/foreach}{/if}
</div>
<nav class="nuxr-filters" aria-label="{Lang::T('Filter activations')}">
    {foreach ['' => 'All', 'processing' => 'Processing', 'attention' => 'Needs attention', 'completed' => 'Completed', 'failed' => 'Failed / cancelled', 'reversed' => 'Reversed'] as $g => $label}
        <a class="nuxr-chip{if $nuxr_group eq $g} is-active{/if}" href="{Text::url('activation/list')}{if $g}&group={$g}{/if}"{if $nuxr_group eq $g} aria-current="page"{/if}>{Lang::T($label)}</a>
    {/foreach}
</nav>
<section class="nuxr-card">
    {if $nuxr_list.rows}
        {include file="admin/nuxr/activation-rows.tpl" nuxr_rows=$nuxr_list.rows nuxr_show_customer=true}
        {if $nuxr_list.pages > 1}
            <nav class="nuxr-pager" aria-label="{Lang::T('Pages')}">
                {if $nuxr_list.page > 1}<a class="nuxr-btn" href="{Text::url('activation/list')}{if $nuxr_group}&group={$nuxr_group}{/if}&page={$nuxr_list.page - 1}" rel="prev"><i class="fa fa-angle-left nuxr-dir-icon" aria-hidden="true"></i>{Lang::T('Previous')}</a>{/if}
                <span class="nuxr-muted">{Lang::T('Page')} <bdi dir="ltr">{$nuxr_list.page} / {$nuxr_list.pages}</bdi></span>
                {if $nuxr_list.page < $nuxr_list.pages}<a class="nuxr-btn" href="{Text::url('activation/list')}{if $nuxr_group}&group={$nuxr_group}{/if}&page={$nuxr_list.page + 1}" rel="next">{Lang::T('Next')}<i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></a>{/if}
            </nav>
        {/if}
    {else}
        <div class="nuxr-empty"><i class="fa fa-bolt" aria-hidden="true"></i>
            <strong>{if $nuxr_group}{Lang::T('No activations in this group.')}{else}{Lang::T('No activations yet.')}{/if}</strong></div>
    {/if}
</section>
{include file="sections/footer.tpl"}
