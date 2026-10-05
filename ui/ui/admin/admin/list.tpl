{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6C (owner amendment A): actions follow the central route permission (RouteMap via NuxrUi::allowed), never role names *}
{assign var=nuxr_can value=['add' => NuxrUi::allowed('settings/users-add'), 'edit' => NuxrUi::allowed('settings/users-edit'), 'delete' => NuxrUi::allowed('settings/users-delete', 'POST')]}
<!-- users -->

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">{Lang::T('Manage Administrator')}</div>
            <div class="panel-body">
                <div class="md-whiteframe-z1 mb20 text-center" style="padding: 15px">
                    <div class="col-md-8">
                        <form id="site-search" method="post" action="{Text::url('settings/users/')}">
                            <input type="hidden" name="csrf_token" value="{$csrf_token}">
                            <div class="input-group">
                                <div class="input-group-addon">
                                    <span class="fa fa-search"></span>
                                </div>
                                <label class="sr-only" for="nxf-staff-search">{Lang::T('Search by Username')}</label>
                                <input type="search" id="nxf-staff-search" name="search" value="{$search|escape}" class="form-control"
                                    placeholder="{Lang::T('Search by Username')}...">
                                <div class="input-group-btn">
                                    <button class="btn btn-success" type="submit">{Lang::T('Search')}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    {if $nuxr_can.add}<div class="col-md-4">
                        <a href="{Text::url('settings/users-add')}" class="btn btn-primary btn-block"><i
                                class="ion ion-android-add"> </i> {Lang::T('Add New Administrator')}</a>
                    </div>{/if}&nbsp;
                </div>
                {* WP6C AdaptiveDataView: the staff table where it fits; cards (identity first, row menu) where narrow *}
                <div class="nuxr-adaptive is-lg">
                <div class="table-responsive nuxr-table-wrap">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>{Lang::T('Username')}</th>
                                <th>{Lang::T('Full Name')}</th>
                                <th>{Lang::T('Phone')}</th>
                                <th>{Lang::T('Email')}</th>
                                <th>{Lang::T('Type')}</th>
                                <th>{Lang::T('Location')}</th>
                                <th>{Lang::T('Agent')}</th>
                                <th>{Lang::T('Last Login')}</th>
                                <th>{Lang::T('Manage')}</th>
                                <th>ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $d as $ds}
                                <tr {if $ds['status'] != 'Active'}class="danger"{/if}>
                                    <td>{$ds['username']}</td>
                                    <td>{$ds['fullname']}</td>
                                    <td>{$ds['phone']}</td>
                                    <td>{$ds['email']}</td>
                                    <td>{$ds['user_type']}</td>
                                    <td>{$ds['city']}, {$ds['subdistrict']}, {$ds['ward']}</td>
                                <td>{if $ds['root']}
                                    <a href="{Text::url('settings/users-view/', $ds['root'])}">
                            {$admins[$ds['root']]}</a>{/if}</td>
                        <td>{if $ds['last_login']}{Lang::timeElapsed($ds['last_login'])}{/if}</td>
                                    <td>
                                        <a href="{Text::url('settings/users-view/',$ds['id'])}"
                                            class="btn btn-success btn-xs">{Lang::T('View')}</a>
                                        {if $nuxr_can.edit}<a href="{Text::url('settings/users-edit/',$ds['id'])}"
                                            class="btn btn-info btn-xs">{Lang::T('Edit')}</a>{/if}
                                        {* Agent accounts are never deleted in V1 (reseller identity: disable / reactivate) *}
                                        {if ($_admin['id']) neq ($ds['id']) && $ds['user_type'] neq 'Agent' && $nuxr_can.delete}
                                            <a data-method="post" href="{Text::url('settings/users-delete/',$ds['id'])}" id="{$ds['id']}"
                                                class="btn btn-danger btn-xs" onclick="return ask(this, '{Lang::T('Delete')}?')" aria-label="{Lang::T('Delete')}"><i class="glyphicon glyphicon-trash" aria-hidden="true"></i></a>
                                        {/if}
                                    </td>
                                    <td>{$ds['id']}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                <ul class="nuxr-mlist">
                    {foreach $d as $ds}
                        <li><div class="nuxr-mcard is-wrap">
                            {call name=nuxr_initial who=$ds['fullname']}
                            <a class="nuxr-mcard-main" href="{Text::url('settings/users-view/', $ds['id'])}">
                                <span class="nuxr-mcard-title">{$ds['fullname']|escape}</span>
                                <span class="nuxr-mcard-sub"><bdi dir="ltr">{$ds['username']|escape}</bdi> · {Lang::T($ds['user_type'])|escape}{if $ds['root']} · {$admins[$ds['root']]|escape}{/if}</span>
                                <span class="nuxr-mcard-sub">{if $ds['status'] eq 'Active'}{call name=nuxr_badge tone='success' label=Lang::T('Active') icon='fa-check-circle'}{else}{call name=nuxr_badge tone='danger' label=Lang::T($ds['status']) icon='fa-ban'}{/if}{if $ds['last_login']} · {Lang::T('Last Login')} {Lang::timeElapsed($ds['last_login'])|escape}{/if}</span>
                            </a>
                            {if $nuxr_can.edit || (($_admin['id']) neq ($ds['id']) && $ds['user_type'] neq 'Agent' && $nuxr_can.delete)}
                                <details class="nuxr-rowmenu">
                                    <summary class="nuxr-icon-btn" aria-label="{Lang::T('Actions')}: {$ds['username']|escape}"><i class="fa fa-ellipsis-v" aria-hidden="true"></i></summary>
                                    <div class="nuxr-rowmenu-panel">
                                        {if $nuxr_can.edit}<a href="{Text::url('settings/users-edit/', $ds['id'])}"><i class="fa fa-pencil" aria-hidden="true"></i>{Lang::T('Edit')}</a>{/if}
                                        {if ($_admin['id']) neq ($ds['id']) && $ds['user_type'] neq 'Agent' && $nuxr_can.delete}<a data-method="post" href="{Text::url('settings/users-delete/', $ds['id'])}" class="is-danger" onclick="return ask(this, '{Lang::T('Delete')|escape:'javascript'}?')"><i class="fa fa-trash" aria-hidden="true"></i>{Lang::T('Delete')}</a>{/if}
                                    </div>
                                </details>
                            {/if}
                        </div></li>
                    {foreachelse}
                        <li><div class="nuxr-empty"><i class="fa fa-users" aria-hidden="true"></i><strong>{Lang::T('No records.')}</strong></div></li>
                    {/foreach}
                </ul>
                </div>
                {include file="pagination.tpl"}
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}