<?php

declare(strict_types=1);

namespace Reach\Session;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Request-scoped accessor for the current session.
 *
 * The underlying cookie is read once per request and cached, so
 * multiple callers (a REST permission_callback and a template
 * redirect, for example) don't each pay the HMAC verification cost.
 */
final class CurrentSession
{
    private bool $resolved = false;
    private ?Session $cached = null;

    public function __construct(
        private readonly SessionCookie $cookie,
    ) {
    }

    public function get(): ?Session
    {
        if (!$this->resolved) {
            $this->cached = $this->cookie->read();
            $this->resolved = true;
        }
        return $this->cached;
    }

    public function isAuthenticated(): bool
    {
        return $this->get() !== null;
    }

    /**
     * Force a re-read on the next call — used after the OAuth callback
     * sets a fresh cookie within the same request.
     */
    public function invalidate(): void
    {
        $this->resolved = false;
        $this->cached = null;
    }
}
