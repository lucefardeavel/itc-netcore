<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'en'}" dir="{$nuxr_dir|default:'ltr'}">
{* WP6A: adaptive AppShell for every staff page (OD-G). One navigation model ($_nav, Reseller\Ui\Navigation) rendered
   once: desktop sidebar >= 1200px, tablet rail 768-1199px, phone bottom navigation < 768px. Legacy pages render
   unchanged inside .content-wrapper. Navigation is presentation only; every route is authorized server-side. *}

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$_title|escape} - {$_c['CompanyName']|escape}</title>
    <link rel="shortcut icon" href="{$app_url}/ui/ui/images/logo.png" type="image/x-icon" />

    <script>
        var appUrl = '{$app_url}';
    </script>

    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/bootstrap.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/fonts/ionicons/css/ionicons.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/fonts/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/modern-AdminLTE.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/select2.min.css" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/select2-bootstrap.min.css" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/sweetalert2.min.css" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/plugins/pace.css" />
    {* OD-H: heavy libraries only where they are used *}
    {if in_array($_routes[0], ['community', 'pages', 'widgets'])}
        <link rel="stylesheet" href="{$app_url}/ui/ui/summernote/summernote.min.css" />
    {/if}
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/phpnuxbill.css?2025.2.4" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-shell.css?wp6a.1" />
    {if $nuxr_dir eq 'rtl'}
        <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-rtl-legacy.css?wp6a.1" />
    {/if}

    <script src="{$app_url}/ui/ui/scripts/sweetalert2.all.min.js"></script>
    {if in_array($_routes[0], ['dashboard', 'reports', ''])}
        {* OD-H residual: Chart.js stays a CDN asset, loaded only by the pages that draw charts *}
        <script src="https://cdn.jsdelivr.net/npm/chart.js@3.5.1/dist/chart.min.js"></script>
    {/if}
    {if isset($xheader)}
        {$xheader}
    {/if}

</head>

<body class="hold-transition nuxr-shell{if $_kolaps} nuxr-collapsed{/if}">
    <a class="nuxr-skip" href="#nuxr-main">{Lang::T('Skip to content')}</a>
    <div class="wrapper">
        <header class="nuxr-topbar" role="banner">
            <a href="{Text::url('dashboard')}" class="nuxr-brand" aria-label="{Lang::T('Home')}">
                <span class="nuxr-brand-mark" aria-hidden="true">{$_c['CompanyName']|escape|truncate:1:'':true}</span>
                <span class="nuxr-brand-name">{$_c['CompanyName']|escape}</span>
            </a>
            <span class="nuxr-topbar-title" aria-hidden="true">{$_title|escape}</span>
            {if $_can['customer.read']}
                <form class="nuxr-search" role="search" method="get" action="{$app_url}/" data-nuxr-search="{Text::url('search_user')}">
                    <input type="hidden" name="_route" value="customers/list">
                    <label class="sr-only" for="nuxr-search-input">{Lang::T('Search subscribers')}</label>
                    <i class="fa fa-search" aria-hidden="true"></i>
                    <input id="nuxr-search-input" class="nuxr-search-input" type="search" name="search" autocomplete="off" minlength="2" maxlength="64"
                        placeholder="{Lang::T('Name, username, phone, PPPoE, IP or #ID')}" role="combobox" aria-expanded="false"
                        aria-controls="nuxr-search-results" aria-autocomplete="list">
                    <kbd class="nuxr-kbd" aria-hidden="true">/</kbd>
                    <div id="nuxr-search-results" class="nuxr-search-results" role="listbox" aria-label="{Lang::T('Search results')}" hidden></div>
                </form>
                <button type="button" class="nuxr-icon-btn nuxr-search-open" data-nuxr-open="nuxr-search-sheet" aria-label="{Lang::T('Search subscribers')}">
                    <i class="fa fa-search" aria-hidden="true"></i>
                </button>
            {/if}
            <details class="nuxr-menu nuxr-user">
                <summary class="nuxr-icon-btn" aria-label="{Lang::T('Account menu')}">
                    <span class="nuxr-avatar nuxr-avatar-initial" aria-hidden="true">{$_admin['fullname']|escape|truncate:1:'':true}</span>
                    <span class="nuxr-user-name">{$_admin['fullname']|escape}</span>
                </summary>
                <div class="nuxr-menu-panel">
                    <div class="nuxr-menu-head"><strong>{$_admin['fullname']|escape}</strong><small>{Lang::T($_admin['user_type'])}</small></div>
                    <a href="{Text::url('settings/users-view/', $_admin['id'])}"><i class="fa fa-user" aria-hidden="true"></i> {Lang::T('My Account')}</a>
                    <a href="{Text::url('settings/change-password')}"><i class="fa fa-key" aria-hidden="true"></i> {Lang::T('Change Password')}</a>
                    <button type="button" data-nuxr-lang="{if $nuxr_dir eq 'rtl'}english{else}arabic{/if}"><i class="fa fa-language" aria-hidden="true"></i>
                        {if $nuxr_dir eq 'rtl'}English{else}العربية{/if}</button>
                    <button type="button" data-nuxr-theme><i class="fa fa-adjust" aria-hidden="true"></i> {Lang::T('Light / dark theme')}</button>
                    <a href="{Text::url('logout')}" class="nuxr-menu-logout"><i class="fa fa-sign-out" aria-hidden="true"></i> {Lang::T('Logout')}</a>
                </div>
            </details>
        </header>

        {if isset($_nav)}
            <aside class="nuxr-sidebar" aria-label="{Lang::T('Main navigation')}">
                <nav>
                    {include file="admin/nuxr/nav-groups.tpl" nuxr_place="sidebar"}
                </nav>
            </aside>
            <nav class="nuxr-rail" aria-label="{Lang::T('Primary navigation')}">
                {include file="admin/nuxr/nav-primary.tpl"}
            </nav>
        {/if}

        <div class="content-wrapper" id="nuxr-main" tabindex="-1">
            {if $_c['maintenance_mode'] == 1}
                <div class="nuxr-banner nuxr-banner-warning" role="status">
                    {Lang::T('The website is currently in maintenance mode, this means that some or all functionality may be unavailable to regular users during this time.')}
                    {if $_can['system.admin']}<a href="{Text::url('settings/maintenance')}">{Lang::T('Turn Off')}</a>{/if}
                </div>
            {/if}
            <section class="content-header">
                <h1>{$_title|escape}</h1>
            </section>

            <section class="content">
                {if $nuxr_schema_not_ready}
                    <div class="alert alert-danger" role="alert">{Lang::T('Reseller schema is not ready: reseller and service operations are disabled for every role until the project migrations (R0001-R0006) are applied and verified.')}</div>
                {/if}
                {if isset($notify)}
                    <script>
                        Swal.fire({
                            icon: '{if $notify_t == "s"}success{elseif $notify_t == "w"}warning{else}error{/if}',
                            title: '{$notify|escape:'javascript'}',
                            position: 'top-end',
                            showConfirmButton: false,
                            timer: 6000,
                            timerProgressBar: true,
                            didOpen: (toast) => {
                                toast.addEventListener('mouseenter', Swal.stopTimer)
                                toast.addEventListener('mouseleave', Swal.resumeTimer)
                            }
                        });
                    </script>
                {/if}
