{include file="sections/header.tpl"}
{* WP6C fix round 1: links only where the route rule allows and (customers) the specific target is in scope *}
{assign var=nuxr_link_invoice value=NuxrUi::allowed('plan/view')}
{assign var=nuxr_link_customer value=NuxrUi::allowed('customers/viewu')}
<!-- pool -->
<div class="row">
    <div class="col-sm-12">
        <div class="panel panel-hovered mb20 panel-primary">
            <div class="panel-heading">
                {Lang::T('Activity Log')}
            </div>
            <div class="panel-body">
                <div class="text-center" style="padding: 15px">
                    <div class="col-md-4">
                        <form id="site-search" method="post" action="{Text::url('')}reports/activation">
                            <div class="input-group">
                                <div class="input-group-addon">
                                    <span class="fa fa-search"></span>
                                </div>
                                <input type="search" name="q" class="form-control" value="{$q|escape}" aria-label="{Lang::T('Invoice')}"
                                    placeholder="{Lang::T('Invoice')}...">
                                <div class="input-group-btn">
                                    <button class="btn btn-success" type="submit">{Lang::T('Search')}</button>
                                </div>
                            </div>
                        </form>
                    </div>
                    <div class="col-md-8">

                    </div>&nbsp;
                </div>
                <br>
                {* WP6C AdaptiveDataView: the history table where it fits; cards where narrow; real links (were clickable cells) *}
                <div class="nuxr-adaptive is-lg">
                <div class="table-responsive nuxr-table-wrap">
                    <table id="datatable" class="table table-bordered table-striped">
                        <thead>
                            <tr>
                                <th>{Lang::T('Invoice')}</th>
                                <th>{Lang::T('Username')}</th>
                                <th>{Lang::T('Plan Name')}</th>
                                <th>{Lang::T('Plan Price')}</th>
                                <th>{Lang::T('Type')}</th>
                                <th>{Lang::T('Created On')}</th>
                                <th>{Lang::T('Expires On')}</th>
                                <th>{Lang::T('Method')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $activation as $ds}
                                <tr>
                                    <td>{if $nuxr_link_invoice}<a href="{Text::url('plan/view/')}{$ds['id']|escape:'url'}">{$ds['invoice']|escape}</a>{else}{$ds['invoice']|escape}{/if}</td>
                                    <td>{if $nuxr_link_customer && $nuxr_customer_view[$ds['username']]|default:false}<a href="{Text::url('customers/viewu/')}{$ds['username']|escape:'url'}">{$ds['username']|escape}</a>{else}{$ds['username']|escape}{/if}</td>
                                    <td>{$ds['plan_name']}</td>
                                    <td>{Lang::moneyFormat($ds['price'])}</td>
                                    <td>{$ds['type']}</td>
                                    <td class="text-success">
                                        {Lang::dateAndTimeFormat($ds['recharged_on'],$ds['recharged_time'])}
                                    </td>
                                    <td class="text-danger">{Lang::dateAndTimeFormat($ds['expiration'],$ds['time'])}</td>
                                    <td>{$ds['method']}</td>
                                </tr>
                            {/foreach}
                        </tbody>
                    </table>
                </div>
                <ul class="nuxr-mlist">
                    {foreach $activation as $ds}
                        <li><div class="nuxr-mcard is-wrap">
                            <{if $nuxr_link_invoice}a href="{Text::url('plan/view/')}{$ds['id']|escape:'url'}"{else}div{/if} class="nuxr-mcard-main">
                                <span class="nuxr-mcard-title"><bdi dir="ltr" class="nuxr-mono">{$ds['invoice']|escape}</bdi></span>
                                <span class="nuxr-mcard-sub"><bdi dir="ltr">{$ds['username']|escape}</bdi> · {$ds['plan_name']|escape} · <bdi dir="ltr">{Lang::moneyFormat($ds['price'])|escape}</bdi></span>
                                <span class="nuxr-mcard-sub"><bdi dir="ltr">{Lang::dateAndTimeFormat($ds['recharged_on'],$ds['recharged_time'])|escape}</bdi> → <bdi dir="ltr">{Lang::dateAndTimeFormat($ds['expiration'],$ds['time'])|escape}</bdi> · {$ds['method']|escape}</span>
                            </{if $nuxr_link_invoice}a{else}div{/if}>
                        </div></li>
                    {foreachelse}
                        <li><div class="nuxr-empty"><strong>{Lang::T('No records.')}</strong></div></li>
                    {/foreach}
                </ul>
                </div>
                {include file="pagination.tpl"}
            </div>
        </div>
    </div>
</div>

{include file="sections/footer.tpl"}