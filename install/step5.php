<?php
// WP9 / INST-1: once installed (config.php written by step 4) and the editable pages exist, this step does nothing:
// it used to copy pages_template/ over the operator's edited pages/ and delete pages_template/ for anyone.
if (file_exists(__DIR__ . '/../config.php') && is_dir(__DIR__ . '/../pages')) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Forbidden: the application is already installed.');
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <title>ITC NetCore Installer</title>
    <link rel="shortcut icon" type="image/x-icon" href="img/favicon.ico">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <!--[if lt IE 9]>
    <script src="http://html5shim.googlecode.com/svn/trunk/html5.js"></script>
    <![endif]-->

    <link type='text/css' href='css/style.css' rel='stylesheet' />
    <link type='text/css' href="css/bootstrap.min.css" rel="stylesheet">
</head>
<?php
$sourceDir = dirname(__DIR__) . '/pages_template';
$targetDir = dirname(__DIR__) . '/pages';

function copyDir($src, $dst) {
    $dir = opendir($src);
    if (!$dir) {
        throw new Exception("Cannot open directory: $src");
    }

    if (!file_exists($dst)) {
        if (!mkdir($dst, 0777, true)) {
            throw new Exception("Failed to create directory: $dst");
        }
    }

    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            if (is_dir($src . '/' . $file)) {
                copyDir($src . '/' . $file, $dst . '/' . $file);
            } else {
                if (!copy($src . '/' . $file, $dst . '/' . $file)) {
                    throw new Exception("Failed to copy $src/$file to $dst/$file");
                }
            }
        }
    }
    closedir($dir);
}

function removeDir($dir) {
    if (!is_dir($dir)) return;
    $objects = scandir($dir);
    foreach ($objects as $object) {
        if ($object == '.' || $object == '..') continue;
        if (is_dir($dir . '/' . $object))
            removeDir($dir . '/' . $object);
        else
            if (!unlink($dir . '/' . $object)) {
                throw new Exception("Failed to delete file: $dir/$object");
            }
    }
    if (!rmdir($dir)) {
        throw new Exception("Failed to remove directory: $dir");
    }
}

try {
    if (!file_exists($sourceDir)) {
        throw new Exception("Source directory does not exist.");
    }

    copyDir($sourceDir, $targetDir);
    removeDir($sourceDir);

} catch (Exception $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
?>
<body style='background-color: #FBFBFB;'>
    <div id='main-container'>
        <img src="img/logo.png" class="img-responsive" alt="Logo" />
        <hr>
        <div class="span12">
            <h4> ITC NetCore Installer </h4>
            <p>
                <strong>Congratulations!</strong><br>
                You have just installed ITC NetCore!<br><br>
                <span class="text-danger">But wait!!<br>
                    <ol>
                        <li>Don't forget to rename folder <b>pages_example</b> to <b>pages</b>.<br>
                            if it not yet renamed</li>
                        <li>Activate <a href="https://github.com/hotspotbilling/phpnuxbill/wiki/Cron-Jobs" target="_blank">Cronjob</a> for Expired and Reminder.</li>
                        <li>Check <a href="https://github.com/hotspotbilling/phpnuxbill/wiki/How-It-Works---Cara-kerja" target="_blank">how PHPNuxbill Works</a></li>
                        <li><a href="https://github.com/hotspotbilling/phpnuxbill/wiki#login-page-mikrotik" target="_blank">how to link Mikrotik Login to PHPNuxBill</a></li>
                        <li>or use <a href="https://github.com/hotspotbilling/phpnuxbill-mikrotik-login-template" target="_blank">Mikrotik Login Template for PHPNuxBill</a></li>
                    </ol>
                </span><br><br>
                To Login Admin Portal:<br>
                Use this link -
                <?php
                $cururl = (((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || $_SERVER['SERVER_PORT'] == 443) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
                $appurl = str_replace('/install/step5.php', '', $cururl);
                $appurl = str_replace('/system', '', $appurl);
                echo '<a href="' . htmlspecialchars($appurl, ENT_QUOTES, 'UTF-8') . '/admin">' . htmlspecialchars($appurl, ENT_QUOTES, 'UTF-8') . '/admin</a>';
                ?>
                <br>
                Username: admin<br>
                Password: admin<br>
                For security, Delete the <b>install</b> directory inside system folder.
            </p>
        </div>
    </div>
    <div class="footer">ITC NetCore V1.0 &middot; ISP Management &amp; Billing Platform &middot; developed and maintained by ITC<br />Based on PHPNuxBill &mdash; Copyright &copy; 2021 PHPNuxBill. All Rights Reserved<br /><br /></div>
</body>

</html>