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
            blocked: '{Lang::T('Not sent: you are offline. Nothing was sent to the server; try again when the connection is back.')|escape:'javascript'}',
            installTitle: '{Lang::T('Install the app')|escape:'javascript'}',
            installText: '{Lang::T('Open your browser menu and choose Install app or Add to Home screen.')|escape:'javascript'}',
            installNote: '{Lang::T('The installed app opens the same pages: sign-in, permissions and every operation stay exactly the same, and the browser keeps working as before.')|escape:'javascript'}',
            close: '{Lang::T('Close')|escape:'javascript'}'
        }
    };
</script>
