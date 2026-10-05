{include file="sections/header.tpl"}

<div class="row">
	<div class="col-sm-12 col-md-12">
	    <div class="panel panel-primary panel-hovered panel-stacked mb30">
		    <div class="panel-heading">{Lang::T('Edit Optical Distribution Points')}</div>
		    <div class="panel-body">

                <form class="form-horizontal" method="post" role="form" action="{Text::url('')}odp/edit-post" >
                    <input type="hidden" name="id" value="{$d['id']}">
                    <div class="form-group">
						<label for="name" class="col-md-2 control-label">{Lang::T('Name')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="name" name="name" value="{$d['name']}" readonly>
						</div>
                    </div>
                    <div class="form-group">
						<label for="port_amount" class="col-md-2 control-label">{Lang::T('Port Amount')}</label>
						<div class="col-md-6">
							<input type="number" class="form-control" id="port_amount" name="port_amount" placeholder="8" max="16" value="{$d['port_amount']}">
						</div>
                    </div>
                    <div class="form-group">
						<label for="attenuation" class="col-md-2 control-label">{Lang::T('Attenuation')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="attenuation" name="attenuation" placeholder="-18.15" value="{$d['attenuation']}">
						</div>
                    </div>
                    <div class="form-group">
                        <label for="address" class="col-md-2 control-label">{Lang::T('Address')}</label>
                        <div class="col-md-6">
                            <textarea name="address" id="address" class="form-control">{$d['address']}</textarea>
                        </div>
                    </div>
                    <div class="form-group">
						<label for="coordinates" class="col-md-2 control-label">{Lang::T('Coordinates')}</label>
						<div class="col-md-6">
							<input type="text" name="coordinates" id="coordinates" class="form-control"
                                   placeholder="6.465422, 3.406448" value="{$d['coordinates']}">
                            <button type="button" class="nuxr-btn" data-nuxr-map="map" data-nuxr-map-init="nuxrMapStart"><i class="fa fa-map-marker" aria-hidden="true"></i> {Lang::T('Show map')}</button>
                            <div id="map" class="mt-2" style="width: 100%; height: 200px; min-height: 150px;" hidden></div>
						</div>
                    </div>
                    <div class="form-group">
                        <label for="coverage" class="col-md-2 control-label">{Lang::T('Coverage (meters)')}</label>
                        <div class="col-md-6">
                            <input type="number" name="coverage" id="coverage" class="form-control"
                                   placeholder="500" value="{$d['coverage']}">
                        </div>
                    </div>
        {* WP6C: the shared action row - a direct child of the form, sticky above the bottom navigation on phones *}
        <div class="nuxr-step-nav nuxr-actionbar-form">
            <a class="nuxr-btn" href="{Text::url('')}odp/list">{Lang::T('Cancel')}</a>
            <button class="nuxr-btn nuxr-btn-primary" onclick="return ask(this, '{Lang::T("Continue the ODP addition process?")}')" type="submit"><i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Save Changes')}</button>
        </div>
                </form>
			</div>
		</div>
	</div>
</div>

{* WP6A (OD-H): Leaflet and the map tiles load only when the operator opens the map (nuxr-shell.js).
   WP6C: this note sits outside the literal block, where Smarty strips it (inside, it leaked into the page as text). *}
{literal}
    <script>
        function getLocation() {
            if (navigator.geolocation) {
                navigator.geolocation.getCurrentPosition(showPosition);
            } else {
                setupMap(51.505, -0.09);
            }
        }

        function showPosition(position) {
            setupMap(position.coords.latitude, position.coords.longitude);
            document.getElementById('coordinates').value = position.coords.latitude + ',' + position.coords.longitude;
        }

        function setupMap(lat, lon) {
            var map = L.map('map').setView([lat, lon], 13);
            L.tileLayer('https://{s}.basemaps.cartocdn.com/rastertiles/light_all/{z}/{x}/{y}.png', {
                attribution:
                    '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors &copy; <a href="https://carto.com/attributions">CARTO</a>',
                    subdomains: 'abcd',
                    maxZoom: 20
            }).addTo(map);
            var marker = L.marker([lat, lon]).addTo(map);
            map.on('click', function(e){
                var coord = e.latlng;
                var lat = coord.lat;
                var lng = coord.lng;
                var newLatLng = new L.LatLng(lat, lng);
                marker.setLatLng(newLatLng);
                $('#coordinates').val(lat + ',' + lng);
            });
        }
        window.nuxrMapStart = function() {
            {/literal}
            {if $d['coordinates']}
                setupMap({$d['coordinates']});
                document.getElementById('coordinates').value = "{$d['coordinates']}";
            {else}
                getLocation();
            {/if}
            {literal}
        }
    </script>
{/literal}

{include file="sections/footer.tpl"}
