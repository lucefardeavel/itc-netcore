{include file="sections/header.tpl"}

<div class="row">
	<div class="col-sm-12 col-md-12">
		<div class="panel panel-primary panel-hovered panel-stacked mb30">
			<div class="panel-heading">{Lang::T('Add Port Pool')}</div>
			<div class="panel-body">
				<form class="form-horizontal" method="post" role="form" action="{Text::url('')}pool/add-port-post">
					<div class="form-group">
						<label for="name" class="col-md-2 control-label">{Lang::T('Port Name')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="name" name="name" placeholder="Vpn Tunnel">
						</div>
					</div>
					<div class="form-group">
						<label for="public_ip" class="col-md-2 control-label">{Lang::T('Public IP')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="public_ip" name="public_ip"
								placeholder="12.34.56.78">
						</div>
					</div>
					<div class="form-group">
						<label for="port_range" class="col-md-2 control-label">{Lang::T('Range Port')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="port_range" name="port_range"
								placeholder="	3000-8000">
						</div>
					</div>
					<div class="form-group">
						<label for="routers" class="col-md-2 control-label"><a
								href="{Text::url('')}routers/add">{Lang::T('Routers')}</a></label>
						<div class="col-md-6">
							<select id="routers" name="routers" class="form-control select2">
								{foreach $r as $rs}
									<option value="{$rs['name']}">{$rs['name']}</option>
								{/foreach}
							</select>
						</div>
					</div>
        {* WP6C: the shared action row - a direct child of the form, sticky above the bottom navigation on phones *}
        <div class="nuxr-step-nav nuxr-actionbar-form">
            <a class="nuxr-btn" href="{Text::url('')}pool/port">{Lang::T('Cancel')}</a>
            <button class="nuxr-btn nuxr-btn-primary" data-nuxr-ask="{Lang::T("Continue the process of adding Ports?")|escape}"
								type="submit"><i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Save')}</button>
        </div>
				</form>

			</div>
		</div>
	</div>
</div>

{include file="sections/footer.tpl"}