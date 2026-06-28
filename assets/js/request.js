/**
 * Reach — "Request 12th Step" page.
 *
 * A standalone callback request form: the responder captures the caller's
 * name, phone and area plus a preferred 12th-stepper gender, and posts it
 * to cfg.requestsUrl. There is no member target — the server records the
 * signed-in responder's name. A part-filled request is preserved in
 * localStorage so it survives a reload or leaving the page, and the draft
 * is cleared only once a request sends successfully (after which the page
 * returns to the home menu).
 */
(function () {
    'use strict';

    var cfg = window.REACH_CONFIG || {};

    var form = document.getElementById('reach-request-form');
    if (!form) return;

    var phoneEl  = document.getElementById('reach-request-phone');
    var nameEl   = document.getElementById('reach-request-name');
    var areaEl   = document.getElementById('reach-request-area');
    var noteEl   = document.getElementById('reach-request-note');
    var statusEl = document.getElementById('reach-request-status');
    var sendBtn  = document.getElementById('reach-request-send');

    // localStorage key holding the in-progress draft. Cleared once a
    // request sends successfully.
    var DRAFT_KEY = 'reach.callRequest.draft';

    function genderEls() {
        return form.querySelectorAll('input[name="gender"]');
    }

    function selectedGender() {
        var checked = form.querySelector('input[name="gender"]:checked');
        return checked ? checked.value : '';
    }

    function draftRead() {
        try {
            var raw = localStorage.getItem(DRAFT_KEY);
            return raw ? JSON.parse(raw) : {};
        } catch (e) {
            return {};
        }
    }

    function draftSave() {
        try {
            localStorage.setItem(DRAFT_KEY, JSON.stringify({
                phone:  phoneEl ? phoneEl.value : '',
                name:   nameEl  ? nameEl.value  : '',
                area:   areaEl  ? areaEl.value  : '',
                gender: selectedGender(),
                note:   noteEl  ? noteEl.value  : ''
            }));
        } catch (e) {}
    }

    function draftClear() {
        try { localStorage.removeItem(DRAFT_KEY); } catch (e) {}
    }

    function setStatus(message, kind) {
        if (!statusEl) return;
        statusEl.textContent = message || '';
        statusEl.classList.remove('is-error', 'is-success');
        if (kind) statusEl.classList.add('is-' + kind);
    }

    function setLoading(loading) {
        if (!sendBtn) return;
        sendBtn.disabled = !!loading;
        sendBtn.classList.toggle('is-loading', !!loading);
    }

    // Restore any saved draft on load.
    (function restore() {
        var draft = draftRead();
        if (phoneEl) phoneEl.value = draft.phone || '';
        if (nameEl)  nameEl.value  = draft.name  || '';
        if (areaEl)  areaEl.value  = draft.area  || '';
        if (noteEl)  noteEl.value  = draft.note  || '';
        Array.prototype.forEach.call(genderEls(), function (el) {
            el.checked = (el.value === draft.gender);
        });
    })();

    // Persist the draft as the user types / picks a gender.
    [phoneEl, nameEl, areaEl, noteEl].forEach(function (el) {
        if (el) el.addEventListener('input', draftSave);
    });
    Array.prototype.forEach.call(genderEls(), function (el) {
        el.addEventListener('change', draftSave);
    });

    form.addEventListener('submit', function (event) {
        event.preventDefault();

        var phone  = (phoneEl ? phoneEl.value : '').trim();
        var name   = (nameEl  ? nameEl.value  : '').trim();
        var area   = (areaEl  ? areaEl.value  : '').trim();
        var note   = (noteEl  ? noteEl.value  : '').trim();
        var gender = selectedGender();

        if (!phone || !name || !area) {
            setStatus('Enter the caller’s name, phone number and area.', 'error');
            if (!name && nameEl) { nameEl.focus(); }
            else if (!phone && phoneEl) { phoneEl.focus(); }
            else if (areaEl) { areaEl.focus(); }
            return;
        }
        if (!gender) {
            setStatus('Choose a preferred 12th Stepper.', 'error');
            return;
        }

        setLoading(true);
        setStatus('Sending…');

        fetch(cfg.requestsUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
            body: JSON.stringify({
                caller_phone: phone,
                caller_name:  name,
                area:         area,
                gender:       gender,
                note:         note
            })
        })
            .then(function (r) {
                return r.json().then(function (body) { return { status: r.status, body: body }; });
            })
            .then(function (resp) {
                // Session expired while the form sat open — bounce to
                // sign-in like the rest of the app.
                if (resp.status === 401) {
                    window.location = cfg.signInUrl;
                    return;
                }
                if (resp.status >= 200 && resp.status < 300) {
                    draftClear();
                    setStatus('Callback request sent.', 'success');
                    // Return to the menu once the confirmation has shown.
                    setTimeout(function () {
                        window.location = cfg.homeUrl || '/reach/home';
                    }, 1000);
                } else {
                    setLoading(false);
                    var msg = (resp.body && resp.body.message) || 'Could not send that. Try again.';
                    setStatus(msg, 'error');
                }
            })
            .catch(function () {
                setLoading(false);
                setStatus('Network error. Check your connection and try again.', 'error');
            });
    });
})();
