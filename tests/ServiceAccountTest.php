<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\Fcm\ServiceAccount;

/**
 * The service-account key file is pasted in by an admin, so the parse
 * has to be forgiving about what it does not care about and unforgiving
 * about what it does. Everything malformed collapses to null, which the
 * caller reads as "FCM is not configured" and falls back to polling.
 */

/** @param array<string, mixed> $overrides */
function json(array $overrides = []): string
{
    return (string) json_encode($overrides + [
        'type'          => 'service_account',
        'project_id'    => 'reach-alerts',
        'client_email'  => 'pusher@reach-alerts.iam.gserviceaccount.com',
        'private_key'   => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
        'token_uri'     => 'https://oauth2.googleapis.com/token',
    ]);
}

test('parses a well formed key file', function () {
    $account = ServiceAccount::fromJson(json());

    $this->assertNotNull($account);
    $this->assertSame('reach-alerts', $account->projectId);
    $this->assertSame('pusher@reach-alerts.iam.gserviceaccount.com', $account->clientEmail);
    $this->assertSame('https://oauth2.googleapis.com/token', $account->tokenUri);
});

/**
 * token_uri decides where a signed assertion gets POSTed, so a
 * value in the file is honoured only if it is one of Google's.
 * A key file is pasted in by an administrator, which makes this a
 * low-privilege-gain sink rather than a hole — but a doctored file
 * or a typo should not be able to redirect the exchange.
 */
test('refuses a token uri that is not googles and falls back', function (string $configured) {
    $account = ServiceAccount::fromJson(json(['token_uri' => $configured]));

    $this->assertNotNull($account, 'the file is still usable; only the endpoint is overridden');
    $this->assertSame('https://oauth2.googleapis.com/token', $account->tokenUri);
})->with('foreignTokenUris');

/** @return array<string, array{0: string}> */
dataset('foreignTokenUris', function (): array {
    return [
        'another host'      => ['https://evil.example.com/token'],
        'plain http'        => ['http://oauth2.googleapis.com/token'],
        'lookalike host'    => ['https://oauth2.googleapis.com.evil.example.com/token'],
        'host as userinfo'  => ['https://oauth2.googleapis.com@evil.example.com/token'],
        'not a url'         => ['token'],
        // An approved host is not enough: these are Google URLs that
        // are not token endpoints, and honouring one would break FCM
        // authentication while appearing to have passed a check.
        'approved host, wrong path' => ['https://oauth2.googleapis.com/not-a-token-endpoint'],
        'approved host, no path'    => ['https://oauth2.googleapis.com'],
        'approved host, subpath'    => ['https://oauth2.googleapis.com/token/../evil'],
        // Rejected because each changes where or how the request
        // goes without changing the host.
        'userinfo on approved host' => ['https://user:pass@oauth2.googleapis.com/token'],
        'port on approved host'     => ['https://oauth2.googleapis.com:8443/token'],
        'query on approved host'    => ['https://oauth2.googleapis.com/token?to=evil'],
        'fragment on approved host' => ['https://oauth2.googleapis.com/token#evil'],
    ];
});

/**
 * The two endpoints Google actually publishes are honoured as
 * given, so a key file naming either keeps working.
 */
test('honours googles own token endpoints', function (string $configured) {
    $account = ServiceAccount::fromJson(json(['token_uri' => $configured]));

    $this->assertNotNull($account);
    $this->assertSame($configured, $account->tokenUri);
})->with('googleTokenUris');

/** @return array<string, array{0: string}> */
dataset('googleTokenUris', function (): array {
    return [
        'oauth2.googleapis.com' => ['https://oauth2.googleapis.com/token'],
        'accounts.google.com'   => ['https://accounts.google.com/o/oauth2/token'],
    ];
});

test('ignores fields it does not use', function () {
    // The rest of the file is Google's own bookkeeping, so a key file
    // that gains a field in some future format must still parse.
    $account = ServiceAccount::fromJson(json([
        'private_key_id'            => 'abc123',
        'auth_uri'                  => 'https://accounts.google.com/o/oauth2/auth',
        'universe_domain'           => 'googleapis.com',
        'something_invented_in_2030' => ['nested' => true],
    ]));

    $this->assertNotNull($account);
    $this->assertSame('reach-alerts', $account->projectId);
});

test('falls back to the published token endpoint when absent', function () {
    $json = (string) json_encode([
        'project_id'   => 'reach-alerts',
        'client_email' => 'pusher@example.iam.gserviceaccount.com',
        'private_key'  => 'key',
    ]);

    $account = ServiceAccount::fromJson($json);

    $this->assertNotNull($account);
    $this->assertSame('https://oauth2.googleapis.com/token', $account->tokenUri);
});

/**
 * @return array<string, array{0: string}>
 */
dataset('unusableFiles', function (): array {
    return [
        'empty'              => [''],
        'whitespace only'    => ["  \n\t "],
        'not json'           => ['not json at all'],
        'json scalar'        => ['"a string"'],
        'json null'          => ['null'],
        'empty object'       => ['{}'],
        'no project_id'      => ['{"client_email":"a@b","private_key":"k"}'],
        'no client_email'    => ['{"project_id":"p","private_key":"k"}'],
        'no private_key'     => ['{"project_id":"p","client_email":"a@b"}'],
        'blank project_id'   => ['{"project_id":"  ","client_email":"a@b","private_key":"k"}'],
        'non-string fields'  => ['{"project_id":42,"client_email":"a@b","private_key":"k"}'],
    ];
});

test('anything unusable parses as not configured', function (string $json) {
    // Null covers empty configuration, malformed JSON and missing
    // fields alike — they all mean the same thing to the caller, and
    // the settings page is where an admin gets told which it was.
    $this->assertNull(ServiceAccount::fromJson($json));
})->with('unusableFiles');

test('surrounding whitespace is tolerated', function () {
    // Pasting from a file usually brings a trailing newline with it.
    $this->assertNotNull(ServiceAccount::fromJson("\n  " . json() . "  \n"));
});

test('send endpoint names the project', function () {
    $account = ServiceAccount::fromJson(json());

    $this->assertNotNull($account);
    $this->assertSame(
        'https://fcm.googleapis.com/v1/projects/reach-alerts/messages:send',
        $account->sendEndpoint(),
    );
});

test('send endpoint escapes the project id', function () {
    $account = ServiceAccount::fromJson(json(['project_id' => 'a/b?c']));

    $this->assertNotNull($account);
    $this->assertStringContainsString('projects/a%2Fb%3Fc/messages:send', $account->sendEndpoint());
});

test('fingerprint is stable and fixed length', function () {
    $a = ServiceAccount::fromJson(json());
    $b = ServiceAccount::fromJson(json());

    $this->assertNotNull($a);
    $this->assertNotNull($b);
    $this->assertSame($a->fingerprint(), $b->fingerprint());
    $this->assertSame(16, strlen($a->fingerprint()));
});

test('fingerprint changes when the account is replaced', function () {
    // This is what invalidates the cached access token immediately
    // when an admin swaps the service account, rather than leaving
    // the old project being pushed to for up to an hour.
    $original = ServiceAccount::fromJson(json());
    $newProject = ServiceAccount::fromJson(json(['project_id' => 'reach-alerts-2']));
    $newEmail = ServiceAccount::fromJson(json(['client_email' => 'other@example.iam.gserviceaccount.com']));

    $this->assertNotNull($original);
    $this->assertNotNull($newProject);
    $this->assertNotNull($newEmail);
    $this->assertNotSame($original->fingerprint(), $newProject->fingerprint());
    $this->assertNotSame($original->fingerprint(), $newEmail->fingerprint());
});

test('fingerprint does not depend on the private key', function () {
    // Hashing here is about producing a fixed-length cache key, not
    // concealment — but the secret still has no business being an
    // input to something that gets written into an option name.
    $a = ServiceAccount::fromJson(json());
    $b = ServiceAccount::fromJson(json(['private_key' => 'a completely different key']));

    $this->assertNotNull($a);
    $this->assertNotNull($b);
    $this->assertSame($a->fingerprint(), $b->fingerprint());
});
