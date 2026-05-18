<?php

namespace BootDesk\ChatSDK\Messenger\Tests;

use BootDesk\ChatSDK\Messenger\MessengerWebhookVerifier;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;

class MessengerWebhookVerifierTest extends TestCase
{
    private MessengerWebhookVerifier $verifier;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;
        $this->verifier = new MessengerWebhookVerifier('test_app_secret', 'test_verify_token', $this->factory);
    }

    public function test_valid_signature(): void
    {
        $body = '{"object":"page","entry":[]}';
        $hash = hash_hmac('sha256', $body, 'test_app_secret');
        $signature = "sha256={$hash}";

        $this->assertTrue($this->verifier->verifySignature($body, $signature));
    }

    public function test_invalid_signature(): void
    {
        $this->assertFalse($this->verifier->verifySignature('body', 'sha256=badhash'));
    }

    public function test_empty_signature(): void
    {
        $this->assertFalse($this->verifier->verifySignature('body', ''));
    }

    public function test_wrong_algo(): void
    {
        $this->assertFalse($this->verifier->verifySignature('body', 'sha1=something'));
    }

    public function test_verification_challenge_success(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhook?hub_mode=subscribe&hub_verify_token=test_verify_token&hub_challenge=challenge123');

        $response = $this->verifier->handleVerificationChallenge($request);

        $this->assertNotNull($response);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('challenge123', (string) $response->getBody());
    }

    public function test_verification_challenge_wrong_token(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhook?hub_mode=subscribe&hub_verify_token=wrong_token&hub_challenge=challenge123');

        $response = $this->verifier->handleVerificationChallenge($request);
        $this->assertNull($response);
    }

    public function test_verification_challenge_wrong_mode(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhook?hub_mode=invalid&hub_verify_token=test_verify_token&hub_challenge=challenge123');

        $response = $this->verifier->handleVerificationChallenge($request);
        $this->assertNull($response);
    }
}
