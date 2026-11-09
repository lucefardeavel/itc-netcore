<?php
// WP2 (SEC-19 minimal): the installer is disabled once the application is configured.
if (file_exists(__DIR__ . '/../config.php')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden: the application is already installed.');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>ITC NetCore Installer</title>
    <link rel="icon" type="image/png" href="img/netcore-favicon.png">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!--[if lt IE 9]>
    <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
    <![endif]-->

    <link type='text/css' href='css/style.css' rel='stylesheet'/>
    <link type='text/css' href="css/bootstrap.min.css" rel="stylesheet">
</head>

<body style='background-color: #FBFBFB;'>
	<div id='main-container'>
        <img src="img/netcore-logo.png" class="img-responsive" alt="ITC NetCore" />
        <hr>

		<div class="span12">
			<h4> ITC NetCore Installer </h4>
			<?php
			if (isset($_GET['_error']) && ($_GET['_error']) == '1') {
				echo '<h4 style="color: red;"> Unable to Connect Database, Please make sure database info is correct and try again ! </h4>';
			}//

			$cururl = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')|| $_SERVER['SERVER_PORT'] == 443)?'https':'http').'://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			$appurl = str_replace('/install/step3.php', '', $cururl);
			$appurl = str_replace('?_error=1', '', $appurl);
			$appurl = str_replace('/system', '', $appurl);
			?>

			<form action="step4.php" method="post">
				<fieldset>
					<legend>Database Connection &amp Site config</legend>

					<div class="form-group">
						<label for="appurl">Application URL</label>
						<input type="text" class="form-control" id="appurl" name="appurl" value="<?php echo $appurl; ?>">
						<span class='help-block'>Application url without trailing slash at the end of url (e.g. http://172.16.10.10). Please keep default, if you are unsure.</span>
					</div>
					<div class="form-group">
						<label for="dbhost">Database Host</label>
						<input type="text" class="form-control" id="dbhost" required name="dbhost">
					</div>
					<div class="form-group">
						<label for="dbuser">Database Username</label>
						<input type="text" class="form-control" id="dbuser" required name="dbuser">
					</div>
					<div class="form-group">
						<label for="dbpass">Database Password</label>
						<input type="text" class="form-control" id="dbpass" required name="dbpass">
					</div>

					<div class="form-group">
						<label for="dbname">Database Name</label>
						<input type="text" class="form-control" id="dbname" required name="dbname">
					</div>

                    <div class="form-group">
						<label for="radius"><input type="checkbox" class="form-" id="radius" name="radius" value="yes"> Install Radius Table?</label>
						<span class='help-block'>You Don't need this if you planning to use FreeRadius REST</span>
					</div>

					<button type="submit" class="btn btn-primary">Submit</button>
				</fieldset>
			</form>
		</div>
	</div>
	<div class="footer">ITC NetCore V1.0 &middot; ISP Management &amp; Billing Platform &middot; developed and maintained by ITC<br />Based on PHPNuxBill &mdash; Copyright &copy; 2021 PHPNuxBill. All Rights Reserved<br/><br/></div>
</body>
</html>

