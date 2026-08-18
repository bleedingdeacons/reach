<?php

declare(strict_types=1);

namespace Reach\Tests;

use BleedingDeacons\WpMocks\WpState;
use Reach\Alerts\Fcm\FcmClient;
use Reach\Alerts\Fcm\ServiceAccount;
use Reach\Tests\ReachTestCase;
use WP_Error;

/**
 * Drives the real JWT-assertion → access-token → send pipeline against a
 * stubbed HTTP layer, with a genuine RSA keypair so the assertion is
 * actually signed rather than mocked past.
 *
 * The behaviour worth pinning down is the failure handling. A push that
 * cannot be sent is not an error the alert path should propagate — the
 * alert is already stored and the handset collects it on its next poll —
 * so every path here has to report false and keep going rather than
 * throw into a caller whose job is to reach the other handsets.
 */
final class FcmClientTest extends ReachTestCase
{
    private ServiceAccount $account;

    /** @var array<int, array{url: string, args: array<string, mixed>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        WpState::$transients = [];
        $this->calls = [];

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        // Same reasoning as JwtVerifierTest: openssl_pkey_new() needs an
        // openssl.cnf, which some Windows PHP builds ship without. That is
        // a missing local prerequisite, not a defect. CI has one.
        if ($res === false) {
            self::markTestSkipped('openssl_pkey_new() unavailable: ' . (openssl_error_string() ?: 'unknown error'));
        }

        openssl_pkey_export($res, $privateKey);

        $account = ServiceAccount::fromJson((string) json_encode([
            'project_id'   => 'reach-alerts',
            'client_email' => 'pusher@reach-alerts.iam.gserviceaccount.com',
            'private_key'  => $privateKey,
            // Google's other published token endpoint, rather than an
            // arbitrary host: ServiceAccount now pins token_uri to
            // Google's own, so a made-up one would be replaced by the
            // default and this fixture would stop distinguishing
            // anything. Deliberately NOT the default, so a client that
            // hard-coded oauth2.googleapis.com would still fail these.
            'token_uri'    => 'https://accounts.google.com/o/oauth2/token',
        ]));

        $this->assertNotNull($account);
        $this->account = $account;
    }

    /**
     * Answer the token endpoint and the send endpoint separately, and
     * record every call so the request shape can be asserted on.
     *
     * @param array<string, mixed>|WP_Error $tokenResponse
     * @param array<string, mixed>|WP_Error $sendResponse
     */
    private function stubEndpoints(mixed $tokenResponse, mixed $sendResponse = null): void
    {
        $calls = &$this->calls;
        $this->stubHttp(static function (string $url, array $args = []) use (&$calls, $tokenResponse, $sendResponse) {
            $calls[] = ['url' => $url, 'args' => $args];

            if (str_contains($url, '/token')) {
                return $tokenResponse;
            }

            return $sendResponse ?? ['response' => ['code' => 200], 'body' => '{"name":"projects/x/messages/1"}'];
        });
    }

    /** @param array<string, mixed> $body */
    private static function ok(array $body, int $code = 200): array
    {
        return ['response' => ['code' => $code], 'body' => (string) json_encode($body)];
    }

    public function testSendMintsATokenThenPostsTheMessage(): void
    {
        $this->stubEndpoints(self::ok(['access_token' => 'ya29.token']));
        $client = new FcmClient();

        $this->assertTrue($client->send($this->account, ['token' => 'device-token']));

        $this->assertCount(2, $this->calls);
        $this->assertSame('https://accounts.google.com/o/oauth2/token', $this->calls[0]['url']);
        $this->assertSame(
            'https://fcm.googleapis.com/v1/projects/reach-alerts/messages:send',
            $this->calls[1]['url'],
        );
        $this->assertSame(
            'Bearer ya29.token',
            $this->calls[1]['args']['headers']['Authorization'],
        );
        $this->assertSame(
            '{"message":{"token":"device-token"}}',
            $this->calls[1]['args']['body'],
        );
    }

    public function testTheAssertionIsARealSignedRs256Jwt(): void
    {
        // RFC 7523: the assertion is what proves possession of the service
        // account's private key, so it has to be genuinely signed — the
        // one thing a mock could hide.
        $this->stubEndpoints(self::ok(['access_token' => 'ya29.token']));
        (new FcmClient())->send($this->account, []);

        $body = $this->calls[0]['args']['body'];
        $this->assertSame('urn:ietf:params:oauth:grant-type:jwt-bearer', $body['grant_type']);

        $parts = explode('.', $body['assertion']);
        $this->assertCount(3, $parts);

        $header = json_decode($this->decodeSegment($parts[0]), true);
        $this->assertSame(['alg' => 'RS256', 'typ' => 'JWT'], $header);

        $claims = json_decode($this->decodeSegment($parts[1]), true);
        $this->assertSame('pusher@reach-alerts.iam.gserviceaccount.com', $claims['iss']);
        $this->assertSame('https://www.googleapis.com/auth/firebase.messaging', $claims['scope']);
        // The audience must be the token endpoint the assertion is sent
        // to, or Google rejects it.
        $this->assertSame('https://accounts.google.com/o/oauth2/token', $claims['aud']);
        $this->assertSame(3600, $claims['exp'] - $claims['iat']);
    }

    public function testTheAccessTokenIsCachedShortOfItsRealLifetime(): void
    {
        // Google issues tokens for 3600s. Caching for 3300 means one is
        // never presented in its last five minutes, covering clock skew
        // and a send that starts just before expiry.
        $this->stubEndpoints(self::ok(['access_token' => 'ya29.token']));
        $client = new FcmClient();

        $client->send($this->account, []);
        $client->send($this->account, []);

        // Three calls, not four: one token request, then two sends.
        $this->assertCount(3, $this->calls);
        $this->assertStringContainsString('/token', $this->calls[0]['url']);
        $this->assertStringNotContainsString('/token', $this->calls[1]['url']);
        $this->assertStringNotContainsString('/token', $this->calls[2]['url']);
    }

    public function testTheCacheKeyIsScopedToTheCredentials(): void
    {
        // Replacing the service account in settings must invalidate the
        // cached token immediately rather than pushing to the old project
        // for up to an hour.
        $this->stubEndpoints(self::ok(['access_token' => 'ya29.token']));
        $client = new FcmClient();
        $client->send($this->account, []);

        $this->assertCount(1, WpState::$transients);
        $key = array_key_first(WpState::$transients);
        $this->assertStringStartsWith('reach_fcm_token_', (string) $key);
        $this->assertSame(
            'reach_fcm_token_' . $this->account->fingerprint(),
            $key,
        );
    }

    public function testACachedTokenIsUsedWithoutContactingTheTokenEndpoint(): void
    {
        WpState::$transients['reach_fcm_token_' . $this->account->fingerprint()] = 'ya29.cached';
        $this->stubEndpoints(new WP_Error('should_not_happen', 'token endpoint was contacted'));

        $this->assertTrue((new FcmClient())->send($this->account, []));

        $this->assertCount(1, $this->calls);
        $this->assertSame('Bearer ya29.cached', $this->calls[0]['args']['headers']['Authorization']);
    }

    public function testATransportFailureOnTheTokenRequestIsReportedNotThrown(): void
    {
        $this->stubEndpoints(new WP_Error('http_request_failed', 'Connection timed out'));

        $this->assertFalse((new FcmClient())->send($this->account, []));
        // No send was attempted without a token.
        $this->assertCount(1, $this->calls);
    }

    public function testATokenResponseWithoutAnAccessTokenIsRefused(): void
    {
        $this->stubEndpoints(self::ok(['error' => 'invalid_grant'], 400));

        $this->assertFalse((new FcmClient())->send($this->account, []));
        $this->assertSame([], WpState::$transients);
    }

    public function testANonJsonTokenResponseIsRefused(): void
    {
        $this->stubEndpoints(['response' => ['code' => 502], 'body' => '<html>Bad Gateway</html>']);

        $this->assertFalse((new FcmClient())->send($this->account, []));
    }

    public function testATokenThatIsNotAStringIsRefused(): void
    {
        $this->stubEndpoints(self::ok(['access_token' => ['not', 'a', 'string']]));

        $this->assertFalse((new FcmClient())->send($this->account, []));
    }

    public function testATransportFailureOnTheSendIsReportedNotThrown(): void
    {
        $this->stubEndpoints(
            self::ok(['access_token' => 'ya29.token']),
            new WP_Error('http_request_failed', 'Connection reset'),
        );

        $this->assertFalse((new FcmClient())->send($this->account, []));
    }

    public function testARejectionFromFcmIsReportedAsFailure(): void
    {
        $this->stubEndpoints(
            self::ok(['access_token' => 'ya29.token']),
            ['response' => ['code' => 404], 'body' => '{"error":{"status":"NOT_FOUND"}}'],
        );

        $this->assertFalse((new FcmClient())->send($this->account, []));
    }

    public function testAnUnreadablePrivateKeyFailsClosed(): void
    {
        $account = ServiceAccount::fromJson((string) json_encode([
            'project_id'   => 'reach-alerts',
            'client_email' => 'pusher@example.iam.gserviceaccount.com',
            'private_key'  => 'not a PEM key',
        ]));
        $this->assertNotNull($account);
        $this->stubEndpoints(self::ok(['access_token' => 'ya29.token']));

        $this->assertFalse((new FcmClient())->send($account, []));
        // Nothing was sent anywhere — the assertion could not be built.
        $this->assertSame([], $this->calls);
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function statuses(): array
    {
        return [
            'app uninstalled'   => [404, true],
            'token rotated'     => [403, true],
            'bad request'       => [400, false],
            'unauthorised'      => [401, false],
            'rate limited'      => [429, false],
            'server error'      => [500, false],
            'accepted'          => [200, false],
        ];
    }

    /**
     * @dataProvider statuses
     */
    public function testDeadTokenStatusesAreTheOnesWorthClearingAStoredTokenFor(int $status, bool $dead): void
    {
        $this->assertSame($dead, (new FcmClient())->isDeadTokenStatus($status));
    }

    private function decodeSegment(string $segment): string
    {
        $padded = strtr($segment, '-_', '+/');
        $padded .= str_repeat('=', (4 - strlen($padded) % 4) % 4);

        return (string) base64_decode($padded, true);
    }
}
