{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
<!-- pool -->
<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                {if in_array($_admin['user_type'],['SuperAdmin','Admin'])}
                    <div class="btn-group pull-right">
                        <a class="btn btn-primary btn-xs" title="save" href="{Text::url('logs/radius-csv')}"
                            onclick="return ask(this, 'This will export to CSV?')"><span
                                class="glyphicon glyphicon-download" aria-hidden="true"></span> CSV</a>
                    </div>
                {/if}
                Radius
            </div>
            <div class="panel-body">
                <div class="text-center" style="padding: 15px">
                    <div class="col-md-4">
                        <form id="site-search" method="post" action="{Text::url('logs/radius/')}">
                            <div class="input-group">
                                <div class="input-group-addon">
                                    <span class="fa fa-search"></span>
                                </div>
                                <input type="text" name="q" class="form-control" aria-label="{Lang::T('Search')}" value="{$q|escape}"
                                    placeholder="{Lang::T('Search by Name')}...">
                                <div class="input-group-btn">
                                    <button class="btn btn-success" type="submit">{Lang::T('Search')}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-8">
                        <form class="form-inline" method="post" action="{Text::url('logs/radius/')}">
                            <div class="input-group has-error">
                                <span class="input-group-addon">{Lang::T('Keep Logs')} </span>
                                <input type="text" name="keep" class="form-control" inputmode="numeric" aria-label="{Lang::T('Keep Logs')} ({Lang::T('Days')})" placeholder="90" value="90">
                                <span class="input-group-addon">{Lang::T('Days')}</span>
                            </div>
                            <button type="submit" class="btn btn-danger btn-sm"
                                onclick="return ask(this, 'Clear old logs?')">{Lang::T('Clean Logs')}</button>
                        </form>
                    </div>&nbsp;
                </div>
                <br>
                {* WP6C AdaptiveDataView: the log table where it fits; readable cards where narrow (same rows) *}
                <div class="nuxr-adaptive">
                <div class="table-responsive nuxr-table-wrap">
                    <table class="table table-bordered table-striped table-condensed">
                        <tbody>
                            {foreach $d as $ds}
                                <tr>
                                    <td width="30px">{$ds['id']}</td>
                                    <td width="200px">{Lang::dateTimeFormat($ds['authdate'])}</td>
                                    <td width="100px">{$ds['username']}</td>
                                    <td width="10px">{include file="admin/nuxr/secret.tpl" nuxr_secret=$ds['pass'] nuxr_secret_label=Lang::T('Password')}</td>
                                    <td>{$ds['reply']}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                <ul class="nuxr-mlist">
                    {foreach $d as $ds}
                        <li><div class="nuxr-mcard is-wrap"><div class="nuxr-mcard-main">
                            <span class="nuxr-mcard-title"><bdi dir="ltr">{$ds['username']|escape}</bdi> <span class="nuxr-muted">#<bdi dir="ltr">{$ds['id']|escape}</bdi></span></span>
                            <span class="nuxr-mcard-sub"><bdi dir="ltr">{Lang::dateTimeFormat($ds['authdate'])|escape}</bdi> · {$ds['reply']|escape}</span>
                            <span class="nuxr-mcard-sub">{include file="admin/nuxr/secret.tpl" nuxr_secret=$ds['pass'] nuxr_secret_label=Lang::T('Password')}</span>
                        </div></div></li>
                    {foreachelse}
                        <li><div class="nuxr-empty"><strong>{Lang::T('No records.')}</strong></div></li>
                    {/foreach}
                </ul>
                </div>
                {include file="pagination.tpl"}
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}