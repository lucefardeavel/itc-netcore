{include file="sections/header.tpl"}

		<div class="row">
			<div class="col-sm-12 col-md-12">
				<div class="panel panel-primary panel-hovered panel-stacked mb30">
					<div class="panel-heading">{Lang::T('Change Password')}</div>
						<div class="panel-body">
						<form class="form-horizontal" method="post" role="form" action="{Text::url('settings/change-password-post')}">
							<input type="hidden" name="csrf_token" value="{$csrf_token}">
							<div class="form-group">
								<label for="password" class="col-md-2 control-label">{Lang::T('Current Password')}</label>
								<div class="col-md-6">
									<input type="password" class="form-control" id="password" name="password">
								</div>
							</div>
							<div class="form-group">
								<label for="npass" class="col-md-2 control-label">{Lang::T('New Password')}</label>
								<div class="col-md-6">
									<input type="password" class="form-control" id="npass" name="npass">
								</div>
							</div>
							<div class="form-group">
								<label for="cnpass" class="col-md-2 control-label">{Lang::T('Confirm New Password')}</label>
								<div class="col-md-6">
									<input type="password" class="form-control" id="cnpass" name="cnpass">
								</div>
							</div>

							{include file="admin/nuxr/actionbar.tpl" nuxr_submit='Save Changes' nuxr_cancel='dashboard'}
						</form>

					</div>
				</div>
			</div>
		</div>

{include file="sections/footer.tpl"}
