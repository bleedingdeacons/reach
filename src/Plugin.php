<?php

declare(strict_types=1);

namespace Reach;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Admin\SettingsPage;
use Reach\Core\ReachServiceProvider;
use Reach\Frontend\PageRouter;
use Reach\Rest\CallAttemptController;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Psr\Container\ContainerInterface;
use Unity\Core\Interfaces\Container;

use RuntimeException;

use function add_action;
use function is_admin;

/**
 * Main Reach Plugin Class
 *
 * Wires Reach services into Unity's container, registers the two REST
 * controllers (OAuth + nearest-members), the frontend rewrite rules
 * for /reach/signin and /reach/find, and the admin settings page for
 * OAuth client credentials.
 */
class Plugin
{
    use \Reach\Logger\HasLogger;

    protected static function logChannel(): string
    {
        return 'reach';
    }

    private static ?ContainerInterface $container = null;
    private static bool $initialized = false;

    public static function init(Container $unityContainer): void
    {
        if (self::$initialized) {
            return;
        }

        self::$container = $unityContainer;

        (new ReachServiceProvider())->register($unityContainer);

        self::$initialized = true;

        // REST controllers.
        self::$container->get(OAuthController::class)->register();
        self::$container->get(NearestMembersController::class)->register();
        self::$container->get(CallAttemptController::class)->register();

        // Frontend pages (rewrite rules + template_redirect).
        self::$container->get(PageRouter::class)->register();

        // Admin settings page.
        if (is_admin()) {
            self::$container->get(SettingsPage::class)->register();
        }

        self::logDebug('Initialised', ['version' => defined('REACH_VERSION') ? REACH_VERSION : 'unknown']);
    }

    public static function getContainer(): ContainerInterface
    {
        if (self::$container === null) {
            throw new RuntimeException('Reach Plugin not initialized');
        }
        return self::$container;
    }
}
