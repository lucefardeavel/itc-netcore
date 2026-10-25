{* lab UX-2: no order button for a reseller-managed customer (manager_id set; the order routes refuse it, OD-11) *}
{assign var=nuxr_owned value=isset($_user['manager_id']) && $_user['manager_id'] neq ''}
<div class="box-footer">
    {if ($_c['payment_gateway'] != 'none' or $_c['payment_gateway'] == '') && !$nuxr_owned}
        <a href="{Text::url('order/package')}" class="btn btn-primary btn-block">
            <i class="ion ion-ios-cart"></i>
            {Lang::T('Order Package')}
        </a>
    {/if}
</div>