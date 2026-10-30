<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'en'}" dir="{$nuxr_dir|default:'ltr'}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$_title} - {$_brand.company|escape}</title>
    <link rel="icon" href="{$app_url}/ui/ui/images/brand/favicon.svg" type="image/svg+xml" />
    <link rel="icon" href="{$app_url}/ui/ui/images/pwa/icon-192.png" type="image/png" sizes="192x192" />

    <script>
        var appUrl = '{$app_url}';
    </script>

    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/bootstrap.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/modern-AdminLTE.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/sweetalert2.min.css" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-customer-a11y.css?v3" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-brand.css?brand.2" />
    {if $nuxr_dir eq 'rtl'}
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-rtl-legacy.css?lab.4" />
    {/if}
    <script src="{$app_url}/ui/ui/scripts/sweetalert2.all.min.js"></script>



</head>

<body id="app" class="app off-canvas body-full nuxr-portal">
    {* brand: the saved theme applies before the first paint (same localStorage key as the portal toggle) *}
    <script>try { if (localStorage.getItem('mode') === 'dark') { document.body.classList.add('dark-mode'); } } catch (e) { }</script>
    <div class="container">
        <div class="form-head mb20">
            <h1 class="site-logo h2 mb5 mt5 text-center text-uppercase text-bold"
                style="text-shadow: 2px 2px 4px #757575;"><bdi>{$_brand.company|escape}</bdi></h1>
            <hr>
        </div>
        {if isset($notify)}
            <script>
                // Display SweetAlert toast notification
                Swal.fire({
                    icon: '{if $notify_t == "s"}success{else}warning{/if}',
                    title: '{$notify}',
                    toast: true,
                    position: 'top-end',
                    showConfirmButton: false,
                    timer: 5000,
                    timerProgressBar: true,
                    didOpen: (toast) => {
                        toast.addEventListener('mouseenter', Swal.stopTimer)
                        toast.addEventListener('mouseleave', Swal.resumeTimer)
                    }
                });
            </script>
        {/if}