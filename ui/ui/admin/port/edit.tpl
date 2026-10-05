{include file="sections/header.tpl"}

<div class="row">
	<div class="col-sm-12 col-md-12">
		<div class="panel panel-primary panel-hovered panel-stacked mb30">
			<div class="panel-heading">{Lang::T('Edit Port')}</div>
			<div class="panel-body">

				<form class="form-horizontal" method="post" role="form" action="{Text::url('')}pool/edit-port-post">
					<input type="hidden" name="id" value="{$d['id']}">
					<div class="form-group">
						<label for="name" class="col-md-2 control-label">{Lang::T('Port Name')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="name" name="name" value="{$d['port_name']}">
						</div>
					</div>
					<div class="form-group">
						<label for="public_ip" class="col-md-2 control-label">{Lang::T('Public IP')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="public_ip" name="public_ip"
								value="{$d['public_ip']}" placeholder="12.34.56.78">
						</div>
					</div>
					<div class="form-group">
						<label for="range_port" class="col-md-2 control-label">{Lang::T('Range Port')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="range_port" name="range_port"
								value="{$d['range_port']}">
						</div>
					</div>
					<div class="form-group">
						<label for="routers" class="col-md-2 control-label">{Lang::T('Routers')}</label>
						<div class="col-md-6">
							<input type="text" class="form-control" id="routers" name="routers" value="{$d['routers']}"
								readonly>
						</div>
					</div>

        {* WP6C: the shared action row - a direct child of the form, sticky above the bottom navigation on phones *}
        <div class="nuxr-step-nav nuxr-actionbar-form">
            <a class="nuxr-btn" href="{Text::url('')}pool/port">{Lang::T('Cancel')}</a>
            <button class="nuxr-btn nuxr-btn-primary" data-nuxr-ask="{Lang::T("Continue the Port change process?")|escape}"
								type="submit"><i class="fa fa-check" aria-hidden="true"></i>{Lang::T('Save Changes')}</button>
        </div>
				</form>

			</div>
		</div>
	</div>
</div>

{include file="sections/footer.tpl"}