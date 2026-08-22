<?php

declare(strict_types=1);

namespace Reach\Core;

if (!defined('ABSPATH')) {
    exit;
}

use Psr\Container\ContainerInterface;
use Reach\Admin\CallAttemptsPage;
use Reach\Admin\CallRequestsPage;
use Reach\Admin\DevicesPage;
use Reach\Admin\MemberSearchPage;
use Reach\Admin\SettingsPage;
use Reach\Alerts\AlertApi;
use Reach\Alerts\AlertContactRepository;
use Reach\Alerts\AlertDispatcher;
use Reach\Alerts\AlertRepository;
use Reach\Alerts\Fcm\FcmClient;
use Reach\Alerts\Transport\FcmTransport;
use Reach\Alerts\WpdbAlertContactRepository;
use Reach\Alerts\WpdbAlertRepository;
use Reach\Auth\DeviceCodeStore;
use Reach\Auth\DeviceRedirectValidator;
use Reach\Auth\DeviceTokenMinter;
use Reach\Auth\JwtVerifier;
use Reach\Auth\PasswordAuthenticator;
use Reach\Auth\PasswordCredentialRepository;
use Reach\Auth\PasswordPolicy;
use Reach\Auth\PasswordResetMailer;
use Reach\Auth\ProviderRegistry;
use Reach\Auth\Providers\AppleProvider;
use Reach\Auth\Providers\FacebookProvider;
use Reach\Auth\Providers\GoogleProvider;
use Reach\Auth\Providers\MicrosoftProvider;
use Reach\Auth\StateStore;
use Reach\Auth\WpdbPasswordCredentialRepository;
use Reach\CallAttempts\AttemptTokenMinter;
use Reach\CallAttempts\CallAttemptRepository;
use Reach\CallAttempts\ResponsivenessScorer;
use Reach\CallAttempts\WpdbCallAttemptRepository;
use Reach\CallRequests\CallRequestMailer;
use Reach\CallRequests\CallRequestRepository;
use Reach\CallRequests\WpdbCallRequestRepository;
use Reach\Devices\CurrentDevice;
use Reach\Devices\DeviceRepository;
use Reach\Devices\ResponderGate;
use Reach\Devices\WpdbDeviceRepository;
use Reach\Frontend\PageRouter;
use Reach\Geocoding\Geocoder;
use Reach\Geocoding\PostcodesIoGeocoder;
use Reach\Resolution\NearestMembersResolver;
use Reach\Rest\AlertController;
use Reach\Rest\CallAttemptController;
use Reach\Rest\CallRequestController;
use Reach\Rest\DeviceAuthController;
use Reach\Rest\NearestMembersController;
use Reach\Rest\OAuthController;
use Reach\Rest\PasswordAuthController;
use Reach\Session\CurrentSession;
use Reach\Session\SessionCookie;
use Reach\Session\SessionCsrf;
use Reach\Session\SessionRevocationList;
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
        $container->register(SessionRevocationList::class, fn() => new SessionRevocationList());
        $container->register(SessionCsrf::class, fn() => new SessionCsrf());
        // CurrentSession takes the member repository because a signed
        // cookie is not on its own an authorisation — see that class.
        $container->register(CurrentSession::class, fn(ContainerInterface $c) => new CurrentSession(
            $c->get(SessionCookie::class),
            $c->get(MemberRepository::class),
            $c->get(SessionRevocationList::class),
        ));
        $container->register(StateStore::class, fn() => new StateStore());
        $container->register(JwtVerifier::class, fn() => new JwtVerifier());

        // ── Hand: handsets, their credentials, and the alerts they ring for ──
        //
        // The gate is registered first and shared: it is the single
        // answer to "may this person use Hand?", consulted by both
        // enrolment paths, by every authenticated request, and by the
        // dispatcher deciding who an alert may reach. See ResponderGate
        // on why it is one object rather than a rule written out four
        // times.
        $container->register(ResponderGate::class, fn(ContainerInterface $c) => new ResponderGate(
            $c->get(MemberRepository::class),
        ));
        $container->register(DeviceTokenMinter::class, fn() => new DeviceTokenMinter());
        $container->register(DeviceCodeStore::class, fn() => new DeviceCodeStore());
        $container->register(DeviceRedirectValidator::class, fn() => new DeviceRedirectValidator());
        $container->register(DeviceRepository::class, function () {
            global $wpdb;
            return new WpdbDeviceRepository($wpdb);
        });
        $container->register(CurrentDevice::class, fn(ContainerInterface $c) => new CurrentDevice(
            $c->get(DeviceRepository::class),
            $c->get(DeviceTokenMinter::class),
            $c->get(ResponderGate::class),
        ));

        $container->register(AlertRepository::class, function () {
            global $wpdb;
            return new WpdbAlertRepository($wpdb);
        });
        $container->register(AlertContactRepository::class, function () {
            global $wpdb;
            return new WpdbAlertContactRepository($wpdb);
        });
        $container->register(FcmClient::class, fn() => new FcmClient());
        $container->register(FcmTransport::class, fn(ContainerInterface $c) => new FcmTransport(
            $c->get(FcmClient::class),
            $c->get(Settings::class),
            $c->get(DeviceRepository::class),
        ));

        // Transports are passed as a list so adding one — WNS for the
        // Windows head, say — is a change here and nowhere else.
        $container->register(AlertDispatcher::class, fn(ContainerInterface $c) => new AlertDispatcher(
            $c->get(AlertRepository::class),
            $c->get(AlertContactRepository::class),
            $c->get(DeviceRepository::class),
            $c->get(ResponderGate::class),
            [$c->get(FcmTransport::class)],
        ));
        $container->register(AlertApi::class, fn(ContainerInterface $c) => new AlertApi(
            $c->get(AlertDispatcher::class),
        ));

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

        // Email + password sign-in (the second auth path alongside OAuth).
        // The credential store is bound by interface so the authenticator
        // can be unit-tested against an in-memory fake.
        $container->register(PasswordCredentialRepository::class, function () {
            global $wpdb;
            return new WpdbPasswordCredentialRepository($wpdb);
        });
        $container->register(PasswordResetMailer::class, fn() => new PasswordResetMailer());
        $container->register(PasswordPolicy::class, fn() => new PasswordPolicy());
        $container->register(PasswordAuthenticator::class, fn(ContainerInterface $c) => new PasswordAuthenticator(
            $c->get(PasswordCredentialRepository::class),
            $c->get(MemberRepository::class),
            $c->get(PasswordResetMailer::class),
            $c->get(PasswordPolicy::class),
        ));

        // Call-attempt logging & responsiveness signal.
        $container->register(AttemptTokenMinter::class, fn() => new AttemptTokenMinter());
        $container->register(ResponsivenessScorer::class, fn() => new ResponsivenessScorer());
        $container->register(CallAttemptRepository::class, function () {
            global $wpdb;
            return new WpdbCallAttemptRepository($wpdb);
        });

        // Out-of-hours callback requests.
        $container->register(CallRequestRepository::class, function () {
            global $wpdb;
            return new WpdbCallRequestRepository($wpdb);
        });
        $container->register(CallRequestMailer::class, fn(ContainerInterface $c) => new CallRequestMailer(
            $c->get(Settings::class),
        ));

        // Geocoder + nearest-members resolver. The Geocoder interface
        // binds to the postcodes.io implementation; a test fake or a
        // future Google fallback can be slotted in without touching
        // the resolver. The configured place bias (a postcode or area
        // name) is read from Settings here and passed in; the geocoder
        // resolves it lazily on first place-name lookup so admins
        // without a bias configured pay no startup cost.
        $container->register(Geocoder::class, fn(ContainerInterface $c) => new PostcodesIoGeocoder(
            $c->get(Settings::class)->getPlaceBias(),
        ));
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
            $c->get(DeviceCodeStore::class),
            $c->get(DeviceRedirectValidator::class),
            $c->get(ResponderGate::class),
            $c->get(CurrentSession::class),
            $c->get(SessionRevocationList::class),
            $c->get(SessionCsrf::class),
        ));

        $container->register(RateLimiter::class, fn() => new RateLimiter());
        $container->register(PasswordAuthController::class, fn(ContainerInterface $c) => new PasswordAuthController(
            $c->get(PasswordAuthenticator::class),
            $c->get(SessionCookie::class),
            $c->get(MemberRepository::class),
            $c->get(AuditLogger::class),
            $c->get(RateLimiter::class),
        ));

        $container->register(NearestMembersController::class, fn(ContainerInterface $c) => new NearestMembersController(
            $c->get(NearestMembersResolver::class),
            $c->get(AuditLogger::class),
            $c->get(CurrentSession::class),
            $c->get(CallAttemptRepository::class),
            $c->get(ResponsivenessScorer::class),
            $c->get(AttemptTokenMinter::class),
            $c->get(RateLimiter::class),
            $c->get(SessionCsrf::class),
        ));

        $container->register(CallAttemptController::class, fn(ContainerInterface $c) => new CallAttemptController(
            $c->get(CallAttemptRepository::class),
            $c->get(AttemptTokenMinter::class),
            $c->get(CurrentSession::class),
            $c->get(AuditLogger::class),
            $c->get(SessionCsrf::class),
        ));

        $container->register(CallRequestController::class, fn(ContainerInterface $c) => new CallRequestController(
            $c->get(CallRequestRepository::class),
            $c->get(CurrentSession::class),
            $c->get(CallRequestMailer::class),
            $c->get(SessionCsrf::class),
        ));

        $container->register(DeviceAuthController::class, fn(ContainerInterface $c) => new DeviceAuthController(
            $c->get(DeviceRepository::class),
            $c->get(DeviceTokenMinter::class),
            $c->get(DeviceCodeStore::class),
            $c->get(DeviceRedirectValidator::class),
            $c->get(ResponderGate::class),
            $c->get(CurrentDevice::class),
            $c->get(PasswordAuthenticator::class),
            $c->get(ProviderRegistry::class),
            $c->get(StateStore::class),
            $c->get(RateLimiter::class),
            $c->get(AuditLogger::class),
        ));

        $container->register(AlertController::class, fn(ContainerInterface $c) => new AlertController(
            $c->get(AlertRepository::class),
            $c->get(AlertContactRepository::class),
            $c->get(CurrentDevice::class),
            $c->get(AuditLogger::class),
        ));

        // Frontend + admin.
        $container->register(PageRouter::class, fn(ContainerInterface $c) => new PageRouter(
            $c->get(CurrentSession::class),
            $c->get(SessionCsrf::class),
        ));

        $container->register(SettingsPage::class, fn(ContainerInterface $c) => new SettingsPage(
            $c->get(Settings::class),
        ));

        $container->register(CallAttemptsPage::class, fn(ContainerInterface $c) => new CallAttemptsPage(
            $c->get(CallAttemptRepository::class),
            $c->get(MemberViewFactory::class),
            $c->get(MemberRepository::class),
        ));

        $container->register(CallRequestsPage::class, fn(ContainerInterface $c) => new CallRequestsPage(
            $c->get(CallRequestRepository::class),
            $c->get(AuditLogger::class),
            $c->get(MemberRepository::class),
        ));

        $container->register(MemberSearchPage::class, fn(ContainerInterface $c) => new MemberSearchPage(
            $c->get(NearestMembersResolver::class),
            $c->get(MemberViewFactory::class),
        ));

        $container->register(DevicesPage::class, fn(ContainerInterface $c) => new DevicesPage(
            $c->get(DeviceRepository::class),
            $c->get(AlertRepository::class),
            $c->get(AlertApi::class),
            $c->get(MemberRepository::class),
        ));
    }
}
