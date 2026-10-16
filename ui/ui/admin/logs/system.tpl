{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
<!-- pool -->
<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                {if in_array($_admin['user_type'],['SuperAdmin','Admin'])}
                    <div class="btn-group pull-right">
                        <a class="btn btn-primary btn-xs" title="save" href="{Text::url('logs/list-csv')}"
                            onclick="return ask(this, '{Lang::T('This will export to CSV')}?')"><span class="glyphicon glyphicon-download"
                                aria-hidden="true"></span> CSV</a>
                    </div>
                {/if}
                {Lang::T('Activity Log')}
            </div>
            <div class="panel-body">
                <div class="text-center" style="padding: 15px">
                    <div class="col-md-4">
                        <form id="site-search" method="post" action="{Text::url('logs/list/')}">
                            <div class="input-group">
                                <div class="input-group-addon">
                                    <span class="fa fa-search"></span>
                                </div>
                                <label class="sr-only" for="nxf-log-q">{Lang::T('Search by Name')}</label>
                                <input type="search" id="nxf-log-q" name="q" class="form-control" value="{$q|escape}"
                                    placeholder="{Lang::T('Search by Name')}...">
                                <div class="input-group-btn">
                                    <button class="btn btn-success" type="submit">{Lang::T('Search')}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-8">
                        <form class="form-inline" method="post" action="{Text::url('')}logs/list/">
                            <div class="input-group has-error">
                                <span class="input-group-addon">{Lang::T('Keep Logs')} </span>
                                <input type="text" name="keep" class="form-control" inputmode="numeric" aria-label="{Lang::T('Keep Logs')} ({Lang::T('Days')})" placeholder="90" value="90">
                                <span class="input-group-addon">{Lang::T('Days')}</span>
                            </div>
                            <button type="submit" class="btn btn-danger btn-sm"
                                onclick="return ask(this, '{Lang::T("Clear old logs?")}')">{Lang::T('Clean up Logs')}</button>
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
                                    <td>{$ds['id']}</td>
                                    <td>{Lang::dateTimeFormat($ds['date'])}</td>
                                    <td>{$ds['type']}</td>
                                    <td>{$ds['ip']}</td>
                                    <td style="overflow-x: scroll;">{$ds['description']|escape|nl2br}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                <ul class="nuxr-mlist">
                    {foreach $d as $ds}
                        <li><div class="nuxr-mcard is-wrap"><div class="nuxr-mcard-main">
                            <span class="nuxr-mcard-title">{$ds['type']|escape} <span class="nuxr-muted">#<bdi dir="ltr">{$ds['id']|escape}</bdi></span></span>
                            <span class="nuxr-mcard-sub"><bdi dir="ltr">{Lang::dateTimeFormat($ds['date'])|escape}</bdi> · <bdi dir="ltr">{$ds['ip']|escape}</bdi></span>
                            <span class="nuxr-mcard-sub">{$ds['description']|escape|nl2br}</span>
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
