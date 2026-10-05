{* WP2 / OD-8: CSRF shim. Adds the session token to every POST form (also forms created or submitted
   by script), to same-origin XHR/fetch requests (X-CSRF-Token header) and turns
   <a data-method="post"> links into POST submissions.
   WP7 (owner decision OD-W7-2): each script-driven send path consults window.nuxrMutationGate when the staff PWA
   client defines it. The gate can only STOP a request (offline / reconnecting): token injection is unchanged, nothing
   is generated, altered, queued or replayed. Where the gate is absent (customer portal) behaviour is unchanged. *}
<script>
    window.nuxrCsrfToken = "{$csrf_token|escape:'javascript'}";
</script>
{literal}
<script>
    (function () {
        var token = window.nuxrCsrfToken;
        if (!token) { return; }
        function sameOrigin(u) {
            try { return new URL(u, window.location.href).origin === window.location.origin; } catch (e) { return false; }
        }
        function addToken(form) {
            if (!form || String(form.getAttribute('method') || form.method || '').toLowerCase() !== 'post') { return; }
            var existing = form.querySelector('input[name="csrf_token"]');
            if (existing) { if (!existing.value) { existing.value = token; } return; }
            var input = document.createElement('input');
            input.type = 'hidden'; input.name = 'csrf_token'; input.value = token;
            form.appendChild(input);
        }
        function allowed(kind, method, url) {
            return typeof window.nuxrMutationGate !== 'function' || window.nuxrMutationGate(kind, method, url) !== false;
        }
        function addAll() { for (var i = 0; i < document.forms.length; i++) { addToken(document.forms[i]); } }
        document.addEventListener('submit', function (e) { addToken(e.target); }, true);
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', addAll); } else { addAll(); }
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () {
            addToken(this);
            if (!allowed('form.submit', this.getAttribute('method') || this.method, this.action)) { return; }
            return nativeSubmit.apply(this, arguments);
        };
        var xOpen = XMLHttpRequest.prototype.open, xSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (m, u) { this._nuxrSame = sameOrigin(u); this._nuxrMethod = m; this._nuxrUrl = u; return xOpen.apply(this, arguments); };
        XMLHttpRequest.prototype.send = function () {
            if (this._nuxrSame) { try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) { } }
            if (!allowed('xhr', this._nuxrMethod, this._nuxrUrl)) {
                var x = this; // not sent: report a network error to the caller (jQuery error callbacks run)
                setTimeout(function () { x.dispatchEvent(new ProgressEvent('error')); x.dispatchEvent(new ProgressEvent('loadend')); }, 0);
                return;
            }
            return xSend.apply(this, arguments);
        };
        if (window.fetch) {
            var nativeFetch = window.fetch;
            window.fetch = function (input, init) {
                var url = (typeof input === 'string') ? input : (input && input.url);
                if (sameOrigin(url)) {
                    init = init || {};
                    var h = new Headers(init.headers || (typeof input !== 'string' && input.headers) || {});
                    h.set('X-CSRF-Token', token);
                    init.headers = h;
                }
                var method = (init && init.method) || (typeof input !== 'string' && input && input.method) || 'GET';
                if (!allowed('fetch', method, url)) { return Promise.reject(new TypeError('Not sent: offline')); }
                return nativeFetch.call(this, input, init);
            };
        }
        // POST navigation for script-driven mutations (replaces window.location.href = url)
        window.nuxrPost = function (url) {
            var form = document.createElement('form');
            form.method = 'post';
            form.action = url;
            form.style.display = 'none';
            addToken(form);
            if (!allowed('nuxrPost', 'POST', url)) { return; }
            document.body.appendChild(form);
            nativeSubmit.call(form);
        };
        document.addEventListener('click', function (e) {
            var a = e.target && e.target.closest ? e.target.closest('a[data-method="post"]') : null;
            if (!a || e.defaultPrevented) { return; }
            e.preventDefault();
            var form = document.createElement('form');
            form.method = 'post';
            form.action = a.href;
            if (a.target) { form.target = a.target; }
            form.style.display = 'none';
            addToken(form);
            if (!allowed('data-method', 'POST', a.href)) { return; }
            document.body.appendChild(form);
            nativeSubmit.call(form);
        });
    })();
</script>
{/literal}
