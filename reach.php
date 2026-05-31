<?php

declare(strict_types=1);

/**
 * Plugin Name: Reach
 * Description: Public-facing front end for finding 12th-step members. Email-verified sign-in via Google, Microsoft, Apple, or Facebook, plus a mobile-first finder UI for locating the nearest available 12th-step members. Requires Unity and Scrutiny.
 * Version: 1.1.8
 * Requires at least: 6.1
 * Requires PHP: 8.1
 * Requires Plugins: unity, scrutiny
 * GitHub Plugin URI: https://github.com/thebleedingdeacons/reach
 * GitHub Branch: main
 * Author: The Bleeding Deacons
 * Author URI: https://github.com/bleedingdeacons/reach
 * Contact: thebleedingdeacons@gmail.com
 * License: MIT (Modified)
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('get_plugin_data')) {
    if (file_exists(ABSPATH . 'wp-admin/includes/plugin.php')) {
        require_once(ABSPATH . 'wp-admin/includes/plugin.php');
    }
}

$reach_plugin_data = get_plugin_data(__FILE__, false, false);
define('REACH_VERSION', $reach_plugin_data['Version']);
define('REACH_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('REACH_PLUGIN_URL', plugin_dir_url(__FILE__));
define('REACH_PLUGIN_FILE', __FILE__);

// Autoloader for Reach namespace
spl_autoload_register(function ($class) {
    try {
        $prefix = 'Reach\\';
        $base_dir = REACH_PLUGIN_DIR . 'src/';

        $len = strlen($prefix);
        if (strncmp($prefix, $class, $len) !== 0) {
            return;
        }

        $relative_class = substr($class, $len);
        $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('reach')->error('Reach Autoloader Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Reach Autoloader Error: ' . $e->getMessage());
    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('reach')->critical('Reach Autoloader Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Reach Autoloader Fatal Error: ' . $e->getMessage());
    }
});

/**
 * Get the Reach dependency container (Unity's container).
 *
 * @return \Psr\Container\ContainerInterface
 * @throws \RuntimeException If Reach is not initialized
 */
function reach(): \Psr\Container\ContainerInterface {
    return \Reach\Plugin::getContainer();
}

// Initialize after Unity is loaded.
add_action('unity/loaded', function($container) {
    try {
        // Scrutiny provides the AuditLogger used by the nearest-members
        // controller (one entry per exposed member) and the call-attempt
        // controller (one entry per recorded outcome). Without it, Reach
        // would silently lose its audit trail — fail loud at init time
        // instead.
        if (!function_exists('scrutiny')) {
            throw new \Exception('Scrutiny plugin is required but not active. Please install and activate Scrutiny before using Reach.');
        }

        if (!class_exists('Reach\Plugin')) {
            throw new \Exception('Reach\Plugin class not found.');
        }

        \Reach\Plugin::init($container);

        do_action('reach/loaded', \Reach\Plugin::getContainer());

    } catch (\Exception $e) {
        function_exists('wp_log')
            ? wp_log('reach')->error('Reach Plugin Initialization Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Reach Plugin Initialization Error: ' . $e->getMessage());

        if (is_admin()) {
            add_action('admin_notices', function() use ($e) {
                echo '<div class="notice notice-error is-dismissible"><p><strong>Reach Plugin Error:</strong> ' . esc_html($e->getMessage()) . '</p></div>';
            });
        }

    } catch (\Throwable $e) {
        function_exists('wp_log')
            ? wp_log('reach')->critical('Reach Plugin Fatal Error: ' . $e->getMessage(), ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()])
            : error_log('Reach Plugin Fatal Error: ' . $e->getMessage());
    }
}, 10);

// Show admin notice if Unity or Scrutiny is not active.
add_action('admin_notices', function() {
    if (!function_exists('unity') && !did_action('unity/loaded')) {
        echo '<div class="notice notice-warning is-dismissible"><p><strong>Reach:</strong> Requires the Unity plugin to be installed and activated.</p></div>';
    } elseif (!function_exists('scrutiny') && function_exists('unity')) {
        echo '<div class="notice notice-error is-dismissible"><p><strong>Reach:</strong> Requires the Scrutiny plugin to be installed and activated for GDPR audit logging.</p></div>';
    }
});

// Activation: ensure Unity and Scrutiny are present.
register_activation_hook(__FILE__, function () {
    if (!function_exists('scrutiny')) {
        deactivate_plugins(plugin_basename(__FILE__));
        wp_die(
            esc_html__('Reach requires the Unity and Scrutiny plugins to be installed and activated. Scrutiny must be active to ensure GDPR audit logging of personal-data access.', 'reach'),
            esc_html__('Plugin Activation Error', 'reach'),
            ['back_link' => true]
        );
    }

    // Ensure our frontend routes are reachable on first activation.
    \Reach\Frontend\PageRouter::addRewriteRules();
    flush_rewrite_rules();

    // Install/upgrade the call-attempts table. dbDelta is idempotent
    // and diffs against the live schema, so this is safe on every
    // activation including upgrades.
    global $wpdb;
    \Reach\CallAttempts\WpdbCallAttemptRepository::install($wpdb);
});

// Self-deactivate if Scrutiny gets deactivated while Reach is active —
// Reach can't honour its audit-logging promise without Scrutiny.
add_action('admin_init', function() {
    if (is_plugin_active(plugin_basename(__FILE__)) && !function_exists('scrutiny')) {
        deactivate_plugins(plugin_basename(__FILE__));
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error"><p><strong>Reach has been deactivated:</strong> The Scrutiny plugin is required for GDPR audit logging but is not active.</p></div>';
        });
    }
});

register_deactivation_hook(__FILE__, function () {
    flush_rewrite_rules();
});
