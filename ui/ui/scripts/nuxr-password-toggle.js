/*
 * Show / hide button for every password field (staff shell, sign-in pages, customer portal).
 * Presentation only: the field keeps its name, value and form; nothing is stored or sent. The icon is drawn by CSS
 * (nuxr-brand.css, .nuxr-pw-btn), so no markup is injected from strings.
 */
(function () {
    'use strict';
    function arabic() { return (document.documentElement.lang || '').indexOf('ar') === 0; }
    function label(shown) {
        if (arabic()) { return shown ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور'; }
        return shown ? 'Hide password' : 'Show password';
    }
    function add(input) {
        if (input.getAttribute('data-nuxr-pw') || !input.parentNode) { return; }
        input.setAttribute('data-nuxr-pw', '1');
        // a Bootstrap input group lays its children out as table cells: the button joins the group in its own
        // .input-group-btn cell instead
        var inGroup = input.parentNode.classList && input.parentNode.classList.contains('input-group');
        var holder = input.parentNode;
        if (!inGroup) {
            holder = document.createElement('span');
            holder.className = 'nuxr-pw';
            // the button sits at the end of the text direction of the field (an LTR password in an RTL page: right)
            holder.setAttribute('dir', window.getComputedStyle(input).direction === 'ltr' ? 'ltr' : 'rtl');
            input.parentNode.insertBefore(holder, input);
            holder.appendChild(input);
        }
        var button = document.createElement('button');
        button.type = 'button';
        button.className = inGroup ? 'nuxr-pw-btn nuxr-pw-btn-group' : 'nuxr-pw-btn';
        button.setAttribute('aria-label', label(false));
        button.setAttribute('title', label(false));
        button.addEventListener('click', function () {
            var show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.classList.toggle('is-shown', show);
            button.setAttribute('aria-label', label(show));
            button.setAttribute('title', label(show));
            input.focus();
        });
        // a shown password is hidden again before the form is sent (no clear text in the browser's form history)
        if (input.form) { input.form.addEventListener('submit', function () { input.type = 'password'; }); }
        if (inGroup) {
            var cell = document.createElement('span');
            cell.className = 'input-group-btn';
            cell.appendChild(button);
            input.parentNode.insertBefore(cell, input.nextSibling);
        } else { holder.appendChild(button); }
    }
    function scan() {
        var list = document.querySelectorAll('input[type="password"]');
        for (var i = 0; i < list.length; i++) { add(list[i]); }
    }
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', scan); } else { scan(); }
})();
