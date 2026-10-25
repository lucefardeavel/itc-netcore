{include file="sections/header.tpl"}
{include file="admin/nuxr/macros.tpl"}
{* WP6A create subscriber: one form, one POST (customers/add-post, CustomerService re-validates everything and resolves
   ownership by role). Phone: a 4-step stepper (identity -> credentials / network -> owner / Sales -> review); tablet and
   desktop: the same sections on one page. Nothing is stored between steps. *}
<ol class="nuxr-stepper nuxr-only-phone" aria-label="{Lang::T('Steps')}">
    <li>{Lang::T('Identity')}</li>
    <li>{Lang::T('Access')}</li>
    <li>{Lang::T('Owner')}</li>
    <li>{Lang::T('Review')}</li>
</ol>
<form class="nuxr-form nuxr-create" method="post" action="{Text::url('customers/add-post')}" data-nuxr-steps autocomplete="off">
    <input type="hidden" name="csrf_token" value="{$csrf_token}">
    <div class="nuxr-grid nuxr-grid-2">
        <section class="nuxr-card" data-nuxr-step aria-labelledby="nuxr-step-1">
            <div class="nuxr-card-head"><h2 id="nuxr-step-1"><span class="nuxr-stepno" aria-hidden="true">1</span>{Lang::T('Identity and service')}</h2></div>
            <div class="nuxr-card-body nuxr-fields">
                <label for="fullname">{Lang::T('Full Name')} <span aria-hidden="true">*</span></label>
                <input id="fullname" name="fullname" class="form-control nuxr-input" required maxlength="45" autocomplete="off" value="{$nuxr_refill.fullname|escape}">
                <label for="phonenumber">{Lang::T('Phone Number')}</label>
                <input id="phonenumber" name="phonenumber" class="form-control nuxr-input ltr" dir="ltr" inputmode="tel" maxlength="20" value="{$nuxr_refill.phonenumber|escape}"
                    placeholder="{if $_c['country_code_phone'] neq ''}{$_c['country_code_phone']|escape}…{/if}">
                <label for="email">{Lang::T('Email')}</label>
                <input id="email" type="email" name="email" class="form-control nuxr-input ltr" dir="ltr" maxlength="128" value="{$nuxr_refill.email|escape}">
                <label for="service_type">{Lang::T('Service Type')}</label>
                <select id="service_type" name="service_type" class="form-control nuxr-input">
                    <option value="Hotspot">Hotspot</option>
                    <option value="PPPoE" {if $nuxr_refill.service_type eq 'PPPoE'}selected{/if}>PPPoE</option>
                    <option value="VPN" {if $nuxr_refill.service_type eq 'VPN'}selected{/if}>VPN</option>
                    <option value="Others" {if $nuxr_refill.service_type eq 'Others'}selected{/if}>{Lang::T('Other')}</option>
                </select>
                <label for="account_type">{Lang::T('Account Type')}</label>
                <select id="account_type" name="account_type" class="form-control nuxr-input">
                    <option value="Personal">{Lang::T('Personal')}</option>
                    <option value="Business" {if $nuxr_refill.account_type eq 'Business'}selected{/if}>{Lang::T('Business')}</option>
                </select>
            </div>
            <div class="nuxr-step-nav"><button type="button" class="nuxr-btn nuxr-btn-primary" data-nuxr-step-next>{Lang::T('Next')}<i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></button></div>
        </section>

        <section class="nuxr-card" data-nuxr-step aria-labelledby="nuxr-step-2">
            <div class="nuxr-card-head"><h2 id="nuxr-step-2"><span class="nuxr-stepno" aria-hidden="true">2</span>{Lang::T('Credentials and network')}</h2></div>
            <div class="nuxr-card-body nuxr-fields">
                <label for="username">{Lang::T('Username')} <span aria-hidden="true">*</span></label>
                <input id="username" name="username" class="form-control nuxr-input ltr" dir="ltr" required minlength="3" maxlength="45" pattern="[A-Za-z0-9:+_.@\-]+"
                    aria-describedby="nuxr-username-help" autocapitalize="off" spellcheck="false" value="{$nuxr_refill.username|escape}">
                <small id="nuxr-username-help" class="nuxr-muted">{Lang::T('Letters, digits and : + _ . @ - only.')}</small>
                <label for="password">{Lang::T('Password')} <span aria-hidden="true">*</span></label>
                <div class="nuxr-filters" style="margin:0">
                    <input id="password" name="password" class="form-control nuxr-input ltr" dir="ltr" required minlength="3" maxlength="35" style="flex:1 1 180px" spellcheck="false">
                    <button type="button" class="nuxr-btn" data-nuxr-genpass="password"><i class="fa fa-random" aria-hidden="true"></i>{Lang::T('Generate')}</button>
                </div>
                <fieldset class="nuxr-pppoe" style="margin-top:12px">
                    <legend style="font-size:15px;font-weight:700;border:0;margin:0 0 4px">PPPoE <span class="nuxr-muted" style="font-weight:400">({Lang::T('optional')})</span></legend>
                    <label for="pppoe_username">{Lang::T('PPPoE username')}</label>
                    <input id="pppoe_username" name="pppoe_username" class="form-control nuxr-input ltr" dir="ltr" maxlength="45" autocapitalize="off" spellcheck="false"
                        aria-describedby="nuxr-pppoe-help" value="{$nuxr_refill.pppoe_username|escape}">
                    <label for="pppoe_password">{Lang::T('PPPoE password')}</label>
                    <input id="pppoe_password" name="pppoe_password" class="form-control nuxr-input ltr" dir="ltr" maxlength="45" spellcheck="false">
                    <label for="pppoe_ip">{Lang::T('Static IP')}</label>
                    <input id="pppoe_ip" name="pppoe_ip" class="form-control nuxr-input ltr" dir="ltr" maxlength="32" inputmode="decimal" placeholder="10.0.0.10" value="{$nuxr_refill.pppoe_ip|escape}">
                    <small id="nuxr-pppoe-help" class="nuxr-muted">{Lang::T('When empty, the PPPoE login uses the subscriber\'s username and password.')}</small>
                </fieldset>
                <label for="address" style="margin-top:12px">{Lang::T('Home Address')}</label>
                <textarea id="address" name="address" class="form-control" rows="2" maxlength="255">{$nuxr_refill.address|escape}</textarea>
                <label for="coordinates">{Lang::T('Coordinates')}</label>
                <input id="coordinates" name="coordinates" class="form-control nuxr-input ltr" dir="ltr" maxlength="64" placeholder="lat,lng">
                <button type="button" class="nuxr-btn" style="margin-top:8px" data-nuxr-map="nuxr-map" ><i class="fa fa-map-marker" aria-hidden="true"></i>{Lang::T('Pick on map')}</button>
                <div id="nuxr-map" data-init="nuxrInitCreateMap" style="height:260px;margin-top:8px;border-radius:8px" hidden></div>
                <details style="margin-top:12px">
                    <summary class="nuxr-btn">{Lang::T('Additional information')}</summary>
                    <div class="nuxr-fields" style="margin-top:8px">
                        <label for="city">{Lang::T('City')}</label><input id="city" name="city" class="form-control nuxr-input" maxlength="64">
                        <label for="district">{Lang::T('District')}</label><input id="district" name="district" class="form-control nuxr-input" maxlength="64">
                        <label for="state">{Lang::T('State')}</label><input id="state" name="state" class="form-control nuxr-input" maxlength="64">
                        <label for="zip">{Lang::T('Zip')}</label><input id="zip" name="zip" class="form-control nuxr-input ltr" dir="ltr" maxlength="16">
                        <div id="nuxr-custom-fields"></div>
                        <button type="button" class="nuxr-btn" id="nuxr-add-field"><i class="fa fa-plus" aria-hidden="true"></i>{Lang::T('Add attribute')}</button>
                    </div>
                </details>
            </div>
            <div class="nuxr-step-nav"><button type="button" class="nuxr-btn" data-nuxr-step-prev><i class="fa fa-angle-left nuxr-dir-icon" aria-hidden="true"></i>{Lang::T('Back')}</button>
                <button type="button" class="nuxr-btn nuxr-btn-primary" data-nuxr-step-next>{Lang::T('Next')}<i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></button></div>
        </section>

        {* owner / Sales: what the actor may choose (the server resolves and re-validates ownership by role) *}
        <section class="nuxr-card" data-nuxr-step aria-labelledby="nuxr-step-3">
            <div class="nuxr-card-head"><h2 id="nuxr-step-3"><span class="nuxr-stepno" aria-hidden="true">3</span>{Lang::T('Commercial owner and Sales')}</h2></div>
            <div class="nuxr-card-body nuxr-fields">
                {if $nuxr_create_scope eq 'G'}
                    <label for="manager_id">{Lang::T('Owner (reseller)')}</label>
                    <select id="manager_id" name="manager_id" class="form-control nuxr-input">
                        <option value="">{Lang::T('Unassigned - legacy customer, Admin only')}</option>
                        {foreach $nuxr_owner_options as $o}<option value="{$o['id']}">{$o['username']|escape} | {$o['fullname']|escape}</option>{/foreach}
                    </select>
                    <small class="nuxr-muted">{Lang::T('A reseller-owned subscriber is served only through reseller activations, paid from the owner\'s wallet.')}</small>
                    <label for="assigned_sales_id">{Lang::T('Sales')}</label>
                    <select id="assigned_sales_id" name="assigned_sales_id" class="form-control nuxr-input" data-nuxr-owner-filter="manager_id">
                        <option value="">{Lang::T('None')}</option>
                        {foreach $nuxr_owner_options as $o}
                            {if $nuxr_owner_sales[$o['id']]|default:[]}
                                <optgroup label="{$o['username']|escape}" data-owner="{$o['id']}">
                                    {foreach $nuxr_owner_sales[$o['id']] as $s}<option value="{$s['id']}">{$s['username']|escape} | {$s['fullname']|escape}</option>{/foreach}
                                </optgroup>
                            {/if}
                        {/foreach}
                    </select>
                    <small class="nuxr-muted">{Lang::T('Only a Sales account of the chosen owner; none for an unassigned customer.')}</small>
                {elseif $nuxr_create_scope eq 'T'}
                    <dl class="nuxr-kv"><div><dt>{Lang::T('Owner (reseller)')}</dt><dd>{Lang::T('You')} (<bdi dir="ltr">{$_admin['username']|escape}</bdi>)</dd></div></dl>
                    {if $nuxr_sales_options}
                        <label for="assigned_sales_id">{Lang::T('Sales')}</label>
                        <select id="assigned_sales_id" name="assigned_sales_id" class="form-control nuxr-input">
                            <option value="">{Lang::T('None')}</option>
                            {foreach $nuxr_sales_options as $o}<option value="{$o['id']}">{$o['username']|escape} | {$o['fullname']|escape}</option>{/foreach}
                        </select>
                    {else}
                        <p class="nuxr-muted">{Lang::T('You have no Sales accounts; the subscriber is yours only.')}</p>
                    {/if}
                {else}
                    <dl class="nuxr-kv">
                        <div><dt>{Lang::T('Owner (reseller)')}</dt><dd>{if $nuxr_parent_owner|default:''}<bdi dir="ltr">{$nuxr_parent_owner|escape}</bdi>{else}{Lang::T('Your reseller')}{/if}</dd></div>
                        <div><dt>{Lang::T('Sales')}</dt><dd>{Lang::T('You')} (<bdi dir="ltr">{$_admin['username']|escape}</bdi>)</dd></div>
                    </dl>
                    <p class="nuxr-muted">{Lang::T('A subscriber you create belongs to your reseller and is assigned to you.')}</p>
                {/if}
            </div>
            <div class="nuxr-step-nav"><button type="button" class="nuxr-btn" data-nuxr-step-prev><i class="fa fa-angle-left nuxr-dir-icon" aria-hidden="true"></i>{Lang::T('Back')}</button>
                <button type="button" class="nuxr-btn nuxr-btn-primary" data-nuxr-step-next>{Lang::T('Next')}<i class="fa fa-angle-right nuxr-dir-icon" aria-hidden="true"></i></button></div>
        </section>

        <section class="nuxr-card" data-nuxr-step aria-labelledby="nuxr-step-4">
            <div class="nuxr-card-head"><h2 id="nuxr-step-4"><span class="nuxr-stepno" aria-hidden="true">4</span>{Lang::T('Review and create')}</h2></div>
            <div class="nuxr-card-body">
                <dl class="nuxr-kv nuxr-only-phone">
                    <div><dt>{Lang::T('Full Name')}</dt><dd data-nuxr-review="fullname">-</dd></div>
                    <div><dt>{Lang::T('Username')}</dt><dd><bdi dir="ltr" data-nuxr-review="username">-</bdi></dd></div>
                    <div><dt>{Lang::T('Phone')}</dt><dd><bdi dir="ltr" data-nuxr-review="phonenumber">-</bdi></dd></div>
                    <div><dt>{Lang::T('Service Type')}</dt><dd data-nuxr-review="service_type">-</dd></div>
                    <div><dt>{Lang::T('PPPoE username')}</dt><dd><bdi dir="ltr" data-nuxr-review="pppoe_username">-</bdi></dd></div>
                    <div><dt>{Lang::T('Static IP')}</dt><dd><bdi dir="ltr" data-nuxr-review="pppoe_ip">-</bdi></dd></div>
                    {if $nuxr_create_scope eq 'G'}<div><dt>{Lang::T('Owner (reseller)')}</dt><dd data-nuxr-review="manager_id">-</dd></div>{/if}
                    {if $nuxr_create_scope eq 'G' || ($nuxr_create_scope eq 'T' && $nuxr_sales_options)}<div><dt>{Lang::T('Sales')}</dt><dd data-nuxr-review="assigned_sales_id">-</dd></div>{/if}
                </dl>
                <label style="display:flex;gap:8px;align-items:center;margin-top:10px">
                    <input type="checkbox" id="send_welcome_message" name="send_welcome_message" value="1"> {Lang::T('Send welcome message')}</label>
                <div id="nuxr-welcome-channels" class="nuxr-filters" style="margin:6px 0 0" hidden>
                    <label class="nuxr-chip"><input type="checkbox" name="sms" value="1"> SMS</label>
                    <label class="nuxr-chip"><input type="checkbox" name="wa" value="1"> WhatsApp</label>
                    <label class="nuxr-chip"><input type="checkbox" name="mail" value="1"> {Lang::T('Email')}</label>
                </div>
                <p class="nuxr-muted" style="margin:12px 0 0">{Lang::T('After creating, you can activate the service now or later.')}</p>
            </div>
            <div class="nuxr-step-nav nuxr-step-final">
                <button type="button" class="nuxr-btn" data-nuxr-step-prev><i class="fa fa-angle-left nuxr-dir-icon" aria-hidden="true"></i>{Lang::T('Back')}</button>
                <a href="{Text::url('customers/list')}" class="nuxr-btn nuxr-hide-phone">{Lang::T('Cancel')}</a>
                <button type="submit" class="nuxr-btn nuxr-btn-primary" data-nuxr-step-submit><i class="fa fa-user-plus" aria-hidden="true"></i>{Lang::T('Create subscriber')}</button>
            </div>
        </section>
    </div>
</form>
<script>
    (function () {
        // suggested password (UX only; the server validates)
        function gen(n) {
            var chars = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789', out = '', buf = new Uint32Array(n);
            (window.crypto || window.msCrypto).getRandomValues(buf);
            for (var i = 0; i < n; i++) { out += chars[buf[i] % chars.length]; }
            return out;
        }
        document.querySelectorAll('[data-nuxr-genpass]').forEach(function (b) {
            var f = document.getElementById(b.getAttribute('data-nuxr-genpass'));
            if (f && !f.value) { f.value = gen(10); }
            b.addEventListener('click', function () { f.value = gen(10); f.dispatchEvent(new Event('input', { bubbles: true })); });
        });
        // PPPoE fields are emphasized for PPPoE subscribers (still available for any type, as before)
        var type = document.getElementById('service_type'), pppoe = document.querySelector('.nuxr-pppoe');
        function syncType() { if (pppoe) { pppoe.classList.toggle('is-muted', type.value !== 'PPPoE'); } }
        if (type) { type.addEventListener('change', syncType); syncType(); }
        // Admin: only the chosen owner's Sales accounts are selectable (the server re-validates)
        var sales = document.querySelector('[data-nuxr-owner-filter]');
        if (sales) {
            var owner = document.getElementById(sales.getAttribute('data-nuxr-owner-filter'));
            var syncSales = function () {
                Array.prototype.forEach.call(sales.querySelectorAll('optgroup'), function (g) {
                    var on = g.getAttribute('data-owner') === owner.value;
                    g.hidden = !on; g.disabled = !on;
                });
                var opt = sales.options[sales.selectedIndex];
                if (opt && opt.parentNode.tagName === 'OPTGROUP' && opt.parentNode.disabled) { sales.value = ''; }
                sales.disabled = owner.value === '';
            };
            owner.addEventListener('change', syncSales); syncSales();
        }
        // welcome message channels: at least one when sending
        var welcome = document.getElementById('send_welcome_message'), channels = document.getElementById('nuxr-welcome-channels');
        if (welcome) {
            welcome.addEventListener('change', function () { channels.hidden = !welcome.checked; });
            welcome.form.addEventListener('submit', function (e) {
                if (welcome.checked && !channels.querySelector('input:checked')) {
                    e.preventDefault();
                    Swal.fire({ icon: 'info', title: '{Lang::T('Choose at least one channel for the welcome message.')|escape:'javascript'}' });
                }
            });
        }
        // custom attributes (sent as custom_field_name[] / custom_field_value[] as before)
        var wrap = document.getElementById('nuxr-custom-fields'), add = document.getElementById('nuxr-add-field');
        if (add) {
            add.addEventListener('click', function () {
                var row = document.createElement('div');
                row.className = 'nuxr-filters';
                row.innerHTML = '<input class="form-control nuxr-input" name="custom_field_name[]" placeholder="{Lang::T('Name')|escape:'javascript'}" aria-label="{Lang::T('Attribute name')|escape:'javascript'}" style="flex:1 1 120px">'
                    + '<input class="form-control nuxr-input" name="custom_field_value[]" placeholder="{Lang::T('Value')|escape:'javascript'}" aria-label="{Lang::T('Attribute value')|escape:'javascript'}" style="flex:1 1 120px">'
                    + '<button type="button" class="nuxr-btn" aria-label="{Lang::T('Remove')|escape:'javascript'}">&times;</button>';
                row.querySelector('button').addEventListener('click', function () { row.remove(); });
                wrap.appendChild(row);
            });
        }
    })();
    // lazy map (OD-H): Leaflet and the map tiles load only after "Pick on map"
    function nuxrInitCreateMap(holder) {
        var start = function (lat, lon) {
            var map = L.map(holder).setView([lat, lon], 13);
            L.tileLayer('https://{literal}{s}{/literal}.google.com/vt/lyrs=m&hl=en&x={literal}{x}{/literal}&y={literal}{y}{/literal}&z={literal}{z}{/literal}&s=Ga', { subdomains: ['mt0', 'mt1', 'mt2', 'mt3'], maxZoom: 20 }).addTo(map);
            var marker = L.marker([lat, lon]).addTo(map);
            map.on('click', function (e) { marker.setLatLng(e.latlng); document.getElementById('coordinates').value = e.latlng.lat.toFixed(6) + ',' + e.latlng.lng.toFixed(6); });
        };
        var c = (document.getElementById('coordinates').value || '').split(',');
        if (c.length === 2 && !isNaN(parseFloat(c[0]))) { start(parseFloat(c[0]), parseFloat(c[1])); }
        else if (window.location.protocol === 'https:' && navigator.geolocation) { navigator.geolocation.getCurrentPosition(function (p) { start(p.coords.latitude, p.coords.longitude); }, function () { start(33.3152, 44.3661); }); }
        else { start(33.3152, 44.3661); }
    }
</script>
{include file="sections/footer.tpl"}
