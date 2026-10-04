{* WP6B: settlement of one activation from immutable history (role-projected; an Agent sees only its subtree's legs).
   Conservation: wholesale debit = commissions + provider settlement (Admin view). *}
{if $s}
<section class="nuxr-card" aria-labelledby="nuxr-settle-t">
    <div class="nuxr-card-head"><h3 id="nuxr-settle-t">{Lang::T('Settlement')}</h3>
        {if $s.full && $s.conserved === true}{call name=nuxr_badge tone='success' label=Lang::T('Conserved') icon='fa-check'}{elseif $s.full && $s.conserved === false}{call name=nuxr_badge tone='danger' label=Lang::T('Inconsistent - contact support') icon='fa-exclamation-triangle'}{elseif !$s.settled}{call name=nuxr_badge tone='neutral' label=Lang::T('Not settled')}{/if}</div>
    <div class="nuxr-card-body">
        <dl class="nuxr-kv">
            {if $s.debit}<div><dt>{Lang::T('Wholesale debit (reseller cost)')} · <bdi dir="ltr">{NuxrUi::party($s.debit.manager)|escape}</bdi></dt><dd><bdi dir="ltr">{NuxrUi::money($s.debit.amount)|escape}</bdi></dd></div>{/if}
            {foreach $s.commissions as $c}<div><dt>{Lang::T('Commission')} · <bdi dir="ltr">{NuxrUi::party($c.manager)|escape}</bdi></dt><dd><bdi dir="ltr">{NuxrUi::money($c.amount)|escape}</bdi></dd></div>{/foreach}
            {if $s.full}
                <div><dt>{Lang::T('Provider settlement (not a wallet)')}</dt><dd><bdi dir="ltr">{NuxrUi::money($s.provider_settlement)|escape}</bdi></dd></div>
                {if $s.base_cost}<div><dt>{Lang::T('Provider base cost / margin over base')}</dt><dd><bdi dir="ltr">{NuxrUi::money($s.base_cost)|escape} / {NuxrUi::money($s.provider_margin)|escape}</bdi></dd></div>{/if}
                {if $s.settled && $s.debit}<div><dt>{Lang::T('Conservation')}</dt><dd><bdi dir="ltr">{NuxrUi::money($s.wholesale)|escape} = {NuxrUi::money($s.commission_total)|escape} + {NuxrUi::money($s.provider_settlement)|escape}</bdi></dd></div>{/if}
            {/if}
            {foreach $s.reversals as $x}<div><dt>{Lang::T('Reversal')} · <bdi dir="ltr">{NuxrUi::party($x.manager)|escape}</bdi>{if $x.reverses_entry_id} · <a href="{Text::url('finance/entry/', $x.reverses_entry_id)}">{Lang::T('original entry')} <bdi dir="ltr">#{$x.reverses_entry_id|escape}</bdi></a>{/if}</dt>
                <dd><bdi dir="ltr">{if $x.direction eq 'CREDIT'}+{else}−{/if}{NuxrUi::money($x.amount)|escape}</bdi></dd></div>{/foreach}
        </dl>
    </div>
</section>
{/if}
