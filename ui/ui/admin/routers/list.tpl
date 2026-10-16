{include file="sections/header.tpl"}
<!-- routers -->

<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">{Lang::T('Routers')}
                <div class="btn-group pull-right">
                    <a class="btn btn-primary btn-xs" title="save" href="{Text::url('')}routers/maps">
                        <span class="glyphicon glyphicon-map-marker"></span></a>
                </div>
            </div>
            <div class="panel-body">
                <div class="md-whiteframe-z1 mb20 text-center" style="padding: 15px">
                    <div class="col-md-8">

                        <form id="site-search" method="post" action="{Text::url('')}routers/list/">
                            <div class="input-group">
                                <div class="input-group-addon">
                                    <span class="fa fa-search"></span>
                                </div>
                                <input type="text" name="name" class="form-control" aria-label="{Lang::T('Search by Name')}"
                                    placeholder="{Lang::T('Search by Name')}...">
                                <div class="input-group-btn">
                                    <button class="btn btn-success" type="submit">{Lang::T('Search')}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-4">
                        <a href="{Text::url('')}routers/add" class="btn btn-primary btn-block"><i
                                class="ion ion-android-add">
                            </i> {Lang::T('New Router')}</a>
                    </div>&nbsp;
                </div>
                <div class="table-responsive nuxr-stack">
                    <table class="table table-bordered table-striped table-condensed">
                        <thead>
                            <tr>
                                <th>{Lang::T('Router Name')}</th>
                                <th>{Lang::T('IP Address')}</th>
                                <th>{Lang::T('Username')}</th>
                                <th>{Lang::T('Description')}</th>
                                {if $_c['router_check']}
                                    <th>{Lang::T('Online Status')}</th>
                                    <th>{Lang::T('Last Seen')}</th>
                                {/if}
                                <th>{Lang::T('Status')}</th>
                                <th>{Lang::T('Manage')}</th>
                                <th>ID</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $d as $ds}
                                <tr {if $ds['enabled'] !=1}class="danger" title="disabled" {/if}>
                                    <td data-label="{Lang::T('Router Name')}">
                                        {if $ds['coordinates']}
                                            <a href="https://www.google.com/maps/dir//{$ds['coordinates']|escape}/" target="_blank"
                                                class="btn btn-default btn-xs" title="{$ds['coordinates']|escape}"><i
                                                    class="glyphicon glyphicon-map-marker"></i></a>
                                        {/if}
                                        {$ds['name']|escape}
                                    </td>
                                    <td data-label="{Lang::T('IP Address')}">{include file="admin/nuxr/secret.tpl" nuxr_secret=$ds['ip_address'] nuxr_secret_label=Lang::T('IP Address')}</td>
                                    <td data-label="{Lang::T('Username')}">{include file="admin/nuxr/secret.tpl" nuxr_secret=$ds['username'] nuxr_secret_label=Lang::T('Username')}</td>
                                    <td data-label="{Lang::T('Description')}">{$ds['description']|escape}</td>
                                    {if $_c['router_check']}
                                        <td data-label="{Lang::T('Online Status')}">
                                            <span
                                                class="label {if $ds['status'] == 'Online'}label-success {else}label-danger {/if}">
                                                {if $ds['status'] == 'Online'}
                                                    {Lang::T('Online')}
                                                {else}
                                                    {Lang::T('Offline')}
                                                {/if}
                                            </span>
                                        </td>
                                        <td data-label="{Lang::T('Last Seen')}">{$ds['last_seen']}</td>
                                    {/if}
                                    <td data-label="{Lang::T('Status')}">{if $ds['enabled'] == 1}{Lang::T('Enabled')}{else}{Lang::T('Disabled')}{/if}</td>
                                    <td data-label="{Lang::T('Manage')}">
                                        <a href="{Text::url('')}routers/edit/{$ds['id']}"
                                            class="btn btn-info btn-xs">{Lang::T('Edit')}</a>
                                        <a data-method="post" href="{Text::url('')}routers/delete/{$ds['id']}" id="{$ds['id']}"
                                            onclick="return ask(this, '{Lang::T('Delete')}?')"
                                            class="btn btn-danger btn-xs" aria-label="{Lang::T('Delete')}"><i class="glyphicon glyphicon-trash" aria-hidden="true"></i></a>
                                    </td>
                                    <td data-label="ID">{$ds['id']}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                {include file="pagination.tpl"}
                <div class="bs-callout bs-callout-info" id="callout-navbar-role">
                    <h4>{Lang::T('Check if Router Online?')}</h4>
                    <p>{Lang::T('To check whether the Router is online or not, please visit the following page')} <a
                            href="{Text::url('')}settings/miscellaneous#router_check" target="_blank"
                            class="btn btn-link">{Lang::T('Cek Now')}</a></p>
                </div>
            </div>
        </div>
    </div>
</div>


{include file="sections/footer.tpl"}