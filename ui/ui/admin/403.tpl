{* WP6C (owner amendment C): RouteGuard renders this before the dispatcher assigns the shell, so the actor's own
   navigation (only destinations it may open), capability flags and identity come from the UI layer here. *}
{if !isset($_nav)}
    {assign var=nuxr_denied_shell value=NuxrUi::deniedShell()}
    {assign var=_nav value=$nuxr_denied_shell.nav}
    {assign var=_can value=$nuxr_denied_shell.can}
    {if !isset($_admin)}{assign var=_admin value=$nuxr_denied_shell.admin}{/if}
{/if}
{if $deny_title eq '404 Not Found'}{assign var=nuxr_state value=404}{elseif $deny_title eq '405 Method Not Allowed'}{assign var=nuxr_state value=405}{else}{assign var=nuxr_state value=403}{/if}
{if $nuxr_state eq 404}{assign var=_title value=Lang::T('Page not found')}{elseif $nuxr_state eq 405}{assign var=_title value=Lang::T('Action not available')}{else}{assign var=_title value=Lang::T('Access denied')}{/if}
{include file="sections/header.tpl"}
{include file="admin/nuxr/state-page.tpl" nuxr_state_code=$deny_title}
{include file="sections/footer.tpl"}
