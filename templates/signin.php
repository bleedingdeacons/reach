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
        <h1 class="reach-title">Find a 12th-step member</h1>
        <p class="reach-subtitle">Sign in to confirm your email. We only use it to verify you&rsquo;re a person.</p>

        <div class="reach-buttons">
            <?php if ($googleConfigured): ?>
                <a class="reach-btn reach-btn--google" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=google')); ?>" rel="nofollow">
                    <span class="reach-btn__icon" aria-hidden="true">G</span>
                    <span>Continue with Google</span>
                </a>
            <?php endif; ?>

            <?php if ($microsoftConfigured): ?>
                <a class="reach-btn reach-btn--microsoft" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=microsoft')); ?>" rel="nofollow">
                    <span class="reach-btn__icon" aria-hidden="true">&#x2756;</span>
                    <span>Continue with Microsoft</span>
                </a>
            <?php endif; ?>

            <?php if ($facebookConfigured): ?>
                <a class="reach-btn reach-btn--facebook" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=facebook')); ?>" rel="nofollow">
                    <span class="reach-btn__icon" aria-hidden="true">f</span>
                    <span>Continue with Facebook</span>
                </a>
            <?php endif; ?>

            <?php if ($appleConfigured): ?>
                <button type="button" class="reach-btn reach-btn--apple" id="reach-apple-btn">
                    <span class="reach-btn__icon" aria-hidden="true">&#xf8ff;</span>
                    <span>Continue with Apple</span>
                </button>
            <?php endif; ?>

            <?php if (!$googleConfigured && !$microsoftConfigured && !$appleConfigured && !$facebookConfigured): ?>
                <p class="reach-error">Sign-in providers haven&rsquo;t been configured yet. Please contact the site administrator.</p>
            <?php endif; ?>
        </div>

        <p class="reach-fineprint">By signing in you agree to be temporarily identified by your email so we can connect you with a 12th-step member.</p>
    </main>

    <?php if ($appleConfigured): ?>
    <script>
    (function () {
        var startUrl = <?php echo wp_json_encode($appleStartUrl); ?>;
        var verifyUrl = <?php echo wp_json_encode($appleVerifyUrl); ?>;
        var findUrl = <?php echo wp_json_encode($findPageUrl); ?>;
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
                    if (!r.ok) throw new Error('Verify failed: ' + r.status);
                    return r.json();
                })
                .then(function (data) {
                    window.location = data.redirect || findUrl;
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
