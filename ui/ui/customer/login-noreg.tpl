{include file="customer/header-public.tpl"}
<div class="hidden-xs" style="height:100px"></div>
{* lab UX-1: the announcement panel only when the page has content; otherwise the login forms are centred *}
{nuxr_page name="Announcement" assign="nuxr_announcement"}
<div class="row">
    {if $nuxr_announcement neq ''}
    <div class="col-sm-8">
        <div class="panel panel-info nuxr-announcement">
            <div class="panel-heading">{Lang::T('Announcement')}</div>
            <div class="panel-body">
                {$nuxr_announcement}
            </div>
        </div>
    </div>
    {/if}
    <div class="col-sm-4{if $nuxr_announcement eq ''} col-sm-offset-4{/if}">
        <div class="panel panel-primary">
            <div class="panel-heading">{Lang::T('Login / Activate Voucher')}</div>
            <div class="panel-body">
                <form action="{Text::url('login/activation')}" method="post">
                    <input type="hidden" name="csrf_token" value="{$csrf_token}">
                    <div class="form-group">
                        <label>
                            {if $_c['registration_username'] == 'phone'}
                                {Lang::T('Phone Number')}
                            {elseif $_c['registration_username'] == 'email'}
                                {Lang::T('Email')}
                            {else}
                                {Lang::T('Username')}
                            {/if}
                        </label>
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
                            <input type="text" class="form-control" name="username"
                                placeholder="{if $_c['country_code_phone']!= '' || $_c['registration_username'] == 'phone'}{$_c['country_code_phone']} {Lang::T('Phone Number')}{elseif $_c['registration_username'] == 'email'}{Lang::T('Email')}{else}{Lang::T('Username')}{/if}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label>{Lang::T('Enter voucher code here')}</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="voucher" name="voucher" required value="{$code|escape}"
                                placeholder="{Lang::T('Enter voucher code here')}">
                            <span class="input-group-btn">
                                <a class="btn btn-default" aria-label="{Lang::T('Scan QR code')}" title="{Lang::T('Scan QR code')}"
                                    href="{}/scan/?back={urlencode(Text::url('login&code='))}"><i
                                        class="glyphicon glyphicon-qrcode" aria-hidden="true"></i></a>
                            </span>
                        </div>
                    </div>
                    <div class="btn-group btn-group-justified mb15">
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">{Lang::T('Login / Activate Voucher')}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <div class="panel panel-primary">
            <div class="panel-heading">{Lang::T('Activate Voucher')}</div>
            <div class="panel-body">
                <form action="{Text::url('login/activation')}" method="post">
                    <input type="hidden" name="csrf_token" value="{$csrf_token}">
                    <div class="form-group">
                        <label>{Lang::T('Enter voucher code here')}</label>
                        <div class="input-group">
                            <input type="text" class="form-control" id="voucher_only" name="voucher_only" required
                                value="{$code|escape}" placeholder="{Lang::T('Enter voucher code here')}">
                            <span class="input-group-btn">
                                <a class="btn btn-default" aria-label="{Lang::T('Scan QR code')}" title="{Lang::T('Scan QR code')}"
                                    href="{}/scan/?back={urlencode(Text::url('login&code='))}"><i
                                        class="glyphicon glyphicon-qrcode" aria-hidden="true"></i></a>
                            </span>
                        </div>
                    </div>
                    {* R0008: only cards that show a password need it *}
                    <div class="form-group">
                        <label for="voucher_password">{Lang::T('Card password (only if your card shows one)')}</label>
                        <input type="password" class="form-control" id="voucher_password" name="voucher_password" autocomplete="off"
                            placeholder="{Lang::T('Card password')}">
                    </div>
                    <div class="btn-group btn-group-justified mb15">
                        <div class="btn-group">
                            <button type="submit" class="btn btn-primary">{Lang::T('Activate Voucher')}</button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
        <br>
        <center>
            <a href="./pages/Privacy_Policy.html" target="_blank">{Lang::T('Privacy')}</a>
            &bull;
            <a href="./pages/Terms_of_Conditions.html" target="_blank">ToC</a>
        </center>
    </div>
</div>
{include file="customer/footer-public.tpl"}