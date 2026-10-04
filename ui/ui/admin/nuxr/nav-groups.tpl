{* WP6A: grouped navigation from $_nav (desktop sidebar; the More sheet on tablet / phone). Plugin menu HTML is
   placed at its legacy positions (WP2 OD-9 filtering unchanged). *}
<ul class="nuxr-nav">
    {foreach $_nav.primary as $p}
        {if isset($p.route) && $p.route eq 'dashboard'}
            <li><a href="{Text::url('dashboard')}" class="nuxr-nav-link{if $p.active} is-active{/if}"{if $p.active} aria-current="page"{/if}>
                <i class="fa {$p.icon} fa-fw" aria-hidden="true"></i><span>{Lang::T('Dashboard')}</span></a></li>
        {/if}
    {/foreach}
    {$_nav.plugins.AFTER_DASHBOARD|default:''}
</ul>
{foreach $_nav.groups as $g}
    <details class="nuxr-nav-group"{if $g.active || $nuxr_place eq 'sheet'} open{/if}>
        <summary><i class="fa {$g.icon} fa-fw" aria-hidden="true"></i><span>{Lang::T($g.label)}</span><i class="fa fa-angle-down nuxr-caret" aria-hidden="true"></i></summary>
        <ul class="nuxr-nav">
            {foreach $g.items as $i}
                <li><a href="{Text::url($i.route)}" class="nuxr-nav-link{if $i.active} is-active{/if}"{if $i.active} aria-current="page"{/if}>
                    <i class="fa {$i.icon} fa-fw" aria-hidden="true"></i><span>{Lang::T($i.label)}</span></a></li>
            {/foreach}
            {if $g.slot}{$_nav.plugins[$g.slot]|default:''}{/if}
        </ul>
    </details>
    {if $g.after && $_nav.plugins[$g.after]|default:'' neq ''}
        <ul class="nuxr-nav">{$_nav.plugins[$g.after]}</ul>
    {/if}
{/foreach}
