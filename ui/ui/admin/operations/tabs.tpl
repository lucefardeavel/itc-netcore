{* WP6B Operations Center navigation (Admin / SuperAdmin). *}
<nav class="nuxr-filters" aria-label="{Lang::T('Operations')}">
    {foreach ['overview' => 'Overview', 'activations' => 'Activations', 'held' => 'Waiting / held', 'renewals' => 'Auto-renew not available', 'drift' => 'Drift check'] as $k => $label}
        <a class="nuxr-chip{if $nuxr_tab eq $k} is-active{/if}" href="{Text::url('operations/', $k)}"{if $nuxr_tab eq $k} aria-current="page"{/if}>{Lang::T($label)}</a>
    {/foreach}
</nav>
