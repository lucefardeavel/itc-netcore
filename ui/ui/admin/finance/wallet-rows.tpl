{* WP6B: wallet rows (finance read model): table on >= 768px, cards on phone. *}
<div class="nuxr-table-wrap">
    <table class="nuxr-table">
        <thead><tr><th>{Lang::T('Reseller')}</th><th>{Lang::T('Status')}</th><th>{Lang::T('Balance')}</th><th>{Lang::T('Debt line')}</th><th>{Lang::T('Reserved')}</th><th>{Lang::T('Available credit')}</th></tr></thead>
        <tbody>
            {foreach $rows as $w}
                <tr>
                    <td><a href="{Text::url('finance/wallet/', $w.id)}">{$w.fullname|escape}</a><br><bdi dir="ltr" class="nuxr-muted">{$w.username|escape}</bdi></td>
                    <td>{if $w.status neq 'Active'}{call name=nuxr_badge tone='danger' label=Lang::T('Inactive') icon='fa-ban'}{elseif $w.frozen}{call name=nuxr_badge tone='warning' label=Lang::T('Frozen by an upstream reseller') icon='fa-pause'}{else}{call name=nuxr_badge tone='success' label=Lang::T('Active')}{/if}
                        {if !$w.exists}<br>{call name=nuxr_badge tone='neutral' label=Lang::T('No wallet yet') icon='fa-circle-o'}{/if}</td>
                    <td><bdi dir="ltr">{NuxrUi::money($w.balance)|escape}</bdi></td>
                    <td><bdi dir="ltr">{NuxrUi::money($w.debt_limit)|escape}</bdi></td>
                    <td><bdi dir="ltr">{NuxrUi::money($w.reserved)|escape}</bdi></td>
                    <td><strong><bdi dir="ltr">{NuxrUi::money($w.available)|escape}</bdi></strong></td>
                </tr>
            {/foreach}
        </tbody>
    </table>
</div>
<ul class="nuxr-mlist">
    {foreach $rows as $w}
        <li>
            <a class="nuxr-mcard" href="{Text::url('finance/wallet/', $w.id)}">
                {call name=nuxr_initial who=$w.fullname}
                <span class="nuxr-mcard-main">
                    <span class="nuxr-mcard-title">{$w.fullname|escape}</span>
                    <span class="nuxr-mcard-sub"><bdi dir="ltr">{$w.username|escape}</bdi>{if !$w.exists} · {Lang::T('No wallet yet')}{elseif $w.frozen} · {Lang::T('Frozen')}{/if}</span>
                </span>
                <span class="nuxr-mcard-end"><span class="nuxr-muted">{Lang::T('Available')}</span><strong><bdi dir="ltr">{NuxrUi::money($w.available)|escape}</bdi></strong></span>
            </a>
        </li>
    {/foreach}
</ul>
