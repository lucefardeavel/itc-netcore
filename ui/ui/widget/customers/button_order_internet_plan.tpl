{* lab UX-2: no order button for a reseller-managed customer ($nuxr_owned from User::_info; the order routes refuse it, OD-11) *}
<div class="box-footer">
    {if ($_c['payment_gateway'] != 'none' or $_c['payment_gateway'] == '') && !$nuxr_owned}
        <a href="{Text::url('order/package')}" class="btn btn-primary btn-block">
            <i class="ion ion-ios-cart"></i>
            {Lang::T('Order Package')}
        </a>
    {/if}
</div>