{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6C (owner amendment A): actions follow the central route permission (RouteMap via NuxrUi::allowed), never role names *}
{assign var=nuxr_can value=['add' => NuxrUi::allowed('plan/add-voucher'), 'print' => NuxrUi::allowed('plan/print-voucher'), 'remove' => NuxrUi::allowed('plan/remove-voucher', 'POST'),
    'delete' => NuxrUi::allowed('plan/voucher-delete', 'POST'), 'customer' => NuxrUi::allowed('customers/viewu')]}
<!-- voucher -->
<div class="row" style="padding: 5px">
    <div class="col-lg-3 col-lg-offset-9">
        <div class="btn-group btn-group-justified" role="group">
            <div class="btn-group" role="group">
                {if $nuxr_can.add}<a href="{Text::url('')}plan/add-voucher" class="btn btn-primary"><i class="ion ion-android-add"></i>
                    {Lang::T('Add Vouchers')}</a>{/if}
            </div>
            <div class="btn-group" role="group">
                {if $nuxr_can.print}<a href="{Text::url('')}plan/print-voucher" target="print_voucher" class="btn btn-info"><i
                        class="ion ion-android-print"></i> {Lang::T('Print')}</a>{/if}
            </div>
        </div>
    </div>
</div>
<div class="panel panel-hovered mb20 panel-primary">
    <div class="panel-heading">
        {if $nuxr_can.remove}
            <div class="btn-group pull-right">
                <a data-method="post" class="btn btn-danger btn-xs" title="Remove used Voucher" href="{Text::url('')}plan/remove-voucher"
                    onclick="return ask(this, 'Delete all used voucher code more than 3 months?')"><span
                        class="glyphicon glyphicon-trash" aria-hidden="true"></span> {Lang::T('Delete')} &gt; {Lang::T('3
                Months')}</a>
            </div>
        {/if}
        &nbsp;
    </div>
    <div class="panel-body">
        {* WP6C: search stays visible; the other filters use the WP6A filter disclosure; same form and field names *}
        <form id="site-search" method="post" action="{Text::url('')}plan/voucher/" class="nuxr-legacy-filters">
            <div class="nuxr-filters">
                <label class="sr-only" for="nxf-v-search">{Lang::T('Code Voucher')}</label>
                <input type="search" id="nxf-v-search" name="search" class="form-control nuxr-input" autocomplete="off" placeholder="{Lang::T('Code Voucher')}" value="{$search|escape}">
                <button class="nuxr-btn nuxr-btn-primary" type="submit"><i class="fa fa-search" aria-hidden="true"></i>{Lang::T('Search')}</button>
                {if $search || $router || $plan || $customer || ($status !== '' && $status !== null && $status !== '-')}<a class="nuxr-btn" href="{Text::url('plan/voucher/')}"><i class="fa fa-times" aria-hidden="true"></i>{Lang::T('Clear')}</a>{/if}
            </div>
            <details class="nuxr-filter-more" {if $router || $plan || $customer || ($status !== '' && $status !== null && $status !== '-')}open{/if}>
                <summary class="nuxr-btn"><i class="fa fa-sliders" aria-hidden="true"></i>{Lang::T('Filters')}</summary>
                <div class="nuxr-filters" style="margin:10px 0 0">
                    <label class="sr-only" for="router">{Lang::T('Location')}</label>
                    <select class="form-control" id="router" name="router">
                        <option value="">{Lang::T('Location')}</option>
                        {foreach $routers as $r}<option value="{$r|escape}" {if $router eq $r }selected{/if}>{$r|escape}</option>{/foreach}
                    </select>
                    <label class="sr-only" for="plan">{Lang::T('Plan Name')}</label>
                    <select class="form-control" id="plan" name="plan">
                        <option value="">{Lang::T('Plan Name')}</option>
                        {foreach $plans as $p}<option value="{$p['id']|escape}" {if $plan eq $p['id'] }selected{/if}>{$p['name_plan']|escape}</option>{/foreach}
                    </select>
                    <label class="sr-only" for="status">{Lang::T('Status')}</label>
                    <select class="form-control" id="status" name="status">
                        <option value="-">{Lang::T('Status')}</option>
                        <option value="1" {if $status eq 1 }selected{/if}>{Lang::T('Used')}</option>
                        <option value="0" {if $status eq 0 }selected{/if}>{Lang::T('Not Use')}</option>
                    </select>
                    <label class="sr-only" for="customer">{Lang::T('Customer')}</label>
                    <select class="form-control" id="customer" name="customer">
                        <option value="">{Lang::T('Customer')}</option>
                        {foreach $customers as $c}<option value="{$c['user']|escape}" {if $customer eq $c['user'] }selected{/if}>{$c['user']|escape}</option>{/foreach}
                    </select>
                    <button class="nuxr-btn nuxr-btn-primary" type="submit">{Lang::T('Apply')}</button>
                </div>
            </details>
        </form>
    </div>
    {* WP6C AdaptiveDataView: dense table (with bulk selection) where it fits; cards where narrow - same rows *}
    <div class="nuxr-adaptive is-lg">
    <div class="table-responsive nuxr-table-wrap">
        <div style="margin-left: 5px; margin-right: 5px;">&nbsp;
            <table id="datatable" class="table table-bordered table-striped table-condensed">
                <thead>
                    <tr>
                        <th><input type="checkbox" id="select-all" aria-label="{Lang::T('Select all')}"></th>
                        <th>ID</th>
                        <th>{Lang::T('Type')}</th>
                        <th>{Lang::T('Routers')}</th>
                        <th>{Lang::T('Plan Name')}</th>
                        <th>{Lang::T('Code Voucher')}</th>
                        <th>{Lang::T('Status Voucher')}</th>
                        <th>{Lang::T('Customer')}</th>
                        <th>{Lang::T('Create Date')}</th>
                        <th>{Lang::T('Used Date')}</th>
                        <th>{Lang::T('Generated By')}</th>
                        <th>{Lang::T('Manage')}</th>
                    </tr>
                </thead>
                <tbody>
                    {foreach $d as $ds}
                        <tr {if $ds['status'] eq '1' }class="danger" {/if}>
                            <td><input type="checkbox" name="voucher_ids[]" value="{$ds['id']|escape}" aria-label="{Lang::T('Select')} #{$ds['id']|escape}"></td>
                            <td>{$ds['id']}</td>
                            <td>{$ds['type']}</td>
                            <td>{$ds['routers']|escape}</td>
                            <td>{$ds['name_plan']|escape}</td>
                            <td>{include file="admin/nuxr/secret.tpl" nuxr_secret=$ds['code'] nuxr_secret_label=Lang::T('Code Voucher')}</td>
                            <td>{if $ds['status'] eq '0'} <label class="btn-tag btn-tag-success"> {Lang::T('Not Use')}
                                </label> {elseif $ds['status'] eq 'CLAIMED'} <label class="btn-tag btn-tag-warning">{Lang::T('Claimed - under review')}</label>
                                {else} <label class="btn-tag btn-tag-danger">{Lang::T('Used')}</label>
                                {/if}</td>
                            <td>{if $ds['user'] eq '0'} -
                                {elseif $nuxr_can.customer && $nuxr_customer_view[$ds['user']]|default:false}<a href="{Text::url('customers/viewu/')}{$ds['user']|escape:'url'}">{$ds['user']|escape}</a>
                                {else}{$ds['user']|escape}{* WP6C fix round 1: a customer outside the actor's scope is plain text *}
                                {/if}</td>
                            <td>{if $ds['created_at']}{Lang::dateTimeFormat($ds['created_at'])}{/if}</td>
                            <td>{if $ds['used_date']}{Lang::dateTimeFormat($ds['used_date'])}{/if}</td>
                            <td>{* WP6C fix round 1: the generator identity is plain text - the route rule does not prove that this
                                   specific account is viewable (e.g. Admin may not view another Admin / SuperAdmin) *}
                                {if $ds['generated_by']}{$admins[$ds['generated_by']]|escape}
                                {else} -
                                {/if}
                            </td>
                            <td>
                                {if $ds['status'] neq '1'}
                                    <a href="{Text::url('')}plan/voucher-view/{$ds['id']}" id="{$ds['id']}" style="margin: 0px;"
                                        class="btn btn-success btn-xs">&nbsp;&nbsp;{Lang::T('View')}&nbsp;&nbsp;</a>
                                {/if}
                                {if $nuxr_can.delete}
                                    <a data-method="post" href="{Text::url('')}plan/voucher-delete/{$ds['id']}" id="{$ds['id']}"
                                        class="btn btn-danger btn-xs" onclick="return ask(this, '{Lang::T('Delete')}?')" aria-label="{Lang::T('Delete')}"><i class="glyphicon glyphicon-trash" aria-hidden="true"></i></a>
                                {/if}
                            </td>
                        </tr>
                    {foreachelse}
                        <tr><td colspan="12" class="text-center text-muted">{Lang::T('No records.')}</td></tr>
                    {/foreach}
                </tbody>
            </table>
            {* bulk selection belongs to the table (it disappears with it where the cards are shown) *}
            {if NuxrUi::allowed('plan/voucher-delete-many', 'POST')}
                <div class="nuxr-actions" style="justify-content:flex-end;padding:8px 0">
                    <button id="deleteSelectedVouchers" class="btn btn-danger">{Lang::T('Delete Selected')}</button>
                </div>
            {/if}
        </div>
    </div>
    <ul class="nuxr-mlist">
        {foreach $d as $ds}
            <li><div class="nuxr-mcard is-wrap">
                <div class="nuxr-mcard-main">
                    <span class="nuxr-mcard-title">{include file="admin/nuxr/secret.tpl" nuxr_secret=$ds['code'] nuxr_secret_label=Lang::T('Code Voucher')}</span>
                    <span class="nuxr-mcard-sub">{$ds['name_plan']|escape} · {$ds['type']|escape}{if $ds['routers']} · <bdi dir="ltr">{$ds['routers']|escape}</bdi>{/if}</span>
                    <span class="nuxr-mcard-sub">{if $ds['status'] eq '0'}{call name=nuxr_badge tone='success' label=Lang::T('Not Use') icon='fa-circle-o'}{elseif $ds['status'] eq 'CLAIMED'}{call name=nuxr_badge tone='warning' label=Lang::T('Claimed - under review') icon='fa-hourglass-half'}{else}{call name=nuxr_badge tone='neutral' label=Lang::T('Used') icon='fa-check'}{/if}
                        {if $ds['user'] neq '0'} · <bdi dir="ltr">{$ds['user']|escape}</bdi>{/if}</span>
                    <span class="nuxr-mcard-sub">{if $ds['created_at']}{Lang::T('Create Date')} <bdi dir="ltr">{Lang::dateTimeFormat($ds['created_at'])|escape}</bdi>{/if}{if $ds['used_date']} · {Lang::T('Used Date')} <bdi dir="ltr">{Lang::dateTimeFormat($ds['used_date'])|escape}</bdi>{/if}</span>
                </div>
                {if $ds['status'] neq '1' || $nuxr_can.delete}
                    <details class="nuxr-rowmenu">
                        <summary class="nuxr-icon-btn" aria-label="{Lang::T('Actions')}: {Lang::T('Code Voucher')} #{$ds['id']|escape}"><i class="fa fa-ellipsis-v" aria-hidden="true"></i></summary>
                        <div class="nuxr-rowmenu-panel">
                            {if $ds['status'] neq '1'}<a href="{Text::url('plan/voucher-view/')}{$ds['id']|escape:'url'}"><i class="fa fa-eye" aria-hidden="true"></i>{Lang::T('View')}</a>{/if}
                            {if $nuxr_can.delete}<a data-method="post" href="{Text::url('plan/voucher-delete/')}{$ds['id']|escape:'url'}" class="is-danger" onclick="return ask(this, '{Lang::T('Delete')|escape:'javascript'}?')"><i class="fa fa-trash" aria-hidden="true"></i>{Lang::T('Delete')}</a>{/if}
                        </div>
                    </details>
                {/if}
            </div></li>
        {foreachelse}
            <li><div class="nuxr-empty"><i class="fa fa-ticket" aria-hidden="true"></i><strong>{Lang::T('No records.')}</strong></div></li>
        {/foreach}
    </ul>
    </div>
</div>
{include file="pagination.tpl"}

<script>
    function deleteVouchers(voucherIds) {
        if (voucherIds.length > 0) {
            Swal.fire({
                title: 'Are you sure?',
                text: 'You won\'t be able to revert this!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Yes, delete it!',
                cancelButtonText: 'Cancel'
            }).then((result) => {
                if (result.isConfirmed) {
                    var xhr = new XMLHttpRequest();
                    xhr.open('POST', '{Text::url('')}plan/voucher-delete-many', true);
                    xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            var response = JSON.parse(xhr.responseText);

                            if (response.status === 'success') {
                                Swal.fire({
                                    title: 'Deleted!',
                                    text: response.message,
                                    icon: 'success',
                                    confirmButtonText: 'OK'
                                }).then(() => {
                                    location.reload(); // Reload the page after confirmation
                                });
                            } else {
                                Swal.fire({
                                    title: 'Error!',
                                    text: response.message,
                                    icon: 'error',
                                    confirmButtonText: 'OK'
                                });
                            }
                        } else {
                            Swal.fire({
                                title: 'Error!',
                                text: 'Failed to delete vouchers. Please try again.',
                                icon: 'error',
                                confirmButtonText: 'OK'
                            });
                        }
                    };
                    xhr.send('voucherIds=' + JSON.stringify(voucherIds));
                }
            });
        } else {
            Swal.fire({
                title: 'Error!',
                text: 'No vouchers selected to delete.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    }

    // Example usage for selected vouchers
    // WP6C: the bulk controls render only for roles that may delete (permission-aware presentation)
    var nuxrDeleteSelected = document.getElementById('deleteSelectedVouchers');
    if (nuxrDeleteSelected) nuxrDeleteSelected.addEventListener('click', function() {
        var selectedVouchers = [];
        document.querySelectorAll('input[name="voucher_ids[]"]:checked').forEach(function(checkbox) {
            selectedVouchers.push(checkbox.value);
        });

        if (selectedVouchers.length > 0) {
            deleteVouchers(selectedVouchers);
        } else {
            Swal.fire({
                title: 'Error!',
                text: 'Please select at least one voucher to delete.',
                icon: 'error',
                confirmButtonText: 'OK'
            });
        }
    });

    document.querySelectorAll('.delete-voucher').forEach(function(button) {
        button.addEventListener('click', function() {
            var voucherId = this.getAttribute('data-id');
            deleteVouchers([voucherId]);
        });
    });


    // Select or deselect all checkboxes
    var nuxrSelectAll = document.getElementById('select-all');
    if (nuxrSelectAll) nuxrSelectAll.addEventListener('change', function() {
        var checkboxes = document.querySelectorAll('input[name="voucher_ids[]"]');
        for (var checkbox of checkboxes) {
            checkbox.checked = this.checked;
        }
    });
</script>
{include file="sections/footer.tpl"}