{include file="sections/header.tpl"}

<div class="page page-err clearfix">
    <div class="text-center" style="padding: 50px; background: #ffffff; border-radius: 8px;">
        <h1 style="font-size: 60px; color: #ff6b6b;">{$deny_title|escape}</h1>
        <p style="font-size: 20px; margin: 20px 0;">{Lang::T($deny_message)|escape}</p>
        <a href="{Text::url('dashboard')}" class="btn btn-primary">{Lang::T("Back to Dashboard")}</a>
    </div>
</div>

{include file="sections/footer.tpl"}
