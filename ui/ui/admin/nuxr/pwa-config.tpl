{* WP7: staff PWA client configuration - the heartbeat URL and the translated UI strings. No user, session, token or
   business data. Included by the staff footer and the staff login page before nuxr-pwa.js. *}
<script>
    window.nuxrPwa = {
        ping: '{$app_url|escape:'javascript'}/?_route=pwa/ping',
        s: {
            slow: '{Lang::T('Slow connection')|escape:'javascript'}',
            offline: '{Lang::T('Offline')|escape:'javascript'}',
            reconnecting: '{Lang::T('Reconnecting...')|escape:'javascript'}',
            banner: '{Lang::T('You are offline. Changes cannot be sent, and the information on screen may not be current.')|escape:'javascript'}',
            blocked: '{Lang::T('Not sent: you are offline. Nothing was sent to the server; try again when the connection is back.')|escape:'javascript'}'
        }
    };
</script>
