/*
 * WP6A adaptive shell: progressive enhancement only (no framework, no build). Every page works without it; it adds
 * sheets / dialogs, the subscriber search typeahead, pending + double-submit protection, typed confirmations,
 * the phone stepper and in-flight status refresh. It never holds authority: no price, permission or state here.
 */
(function () {
    'use strict';
    var T = window.nuxrShell || {};
    var live = document.getElementById('nuxr-live');

    function announce(text) {
        if (live) { live.textContent = ''; setTimeout(function () { live.textContent = text; }, 50); }
    }

    function q(sel, root) { return (root || document).querySelector(sel); }
    function qa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /* ---------- sheets / dialogs ---------- */
    function openDialog(d, trigger) {
        if (!d) { return; }
        d._nuxrTrigger = trigger || document.activeElement;
        if (typeof d.showModal === 'function') { if (!d.open) { d.showModal(); } } else { d.setAttribute('open', ''); }
        var first = q('[autofocus], input:not([type=hidden]):not([disabled]), button:not([data-nuxr-close]):not([disabled]), a[href]', d);
        if (first) { first.focus(); }
    }

    function closeDialog(d) {
        if (!d) { return; }
        if (typeof d.close === 'function') { d.close(); } else { d.removeAttribute('open'); }
    }

    document.addEventListener('click', function (e) {
        var opener = e.target.closest ? e.target.closest('[data-nuxr-open]') : null;
        if (opener) {
            e.preventDefault();
            openDialog(document.getElementById(opener.getAttribute('data-nuxr-open')), opener);
            return;
        }
        var closer = e.target.closest ? e.target.closest('[data-nuxr-close]') : null;
        if (closer) {
            e.preventDefault();
            closeDialog(closer.closest('dialog'));
            return;
        }
        // a click on the backdrop (outside the dialog box) closes a sheet
        if (e.target.tagName === 'DIALOG' && e.target.open) {
            var r = e.target.getBoundingClientRect();
            if (e.clientX < r.left || e.clientX > r.right || e.clientY < r.top || e.clientY > r.bottom) { closeDialog(e.target); }
        }
    });

    document.addEventListener('close', function (e) {
        var d = e.target;
        if (d && d._nuxrTrigger && d._nuxrTrigger.focus) { d._nuxrTrigger.focus(); }
    }, true);

    /* close <details> menus on outside click / Escape */
    document.addEventListener('click', function (e) {
        qa('details.nuxr-menu[open]').forEach(function (m) { if (!m.contains(e.target)) { m.removeAttribute('open'); } });
    });

    /* ---------- theme / language ---------- */
    try {
        if (localStorage.getItem('mode') === 'dark') { document.body.classList.add('dark-mode'); }
    } catch (err) { /* storage unavailable: light theme */ }

    document.addEventListener('click', function (e) {
        var t = e.target.closest ? e.target.closest('[data-nuxr-theme]') : null;
        if (t) {
            var dark = document.body.classList.toggle('dark-mode');
            try { localStorage.setItem('mode', dark ? 'dark' : 'light'); } catch (err) { /* ignore */ }
        }
        var l = e.target.closest ? e.target.closest('[data-nuxr-lang]') : null;
        if (l) {
            var lang = l.getAttribute('data-nuxr-lang');
            if (/^[a-z]+$/.test(lang)) {
                document.cookie = 'user_language=' + lang + '; path=/; max-age=31536000; SameSite=Lax';
                window.location.reload();
            }
        }
    });

    /* ---------- subscriber search typeahead (server-side, scoped, bounded) ---------- */
    function setupSearch(form) {
        var input = q('.nuxr-search-input', form);
        var box = q('.nuxr-search-results', form);
        var url = form.getAttribute('data-nuxr-search');
        if (!input || !box || !url) { return; }
        var timer = null, ctrl = null, active = -1;

        function options() { return qa('[role="option"]', box); }
        function hide() { box.hidden = true; input.setAttribute('aria-expanded', 'false'); active = -1; }
        function show() { box.hidden = false; input.setAttribute('aria-expanded', 'true'); }
        // a listbox only while it holds options; a message (too short, searching, none found, failed) is a status
        function fill(html) { box.innerHTML = html; box.setAttribute('role', options().length ? 'listbox' : 'status'); }
        function mark(i) {
            var o = options();
            o.forEach(function (el, k) { el.setAttribute('aria-selected', k === i ? 'true' : 'false'); });
            active = i;
            if (o[i]) { input.setAttribute('aria-activedescendant', o[i].id); o[i].scrollIntoView({ block: 'nearest' }); }
        }

        input.addEventListener('input', function () {
            var term = input.value.trim();
            clearTimeout(timer);
            if (ctrl) { ctrl.abort(); }
            if (term.length < 2) {
                if (term.length === 0) { hide(); } else { fill('<p class="nuxr-search-msg">' + (T.minChars || '') + '</p>'); show(); }
                return;
            }
            timer = setTimeout(function () {
                ctrl = window.AbortController ? new AbortController() : null;
                fill('<p class="nuxr-search-msg" aria-busy="true">' + (T.searching || '') + '</p>');
                show();
                fetch(url + (url.indexOf('?') >= 0 ? '&' : '?') + 'query=' + encodeURIComponent(term), {
                    credentials: 'same-origin', headers: { 'X-Requested-With': 'nuxr-search' }, signal: ctrl ? ctrl.signal : undefined
                }).then(function (r) {
                    if (!r.ok) { throw new Error('search ' + r.status); }
                    return r.text();
                }).then(function (html) {
                    fill(html);
                    options().forEach(function (o, k) { o.id = form.id + '-opt-' + k + '-' + (input.id || 's'); o.setAttribute('aria-selected', 'false'); });
                    active = -1;
                    var n = options().length;
                    announce(n ? String(n) : (q('.nuxr-search-msg', box) || {}).textContent || '');
                }).catch(function (err) {
                    if (err && err.name === 'AbortError') { return; }
                    fill('<p class="nuxr-search-msg">' + (T.failed || '') + '</p>');
                });
            }, 250);
        });

        input.addEventListener('keydown', function (e) {
            var o = options();
            if (e.key === 'ArrowDown' && o.length) { e.preventDefault(); show(); mark(Math.min(active + 1, o.length - 1)); }
            else if (e.key === 'ArrowUp' && o.length) { e.preventDefault(); mark(Math.max(active - 1, 0)); }
            else if (e.key === 'Enter' && active >= 0 && o[active]) { e.preventDefault(); window.location.href = o[active].href; }
            else if (e.key === 'Escape') { hide(); }
        });

        document.addEventListener('click', function (e) { if (!form.contains(e.target)) { hide(); } });
    }

    qa('form[data-nuxr-search]').forEach(function (f, k) { if (!f.id) { f.id = 'nuxr-search-form-' + k; } setupSearch(f); });

    // "/" or Ctrl/Cmd+K focuses the desktop search field
    document.addEventListener('keydown', function (e) {
        var tag = (e.target.tagName || '').toLowerCase();
        var typing = tag === 'input' || tag === 'textarea' || tag === 'select' || e.target.isContentEditable;
        if ((e.key === '/' && !typing) || ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K'))) {
            var field = document.getElementById('nuxr-search-input');
            if (field && field.offsetParent !== null) { e.preventDefault(); field.focus(); }
            else if (document.getElementById('nuxr-search-sheet')) { e.preventDefault(); openDialog(document.getElementById('nuxr-search-sheet')); }
        }
    });

    /* ---------- WP6C: explicit reveal of a masked value (touch / keyboard; re-masked when focus leaves) ---------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-nuxr-reveal]') : null;
        if (!btn) { return; }
        var value = btn.parentNode.querySelector('.nuxr-secret-value');
        var on = btn.getAttribute('aria-pressed') !== 'true';
        btn.setAttribute('aria-pressed', on ? 'true' : 'false');
        if (value) { value.classList.toggle('is-revealed', on); }
    });
    document.addEventListener('focusout', function (e) {
        var btn = e.target.closest ? e.target.closest('[data-nuxr-reveal]') : null;
        if (!btn || btn.getAttribute('aria-pressed') !== 'true') { return; }
        btn.setAttribute('aria-pressed', 'false');
        var value = btn.parentNode.querySelector('.nuxr-secret-value');
        if (value) { value.classList.remove('is-revealed'); }
    });

    /* ---------- pending state + double-submit protection (until the response; no timer re-enable) ---------- */
    document.addEventListener('submit', function (e) {
        if (e.defaultPrevented) { return; }
        var form = e.target;
        if (!form || form.hasAttribute('data-nuxr-nopending') || (form.method || '').toLowerCase() !== 'post') { return; }
        if (form.getAttribute('data-nuxr-submitted') === '1') { e.preventDefault(); return; }
        form.setAttribute('data-nuxr-submitted', '1');
        var btn = e.submitter || q('button[type=submit], input[type=submit]', form);
        // after the browser built the form data (a disabled submitter would lose its name/value)
        setTimeout(function () {
            form.setAttribute('aria-busy', 'true');
            if (btn) { btn.disabled = true; btn.classList.add('is-pending'); }
            if (form.target && form.target !== '_self') {
                setTimeout(function () { resetForm(form, btn); }, 2500);
            }
        }, 0);
    });

    function resetForm(form, btn) {
        form.removeAttribute('data-nuxr-submitted');
        form.removeAttribute('aria-busy');
        if (btn) { btn.disabled = false; btn.classList.remove('is-pending'); }
    }

    window.addEventListener('pageshow', function () {
        qa('form[data-nuxr-submitted]').forEach(function (f) {
            resetForm(f, q('.is-pending', f));
            qa('.is-pending', f).forEach(function (b) { b.disabled = false; b.classList.remove('is-pending'); });
        });
    });

    /* ---------- typed confirmation for dangerous actions ---------- */
    function syncMatch(input) {
        var form = input.form;
        var ok = input.value.trim() === input.getAttribute('data-nuxr-match');
        qa('[data-nuxr-match-submit]', form).forEach(function (b) { b.disabled = !ok; });
    }

    qa('input[data-nuxr-match]').forEach(function (input) {
        syncMatch(input);
        input.addEventListener('input', function () { syncMatch(input); });
    });

    /* ---------- phone stepper over one form (all sections visible on tablet / desktop) ---------- */
    qa('form[data-nuxr-steps]').forEach(function (form) {
        var steps = qa('[data-nuxr-step]', form);
        var nav = qa('.nuxr-stepper > li', form.parentNode);
        var mq = window.matchMedia('(max-width: 767px)');
        var current = 0;

        function render() {
            var phone = mq.matches;
            form.classList.toggle('is-stepping', phone);
            steps.forEach(function (s, i) { s.hidden = phone && i !== current; });
            nav.forEach(function (li, i) {
                li.classList.toggle('is-current', i === current);
                li.classList.toggle('is-done', i < current);
                if (i === current) { li.setAttribute('aria-current', 'step'); } else { li.removeAttribute('aria-current'); }
            });
            qa('[data-nuxr-step-prev]', form).forEach(function (b) { b.hidden = !phone || current === 0; });
            qa('[data-nuxr-step-next]', form).forEach(function (b) { b.hidden = !phone || current === steps.length - 1; });
            qa('[data-nuxr-step-submit]', form).forEach(function (b) { b.hidden = phone && current !== steps.length - 1; });
            if (phone) { fillReview(); }
        }

        function valid(step) {
            var fields = qa('input, select, textarea', step).filter(function (f) { return !f.disabled && f.type !== 'hidden'; });
            for (var i = 0; i < fields.length; i++) {
                if (!fields[i].checkValidity()) { fields[i].reportValidity(); return false; }
            }
            return true;
        }

        function fillReview() {
            qa('[data-nuxr-review]', form).forEach(function (out) {
                var f = form.elements[out.getAttribute('data-nuxr-review')];
                if (!f) { return; }
                var v = f.tagName === 'SELECT' ? (f.options[f.selectedIndex] || {}).text : f.value;
                out.textContent = v && String(v).trim() !== '' ? v : '-';
            });
        }

        form.addEventListener('click', function (e) {
            if (e.target.closest('[data-nuxr-step-next]')) {
                e.preventDefault();
                if (valid(steps[current])) { current = Math.min(current + 1, steps.length - 1); render(); steps[current].scrollIntoView({ block: 'start' }); focusStep(); }
            } else if (e.target.closest('[data-nuxr-step-prev]')) {
                e.preventDefault();
                current = Math.max(current - 1, 0); render(); focusStep();
            }
        });

        function focusStep() {
            var h = q('h2, h3, legend', steps[current]);
            if (h) { h.setAttribute('tabindex', '-1'); h.focus(); }
        }

        form.addEventListener('input', fillReview);
        form.addEventListener('change', fillReview);
        if (mq.addEventListener) { mq.addEventListener('change', render); }
        render();
    });

    /* ---------- in-flight activation status: refresh the status region (GET only; never drives the saga) ---------- */
    qa('[data-nuxr-poll]').forEach(function (region) {
        var started = Date.now();
        var id = region.id;
        function tick() {
            if (document.hidden) { return setTimeout(tick, 3000); }
            if (Date.now() - started > 120000) { return; }
            fetch(window.location.href, { credentials: 'same-origin', headers: { 'X-Requested-With': 'nuxr-poll' } })
                .then(function (r) { return r.ok ? r.text() : null; })
                .then(function (html) {
                    if (!html) { return; }
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.getElementById(id);
                    if (!fresh) { return; }
                    region.innerHTML = fresh.innerHTML;
                    if (fresh.hasAttribute('data-nuxr-poll')) { setTimeout(tick, 3000); }
                    else { region.removeAttribute('data-nuxr-poll'); announce((q('.nuxr-badge', region) || {}).textContent || ''); }
                })
                .catch(function () { setTimeout(tick, 6000); });
        }
        setTimeout(tick, 3000);
    });

    /* ---------- lazy map: the map library and tiles load only when the operator opens the map ---------- */
    qa('[data-nuxr-map]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var holder = document.getElementById(btn.getAttribute('data-nuxr-map'));
            if (!holder || holder.getAttribute('data-loaded') === '1') { return; }
            holder.setAttribute('data-loaded', '1');
            holder.hidden = false;
            var css = document.createElement('link');
            css.rel = 'stylesheet';
            css.href = 'https://unpkg.com/leaflet@1.9.3/dist/leaflet.css';
            document.head.appendChild(css);
            var js = document.createElement('script');
            js.src = 'https://unpkg.com/leaflet@1.9.3/dist/leaflet.js';
            var init = btn.getAttribute('data-nuxr-map-init') || holder.getAttribute('data-init');
            js.onload = function () { if (init && typeof window[init] === 'function') { window[init](holder); } };
            document.body.appendChild(js);
            btn.hidden = true;
        });
    });
})();
