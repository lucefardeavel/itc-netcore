{* WP6A: quick-search results (listbox options) rendered by search_user; scoped server-side, at most 20. *}
{include file="admin/nuxr/macros.tpl"}
{foreach $nuxr_results as $c}
    <a role="option" href="{Text::url('customers/view/', $c['id'])}" class="nuxr-mcard">
        {call name=nuxr_initial who=$c['fullname']}
        <span class="nuxr-mcard-main">
            <span class="nuxr-mcard-title">{$c['fullname']|escape}</span>
            <span class="nuxr-mcard-sub"><bdi dir="ltr">{$c['username']|escape}</bdi>{if $c['pppoe_ip']} · <bdi dir="ltr">{$c['pppoe_ip']|escape}</bdi>{/if}{if $c['phonenumber']} · <bdi dir="ltr">{$c['phonenumber']|escape}</bdi>{/if}</span>
        </span>
        <span class="nuxr-mcard-end">{call name=nuxr_plan_state p=$nuxr_plans[$c['id']]|default:null}<bdi dir="ltr" class="nuxr-muted">#{$c['id']}</bdi></span>
    </a>
{/foreach}
<a href="{$nuxr_more_url}" class="nuxr-btn" style="width:100%;margin-top:6px">{Lang::T('See all results')}</a>
