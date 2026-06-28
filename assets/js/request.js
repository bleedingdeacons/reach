/**
 * Reach — "Request a callback" dialog (home page).
 *
 * A standalone callback request: the responder captures the caller's
 * name, phone and area plus a preferred 12th-stepper gender, and posts it
 * to cfg.requestsUrl. There is no member target — the server records the
 * signed-in responder's name. A part-filled request is preserved in
 * localStorage so it survives a reload or an accidental close, and the
 * draft is cleared only once a request sends successfully.
 */
(function () {
    'use strict';

    var cfg = window.REACH_CONFIG || {};

    var openBtn = document.getElementById('reach-request-open');
    var dialog  = document.getElementById('reach-request-dialog');
    var form    = document.getElementById('reach-request-form');
    if (!openBtn || !dialog || !form) return;

    var phoneEl  = document.getElementById('reach-request-phone');
    var nameEl   = document.getElementById('reach-request-name');
    var areaEl   = document.getElementById('reach-request-area');
    var noteEl   = document.getElementById('reach-request-note');
    var statusEl = document.getElementById('reach-request-status');
    var sendBtn  = document.getElementById('reach-request-send');
    var cancelBtn = document.getElementById('reach-request-cancel');

    // localStorage key holding the in-progress draft. Shared with no one;
    // cleared once a request sends successfully.
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

    function closeDialog() {
        if (typeof dialog.close === 'function') {
            dialog.close();
        } else {
            dialog.removeAttribute('open');
        }
    }

    function openDialog() {
        var draft = draftRead();
        if (phoneEl) phoneEl.value = draft.phone || '';
        if (nameEl)  nameEl.value  = draft.name  || '';
        if (areaEl)  areaEl.value  = draft.area  || '';
        if (noteEl)  noteEl.value  = draft.note  || '';
        Array.prototype.forEach.call(genderEls(), function (el) {
            el.checked = (el.value === draft.gender);
        });

        setStatus('');
        setLoading(false);

        if (typeof dialog.showModal === 'function') {
            dialog.showModal();
        } else {
            dialog.setAttribute('open', '');
        }

        var focusEl = (phoneEl && !phoneEl.value) ? phoneEl
            : ((nameEl && !nameEl.value) ? nameEl : phoneEl);
        if (focusEl) { try { focusEl.focus(); } catch (e) {} }
    }

    openBtn.addEventListener('click', openDialog);

    // Persist the draft as the user types / picks a gender.
    [phoneEl, nameEl, areaEl, noteEl].forEach(function (el) {
        if (el) el.addEventListener('input', draftSave);
    });
    Array.prototype.forEach.call(genderEls(), function (el) {
        el.addEventListener('change', draftSave);
    });

    if (cancelBtn) {
        cancelBtn.addEventListener('click', closeDialog);
    }
    // Click on the backdrop (the dialog element itself, outside the form)
    // dismisses — the draft is safe in localStorage regardless.
    dialog.addEventListener('click', function (event) {
        if (event.target === dialog) closeDialog();
    });

    form.addEventListener('submit', function (event) {
        // method="dialog"; preventDefault stops the navigation and keeps
        // the dialog open so an error can show without it vanishing.
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
                setLoading(false);
                // Session expired while the dialog sat open — bounce to
                // sign-in like the rest of the app.
                if (resp.status === 401) {
                    window.location = cfg.signInUrl;
                    return;
                }
                if (resp.status >= 200 && resp.status < 300) {
                    draftClear();
                    form.reset();
                    setStatus('Callback request sent.', 'success');
                    setLoading(true); // keep Send disabled until reopened
                    setTimeout(closeDialog, 1200);
                } else {
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
