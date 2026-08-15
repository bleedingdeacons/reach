<?php

declare(strict_types=1);

namespace Reach\Tests;

use Reach\Auth\DeviceRedirectValidator;

/**
 * The allow-list that decides where a one-time exchange code may be
 * delivered.
 *
 * This is the highest-consequence check in the device sign-in flow: a
 * redirect an attacker can influence is a code an attacker receives, and
 * the code buys a long-lived device token. So the tests below are
 * mostly about what must be *refused* — an allow-list is only as good as
 * the things it turns away.
 */
final class DeviceRedirectValidatorTest extends ReachTestCase
{
    private DeviceRedirectValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new DeviceRedirectValidator();
    }

    public function testAcceptsTheAppScheme(): void
    {
        $this->assertTrue($this->validator->isAllowed('hand://auth'));
    }

    public function testAppSchemeIsCaseInsensitive(): void
    {
        // Schemes and hosts are case-insensitive per RFC 3986, and
        // platforms differ on how they normalise them before handing the
        // URI over.
        $this->assertTrue($this->validator->isAllowed('HAND://AUTH'));
    }

    public function testAcceptsLoopbackOnAnyHighPort(): void
    {
        // RFC 8252 §7.3 requires *any* port be accepted: a desktop app
        // cannot reserve one in advance.
        $this->assertTrue($this->validator->isAllowed('http://127.0.0.1:1024/callback'));
        $this->assertTrue($this->validator->isAllowed('http://127.0.0.1:54321/callback'));
        $this->assertTrue($this->validator->isAllowed('http://[::1]:49152/cb'));
    }

    /**
     * @dataProvider refusedRedirects
     */
    public function testRefuses(string $uri, string $why): void
    {
        $this->assertFalse($this->validator->isAllowed($uri), $why);
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refusedRedirects(): array
    {
        return [
            'empty' => ['', 'An empty redirect is not a destination.'],
            'external https' => [
                'https://evil.example/callback',
                'The whole point of the allow-list is that arbitrary hosts are refused.',
            ],
            'app scheme, wrong host' => [
                'hand://evil',
                'Only the auth host is claimed by the app.',
            ],
            'localhost by name' => [
                'http://localhost:8080/cb',
                'RFC 8252 §8.3: localhost resolves through DNS and can be redirected; the literals cannot.',
            ],
            'loopback on a privileged port' => [
                'http://127.0.0.1:80/cb',
                'An unprivileged app cannot bind below 1024, so a request for one is not the app.',
            ],
            'loopback over https' => [
                'https://127.0.0.1:8080/cb',
                'Only the plain-http loopback form is expected.',
            ],
            'loopback with no port' => [
                'http://127.0.0.1/cb',
                'Without a port there is no listener to speak of.',
            ],
            'carries a fragment' => [
                'hand://auth#https://evil.example',
                'A fragment can smuggle a second URI past naive parsing.',
            ],
            'carries credentials' => [
                'hand://auth@evil.example',
                'Userinfo in the authority makes the real host the part after the @.',
            ],
            'app scheme with an existing query' => [
                'hand://auth?code=already',
                'We append the code ourselves; a supplied one could shadow it.',
            ],
            'a non-loopback address that looks close' => [
                'http://127.0.0.1.evil.example:8080/cb',
                'The host is evil.example, not the loopback.',
            ],
            'not a URI at all' => ['just-a-string', 'Nothing to redirect to.'],
        ];
    }

    public function testWithParamsAppendsToTheAppScheme(): void
    {
        // add_query_arg() is unreliable for a private scheme with no
        // path, which is exactly the shape the app registers — hence
        // the hand-rolled composition this asserts.
        $this->assertSame(
            'hand://auth?code=abc123',
            $this->validator->withParams('hand://auth', ['code' => 'abc123']),
        );
    }

    public function testWithParamsEncodesValues(): void
    {
        $result = $this->validator->withParams('hand://auth', ['error' => 'not eligible/&']);

        $this->assertStringStartsWith('hand://auth?error=', $result);
        $this->assertStringNotContainsString(' ', $result);
        $this->assertStringNotContainsString('&', substr($result, strlen('hand://auth?error=')));
    }

    public function testWithParamsLeavesUriAloneWhenThereIsNothingToAdd(): void
    {
        $this->assertSame('hand://auth', $this->validator->withParams('hand://auth', []));
    }
}
