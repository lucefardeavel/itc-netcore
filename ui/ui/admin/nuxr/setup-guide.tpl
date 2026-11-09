{* V1.0.1: "Get started" after a fresh install (Reseller\Ui\SetupGuide); gone once every required step is done *}
<section class="nuxr-card nuxr-setup" aria-labelledby="nuxr-setup-t" style="margin-bottom:16px">
    <div class="nuxr-card-head"><h3 id="nuxr-setup-t"><i class="fa fa-flag-checkered" aria-hidden="true"></i> {Lang::T('Get started')}</h3>
        <span class="nuxr-muted">{Lang::T('A few steps to make the system ready for your subscribers.')}</span></div>
    <ol class="nuxr-setup-steps">
        {foreach $nuxr_setup as $s}
            <li class="{if $s.done}is-done{/if}">
                <i class="fa {if $s.done}fa-check-circle{else}fa-circle-o{/if}" aria-hidden="true"></i>
                <span class="nuxr-setup-text">
                    {if $s.done}<span>{Lang::T($s.label)}</span>{else}<a href="{Text::url($s.route)}">{Lang::T($s.label)}</a>{/if}
                    {if $s.optional}<span class="nuxr-muted">({Lang::T('optional')})</span>{/if}
                    <small class="nuxr-muted">{Lang::T($s.hint)}</small>
                </span>
                <span class="sr-only">{if $s.done}{Lang::T('Done')}{else}{Lang::T('Not done yet')}{/if}</span>
            </li>
        {/foreach}
    </ol>
</section>
