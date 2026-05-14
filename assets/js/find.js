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
            if (m.personal_email) {
                var mail = document.createElement('a');
                mail.href = 'mailto:' + m.personal_email;
                mail.textContent = 'Email ' + m.personal_email;
                contact.appendChild(mail);
            }
            li.appendChild(contact);

            if (m.accepts && m.accepts.length) {
                var ac = document.createElement('div');
                ac.className = 'reach-result__accepts';
                ac.textContent = 'Accepts calls from: ' + m.accepts.join(', ');
                li.appendChild(ac);
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
