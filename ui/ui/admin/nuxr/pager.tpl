{* WP6A: touch-friendly server pagination over Paginator's $paginator (no client-side dataset). *}
{if $paginator && $paginator['count'] > 1}
    <nav class="nuxr-pager" aria-label="{Lang::T('Pages')}">
        {if $paginator['page'] > 1}
            <a class="nuxr-btn" href="{$paginator['url']}{$paginator['page'] - 1}" rel="prev"><i class="fa fa-angle-left nuxr-dir-icon" aria-hidden="true"></i>{Lang::T('Previous')}</a>
        {/if}
        <span class="nuxr-muted">{Lang::T('Page')} <bdi dir="ltr">{$paginator['page']}</bdi> / <bdi dir="ltr">{$paginator['count']}</bdi></span>
        {if $paginator['page'] < $paginator['count']}
            <a class="nuxr-btn" href="{$paginator['url']}{$paginator['page'] + 1}" rel="next">{Lang::T('Next')}<i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></a>
        {/if}
    </nav>
{/if}
