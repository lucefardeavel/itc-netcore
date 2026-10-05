{include file="sections/header.tpl"}
<!-- user-edit -->

<form class="form-horizontal" method="post" role="form" action="{Text::url('settings/users-post')}">
    <input type="hidden" name="csrf_token" value="{$csrf_token}">
    <div class="row">
        <div class="col-sm-6 col-md-6">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading">{Lang::T('Profile')}</div>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="fullname" class="col-md-3 control-label">{Lang::T('Full Name')}</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" id="fullname" name="fullname">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="phone" class="col-md-3 control-label">{Lang::T('Phone')}</label>
                        <div class="col-md-9">
                            <input type="number" class="form-control" id="phone" name="phone">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="email" class="col-md-3 control-label">{Lang::T('Email')}</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" id="email" name="email">
                        </div>
                    </div>
                    <div class="form-group">
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="city" name="city" aria-label="{Lang::T('City')}" placeholder="{Lang::T('City')}">
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="subdistrict" name="subdistrict" aria-label="{Lang::T('Sub District')}" placeholder="{Lang::T('Sub District')}">
                        </div>
                        <div class="col-md-4">
                            <input type="text" class="form-control" id="ward" name="ward" aria-label="{Lang::T('Ward')}" placeholder="{Lang::T('Ward')}">
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-sm-6 col-md-6">
            <div class="panel panel-primary panel-hovered panel-stacked mb30">
                <div class="panel-heading">{Lang::T('Credentials')}</div>
                <div class="panel-body">
                    <div class="form-group">
                        <label for="user_type" class="col-md-3 control-label">{Lang::T('User Type')}</label>
                        <div class="col-md-9">
                            {* WP2 / SEC-04: offered roles come from the server-side target-role table *}
                            <select name="user_type" id="user_type" class="form-control" onchange="checkUserType(this)">
                                {foreach $nuxr_roles as $nuxrRole}
                                    <option value="{$nuxrRole|escape}">{Lang::T($nuxrRole)}</option>
                                {/foreach}
                            </select>
                        </div>
                    </div>
                    {if !$nuxr_parent_fixed}
                    <div class="form-group hidden" id="agentChooser">
                        <label for="parent_id" class="col-md-3 control-label">{Lang::T('Parent Agent')}</label>
                        <div class="col-md-9">
                            <select name="parent_id" id="parent_id" class="form-control">
                                <option value="">{Lang::T('None (top-level Agent)')}</option>
                                {foreach $agents as $agent}
                                    <option value="{$agent['id']}">{$agent['username']|escape} | {$agent['fullname']|escape}</option>
                                {/foreach}
                            </select>
                            <span class="help-block">{Lang::T('Required for Sales; optional for Agents.')}</span>
                        </div>
                    </div>
                    {else}
                        <p class="help-block">{Lang::T('New accounts are created as your direct children.')}</p>
                    {/if}
                    <div class="form-group">
                        <label for="username" class="col-md-3 control-label">{Lang::T('Username')}</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" id="username" name="username">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password" class="col-md-3 control-label">{Lang::T('Password')}</label>
                        <div class="col-md-9">
                            <input type="password" class="form-control" id="password" value="{rand(000000,999999)}" name="password"
                            onmouseleave="this.type = 'password'" onmouseenter="this.type = 'text'">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="send_notif" class="col-md-5 control-label">{Lang::T('Send Notification')}</label>
                        <div class="col-md-7">
                            <select name="send_notif" id="send_notif" class="form-control">
                                <option value="-">{Lang::T("Don't Send")}</option>
                                <option value="sms">{Lang::T('By SMS')}</option>
                                <option value="wa">{Lang::T('By WhatsApp')}</option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    {include file="admin/nuxr/actionbar.tpl" nuxr_submit='Save Changes' nuxr_ask='Continue the process of adding Admin?' nuxr_cancel='settings/users'}
</form>
{literal}
    <script>
        function checkUserType($field){
            if($field.value=='Sales' || $field.value=='Agent'){
                $('#agentChooser').removeClass('hidden');
            }else{
                $('#agentChooser').addClass('hidden');
            }
        }
</script>
{/literal}

{include file="sections/footer.tpl"}
