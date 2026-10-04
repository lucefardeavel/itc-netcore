{* WP6A component macros (Smarty {function}; every value escaped). Statuses are icon + text, never color alone. *}
{function nuxr_badge tone='neutral' label='' icon=''}<span class="nuxr-badge is-{$tone|escape}"><i class="fa {if $icon}{$icon|escape}{else}{NuxrUi::icon($tone)}{/if}" aria-hidden="true"></i>{$label|escape}</span>{/function}

{* activation state badge (Format::STATE_TONE / STATE_LABEL) *}
{function nuxr_state state=''}{call name=nuxr_badge tone=NuxrUi::tone($state) label=Lang::T(NuxrUi::stateLabel($state))}{/function}

{* customer account status *}
{function nuxr_customer_status status=''}
    {if $status eq 'Active'}{call name=nuxr_badge tone='success' label=Lang::T('Active')}
    {elseif $status eq 'Limited'}{call name=nuxr_badge tone='warning' label=Lang::T('Limited')}
    {elseif in_array($status, ['Banned', 'Disabled', 'Suspended'])}{call name=nuxr_badge tone='danger' label=Lang::T($status) icon='fa-ban'}
    {else}{call name=nuxr_badge tone='neutral' label=Lang::T($status)}{/if}
{/function}

{* service (plan) state from Reseller\Ui\Subscriber::plans(); 7-day expiring threshold (OD-L) *}
{function nuxr_plan_state p=null}
    {if !$p}{call name=nuxr_badge tone='neutral' label=Lang::T('No service')}
    {elseif !$p.on || $p.days === null || $p.days < 0}{call name=nuxr_badge tone='danger' label=Lang::T('Expired') icon='fa-clock-o'}
    {elseif $p.days <= 7}{call name=nuxr_badge tone='warning' label=Lang::T('Expiring') icon='fa-exclamation-circle'}
    {else}{call name=nuxr_badge tone='success' label=Lang::T('Active')}{/if}
{/function}

{* money with a mandatory domain label: wholesale (reseller cost) / retail (customer price) / credit *}
{function nuxr_money domain='credit' label='' amount='' note=''}
    <div class="nuxr-money is-{$domain|escape}{if NuxrUi::isNegative($amount)} is-negative{/if}">
        <span class="nuxr-money-label"><i class="fa {if $domain eq 'wholesale'}fa-building{elseif $domain eq 'retail'}fa-user{else}fa-credit-card{/if}" aria-hidden="true"></i>{$label|escape}</span>
        <span class="nuxr-money-value"><bdi dir="ltr">{NuxrUi::money($amount)|escape}</bdi></span>
        {if $note}<span class="nuxr-money-note">{$note|escape}</span>{/if}
    </div>
{/function}

{function nuxr_initial who=''}<span class="nuxr-avatar-initial" aria-hidden="true">{$who|truncate:1:'':true|upper|escape}</span>{/function}
