{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6B: parent -> direct-child transfer (wallet.transfer). An Agent always sends from its own wallet to one of its
   direct child resellers; Admin / SuperAdmin from the destination's parent. A balance transfer, not a sale. *}
{assign var=f value=$nuxr_form}
<section class="nuxr-card" aria-labelledby="nuxr-send-t">
    <div class="nuxr-card-head"><h2 id="nuxr-send-t"><i class="fa fa-exchange" aria-hidden="true"></i>{Lang::T('Send to a child reseller')}</h2></div>
    <div class="nuxr-card-body">
        {if $nuxr_error|default:''}<div class="nuxr-alert is-danger" role="alert"><i class="fa fa-exclamation-circle" aria-hidden="true"></i><div>{$nuxr_error|escape}</div></div>{/if}
        <p>{Lang::T('From')}: <strong>{$f.from.fullname|escape}</strong> <bdi dir="ltr" class="nuxr-muted">{$f.from.username|escape}</bdi>
            · {Lang::T('Available credit')}: <bdi dir="ltr">{NuxrUi::money($f.from.available)|escape}</bdi></p>
        {if !$f.to && !$f.children}
            <div class="nuxr-empty"><i class="fa fa-sitemap" aria-hidden="true"></i><strong>{Lang::T('You have no child resellers to send to.')}</strong></div>
        {else}
        <form method="post" action="{Text::url('finance/send-review')}" class="nuxr-form nuxr-fields">
            <input type="hidden" name="csrf_token" value="{$csrf_token}">
            {if $f.to}
                <input type="hidden" name="to_id" value="{$f.to.id|escape}">
                <p>{Lang::T('To')}: <strong>{$f.to.fullname|escape}</strong> <bdi dir="ltr" class="nuxr-muted">{$f.to.username|escape}</bdi></p>
            {else}
                <fieldset><legend>{Lang::T('To (direct child reseller)')} <span aria-hidden="true">*</span></legend>
                    <ul class="nuxr-mlist" style="display:block">
                        {foreach $f.children as $c}
                            <li><label class="nuxr-mcard">
                                <input type="radio" name="to_id" value="{$c.id|escape}" required{if $c.frozen} disabled{/if}>
                                <span class="nuxr-mcard-main"><span class="nuxr-mcard-title">{$c.fullname|escape}</span>
                                    <span class="nuxr-mcard-sub"><bdi dir="ltr">{$c.username|escape}</bdi>{if $c.frozen} · {Lang::T('Inactive - transfers blocked')}{/if}</span></span>
                                <span class="nuxr-mcard-end"><bdi dir="ltr">{NuxrUi::money($c.balance)|escape}</bdi></span>
                            </label></li>
                        {/foreach}
                    </ul>
                </fieldset>
            {/if}
            <label for="nuxr-amount">{Lang::T('Amount')} <span aria-hidden="true">*</span></label>
            <input id="nuxr-amount" name="amount" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" autocomplete="off" required maxlength="20" value="{$nuxr_values.amount|default:''|escape}" placeholder="0.00">
            <label for="nuxr-reason">{Lang::T('Reason')} <span aria-hidden="true">*</span></label>
            <input id="nuxr-reason" name="reason" class="form-control nuxr-input" required minlength="3" maxlength="255" value="{$nuxr_values.reason|default:''|escape}">
            <div class="nuxr-step-nav"><a class="nuxr-btn" href="{Text::url('finance')}">{Lang::T('Cancel')}</a>
                <button type="submit" class="nuxr-btn nuxr-btn-primary"><i class="fa fa-eye" aria-hidden="true"></i>{Lang::T('Review')}</button></div>
        </form>
        {/if}
    </div>
</section>
{include file="sections/footer.tpl"}
