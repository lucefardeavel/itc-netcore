{include file="sections/header.tpl"}
{* WP2: reseller hierarchy (closure table). Every action is re-authorized server-side and audited. *}
<div class="row">
    <div class="col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Reseller Tree')}</div>
            <div class="table-responsive">
                <table class="table table-bordered table-condensed">
                    <thead>
                        <tr>
                            <th>{Lang::T('Account')}</th>
                            <th>{Lang::T('Role')}</th>
                            <th>{Lang::T('Status')}</th>
                            <th>{Lang::T('Customers owned')}</th>
                            <th>{Lang::T('Actions')}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {foreach $nuxr_tree as $n}
                            <tr {if $n['status'] neq 'Active'}class="danger"{/if}>
                                <td style="padding-left: {($n['level'] * 24) + 8}px">
                                    {if $n['level'] > 0}&#8627; {/if}<a href="{Text::url('settings/users-view/', $n['id'])}">{$n['username']|escape}</a>
                                    <small class="text-muted">{$n['fullname']|escape}</small>
                                </td>
                                <td>{$n['user_type']|escape}</td>
                                <td>{$n['status']|escape}</td>
                                <td>{$n['customers']}</td>
                                <td>
                                    {if $n['can_status']}
                                        <form method="post" action="{Text::url('reseller/status-post')}" style="display:inline">
                                            <input type="hidden" name="csrf_token" value="{$csrf_token}">
                                            <input type="hidden" name="user_id" value="{$n['id']}">
                                            {if $n['status'] eq 'Active'}
                                                <input type="hidden" name="status" value="Inactive">
                                                <button class="btn btn-warning btn-xs" type="submit" onclick="return ask(this, '{Lang::T('Disable this account? Its descendants become read-only.')}')">{Lang::T('Disable')}</button>
                                            {else}
                                                <input type="hidden" name="status" value="Active">
                                                <button class="btn btn-success btn-xs" type="submit">{Lang::T('Reactivate')}</button>
                                            {/if}
                                        </form>
                                    {/if}
                                    {if $nuxr_can_move}
                                        <form method="post" action="{Text::url('reseller/move-post')}" style="display:inline" class="form-inline">
                                            <input type="hidden" name="csrf_token" value="{$csrf_token}">
                                            <input type="hidden" name="node_id" value="{$n['id']}">
                                            <select name="parent_id" class="form-control input-sm">
                                                {if $n['user_type'] eq 'Agent'}<option value="">{Lang::T('Top level')}</option>{/if}
                                                {foreach $nuxr_agents as $ag}
                                                    {if $ag['id'] neq $n['id']}<option value="{$ag['id']}">{$ag['username']|escape}</option>{/if}
                                                {/foreach}
                                            </select>
                                            <button class="btn btn-default btn-xs" type="submit" onclick="return ask(this, '{Lang::T('Move this account (and its subtree)?')}')">{Lang::T('Move')}</button>
                                        </form>
                                    {/if}
                                </td>
                            </tr>
                        {foreachelse}
                            <tr><td colspan="5">{Lang::T('No accounts in the hierarchy yet.')}</td></tr>
                        {/foreach}
                    </tbody>
                </table>
            </div>
        </div>
        {if $nuxr_can_place}
            <div class="panel panel-default">
                <div class="panel-heading">{Lang::T('Not yet enrolled Agent/Sales accounts')} <small>({Lang::T('explicit onboarding; the legacy parent value is shown for reference only and is never applied automatically')})</small></div>
                <div class="table-responsive">
                    <table class="table table-condensed">
                        <thead><tr><th>{Lang::T('Account')}</th><th>{Lang::T('Role')}</th><th>{Lang::T('Legacy parent id')}</th><th>{Lang::T('Place under')}</th></tr></thead>
                        <tbody>
                            {foreach $nuxr_unenrolled as $u}
                                <tr>
                                    <td>{$u['username']|escape} <small class="text-muted">{$u['fullname']|escape}</small></td>
                                    <td>{$u['user_type']|escape}</td>
                                    <td>{$u['root']}</td>
                                    <td>
                                        <form method="post" action="{Text::url('reseller/place-post')}" class="form-inline">
                                            <input type="hidden" name="csrf_token" value="{$csrf_token}">
                                            <input type="hidden" name="user_id" value="{$u['id']}">
                                            <select name="parent_id" class="form-control input-sm">
                                                {if $u['user_type'] eq 'Agent'}<option value="">{Lang::T('Top level')}</option>{/if}
                                                {foreach $nuxr_agents as $ag}<option value="{$ag['id']}">{$ag['username']|escape}</option>{/foreach}
                                            </select>
                                            <button class="btn btn-primary btn-xs" type="submit">{Lang::T('Place')}</button>
                                        </form>
                                    </td>
                                </tr>
                            {foreachelse}
                                <tr><td colspan="4">{Lang::T('None')}</td></tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
            </div>
        {/if}
        {if $nuxr_verify}
            <div class="panel panel-danger">
                <div class="panel-heading">{Lang::T('Hierarchy consistency findings')}</div>
                <ul class="list-group">
                    {foreach $nuxr_verify as $v}<li class="list-group-item">{$v['code']|escape} — #{$v['user_id']} {$v['detail']|escape}</li>{/foreach}
                </ul>
            </div>
        {/if}
    </div>
</div>
{include file="sections/footer.tpl"}
