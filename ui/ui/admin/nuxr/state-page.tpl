{* WP6C (owner amendment C): permission-denied / not-found / wrong-method inside the shell. Explains the state, keeps
   the navigation, offers recovery (Up when the actor may open the parent, Home), and never names the requested object.
   $nuxr_state: 403 | 404 | 405; $nuxr_state_code: the plain HTTP status line (kept for clarity, LTR token). *}
<section class="nuxr-card nuxr-state-page" aria-labelledby="nuxr-state-t">
    <div class="nuxr-empty">
        <i class="fa {if $nuxr_state eq 404}fa-search{elseif $nuxr_state eq 405}fa-hand-paper-o{else}fa-lock{/if}" aria-hidden="true"></i>
        <h2 id="nuxr-state-t">{if $nuxr_state eq 404}{Lang::T('Page not found')}{elseif $nuxr_state eq 405}{Lang::T('This action needs its own form')}{else}{Lang::T('You cannot open this page')}{/if}</h2>
        <p>{if $nuxr_state eq 404}{Lang::T('The page or record does not exist, or it is not available to your account.')}{elseif $nuxr_state eq 405}{Lang::T('This action must be submitted from its form or button.')}{else}{Lang::T('Your account does not have access to this page. If you need it, ask an administrator.')}{/if}</p>
        <p class="nuxr-muted"><bdi dir="ltr">{$nuxr_state_code|escape}</bdi></p>
        <div class="nuxr-actions" style="justify-content:center">
            {if !empty($_nav.up) && $_nav.up.route neq 'dashboard'}<a class="nuxr-btn" href="{Text::url($_nav.up.route)}"><i class="fa fa-arrow-left nuxr-dir-icon" aria-hidden="true"></i>{Lang::T($_nav.up.label)|escape}</a>{/if}
            <a class="nuxr-btn nuxr-btn-primary" href="{Text::url('dashboard')}"><i class="fa fa-home" aria-hidden="true"></i>{Lang::T('Home')}</a>
        </div>
    </div>
</section>
