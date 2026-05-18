<?php

declare(strict_types=1);

namespace Reach\Auth;

if (!defined('ABSPATH')) {
    exit;
}

use Reach\Auth\Providers\OAuthProvider;

/**
 * Lookup-by-name for OAuth providers.
 *
 * Bound in the container with all configured providers (currently
 * Google, Microsoft, Apple, Facebook) preregistered. Splitting this
 * out from the controller means the REST handlers stay focused on
 * HTTP concerns and don't have to know which providers exist; adding
 * another provider is a service-provider edit, not a controller edit.
 */
final class ProviderRegistry
{
    /** @var array<string, OAuthProvider> */
    private array $providers = [];

    public function register(OAuthProvider $provider): void
    {
        $this->providers[strtolower($provider->name())] = $provider;
    }

    public function get(string $name): ?OAuthProvider
    {
        return $this->providers[strtolower($name)] ?? null;
    }

    /**
     * @return string[]
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
