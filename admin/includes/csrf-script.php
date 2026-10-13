<?php
/* Shared by admin/includes/header.php and the full-screen builder. */
?>
    <script>
    /* Attach the CSRF token (checked by admin_enforce_csrf()) to every
       state-changing request the admin makes, so individual pages need no
       changes: POST forms get a hidden field, ?action=delete links get a
       query param, same-origin fetch/XHR get an X-CSRF-Token header. */
    (function () {
        var TOKEN = <?= json_encode(csrf_token()) ?>;
        function sameOrigin(url) {
            try { return new URL(url, location.href).origin === location.origin; } catch (e) { return false; }
        }
        function arm(form) {
            // getAttribute(): many admin forms have an <input name="action">,
            // which shadows the form.action / form.method properties.
            if (!form || !form.getAttribute) return;
            if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
            if (!sameOrigin(form.getAttribute('action') || location.href)) return;
            var f = form.querySelector('input[name="csrf"]');
            if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = 'csrf'; form.appendChild(f); }
            f.value = TOKEN;
        }
        document.addEventListener('submit', function (e) { arm(e.target); }, true);
        var nativeSubmit = HTMLFormElement.prototype.submit;
        HTMLFormElement.prototype.submit = function () { arm(this); return nativeSubmit.call(this); };
        document.addEventListener('DOMContentLoaded', function () {
            Array.prototype.forEach.call(document.forms, arm);
        });
        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('a[href*="action=delete"]');
            if (!a || !sameOrigin(a.href)) return;
            var u = new URL(a.href, location.href);
            u.searchParams.set('csrf', TOKEN);
            a.href = u.toString();
        }, true);
        var nativeFetch = window.fetch;
        if (nativeFetch) {
            window.fetch = function (input, init) {
                var url = (input && input.url) || input;
                if (sameOrigin(url)) {
                    init = init || {};
                    var headers = new Headers(init.headers || (input && input.headers) || {});
                    headers.set('X-CSRF-Token', TOKEN);
                    init.headers = headers;
                }
                return nativeFetch.call(this, input, init);
            };
        }
        var nativeOpen = XMLHttpRequest.prototype.open, nativeSend = XMLHttpRequest.prototype.send;
        XMLHttpRequest.prototype.open = function (m, url) { this._lumeSameOrigin = sameOrigin(url); return nativeOpen.apply(this, arguments); };
        XMLHttpRequest.prototype.send = function () {
            if (this._lumeSameOrigin) { try { this.setRequestHeader('X-CSRF-Token', TOKEN); } catch (e) {} }
            return nativeSend.apply(this, arguments);
        };
    })();
    </script>
