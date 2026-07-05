<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reach "Set / reset password" request page.
 *
 * Public (not session-gated): a signed-out member enters their email and
 * we send a one-time link to set or reset a password. The response is
 * always the same confirmation whether or not the address is registered,
 * so this page never reveals who has an account.
 *
 * Standalone shell, same conventions as signin.php — own <html>, no theme
 * chrome, no WP nonce (Reach is cookie/session only).
 */

$requestResetUrl = esc_url(rest_url('reach/v1/auth/request-reset'));
$signInUrl       = esc_url(home_url('/reach/signin'));
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Set your password &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
    <script>try{var s=localStorage.getItem('reach.textSize');if(s==='large'||s==='xlarge')document.documentElement.setAttribute('data-reach-text',s);}catch(e){}</script>
</head>
<body class="reach-page reach-reset">
    <main class="reach-card">
        <header class="reach-header">
            <a class="reach-back" href="<?php echo $signInUrl; ?>" aria-label="Back to sign in">Back</a>
            <h1 class="reach-title">Set your password</h1>
        </header>
        <p class="reach-subtitle">Enter your Reach email and we&rsquo;ll send you a link to set or reset your password.</p>

        <form id="reach-reset-form" class="reach-form" novalidate>
            <label class="reach-label" for="reach-reset-email">Email</label>
            <input type="email"
                   id="reach-reset-email"
                   name="email"
                   class="reach-input"
                   autocomplete="username"
                   inputmode="email"
                   autocapitalize="none"
                   spellcheck="false"
                   required>

            <button type="submit" class="reach-btn reach-btn--primary" id="reach-reset-submit">
                <span class="reach-btn__label">Send link</span>
                <span class="reach-btn__spinner" aria-hidden="true"></span>
            </button>
        </form>

        <div id="reach-reset-status" class="reach-status" role="status" aria-live="polite"></div>
    </main>

    <?php $reachBuild = \Reach\Plugin::buildDate(); ?>
    <p class="reach-buildstamp">v<?php echo esc_html(REACH_VERSION); ?><?php if ($reachBuild !== ''): ?> &middot; Build <?php echo esc_html($reachBuild); ?><?php endif; ?></p>

    <script>
        window.REACH_AUTH = {
            requestResetUrl: <?php echo wp_json_encode($requestResetUrl); ?>
        };
    </script>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/auth.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/textsize.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
</body>
</html>
