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

$appleStartUrl = esc_url(rest_url('reach/v1/oauth/apple/start'));
$appleVerifyUrl = esc_url(rest_url('reach/v1/oauth/apple'));
$homeUrl       = esc_url(home_url('/reach/home'));

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
        <p class="reach-subtitle">Sign in to confirm your email. We only use it to verify you&rsquo;re a telephone responder.</p>

        <?php if ($reachNotice !== null): ?>
            <div class="reach-notice" role="alert">
                <p class="reach-notice__title"><?php echo esc_html($reachNotice['title']); ?></p>
                <p class="reach-notice__body"><?php echo esc_html($reachNotice['body']); ?></p>
            </div>
        <?php endif; ?>

        <div class="reach-buttons">
            <?php if ($googleConfigured): ?>
                <a class="reach-btn reach-btn--google" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=google')); ?>" rel="nofollow">
                    <!--
                        Google "G" mark — the four-colour brand logo
                        required by Google's Sign-In branding guidelines.
                        Letter glyphs ("G") fail the guideline and also
                        risk being parsed as part of the button label by
                        sighted readers chunking the line visually.
                    -->
                    <svg class="reach-btn__icon" aria-hidden="true" focusable="false" viewBox="0 0 48 48">
                        <path fill="#FFC107" d="M43.611 20.083H42V20H24v8h11.303c-1.649 4.657-6.08 8-11.303 8-6.627 0-12-5.373-12-12s5.373-12 12-12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 12.955 4 4 12.955 4 24s8.955 20 20 20 20-8.955 20-20c0-1.341-.138-2.65-.389-3.917z"/>
                        <path fill="#FF3D00" d="M6.306 14.691l6.571 4.819C14.655 15.108 18.961 12 24 12c3.059 0 5.842 1.154 7.961 3.039l5.657-5.657C34.046 6.053 29.268 4 24 4 16.318 4 9.656 8.337 6.306 14.691z"/>
                        <path fill="#4CAF50" d="M24 44c5.166 0 9.86-1.977 13.409-5.192l-6.19-5.238A11.91 11.91 0 0124 36c-5.202 0-9.619-3.317-11.283-7.946l-6.522 5.025C9.505 39.556 16.227 44 24 44z"/>
                        <path fill="#1976D2" d="M43.611 20.083H42V20H24v8h11.303a12.04 12.04 0 01-4.087 5.571l.003-.002 6.19 5.238C36.971 39.205 44 34 44 24c0-1.341-.138-2.65-.389-3.917z"/>
                    </svg>
                    <span>Sign in with Google</span>
                </a>
            <?php endif; ?>

            <?php if ($microsoftConfigured): ?>
                <a class="reach-btn reach-btn--microsoft" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=microsoft')); ?>" rel="nofollow">
                    <!-- Microsoft four-square brand mark. -->
                    <svg class="reach-btn__icon" aria-hidden="true" focusable="false" viewBox="0 0 23 23">
                        <path fill="#F35325" d="M1 1h10v10H1z"/>
                        <path fill="#81BC06" d="M12 1h10v10H12z"/>
                        <path fill="#05A6F0" d="M1 12h10v10H1z"/>
                        <path fill="#FFBA08" d="M12 12h10v10H12z"/>
                    </svg>
                    <span>Sign in with Microsoft</span>
                </a>
            <?php endif; ?>

            <?php if ($facebookConfigured): ?>
                <a class="reach-btn reach-btn--facebook" href="<?php echo esc_url(rest_url('reach/v1/oauth/start?provider=facebook')); ?>" rel="nofollow">
                    <!-- Facebook "f in circle" brand mark. -->
                    <svg class="reach-btn__icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24">
                        <path fill="#1877F2" d="M24 12.073C24 5.405 18.627 0 12 0S0 5.405 0 12.073c0 6.025 4.388 11.02 10.125 11.927v-8.437H7.078v-3.49h3.047V9.412c0-3.022 1.792-4.692 4.533-4.692 1.312 0 2.686.235 2.686.235v2.962h-1.514c-1.491 0-1.957.93-1.957 1.886v2.265h3.328l-.532 3.49h-2.796v8.437C19.612 23.092 24 18.098 24 12.073z"/>
                    </svg>
                    <span>Sign in with Facebook</span>
                </a>
            <?php endif; ?>

            <?php if ($appleConfigured): ?>
                <button type="button" class="reach-btn reach-btn--apple" id="reach-apple-btn">
                    <!--
                        Apple logo silhouette. `fill="currentColor"` lets
                        the icon inherit the button's `color: #fff`, so
                        the logo renders white on the black Apple button
                        without needing a separate colour rule. Replaces
                        U+F8FF, which only mapped to the Apple glyph on
                        Apple's own OSes — everywhere else it was tofu.
                    -->
                    <svg class="reach-btn__icon" aria-hidden="true" focusable="false" viewBox="0 0 24 24">
                        <path fill="currentColor" d="M17.05 12.04c-.03-2.85 2.32-4.21 2.42-4.27-1.32-1.93-3.38-2.2-4.11-2.23-1.75-.18-3.42 1.03-4.31 1.03-.89 0-2.27-1.01-3.74-.98-1.92.03-3.7 1.12-4.69 2.84-2 3.47-.51 8.59 1.44 11.4.95 1.38 2.08 2.92 3.57 2.86 1.43-.06 1.97-.93 3.7-.93s2.22.93 3.74.9c1.54-.03 2.52-1.4 3.46-2.78 1.09-1.6 1.54-3.15 1.56-3.23-.03-.01-2.99-1.15-3.04-4.55zM14.21 4.04c.79-.96 1.32-2.29 1.17-3.61-1.13.05-2.5.75-3.32 1.71-.73.85-1.37 2.21-1.2 3.51 1.26.1 2.55-.64 3.35-1.61z"/>
                    </svg>
                    <span>Sign in with Apple</span>
                </button>
            <?php endif; ?>

            <?php if (!$googleConfigured && !$microsoftConfigured && !$appleConfigured && !$facebookConfigured): ?>
                <p class="reach-error">Sign-in providers haven&rsquo;t been configured yet.</p>
            <?php endif; ?>
        </div>

        <p class="reach-fineprint">By signing in you agree to be temporarily identified by your email so you can connect with a 12th Stepper.</p>
    </main>

    <?php $reachBuild = \Reach\Plugin::buildDate(); ?>
    <p class="reach-buildstamp">v<?php echo esc_html(REACH_VERSION); ?><?php if ($reachBuild !== ''): ?> &middot; Build <?php echo esc_html($reachBuild); ?><?php endif; ?></p>

    <?php if ($appleConfigured): ?>
    <script>
    (function () {
        var startUrl = <?php echo wp_json_encode($appleStartUrl); ?>;
        var verifyUrl = <?php echo wp_json_encode($appleVerifyUrl); ?>;
        var homeUrl = <?php echo wp_json_encode($homeUrl); ?>;
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
                            window.location = data.redirect || homeUrl;
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
