{include file="sections/header.tpl"}
<div class="itc-about-page">

{* About ITC NetCore (product identity, Reseller\Ui\Brand). The page carries ITC only: the original project keeps its
   attribution and licence rows (its copyright notices are kept; LIC-1 is open). ITC is the only support channel. *}
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
                            <tr><th scope="row">{Lang::T('Developed and maintained by')}</th><td><bdi>{$_brand.vendor|escape}</bdi></td></tr>
                            <tr><th scope="row">{Lang::T('Based on the open-source project')}</th><td><bdi>{$_brand.upstream|escape} ({$_brand.upstream_author|escape})</bdi></td></tr>
                            <tr><th scope="row">{Lang::T('Licence')}</th><td><bdi>{$_brand.license|escape}</bdi> <bdi>({Lang::T('see the LICENSE and NOTICE files')})</bdi></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="itc-muted">{Lang::T('ITC NetCore is a modified version of PHPNuxBill. The copyright notices and licences of the original authors and of every third-party component are kept unchanged.')}</p>
                {* WP2 / OD-2: the in-app web updater and rollback are disabled; updates are installed through
                   the reviewed release process and schema changes through the project migration runner. *}
                <p class="itc-muted">{Lang::T('In-app updates are disabled for this installation. Updates are installed through the reviewed release process.')}</p>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-12">
        <div class="box box-hovered mb20 box-primary">
    <div class="box-header">
        <h3 class="box-title">{Lang::T('Credits')}</h3>
    </div>
    <div class="box-body">
        <div class="table-responsive">
            <table class="table table-bordered table-striped table-hover">
                <thead>
                    <tr>
                        <th>{Lang::T('Souce')}</th>
                        <th>{Lang::T('Details')}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr>
                        <td>Bootstrap V3</td>
                        <td>
                            <a href="https://getbootstrap.com/docs/3.4/" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td>Admin LTE V3</td>
                        <td>
                            <a href="https://adminlte.io/themes/v3/" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td>Smarty Template V4</td>
                        <td>
                            <a href="https://www.smarty.net/" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td>PHP IdiORM</td>
                        <td>
                            <a href="https://idiorm.readthedocs.io/" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                    <tr>
                        <td>PHP mPDF</td>
                        <td>
                            <a href="https://mpdf.github.io/" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                   <tr>
                        <td>PHP QRCode</td>
                        <td>
                            <a href="http://phpqrcode.sourceforge.net/" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                   <tr>
                        <td>PHP Net_RouterOS</td>
                        <td>
                            <a href="https://github.com/pear2/Net_RouterOS" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                   <tr>
                        <td>Summernote</td>
                        <td>
                            <a href="https://summernote.org/" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                  <tr>
                        <td>PHP Mailer</td>
                        <td>
                            <a href="https://github.com/PHPMailer/PHPMailer/" target="_blank">
                                <i class="glyphicon glyphicon-globe"></i> {Lang::T('Visit')}
                            </a>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
    </div>
</div>
</div>
{include file="sections/footer.tpl"}
