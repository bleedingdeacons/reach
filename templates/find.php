<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reach find page.
 *
 * The interactive UI: a location input, a checkbox group of genders
 * the member accepts 12th-step calls from (Male / Female / Non-Binary,
 * all selected by default), a search button, a results list, and a
 * sign-out link in the top corner. All real work happens in
 * assets/js/find.js; this template is the static shell.
 *
 * The page assumes a valid session (PageRouter redirects un-
 * authenticated requests to /reach/signin before reaching the
 * template), so we render the signed-in user's email in the header.
 *
 * `nonce` is the standard WP REST nonce — although Reach sessions
 * authenticate the request, sending the nonce too makes the request
 * survive any future capability-check overlay (e.g. when an admin
 * has set Settings::requireScrutinyCapability to true and the user
 * also happens to be logged in to WP).
 */

/** @var \Reach\Session\Session|null $session */
$email = $session !== null ? $session->email : '';
$restUrl  = esc_url(rest_url('reach/v1/nearest-members'));
$attemptsUrl = esc_url(rest_url('reach/v1/call-attempts'));
$signOutUrl = esc_url(rest_url('reach/v1/oauth/signout'));
$signInUrl = esc_url(home_url('/reach/signin'));
$nonce = wp_create_nonce('wp_rest');
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Find &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
</head>
<body class="reach-page reach-find">
    <header class="reach-header">
        <div class="reach-header__who"><?php echo esc_html($email); ?></div>
        <button type="button" class="reach-signout" id="reach-signout">Sign out</button>
    </header>

    <main class="reach-card">
        <h1 class="reach-title">Find nearest member</h1>

        <form id="reach-form" class="reach-form" novalidate>
            <label class="reach-label" for="reach-location">Your location</label>
            <input type="text"
                   id="reach-location"
                   name="location"
                   class="reach-input"
                   placeholder="Postcode or area"
                   autocomplete="postal-code"
                   inputmode="text"
                   required>

            <fieldset class="reach-fieldset">
                <legend class="reach-label">They accepts</legend>
                <label class="reach-check"><input type="checkbox" name="accepts" value="accepts-male" checked> Male</label>
                <label class="reach-check"><input type="checkbox" name="accepts" value="accepts-female" checked> Female</label>
                <label class="reach-check"><input type="checkbox" name="accepts" value="accepts-non-binary" checked> Non-Binary</label>
            </fieldset>

            <button type="submit" class="reach-btn reach-btn--primary" id="reach-submit">
                <span class="reach-btn__label">Search</span>
                <span class="reach-btn__spinner" aria-hidden="true"></span>
            </button>
        </form>

        <div id="reach-status" class="reach-status" role="status" aria-live="polite"></div>
        <ol id="reach-results" class="reach-results"></ol>
    </main>

    <script>
        window.REACH_CONFIG = {
            restUrl: <?php echo wp_json_encode($restUrl); ?>,
            attemptsUrl: <?php echo wp_json_encode($attemptsUrl); ?>,
            signOutUrl: <?php echo wp_json_encode($signOutUrl); ?>,
            signInUrl: <?php echo wp_json_encode($signInUrl); ?>,
            nonce: <?php echo wp_json_encode($nonce); ?>
        };
    </script>
    <script src="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/js/find.js'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>"></script>
</body>
</html>
