<!DOCTYPE html>
<html lang="{$nuxr_lang|default:'ar'}" dir="{$nuxr_dir|default:'rtl'}">
{* Voucher cards for an 80 mm thermal printer (owner request 2026-11-09): one card after another on the roll.
   Hotspot card: the code is the user name and the password. Activation card (PPPoE): a recharge code the subscriber
   enters in the portal or gives to the reseller. Same voucher selection and permissions as the A4 sheet. *}
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$_title|escape}</title>
    <link rel="icon" href="{$app_url}/ui/ui/images/brand/favicon.svg" type="image/svg+xml" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-brand.css?brand.2" />
{literal}
    <style>
        @page { size: 80mm auto; margin: 0; }
        * { box-sizing: border-box; }
        body { margin: 0; background: #eef2f7; color: #000; font-family: "Noto Sans Arabic", Inter, Tahoma, sans-serif; }
        .controls { max-width: 640px; margin: 16px auto; background: #fff; border: 1px solid #d8e2ee; border-radius: 12px; padding: 14px 16px; }
        .controls h1 { font-size: 18px; margin: 0 0 10px; }
        .controls form { display: flex; flex-wrap: wrap; gap: 10px 14px; align-items: end; }
        .controls label { display: flex; flex-direction: column; gap: 4px; font-size: 13px; font-weight: 600; }
        .controls input, .controls select { min-height: 36px; border: 1px solid #c6d2e1; border-radius: 8px; padding: 4px 8px; font: inherit; }
        .controls .count { margin: 10px 0 0; font-size: 13px; color: #44546a; }
        .btn { min-height: 38px; border: 0; border-radius: 8px; padding: 6px 16px; font: inherit; font-weight: 700; cursor: pointer; background: #e6edf6; color: #193768; }
        .btn-primary { background: #245d99; color: #fff; }
        .roll { width: 80mm; margin: 0 auto 24px; background: #fff; padding: 2mm 4mm; }
        .card { break-inside: avoid; page-break-inside: avoid; padding: 3mm 0 4mm; border-bottom: 1px dashed #000; text-align: center; }
        .card:last-child { border-bottom: 0; }
        .company { font-size: 15px; font-weight: 700; margin: 0; }
        .kind { display: inline-block; margin: 1.5mm 0 2mm; padding: 0.6mm 3mm; border: 1px solid #000; border-radius: 3mm; font-size: 12px; font-weight: 700; }
        .facts { width: 100%; border-collapse: collapse; font-size: 12px; margin: 0 0 2mm; }
        .facts th { text-align: start; font-weight: 600; padding: 0.6mm 0; }
        .facts td { text-align: end; font-weight: 700; padding: 0.6mm 0; }
        .price { font-size: 20px; font-weight: 800; margin: 1mm 0; }
        .code-label { font-size: 11px; margin: 1mm 0 0.5mm; }
        .code { direction: ltr; unicode-bidi: isolate; font-family: "Courier New", Consolas, monospace; font-size: 22px; font-weight: 800;
                letter-spacing: 1.5px; border: 1.5px solid #000; border-radius: 2mm; padding: 1.5mm 1mm; margin: 0 0 2mm; word-break: break-all; }
        .qr img { width: 26mm; height: 26mm; image-rendering: pixelated; }
        .howto { font-size: 11px; line-height: 1.5; margin: 1.5mm 0 0; }
        .serial { font-size: 10px; margin: 1mm 0 0; direction: ltr; }
        .empty { text-align: center; padding: 10mm 0; font-size: 13px; }
        @media print {
            body { background: #fff; }
            .controls { display: none !important; }
            .roll { margin: 0; padding: 0 3mm; width: 80mm; }
        }
    </style>
{/literal}
</head>

<body>
    <section class="controls">
        <h1>{Lang::T('Print vouchers')}</h1>
        <form method="post" action="{Text::url('')}plan/print-voucher/">
            <label>{Lang::T('Printer')}
                <select name="format">
                    <option value="thermal80" selected>{Lang::T('Thermal 80 mm')}</option>
                    <option value="a4">{Lang::T('A4 sheet')}</option>
                </select></label>
            <label>{Lang::T('Plan')}
                <select name="planid">
                    <option value="0">{Lang::T('All')}</option>
                    {foreach $plans as $plan}
                        <option value="{$plan['id']}" {if $plan['id']==$planid}selected{/if}>{$plan['name_plan']|escape}</option>
                    {/foreach}
                </select></label>
            <label>{Lang::T('Created on')}
                <select name="selected_datetime">
                    <option value="">{Lang::T('All')}</option>
                    {foreach $createdate as $date}
                        <option value="{$date.created_datetime|escape}" {if $date.created_datetime eq $selected_datetime}selected{/if}>{if $date.created_datetime eq 'Today'}{Lang::T('Today')}{else}{$date.created_datetime|escape}{/if} ({$date.voucher_count})</option>
                    {/foreach}
                </select></label>
            <label>{Lang::T('Number of cards')}
                <input type="number" name="limit" min="1" max="500" value="{$limit|escape}" style="width:90px"></label>
            <label>{Lang::T('Start after card number')}
                <input type="number" name="from_id" min="0" value="{$from_id|escape}" style="width:90px"></label>
            <button type="submit" class="btn">{Lang::T('Show')}</button>
            <button type="button" class="btn btn-primary" onclick="window.print()">{Lang::T('Print')}</button>
        </form>
        <p class="count">{Lang::T('Cards shown')}: <bdi>{$cards|@count}</bdi> &middot; {Lang::T('Unused vouchers in this selection')}: <bdi>{$vc}</bdi></p>
    </section>

    <main class="roll">
        {foreach $cards as $c}
            <article class="card">
                <p class="company"><bdi>{$_brand.company|escape}</bdi></p>
                <span class="kind">{if $c.kind eq 'hotspot'}{Lang::T('Hotspot internet card')}{else}{Lang::T('Subscription recharge card')}{/if}</span>
                <table class="facts">
                    <tr><th>{Lang::T('Plan')}</th><td><bdi>{$c.plan|escape}</bdi></td></tr>
                    {if $c.validity}<tr><th>{Lang::T('Validity')}</th><td><bdi>{$c.validity|escape}</bdi></td></tr>{/if}
                </table>
                <p class="price"><bdi>{$c.price|escape}</bdi></p>
                {if $c.password}
                    <p class="code-label">{Lang::T('User name')}</p>
                    <div class="code">{$c.code|escape}</div>
                    <p class="code-label">{Lang::T('Password')}</p>
                    <div class="code">{$c.password|escape}</div>
                {else}
                    <p class="code-label">{if $c.kind eq 'hotspot'}{Lang::T('Login code')}{else}{Lang::T('Recharge code')}{/if}</p>
                    <div class="code">{$c.code|escape}</div>
                {/if}
                <div class="qr"><img src="{$app_url}/qrcode/?data={$c.code|escape:'url'}" alt=""></div>
                <p class="howto">{if $c.password}{Lang::T('Connect to the Wi-Fi network, open the login page and type the user name and the password shown on this card.')}{elseif $c.kind eq 'hotspot'}{Lang::T('Connect to the Wi-Fi network, open the login page and type this code as the user name and the password.')}{else}{Lang::T('Enter this code in the subscriber portal under Voucher Activation.')}{/if}</p>
                <p class="serial">#{$c.id|escape}</p>
            </article>
        {foreachelse}
            <p class="empty">{Lang::T('No unused vouchers match this selection.')}</p>
        {/foreach}
    </main>
{include file="nuxr-csrf.tpl"}
</body>

</html>
