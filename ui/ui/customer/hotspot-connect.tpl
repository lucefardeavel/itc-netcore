{include file="customer/header-public.tpl"}
{* V1.0.1: sends the sign-in to the hotspot router (Reseller\Ui\HotspotLogin::formFor); the button is the fallback without scripts *}
<div class="row">
    <div class="col-sm-6 col-sm-offset-3 col-md-4 col-md-offset-4 text-center">
        <div class="panel panel-primary">
            <div class="panel-body">
                <p class="lead">{Lang::T('Connecting to the internet...')}</p>
                <form id="nuxr-hs-connect" action="{$hs_form.action|escape}" method="{$hs_form.method}">
                    {foreach $hs_form.fields as $name => $value}
                        <input type="hidden" name="{$name|escape}" value="{$value|escape}">
                    {/foreach}
                    <button type="submit" class="btn btn-primary btn-block">{Lang::T('Connect')}</button>
                </form>
            </div>
        </div>
    </div>
</div>
<script>document.getElementById('nuxr-hs-connect').submit();</script>
{include file="customer/footer-public.tpl"}
