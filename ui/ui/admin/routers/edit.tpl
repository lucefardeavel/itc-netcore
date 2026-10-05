{include file="sections/header.tpl"}
<!-- routers-edit -->

<div class="row">
    <div class="col-sm-12 col-md-12">
        <div class="panel panel-primary panel-hovered panel-stacked mb30">
            <div class="panel-heading">{Lang::T('Edit Router')}</div>
            <div class="panel-body">
                <form class="form-horizontal" method="post" role="form" action="{Text::url('')}routers/edit-post">
                    <input type="hidden" name="id" value="{$d['id']}">
                    <div class="form-group">
                        <label class="col-md-2 control-label">{Lang::T('Status')}</label>
                        <div class="col-md-10">
                            <label class="radio-inline warning">
                                <input type="radio" {if $d['enabled'] == 1}checked{/if} name="enabled" value="1"> {Lang::T('Enable')}
                            </label>
                            <label class="radio-inline">
                                <input type="radio" {if $d['enabled'] == 0}checked{/if} name="enabled" value="0">
                                {Lang::T('Disable')}
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="name" class="col-md-2 control-label">{Lang::T('Router Name / Location')}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="name" name="name" maxlength="32"
                                value="{$d['name']}">
                            <p class="help-block">{Lang::T('Name of Area that router operated')}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="ip_address" class="col-md-2 control-label">{Lang::T('IP Address')}</label>
                        <div class="col-md-6">
                            <input type="text" placeholder="192.168.88.1:8728" class="form-control" id="ip_address"
                                name="ip_address" value="{$d['ip_address']}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="username" class="col-md-2 control-label">{Lang::T('Username')}</label>
                        <div class="col-md-6">
                            <input type="text" class="form-control" id="username" name="username"
                                value="{$d['username']}">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="password" class="col-md-2 control-label">{Lang::T('Router Secret')}</label>
                        <div class="col-md-6">
                            <input type="password" class="form-control" id="password" name="password"
                                value="{$d['password']}" onmouseleave="this.type = 'password'"
                                onmouseenter="this.type = 'text'" data-nuxr-peek>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="description" class="col-md-2 control-label">{Lang::T('Description')}</label>
                        <div class="col-md-6">
                            <textarea class="form-control" id="description"
                                name="description">{$d['description']}</textarea>
                            <p class="help-block">{Lang::T('Explain Coverage of router')}</p>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="coordinates" class="col-md-2 control-label">{Lang::T('Coordinates')}</label>
                        <div class="col-md-6">
                            <input name="coordinates" id="coordinates" class="form-control" value="{$d['coordinates']}"
                                placeholder="6.465422, 3.406448">
                            <button type="button" class="nuxr-btn" data-nuxr-map="map" data-nuxr-map-init="nuxrMapStart"><i class="fa fa-map-marker" aria-hidden="true"></i> {Lang::T('Show map')}</button>
                            <div id="map" style="width: '100%'; height: 200px; min-height: 150px;" hidden></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="coverage" class="col-md-2 control-label">{Lang::T('Coverage')}</label>
                        <div class="col-md-6">
                            <div class="input-group">
                                <input type="number" class="form-control" id="coverage" name="coverage" value="{$d['coverage']}"
                                onkeyup="updateCoverage()">
                                <span class="input-group-addon">meter(s)</span>
                            </div>
                        </div>
                    </div>
        {* WP6C: the shared action row - a direct child of the form, sticky above the bottom navigation on phones *}
        <div class="nuxr-step-nav nuxr-actionbar-form">
            <a class="nuxr-btn" href="{Text::url('')}routers/list">{Lang::T('Cancel')}</a>
            <button class="nuxr-btn nuxr-btn-primary" onclick="return ask(this, '{Lang::T("Continue the process of changing Routers?")}')" type="submit"><i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Save Changes')}</button>
        </div>
                </form>
            </div>
        </div>
    </div>
</div>
</div>

{* WP6A (OD-H): Leaflet and the map tiles load only when the operator opens the map (nuxr-shell.js).
   WP6C: this note sits outside the literal block, where Smarty strips it (inside, it leaked into the page as text). *}
{literal}
    <script>
        var circle;
        function getLocation() {
            if (window.location.protocol == "https:" && navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(showPosition);
            } else {
                setupMap(51.505, -0.09);
            }
        }

        function showPosition(position) {
            setupMap(position.coords.latitude, position.coords.longitude);
        }

        function updateCoverage() {
            if(circle != undefined){
                circle.setRadius($("#coverage").val());
            }
        }


        function setupMap(lat, lon) {
            var map = L.map('map').setView([lat, lon], 13);
            L.tileLayer('https://{s}.google.com/vt/lyrs=m&hl=en&x={x}&y={y}&z={z}&s=Ga', {
            subdomains: ['mt0', 'mt1', 'mt2', 'mt3'],
                maxZoom: 20
            }).addTo(map);
            circle = L.circle([lat, lon], 5, {
            color: 'blue',
            fillOpacity: 0.1
            }).addTo(map);
            var marker = L.marker([lat, lon]).addTo(map);
            map.on('click', function(e) {
                var coord = e.latlng;
                var lat = coord.lat;
                var lng = coord.lng;
                newLatLng = new L.LatLng(lat, lng);
                marker.setLatLng(newLatLng);
                circle.setLatLng(newLatLng);
                $('#coordinates').val(lat + ',' + lng);
                updateCoverage();
            });
            updateCoverage();
        }
        window.nuxrMapStart = function() {
        {/literal}
        {if $d['coordinates']}
            setupMap({$d['coordinates']});
        {else}
            getLocation();
        {/if}
        {literal}
        }
    </script>
{/literal}
{include file="sections/footer.tpl"}
