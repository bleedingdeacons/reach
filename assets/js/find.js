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
    var distanceEl = document.getElementById('reach-distance');
    var signOutBtn = document.getElementById('reach-signout');

    if (!form) return;

    // Distance-filter steps (km), shown as buttons under the search.
    // The page fetches everyone inside the widest step once, then these
    // buttons narrow the *already-fetched* set client-side — no refetch.
    var DISTANCE_STEPS = [1, 5, 10, 20];
    var FETCH_MAX_KM = DISTANCE_STEPS[DISTANCE_STEPS.length - 1];
    var FETCH_LIMIT = 50;
    // Distance the results default to on a fresh search.
    var DEFAULT_MAX_KM = 5;

    // The full result set from the last search (everyone within
    // FETCH_MAX_KM), the currently selected cap, and a record of any
    // call outcomes already logged this session so re-rendering after a
    // distance change doesn't wipe the "Logged: …" confirmations.
    var lastResults = [];
    var activeMaxKm = FETCH_MAX_KM;
    var loggedOutcomes = {};

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

    // Human-readable labels for the stored accepts values. The member
    // record holds slugs (accepts-male, …); the results line shows the
    // friendly form. Unknown values fall back to a tidied slug.
    var ACCEPTS_LABELS = {
        'accepts-male':       'Male',
        'accepts-female':     'Female',
        'accepts-non-binary': 'Non-binary'
    };

    function acceptsLabel(value) {
        if (ACCEPTS_LABELS[value]) return ACCEPTS_LABELS[value];
        var s = String(value).replace(/^accepts-/, '').replace(/-/g, ' ');
        return s ? s.charAt(0).toUpperCase() + s.slice(1) : value;
    }

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
                    var label = (
                        OUTCOME_BUTTONS.find(function (o) { return o.value === outcome; }) || {}
                    ).label;
                    loggedOutcomes[member.id] = label;
                    group.innerHTML = '';
                    var ack = document.createElement('div');
                    ack.className = 'reach-result__logged';
                    ack.textContent = 'Logged: ' + label;
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

    // Members of the last search within the currently selected cap,
    // preserving the server's distance-then-preferred ordering.
    function filteredMembers() {
        return lastResults.filter(function (m) {
            return typeof m.distance_km !== 'number' || m.distance_km <= activeMaxKm;
        });
    }

    // Entry point after a successful search: keep the full set, default
    // to the widest cap (show everyone fetched), then paint the list and
    // the distance bar.
    function applyResults(payload) {
        lastResults = (payload && payload.members) || [];
        loggedOutcomes = {};
        activeMaxKm = pickDefaultMaxKm();

        if (lastResults.length === 0) {
            distanceEl.hidden = true;
            distanceEl.innerHTML = '';
            resultsEl.innerHTML = '';
            setStatus('No members matched. Try widening your search.', null);
            return;
        }

        renderDistanceBar();
        renderList();
    }

    // Default the view to 5km. If nothing is that close, fall back to the
    // smallest step that actually has a member so a fresh search never
    // opens on an empty list.
    function pickDefaultMaxKm() {
        var within = function (km) {
            return lastResults.some(function (m) {
                return typeof m.distance_km !== 'number' || m.distance_km <= km;
            });
        };
        if (within(DEFAULT_MAX_KM)) return DEFAULT_MAX_KM;
        for (var i = 0; i < DISTANCE_STEPS.length; i++) {
            if (within(DISTANCE_STEPS[i])) return DISTANCE_STEPS[i];
        }
        return FETCH_MAX_KM;
    }

    // Build the 5 / 10 / 15 / 20 km buttons. Each shows how many of the
    // current results fall within it; an empty step is disabled rather
    // than hidden so the scale stays stable as the user narrows.
    function renderDistanceBar() {
        distanceEl.innerHTML = '';

        var legend = document.createElement('div');
        legend.className = 'reach-distance__label';
        legend.textContent = 'Within';
        distanceEl.appendChild(legend);

        var row = document.createElement('div');
        row.className = 'reach-distance__buttons';

        DISTANCE_STEPS.forEach(function (step) {
            var count = lastResults.filter(function (m) {
                return typeof m.distance_km !== 'number' || m.distance_km <= step;
            }).length;

            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'reach-btn reach-btn--distance';
            if (step === activeMaxKm) b.classList.add('is-active');
            b.setAttribute('aria-pressed', step === activeMaxKm ? 'true' : 'false');
            b.disabled = count === 0;
            b.textContent = step + ' km';

            var tally = document.createElement('span');
            tally.className = 'reach-distance__count';
            tally.textContent = count;
            b.appendChild(tally);

            b.addEventListener('click', function () {
                if (step === activeMaxKm) return;
                activeMaxKm = step;
                renderDistanceBar();
                renderList();
            });

            row.appendChild(b);
        });

        distanceEl.appendChild(row);
        distanceEl.hidden = false;
    }

    function renderList() {
        var members = filteredMembers();
        resultsEl.innerHTML = '';

        if (members.length === 0) {
            setStatus('No members within ' + activeMaxKm + ' km. Pick a wider distance.', null);
            return;
        }

        var shown = members.length;
        var total = lastResults.length;
        var noun = 'member' + (shown === 1 ? '' : 's');
        var msg = shown + ' ' + noun + ' within ' + activeMaxKm + ' km';
        if (shown < total) msg += ' (of ' + total + ')';
        setStatus(msg, 'success');

        members.forEach(function (m) {
            var li = document.createElement('li');
            li.className = 'reach-result';
            if (m.preferred === false) li.classList.add('reach-result--fallback');

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

            // Flag members who fall outside the selected gender
            // preference: still nearby and offered, but not a match on
            // who they take 12th-step calls from.
            if (m.preferred === false) {
                var pref = document.createElement('div');
                pref.className = 'reach-result__badge reach-result__badge--soft';
                pref.textContent = 'Outside selected preference';
                li.appendChild(pref);
            }

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
                // Clicking call/text after the user has already logged
                // an outcome for this member implies a *new* attempt is
                // about to happen — clear the previous "Logged: …"
                // confirmation so the outcome buttons reappear and the
                // next result can be recorded. If no outcome is logged
                // yet (initial state), this is a no-op and the buttons
                // are already on screen.
                //
                // The re-render is deferred to a microtask via
                // setTimeout(_, 0) so the browser's native handling of
                // the tel:/sms: link runs first — mutating the list
                // mid-click could otherwise yank the anchor out from
                // under the navigation on some browsers.
                var reopenFeedback = function () {
                    if (!loggedOutcomes[m.id]) {
                        return;
                    }
                    delete loggedOutcomes[m.id];
                    setTimeout(renderList, 0);
                };

                var tel = document.createElement('a');
                tel.href = 'tel:' + m.mobile_number.replace(/\s+/g, '');
                tel.textContent = 'Call ' + m.mobile_number;
                tel.addEventListener('click', reopenFeedback);
                contact.appendChild(tel);
                var sms = document.createElement('a');
                sms.href = 'sms:' + m.mobile_number.replace(/\s+/g, '');
                sms.textContent = 'Text ' + m.mobile_number;
                sms.addEventListener('click', reopenFeedback);
                contact.appendChild(sms);
            }
            li.appendChild(contact);

            if (m.accepts && m.accepts.length) {
                var ac = document.createElement('div');
                ac.className = 'reach-result__accepts';
                ac.textContent = 'Accepts calls from: ' + m.accepts.map(acceptsLabel).join(', ');
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

                // Already logged this session (e.g. before the user
                // changed the distance filter): show the confirmation
                // instead of re-offering the buttons.
                if (loggedOutcomes[m.id]) {
                    var ack = document.createElement('div');
                    ack.className = 'reach-result__logged';
                    ack.textContent = 'Logged: ' + loggedOutcomes[m.id];
                    group.appendChild(ack);
                } else {
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
                }
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
        // Fetch everyone inside the widest distance step in one go; the
        // distance buttons then narrow this set client-side.
        url.searchParams.set('max_km', String(FETCH_MAX_KM));
        url.searchParams.set('limit', String(FETCH_LIMIT));

        setLoading(true);
        setStatus('Searching\u2026');
        resultsEl.innerHTML = '';
        distanceEl.hidden = true;
        distanceEl.innerHTML = '';

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
                    applyResults(resp.body);
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
