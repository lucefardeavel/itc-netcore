{* WP2 / OD-8: CSRF shim. Adds the session token to every POST form (also forms created or submitted
   by script), to same-origin XHR/fetch requests (X-CSRF-Token header) and turns
   <a data-method="post"> links into POST submissions. *}
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
        function addAll() { for (var i = 0; i < document.forms.length; i++) { addToken(document.forms[i]); } }
        document.addEventListener('submit', function (e) { addToken(e.target); }, true);
        if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', addAll); } else { addAll(); }
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () { addToken(this); return nativeSubmit.apply(this, arguments); };
        var xOpen = XMLHttpRequest.prototype.open, xSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (m, u) { this._nuxrSame = sameOrigin(u); return xOpen.apply(this, arguments); };
        XMLHttpRequest.prototype.send = function () {
            if (this._nuxrSame) { try { this.setRequestHeader('X-CSRF-Token', token); } catch (e) { } }
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
            document.body.appendChild(form);
            nativeSubmit.call(form);
        });
    })();
</script>
{/literal}
