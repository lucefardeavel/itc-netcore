<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'ar'}" dir="{$nuxr_dir|default:'rtl'}">

<head>
    <title>{$_title}</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="shortcut icon" type="image/x-icon" href="{$app_url}/ui/ui/images/favicon.ico">
    <style>
        .ukuran {
            size: A4;
        }

        body,
        td,
        th {
            font-size: 12px;
            font-family: Segoe, "Segoe UI", "DejaVu Sans", "Trebuchet MS", Verdana, sans-serif;
        }

        page[size="A4"] {
            background: white;
            width: 21cm;
            height: 29.7cm;
            display: block;
            margin: 0 auto;
            margin-bottom: 0.5cm;

            html,
            body {
                width: 210mm;
                height: 297mm;
            }
        }

        @media print {
            body {
                size: auto;
                margin: 0;
                box-shadow: 0;
            }

            page[size="A4"] {
                margin: 0;
                size: auto;
                box-shadow: 0;
            }

            .page-break {
                display: block;
                page-break-before: always;
            }

            .no-print,
            .no-print * {
                display: none !important;
            }
        }
    </style>
</head>

<body>
    <page size="A4">
        <form method="post" action="{Text::url('')}plan/print-voucher/" class="no-print">
            <table width="100%" border="0" cellspacing="0" cellpadding="1" class="btn btn-default btn-sm">
                <tr>
                    <td>{Lang::T('Printer')} <select name="format"><option value="a4" selected>{Lang::T('A4 sheet')}</option><option value="thermal80">{Lang::T('Thermal 80 mm')}</option></select></td>
                    <td>{Lang::T('Start after card number')} <input type="text" name="from_id" style="width:40px" value="{$from_id}"> {Lang::T('Number of cards')}
                        <input type="text" name="limit" style="width:40px" value="{$limit}">
                    </td>
                    <td>{Lang::T('Cards per row')} <input type="text" style="width:40px" name="vpl" value="{$vpl}">
                    </td>
                    <td>{Lang::T('New page after')} <input type="text" style="width:40px" name="pagebreak" value="{$pagebreak}">
                    </td>
                    <td>{Lang::T('Plan')} <select id="plan_id" name="planid" style="width:50px">
                            <option value="0">{Lang::T('All')}</option>
                            {foreach $plans as $plan}
                                <option value="{$plan['id']}" {if $plan['id']==$planid}selected{/if}>{$plan['name_plan']|escape}
                                </option>
                            {/foreach}
                        </select></td>
                    <td>{Lang::T('Created on')} <select id="selected_datetime" name="selected_datetime" style="width:50px">
                            <option value="">{Lang::T('All')}</option>
                            {foreach $createdate as $date}
                                <option value="{$date.created_datetime}"
                                    {if $date.created_datetime eq $selected_datetime}selected{/if}>
                                    {$date.created_datetime} ({$date.voucher_count})
                                </option>
                            {/foreach}
                        </select></td>
                    <td><button type="submit">{Lang::T('Show')}</button></td>
                </tr>
            </table>
            <hr>
            <center><button type="button" onclick="window.print()"
                    class="btn btn-default btn-sm no-print">{Lang::T('Click Here to Print')}</button><br>
                {Lang::T('Cards shown')}: {$voucher|@count} &middot; {Lang::T('Unused vouchers in this selection')}: {$vc}
            </center>
        </form>
        <div id="printable" align="center">
            <hr> {$n = 1} {foreach $voucher as $vs} {$jml = $jml + 1}
                {if $n == 1}
                    <table>
                        <tr>
                        {/if}
                        <td>{$vs}</td>
                        {if $n == $vpl}
                    </table>
                    {$n = 1} {else} {$n = $n + 1} {/if} {if $jml == $pagebreak} {$jml = 0}
                    <!-- pageBreak -->
                    <div class="page-break">
                        <div class="no-print" style="background-color: #E91E63; color:#FFF;" align="center">-- pageBreak --
                            <hr>
                        </div>
                    </div>
                {/if}
            {/foreach}
        </div>
    </page>
    <script src="{$app_url}/ui/ui/scripts/jquery.min.js"></script>
    {if isset($xfooter)} {$xfooter} {/if}
    <script>
        jQuery(document).ready(function() {
            // initiate layout and plugins
            $("#actprint").click(function() {
                window.print();
                return false;
            });
        });
    </script>

{include file="nuxr-csrf.tpl"}
</body>

</html>