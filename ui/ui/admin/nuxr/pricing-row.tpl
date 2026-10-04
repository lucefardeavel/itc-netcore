{* WP6B pricing partials (the accepted pricing projection; never computed here). Wholesale = reseller cost; retail =
   customer price; the word "price" is never used alone. $r = one projected row; $o = edit offers; $back / $mid / $pid. *}
{function nuxr_wsource src=''}
    {if $src eq 'OVERRIDE'}{Lang::T('set for this reseller')}{elseif $src eq 'INHERITED'}{Lang::T('inherited')}{elseif $src eq 'BASE'}{Lang::T('provider base cost')}{elseif $src eq 'UPSTREAM'}{Lang::T('from upstream')}{/if}
{/function}
{function nuxr_rsource src=''}
    {if $src eq 'OVERRIDE'}{Lang::T('reseller retail')}{elseif $src eq 'LEGACY_DEFAULT'}{Lang::T('default retail (plan price)')}{/if}
{/function}
{function nuxr_avail r=null}
    {if in_array('PLAN_DISABLED_BY_SELF', $r.reasons)}{call name=nuxr_badge tone='neutral' label=Lang::T('Disabled for this reseller') icon='fa-ban'}
    {elseif in_array('PLAN_DISABLED_BY_ANCESTOR', $r.reasons)}{call name=nuxr_badge tone='neutral' label=Lang::T('Disabled upstream') icon='fa-ban'}
    {elseif in_array('PLAN_RESALE_DISABLED', $r.reasons)}{call name=nuxr_badge tone='neutral' label=Lang::T('Reseller commerce off') icon='fa-power-off'}
    {elseif $r.available}{call name=nuxr_badge tone='success' label=Lang::T('Available')}
    {else}{call name=nuxr_badge tone='neutral' label=Lang::T('Not available')}{/if}
{/function}
{function nuxr_saleable r=null}
    {if $r.saleable}{call name=nuxr_badge tone='success' label=Lang::T('Saleable') icon='fa-check'}
    {else}{call name=nuxr_badge tone='warning' label=Lang::T('Not saleable') icon='fa-exclamation-triangle'}
        <ul class="nuxr-reasons">{foreach $r.reasons as $code}<li>{Lang::T(NuxrUi::errorText($code)|default:$code)|escape}</li>{/foreach}</ul>{/if}
{/function}
{function nuxr_pricing_edits r=null o=null back='' mid=0 pid=0}
    {if $o.wholesale}
        <details class="nuxr-inline-edit"><summary class="nuxr-btn">{Lang::T('Child wholesale')}</summary>
            <form method="post" action="{Text::url('pricing/wholesale-post')}" class="nuxr-fields">
                <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="back" value="{$back|escape}">
                <input type="hidden" name="manager_id" value="{$mid|escape}"><input type="hidden" name="plan_id" value="{$pid|escape}">
                <label for="nuxr-w-{$mid|escape}-{$pid|escape}">{Lang::T('Wholesale (reseller cost) for this reseller')}</label>
                <input id="nuxr-w-{$mid|escape}-{$pid|escape}" name="cost" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" maxlength="20" value="{if $r.wholesale_source eq 'OVERRIDE'}{$r.effective_wholesale|escape}{/if}" placeholder="{Lang::T('empty = inherit')}">
                <small class="nuxr-muted">{Lang::T('Cannot be below the parent wholesale. Empty = inherit.')}</small>
                <input name="reason" class="form-control nuxr-input" maxlength="255" placeholder="{Lang::T('Reason (optional)')}" aria-label="{Lang::T('Reason (optional)')}">
                <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Save')}</button>
            </form></details>
    {/if}
    {if $o.retail}
        <details class="nuxr-inline-edit"><summary class="nuxr-btn">{Lang::T('Retail (customer price)')}</summary>
            <form method="post" action="{Text::url('pricing/retail-post')}" class="nuxr-fields">
                <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="back" value="{$back|escape}">
                <input type="hidden" name="manager_id" value="{$mid|escape}"><input type="hidden" name="plan_id" value="{$pid|escape}">
                <label for="nuxr-r-{$mid|escape}-{$pid|escape}">{Lang::T('Retail (customer price)')}</label>
                <input id="nuxr-r-{$mid|escape}-{$pid|escape}" name="price" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" maxlength="20" value="{if $r.retail_source eq 'OVERRIDE'}{$r.effective_retail|escape}{/if}" placeholder="{Lang::T('empty = default retail (plan price)')}">
                <small class="nuxr-muted">{Lang::T('Cannot be below the wholesale. Empty = default retail (plan price).')}</small>
                <input name="reason" class="form-control nuxr-input" maxlength="255" placeholder="{Lang::T('Reason (optional)')}" aria-label="{Lang::T('Reason (optional)')}">
                <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Save')}</button>
            </form></details>
    {/if}
    {if $o.availability}
        <details class="nuxr-inline-edit"><summary class="nuxr-btn">{Lang::T('Availability')}</summary>
            <form method="post" action="{Text::url('pricing/availability-post')}" class="nuxr-fields">
                <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="back" value="{$back|escape}">
                <input type="hidden" name="manager_id" value="{$mid|escape}"><input type="hidden" name="plan_id" value="{$pid|escape}">
                <label for="nuxr-a-{$mid|escape}-{$pid|escape}">{Lang::T('Availability for this reseller')}</label>
                <select id="nuxr-a-{$mid|escape}-{$pid|escape}" name="state" class="form-control nuxr-input">
                    <option value="inherit">{Lang::T('Inherit (no own setting)')}</option>
                    <option value="disable">{Lang::T('Disable')}</option>
                    <option value="enable">{Lang::T('Enable (cannot override an upstream restriction)')}</option>
                </select>
                <input name="reason" class="form-control nuxr-input" maxlength="255" placeholder="{Lang::T('Reason (optional)')}" aria-label="{Lang::T('Reason (optional)')}">
                <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Save')}</button>
            </form></details>
    {/if}
{/function}
