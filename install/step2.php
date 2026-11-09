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

    <link type='text/css' href='css/style.css' rel='stylesheet' />
    <link type='text/css' href="css/bootstrap.min.css" rel="stylesheet">
</head>

<body style='background-color: #FBFBFB;'>
    <div id='main-container'>
        <img src="img/netcore-logo.png" class="img-responsive" alt="ITC NetCore" />
        <hr>

        <div class="span12">
            <h4> ITC NetCore Installer </h4>
            <?php
            $passed = '';
            $ltext = '';
            if (version_compare(PHP_VERSION, '7.2.0') >= 0) {
                $ltext .= 'To run ITC NetCore you need at least PHP version 7.2.0, Your PHP Version is: ' . PHP_VERSION . " Tested <strong>---PASSED---</strong><br/>";
                $passed .= '1';
            } else {
                $ltext .= 'To run ITC NetCore you need at least PHP version 7.2.0, Your PHP Version is: ' . PHP_VERSION . " Tested <strong>---FAILED---</strong><br/>";
                $passed .= '0';
            }

            if (extension_loaded('PDO')) {
                $ltext .= 'PDO is installed on your server: ' . "Tested <strong>---PASSED---</strong><br/>";
                $passed .= '1';
            } else {
                $ltext = 'PDO is installed on your server: ' . "Tested <strong>---FAILED---</strong><br/>";
                $passed .= '0';
            }

            if (extension_loaded('pdo_mysql')) {
                $ltext .= 'PDO MySQL driver is enabled on your server: ' . "Tested <strong>---PASSED---</strong><br/>";
                $passed .= '1';
            } else {
                $ltext .= 'PDO MySQL driver is not enabled on your server: ' . "Tested <strong>---FAILED---</strong><br/>";
                $passed .= '0';
            }

            if ($passed == '111') {
                echo ("<br/> $ltext <br/> Great! System Test Completed. You can run ITC NetCore on your server. Click Continue For Next Step.
				<br><br>
                <a href=\"update.php\" class=\"btn btn-warning\">Update System From PHPMixBill</a>
                <br><br><br><br>
				<a href=\"step3.php\" class=\"btn btn-primary\">Continue to Install ITC NetCore</a><br><br>");
            } else {
                echo ("<br/> $ltext <br/> Sorry. The requirements of ITC NetCore are not available on your server.
				Contact ITC (www.itc-iq.net) or your server administrator with this code: $passed
				<br><br>
				<a href=\"#\" class=\"btn btn-primary disabled\">Correct The Problem To Continue</a>");
            }
            ?>
        </div>
    </div>
    <div class="footer">ITC NetCore V1.0 &middot; ISP Management &amp; Billing Platform &middot; developed and maintained by ITC<br />Based on PHPNuxBill &mdash; Copyright &copy; 2021 PHPNuxBill. All Rights Reserved<br /><br /></div>
</body>

</html>