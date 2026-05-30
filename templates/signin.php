<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reach sign-in page.
 *
 * Four buttons — Google, Microsoft, Facebook, Apple — and nothing
 * else. Google, Microsoft, and Facebook are plain anchor tags
 * pointing at /reach/v1/oauth/start (the REST endpoint issues the
 * redirect to the provider). Apple cannot be a redirect because
 * Apple's server-side flow requires a .p8-signed client secret;
 * instead the page loads Apple's JS SDK, asks Reach for a state+nonce,
 * and calls AppleID.auth.signIn() in the browser. The returned ID
 * token is POSTed back to /oauth/apple.
 *
 * No tracking, no analytics, no fonts — the page is the smallest
 * possible thing that does the job. A user agent on a flaky mobile
 * connection should still get the page in under a second.
 */

// Detect which providers are configured (presence of a client ID).
// Secrets are checked lazily on the actual sign-in callback rather
// than here — the page only needs to know which buttons to show.
$reachSettings       = get_option('reach_settings', []);
$googleConfigured    = !empty($reachSettings['client_id_google']);
$microsoftConfigured = !empty($reachSettings['client_id_microsoft']);
$appleConfigured     = !empty($reachSettings['client_id_apple']);
$facebookConfigured  = !empty($reachSettings['client_id_facebook']);
$appleClientId       = $reachSettings['client_id_apple'] ?? '';

$signOutUrl    = esc_url(rest_url('reach/v1/oauth/signout'));
$appleStartUrl = esc_url(rest_url('reach/v1/oauth/apple/start'));
$appleVerifyUrl = esc_url(rest_url('reach/v1/oauth/apple'));
$findPageUrl   = esc_url(home_url('/reach/find'));

// Friendly sign-in notices. When the OAuth callback can prove who you
// are but can't let you in, it bounces back here with
// ?reach_error=<code> rather than dumping a raw JSON error in the
// browser. Map known codes to a human message; anything unrecognised
// falls back to a generic line so the user never sees a bare code.
$reachNotice = null;
$reachErrorCode = isset($_GET['reach_error'])
    ? sanitize_key((string) wp_unslash($_GET['reach_error']))
    : '';
if ($reachErrorCode !== '') {
    $reachNotices = [
        'not_eligible' => [
            'title' => 'This email isn’t registered as a telephone responder.',
            'body'  => 'We confirmed your email, but it isn’t on the telephone responder list. Please contact BADI Support.',
        ],
        'email_required' => [
            'title' => 'An email address is required',
            'body'  => 'The provider you signed in with didn’t share an email we can reach you on. Please sign in again and choose to share your email, or try a different provider.',
        ],
        'signin_failed' => [
            'title' => 'Sign-in didn’t complete',
            'body'  => 'Something went wrong while signing you in. Please try again.',
        ],
    ];
    $reachNotice = $reachNotices[$reachErrorCode] ?? [
        'title' => 'We couldn’t sign you in',
        'body'  => 'Please try again, or use a different account if possible.',
    ];
}
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Sign in &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
    <?php if ($appleConfigured): ?>
        <script type="text/javascript" src="https://appleid.cdn-apple.com/appleauth/static/jsapi/appleid/1/en_US/appleid.auth.js" defer></script>
    <?php endif; ?>
</head>
<body class="reach-page reach-signin">
    <main class="reach-card">
        <h1 class="reach-title">Reach</h1>
        <p class="reach-subtitle">Sign in to confirm your email. We only use it to verify you&rsquo;re a person.</p>

        <?php if ($reachNotice !== null): ?>
            <div class="reach-notice" role="alert">
                <p class="reach-notice__title"><?php echo esc_html($reachNotice['title']); ?></p>
                <p class="reach-notice__body"><?php echo esc_html($reachNotice['body']); ?></p>
            </div>
        <?php endif; ?>

        <div class="reach-buttons">
            <?php if ($googleConfigured): ?>
                <a class="reach-btn reach-btn--google" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=google')); ?>" rel="nofollow">
                    <span class="reach-btn__icon" aria-hidden="true">G</span>
                    <span>Login using Google</span>
                </a>
            <?php endif; ?>

            <?php if ($microsoftConfigured): ?>
                <a class="reach-btn reach-btn--microsoft" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=microsoft')); ?>" rel="nofollow">
                    <span class="reach-btn__icon" aria-hidden="true">&#x2756;</span>
                    <span>Login using Microsoft</span>
                </a>
            <?php endif; ?>

            <?php if ($facebookConfigured): ?>
                <a class="reach-btn reach-btn--facebook" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=facebook')); ?>" rel="nofollow">
                    <span class="reach-btn__icon" aria-hidden="true">f</span>
                    <span>Login using Facebook</span>
                </a>
            <?php endif; ?>

            <?php if ($appleConfigured): ?>
                <button type="button" class="reach-btn reach-btn--apple" id="reach-apple-btn">
                    <span class="reach-btn__icon" aria-hidden="true">&#xf8ff;</span>
                    <span>Login using Apple</span>
                </button>
            <?php endif; ?>

            <?php if (!$googleConfigured && !$microsoftConfigured && !$appleConfigured && !$facebookConfigured): ?>
                <p class="reach-error">Sign-in providers haven&rsquo;t been configured yet.</p>
            <?php endif; ?>
        </div>

        <p class="reach-fineprint">By signing in you agree to be temporarily identified by your email so you can connect you with a 12th Stepper.</p>
    </main>

    <?php if ($appleConfigured): ?>
    <script>
    (function () {
        var startUrl = <?php echo wp_json_encode($appleStartUrl); ?>;
        var verifyUrl = <?php echo wp_json_encode($appleVerifyUrl); ?>;
        var findUrl = <?php echo wp_json_encode($findPageUrl); ?>;
        var signinUrl = <?php echo wp_json_encode(esc_url(home_url('/reach/signin'))); ?>;
        var clientId = <?php echo wp_json_encode($appleClientId); ?>;
        var btn = document.getElementById('reach-apple-btn');
        if (!btn) return;

        btn.addEventListener('click', function () {
            btn.disabled = true;
            fetch(startUrl, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (tokens) {
                    if (!window.AppleID || !window.AppleID.auth) {
                        throw new Error('Apple SDK not loaded');
                    }
                    AppleID.auth.init({
                        clientId: clientId,
                        scope: 'email',
                        redirectURI: window.location.origin + '/reach/signin',
                        state: tokens.state,
                        nonce: tokens.nonce,
                        usePopup: true
                    });
                    return AppleID.auth.signIn();
                })
                .then(function (response) {
                    var body = new URLSearchParams();
                    body.append('id_token', response.authorization.id_token);
                    body.append('state', response.authorization.state);
                    return fetch(verifyUrl, {
                        method: 'POST',
                        body: body,
                        credentials: 'same-origin'
                    });
                })
                .then(function (r) {
                    if (r.ok) {
                        return r.json().then(function (data) {
                            window.location = data.redirect || findUrl;
                        });
                    }
                    // Verification was refused (e.g. not a registered
                    // member). Reload the sign-in page with the matching
                    // notice so Apple users see the same friendly message
                    // the redirect providers get, rather than nothing.
                    return r.json().then(function (data) {
                        var code = (data && data.code) ? String(data.code).replace(/^reach_/, '') : 'signin_failed';
                        window.location = signinUrl + '?reach_error=' + encodeURIComponent(code);
                    });
                })
                .catch(function (err) {
                    console.error('Apple sign-in failed', err);
                    btn.disabled = false;
                });
        });
    })();
    </script>
    <?php endif; ?>
</body>
</html>
