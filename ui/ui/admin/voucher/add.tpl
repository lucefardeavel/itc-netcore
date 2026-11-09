{include file="sections/header.tpl"}
<!-- voucher-add -->

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Add Vouchers')}</div>
            <div class="panel-body">

                <form class="form-horizontal" method="post" role="form" action="{Text::url('')}plan/voucher-post">
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Type')}</label>
                        <div class="col-md-6">
                            <label class="radio-inline"><input type="radio" id="Hot" name="type" value="Hotspot"> {Lang::T('Hotspot card (the code logs in)')}</label>
                            <label class="radio-inline"><input type="radio" id="POE" name="type" value="PPPOE"> {Lang::T('Recharge card for a PPPoE subscriber')}</label>
                        </div>
                        <p class="help-block col-md-4">{Lang::T('Recharge cards are for subscribers managed directly by the company; subscribers of a reseller are renewed by their reseller.')}</p>
                    </div>
                    <div class="form-group" id="login-style-row">
                        <label for="login_style" class="col-md-2 control-label">{Lang::T('Login on the card')}</label>
                        <div class="col-md-6">
                            <select id="login_style" name="login_style" class="form-control">
                                <option value="code" selected>{Lang::T('One code (user name = password)')}</option>
                                <option value="userpass">{Lang::T('User name and a separate password')}</option>
                            </select>
                        </div>
                        <p class="help-block col-md-4">{Lang::T('For hotspot cards. With a separate password the card shows both, and both are typed on the login page.')}</p>
                    </div>
                    <div class="form-group" id="password-length-row" style="display:none">
                        <label for="password_length" class="col-md-2 control-label">{Lang::T('Password length')}</label>
                        <div class="col-md-6">
                            <input id="password_length" type="number" min="4" max="12" class="form-control" name="password_length" value="6">
                        </div>
                        <p class="help-block col-md-4">{Lang::T('Digits, 4 to 12.')}</p>
                    </div>
                    <script>
                        document.addEventListener('change', function (e) {
                            if (!e.target) { return; }
                            if (e.target.id === 'login_style') {
                                document.getElementById('password-length-row').style.display = e.target.value === 'userpass' ? 'block' : 'none';
                            }
                            if (e.target.name === 'type') {
                                var hs = document.getElementById('Hot').checked;
                                document.getElementById('login-style-row').style.display = hs ? 'block' : 'none';
                                if (!hs) { document.getElementById('login_style').value = 'code'; document.getElementById('password-length-row').style.display = 'none'; }
                            }
                        });
                    </script>
                    <div class="form-group">
                        <label for="server" class="col-md-2 control-label">{Lang::T('Routers')}</label>
                        <div class="col-md-6">
                            <select id="server" name="server" class="form-control select2">
                                <option value=''>{Lang::T('Select Routers')}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="plan" class="col-md-2 control-label">{Lang::T('Service Plan')}</label>
                        <div class="col-md-6">
                            <select id="plan" name="plan" class="form-control select2">
                                <option value=''>{Lang::T('Select Plans')}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="nxf-numbervoucher" class="col-md-2 control-label">{Lang::T('Number of Vouchers')}</label>
                        <div class="col-md-6">
                            <input id="nxf-numbervoucher" type="text" class="form-control" name="numbervoucher" value="1">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="voucher_format" class="col-md-2 control-label">{Lang::T('Voucher Format')}</label>
                        <div class="col-md-6">
                            <select name="voucher_format" id="voucher_format" class="form-control">
                                <option value="numbers" {if $_c['voucher_format']=='numbers' || empty($_c['voucher_format'])}selected="selected" {/if}>{Lang::T('Digits only (easiest to type)')}</option>
                                <option value="up" {if $_c['voucher_format']=='up' }selected="selected" {/if}>{Lang::T('Capital letters and digits')}</option>
                                <option value="low" {if $_c['voucher_format']=='low' }selected="selected" {/if}>{Lang::T('Small letters and digits')}</option>
                                <option value="rand" {if $_c['voucher_format']=='rand' }selected="selected" {/if}>{Lang::T('Mixed letters and digits')}</option>
                            </select>
                        </div>
                        <p class="help-block col-md-4">{Lang::T('Digits are the easiest for subscribers to type on a phone.')}</p>
                    </div>
                    <div class="form-group">
                        <label for="nxf-prefix" class="col-md-2 control-label">{Lang::T('Voucher Prefix')}</label>
                        <div class="col-md-6">
                            <input id="nxf-prefix" type="text" class="form-control" name="prefix" placeholder="ITC-"
                                value="{$_c['voucher_prefix']}">
                        </div>
                        <p class="help-block col-md-4">{Lang::T('Optional text before every code, for example ITC-.')}</p>
                    </div>
                    <div class="form-group">
                        <label for="nxf-lengthcode" class="col-md-2 control-label">{Lang::T('Length Code')}</label>
                        <div class="col-md-6">
                            <input id="nxf-lengthcode" type="text" class="form-control" name="lengthcode" value="10">
                        </div>
                        <p class="help-block col-md-4">{Lang::T('Number of characters after the prefix (digits: at least 6).')}</p>
                        <div class="hidden">
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="print_now" class="col-sm-2 control-label">{Lang::T('Print Now')}</label>

                        <div class="col-sm-10">
                            <input type="checkbox" id="print_now" name="print_now" class="iCheck" value="yes"
                                onclick="showVouchersPerPage()">
                        </div>
                    </div>

                    <div class="form-group" id="printers" style="display:none;">
                        <label for="print_format" class="col-md-2 control-label">{Lang::T('Printer')}</label>
                        <div class="col-md-6">
                            <select id="print_format" name="print_format" class="form-control">
                                <option value="thermal80" selected>{Lang::T('Thermal 80 mm')}</option>
                                <option value="a4">{Lang::T('A4 sheet')}</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-group" id="printers-a4" style="display:none;">
                        <label for="voucher-print" class="col-md-2 control-label">{Lang::T('Vouchers Per Page')}</label>
                        <div class="col-md-6">
                            <input type="text" id="voucher-print" class="form-control" name="voucher_per_page"
                                value="36" placeholder="{Lang::T("Vouchers Per Page")} (default 36)">
                        </div>
                        <p class="help-block col-md-4">
                            {Lang::T('Vouchers Per Page')} (default 36)
                        </p>
                    </div>
                    {include file="admin/nuxr/actionbar.tpl" nuxr_submit='Generate' nuxr_icon='fa-ticket' nuxr_ask='Continue the Voucher creation process?' nuxr_cancel='plan/voucher'}
                </form>

            </div>
        </div>
    </div>
</div>

<!-- /voucher-add -->

<script>
    function showVouchersPerPage() {
        var printNow = document.getElementById('print_now');
        var printers = document.getElementById('printers');
        var voucherPrint = document.getElementById('voucher-print');

        var format = document.getElementById('print_format');
        var a4 = document.getElementById('printers-a4');

        voucherPrint.required = false;
        if (printNow.checked) {
            printers.style.display = 'block';
            a4.style.display = format.value === 'a4' ? 'block' : 'none';
            voucherPrint.required = format.value === 'a4';
        } else {
            printers.style.display = 'none';
            a4.style.display = 'none';
        }
    }
    document.addEventListener('change', function (e) { if (e.target && e.target.id === 'print_format') { showVouchersPerPage(); } });
</script>

{include file="sections/footer.tpl"}