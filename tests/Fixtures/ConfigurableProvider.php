<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Reach\Auth\Providers\OAuthProvider;
use Reach\Auth\VerifiedIdentity;

/**
 * Configurable OAuthProvider double: fixed authorisation URL, and a preset
 * identity (or null) returned from both handleCallback and verifyIdToken.
 */
final class ConfigurableProvider implements OAuthProvider
{
    public function __construct(
        private string $providerName,
        private bool $serverSide,
        private ?VerifiedIdentity $identity,
    ) {
    }

    public function name(): string
    {
        return $this->providerName;
    }

    public function isServerSide(): bool
    {
        return $this->serverSide;
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $redirectUri, ?string $codeVerifier = null): string
    {
        return 'https://provider.test/auth';
    }

    public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity
    {
        return $this->identity;
    }

    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
    {
        return $this->identity;
    }
}
