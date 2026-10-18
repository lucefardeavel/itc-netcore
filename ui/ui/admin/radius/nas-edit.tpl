{include file="sections/header.tpl"}
<!-- routers-add -->

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">Radius - {Lang::T('Edit NAS')}</div>
            <div class="panel-body">

                <form class="form-horizontal" method="post" role="form"
                    action="{Text::url('')}radius/nas-edit-post/{$d['id']}">
                    <div class="form-group">
                        <label for="shortname" class="col-md-2 control-label">{Lang::T('Router Name')}</label>
                        <div class="col-md-6">
                            <input type="text" required class="form-control" id="shortname" name="shortname"
                                value="{$d['shortname']}" maxlength="32">
                            <p class="help-block">{Lang::T('Name of Area that router operated')}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="nasname" class="col-md-2 control-label">{Lang::T('IP Address')}</label>
                        <div class="col-md-6">
                            <input type="text" placeholder="192.168.88.1" value="{$d['nasname']}" required
                                class="form-control" id="nasname" name="nasname" maxlength="128">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="secret" class="col-md-2 control-label">{Lang::T('Secret')}</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" id="secret" name="secret" autocomplete="new-password"
                                placeholder="{Lang::T('Leave empty to keep the current secret')}" maxlength="60">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ports" class="col-md-2 control-label">{Lang::T('CoA port')}</label>
                        <div class="col-md-2">
                            <input type="text" class="form-control" id="ports" name="ports" placeholder="null"
                                value="{$d['ports']}">
                        </div>
                        <label for="type" class="col-md-2 control-label">{Lang::T('Type')}</label>
                        <div class="col-md-2">
                            <input type="text" class="form-control" id="type" name="type" value="other"
                                value="{$d['type']}" required placeholder="other">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="server" class="col-md-2 control-label">{Lang::T('Server')}</label>
                        <div class="col-md-2">
                            <input type="text" class="form-control" id="server" name="server" value="{$d['server']}"
                                placeholder="null">
                        </div>
                        <label for="community" class="col-md-2 control-label">{Lang::T('SNMP community')}</label>
                        <div class="col-md-2">
                            <input type="text" class="form-control" id="community" name="community"
                                value="{$d['community']}" placeholder="null">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description" class="col-md-2 control-label">{Lang::T('Description')}</label>
                        <div class="col-md-6">
                            <textarea class="form-control" id="description"
                                name="description"> {htmlentities($d['description'])}</textarea>
                            <p class="help-block">{Lang::T('Explain Coverage of router')}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="routers" class="col-md-2 control-label"><a
                                href="{Text::url('')}routers/add">{Lang::T('Routers')}</a></label>
                        <div class="col-md-6">
                            <select id="routers" name="routers" class="form-control select2">
                                <option value="">No Router</option>
                                {foreach $routers as $rs}
                                    <option {if $rs['name'] == $d['routers']}selected{/if} value="{$rs['name']|escape}">
                                        {$rs['name']|escape}</option>
                                {/foreach}
                            </select>
                        </div>
                        <p class="help-block col-md-4">{Lang::T('Assign NAS to Router')}</p>
                    </div>
        {* WP6C: the shared action row - a direct child of the form, sticky above the bottom navigation on phones *}
        <div class="nuxr-step-nav nuxr-actionbar-form">
            <a class="nuxr-btn" href="{Text::url('')}radius/nas-list">{Lang::T('Cancel')}</a>
            <button class="nuxr-btn nuxr-btn-primary" data-nuxr-ask="{Lang::T("Continue the process of changing the Radius of the NAS?")|escape}"
                                type="submit"><i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Save Changes')}</button>
        </div>
                </form>

            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}