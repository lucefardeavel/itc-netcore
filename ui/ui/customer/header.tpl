<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'en'}" dir="{$nuxr_dir|default:'ltr'}" class="has-aside-left has-aside-mobile-transition has-navbar-fixed-top has-aside-expanded">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{$_title} - {$_brand.company|escape}</title>

    <script>
        var appUrl = '{$app_url}';
    </script>

    <link rel="icon" href="{$app_url}/ui/ui/images/pwa/icon-192.png" type="image/png" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/bootstrap.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/fonts/ionicons/css/ionicons.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/fonts/font-awesome/css/font-awesome.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/modern-AdminLTE.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/sweetalert2.min.css" />
    <script src="{$app_url}/ui/ui/scripts/sweetalert2.all.min.js"></script>
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/phpnuxbill.customer.css?2025.2.4" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-customer-a11y.css?v3" />
    {if $nuxr_dir eq 'rtl'}
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-rtl-legacy.css?lab.3" />
    {/if}

    <style>

    </style>

    {if isset($xheader)}
        {$xheader}
    {/if}

</head>

<body class="hold-transition modern-skin-dark sidebar-mini">
    <div class="wrapper">
        <header class="main-header" style="position:fixed; width: 100%">
            <a href="{Text::url('home')}" class="logo">
                <span class="logo-mini"><b>{$_brand.mark|escape}</b></span>
                <span class="logo-lg"><bdi>{$_brand.company|escape}</bdi></span>
            </a>
            <nav class="navbar navbar-static-top">
                <a href="#" class="sidebar-toggle" data-toggle="push-menu" role="button">
                    <span class="sr-only">{Lang::T('Toggle navigation')}</span>
                </a>
                <div class="navbar-custom-menu">
                    <ul class="nav navbar-nav">
                        <li>
                            <a class="toggle-container" href="#" role="button" aria-label="{Lang::T('Dark mode')}" aria-pressed="false">
                                <i class="toggle-icon" id="toggleIcon" aria-hidden="true">🌜</i>
                            </a>
                        </li>
                        <li class="dropdown tasks-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown" aria-expanded="true" aria-label="{Lang::T('Language')}">
                                <i class="fa fa-flag-o" aria-hidden="true"></i>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <!-- inner menu: contains the actual data -->
                                    <ul class="menu" api-get-text="{Text::url('autoload_user/language&select=',$user_language)}"></ul>
                                </li>
                            </ul>
                        </li>
                        <li class="dropdown notifications-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
                                <i class="fa fa-envelope-o" aria-hidden="true"></i>
                                <span class="sr-only">{Lang::T('Inbox')}</span>
                                <span class="label label-warning"
                                    api-get-text="{Text::url('autoload_user/inbox_unread')}"></span>
                            </a>
                            <ul class="dropdown-menu">
                                <li>
                                    <!-- inner menu: contains the actual data -->
                                    <ul class="menu" api-get-text="{Text::url('autoload_user/inbox')}"></ul>
                                </li>
                                <li class="footer"><a href="{Text::url('mail')}">{Lang::T('Inbox')}</a></li>
                            </ul>
                        </li>
                        <li class="dropdown user user-menu">
                            <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                                {if $_c['enable_balance'] == 'yes'}
                                    <span
                                        style="color: whitesmoke;">&nbsp;{Lang::moneyFormat($_user['balance'])}&nbsp;</span>
                                {else}
                                    <span>{$_user['fullname']|escape}</span>
                                {/if}
                                <img src="{$app_url}/{$UPLOAD_PATH}{$_user['photo']}.thumb.jpg"
                                    onerror="this.src='{$app_url}/{$UPLOAD_PATH}/user.default.jpg'" class="user-image"
                                    alt="User Image">
                            </a>
                            <ul class="dropdown-menu">
                                <li class="user-header">
                                    <img src="{$app_url}/{$UPLOAD_PATH}{$_user['photo']}.thumb.jpg"
                                        onerror="this.src='{$app_url}/{$UPLOAD_PATH}/user.default.jpg'" class="img-circle"
                                        alt="User Image">

                                    <p>
                                        {$_user['fullname']|escape}
                                        <small>{$_user['phonenumber']|escape}<br>
                                            {$_user['email']|escape}</small>
                                    </p>
                                </li>
                                <li class="user-body">
                                    <div class="row">
                                        <div class="col-xs-7 text-center text-sm">
                                            <a href="{Text::url('accounts/change-password')}"><i class="ion ion-settings"></i>
                                                {Lang::T('Change Password')}</a>
                                        </div>
                                        <div class="col-xs-5 text-center text-sm">
                                            <a href="{Text::url('accounts/profile')}"><i class="ion ion-person"></i>
                                                {Lang::T('My Account')}</a>
                                        </div>
                                    </div>
                                </li>
                                <li class="user-footer">
                                    <div class="pull-right">
                                        <a href="{Text::url('logout')}" class="btn btn-default btn-flat"><i
                                                class="ion ion-power"></i> {Lang::T('Logout')}</a>
                                    </div>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </div>
            </nav>
        </header>

        <aside class="main-sidebar" style="position:fixed;">
            <section class="sidebar">
                <ul class="sidebar-menu" data-widget="tree">
                    <li {if $_system_menu eq 'home'}class="active" {/if}>
                        <a href="{Text::url('home')}">
                            <i class="ion ion-monitor"></i>
                            <span>{Lang::T('Dashboard')}</span>
                        </a>
                    </li>
                    {$_MENU_AFTER_DASHBOARD}
                    <li {if $_system_menu eq 'inbox'}class="active" {/if}>
                        <a href="{Text::url('mail')}">
                            <i class="fa fa-envelope"></i>
                            <span>{Lang::T('Inbox')}</span>
                        </a>
                    </li>
                    {$_MENU_AFTER_INBOX}
                    {* lab UX-2: a reseller-managed customer ($nuxr_owned, assigned by User::_info) cannot redeem a voucher or
                       buy here (OD-11 refusal); no dead entry points *}
                    {if $_c['disable_voucher'] != 'yes' && !$nuxr_owned}
                        <li {if $_system_menu eq 'voucher'}class="active" {/if}>
                            <a href="{Text::url('voucher/activation')}">
                                <i class="fa fa-ticket"></i>
                                <span>Voucher</span>
                            </a>
                        </li>
                    {/if}
                    {if $_c['payment_gateway'] != 'none' or $_c['payment_gateway'] == '' }
                        {if $_c['enable_balance'] == 'yes' && !$nuxr_owned}
                            <li {if $_system_menu eq 'balance'}class="active" {/if}>
                                <a href="{Text::url('order/balance')}">
                                    <i class="ion ion-ios-cart"></i>
                                    <span>{Lang::T('Buy Balance')}</span>
                                </a>
                            </li>
                        {/if}
                        {if !$nuxr_owned}
                        <li {if $_system_menu eq 'package'}class="active" {/if}>
                            <a href="{Text::url('order/package')}">
                                <i class="ion ion-ios-cart"></i>
                                <span>{Lang::T('Buy Package')}</span>
                            </a>
                        </li>
                        {/if}
                        <li {if $_system_menu eq 'history'}class="active" {/if}>
                            <a href="{Text::url('order/history')}">
                                <i class="fa fa-file-text"></i>
                                <span>{Lang::T('Payment History')}</span>
                            </a>
                        </li>
                    {/if}
                    {$_MENU_AFTER_ORDER}
                    <li {if $_system_menu eq 'list-activated'}class="active" {/if}>
                        <a href="{Text::url('voucher/list-activated')}">
                            <i class="fa fa-list-alt"></i>
                            <span>{Lang::T('Activation History')}</span>
                        </a>
                    </li>
                    {$_MENU_AFTER_HISTORY}
                </ul>
            </section>
        </aside>

        <div class="content-wrapper">
            <section class="content-header">
                <h1>
                    {$_title}
                </h1>
            </section>
            <section class="content">


                {if isset($notify)}
                    <script>
                        // Display SweetAlert toast notification
                        Swal.fire({
                            icon: '{if $notify_t == "s"}success{else}warning{/if}',
                            title: '{$notify}',
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
