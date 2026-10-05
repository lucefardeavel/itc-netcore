{include file="sections/header.tpl"}

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Refill Account')}</div>
            <div class="panel-body">
                <form class="form-horizontal" method="post" role="form" action="{Text::url('')}plan/refill-post">
                    <div class="form-group">
                        <label for="personSelect" class="col-md-2 control-label">{Lang::T('Select Account')}</label>
                        <div class="col-md-6">
                            <select id="personSelect" class="form-control select2" name="id_customer"
                                style="width: 100%" data-placeholder="{Lang::T('Select a customer')}...">
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="code" class="col-md-2 control-label">{Lang::T('Code Voucher')}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="code" name="code"
                                placeholder="{Lang::T('Enter voucher code here')}">
                        </div>
                    </div>

                    {include file="admin/nuxr/actionbar.tpl" nuxr_submit='Recharge' nuxr_icon='fa-refresh' nuxr_ask='Continue the Refill process' nuxr_ask_q=1 nuxr_cancel='customers/list'}
                </form>
            </div>
        </div>
    </div>
</div>


{include file="sections/footer.tpl"}