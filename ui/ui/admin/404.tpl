{* WP6C (owner amendment C): not-found inside the shell (controllers render it after the dispatcher assigned the
   navigation). The requested object is never named. *}
{assign var=_title value=Lang::T('Page not found')}
{include file="sections/header.tpl"}
{include file="admin/nuxr/state-page.tpl" nuxr_state=404 nuxr_state_code='404 Not Found'}
{include file="sections/footer.tpl"}
