{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B Operations Center - activations. Buttons appear only for the exact accepted operation of the state
   (ActivationOperatorService); otherwise the item is informational. Ambiguous outcomes stay "still uncertain". *}
{include file="admin/operations/tabs.tpl"}
{function nuxr_ops_row r=null}
    <li><div class="nuxr-mcard nuxr-mcard-form">
        <a class="nuxr-mcard-main" href="{Text::url('activation/view/', $r.reference)}">
            <span class="nuxr-mcard-title"><bdi dir="ltr" class="nuxr-mono">{$r.reference|escape}</bdi> · {$r.customer_fullname|escape}</span>
            <span class="nuxr-mcard-sub">{call name=nuxr_state state=$r.status} · <bdi dir="ltr">{$r.manager_username|escape}</bdi> · {Lang::T('updated')} <bdi dir="ltr">{$r.updated_at|truncate:16:'':true|escape}</bdi> (UTC)</span>
            {if $r.error_code eq 'EXTERNAL_STATE_AMBIGUOUS'}<span class="nuxr-mcard-sub">{call name=nuxr_badge tone='warning' label=Lang::T('Still uncertain - funds stay reserved') icon='fa-question-circle'}</span>
            {elseif $r.error_code eq 'PRIOR_SECRET_UNRESTORABLE' || $r.error_code eq 'PRIOR_STATE_NOT_CAPTURED'}<span class="nuxr-mcard-sub">{call name=nuxr_badge tone='neutral' label=Lang::T('No action available in the application - operator review') icon='fa-user'}</span>
            {elseif $r.error_code eq 'REVERSAL_FINANCIAL_REFUSED'}<span class="nuxr-mcard-sub">{Lang::T('The reseller wallet may need funding before the reversal can be resumed.')}</span>{/if}
        </a>
        <div class="nuxr-actions">
            {if $r.actions}<a class="nuxr-btn nuxr-btn-primary" href="{Text::url('activation/view/', $r.reference)}">{Lang::T('Open to act')}{foreach $r.actions as $op} · {if $op eq 'reconcile'}{Lang::T('Reconcile')}{elseif $op eq 'rollback'}{Lang::T('Roll back')}{elseif $op eq 'reverse'}{Lang::T('Reverse')}{elseif $op eq 'cancel'}{Lang::T('Cancel')}{else}{Lang::T('Resume')}{/if}{/foreach}</a>
            {else}<span class="nuxr-muted">{Lang::T('No action available here')}</span>{/if}
        </div>
    </div></li>
{/function}
{assign var=o value=$nuxr_ops}
<section class="nuxr-card" aria-labelledby="nuxr-att-t">
    <div class="nuxr-card-head"><h2 id="nuxr-att-t">{Lang::T('Needs attention')}</h2><span class="nuxr-muted"><bdi dir="ltr">{$o.attention.total|escape}</bdi></span></div>
    {if $o.attention.rows}<ul class="nuxr-mlist" style="display:block">{foreach $o.attention.rows as $r}{call name=nuxr_ops_row r=$r}{/foreach}</ul>
    {else}<div class="nuxr-empty"><i class="fa fa-check" aria-hidden="true"></i><strong>{Lang::T('Nothing needs attention.')}</strong></div>{/if}
</section>
<section class="nuxr-card" aria-labelledby="nuxr-stale-t">
    <div class="nuxr-card-head"><h2 id="nuxr-stale-t">{Lang::T('Stale activations (no progress for 5 minutes)')}</h2><span class="nuxr-muted"><bdi dir="ltr">{$o.stale.total|escape}</bdi></span></div>
    {if $o.stale.rows}<ul class="nuxr-mlist" style="display:block">{foreach $o.stale.rows as $r}{call name=nuxr_ops_row r=$r}{/foreach}</ul>
    {else}<div class="nuxr-empty"><strong>{Lang::T('No stale activations.')}</strong></div>{/if}
</section>
<section class="nuxr-card" aria-labelledby="nuxr-hist-t">
    <div class="nuxr-card-head"><h2 id="nuxr-hist-t">{Lang::T('Resolved / history')}</h2><a href="{Text::url('activation/list')}">{Lang::T('All')}</a></div>
    {if $o.history.rows}{include file="admin/nuxr/activation-rows.tpl" nuxr_rows=$o.history.rows nuxr_show_customer=true}
    {else}<div class="nuxr-empty"><strong>{Lang::T('No reversed or rolled back activations.')}</strong></div>{/if}
</section>
{include file="sections/footer.tpl"}
