<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{ucwords(Lang::T($type))} - {$_brand.company|escape}</title>
    <meta content="width=device-width, initial-scale=1" name="viewport">
    <link rel="icon" href="{$app_url}/ui/ui/images/brand/favicon.svg" type="image/svg+xml" />
    <link rel="icon" href="{$app_url}/ui/ui/images/pwa/icon-192.png" type="image/png" sizes="192x192" />
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/bootstrap.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/modern-AdminLTE.min.css">
    <link rel="stylesheet" href="{$app_url}/ui/ui/styles/nuxr-brand.css?brand.1" />
    <meta http-equiv="refresh" content="{$time}; url={$url}">
</head>

<body class="hold-transition lockscreen nuxr-portal">
    <div class="lockscreen-wrapper">
        <div class="panel panel-{$type}">
            <div class="panel-heading">{ucwords(Lang::T($type))}</div>
            <div class="panel-body">
                {$text}
            </div>
            <div class="panel-footer">
                <a href="{$url}" id="button" class="btn btn-{$type} btn-block btn-block">{Lang::T('Click Here')} ({$time})</a>
            </div>
        </div>
        <div class="lockscreen-footer text-center">
            {$_brand.company|escape}
        </div>
    </div>

    <script>
        var time = {$time};
        timer();

        function timer() {
            setTimeout(() => {
                time--;
                if (time > -1) {
                    document.getElementById("button").innerHTML = "{Lang::T('Click Here')} (" + time + ")";
                    timer();
                }
            }, 1000);
        }
    </script>
</body>

</html>