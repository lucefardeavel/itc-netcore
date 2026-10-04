{* WP6A: primary destinations (tablet rail, phone bottom navigation): Home, Customers, Quick action, Activations, More
   (OD-J; no Wallet before WP6B). Only destinations the actor may open are present. *}
<ul class="nuxr-primary">
    {foreach $_nav.primary as $p}
        <li>
            {if isset($p.sheet)}
                <button type="button" class="nuxr-primary-item{if $p.sheet eq 'quick'} nuxr-primary-quick{/if}" data-nuxr-open="nuxr-{$p.sheet}-sheet" aria-haspopup="dialog">
                    <i class="fa {$p.icon}" aria-hidden="true"></i><span>{Lang::T($p.label)}</span>
                </button>
            {else}
                <a href="{Text::url($p.route)}" class="nuxr-primary-item{if $p.active} is-active{/if}"{if $p.active} aria-current="page"{/if}>
                    <i class="fa {$p.icon}" aria-hidden="true"></i><span>{Lang::T($p.label)}</span>
                </a>
            {/if}
        </li>
    {/foreach}
</ul>
