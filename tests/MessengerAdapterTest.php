<?php

namespace BootDesk\ChatSDK\Messenger\Tests;

use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Messenger\MessengerAdapter;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class MessengerAdapterTest extends TestCase
{
    private MessengerAdapter $adapter;

    private Psr17Factory $factory;

    protected function setUp(): void
    {
        $this->factory = new Psr17Factory;

        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $uri = (string) $request->getUri();
                $method = $request->getMethod();

                // POST me/messages → send message
                if ($method === 'POST' && str_contains($uri, 'me/messages')) {
                    $body = (string) $request->getBody();
                    $data = json_decode($body, true);
                    $hasTyping = isset($data['sender_action']);

                    if ($hasTyping) {
                        return $factory->createResponse(200)->withBody(
                            $factory->createStream(json_encode(['success' => true]))
                        );
                    }

                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'message_id' => 'mid.123456',
                            'recipient_id' => 'U999',
                        ]))
                    );
                }

                // GET me → bot identity
                if ($method === 'GET' && preg_match('#/me\?#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode(['id' => 'PAGE123', 'name' => 'MyBot']))
                    );
                }

                // GET {userId} → user profile
                if ($method === 'GET' && preg_match('#/\d+\?#', $uri)) {
                    return $factory->createResponse(200)->withBody(
                        $factory->createStream(json_encode([
                            'id' => '123456',
                            'first_name' => 'John',
                            'last_name' => 'Doe',
                        ]))
                    );
                }

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['id' => 'fallback']))
                );
            }
        };

        $this->adapter = new MessengerAdapter(
            pageAccessToken: 'test-page-token',
            appSecret: 'test_app_secret',
            verifyToken: 'test_verify_token',
            httpClient: $mockClient,
            psrFactory: $this->factory,
        );
    }

    // --- Construction ---

    public function test_get_name(): void
    {
        $this->assertSame('messenger', $this->adapter->getName());
    }

    public function test_initialize_sets_bot_user_id(): void
    {
        $chat = $this->createMock(Chat::class);
        $this->adapter->initialize($chat);
        $this->assertSame('PAGE123', $this->adapter->getBotUserId());
    }

    // --- Thread IDs ---

    public function test_thread_id_encode(): void
    {
        $id = $this->adapter->encodeThreadId(['recipientId' => '123456']);
        $this->assertSame('messenger:123456', $id);
    }

    public function test_thread_id_decode(): void
    {
        $decoded = $this->adapter->decodeThreadId('messenger:123456');
        $this->assertSame('123456', $decoded['recipientId']);
    }

    public function test_thread_id_decode_invalid(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->decodeThreadId('not-messenger');
    }

    public function test_thread_id_decode_empty_recipient(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->decodeThreadId('messenger:');
    }

    public function test_channel_id_is_thread_id(): void
    {
        $this->assertSame('messenger:123', $this->adapter->channelIdFromThreadId('messenger:123'));
    }

    // --- Webhook verification ---

    public function test_get_challenge_verification(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhook?hub_mode=subscribe&hub_verify_token=test_verify_token&hub_challenge=challenge_abc');

        $response = $this->adapter->verifyWebhook($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('challenge_abc', (string) $response->getBody());
    }

    public function test_get_challenge_wrong_token(): void
    {
        $request = $this->factory->createServerRequest('GET', '/webhook?hub_mode=subscribe&hub_verify_token=wrong&hub_challenge=abc');

        $response = $this->adapter->verifyWebhook($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    public function test_post_valid_signature(): void
    {
        $body = json_encode(['object' => 'page', 'entry' => []]);
        $hash = hash_hmac('sha256', $body, 'test_app_secret');

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-hub-signature-256', "sha256={$hash}")
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertNull($response);
    }

    public function test_post_invalid_signature(): void
    {
        $body = '{"object":"page"}';
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withHeader('x-hub-signature-256', 'sha256=badhash')
            ->withBody($this->factory->createStream($body));

        $response = $this->adapter->verifyWebhook($request);
        $this->assertSame(403, $response->getStatusCode());
    }

    // --- Parse webhook ---

    public function test_parse_webhook_message(): void
    {
        $this->adapter->initialize($this->createMock(Chat::class));

        $body = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE1',
                    'messaging' => [
                        [
                            'sender' => ['id' => '123456'],
                            'recipient' => ['id' => 'PAGE1'],
                            'timestamp' => 1234567890,
                            'message' => [
                                'mid' => 'mid.abc',
                                'text' => 'Hello bot',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('mid.abc', $message->id);
        $this->assertSame('messenger:123456', $message->threadId);
        $this->assertSame('123456', $message->author->id);
        $this->assertSame('Hello bot', $message->text);
        $this->assertTrue($message->isDM);
    }

    public function test_parse_webhook_skips_echo(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'messaging' => [
                        [
                            'sender' => ['id' => 'PAGE1'],
                            'recipient' => ['id' => '123456'],
                            'message' => ['mid' => 'm1', 'text' => 'echo', 'is_echo' => true],
                        ],
                        [
                            'sender' => ['id' => '123456'],
                            'recipient' => ['id' => 'PAGE1'],
                            'message' => ['mid' => 'm2', 'text' => 'real msg'],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $message = $this->adapter->parseWebhook($request);
        $this->assertSame('m2', $message->id);
        $this->assertSame('real msg', $message->text);
    }

    public function test_parse_webhook_invalid_payload(): void
    {
        $this->expectException(AdapterException::class);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('{"object":"not_page"}'));

        $this->adapter->parseWebhook($request);
    }

    public function test_parse_webhook_no_user_message(): void
    {
        $this->expectException(AdapterException::class);

        $body = json_encode([
            'object' => 'page',
            'entry' => [
                ['messaging' => [
                    ['sender' => ['id' => 'P1'], 'message' => ['mid' => 'm1', 'is_echo' => true]],
                ]],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $this->adapter->parseWebhook($request);
    }

    // --- Message operations ---

    public function test_post_message(): void
    {
        $sent = $this->adapter->postMessage('messenger:123456', PostableMessage::text('Hello'));

        $this->assertSame('mid.123456', $sent->id);
        $this->assertSame('messenger:123456', $sent->threadId);
    }

    public function test_post_message_with_card_template(): void
    {
        $card = Card::make()
            ->header('Deploy Ready')
            ->section(fn ($s) => $s->text('Build passed'))
            ->actions([Button::primary('Deploy', 'deploy')]);

        $sent = $this->adapter->postMessage('messenger:123456', PostableMessage::card($card));
        $this->assertSame('mid.123456', $sent->id);
    }

    public function test_post_message_with_card_text_fallback(): void
    {
        $card = Card::make()->section(fn ($s) => $s->text('Just text'));
        $sent = $this->adapter->postMessage('messenger:123456', PostableMessage::card($card));
        $this->assertSame('mid.123456', $sent->id);
    }

    public function test_stream_collects_and_posts(): void
    {
        $sent = $this->adapter->stream('messenger:123456', ['Hello ', 'World']);
        $this->assertNotNull($sent);
        $this->assertSame('mid.123456', $sent->id);
    }

    public function test_stream_empty_returns_null(): void
    {
        $this->assertNull($this->adapter->stream('messenger:123456', []));
    }

    // --- Unsupported operations ---

    public function test_edit_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->editMessage('messenger:123', 'm1', PostableMessage::text('x'));
    }

    public function test_delete_message_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->deleteMessage('messenger:123', 'm1');
    }

    public function test_add_reaction_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->addReaction('messenger:123', 'm1', '👍');
    }

    public function test_remove_reaction_throws(): void
    {
        $this->expectException(AdapterException::class);
        $this->adapter->removeReaction('messenger:123', 'm1', '👍');
    }

    // --- Supported operations ---

    public function test_start_typing(): void
    {
        $this->adapter->startTyping('messenger:123456');
        $this->assertTrue(true);
    }

    public function test_fetch_messages_returns_empty(): void
    {
        $result = $this->adapter->fetchMessages('messenger:123');
        $this->assertCount(0, $result->messages);
    }

    public function test_fetch_thread(): void
    {
        $info = $this->adapter->fetchThread('messenger:123');
        $this->assertSame('messenger:123', $info->id);
    }

    public function test_fetch_channel_info(): void
    {
        $info = $this->adapter->fetchChannelInfo('messenger:123456');
        $this->assertSame('messenger:123456', $info->id);
        $this->assertSame('John Doe', $info->name);
        $this->assertTrue($info->isPrivate);
    }

    public function test_get_user(): void
    {
        $user = $this->adapter->getUser('123456');
        $this->assertSame('123456', $user->id);
        $this->assertSame('John Doe', $user->name);
    }

    public function test_open_dm(): void
    {
        $threadId = $this->adapter->openDM('123456');
        $this->assertSame('messenger:123456', $threadId);
    }

    public function test_get_format_converter(): void
    {
        $this->assertNotNull($this->adapter->getFormatConverter());
    }

    public function test_disconnect_is_noop(): void
    {
        $this->adapter->disconnect();
        $this->assertTrue(true);
    }

    public function test_create_response_returns_null(): void
    {
        $this->assertNull($this->adapter->createResponse());
    }

    public function test_post_message_truncates_long_text(): void
    {
        $factory = new Psr17Factory;
        $captured = [];

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode(['recipient_id' => '123', 'message_id' => 'mid.999']))
                );
            }
        };

        $adapter = new MessengerAdapter(
            pageAccessToken: 'test_token',
            appSecret: 'test_secret',
            verifyToken: 'verify_me',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $longText = str_repeat('a', 3000);
        $adapter->postMessage('messenger:123:456', PostableMessage::text($longText));

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);
        $this->assertStringEndsWith('...', $body['message']['text']);
        $this->assertSame(2000, strlen($body['message']['text']));
    }

    public function test_api_call_throws_authentication_exception_on_auth_error(): void
    {
        $factory = new Psr17Factory;
        $mockClient = new class implements ClientInterface
        {
            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $f = new Psr17Factory;

                return $f->createResponse(200)->withBody(
                    $f->createStream(json_encode(['error' => ['type' => 'OAuthException', 'code' => 190, 'message' => 'Invalid access token']]))
                );
            }
        };

        $adapter = new MessengerAdapter(
            pageAccessToken: 'bad-token',
            appSecret: 'secret',
            verifyToken: 'verify',
            httpClient: $mockClient,
            psrFactory: $factory,
        );

        $this->expectException(AuthenticationException::class);
        $adapter->postMessage('messenger:123:456', PostableMessage::text('test'));
    }

    public function test_parse_status_delivery(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => '12345'],
                    'recipient' => ['id' => '67890'],
                    'timestamp' => 1700000000,
                    'delivery' => ['mids' => ['mid.1', 'mid.2'], 'watermark' => 1700000000],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/messenger')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('delivered', $result['type']);
        $this->assertSame(['mid.1', 'mid.2'], $result['messageIds']);
        $this->assertSame('12345', $result['userId']);
    }

    public function test_parse_status_read(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => '12345'],
                    'recipient' => ['id' => '67890'],
                    'timestamp' => 1700000001,
                    'read' => ['watermark' => 1700000001],
                ]],
            ]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/messenger')
            ->withBody($this->factory->createStream($body));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('read', $result['type']);
        $this->assertSame('12345', $result['userId']);
        $this->assertSame(1700000001, $result['timestamp']);
    }

    public function test_parse_status_not_page_object(): void
    {
        $body = json_encode(['object' => 'other']);

        $request = $this->factory->createServerRequest('POST', '/webhooks/messenger')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseStatus($request));
    }

    public function test_parse_status_no_messaging(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [['messaging' => []]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhooks/messenger')
            ->withBody($this->factory->createStream($body));

        $this->assertNull($this->adapter->parseStatus($request));
    }
}
