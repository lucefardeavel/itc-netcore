{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B Operations Center overview: counts per category. Viewing authorizes nothing; actions live on the item pages and
   go to their own accepted primitives. *}
{include file="admin/operations/tabs.tpl"}
{assign var=c value=$nuxr_counts}
<h2 class="nuxr-section-title">{Lang::T('Needs attention')}</h2>
<div class="nuxr-stats">
    <a class="nuxr-stat{if $c.attention} is-warning{/if}" href="{Text::url('operations/activations')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$c.attention|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-exclamation-triangle" aria-hidden="true"></i>{Lang::T('Activations needing attention')}</span></a>
    <a class="nuxr-stat{if $c.stale} is-warning{/if}" href="{Text::url('operations/activations')}#nuxr-stale-t"><span class="nuxr-stat-value"><bdi dir="ltr">{$c.stale|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-hourglass-o" aria-hidden="true"></i>{Lang::T('Stale activations (no progress for 5 minutes)')}</span></a>
</div>
<h2 class="nuxr-section-title">{Lang::T('Waiting / held')}</h2>
<div class="nuxr-stats">
    <a class="nuxr-stat{if $c.held} is-warning{/if}" href="{Text::url('operations/held')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$c.held|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-pause" aria-hidden="true"></i>{Lang::T('Legacy balance operations held')}</span></a>
</div>
<h2 class="nuxr-section-title">{Lang::T('Informational / CLI required')}</h2>
<div class="nuxr-stats">
    <a class="nuxr-stat" href="{Text::url('operations/renewals')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$c.renewals|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-refresh" aria-hidden="true"></i>{Lang::T('Auto-renew not available (records)')}</span></a>
    <a class="nuxr-stat" href="{Text::url('operations/drift')}"><span class="nuxr-stat-value"><i class="fa fa-search" aria-hidden="true"></i></span><span class="nuxr-stat-label">{Lang::T('Drift check (run on demand)')}</span></a>
</div>
<h2 class="nuxr-section-title">{Lang::T('Resolved / history')}</h2>
<div class="nuxr-stats">
    <a class="nuxr-stat" href="{Text::url('activation/list')}"><span class="nuxr-stat-value"><bdi dir="ltr">{$c.resolved|escape}</bdi></span><span class="nuxr-stat-label"><i class="fa fa-history" aria-hidden="true"></i>{Lang::T('Terminal outcomes, last 7 days')}</span></a>
</div>
{include file="sections/footer.tpl"}
