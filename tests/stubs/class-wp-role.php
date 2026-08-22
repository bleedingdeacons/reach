<?php

/**
 * Stand-in for WordPress's WP_Role.
 *
 * The shared stubs answer get_role() with a plain stdClass, which is
 * enough for code that only reads a role's name. It is not enough for
 * {@see \Reach\Core\Capabilities::ensureAssigned()}, which asks a role
 * what it has and grants what it lacks, and which type-checks the object
 * it is handed — the real get_role() returns WP_Role or null, and
 * accepting anything object-shaped instead would be a weaker guard in
 * production for the sake of a test.
 *
 * So the class exists here and the tests hand back a real instance. Same
 * reasoning as the WP_List_Table stand-in beside it: a wp-admin- or
 * roles-layer class the shared package does not cover, kept local until
 * a second plugin needs it.
 *
 * `capabilities` is public and the two methods are the whole surface
 * used; a test can seed the array to model a role that already has a
 * capability, and read it back to see what was granted.
 */

declare(strict_types=1);

if (class_exists('WP_Role')) {
    return;
}

class WP_Role
{
    /** @var array<string, bool> */
    public $capabilities;

    /** @var string */
    public $name;

    /** @param array<string, bool> $capabilities */
    public function __construct(string $role = 'administrator', array $capabilities = [])
    {
        $this->name = $role;
        $this->capabilities = $capabilities;
    }

    public function has_cap(string $cap): bool
    {
        return !empty($this->capabilities[$cap]);
    }

    public function add_cap(string $cap, bool $grant = true): void
    {
        $this->capabilities[$cap] = $grant;
    }

    public function remove_cap(string $cap): void
    {
        unset($this->capabilities[$cap]);
    }
}
