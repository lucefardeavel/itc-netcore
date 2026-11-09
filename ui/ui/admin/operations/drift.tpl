{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B Operations Center - drift check, run on demand only (it reads every reseller-owned customer; the RADIUS part
   reads RADIUS per customer and is opt-in). Findings carry identifiers and component names only - never secrets. *}
{include file="admin/operations/tabs.tpl"}
<section class="nuxr-card">
    <div class="nuxr-card-head"><h2>{Lang::T('Drift check')}</h2>{call name=nuxr_badge tone='info' label=Lang::T('Read only') icon='fa-info-circle'}</div>
    <div class="nuxr-card-body">
        <p class="nuxr-muted" style="margin:0 0 12px">{Lang::T('Compares what the system recorded for each subscriber with what was applied, and lists any record that does not match so that it can be corrected. It changes nothing by itself.')}</p>
        <form method="get" action="{$app_url}/" class="nuxr-filters" style="margin:0">
            <input type="hidden" name="_route" value="operations/drift"><input type="hidden" name="run" value="1">
            <label><input type="checkbox" name="radius" value="1"{if $nuxr_radius} checked{/if}> {Lang::T('Also compare RADIUS (slower)')}</label>
            <button type="submit" class="nuxr-btn nuxr-btn-primary"><i class="fa fa-search" aria-hidden="true"></i>{Lang::T('Run check')}</button>
        </form>
    </div>
    {if $nuxr_run}
        {if $nuxr_drift}
            {foreach $nuxr_drift as $kind => $g}
                <div class="nuxr-card-body" style="border-top:1px solid var(--nx-border)">
                    <h3 style="margin:0 0 8px">{Lang::T('drift.'|cat:$kind)|escape} · <bdi dir="ltr">{$g.count|escape}</bdi></h3>
                    <p class="nuxr-muted">{if $kind eq 'REPROVISION_UNCONFIRMED'}{Lang::T('Open the subscriber and use Synchronize (the accepted administrative re-provision).')}
                        {elseif $kind eq 'VOUCHER_CLAIMED'}{Lang::T('No action is available in the application; operator review.')}
                        {elseif $kind eq 'BALANCE_HELD'}{Lang::T('Decide on the server (see Waiting / held).')}
                        {else}{Lang::T('Informational; no automatic correction exists.')}{/if}</p>
                    <ul class="nuxr-drift-list">{foreach $g.items as $f}<li><bdi dir="ltr" class="nuxr-mono">{$f.detail|escape}</bdi>
                        {if $f.customer_id} <a href="{Text::url('customers/view/', $f.customer_id)}">{Lang::T('Open subscriber')}</a>{/if}</li>{/foreach}</ul>
                </div>
            {/foreach}
        {else}<div class="nuxr-empty"><i class="fa fa-check" aria-hidden="true"></i><strong>{Lang::T('No drift found.')}</strong></div>{/if}
    {/if}
</section>
{include file="sections/footer.tpl"}
