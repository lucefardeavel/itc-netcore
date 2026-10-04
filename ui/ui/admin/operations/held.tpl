{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B Operations Center - legacy customer balance operations HELD (unassigned customers). The accepted resolution
   is CLI only (its service has no web actor); HELD means the grant outcome is unknown, and a refund does not revoke
   service that may have been granted. *}
{include file="admin/operations/tabs.tpl"}
<section class="nuxr-card">
    <div class="nuxr-card-head"><h2>{Lang::T('Legacy balance operations held')}</h2>{call name=nuxr_badge tone='neutral' label=Lang::T('CLI / operator action required') icon='fa-terminal'}</div>
    <div class="nuxr-card-body"><p class="nuxr-muted">{Lang::T('The grant outcome of a held operation is unknown. Decide on the server after checking the customer: completed (no grant is repeated) or refund (credits the balance back; service that may have been granted is not revoked).')}</p></div>
    {if $nuxr_held}
        <ul class="nuxr-mlist" style="display:block">
            {foreach $nuxr_held as $h}
                <li><div class="nuxr-mcard nuxr-mcard-form">
                    <span class="nuxr-mcard-main"><span class="nuxr-mcard-title"><bdi dir="ltr">#{$h.id|escape}</bdi> · <bdi dir="ltr">{$h.username|escape}</bdi> · {$h.plan|escape}</span>
                        <span class="nuxr-mcard-sub">{Lang::T('Amount')} <bdi dir="ltr">{$h.amount|escape}</bdi> · {$h.channel|escape} · <bdi dir="ltr">{$h.created|escape}</bdi></span></span>
                    <code class="nuxr-mono" dir="ltr">php system/reseller/reconcile.php balance-resolve {$h.id|escape} completed|refund</code>
                </div></li>
            {/foreach}
        </ul>
    {else}<div class="nuxr-empty"><i class="fa fa-check" aria-hidden="true"></i><strong>{Lang::T('No held operations.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
