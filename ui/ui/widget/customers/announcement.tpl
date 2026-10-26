{* lab UX-1: no announcement box when the customer announcement page is empty *}
{nuxr_page name="Announcement_Customer" assign="nuxr_announcement"}
{if $nuxr_announcement neq ''}
<div class="box box-info box-solid nuxr-announcement">
    <div class="box-header">
        <h3 class="box-title">{Lang::T('Announcement')}</h3>
    </div>
    <div class="box-body">
        {$nuxr_announcement}
    </div>
</div>
{/if}