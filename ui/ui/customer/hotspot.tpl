{include file="customer/header-public.tpl"}
{* V1.0.1: the hotspot sign-in (opened by the router's login page): a card, or a subscriber account *}
<div class="row">
    <div class="col-sm-6 col-sm-offset-3 col-md-4 col-md-offset-4">
        {if $hs_router_error}
            <div class="alert alert-warning" role="alert">{Lang::T('The router did not accept the sign-in. Check the code or password, or that the package has not ended.')}</div>
        {/if}
        <div class="panel panel-primary">
            <div class="panel-heading">{Lang::T('Connect with a card')}</div>
            <div class="panel-body">
                <form action="{Text::url('login/activation')}" method="post">
                    <input type="hidden" name="csrf_token" value="{$csrf_token}">
                    <div class="form-group">
                        <label for="voucher_only">{Lang::T('Card code or username')}</label>
                        <input type="text" class="form-control input-lg" id="voucher_only" name="voucher_only" required
                            autocomplete="off" autocapitalize="off" spellcheck="false" dir="ltr" value="{$code|escape}">
                    </div>
                    <div class="form-group">
                        <label for="voucher_password">{Lang::T('Card password (only if your card shows one)')}</label>
                        <input type="password" class="form-control input-lg" id="voucher_password" name="voucher_password"
                            autocomplete="off" dir="ltr">
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg btn-block">{Lang::T('Connect')}</button>
                </form>
            </div>
        </div>
        <details class="panel panel-default nuxr-hs-account">
            <summary class="panel-heading">{Lang::T('I have a subscriber account')}</summary>
            <div class="panel-body">
                <form action="{Text::url('login/hotspot')}" method="post">
                    <input type="hidden" name="csrf_token" value="{$csrf_token}">
                    <div class="form-group">
                        <label for="hs_username">{Lang::T('Username')}</label>
                        <input type="text" class="form-control" id="hs_username" name="hs_username" required
                            autocomplete="username" autocapitalize="off" spellcheck="false" dir="ltr">
                    </div>
                    <div class="form-group">
                        <label for="hs_password">{Lang::T('Password')}</label>
                        <input type="password" class="form-control" id="hs_password" name="hs_password" required
                            autocomplete="current-password" dir="ltr">
                    </div>
                    <button type="submit" class="btn btn-default btn-block">{Lang::T('Connect')}</button>
                </form>
            </div>
        </details>
    </div>
</div>
{include file="customer/footer-public.tpl"}
