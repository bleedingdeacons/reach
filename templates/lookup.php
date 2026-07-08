<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Reach lookup page.
 *
 * Embeds the LifeLines [lifelines_lookup] shortcode. Reach templates are
 * standalone (own <html>, no wp_head()/wp_footer()), so the shortcode's own
 * CSS/JS won't be output by the normal asset pipeline. We therefore fire
 * wp_enqueue_scripts once (so LifeLines registers its 'lifelines-lookup'
 * handles), render the shortcode (which enqueues + localises them), and print
 * just those handles into this page's head/footer.
 *
 * Standalone shell, same conventions as home.php — own <html>, no theme chrome.
 *
 * @var \Reach\Session\Session|null $session
 */

$homeUrl   = esc_url(home_url('/reach/home'));
$hasLookup = shortcode_exists('lifelines_lookup');
$lookupHtml = '';

if ($hasLookup) {
    // Reach bypasses wp_head(), so wp_enqueue_scripts hasn't fired yet — fire it
    // once so LifeLines' asset handles get registered before we render.
    if (!did_action('wp_enqueue_scripts')) {
        do_action('wp_enqueue_scripts');
    }
    $lookupHtml = do_shortcode('[lifelines_lookup]');
}
?><!DOCTYPE html>
<html lang="<?php echo esc_attr(get_bloginfo('language')); ?>">
<head>
    <meta charset="<?php echo esc_attr(get_bloginfo('charset')); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <title>Lookup &mdash; Reach</title>
    <link rel="stylesheet" href="<?php echo esc_url(REACH_PLUGIN_URL . 'assets/css/reach.css'); ?>?v=<?php echo esc_attr(REACH_VERSION); ?>">
    <script>try{var s=localStorage.getItem('reach.textSize');if(s==='large'||s==='xlarge')document.documentElement.setAttribute('data-reach-text',s);}catch(e){}</script>
    <?php if ($hasLookup) { wp_print_styles('lifelines-lookup'); } ?>
</head>
<body class="reach-page reach-lookup">
    <main class="reach-card">
        <header class="reach-header">
            <a class="reach-back" href="<?php echo $homeUrl; ?>" aria-label="Back to menu">&lt;</a>
            <h1 class="reach-title">Lookup</h1>
        </header>

        <?php if ($hasLookup): ?>
            <?php echo $lookupHtml; // shortcode output is already escaped ?>
        <?php else: ?>
            <p class="reach-subtitle">Lookup is currently unavailable.</p>
        <?php endif; ?>
    </main>

    <?php $reachBuild = \Reach\Plugin::buildDate(); ?>
    <p class="reach-buildstamp">v<?php echo esc_html(REACH_VERSION); ?><?php if ($reachBuild !== ''): ?> &middot; Build <?php echo esc_html($reachBuild); ?><?php endif; ?></p>

    <?php if ($hasLookup) { wp_print_scripts('lifelines-lookup'); } ?>
</body>
</html>
