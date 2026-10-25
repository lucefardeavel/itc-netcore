            </section>
        </div>
        <footer class="main-footer nuxr-footer">
            {* Product identity (Reseller\Ui\Brand) and the credits of the original project and the theme (kept).
               UX-8: the version links to the About page only for the roles that may open it (RouteMap community:
               system.admin); for the others it is plain text (presentation only, the route is checked server-side) *}
            {assign var=nuxr_about_link value=NuxrUi::allowed('community')}
            <div class="pull-right itc-footer-version">{if $nuxr_about_link}<a id="version" href="{Text::url('community')}"><bdi>{$_brand.name|escape} {$_brand.version|escape}</bdi></a>{else}<span id="version"><bdi>{$_brand.name|escape} {$_brand.version|escape}</bdi></span>{/if}</div>
            <span class="itc-footer-product"><bdi class="itc-name">{$_brand.name|escape}</bdi> &middot; {Lang::T('ISP Management & Billing Platform')}
                &middot; {Lang::T('Developed and maintained by')} <bdi>{$_brand.vendor|escape}</bdi></span>
            <span class="itc-footer-credits">{Lang::T('Based on the open-source project')} <a href="{$_brand.upstream_url|escape}" rel="nofollow noreferrer noopener"
                target="_blank">{$_brand.upstream|escape}</a> <bdi>({$_brand.upstream_author|escape}, {$_brand.license|escape})</bdi>
                &middot; {Lang::T('Interface theme')} <a href="https://adminlte.io/" rel="nofollow noreferrer noopener" target="_blank">AdminLTE</a></span>
        </footer>
    </div>

    {if isset($_nav)}
        <nav class="nuxr-bottomnav" aria-label="{Lang::T('Primary navigation')}">
            {include file="admin/nuxr/nav-primary.tpl"}
        </nav>

        <dialog id="nuxr-more-sheet" class="nuxr-sheet" aria-labelledby="nuxr-more-title">
            <div class="nuxr-sheet-head"><h2 id="nuxr-more-title">{Lang::T('More')}</h2>
                <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
            <div class="nuxr-sheet-body">{include file="admin/nuxr/nav-groups.tpl" nuxr_place="sheet"}
                {* WP7: user-initiated install *}
                <ul class="nuxr-sheet-list"><li><button type="button" data-nuxr-install hidden><i class="fa fa-download fa-fw" aria-hidden="true"></i><span>{Lang::T('Install app')}</span></button></li></ul>
                {* product identity on phone / tablet, where the footer is not shown (WP6A) *}
                <p class="itc-sheet-product">{if $nuxr_about_link}<a href="{Text::url('community')}"><bdi>{$_brand.name|escape} {$_brand.version|escape}</bdi></a>{else}<bdi>{$_brand.name|escape} {$_brand.version|escape}</bdi>{/if}</p>
            </div>
        </dialog>

        {if $_nav.quick}
            <dialog id="nuxr-quick-sheet" class="nuxr-sheet" aria-labelledby="nuxr-quick-title">
                <div class="nuxr-sheet-head"><h2 id="nuxr-quick-title">{Lang::T('Quick action')}</h2>
                    <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
                <ul class="nuxr-sheet-list">
                    {foreach $_nav.quick as $q}
                        <li><a href="{Text::url($q.route)}"><i class="fa {$q.icon} fa-fw" aria-hidden="true"></i><span>{Lang::T($q.label)}</span><i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></a></li>
                    {/foreach}
                </ul>
            </dialog>
        {/if}

        {if $_can['customer.read']}
            <dialog id="nuxr-search-sheet" class="nuxr-sheet nuxr-sheet-full" aria-labelledby="nuxr-search-title">
                <div class="nuxr-sheet-head"><h2 id="nuxr-search-title">{Lang::T('Search subscribers')}</h2>
                    <button type="button" class="nuxr-icon-btn" data-nuxr-close aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></button></div>
                <form class="nuxr-search nuxr-search-sheet-form" role="search" method="get" action="{$app_url}/" data-nuxr-search="{Text::url('search_user')}">
                    <input type="hidden" name="_route" value="customers/list">
                    <label class="sr-only" for="nuxr-search-sheet-input">{Lang::T('Search subscribers')}</label>
                    <input id="nuxr-search-sheet-input" class="nuxr-search-input" type="search" name="search" autocomplete="off" minlength="2" maxlength="64"
                        placeholder="{Lang::T('Name, username, phone, PPPoE, IP or #ID')}" role="combobox" aria-expanded="false"
                        aria-controls="nuxr-search-sheet-results" aria-autocomplete="list">
                    <div id="nuxr-search-sheet-results" class="nuxr-search-results" role="listbox" aria-label="{Lang::T('Search results')}" hidden></div>
                </form>
            </dialog>
        {/if}
    {/if}
    <div class="sr-only" role="status" aria-live="polite" id="nuxr-live"></div>

    <script src="{$app_url}/ui/ui/scripts/jquery.min.js"></script>
    <script src="{$app_url}/ui/ui/scripts/bootstrap.min.js"></script>
    <script src="{$app_url}/ui/ui/scripts/adminlte.min.js"></script>
    <script src="{$app_url}/ui/ui/scripts/plugins/select2.min.js"></script>
    <script src="{$app_url}/ui/ui/scripts/pace.min.js"></script>
    {if in_array($_routes[0], ['community', 'pages', 'widgets'])}
        <script src="{$app_url}/ui/ui/summernote/summernote.min.js"></script>
    {/if}
    <script src="{$app_url}/ui/ui/scripts/custom.js?2025.2.5"></script>
    <script>
        window.nuxrShell = {
            searching: '{Lang::T('Searching...')|escape:'javascript'}',
            minChars: '{Lang::T('Type at least 2 characters.')|escape:'javascript'}',
            failed: '{Lang::T('Search is unavailable. Try again.')|escape:'javascript'}',
            working: '{Lang::T('Working...')|escape:'javascript'}',
            show: '{Lang::T('Show')|escape:'javascript'}',
            askTitle: '{Lang::T('Please confirm')|escape:'javascript'}',
            cancel: '{Lang::T('Cancel')|escape:'javascript'}',
            confirm: '{Lang::T('Confirm')|escape:'javascript'}',
            close: '{Lang::T('Close')|escape:'javascript'}'
        };
    </script>
    <script src="{$app_url}/ui/ui/scripts/nuxr-shell.js?wp6c.3"></script>
    {* WP7: staff PWA client (before the CSRF shim, whose mutation paths consult its gate) *}
    {include file="admin/nuxr/pwa-config.tpl"}
    <script src="{$app_url}/ui/ui/scripts/nuxr-pwa.js?wp7.3" data-nuxr-sw="{$app_url}/nuxr-sw.js"></script>

    {if isset($xfooter)}
        {$xfooter}
    {/if}
    {literal}
        <script>
            var listAttApi;
            var posAttApi = 0;
            $(document).ready(function() {
                $('.select2').select2({theme: "bootstrap"});
                $('.select2tag').select2({theme: "bootstrap", tags: true});
                setTimeout(() => {
                    listAttApi = document.querySelectorAll(`[api-get-text]`);
                    apiGetText();
                }, 500);
            });

            // legacy pages: window.confirm based confirmation (the WP6A screens use the ConfirmSheet / DangerDialog)
            function ask(field, text) {
                return confirm(text);
            }

            function apiGetText() {
                var el = listAttApi[posAttApi];
                if (el != undefined) {
                    $.get(el.getAttribute('api-get-text'), function(data) {
                        el.innerHTML = data;
                        posAttApi++;
                        if (posAttApi < listAttApi.length) {
                            apiGetText();
                        }
                    });
                }
            }

            function setKolaps() {
                var kolaps = getCookie('kolaps');
                if (kolaps) {
                    setCookie('kolaps', false, 30);
                } else {
                    setCookie('kolaps', true, 30);
                }
                return true;
            }

            function setCookie(name, value, days) {
                var expires = "";
                if (days) {
                    var date = new Date();
                    date.setTime(date.getTime() + (days * 24 * 60 * 60 * 1000));
                    expires = "; expires=" + date.toUTCString();
                }
                document.cookie = name + "=" + (value || "") + expires + "; path=/";
            }

            function getCookie(name) {
                var nameEQ = name + "=";
                var ca = document.cookie.split(';');
                for (var i = 0; i < ca.length; i++) {
                    var c = ca[i];
                    while (c.charAt(0) == ' ') c = c.substring(1, c.length);
                    if (c.indexOf(nameEQ) == 0) return c.substring(nameEQ.length, c.length);
                }
                return null;
            }

            $(function() {
                $('[data-toggle="tooltip"]').tooltip()
            })
            $("[data-toggle=popover]").popover();
        </script>
    {/literal}

    {include file="nuxr-csrf.tpl"}
</body>

</html>
