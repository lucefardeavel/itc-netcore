{* WP6B: server pagination of a finance list ($p: page / pages; $pager_url: base URL with the current filters). *}
{if $p.pages > 1}
    <nav class="nuxr-pager" aria-label="{Lang::T('Pages')}">
        {if $p.page > 1}<a class="nuxr-btn" href="{$pager_url|escape}&page={($p.page - 1)|escape}" rel="prev"><i class="fa fa-angle-left nuxr-dir-icon" aria-hidden="true"></i>{Lang::T('Previous')}</a>{/if}
        <span class="nuxr-muted">{Lang::T('Page')} <bdi dir="ltr">{$p.page|escape} / {$p.pages|escape}</bdi></span>
        {if $p.page < $p.pages}<a class="nuxr-btn" href="{$pager_url|escape}&page={($p.page + 1)|escape}" rel="next">{Lang::T('Next')}<i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></a>{/if}
    </nav>
{/if}
