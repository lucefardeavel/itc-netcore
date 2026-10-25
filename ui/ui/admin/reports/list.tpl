{include file="sections/header.tpl"}
<!-- reports-daily -->
<div class="row">
    <div class="col-lg-3">
        <form method="get" class="form">
            <div class="box box-primary box-solid">
                {* WP6C: a real, keyboard-operable toggle (was a clickable div) *}
                <button type="button" class="box-header nuxr-filter-toggle" onclick="showFilter(); this.setAttribute('aria-expanded', this.getAttribute('aria-expanded') === 'true' ? 'false' : 'true')"
                    aria-controls="filter_box" aria-expanded="false">
                    <span class="box-title"><i class="fa fa-sliders" aria-hidden="true"></i> {Lang::T('Filter')}</span>
                </button>
                <div id="filter_box" class="box-body hidden-xs hidden-sm hidden-md">
                    <center>
                        <label for="show_chart">
                            <input type="checkbox" id="show_chart" onclick="return setShowChart()">
                            {Lang::T('Show chart')}
                        </label>
                    </center>
                    <hr style="margin: 1px;">
                    <input type="hidden" name="_route" value="reports">
                    <label for="nxf-r-sd">{Lang::T('Start Date')}</label>
                    <input type="date" id="nxf-r-sd" class="form-control" name="sd" value="{$sd|escape}">
                    <label for="nxf-r-ts">{Lang::T('Start time')}</label>
                    <input type="time" id="nxf-r-ts" class="form-control" name="ts" value="{$ts|escape}">
                    <label for="nxf-r-ed">{Lang::T('End Date')}</label>
                    <input type="date" id="nxf-r-ed" class="form-control" name="ed" value="{$ed|escape}">
                    <label for="nxf-r-te">{Lang::T('End Time')}</label>
                    <input type="time" id="nxf-r-te" class="form-control" name="te" value="{$te|escape}">
                    <label for="nxf-r-tps">{Lang::T('Type')}</label>
                    <select id="nxf-r-tps" class="form-control select2" name="tps[]" multiple>
                        {foreach $types as $type}
                            <option value="{$type}" {if in_array($type, $tps)}selected{/if}>{$type}</option>
                        {/foreach}
                    </select>
                    <label for="nxf-r-plns">{Lang::T('Internet Plans')}</label>
                    <select id="nxf-r-plns" class="form-control select2" name="plns[]" multiple>
                        {foreach $plans as $plan}
                            <option value="{$plan}" {if in_array($plan, $plns)}selected{/if}>{$plan}</option>
                        {/foreach}
                    </select>
                    <label for="nxf-r-mts">{Lang::T('Methods')}</label>
                    <select id="nxf-r-mts" class="form-control select2" name="mts[]" multiple>
                        {foreach $methods as $method}
                            <option value="{$method}" {if in_array($method, $mts)}selected{/if}>{$method}</option>
                        {/foreach}
                    </select>
                    <label for="nxf-r-rts">{Lang::T('Routers')}</label>
                    <select id="nxf-r-rts" class="form-control select2" name="rts[]" multiple>
                        {foreach $routers as $router}
                            <option value="{$router}" {if in_array($router, $rts)}selected{/if}>{Lang::T($router)}</option>
                        {/foreach}
                    </select>
                    <button type="submit" class="btn btn-success btn-block">{Lang::T('Apply')}</button>
                </div>
            </div>
        </form>
    </div>
    <div class="col-lg-9">
        <span id="chart_area" class="hidden">
            <div class="box box-primary box-solid">
                <div class="box-body row">
                    <div class="col-md-3 col-xs-6">
                        <canvas id="cart_type"></canvas>
                    </div>
                    <div class="col-md-3 col-xs-6">
                        <canvas id="cart_plan"></canvas>
                    </div>
                    <div class="col-md-3 col-xs-6">
                        <canvas id="cart_method"></canvas>
                    </div>
                    <div class="col-md-3 col-xs-6">
                        <canvas id="cart_router"></canvas>
                    </div>
                </div>
            </div>
            <div class="box box-primary box-solid">
                <div class="box-header">
                    <h3 class="box-title">{Lang::dateFormat($sd)} - {Lang::dateFormat($ed)}
                        <sup>{Lang::T('Max 30 days')}</sup>
                    </h3>
                </div>
                <div class="box-body row" style="height: 300px;">
                    <canvas id="line_cart"></canvas>
                </div>
            </div>
        </span>
        <div class="box box-primary box-solid">
            {* WP6C AdaptiveDataView: the transaction table where it fits; cards (price first) where narrow *}
            <div class="nuxr-adaptive is-lg">
            <div class="table-responsive nuxr-table-wrap">&nbsp;&nbsp;
                <div style="margin-left: 5px; margin-right: 5px;">
                    <table class="table table-bordered table-condensed">
                        <thead>
                            <tr>
                                <th>
                                    <a href="{Text::url('export/print-by-date&')}{$filter}" class="btn btn-default"
                                        target="_blank" aria-label="{Lang::T('Print')}"><i class="ion ion-printer" aria-hidden="true"></i></a>
                                    <a href="{Text::url('export/pdf-by-date&')}{$filter}" class="btn btn-default"><i
                                            class="fa fa-file-pdf-o"></i></a>
                                </th>
                                <th colspan="7"></th>
                            </tr>
                            <tr>
                                <th>{Lang::T('Username')}</th>
                                <th>{Lang::T('Type')}</th>
                                <th>{Lang::T('Plan Name')}</th>
                                <th>{Lang::T('Plan Price')}</th>
                                <th>{Lang::T('Created On')}</th>
                                <th>{Lang::T('Expires On')}</th>
                                <th>{Lang::T('Method')}</th>
                                <th>{Lang::T('Routers')}</th>
                            </tr>
                        </thead>
                        <tbody>
                            {foreach $d as $ds}
                                <tr>
                                    <td>{NuxrUi::holder($ds['username'],$ds['method'])|escape}</td>
                                    <td>{$ds['type']}</td>
                                    <td>{$ds['plan_name']}</td>
                                    <td class="text-right">{Lang::moneyFormat($ds['price'])}</td>
                                    <td>{Lang::dateAndTimeFormat($ds['recharged_on'],$ds['recharged_time'])}</td>
                                    <td>{Lang::dateAndTimeFormat($ds['expiration'],$ds['time'])}</td>
                                    <td>{NuxrUi::method($ds['method'])|escape}</td>
                                    <td>{$ds['routers']|escape}</td>
                                </tr>
                            {/foreach}
                            <tr>
                                <th>{Lang::T('Total')}</th>
                                <td colspan="2"></td>
                                <th class="text-right">{Lang::moneyFormat($dr)}</th>
                                <td colspan="4"></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <ul class="nuxr-mlist">
                <li><div class="nuxr-mcard is-wrap"><div class="nuxr-mcard-main"><span class="nuxr-mcard-title">{Lang::T('Total')}: <bdi dir="ltr">{Lang::moneyFormat($dr)|escape}</bdi></span>
                    <span class="nuxr-mcard-sub"><a href="{Text::url('export/print-by-date&')}{$filter}" target="_blank"><i class="ion ion-printer" aria-hidden="true"></i> {Lang::T('Print')}</a> · <a href="{Text::url('export/pdf-by-date&')}{$filter}"><i class="fa fa-file-pdf-o" aria-hidden="true"></i> PDF</a></span></div></div></li>
                {foreach $d as $ds}
                    <li><div class="nuxr-mcard is-wrap"><div class="nuxr-mcard-main">
                        <span class="nuxr-mcard-title"><bdi dir="ltr">{Lang::moneyFormat($ds['price'])|escape}</bdi> · <bdi dir="ltr">{NuxrUi::holder($ds['username'],$ds['method'])|escape}</bdi></span>
                        <span class="nuxr-mcard-sub">{$ds['plan_name']|escape} · {$ds['type']|escape} · {NuxrUi::method($ds['method'])|escape}</span>
                        <span class="nuxr-mcard-sub"><bdi dir="ltr">{Lang::dateAndTimeFormat($ds['recharged_on'],$ds['recharged_time'])|escape}</bdi> → <bdi dir="ltr">{Lang::dateAndTimeFormat($ds['expiration'],$ds['time'])|escape}</bdi>{if $ds['routers']} · <bdi dir="ltr">{$ds['routers']|escape}</bdi>{/if}</span>
                    </div></div></li>
                {foreachelse}
                    <li><div class="nuxr-empty"><strong>{Lang::T('No records.')}</strong></div></li>
                {/foreach}
            </ul>
            </div>
            <div class="box-footer">
                <p class="text-center small text-info">{Lang::T('All Transactions at Date')}:
                    {Lang::dateAndTimeFormat($sd, $ts)} - {Lang::dateAndTimeFormat($ed, $te)}</p>
            </div>
        </div>
    </div>
</div>

{include file="pagination.tpl"}

<div class="bs-callout bs-callout-warning bg-gray">
    <h4>{Lang::T('Information')}</h4>
    <p>{Lang::T('Export and Print will show all data without pagination')}.</p>
</div>

<script src="{$app_url}/ui/ui/scripts/vendor/chart-3.5.1.min.js" integrity="sha384-zVyRZ9eQ45iRS7ZRZQSFKGJpypyxssU6clBCysSqzjmdGWNn8//uC8dh9DabpAbK"></script>
<script src="{$app_url}/ui/ui/scripts/vendor/chartjs-plugin-autocolors-0.5.1.min.js" integrity="sha384-vnGRB4CdtZ0nxGdDU8ZknCYFULPqwUgZDuBR0bVo3l1hpTKuOCWykajKvrGGFnyz"></script>

{literal}
    <script>
        var isShow = false;

        function showFilter() {
            if (isShow) {
                $("#filter_box").addClass("hidden-xs");
                $("#filter_box").addClass("hidden-sm");
                $("#filter_box").addClass("hidden-md");
                isShow = false;
            } else {
                // remove class
                $("#filter_box").removeClass("hidden-xs");
                $("#filter_box").removeClass("hidden-sm");
                $("#filter_box").removeClass("hidden-md");
                isShow = true;
            }
        }
        document.addEventListener("DOMContentLoaded", function() {
        const autocolors = window['chartjs-plugin-autocolors'];
        Chart.register(autocolors);
        var options = {
        responsive: true,
        aspectRatio: 1,
        plugins: {
            autocolors: {
                mode: 'data'
            },
            legend: {
                position: 'bottom',
                labels: {
                    boxWidth: 15
                }
            }
        }
        };

        function create_cart(field, labels, datas, options) {
        new Chart(document.getElementById(field), {
            type: 'pie',
            data: {
                labels: labels,
                datasets: [{
                    data: datas,
                    borderWidth: 1
                }]
            },
            options: options
        });
        }

        function showChart() {
        // get cart one by one
        $.getJSON("{/literal}{Text::url('reports/ajax/type&', $filter)}{literal}", function( data ) {
        create_cart('cart_type', data.labels, data.datas, options);
        $.getJSON("{/literal}{Text::url('reports/ajax/plan&', $filter)}{literal}", function( data ) {
        create_cart('cart_plan', data.labels, data.datas, options);
        $.getJSON("{/literal}{Text::url('reports/ajax/method&', $filter)}{literal}", function( data ) {
        create_cart('cart_method', data.labels, data.datas, options);
        $.getJSON("{/literal}{Text::url('reports/ajax/router&', $filter)}{literal}", function( data ) {
        create_cart('cart_router', data.labels, data.datas, options);
        getLineChartData();
        });
        });
        });
        });
        }

        if (getCookie('show_report_graph') != 'hide') {
            $("#chart_area").removeClass("hidden");
            document.getElementById('show_chart').checked = true;
            showChart();
        }

        });

        function setShowChart() {
            if (document.getElementById('show_chart').checked) {
                setCookie('show_report_graph', 'show', 30);
            } else {
                setCookie('show_report_graph', 'hide', 30);
            }
            location.reload();
        }

        function getLineChartData() {
            $.getJSON("{/literal}{Text::url('reports/ajax/line&', $filter)}{literal}", function( data ) {
            var linechart = new Chart(document.getElementById('line_cart'), {
                type: 'line',
                data: {
                    labels: data.labels,
                    datasets: data.datas,
                },
                options: {
                    maintainAspectRatio: false,
                    aspectRatio: 1,
                    plugins: {
                        autocolors: {
                            mode: 'data'
                        },
                        legend: {
                            position: 'bottom'
                        }
                    }
                }
            });
        });
        }
        // [{
        //     label: 'a',
        //     data: [8, 3, 9, 2, 7, 4, 2]
        // }, {
        //     label: 'b',
        //     data: [6, 4, 5, 5, 9, 6, 3]
        // }, {
        //     label: 'c',
        //     data: [5, 2, 3, 6, 4, 8, 6]
        // }]
    </script>
{/literal}

{include file="sections/footer.tpl"}