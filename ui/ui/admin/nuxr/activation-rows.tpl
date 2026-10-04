{* WP6A: activation rows from ActivationReadService (display fields only): table on >= 768px, cards on phone. *}
<div class="nuxr-table-wrap">
    <table class="nuxr-table">
        <thead><tr><th>{Lang::T('Reference')}</th>{if $nuxr_show_customer}<th>{Lang::T('Subscriber')}</th>{/if}<th>{Lang::T('Plan')}</th><th>{Lang::T('Action')}</th><th>{Lang::T('Status')}</th><th>{Lang::T('Updated (UTC)')}</th></tr></thead>
        <tbody>
            {foreach $nuxr_rows as $r}
                <tr>
                    <td><a href="{Text::url('activation/view/', $r.reference)}"><bdi dir="ltr" class="nuxr-mono">{$r.reference|escape}</bdi></a></td>
                    {if $nuxr_show_customer}<td>{$r.customer_fullname|escape}<br><bdi dir="ltr" class="nuxr-muted">{$r.customer_username|escape}</bdi></td>{/if}
                    <td>{$r.plan_name|escape}</td>
                    <td>{Lang::T(NuxrUi::actionLabel($r.action))}</td>
                    <td>{call name=nuxr_state state=$r.status}</td>
                    <td><bdi dir="ltr" class="nuxr-muted">{$r.updated_at|truncate:16:'':true|escape}</bdi></td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>
<ul class="nuxr-mlist">
    {foreach $nuxr_rows as $r}
        <li>
            <a class="nuxr-mcard" href="{Text::url('activation/view/', $r.reference)}">
                <span class="nuxr-mcard-main">
                    <span class="nuxr-mcard-title">{if $nuxr_show_customer}{$r.customer_fullname|escape}{else}{$r.plan_name|escape}{/if}</span>
                    <span class="nuxr-mcard-sub">{Lang::T(NuxrUi::actionLabel($r.action))} · <bdi dir="ltr">{$r.reference|escape}</bdi></span>
                </span>
                <span class="nuxr-mcard-end">{call name=nuxr_state state=$r.status}<bdi dir="ltr">{$r.updated_at|truncate:10:'':true|escape}</bdi></span>
            </a>
        </li>
    {/foreach}
</ul>
