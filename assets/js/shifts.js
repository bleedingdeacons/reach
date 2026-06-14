/*
 * Reach — shift sign-up client logic.
 *
 * Lists a day's shifts from Trusted's member-facing REST and lets the signed-in
 * responder sign up for the open ones. Authentication piggy-backs on the Reach
 * session cookie (credentials: same-origin, no X-WP-Nonce — same rationale as
 * find.js); Trusted resolves the member from it via the trusted_signup_member
 * filter and enforces responder-only + one-member-per-shift server-side.
 */
(function () {
    'use strict';

    var cfg = window.REACH_CONFIG || {};
    var base = String(cfg.trustedBase || '').replace(/\/$/, '');

    var prevBtn = document.getElementById('reach-day-prev');
    var nextBtn = document.getElementById('reach-day-next');
    var statusEl = document.getElementById('reach-status');
    var form = document.getElementById('reach-shifts-form');
    var listEl = document.getElementById('reach-shifts-list');
    var submitBtn = document.getElementById('reach-shifts-submit');
    var signOutBtn = document.getElementById('reach-signout');
    var weekdayEl = document.getElementById('reach-day-weekday');

    if (!form || !listEl) { return; }

    // --- Helpers ------------------------------------------------------------

    function isoDate(d) {
        var y = d.getFullYear();
        var m = ('0' + (d.getMonth() + 1)).slice(-2);
        var day = ('0' + d.getDate()).slice(-2);
        return y + '-' + m + '-' + day;
    }

    function shiftDay(iso, delta) {
        var d = new Date(iso + 'T00:00:00');
        d.setDate(d.getDate() + delta);
        return isoDate(d);
    }

    // Show the chosen day as a full, unambiguous GB-formatted date, e.g.
    // "Tuesday 13 January 2026". Intl honours the explicit 'en-GB' locale on
    // every platform, unlike the native date field — Android Chrome takes that
    // field's displayed format from the device's system language and ignores
    // our lang="en-GB" attribute (which only takes effect on desktop Chrome).
    function setWeekday(iso) {
        if (!weekdayEl) { return; }
        if (!iso) { weekdayEl.textContent = ''; return; }
        weekdayEl.textContent = new Date(iso + 'T00:00:00').toLocaleDateString('en-GB', {
            weekday: 'long', day: 'numeric', month: 'long', year: 'numeric'
        });
    }

    function setStatus(message, kind) {
        statusEl.textContent = message || '';
        statusEl.classList.remove('is-error', 'is-success');
        if (kind) { statusEl.classList.add('is-' + kind); }
    }

    function toSignin() { window.location = cfg.signInUrl; }

    // Wrap fetch with the shared auth/credentials and JSON handling. Resolves to
    // { status, body }. A 401 (session gone) bounces straight to sign-in.
    function api(path, options) {
        options = options || {};
        options.credentials = 'same-origin';
        options.headers = Object.assign({ 'Accept': 'application/json' }, options.headers || {});
        if (options.body && typeof options.body !== 'string') {
            options.headers['Content-Type'] = 'application/json';
            options.body = JSON.stringify(options.body);
        }
        return fetch(base + path, options).then(function (r) {
            if (r.status === 401) { toSignin(); return Promise.reject({ handled: true }); }
            return r.json().catch(function () { return {}; }).then(function (body) {
                return { status: r.status, body: body };
            });
        });
    }

    // --- Rendering ----------------------------------------------------------

    function render(shifts) {
        listEl.innerHTML = '';
        var openCount = 0;

        (shifts || []).forEach(function (s) {
            var li = document.createElement('li');
            li.className = 'reach-shift' + (s.is_open ? '' : ' is-taken');

            var time = document.createElement('span');
            time.className = 'reach-shift__time';
            time.textContent = s.start + '–' + s.end;

            var label = document.createElement('span');
            label.className = 'reach-shift__label';
            label.textContent = s.label || '';

            if (s.is_open) {
                openCount++;
                var lbl = document.createElement('label');
                lbl.className = 'reach-shift__pick';
                var cb = document.createElement('input');
                cb.type = 'checkbox';
                cb.className = 'reach-shift__check';
                cb.value = String(s.id);
                lbl.appendChild(cb);
                lbl.appendChild(time);
                lbl.appendChild(label);
                li.appendChild(lbl);
            } else {
                var taken = document.createElement('span');
                taken.className = 'reach-shift__taken';
                taken.textContent = s.is_mine ? 'You' : (s.assignee || 'Taken');
                li.appendChild(time);
                li.appendChild(label);
                li.appendChild(taken);

                if (s.is_mine) {
                    li.classList.add('is-mine');
                    var remove = document.createElement('button');
                    remove.type = 'button';
                    remove.className = 'reach-shift__remove';
                    remove.textContent = 'Remove';
                    remove.setAttribute('data-rota', String(s.id));
                    li.appendChild(remove);
                }
            }

            listEl.appendChild(li);
        });

        submitBtn.hidden = openCount === 0;

        if (!shifts || !shifts.length) {
            setStatus('No shifts on this day.');
        } else if (openCount === 0) {
            setStatus('Every shift this day is already covered.');
        } else {
            setStatus('');
        }
    }

    // --- Data ---------------------------------------------------------------

    // Monotonic load counter. Each loadDay() takes a ticket; when its response
    // arrives it only renders if it's still the latest request. This stops two
    // overlapping loads (rapid prev/next taps, or a refresh racing a nav on a
    // slow mobile connection) from resolving out of order and repainting twice.
    var loadSeq = 0;

    // `quiet` keeps the currently shown rows on screen while the refresh is in
    // flight instead of blanking to an empty "Loading…" state. The post-assign
    // / post-remove refreshes pass it so the list swaps in a single paint when
    // the new data lands — on a slow connection (Android Chrome) the old
    // teardown-then-wait-then-repaint was visible as a flicker. Day changes
    // (initial load, prev/next) stay loud: clearing is the right cue there.
    function loadDay(iso, quiet) {
        // Belt-and-braces: never fetch without a date. An empty iso would hit
        // /signup/shifts/ (no date segment) and surface as a load error; if we
        // somehow get here with nothing, fall back to the last shown day, or
        // today on first run.
        if (!iso) { iso = currentIso || today; }
        // currentIso is the source of truth for the shown day. The chosen day
        // is surfaced to the visitor through the weekday label between the
        // chevrons — there's no editable date field.
        currentIso = iso;
        setWeekday(iso);

        var seq = ++loadSeq;

        if (!quiet) {
            setStatus('Loading…');
            listEl.innerHTML = '';
            submitBtn.hidden = true;
        }

        api('/signup/shifts/' + iso, { method: 'GET' }).then(function (resp) {
            // A newer load (or a day change) started after us — drop this stale
            // response rather than letting it overwrite fresher content.
            if (seq !== loadSeq) { return; }
            if (resp.status === 403) {
                setStatus('You’re not registered as a telephone responder.', 'error');
                return;
            }
            if (resp.status !== 200) {
                setStatus((resp.body && resp.body.message) || 'Could not load shifts.', 'error');
                return;
            }
            render(resp.body);
        }).catch(function (e) {
            if (e && e.handled) { return; }
            if (seq !== loadSeq) { return; }
            setStatus('Could not load shifts.', 'error');
        });
    }

    function submit() {
        var ids = Array.prototype.slice.call(listEl.querySelectorAll('.reach-shift__check:checked'))
            .map(function (cb) { return parseInt(cb.value, 10); });

        if (!ids.length) {
            setStatus('Pick at least one shift.', 'error');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.classList.add('is-loading');

        api('/signup', { method: 'POST', body: { rota_ids: ids } }).then(function (resp) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('is-loading');

            if (resp.status === 403) {
                setStatus('You’re not registered as a telephone responder.', 'error');
                return;
            }
            if (resp.status !== 201) {
                setStatus((resp.body && resp.body.message) || 'Sign-up failed.', 'error');
                return;
            }

            var assigned = (resp.body && resp.body.assigned) || [];
            var skipped = (resp.body && resp.body.skipped) || [];
            var msg = assigned.length === 1
                ? 'Signed up for 1 shift.'
                : 'Signed up for ' + assigned.length + ' shifts.';
            if (skipped.length) {
                msg += ' ' + skipped.length + ' were already taken.';
            }
            setStatus(msg, 'success');
            loadDay(currentIso, true); // quiet refresh so the now-taken shifts update without flicker
        }).catch(function (e) {
            submitBtn.disabled = false;
            submitBtn.classList.remove('is-loading');
            if (e && e.handled) { return; }
            setStatus('Sign-up failed.', 'error');
        });
    }

    function removeSignup(rotaId) {
        setStatus('Removing…');

        api('/signup/' + rotaId, { method: 'DELETE' }).then(function (resp) {
            if (resp.status === 403) {
                setStatus('You’re not registered as a telephone responder.', 'error');
                return;
            }
            if (resp.status !== 200) {
                setStatus((resp.body && resp.body.message) || 'Could not remove your sign-up.', 'error');
                return;
            }
            setStatus('Removed your sign-up.', 'success');
            loadDay(currentIso, true); // quiet refresh so the now-open shift updates without flicker
        }).catch(function (e) {
            if (e && e.handled) { return; }
            setStatus('Could not remove your sign-up.', 'error');
        });
    }

    // --- Wire up ------------------------------------------------------------

    var today = isoDate(new Date());
    var currentIso = today;

    prevBtn.addEventListener('click', function () {
        loadDay(shiftDay(currentIso, -1));
    });
    nextBtn.addEventListener('click', function () {
        loadDay(shiftDay(currentIso, 1));
    });
    form.addEventListener('submit', function (e) { e.preventDefault(); submit(); });

    listEl.addEventListener('click', function (e) {
        var btn = e.target;
        if (!btn.classList || !btn.classList.contains('reach-shift__remove')) { return; }
        var rotaId = parseInt(btn.getAttribute('data-rota'), 10);
        if (rotaId > 0 && window.confirm('Remove your sign-up for this shift?')) {
            removeSignup(rotaId);
        }
    });

    if (signOutBtn) {
        signOutBtn.addEventListener('click', function () {
            fetch(cfg.signOutUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                .then(function () { window.location = cfg.signInUrl; })
                .catch(function () { window.location = cfg.signInUrl; });
        });
    }

    loadDay(today);
})();
