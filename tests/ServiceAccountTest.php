<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Alerts\Fcm\ServiceAccount;
use Reach\Tests\ReachTestCase;

/**
 * The service-account key file is pasted in by an admin, so the parse
 * has to be forgiving about what it does not care about and unforgiving
 * about what it does. Everything malformed collapses to null, which the
 * caller reads as "FCM is not configured" and falls back to polling.
 */
final class ServiceAccountTest extends ReachTestCase
{
    /** @param array<string, mixed> $overrides */
    private function json(array $overrides = []): string
    {
        return (string) json_encode($overrides + [
            'type'          => 'service_account',
            'project_id'    => 'reach-alerts',
            'client_email'  => 'pusher@reach-alerts.iam.gserviceaccount.com',
            'private_key'   => "-----BEGIN PRIVATE KEY-----\nMIIB\n-----END PRIVATE KEY-----\n",
            'token_uri'     => 'https://oauth2.googleapis.com/token',
        ]);
    }

    public function testParsesAWellFormedKeyFile(): void
    {
        $account = ServiceAccount::fromJson($this->json());

        $this->assertNotNull($account);
        $this->assertSame('reach-alerts', $account->projectId);
        $this->assertSame('pusher@reach-alerts.iam.gserviceaccount.com', $account->clientEmail);
        $this->assertSame('https://oauth2.googleapis.com/token', $account->tokenUri);
    }

    /**
     * token_uri decides where a signed assertion gets POSTed, so a
     * value in the file is honoured only if it is one of Google's.
     * A key file is pasted in by an administrator, which makes this a
     * low-privilege-gain sink rather than a hole — but a doctored file
     * or a typo should not be able to redirect the exchange.
     *
     * @dataProvider foreignTokenUris
     */
    public function testRefusesATokenUriThatIsNotGooglesAndFallsBack(string $configured): void
    {
        $account = ServiceAccount::fromJson($this->json(['token_uri' => $configured]));

        $this->assertNotNull($account, 'the file is still usable; only the endpoint is overridden');
        $this->assertSame('https://oauth2.googleapis.com/token', $account->tokenUri);
    }

    /** @return array<string, array{0: string}> */
    public static function foreignTokenUris(): array
    {
        return [
            'another host'      => ['https://evil.example.com/token'],
            'plain http'        => ['http://oauth2.googleapis.com/token'],
            'lookalike host'    => ['https://oauth2.googleapis.com.evil.example.com/token'],
            'host as userinfo'  => ['https://oauth2.googleapis.com@evil.example.com/token'],
            'not a url'         => ['token'],
        ];
    }

    /**
     * The two endpoints Google actually publishes are honoured as
     * given, so a key file naming either keeps working.
     *
     * @dataProvider googleTokenUris
     */
    public function testHonoursGooglesOwnTokenEndpoints(string $configured): void
    {
        $account = ServiceAccount::fromJson($this->json(['token_uri' => $configured]));

        $this->assertNotNull($account);
        $this->assertSame($configured, $account->tokenUri);
    }

    /** @return array<string, array{0: string}> */
    public static function googleTokenUris(): array
    {
        return [
            'oauth2.googleapis.com' => ['https://oauth2.googleapis.com/token'],
            'accounts.google.com'   => ['https://accounts.google.com/o/oauth2/token'],
        ];
    }

    public function testIgnoresFieldsItDoesNotUse(): void
    {
        // The rest of the file is Google's own bookkeeping, so a key file
        // that gains a field in some future format must still parse.
        $account = ServiceAccount::fromJson($this->json([
            'private_key_id'            => 'abc123',
            'auth_uri'                  => 'https://accounts.google.com/o/oauth2/auth',
            'universe_domain'           => 'googleapis.com',
            'something_invented_in_2030' => ['nested' => true],
        ]));

        $this->assertNotNull($account);
        $this->assertSame('reach-alerts', $account->projectId);
    }

    public function testFallsBackToThePublishedTokenEndpointWhenAbsent(): void
    {
        $json = (string) json_encode([
            'project_id'   => 'reach-alerts',
            'client_email' => 'pusher@example.iam.gserviceaccount.com',
            'private_key'  => 'key',
        ]);

        $account = ServiceAccount::fromJson($json);

        $this->assertNotNull($account);
        $this->assertSame('https://oauth2.googleapis.com/token', $account->tokenUri);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function unusableFiles(): array
    {
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
    }

    /**
     * @dataProvider unusableFiles
     */
    public function testAnythingUnusableParsesAsNotConfigured(string $json): void
    {
        // Null covers empty configuration, malformed JSON and missing
        // fields alike — they all mean the same thing to the caller, and
        // the settings page is where an admin gets told which it was.
        $this->assertNull(ServiceAccount::fromJson($json));
    }

    public function testSurroundingWhitespaceIsTolerated(): void
    {
        // Pasting from a file usually brings a trailing newline with it.
        $this->assertNotNull(ServiceAccount::fromJson("\n  " . $this->json() . "  \n"));
    }

    public function testSendEndpointNamesTheProject(): void
    {
        $account = ServiceAccount::fromJson($this->json());

        $this->assertNotNull($account);
        $this->assertSame(
            'https://fcm.googleapis.com/v1/projects/reach-alerts/messages:send',
            $account->sendEndpoint(),
        );
    }

    public function testSendEndpointEscapesTheProjectId(): void
    {
        $account = ServiceAccount::fromJson($this->json(['project_id' => 'a/b?c']));

        $this->assertNotNull($account);
        $this->assertStringContainsString('projects/a%2Fb%3Fc/messages:send', $account->sendEndpoint());
    }

    public function testFingerprintIsStableAndFixedLength(): void
    {
        $a = ServiceAccount::fromJson($this->json());
        $b = ServiceAccount::fromJson($this->json());

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertSame(16, strlen($a->fingerprint()));
    }

    public function testFingerprintChangesWhenTheAccountIsReplaced(): void
    {
        // This is what invalidates the cached access token immediately
        // when an admin swaps the service account, rather than leaving
        // the old project being pushed to for up to an hour.
        $original = ServiceAccount::fromJson($this->json());
        $newProject = ServiceAccount::fromJson($this->json(['project_id' => 'reach-alerts-2']));
        $newEmail = ServiceAccount::fromJson($this->json(['client_email' => 'other@example.iam.gserviceaccount.com']));

        $this->assertNotNull($original);
        $this->assertNotNull($newProject);
        $this->assertNotNull($newEmail);
        $this->assertNotSame($original->fingerprint(), $newProject->fingerprint());
        $this->assertNotSame($original->fingerprint(), $newEmail->fingerprint());
    }

    public function testFingerprintDoesNotDependOnThePrivateKey(): void
    {
        // Hashing here is about producing a fixed-length cache key, not
        // concealment — but the secret still has no business being an
        // input to something that gets written into an option name.
        $a = ServiceAccount::fromJson($this->json());
        $b = ServiceAccount::fromJson($this->json(['private_key' => 'a completely different key']));

        $this->assertNotNull($a);
        $this->assertNotNull($b);
        $this->assertSame($a->fingerprint(), $b->fingerprint());
    }
}
