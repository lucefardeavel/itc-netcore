<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'en'|escape}" dir="{$nuxr_dir|default:'ltr'|escape}">

<head>
    <meta charset="utf-8">
    {* WP6C: standalone on purpose (an internal error must not depend on the shell that may have failed); adaptive,
       zoom allowed, safe areas, the shell tokens; WP2 / OD-14: generic message + reference only *}
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{$error_title|escape}</title>
    <link rel="shortcut icon" href="{$app_url}/ui/ui/images/logo.png" type="image/x-icon" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/fonts/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-shell.css?wp7.1">
</head>

<body class="nuxr-shell nuxr-standalone">
    <main class="nuxr-standalone-main" id="nuxr-main">
        <section class="nuxr-card nuxr-state-page" aria-labelledby="nuxr-state-t">
            <div class="nuxr-empty">
                <i class="fa fa-exclamation-triangle" aria-hidden="true"></i>
                <h1 id="nuxr-state-t">{$error_title|escape}</h1>
                <p>{$error_message|escape}</p>
                <div class="nuxr-actions" style="justify-content:center">
                    <a class="nuxr-btn" href="javascript:history.back()"><i class="fa fa-arrow-left nuxr-dir-icon" aria-hidden="true"></i>{Lang::T('Back')}</a>
                    <a class="nuxr-btn nuxr-btn-primary" href="{$app_url}/?_route=dashboard"><i class="fa fa-home" aria-hidden="true"></i>{Lang::T('Home')}</a>
                </div>
            </div>
        </section>
    </main>
</body>

</html>
