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
{* WP6C (OD-2): one row-level Edit opens one editing surface per row - full screen on a phone, a side panel on wider
   screens. It holds the same accepted forms (same routes, fields, CSRF, offers); nothing is computed here. The
   surface is rendered once per row (outside the table / card pair). Without JS the link targets the panel (:target). *}
{function nuxr_pricing_edit_btn o=null mid=0 pid=0 title=''}
    {if $o.wholesale || $o.retail || $o.availability}
        <a class="nuxr-btn" href="#nuxr-pe-{$mid|escape}-{$pid|escape}" data-nuxr-open="nuxr-pe-{$mid|escape}-{$pid|escape}" role="button"
            aria-haspopup="dialog"><i class="fa fa-pencil" aria-hidden="true"></i>{Lang::T('Edit')}<span class="sr-only"> {$title|escape}</span></a>
    {/if}
{/function}
{function nuxr_pricing_editor r=null o=null back='' mid=0 pid=0 title=''}
    {if $o.wholesale || $o.retail || $o.availability}
    <dialog id="nuxr-pe-{$mid|escape}-{$pid|escape}" class="nuxr-sheet nuxr-sheet-edit" aria-labelledby="nuxr-pe-{$mid|escape}-{$pid|escape}-t">
        <div class="nuxr-sheet-head"><h2 id="nuxr-pe-{$mid|escape}-{$pid|escape}-t">{Lang::T('Edit pricing')}<span class="nuxr-sheet-subtitle">{$title|escape}</span></h2>
            <a href="#" class="nuxr-icon-btn" data-nuxr-close role="button" aria-label="{Lang::T('Close')}"><i class="fa fa-times" aria-hidden="true"></i></a></div>
        <div class="nuxr-sheet-body">
            <dl class="nuxr-pe-now">
                <div><dt>{Lang::T('Wholesale (reseller cost)')}</dt><dd><bdi dir="ltr">{NuxrUi::money($r.effective_wholesale)|escape}</bdi> <small class="nuxr-muted">{call name=nuxr_wsource src=$r.wholesale_source}</small></dd></div>
                <div><dt>{Lang::T('Retail (customer price)')}</dt><dd><bdi dir="ltr">{NuxrUi::money($r.effective_retail)|escape}</bdi> <small class="nuxr-muted">{call name=nuxr_rsource src=$r.retail_source}</small></dd></div>
                <div><dt>{Lang::T('Availability')}</dt><dd>{call name=nuxr_avail r=$r} {call name=nuxr_saleable r=$r}</dd></div>
            </dl>
            {if $o.wholesale}
                <form method="post" action="{Text::url('pricing/wholesale-post')}" class="nuxr-fields nuxr-pe-section">
                    <h3>{Lang::T('Child wholesale')}</h3>
                    <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="back" value="{$back|escape}">
                    <input type="hidden" name="manager_id" value="{$mid|escape}"><input type="hidden" name="plan_id" value="{$pid|escape}">
                    <label for="nuxr-w-{$mid|escape}-{$pid|escape}">{Lang::T('Wholesale (reseller cost) for this reseller')}</label>
                    <input id="nuxr-w-{$mid|escape}-{$pid|escape}" name="cost" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" maxlength="20" value="{if $r.wholesale_source eq 'OVERRIDE'}{$r.effective_wholesale|escape}{/if}" placeholder="{Lang::T('empty = inherit')}" aria-describedby="nuxr-w-{$mid|escape}-{$pid|escape}-h">
                    <small class="nuxr-muted" id="nuxr-w-{$mid|escape}-{$pid|escape}-h">{Lang::T('Cannot be below the parent wholesale. Empty = inherit.')}</small>
                    <label for="nuxr-wr-{$mid|escape}-{$pid|escape}">{Lang::T('Reason (optional)')}</label>
                    <input id="nuxr-wr-{$mid|escape}-{$pid|escape}" name="reason" class="form-control nuxr-input" maxlength="255">
                    <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Save')}</button>
                </form>
            {/if}
            {if $o.retail}
                <form method="post" action="{Text::url('pricing/retail-post')}" class="nuxr-fields nuxr-pe-section">
                    <h3>{Lang::T('Retail (customer price)')}</h3>
                    <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="back" value="{$back|escape}">
                    <input type="hidden" name="manager_id" value="{$mid|escape}"><input type="hidden" name="plan_id" value="{$pid|escape}">
                    <label for="nuxr-r-{$mid|escape}-{$pid|escape}">{Lang::T('Retail (customer price)')}</label>
                    <input id="nuxr-r-{$mid|escape}-{$pid|escape}" name="price" class="form-control nuxr-input ltr" dir="ltr" inputmode="decimal" maxlength="20" value="{if $r.retail_source eq 'OVERRIDE'}{$r.effective_retail|escape}{/if}" placeholder="{Lang::T('empty = default retail (plan price)')}" aria-describedby="nuxr-r-{$mid|escape}-{$pid|escape}-h">
                    <small class="nuxr-muted" id="nuxr-r-{$mid|escape}-{$pid|escape}-h">{Lang::T('Cannot be below the wholesale. Empty = default retail (plan price).')}</small>
                    <label for="nuxr-rr-{$mid|escape}-{$pid|escape}">{Lang::T('Reason (optional)')}</label>
                    <input id="nuxr-rr-{$mid|escape}-{$pid|escape}" name="reason" class="form-control nuxr-input" maxlength="255">
                    <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Save')}</button>
                </form>
            {/if}
            {if $o.availability}
                <form method="post" action="{Text::url('pricing/availability-post')}" class="nuxr-fields nuxr-pe-section">
                    <h3>{Lang::T('Availability')}</h3>
                    <input type="hidden" name="csrf_token" value="{$csrf_token}"><input type="hidden" name="back" value="{$back|escape}">
                    <input type="hidden" name="manager_id" value="{$mid|escape}"><input type="hidden" name="plan_id" value="{$pid|escape}">
                    <label for="nuxr-a-{$mid|escape}-{$pid|escape}">{Lang::T('Availability for this reseller')}</label>
                    <select id="nuxr-a-{$mid|escape}-{$pid|escape}" name="state" class="form-control nuxr-input">
                        <option value="inherit">{Lang::T('Inherit (no own setting)')}</option>
                        <option value="disable">{Lang::T('Disable')}</option>
                        <option value="enable">{Lang::T('Enable (cannot override an upstream restriction)')}</option>
                    </select>
                    <label for="nuxr-ar-{$mid|escape}-{$pid|escape}">{Lang::T('Reason (optional)')}</label>
                    <input id="nuxr-ar-{$mid|escape}-{$pid|escape}" name="reason" class="form-control nuxr-input" maxlength="255">
                    <button type="submit" class="nuxr-btn nuxr-btn-primary">{Lang::T('Save')}</button>
                </form>
            {/if}
        </div>
    </dialog>
    {/if}
{/function}
