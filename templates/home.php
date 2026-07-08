<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reach home / menu page.
 *
 * Shown right after sign-in (PageRouter sends authenticated visitors here).
 * Two choices: Search the 12th-step finder, or Shift sign-up. The shift button
 * only appears when the Trusted plugin is active, since that's what backs it.
 *
 * Standalone shell, same conventions as find.php — own <html>, no theme chrome,
 * no WP nonce (Reach is OAuth/session-cookie only).
 *
 * @var \Reach\Session\Session|null $session
 */

$email          = $session !== null ? $session->email : '';
$findUrl        = esc_url(home_url('/reach/find'));
$lookupUrl      = esc_url(home_url('/reach/lookup'));
$shiftsUrl      = esc_url(home_url('/reach/shifts'));
$signOutUrl     = esc_url(rest_url('reach/v1/oauth/signout'));
$signInUrl      = esc_url(home_url('/reach/signin'));
$requestPageUrl = esc_url(home_url('/reach/request'));
$findMeetingUrl = esc_url('https://www.alcoholics-anonymous.org.uk/find-a-meeting/#form');
$shiftsEnabled  = defined('TRUSTED_VERSION');
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Menu &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
    <script>try{var s=localStorage.getItem('reach.textSize');if(s==='large'||s==='xlarge')document.documentElement.setAttribute('data-reach-text',s);}catch(e){}</script>
</head>
<body class="reach-page reach-home">
    <main class="reach-card">
        <header class="reach-header">
            <h1 class="reach-title">Reach</h1>
        </header>
        <p class="reach-subtitle">What would you like to do?</p>

        <nav class="reach-menu">
            <a class="reach-btn reach-btn--primary" href="<?php echo $findUrl; ?>">
                <span>Search</span>
            </a>
            <a class="reach-btn" href="<?php echo $lookupUrl; ?>">
                <span>Lookup</span>
            </a>
            <?php if ($shiftsEnabled): ?>
                <a class="reach-btn" href="<?php echo $shiftsUrl; ?>">
                    <span>Shift sign-up</span>
                </a>
            <?php endif; ?>
            <a class="reach-btn" href="<?php echo $requestPageUrl; ?>">
                <span>Request 12th Step</span>
            </a>
            <a class="reach-btn" href="<?php echo $findMeetingUrl; ?>" target="_blank" rel="noopener noreferrer">
                <span>Find Meeting</span>
            </a>
        </nav>

        <footer class="reach-footer">
            <div class="reach-footer__who"><?php echo esc_html($email); ?></div>
            <button type="button" class="reach-signout" id="reach-signout">Sign out</button>
        </footer>
    </main>

    <?php $reachBuild = \Reach\Plugin::buildDate(); ?>
    <p class="reach-buildstamp">v<?php echo esc_html(REACH_VERSION); ?><?php if ($reachBuild !== ''): ?> &middot; Build <?php echo esc_html($reachBuild); ?><?php endif; ?></p>

    <script>
        window.REACH_CONFIG = {
            signOutUrl: <?php echo wp_json_encode($signOutUrl); ?>,
            signInUrl: <?php echo wp_json_encode($signInUrl); ?>
        };
    </script>
    <script>
        (function () {
            var cfg = window.REACH_CONFIG || {};
            var signOutBtn = document.getElementById('reach-signout');
            if (!signOutBtn) return;
            signOutBtn.addEventListener('click', function () {
                fetch(cfg.signOutUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                    .then(function () { window.location = cfg.signInUrl; })
                    .catch(function () { window.location = cfg.signInUrl; });
            });
        })();
    </script>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/textsize.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
</body>
</html>
