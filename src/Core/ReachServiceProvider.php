<?php

declare(strict_types=1);

namespace Reach\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Compass\Resolution\NearestMembersResolver;
use Psr\Container\ContainerInterface;
use Reach\Admin\SettingsPage;
use Reach\Auth\JwtVerifier;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\Providers\AppleProvider;
use Reach\Auth\Providers\GoogleProvider;
use Reach\Auth\Providers\MicrosoftProvider;
use Reach\Auth\StateStore;
use Reach\Frontend\PageRouter;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Reach\Session\CurrentSession;
use Reach\Session\SessionCookie;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Core\Interfaces\Container;

/**
 * Register Reach services into Unity's container.
 *
 * Follows Compass's provider pattern. The three OAuth providers are
 * registered individually and also assembled into a ProviderRegistry
 * so the OAuth controller can look one up by name.
 */
final class ReachServiceProvider
{
    public function register(Container $container): void
    {
        // Core helpers.
        $container->register(Settings::class, fn() => new Settings());
        $container->register(SessionCookie::class, fn() => new SessionCookie());
        $container->register(CurrentSession::class, fn(ContainerInterface $c) => new CurrentSession($c->get(SessionCookie::class)));
        $container->register(StateStore::class, fn() => new StateStore());
        $container->register(JwtVerifier::class, fn() => new JwtVerifier());

        // Providers.
        $container->register(GoogleProvider::class, fn(ContainerInterface $c) => new GoogleProvider(
            $c->get(Settings::class),
            $c->get(JwtVerifier::class),
        ));
        $container->register(MicrosoftProvider::class, fn(ContainerInterface $c) => new MicrosoftProvider(
            $c->get(Settings::class),
            $c->get(JwtVerifier::class),
        ));
        $container->register(AppleProvider::class, fn(ContainerInterface $c) => new AppleProvider(
            $c->get(Settings::class),
            $c->get(JwtVerifier::class),
        ));

        $container->register(ProviderRegistry::class, function (ContainerInterface $c) {
            $registry = new ProviderRegistry();
            $registry->register($c->get(GoogleProvider::class));
            $registry->register($c->get(MicrosoftProvider::class));
            $registry->register($c->get(AppleProvider::class));
            return $registry;
        });

        // REST controllers.
        $container->register(OAuthController::class, fn(ContainerInterface $c) => new OAuthController(
            $c->get(ProviderRegistry::class),
            $c->get(StateStore::class),
            $c->get(SessionCookie::class),
        ));

        $container->register(NearestMembersController::class, fn(ContainerInterface $c) => new NearestMembersController(
            $c->get(NearestMembersResolver::class),
            $c->get(AuditLogger::class),
            $c->get(CurrentSession::class),
            $c->get(Settings::class),
        ));

        // Frontend + admin.
        $container->register(PageRouter::class, fn(ContainerInterface $c) => new PageRouter(
            $c->get(CurrentSession::class),
        ));

        $container->register(SettingsPage::class, fn(ContainerInterface $c) => new SettingsPage(
            $c->get(Settings::class),
        ));
    }
}
