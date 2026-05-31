<?php

declare(strict_types=1);

namespace Reach\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Reach\Admin\CallAttemptsPage;
use Reach\Admin\SettingsPage;
use Reach\Auth\JwtVerifier;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\Providers\AppleProvider;
use Reach\Auth\Providers\FacebookProvider;
use Reach\Auth\Providers\GoogleProvider;
use Reach\Auth\Providers\MicrosoftProvider;
use Reach\Auth\StateStore;
use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\CallAttempts\ResponsivenessScorer;
use Reach\CallAttempts\WpdbCallAttemptRepository;
use Reach\Frontend\PageRouter;
use Reach\Geocoding\Geocoder;
use Reach\Geocoding\PostcodesIoGeocoder;
use Reach\Resolution\NearestMembersResolver;
use Reach\Rest\CallAttemptController;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Reach\Session\CurrentSession;
use Reach\Session\SessionCookie;
use Scrutiny\Audit\Interfaces\AuditLogger;
use Unity\Core\Interfaces\Container;
use Unity\Members\Interfaces\MemberRepository;
use Unity\Members\Interfaces\MemberViewFactory;

/**
 * Register Reach services into Unity's container.
 *
 * Each OAuth provider is registered individually and also assembled
 * into a ProviderRegistry so the OAuth controller can look one up by
 * name. The Geocoder is bound by interface so a test fake or
 * alternative implementation can be slotted in without touching the
 * resolver.
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
        $container->register(FacebookProvider::class, fn(ContainerInterface $c) => new FacebookProvider(
            $c->get(Settings::class),
            $c->get(JwtVerifier::class),
        ));

        $container->register(ProviderRegistry::class, function (ContainerInterface $c) {
            $registry = new ProviderRegistry();
            $registry->register($c->get(GoogleProvider::class));
            $registry->register($c->get(MicrosoftProvider::class));
            $registry->register($c->get(AppleProvider::class));
            $registry->register($c->get(FacebookProvider::class));
            return $registry;
        });

        // Call-attempt logging & responsiveness signal.
        $container->register(AttemptTokenMinter::class, fn() => new AttemptTokenMinter());
        $container->register(ResponsivenessScorer::class, fn() => new ResponsivenessScorer());
        $container->register(CallAttemptRepository::class, function () {
            global $wpdb;
            return new WpdbCallAttemptRepository($wpdb);
        });

        // Geocoder + nearest-members resolver. The Geocoder interface
        // binds to the postcodes.io implementation; a test fake or a
        // future Google fallback can be slotted in without touching
        // the resolver.
        $container->register(Geocoder::class, fn() => new PostcodesIoGeocoder());
        $container->register(NearestMembersResolver::class, fn(ContainerInterface $c) => new NearestMembersResolver(
            $c->get(MemberRepository::class),
            $c->get(Geocoder::class),
        ));

        // REST controllers.
        $container->register(OAuthController::class, fn(ContainerInterface $c) => new OAuthController(
            $c->get(ProviderRegistry::class),
            $c->get(StateStore::class),
            $c->get(SessionCookie::class),
            $c->get(MemberRepository::class),
        ));

        $container->register(NearestMembersController::class, fn(ContainerInterface $c) => new NearestMembersController(
            $c->get(NearestMembersResolver::class),
            $c->get(AuditLogger::class),
            $c->get(CurrentSession::class),
            $c->get(CallAttemptRepository::class),
            $c->get(ResponsivenessScorer::class),
            $c->get(AttemptTokenMinter::class),
            $c->get(MemberRepository::class),
        ));

        $container->register(CallAttemptController::class, fn(ContainerInterface $c) => new CallAttemptController(
            $c->get(CallAttemptRepository::class),
            $c->get(AttemptTokenMinter::class),
            $c->get(CurrentSession::class),
            $c->get(AuditLogger::class),
            $c->get(MemberRepository::class),
        ));

        // Frontend + admin.
        $container->register(PageRouter::class, fn(ContainerInterface $c) => new PageRouter(
            $c->get(CurrentSession::class),
        ));

        $container->register(SettingsPage::class, fn(ContainerInterface $c) => new SettingsPage(
            $c->get(Settings::class),
        ));

        $container->register(CallAttemptsPage::class, fn(ContainerInterface $c) => new CallAttemptsPage(
            $c->get(CallAttemptRepository::class),
            $c->get(MemberViewFactory::class),
            $c->get(MemberRepository::class),
        ));
    }
}
