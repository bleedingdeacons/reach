/*
 * Reach — text-size toggle.
 *
 * Builds the "A A A" control (Normal / Larger / Largest) and places it on the
 * home/menu page, centered just under the menu buttons. Only the home template
 * loads this file, but the chosen size applies everywhere: the choice is stored
 * in localStorage and every Reach page's <head> snippet replays it before paint,
 * setting data-reach-text on <html> to flip the --ts CSS scale (see reach.css).
 *
 * The saved preference is applied before first paint by a tiny inline snippet
 * in each template's <head>; this file only builds the control and keeps the
 * active button in sync, so a slow load never flashes the wrong size.
 */
(function () {
    'use strict';

    var KEY = 'reach.textSize';
    var root = document.documentElement;

    // Ordered smallest → largest. `attr` is the data-reach-text value ('' =
    // normal, no attribute); `glyph` is the constant px size of this button's
    // "A" so the three buttons visibly step up. The matching --ts scale for
    // each lives in reach.css.
    var SIZES = [
        { key: 'normal', attr: '',       label: 'Normal text size',  glyph: 13 },
        { key: 'large',  attr: 'large',  label: 'Larger text size',  glyph: 17 },
        { key: 'xlarge', attr: 'xlarge', label: 'Largest text size', glyph: 22 }
    ];

    function currentKey() {
        var attr = root.getAttribute('data-reach-text') || '';
        for (var i = 0; i < SIZES.length; i++) {
            if (SIZES[i].attr === attr) { return SIZES[i].key; }
        }
        return 'normal';
    }

    function apply(size) {
        if (size.attr) {
            root.setAttribute('data-reach-text', size.attr);
        } else {
            root.removeAttribute('data-reach-text');
        }
        try { window.localStorage.setItem(KEY, size.key); } catch (e) { /* storage off */ }
        sync();
    }

    var wrap = document.createElement('div');
    wrap.className = 'reach-textsize';
    wrap.setAttribute('role', 'group');
    wrap.setAttribute('aria-label', 'Text size');

    var buttons = SIZES.map(function (size) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'reach-textsize__btn';
        b.style.fontSize = size.glyph + 'px';
        b.textContent = 'A';
        b.setAttribute('aria-label', size.label);
        b.addEventListener('click', function () { apply(size); });
        wrap.appendChild(b);
        return b;
    });

    function sync() {
        var active = currentKey();
        SIZES.forEach(function (size, i) {
            var on = size.key === active;
            buttons[i].classList.toggle('is-active', on);
            buttons[i].setAttribute('aria-pressed', String(on));
        });
    }

    function mount() {
        // Directly under the menu buttons on the home page. Fall back to the end
        // of the card if the menu markup ever changes.
        var menu = document.querySelector('.reach-menu');
        if (menu && menu.parentNode) {
            menu.parentNode.insertBefore(wrap, menu.nextSibling);
        } else {
            var card = document.querySelector('.reach-card') || document.body;
            card.appendChild(wrap);
        }
        sync();
    }

    if (document.body) {
        mount();
    } else {
        document.addEventListener('DOMContentLoaded', mount);
    }
})();
