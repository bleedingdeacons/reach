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

    var dayInput = document.getElementById('reach-day');
    var prevBtn = document.getElementById('reach-day-prev');
    var nextBtn = document.getElementById('reach-day-next');
    var statusEl = document.getElementById('reach-status');
    var form = document.getElementById('reach-shifts-form');
    var listEl = document.getElementById('reach-shifts-list');
    var submitBtn = document.getElementById('reach-shifts-submit');
    var signOutBtn = document.getElementById('reach-signout');
    var weekdayEl = document.getElementById('reach-day-weekday');

    if (!form || !listEl || !dayInput) { return; }

    var WEEKDAYS = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];

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

    function setWeekday(iso) {
        if (!weekdayEl) { return; }
        weekdayEl.textContent = iso ? WEEKDAYS[new Date(iso + 'T00:00:00').getDay()] : '';
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

    function loadDay(iso) {
        setWeekday(iso);
        setStatus('Loading…');
        listEl.innerHTML = '';
        submitBtn.hidden = true;

        api('/signup/shifts/' + iso, { method: 'GET' }).then(function (resp) {
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
            loadDay(dayInput.value); // refresh so the now-taken shifts update
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
            loadDay(dayInput.value); // refresh so the now-open shift updates
        }).catch(function (e) {
            if (e && e.handled) { return; }
            setStatus('Could not remove your sign-up.', 'error');
        });
    }

    // --- Wire up ------------------------------------------------------------

    var today = isoDate(new Date());
    dayInput.value = today;

    dayInput.addEventListener('change', function () {
        if (dayInput.value) { loadDay(dayInput.value); }
    });
    prevBtn.addEventListener('click', function () {
        dayInput.value = shiftDay(dayInput.value || today, -1);
        loadDay(dayInput.value);
    });
    nextBtn.addEventListener('click', function () {
        dayInput.value = shiftDay(dayInput.value || today, 1);
        loadDay(dayInput.value);
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
