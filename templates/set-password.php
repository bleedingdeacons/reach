<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\PasswordPolicy;

/**
 * Reach "Choose a password" page — the target of the emailed reset link.
 *
 * Public (not session-gated). The one-time token arrives as ?token=… and
 * is posted, with the new password, to the set-password endpoint. On
 * success the endpoint signs the member in and returns where to go next;
 * an expired/used token comes back as an error the member can recover from
 * by requesting a fresh link.
 *
 * Standalone shell, same conventions as signin.php.
 */

$token    = isset($_GET['token']) ? sanitize_text_field(wp_unslash((string) $_GET['token'])) : '';
$hasToken = $token !== '';

$setPasswordUrl = esc_url(rest_url('reach/v1/auth/set-password'));
$signInUrl      = esc_url(home_url('/reach/signin'));
$homeUrl        = esc_url(home_url('/reach/home'));
$resetPageUrl   = esc_url(home_url('/reach/reset'));
$minLength      = PasswordPolicy::MIN_LENGTH;
$maxLength      = PasswordPolicy::MAX_LENGTH;
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Choose a password &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
    <script>try{var s=localStorage.getItem('reach.textSize');if(s==='large'||s==='xlarge')document.documentElement.setAttribute('data-reach-text',s);}catch(e){}</script>
</head>
<body class="reach-page reach-setpw">
    <main class="reach-card">
        <header class="reach-header">
            <h1 class="reach-title">Choose a password</h1>
        </header>

        <?php if (!$hasToken): ?>
            <div class="reach-notice" role="alert">
                <p class="reach-notice__title">This link is missing or invalid</p>
                <p class="reach-notice__body">Please open the most recent link from your email, or <a href="<?php echo $resetPageUrl; ?>">request a new one</a>.</p>
            </div>
        <?php else: ?>
            <p class="reach-subtitle">Set a password for your Reach account. You&rsquo;ll be signed in straight away.</p>

            <form id="reach-setpw-form" class="reach-form" novalidate>
                <label class="reach-label" for="reach-setpw-password">New password</label>
                <input type="password"
                       id="reach-setpw-password"
                       name="password"
                       class="reach-input"
                       autocomplete="new-password"
                       minlength="<?php echo esc_attr((string) $minLength); ?>"
                       maxlength="<?php echo esc_attr((string) $maxLength); ?>"
                       required>
                <p class="reach-hint">At least <?php echo esc_html((string) $minLength); ?> characters. Longer is stronger &mdash; a few random words make a good, memorable password. Avoid common passwords.</p>

                <label class="reach-label" for="reach-setpw-confirm">Confirm password</label>
                <input type="password"
                       id="reach-setpw-confirm"
                       name="confirm"
                       class="reach-input"
                       autocomplete="new-password"
                       maxlength="<?php echo esc_attr((string) $maxLength); ?>"
                       required>

                <button type="submit" class="reach-btn reach-btn--primary" id="reach-setpw-submit">
                    <span class="reach-btn__label">Save password</span>
                    <span class="reach-btn__spinner" aria-hidden="true"></span>
                </button>
            </form>

            <div id="reach-setpw-status" class="reach-status" role="status" aria-live="polite"></div>
        <?php endif; ?>
    </main>

    <?php $reachBuild = \Reach\Plugin::buildDate(); ?>
    <p class="reach-buildstamp">v<?php echo esc_html(REACH_VERSION); ?><?php if ($reachBuild !== ''): ?> &middot; Build <?php echo esc_html($reachBuild); ?><?php endif; ?></p>

    <?php if ($hasToken): ?>
    <script>
        window.REACH_AUTH = {
            setPasswordUrl: <?php echo wp_json_encode($setPasswordUrl); ?>,
            token: <?php echo wp_json_encode($token); ?>,
            homeUrl: <?php echo wp_json_encode($homeUrl); ?>,
            signInUrl: <?php echo wp_json_encode($signInUrl); ?>,
            minLength: <?php echo (int) $minLength; ?>
        };
    </script>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/auth.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
    <?php endif; ?>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/textsize.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
</body>
</html>
