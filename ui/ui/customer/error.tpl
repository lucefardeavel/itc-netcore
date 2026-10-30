<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'en'}" dir="{$nuxr_dir|default:'ltr'}">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{ucwords(Lang::T("Error"))} - {$_brand.company|escape}</title>
    <meta content="width=device-width, initial-scale=1" name="viewport">
    <link rel="icon" href="{$app_url}/ui/ui/images/brand/favicon.svg" type="image/svg+xml" />
    <link rel="icon" href="{$app_url}/ui/ui/images/pwa/icon-192.png" type="image/png" sizes="192x192" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/bootstrap.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/modern-AdminLTE.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-brand.css?brand.2" />
</head>

<body class="hold-transition lockscreen nuxr-portal">
    <div class="lockscreen-wrapper">
        <div class="panel panel-danger">
            <div class="panel-heading">{ucwords(Lang::T("Internal Error"))}</div>
            <div class="panel-body">
                {Lang::T("Sorry, the software failed to process the request, if it still happening, please tell")}
                {$_brand.company|escape}
                {if $error_ref}<br><small>{Lang::T('Please quote reference')} {$error_ref|escape}</small>{/if}
            </div>
            <div class="panel-footer">
                <a href="{$url}" id="button" class="btn btn-danger btn-block">{Lang::T('Try Again')}</a>
            </div>
        </div>
        <div class="lockscreen-footer text-center">
            {$_brand.company|escape}
        </div>
    </div>

    {if $_c['tawkto'] != ''}
        <!--Start of Tawk.to Script-->
        <script type="text/javascript">
            var Tawk_API = Tawk_API || {},
                Tawk_LoadStart = new Date();
            (function() {
                var s1 = document.createElement("script"),
                    s0 = document.getElementsByTagName("script")[0];
                s1.async = true;
                s1.src='https://embed.tawk.to/{$_c['tawkto']}';
                s1.charset = 'UTF-8';
                s1.setAttribute('crossorigin', '*');
                s0.parentNode.insertBefore(s1, s0);
            })();
        </script>
        <!--End of Tawk.to Script-->
    {/if}

</body>

</html>