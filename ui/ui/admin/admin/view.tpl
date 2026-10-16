{include file="sections/header.tpl"}
<!-- user-edit -->

<form class="form-horizontal">
    <div class="row">
        {if $d['user_type'] == "Sales"}<div class="col-sm-6 col-md-6">{else}<div class="col-md-6 col-md-offset-3">{/if}
                <div
                    class="panel panel-{if $d['status'] != 'Active'}danger{else}primary{/if} panel-hovered panel-stacked mb30">
                    <div class="panel-heading">{$d['fullname']|escape}</div>
                    <div class="panel-body">
                        <center>
                            <a href="{$app_url}/{$UPLOAD_PATH}{$d['photo']}" target="foto">
                                <img src="{$app_url}/{$UPLOAD_PATH}{if $d['photo']}{$d['photo']}.thumb.jpg{else}/admin.default.png{/if}" width="200"
                                    onerror="this.src='{$app_url}/{$UPLOAD_PATH}/admin.default.png'" class="img-circle img-responsive" alt="Foto">
                            </a>
                        </center><br>
                        <ul class="list-group list-group-unbordered">
                            <li class="list-group-item">
                                <b>{Lang::T('Username')}</b> <span class="pull-right">{$d['username']|escape}</span>
                            </li>
                            <li class="list-group-item">
                                <b>{Lang::T('Phone Number')}</b> <span class="pull-right">{$d['phone']|escape}</span>
                            </li>
                            <li class="list-group-item">
                                <b>{Lang::T('Email')}</b> <span class="pull-right">{$d['email']|escape}</span>
                            </li>
                            <li class="list-group-item">
                                <b>{Lang::T('City')}</b> <span class="pull-right">{$d['city']|escape}</span>
                            </li>
                            <li class="list-group-item">
                                <b>{Lang::T('Sub District')}</b> <span class="pull-right">{$d['subdistrict']|escape}</span>
                            </li>
                            <li class="list-group-item">
                                <b>{Lang::T('Ward')}</b> <span class="pull-right">{$d['ward']|escape}</span>
                            </li>
                            <li class="list-group-item">
                                <b>{Lang::T('User Type')}</b> <span class="pull-right">{$d['user_type']}</span>
                            </li>
                        </ul>
                    </div>
                    <div class="panel-footer">
                        <center><a href="{Text::url('settings/users-edit/', $d['id'])}"
                                class="btn btn-info btn-block">{Lang::T('Edit')}</a>
                            <a href="{Text::url('settings/users')}" class="btn btn-link btn-block">{Lang::T('Cancel')}</a>
                        </center>
                    </div>
                </div>
            </div>
            {if $d['user_type'] == "Sales" && $d['root'] neq ''}
                <div class="col-sm-6 col-md-6">
                    <div class="panel panel-success">
                        <div class="panel-heading">Agent - {$agent['fullname']|escape}</div>
                        <div class="panel-body">
                            <ul class="list-group list-group-unbordered">
                                <li class="list-group-item">
                                    <b>{Lang::T('Phone Number')}</b> <span class="pull-right"><a
                                            href="tel:{$agent['phone']|escape}">{$agent['phone']|escape}</a></span>
                                </li>
                                <li class="list-group-item">
                                    <b>{Lang::T('Email')}</b> <span class="pull-right"><a
                                            href="mailto:{$agent['email']|escape}">{$agent['email']|escape}</a></span>
                                </li>
                                <li class="list-group-item">
                                    <b>{Lang::T('City')}</b> <span class="pull-right">{$agent['city']|escape}</span>
                                </li>
                                <li class="list-group-item">
                                    <b>{Lang::T('Sub District')}</b> <span class="pull-right">{$agent['subdistrict']|escape}</span>
                                </li>
                                <li class="list-group-item">
                                    <b>{Lang::T('Ward')}</b> <span class="pull-right">{$agent['ward']|escape}</span>
                                </li>
                            </ul>
                        </div>
                    </div>
                </div>
            {/if}
        </div>
</form>
{include file="sections/footer.tpl"}