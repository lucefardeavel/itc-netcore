{include file="sections/header.tpl"}
<!-- routers-add -->

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Add Router')}</div>
            <div class="panel-body">

                <form class="form-horizontal" method="post" role="form" action="{Text::url('')}routers/add-post">
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Status')}</label>
                        <div class="col-md-10">
                            <label class="radio-inline warning">
                                <input type="radio" checked name="enabled" value="1"> {Lang::T('Enable')}
                            </label>
                            <label class="radio-inline">
                                <input type="radio" name="enabled" value="0"> {Lang::T('Disable')}
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="name" class="col-md-2 control-label">{Lang::T('Router Name / Location')}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="name" name="name" maxlength="32">
                            <p class="help-block">{Lang::T('Name of Area that router operated')}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ip_address" class="col-md-2 control-label">{Lang::T('IP Address')}</label>
                        <div class="col-md-6">
                            <input type="text" placeholder="192.168.88.1:8728" class="form-control" id="ip_address"
                                name="ip_address">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="username" class="col-md-2 control-label">{Lang::T('Username')}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="username" name="username">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password" class="col-md-2 control-label">{Lang::T('Router Secret')}</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" id="password" name="password" autocomplete="new-password"
                            onmouseleave="this.type = 'password'" onmouseenter="this.type = 'text'" data-nuxr-peek>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description" class="col-md-2 control-label">{Lang::T('Description')}</label>
                        <div class="col-md-6">
                            <textarea class="form-control" id="description" name="description"></textarea>
                            <p class="help-block">{Lang::T('Explain Coverage of router')}</p>
                        </div>
                    </div>
                    <div class="form-group row">
                        <label class="col-md-2 control-label"></label>
                        <div class="col-md-6">
                            <label><input type="checkbox" checked name="testIt" value="yes"> {Lang::T('Check the connection')}</label>
                        </div>
                    </div>
        {* WP6C: the shared action row - a direct child of the form, sticky above the bottom navigation on phones *}
        <div class="nuxr-step-nav nuxr-actionbar-form">
            <a class="nuxr-btn" href="{Text::url('')}routers/list">{Lang::T('Cancel')}</a>
            <button class="nuxr-btn nuxr-btn-primary" data-nuxr-ask="{Lang::T("Continue the process of adding Routers?")|escape}"
                                type="submit"><i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Save')}</button>
        </div>
                </form>

            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}
