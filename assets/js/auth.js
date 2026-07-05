/**
 * Reach — email + password auth pages.
 *
 * One small script shared by the three password pages; it wires up
 * whichever form is present on the current page (they never coexist):
 *
 *   - #reach-login-form   (signin.php)       → POST cfg.loginUrl
 *   - #reach-reset-form   (reset.php)        → POST cfg.requestResetUrl
 *   - #reach-setpw-form   (set-password.php) → POST cfg.setPasswordUrl
 *
 * No framework, no dependencies — same standalone style as the inline
 * Apple-sign-in script. Config is passed in via window.REACH_AUTH.
 */
(function () {
    'use strict';

    var cfg = window.REACH_AUTH || {};

    function setStatus(el, message, kind) {
        if (!el) return;
        el.textContent = message || '';
        el.classList.remove('is-error', 'is-success');
        if (kind) el.classList.add('is-' + kind);
    }

    function setLoading(btn, loading) {
        if (!btn) return;
        btn.disabled = !!loading;
        btn.classList.toggle('is-loading', !!loading);
    }

    function postJson(url, payload) {
        return fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify(payload)
        }).then(function (r) {
            return r.json()
                .catch(function () { return {}; })
                .then(function (body) { return { status: r.status, body: body }; });
        });
    }

    // --- Sign in --------------------------------------------------------
    var loginForm = document.getElementById('reach-login-form');
    if (loginForm) {
        var loginEmail  = document.getElementById('reach-login-email');
        var loginPw     = document.getElementById('reach-login-password');
        var loginBtn    = document.getElementById('reach-login-submit');
        var loginStatus = document.getElementById('reach-login-status');
        var loginError  = document.getElementById('reach-login-error');
        var loginErrorBody = document.getElementById('reach-login-error-body');

        // Definitive sign-in failures go in the error-tinted notice box;
        // transient hints (empty fields, "Signing in…") stay inline.
        function showLoginError(message) {
            if (loginErrorBody) loginErrorBody.textContent = message;
            if (loginError) loginError.hidden = false;
        }
        function hideLoginError() {
            if (loginError) loginError.hidden = true;
        }

        loginForm.addEventListener('submit', function (event) {
            event.preventDefault();
            hideLoginError();
            var email = (loginEmail ? loginEmail.value : '').trim();
            var pw    = loginPw ? loginPw.value : '';
            if (!email || !pw) {
                setStatus(loginStatus, 'Enter your email and password.', 'error');
                return;
            }
            setLoading(loginBtn, true);
            setStatus(loginStatus, 'Signing in…');
            postJson(cfg.loginUrl, { email: email, password: pw })
                .then(function (resp) {
                    if (resp.status >= 200 && resp.status < 300) {
                        window.location = (resp.body && resp.body.redirect) || cfg.homeUrl;
                        return;
                    }
                    setLoading(loginBtn, false);
                    setStatus(loginStatus, '');
                    var msg = (resp.body && resp.body.message) || 'Email or password is incorrect.';
                    showLoginError(msg);
                })
                .catch(function () {
                    setLoading(loginBtn, false);
                    setStatus(loginStatus, '');
                    showLoginError('Network error. Check your connection and try again.');
                });
        });

        // Carry a typed email over to the reset page so the member doesn't
        // have to retype it. Falls back to plain navigation when the field
        // is empty (or JS is off — the link is a real href).
        var resetLink = document.getElementById('reach-reset-link');
        if (resetLink) {
            resetLink.addEventListener('click', function (event) {
                var email = (loginEmail ? loginEmail.value : '').trim();
                if (!email) return;
                event.preventDefault();
                var base = resetLink.getAttribute('href');
                var sep = base.indexOf('?') === -1 ? '?' : '&';
                window.location = base + sep + 'email=' + encodeURIComponent(email);
            });
        }
    }

    // --- Request a reset link ------------------------------------------
    var resetForm = document.getElementById('reach-reset-form');
    if (resetForm) {
        var resetEmail  = document.getElementById('reach-reset-email');
        var resetBtn    = document.getElementById('reach-reset-submit');
        var resetStatus = document.getElementById('reach-reset-status');

        resetForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var email = (resetEmail ? resetEmail.value : '').trim();
            if (!email) {
                setStatus(resetStatus, 'Enter your email address.', 'error');
                return;
            }
            // Disable to prevent a double-submit, but no spinner on this
            // button — just a brief status line.
            if (resetBtn) resetBtn.disabled = true;
            setStatus(resetStatus, 'Sending…');
            postJson(cfg.requestResetUrl, { email: email })
                .then(function () {
                    // Always the same confirmation, whether or not the
                    // address is registered — no account enumeration. The
                    // message lives in a pre-rendered green notice box; just
                    // swap the form for it.
                    resetForm.hidden = true;
                    setStatus(resetStatus, '');
                    var done = document.getElementById('reach-reset-done');
                    if (done) done.hidden = false;
                })
                .catch(function () {
                    if (resetBtn) resetBtn.disabled = false;
                    setStatus(resetStatus, 'Network error. Check your connection and try again.', 'error');
                });
        });
    }

    // --- Set a new password from the emailed link ----------------------
    var setpwForm = document.getElementById('reach-setpw-form');
    if (setpwForm) {
        var setpwPw      = document.getElementById('reach-setpw-password');
        var setpwConfirm = document.getElementById('reach-setpw-confirm');
        var setpwBtn     = document.getElementById('reach-setpw-submit');
        var setpwStatus  = document.getElementById('reach-setpw-status');
        var minLength    = cfg.minLength || 14;

        setpwForm.addEventListener('submit', function (event) {
            event.preventDefault();
            var pw      = setpwPw ? setpwPw.value : '';
            var confirm = setpwConfirm ? setpwConfirm.value : '';
            if (pw.length < minLength) {
                setStatus(setpwStatus, 'Use at least ' + minLength + ' characters.', 'error');
                return;
            }
            if (pw !== confirm) {
                setStatus(setpwStatus, 'The two passwords don’t match.', 'error');
                return;
            }
            setLoading(setpwBtn, true);
            setStatus(setpwStatus, 'Saving…');
            postJson(cfg.setPasswordUrl, { token: cfg.token, password: pw })
                .then(function (resp) {
                    if (resp.status >= 200 && resp.status < 300) {
                        setStatus(setpwStatus, 'Password saved.', 'success');
                        setTimeout(function () {
                            window.location = (resp.body && resp.body.redirect) || cfg.signInUrl;
                        }, 800);
                        return;
                    }
                    setLoading(setpwBtn, false);
                    var msg = (resp.body && resp.body.message)
                        || 'This link is invalid or has expired. Please request a new one.';
                    setStatus(setpwStatus, msg, 'error');
                })
                .catch(function () {
                    setLoading(setpwBtn, false);
                    setStatus(setpwStatus, 'Network error. Check your connection and try again.', 'error');
                });
        });
    }
})();
