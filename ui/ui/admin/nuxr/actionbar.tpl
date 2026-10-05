{* WP6C AdaptiveActions for long / legacy forms: a direct child of the <form>. Phone: sticky above the bottom navigation
   (drops to the bottom while typing), so Save never scrolls out of reach; desktop: an inline action row. Same submit
   button, same field names and POST route - presentation only.
   Params: nuxr_submit (label key), nuxr_icon (fa-*), nuxr_cancel (route, optional), nuxr_ask (confirmation key,
   optional, shown in the shell's confirmation sheet; nuxr_ask_q=1 appends "?"), nuxr_danger (bool). *}
<div class="nuxr-step-nav nuxr-actionbar-form">
    {if $nuxr_cancel|default:''}<a class="nuxr-btn" href="{Text::url($nuxr_cancel)}">{Lang::T('Cancel')}</a>{/if}
    <button type="submit" class="nuxr-btn {if $nuxr_danger|default:false}nuxr-btn-danger-solid{else}nuxr-btn-primary{/if}"{if $nuxr_ask|default:''} data-nuxr-ask="{Lang::T($nuxr_ask)|escape}{if $nuxr_ask_q|default:0}?{/if}"{/if}>
        <i class="fa {$nuxr_icon|default:'fa-check'|escape}" aria-hidden="true"></i>{Lang::T($nuxr_submit)|escape}</button>
</div>
