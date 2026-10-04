{include file="sections/header.tpl"}
{* WP6A (OD-C): read-only activation list (ActivationReadService; display fields only). *}
<div class="panel panel-primary">
    <div class="panel-heading">{Lang::T('Activations')}</div>
    <div class="panel-body">
        {if $nuxr_list['rows']}
            <table class="table table-condensed">
                <thead><tr><th>{Lang::T('Reference')}</th><th>{Lang::T('Customer')}</th><th>{Lang::T('Plan')}</th><th>{Lang::T('Action')}</th><th>{Lang::T('Status')}</th><th>{Lang::T('Updated')}</th></tr></thead>
                <tbody>
                    {foreach $nuxr_list['rows'] as $r}
                        <tr><td><a href="{Text::url('activation/view/', $r['reference'])}"><bdi dir="ltr">{$r['reference']|escape}</bdi></a></td><td><bdi dir="ltr">{$r['customer_username']|escape}</bdi></td>
                            <td>{$r['plan_name']|escape}</td><td>{$r['action']|escape}</td><td>{$r['status']|escape}</td><td><bdi dir="ltr">{$r['updated_at']|escape}</bdi></td></tr>
                    {/foreach}
                </tbody>
            </table>
        {else}
            <p>{Lang::T('No activations yet.')}</p>
        {/if}
    </div>
</div>
{include file="sections/footer.tpl"}
