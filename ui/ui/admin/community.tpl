{include file="sections/header.tpl"}
<div class="itc-about-page">

{* About ITC NetCore (product identity, Reseller\Ui\Brand). The original project's credits, community links and notices
   below are kept and labelled as the original project's; they are not ITC support channels. *}
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
                            <tr><th scope="row">{Lang::T('Based on the open-source project')}</th><td><a href="{$_brand.upstream_url|escape}" target="_blank" rel="noopener noreferrer">{$_brand.upstream|escape}</a>
                                <bdi>({$_brand.upstream_author|escape})</bdi> {Lang::T('and its contributors')}</td></tr>
                            <tr><th scope="row">{Lang::T('Base version')}</th><td><bdi dir="ltr">{$_brand.upstream|escape} <span id="currentVersion">-</span></bdi></td></tr>
                            <tr><th scope="row">{Lang::T('Licence')}</th><td><bdi>{$_brand.license|escape}</bdi> <bdi>({Lang::T('see the LICENSE and NOTICE files')})</bdi></td></tr>
                        </tbody>
                    </table>
                </div>
                <p class="itc-muted">{Lang::T('ITC NetCore is a modified version of PHPNuxBill. The copyright notices and licences of the original authors and of every third-party component are kept unchanged.')}</p>
            </div>
        </div>
        <h2 class="itc-section-title">{Lang::T('Original project')}: <bdi>{$_brand.upstream|escape}</bdi></h2>
        <p class="itc-muted">{Lang::T('The community, donation and support links below belong to the original PHPNuxBill project and its author. They are not ITC support channels.')}</p>
    </div>
</div>

{* brand / privacy: the original project's banner as a link (no third-party image is fetched when the page opens) *}
<p class="text-center"><a class="btn btn-default" href="https://s.id/standwithpalestine" target="_blank" rel="noopener noreferrer">{Lang::T('Support Palestine')}</a></p>
<br><br>

<div class="row">
    <div class="col-sm-6">

        <div class="box box-hovered mb20 box-primary">
            <div class="box-header">
                <h3 class="box-title">{Lang::T('Contribution')} PHPNuxBill</h3>
            </div>
            <div class="box-body">
                {* brand / privacy: a link instead of the remote contributors image *}
                <a href="https://github.com/hotspotbilling/phpnuxbill/graphs/contributors" target="_blank" rel="noopener noreferrer">{Lang::T('PHPNuxBill contributors (GitHub)')}</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="box box-hovered mb20 box-primary">
            <div class="box-header">
                <h3 class="box-title">{Lang::T('Discussion – Get Help from the Community')}</h3>
            </div>
            <div class="box-body">{Lang::T('Join the discussion to find solutions and support from a community ready to help.')}</div>
            <div class="box-footer">
                <div class="btn-group btn-group-justified" role="group" aria-label="...">
                    <a href="https://github.com/hotspotbilling/phpnuxbill/discussions" target="_blank"
                        class="btn btn-primary btn-sm btn-block"><i class="ion ion-chatboxes"></i> {Lang::T('Github
                        Discussions')}</a>
                    <a href="https://t.me/phpnuxbill" target="_blank" class="btn btn-primary btn-sm btn-block"><i
                            class="ion ion-chatboxes"></i> {Lang::T('Telegram Group')}</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-sm-6">
        <div class="box box-hovered mb20 box-primary">
            <div class="box-header">
                <h3 class="box-title">{Lang::T('Donations')} 🇮🇩</h3>
            </div>
            <div class="box-body">{Lang::T('To support further development, please donate to iBNuX. Your donation will help ensure the continued development of this application.')}</div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <tbody>
                        <tr>
                            <td>BCA</td>
                            <td>5410-454-825</td>
                        </tr>
                        <tr>
                            <td>Mandiri</td>
                            <td>163-000-1855-793</td>
                        </tr>
                        <tr>
                            <td>Atas nama</td>
                            <td>Ibnu Maksum</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="box-footer">
                <div class="btn-group btn-group-justified" role="group" aria-label="...">
                    <a href="https://trakteer.id/ibnux" target="_blank"
                        class="btn btn-success text-black btn-sm btn-block">Trakteer</a>
                    <a href="https://karyakarsa.com/ibnux/support" target="_blank"
                        class="btn btn-info text-black btn-sm btn-block">karyakarsa</a>
                </div>
            </div>
        </div>
    </div>
    <div class="col-sm-6">
        <div class="box box-hovered mb20 box-primary">
            <div class="box-header">
                <h3 class="box-title">{Lang::T('Donations other than')} 🇮🇩</h3>
            </div>
            <div class="box-body">
                {Lang::T('Your donation will help support and continue the development of PHPNuxBill.')}
            </div>
            <div class="table-responsive">
                <table class="table table-bordered table-striped">
                    <tbody>
                        <tr>
                            <td>Bank Central Asia</td>
                            <td>5410-454-825</td>
                        </tr>
                        <tr>
                            <td>SWIFT/BIC</td>
                            <td>CENAIDJA</td>
                        </tr>
                        <tr>
                            <td>Jakarta</td>
                            <td>Indonesia</td>
                        </tr>
                        <tr>
                            <td>Account Name</td>
                            <td>Ibnu Maksum</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div class="box-footer">
                <div class="btn-group btn-group-justified" role="group" aria-label="...">
                    <a href="https://paypal.me/ibnux" target="_blank"
                        class="btn btn-primary btn-sm btn-block">Paypal</a>
                </div>
            </div>
        </div>
    </div>
</div>
<div class="row">
    <div class="col-sm-6">
        <div class="box box-hovered mb20 box-primary">
            <div class="box-header">
                <h3 class="box-title">{Lang::T('Chat with Me — Paid Support $50')}</h3>
            </div>
            <div class="box-body">
                {Lang::T('Confirm your donation to continue this paid support. Or, ask about alternative donations available to suit your needs.')}
            </div>
            <div class="box-footer">
                <a href="https://t.me/ibnux" target="_blank" class="btn btn-primary btn-sm btn-block">{Lang::T('Telegram')}</a>
            </div>
        </div>
        <div class="box box-primary box-hovered mb20 activities">
            <div class="box-header">
                <h3 class="box-title">{Lang::T('WhatsApp Gateway and Free Telegram Bot')}</h3>
            </div>
            <div class="box-body">
                {Lang::T('Connect your PHPNuxBill to WhatsApp efficiently using WhatsApp Gateway. Also, create Telegram bots easily and practically.')}
            </div>
            <div class="box-footer">
                <a href="https://wa.nux.my.id/login" target="_blank"
                    class="btn btn-primary btn-sm btn-block">wa.nux.my.id</a>
            </div>
        </div>
    </div>
    <div class="col-sm-6" id="update">
        <div class="box box-primary box-hovered mb20 activities">
            <div class="box-header">
                <h3 class="box-title">PHPNuxBill ({Lang::T('Original project')})</h3>
            </div>
            <div class="box-body">
                <b>PHPNuxBill</b>
                {Lang::T('is a Hotspot and PPPoE billing platform for Mikrotik developed using PHP. The application uses Mikrotik API to communicate with the router, ensuring efficient and easy integration. If you feel you get more benefits from this application, we would greatly appreciate your contribution through donation.')}<br>{Lang::T('Watch project –')} <a
                    href="https://github.com/hotspotbilling/phpnuxbill" target="_blank">{Lang::T('IN HERE')}</a>
            </div>
            <div class="box-footer">{Lang::T('Upstream changelog')}: <a id="latestVersion" href="https://github.com/hotspotbilling/phpnuxbill/blob/master/CHANGELOG.md" target="_blank" rel="noopener noreferrer">CHANGELOG.md</a></div>
            <div class="box-footer">
                {* WP2 / OD-2: the in-app web updater and rollback are disabled; updates are installed through
                   the reviewed release process and schema changes through the project migration runner. *}
                {Lang::T('In-app updates are disabled for this installation. Updates are installed through the reviewed release process.')}
            </div>
            <div class="box-footer">
                <div class="btn-group btn-group-justified" role="group" aria-label="...">
                    <a href="./CHANGELOG.md" target="_blank"
                        class="btn btn-default btn-sm btn-block">{Lang::T('Current Changelog')}</a>
                    <a href="https://github.com/hotspotbilling/phpnuxbill/blob/master/CHANGELOG.md" target="_blank"
                        class="btn btn-default btn-sm btn-block">{Lang::T('Repo Changelog')}</a>
                </div>
            </div>
        </div>

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
<script>
    // the remote version file is third-party data: text only, and a restricted token (never HTML)
    function nuxrSafeVersion(v) { v = String(v); return v.length <= 32 && /^[0-9A-Za-z._-]+$/.test(v) ? v : 'unknown'; }
    window.addEventListener('DOMContentLoaded', function() {
        $.getJSON("./version.json?" + Math.random(), function(data) {
            $('#currentVersion').text(nuxrSafeVersion(data.version));
        });
        // brand / privacy: the latest upstream version is a link (no automatic request to raw.githubusercontent.com)
    });
</script>
</div>
{include file="sections/footer.tpl"}
