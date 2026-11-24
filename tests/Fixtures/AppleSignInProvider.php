<?php

declare(strict_types=1);

namespace Reach\Tests\Fixtures;

use Guardian\AppleSignIn;
use Guardian\Providers\OAuthProvider;
use Guardian\VerifiedIdentity;

/**
 * An Apple provider as Guardian 1.3.0+ shapes it: a client-side provider
 * with verifySignIn(), answering whatever it was built with.
 *
 * {@see ConfigurableProvider} has no verifySignIn(), so it stands in for an
 * older Guardian loaded first by another plugin; this one drives the path
 * that finds the member by Apple's `sub`.
 */
final class AppleSignInProvider implements OAuthProvider
{
    /** Nonces verifySignIn() was asked to check, in order. */
    public array $nonces = [];

    public function __construct(private ?AppleSignIn $signIn)
    {
    }

    public function name(): string
    {
        return 'apple';
    }

    public function isServerSide(): bool
    {
        return false;
    }

    public function requiresPkce(): bool
    {
        return false;
    }

    public function getAuthorizationUrl(string $state, string $nonce, string $redirectUri, ?string $codeVerifier = null): string
    {
        throw new \LogicException('Client-side provider.');
    }

    public function handleCallback(string $code, string $nonce, string $redirectUri, ?string $codeVerifier = null): ?VerifiedIdentity
    {
        throw new \LogicException('Client-side provider.');
    }

    public function verifyIdToken(string $idToken, string $nonce): ?VerifiedIdentity
    {
        throw new \LogicException('The sub path must not fall back to verifyIdToken().');
    }

    public function verifySignIn(string $idToken, string $nonce): ?AppleSignIn
    {
        $this->nonces[] = $nonce;

        return $this->signIn;
    }
}
