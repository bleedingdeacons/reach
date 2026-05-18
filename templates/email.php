<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reach typed-email page.
 *
 * Shown when an OAuth provider returns a relay address that Reach
 * can't use as a contact address — currently Facebook's `*.facebook.com`
 * relay. The OAuth callback parks the proven identity in a pending-
 * identity transient and redirects here with a `?pending=…` token.
 *
 * The page is intentionally one field plus a submit: nothing to fix
 * if the user mistyped, no third-party scripts, no analytics. The
 * pending token is the only credential — if it's missing or has
 * expired (10 minutes) we just send the user back to /signin.
 *
 * The form POSTs to /oauth/complete-email via fetch (the endpoint
 * returns JSON with a redirect target). A noscript fallback is not
 * provided: WordPress's REST router would happily accept the POST,
 * but the typical client is a phone where JS is available and the
 * UX of "page reload that may or may not redirect" is worse than
 * just requiring JS.
 */

$pendingToken = isset($_GET['pending']) && is_string($_GET['pending'])
    ? sanitize_text_field(wp_unslash($_GET['pending']))
    : '';

if ($pendingToken === '') {
    wp_safe_redirect(home_url('/reach/signin'));
    exit;
}

$completeUrl = esc_url(rest_url('reach/v1/oauth/complete-email'));
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Confirm your email &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
</head>
<body class="reach-page reach-signin">
    <main class="reach-card">
        <h1 class="reach-title">One more step</h1>
        <p class="reach-subtitle">Facebook didn&rsquo;t share your real email. Please enter the email address Reach should use to verify you.</p>

        <form id="reach-email-form" class="reach-form" novalidate>
            <input type="hidden" name="pending" value="<?php echo esc_attr($pendingToken); ?>">
            <label class="reach-label" for="reach-email-input">Email address</label>
            <input
                type="email"
                name="email"
                id="reach-email-input"
                class="reach-input"
                autocomplete="email"
                inputmode="email"
                autocapitalize="off"
                autocorrect="off"
                spellcheck="false"
                required
            >
            <p id="reach-email-error" class="reach-error" hidden></p>
            <button type="submit" class="reach-btn reach-btn--primary" id="reach-email-submit">
                <span class="reach-btn__label">Continue</span>
                <span class="reach-btn__spinner" aria-hidden="true"></span>
            </button>
        </form>

        <p class="reach-fineprint">We use this email only to verify you&rsquo;re a person and to connect you with a 12th-step member.</p>
    </main>

    <script>
    (function () {
        var form = document.getElementById('reach-email-form');
        var input = document.getElementById('reach-email-input');
        var error = document.getElementById('reach-email-error');
        var submit = document.getElementById('reach-email-submit');
        var endpoint = <?php echo wp_json_encode($completeUrl); ?>;
        if (!form) return;

        function showError(message) {
            error.textContent = message;
            error.hidden = false;
            submit.classList.remove('is-loading');
            submit.disabled = false;
        }

        form.addEventListener('submit', function (event) {
            event.preventDefault();
            error.hidden = true;
            submit.disabled = true;
            submit.classList.add('is-loading');

            var body = new URLSearchParams();
            body.append('pending', form.elements['pending'].value);
            body.append('email', input.value);

            fetch(endpoint, {
                method: 'POST',
                body: body,
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            })
                .then(function (r) {
                    return r.json().then(function (data) {
                        return { ok: r.ok, status: r.status, data: data };
                    });
                })
                .then(function (result) {
                    if (!result.ok) {
                        var msg = (result.data && result.data.message) || 'Something went wrong. Please try again.';
                        showError(msg);
                        return;
                    }
                    window.location = (result.data && result.data.redirect) || <?php echo wp_json_encode(home_url('/reach/find')); ?>;
                })
                .catch(function () {
                    showError('Network error. Please try again.');
                });
        });
    })();
    </script>
</body>
</html>
