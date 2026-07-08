<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reach "Request 12th Step" page.
 *
 * A standalone callback request: the responder captures the caller's
 * name, phone and area plus a preferred 12th-stepper gender, and posts it
 * to the call-requests endpoint. There is no member target — the server
 * records the signed-in responder's name. Reached from the home menu;
 * was previously a modal dialog on the home page.
 *
 * Standalone shell, same conventions as find.php — own <html>, no theme
 * chrome, no WP nonce (Reach is OAuth/session-cookie only). PageRouter
 * bounces an unauthenticated visitor to sign-in before reaching here.
 *
 * @var \Reach\Session\Session|null $session
 */

$email       = $session !== null ? $session->email : '';
$requestsUrl = esc_url(rest_url('reach/v1/call-requests'));
$signOutUrl  = esc_url(rest_url('reach/v1/oauth/signout'));
$signInUrl   = esc_url(home_url('/reach/signin'));
$homeUrl     = esc_url(home_url('/reach/home'));
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Request 12th Step &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
    <script>try{var s=localStorage.getItem('reach.textSize');if(s==='large'||s==='xlarge')document.documentElement.setAttribute('data-reach-text',s);}catch(e){}</script>
</head>
<body class="reach-page reach-request">
    <main class="reach-card">
        <header class="reach-header">
            <a class="reach-back" href="<?php echo $homeUrl; ?>" aria-label="Back to menu">&lt;</a>
            <h1 class="reach-title">Request 12th Step</h1>
        </header>
        <p class="reach-subtitle">Log a caller&rsquo;s details for a 12th&#8209;Stepper to call them back.</p>

        <form id="reach-request-form" class="reach-form" novalidate>
            <label class="reach-label" for="reach-request-phone">Caller&rsquo;s phone number</label>
            <input type="tel"
                   id="reach-request-phone"
                   name="caller_phone"
                   class="reach-input"
                   autocomplete="off"
                   inputmode="tel"
                   required>

            <label class="reach-label" for="reach-request-name">Caller&rsquo;s name</label>
            <input type="text"
                   id="reach-request-name"
                   name="caller_name"
                   class="reach-input"
                   autocomplete="off"
                   required>

            <label class="reach-label" for="reach-request-area">Caller&rsquo;s area</label>
            <input type="text"
                   id="reach-request-area"
                   name="area"
                   class="reach-input"
                   placeholder="Postcode or area"
                   autocomplete="off"
                   inputmode="text"
                   required>

            <fieldset class="reach-fieldset">
                <legend class="reach-label">Preferred 12th Stepper</legend>
                <label class="reach-check"><input type="radio" name="gender" value="male"> Male</label>
                <label class="reach-check"><input type="radio" name="gender" value="female"> Female</label>
                <label class="reach-check"><input type="radio" name="gender" value="non-binary"> Non-Binary</label>
            </fieldset>

            <label class="reach-label" for="reach-request-note">Notes <span class="reach-dialog__optional">(optional)</span></label>
            <textarea id="reach-request-note"
                      name="note"
                      class="reach-input reach-dialog__note"
                      rows="3"></textarea>

            <button type="submit" class="reach-btn reach-btn--primary" id="reach-request-send">
                <span class="reach-btn__label">Send</span>
                <span class="reach-btn__spinner" aria-hidden="true"></span>
            </button>
        </form>

        <div id="reach-request-status" class="reach-status" role="status" aria-live="polite"></div>

        <footer class="reach-footer">
            <div class="reach-footer__who"><?php echo esc_html($email); ?></div>
            <button type="button" class="reach-signout" id="reach-signout">Sign out</button>
        </footer>
    </main>

    <?php $reachBuild = \Reach\Plugin::buildDate(); ?>
    <p class="reach-buildstamp">v<?php echo esc_html(REACH_VERSION); ?><?php if ($reachBuild !== ''): ?> &middot; Build <?php echo esc_html($reachBuild); ?><?php endif; ?></p>

    <script>
        window.REACH_CONFIG = {
            requestsUrl: <?php echo wp_json_encode($requestsUrl); ?>,
            signOutUrl: <?php echo wp_json_encode($signOutUrl); ?>,
            signInUrl: <?php echo wp_json_encode($signInUrl); ?>,
            homeUrl: <?php echo wp_json_encode($homeUrl); ?>
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
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/request.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/textsize.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
</body>
</html>
