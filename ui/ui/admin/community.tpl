{include file="sections/header.tpl"}
<div class="itc-about-page">

{* About ITC NetCore (product identity and contact, Reseller\Ui\Brand): ITC only. *}
<div class="row">
    <div class="col-sm-12">
        <div class="box box-primary mb20 itc-about" id="about">
            <div class="box-header">
                <h3 class="box-title">{Lang::T('About')} <bdi>{$_brand.name|escape}</bdi></h3>
            </div>
            <div class="box-body">
                {* brand: the outlined ITC NetCore wordmark (light / reversed artwork); the tagline in the interface language *}
                <p class="itc-about-head"><span class="nc-about-wordmark"><img class="nc-wordmark nc-on-light" src="{$app_url}/ui/ui/images/brand/netcore-wordmark.svg" alt="{$_brand.name|escape}" width="230" height="80"><img class="nc-wordmark nc-on-dark" src="{$app_url}/ui/ui/images/brand/netcore-wordmark-white.svg" alt="{$_brand.name|escape}" width="230" height="80"></span>
                    <span class="itc-tagline">{Lang::T('ISP Management & Billing Platform')}</span></p>
                <div>
                    <table class="table table-bordered itc-facts">
                        <tbody>
                            <tr><th scope="row">{Lang::T('Product')}</th><td><bdi>{$_brand.name|escape}</bdi></td></tr>
                            <tr><th scope="row">{Lang::T('Version')}</th><td><bdi>{$_brand.version|escape}</bdi></td></tr>
                            <tr><th scope="row">{Lang::T('Developed and maintained by')}</th><td><bdi>{$_brand.vendor_full|escape}</bdi></td></tr>
                            <tr><th scope="row">{Lang::T('Website')}</th><td><a href="https://{$_brand.website|escape}" target="_blank" rel="noopener noreferrer"><bdi>{$_brand.website|escape}</bdi></a></td></tr>
                            <tr><th scope="row">{Lang::T('Phone')}</th><td>{foreach $_brand.phones as $p}<a href="tel:{$p|escape}"><bdi dir="ltr">{$p|escape}</bdi></a>{if !$p@last} &middot; {/if}{/foreach}</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

</div>
{include file="sections/footer.tpl"}
