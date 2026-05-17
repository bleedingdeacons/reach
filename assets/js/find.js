/*
 * Reach — find page client logic.
 *
 * Hooks the form to /reach/v1/nearest-members, renders results,
 * handles the sign-out button. No framework, no dependencies.
 *
 * Authentication piggy-backs on the HttpOnly session cookie that
 * was set during sign-in — we just have to send credentials with
 * the fetch. The WP REST nonce is sent in case the capability
 * gate is also enabled (cookie auth path in WordPress requires
 * the nonce; pure Reach-cookie auth doesn't, and sending it is
 * harmless when unused).
 */
(function () {
    'use strict';

    var cfg = window.REACH_CONFIG || {};
    var form = document.getElementById('reach-form');
    var submitBtn = document.getElementById('reach-submit');
    var location = document.getElementById('reach-location');
    var statusEl = document.getElementById('reach-status');
    var resultsEl = document.getElementById('reach-results');
    var signOutBtn = document.getElementById('reach-signout');

    if (!form) return;

    function setStatus(message, kind) {
        statusEl.textContent = message || '';
        statusEl.classList.remove('is-error', 'is-success');
        if (kind) statusEl.classList.add('is-' + kind);
    }

    function setLoading(loading) {
        submitBtn.disabled = !!loading;
        submitBtn.classList.toggle('is-loading', !!loading);
    }

    // Coarse, human-facing labels for the responsiveness badges. Wording
    // is deliberately gentle — "no recent reply" not "unresponsive" —
    // because the underlying signal is noisy (battery, sleep, holiday).
    var BADGE_LABELS = {
        'reached_recently':    { text: 'Reached recently',  kind: 'good' },
        'quiet':               { text: 'No recent reply',   kind: 'soft' },
        'bad_number_reported': { text: 'Number may be out of date', kind: 'warn' }
    };

    var OUTCOME_BUTTONS = [
        { value: 'reached',             label: 'Spoke' },
        { value: 'no_answer',           label: 'No answer' },
        { value: 'wrong_or_bad_number', label: 'Wrong / bad number' }
    ];

    function logAttempt(member, outcome, btn, group) {
        // Disable the whole group while in flight so the user can't
        // double-tap or race two outcomes against each other.
        var buttons = group.querySelectorAll('button');
        for (var i = 0; i < buttons.length; i++) buttons[i].disabled = true;
        btn.classList.add('is-loading');

        var body = JSON.stringify({
            member_id:     member.id,
            outcome:       outcome,
            attempt_token: member.attempt_token
        });

        fetch(cfg.attemptsUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-WP-Nonce': cfg.nonce || ''
            },
            body: body
        })
            .then(function (r) {
                return r.json().then(function (body) { return { status: r.status, body: body }; });
            })
            .then(function (resp) {
                btn.classList.remove('is-loading');
                if (resp.status >= 200 && resp.status < 300) {
                    // Replace the buttons with a confirmation. Tracking
                    // multiple outcomes per call (e.g. "no answer, then
                    // reached") is intentionally not supported here —
                    // run the search again to update.
                    group.innerHTML = '';
                    var ack = document.createElement('div');
                    ack.className = 'reach-result__logged';
                    ack.textContent = 'Logged: ' + (
                        OUTCOME_BUTTONS.find(function (o) { return o.value === outcome; }) || {}
                    ).label;
                    group.appendChild(ack);
                } else {
                    for (var i = 0; i < buttons.length; i++) buttons[i].disabled = false;
                    var msg = (resp.body && resp.body.message) || 'Could not record that. Try again.';
                    var err = document.createElement('div');
                    err.className = 'reach-result__logged is-error';
                    err.textContent = msg;
                    group.appendChild(err);
                    setTimeout(function () { if (err.parentNode) err.parentNode.removeChild(err); }, 4000);
                }
            })
            .catch(function () {
                btn.classList.remove('is-loading');
                for (var i = 0; i < buttons.length; i++) buttons[i].disabled = false;
            });
    }

    function renderResults(payload) {
        resultsEl.innerHTML = '';
        if (!payload.members || payload.members.length === 0) {
            setStatus('No members matched. Try widening your search.', null);
            return;
        }
        setStatus(payload.count + ' nearest member' + (payload.count === 1 ? '' : 's'), 'success');

        payload.members.forEach(function (m) {
            var li = document.createElement('li');
            li.className = 'reach-result';

            var top = document.createElement('div');
            top.className = 'reach-result__top';
            var name = document.createElement('div');
            name.className = 'reach-result__name';
            name.textContent = m.anonymous_name + ' \u00b7 ' + (m.area || '');
            var dist = document.createElement('div');
            dist.className = 'reach-result__dist';
            dist.textContent = m.distance_km + ' km away';
            top.appendChild(name);
            top.appendChild(dist);
            li.appendChild(top);

            if (m.responsiveness && BADGE_LABELS[m.responsiveness]) {
                var badgeMeta = BADGE_LABELS[m.responsiveness];
                var badge = document.createElement('div');
                badge.className = 'reach-result__badge reach-result__badge--' + badgeMeta.kind;
                badge.textContent = badgeMeta.text;
                li.appendChild(badge);
            }

            var contact = document.createElement('div');
            contact.className = 'reach-result__contact';
            if (m.mobile_number) {
                var tel = document.createElement('a');
                tel.href = 'tel:' + m.mobile_number.replace(/\s+/g, '');
                tel.textContent = 'Call ' + m.mobile_number;
                contact.appendChild(tel);
                var sms = document.createElement('a');
                sms.href = 'sms:' + m.mobile_number.replace(/\s+/g, '');
                sms.textContent = 'Text ' + m.mobile_number;
                contact.appendChild(sms);
            }
            li.appendChild(contact);

            if (m.accepts && m.accepts.length) {
                var ac = document.createElement('div');
                ac.className = 'reach-result__accepts';
                ac.textContent = 'Accepts calls from: ' + m.accepts.join(', ');
                li.appendChild(ac);
            }

            // Outcome buttons — only render if the server gave us a
            // valid attempt token. Without a token (e.g. somehow no
            // session), the buttons would 403 anyway.
            if (m.attempt_token) {
                var actions = document.createElement('div');
                actions.className = 'reach-result__actions';
                var label = document.createElement('div');
                label.className = 'reach-result__actions-label';
                label.textContent = 'How did it go?';
                actions.appendChild(label);

                var group = document.createElement('div');
                group.className = 'reach-result__action-buttons';
                OUTCOME_BUTTONS.forEach(function (o) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'reach-btn reach-btn--outcome reach-btn--outcome-' + o.value.replace(/_/g, '-');
                    b.textContent = o.label;
                    b.addEventListener('click', function () {
                        logAttempt(m, o.value, b, group);
                    });
                    group.appendChild(b);
                });
                actions.appendChild(group);
                li.appendChild(actions);
            }

            resultsEl.appendChild(li);
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var loc = (location.value || '').trim();
        if (!loc) {
            setStatus('Enter a postcode or area.', 'error');
            location.focus();
            return;
        }

        var accepts = Array.prototype.map.call(
            form.querySelectorAll('input[name="accepts"]:checked'),
            function (el) { return el.value; }
        );

        var url = new URL(cfg.restUrl, window.location.origin);
        url.searchParams.set('location', loc);
        accepts.forEach(function (a) { url.searchParams.append('accepts[]', a); });

        setLoading(true);
        setStatus('Searching\u2026');
        resultsEl.innerHTML = '';

        fetch(url.toString(), {
            method: 'GET',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'X-WP-Nonce': cfg.nonce || '' }
        })
            .then(function (r) {
                if (r.status === 401) {
                    window.location = cfg.signInUrl;
                    return Promise.reject(new Error('signed-out'));
                }
                return r.json().then(function (body) { return { status: r.status, body: body }; });
            })
            .then(function (resp) {
                if (resp.status >= 200 && resp.status < 300) {
                    renderResults(resp.body);
                } else {
                    setStatus((resp.body && resp.body.message) || 'Search failed. Try again.', 'error');
                }
            })
            .catch(function (err) {
                if (err && err.message === 'signed-out') return;
                console.error(err);
                setStatus('Network error. Check your connection and try again.', 'error');
            })
            .then(function () {
                setLoading(false);
            });
    });

    if (signOutBtn) {
        signOutBtn.addEventListener('click', function () {
            signOutBtn.disabled = true;
            fetch(cfg.signOutUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json', 'X-WP-Nonce': cfg.nonce || '' }
            }).then(function () {
                window.location = cfg.signInUrl;
            }).catch(function () {
                window.location = cfg.signInUrl;
            });
        });
    }
})();
