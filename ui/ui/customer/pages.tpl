{include file="customer/header.tpl"}
<!-- user-pages -->

					<div class="row">
						<div class="col-sm-12">
							<div class="panel mb20 panel-primary panel-hovered">
								<div class="panel-heading">{$_L[$pageHeader]}</div>
								<div class="panel-body">
									{nuxr_page name=$PageFile}
								</div>
							</div>
						</div>
					</div>

{include file="customer/footer.tpl"}
