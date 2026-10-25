<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'en'}" dir="{$nuxr_dir|default:'ltr'}">
{* WP6A: staff sign-in in the adaptive shell style: language / direction, zoom allowed, real labels, touch-sized
   controls. Same form, fields and CSRF as before. *}

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{Lang::T('Login')} - {$_brand.company|escape}</title>
    <link rel="icon" href="{$app_url}/ui/ui/images/brand/favicon.svg" type="image/svg+xml" />
    <link rel="icon" href="{$app_url}/ui/ui/images/pwa/icon-192.png" type="image/png" sizes="192x192" />
    {* WP7: staff PWA - the user-neutral manifest (fetched without credentials) and replaceable interim identity assets *}
    <link rel="manifest" href="{$app_url}/?_route=pwa/manifest">
    <meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)">
    <meta name="theme-color" content="#17263d" media="(prefers-color-scheme: dark)">
    <link rel="apple-touch-icon" href="{$app_url}/ui/ui/images/pwa/icon-180.png">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/bootstrap.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-brand.css?brand.1" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-shell.css?brand.1">
    <style>
        .nuxr-login { min-height: 100vh; display: grid; place-items: center; padding: 16px; }
        .nuxr-login-card { width: min(420px, 100%); }
        .nuxr-login-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; font-size: 20px; font-weight: 700; }
    </style>
</head>

<body class="nuxr-shell">
    {* brand: the saved theme applies before the first paint (no light flash; same localStorage key as nuxr-shell.js) *}
    <script>try { if (localStorage.getItem('mode') === 'dark') { document.body.classList.add('dark-mode'); } } catch (e) { }</script>
    <main class="nuxr-login">
        <div class="nuxr-login-card">
            <div class="nuxr-login-brand">{if $_brand.company_is_product}<span class="nuxr-brand-mark is-logo" aria-hidden="true"><img class="nc-on-light" src="{$app_url}/ui/ui/images/brand/netcore-mark.svg" alt="" width="36" height="36"><img class="nc-on-dark" src="{$app_url}/ui/ui/images/brand/netcore-mark-white.svg" alt="" width="36" height="36"></span>{else}<span class="nuxr-brand-mark" aria-hidden="true">{$_brand.mark|escape}</span>{/if}<bdi>{$_brand.company|escape}</bdi></div>
            <section class="nuxr-card">
                <div class="nuxr-card-head"><h1 style="margin:0;font-size:18px;font-weight:700">{Lang::T('Enter Admin Area')}</h1></div>
                <div class="nuxr-card-body">
                    {if isset($notify)}<div class="nuxr-alert is-danger" role="alert"><div>{$notify}</div></div>{/if}
                    <form action="{Text::url('admin/post')}" method="post" class="nuxr-fields">
                        <input type="hidden" name="csrf_token" value="{$csrf_token}">
                        <label for="nuxr-login-user">{Lang::T('Username')}</label>
                        <input id="nuxr-login-user" type="text" required class="form-control nuxr-input ltr" dir="ltr" name="username" autocomplete="username" autocapitalize="off" spellcheck="false">
                        <label for="nuxr-login-pass">{Lang::T('Password')}</label>
                        <input id="nuxr-login-pass" type="password" required class="form-control nuxr-input ltr" dir="ltr" name="password" autocomplete="current-password">
                        <button type="submit" class="nuxr-btn nuxr-btn-primary" style="margin-top:14px;min-height:48px">{Lang::T('Login')}</button>
                    </form>
                    <p style="margin:14px 0 0;text-align:center"><a href="{Text::url('login')}">{Lang::T('Go Back')}</a></p>
                </div>
            </section>
            {* product identity under the operator's sign-in card: the outlined ITC NetCore wordmark (light / reversed artwork,
               F-03 rule); the wordmark carries the English tagline, the interface language repeats it in Arabic *}
            <p class="itc-login-product"><span class="nc-login-wordmark"><img class="nc-wordmark nc-on-light" src="{$app_url}/ui/ui/images/brand/netcore-wordmark.svg" alt="{$_brand.name|escape} - {$_brand.tagline|escape}" width="230" height="80"><img class="nc-wordmark nc-on-dark" src="{$app_url}/ui/ui/images/brand/netcore-wordmark-white.svg" alt="{$_brand.name|escape} - {$_brand.tagline|escape}" width="230" height="80"></span>
                <span class="itc-login-tagline">{if $nuxr_dir eq 'rtl'}{Lang::T('ISP Management & Billing Platform')} &middot; {/if}<bdi>{$_brand.version|escape}</bdi></span></p>
        </div>
    </main>
{* WP7: staff PWA client (registration; prune on the staff login page) *}
{include file="admin/nuxr/pwa-config.tpl"}
<script src="{$app_url}/ui/ui/scripts/nuxr-pwa.js?wp7.3" data-nuxr-sw="{$app_url}/nuxr-sw.js"></script>
{include file="nuxr-csrf.tpl"}
</body>

</html>
