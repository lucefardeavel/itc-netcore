<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'en'}" dir="{$nuxr_dir|default:'ltr'}">
{* WP6A: staff sign-in in the adaptive shell style: language / direction, zoom allowed, real labels, touch-sized
   controls. Same form, fields and CSRF as before. *}

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{Lang::T('Login')} - {$_c['CompanyName']|escape}</title>
    <link rel="shortcut icon" href="{$app_url}/ui/ui/images/logo.png" type="image/x-icon" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/bootstrap.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-shell.css?wp6a.1">
    <style>
        .nuxr-login { min-height: 100vh; display: grid; place-items: center; padding: 16px; }
        .nuxr-login-card { width: min(420px, 100%); }
        .nuxr-login-brand { display: flex; align-items: center; gap: 12px; margin-bottom: 18px; font-size: 20px; font-weight: 700; }
    </style>
</head>

<body class="nuxr-shell">
    <main class="nuxr-login">
        <div class="nuxr-login-card">
            <div class="nuxr-login-brand"><span class="nuxr-brand-mark" aria-hidden="true">{$_c['CompanyName']|truncate:1:'':true|escape}</span><span>{$_c['CompanyName']|escape}</span></div>
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
        </div>
    </main>
{include file="nuxr-csrf.tpl"}
</body>

</html>
