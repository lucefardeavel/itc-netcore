{include file="customer/header.tpl"}
<!-- user-profile -->

<div class="row">
    <div class="col-md-6 col-md-offset-3">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Data Change')}</div>
            <div class="panel-body">
                <form class="form-horizontal" enctype="multipart/form-data" method="post" role="form"
                    action="{Text::url('accounts/edit-profile-post')}">
                    <input type="hidden" name="csrf_token" value="{$csrf_token}">
                    <input type="hidden" name="id" value="{$_user['id']}">
                    <center>
                        <img src="{$app_url}/{$UPLOAD_PATH}{$_user['photo']}.thumb.jpg" width="200"
                            onerror="this.src='{$app_url}/{$UPLOAD_PATH}/user.default.jpg'"
                            class="img-circle img-responsive" alt="Foto" onclick="return deletePhoto({$d['id']})">
                    </center><br>
                    <div class="form-group">
                        <label class="col-md-3 col-xs-12 control-label" for="photo">{Lang::T('Photo')}</label>
                        <div class="col-md-6 col-xs-8">
                            <input type="file" class="form-control" name="photo" id="photo" accept="image/*">
                        </div>
                        <div class="form-group col-md-3 col-xs-4" title="Not always Working">
                            <label class=""><input type="checkbox" checked name="faceDetect" value="yes">
                                {Lang::T('Face Detect')}</label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label" for="username">{Lang::T('Usernames')}</label>
                        <div class="col-md-9">
                            <div class="input-group">
                                {if $_c['registration_username'] == 'phone'}
                                    <span class="input-group-addon" id="basic-addon1"><i
                                            class="glyphicon glyphicon-phone-alt"></i></span>
                                {elseif $_c['registration_username'] == 'email'}
                                    <span class="input-group-addon" id="basic-addon1"><i
                                            class="glyphicon glyphicon-envelope"></i></span>
                                {else}
                                    <span class="input-group-addon" id="basic-addon1"><i
                                            class="glyphicon glyphicon-user"></i></span>
                                {/if}
                                <input type="text" class="form-control" name="username" id="username" readonly
                                    value="{$_user['username']|escape}"
                                    placeholder="{if $_c['country_code_phone']!= '' || $_c['registration_username'] == 'phone'}{$_c['country_code_phone']} {Lang::T('Phone Number')}{elseif $_c['registration_username'] == 'email'}{Lang::T('Email')}{else}{Lang::T('Username')}{/if}">
                            </div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label class="col-md-3 control-label" for="fullname">{Lang::T('Full Name')}</label>
                        <div class="col-md-9">
                            <input type="text" class="form-control" id="fullname" name="fullname"
                                value="{$_user['fullname']|escape}">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="col-md-3 control-label" for="address">{Lang::T('Home Address')}</label>
                        <div class="col-md-9">
                            <textarea name="address" id="address" class="form-control">{$_user['address']|escape}</textarea>
                        </div>
                    </div>
                    {if $_c['allow_phone_otp'] != 'yes'}
                        <div class="form-group">
                            <label class="col-md-3 control-label" for="phonenumber">{Lang::T('Phone Number')}</label>
                            <div class="col-md-9">
                                <div class="input-group">
                                    <span class="input-group-addon" id="basic-addon1"><i
                                            class="glyphicon glyphicon-phone-alt"></i></span>
                                    <input type="text" class="form-control" name="phonenumber" id="phonenumber"
                                        value="{$_user['phonenumber']|escape}"
                                        placeholder="{if $_c['country_code_phone']!= ''}{$_c['country_code_phone']}{/if} {Lang::T('Phone Number')}">
                                </div>
                            </div>
                        </div>
                    {else}
                        <div class="form-group">
                            <label class="col-md-3 control-label" for="phonenumber">{Lang::T('Phone Number')}</label>
                            <div class="col-md-9">
                                <div class="input-group">
                                    <span class="input-group-addon" id="basic-addon1"><i
                                            class="glyphicon glyphicon-phone-alt"></i></span>
                                    <input type="text" class="form-control" name="phonenumber" id="phonenumber"
                                        value="{$_user['phonenumber']|escape}" readonly
                                        placeholder="{if $_c['country_code_phone']!= ''}{$_c['country_code_phone']}{/if} {Lang::T('Phone Number')}">
                                    <span class="input-group-btn">
                                        <a href="{Text::url('accounts/phone-update')}" type="button"
                                            class="btn btn-info btn-flat">{Lang::T('Change')}</a>
                                    </span>
                                </div>
                            </div>
                        </div>
                    {/if}
                    {if $_c['allow_email_otp'] != 'yes'}
                        <div class="form-group">
                            <label class="col-md-3 control-label" for="email">{Lang::T('Email Address')}</label>
                            <div class="col-md-9">
                                <input type="text" class="form-control" id="email" name="email" value="{$_user['email']|escape}">
                            </div>
                        </div>
                    {else}
                        <div class="form-group">
                            <label class="col-md-3 control-label" for="email">{Lang::T('Email Address')}</label>
                            <div class="col-md-9">
                                <div class="input-group">
                                    <span class="input-group-addon"><i class="glyphicon glyphicon-envelope"></i></span>
                                    <input type="text" class="form-control" name="email" id="email"
                                        value="{$_user['email']|escape}" readonly>
                                    <span class="input-group-btn">
                                        <a href="{Text::url('accounts/email-update')}" type="button"
                                            class="btn btn-info btn-flat">{Lang::T('Change')}</a>
                                    </span>
                                </div>
                            </div>
                        </div>
                    {/if}
                    {$customFields}
                    <div class="form-group">
                        <div class="col-md-offset-3 col-md-9">
                            <button class="btn btn-success btn-block" type="submit">
                                {Lang::T('Save Changes')}</button>
                            <br>
                            <a href="{Text::url('home')}" class="btn btn-link btn-block">{Lang::T('Cancel')}</a>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

{include file="customer/footer.tpl"}