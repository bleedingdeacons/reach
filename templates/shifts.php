<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reach shift sign-up page.
 *
 * A simple list of a day's shifts that the signed-in responder can sign up for,
 * driven entirely by Trusted's member-facing REST (`trusted/v1/signup/...`).
 * Reach's session cookie authorises those calls via the `trusted_signup_member`
 * filter (see Plugin::init). Trusted enforces responder-only + one-per-shift.
 *
 * Standalone shell, same conventions as find.php — all logic in assets/js/shifts.js.
 *
 * @var \Reach\Session\Session|null $session
 */

$email      = $session !== null ? $session->email : '';
$signOutUrl = esc_url(rest_url('reach/v1/oauth/signout'));
$signInUrl  = esc_url(home_url('/reach/signin'));
$homeUrl    = esc_url(home_url('/reach/home'));
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Shift sign-up &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
    <script>try{var s=localStorage.getItem('reach.textSize');if(s==='large'||s==='xlarge')document.documentElement.setAttribute('data-reach-text',s);}catch(e){}</script>
</head>
<body class="reach-page reach-shifts">
    <main class="reach-card">
        <header class="reach-header">
            <a class="reach-back" href="<?php echo $homeUrl; ?>" aria-label="Back to menu">Back</a>
            <h1 class="reach-title">Shift sign-up</h1>
        </header>
        <p class="reach-subtitle">Choose a day, then sign up for an open shift.</p>

        <div class="reach-day" role="group" aria-label="Day">
            <button type="button" class="reach-day__nav" id="reach-day-prev" aria-label="Previous day">&lsaquo;</button>
            <div id="reach-day-weekday" class="reach-day__weekday" aria-live="polite"></div>
            <button type="button" class="reach-day__nav" id="reach-day-next" aria-label="Next day">&rsaquo;</button>
        </div>

        <div id="reach-status" class="reach-status" role="status" aria-live="polite"></div>

        <form id="reach-shifts-form" class="reach-shifts-form" novalidate>
            <ul id="reach-shifts-list" class="reach-shifts-list"></ul>
            <button type="submit" class="reach-btn reach-btn--primary" id="reach-shifts-submit" hidden>
                <span class="reach-btn__label">Sign up</span>
                <span class="reach-btn__spinner" aria-hidden="true"></span>
            </button>
        </form>

        <footer class="reach-footer">
            <div class="reach-footer__who"><?php echo esc_html($email); ?></div>
            <button type="button" class="reach-signout" id="reach-signout">Sign out</button>
        </footer>
    </main>

    <?php $reachBuild = \Reach\Plugin::buildDate(); ?>
    <p class="reach-buildstamp">v<?php echo esc_html(REACH_VERSION); ?><?php if ($reachBuild !== ''): ?> &middot; Build <?php echo esc_html($reachBuild); ?><?php endif; ?></p>

    <script>
        window.REACH_CONFIG = {
            trustedBase: <?php echo wp_json_encode(esc_url_raw(rest_url('trusted/v1'))); ?>,
            signOutUrl: <?php echo wp_json_encode($signOutUrl); ?>,
            signInUrl: <?php echo wp_json_encode($signInUrl); ?>,
            // "Today" in the site's configured timezone (Settings → General), so
            // the day list opens on the same day the rest of the site considers
            // today rather than whatever the visitor's device clock reads.
            today: <?php echo wp_json_encode(current_datetime()->format('Y-m-d')); ?>
        };
    </script>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/shifts.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/textsize.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
</body>
</html>
