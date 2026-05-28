<?php

namespace BootDesk\ChatSDK\Messenger\Tests;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Cards\Button;
use BootDesk\ChatSDK\Core\Cards\Card;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
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
        $this->assertSame('PAGE1', $message->originId);
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

    public function test_post_with_attachment_and_text_returns_additional_messages(): void
    {
        $factory = $this->factory;
        $callCount = 0;

        $mockClient = new class($factory, $callCount) implements ClientInterface
        {
            private Psr17Factory $factory;

            private int $callCount;

            public function __construct(Psr17Factory $factory, int &$callCount)
            {
                $this->factory = $factory;
                $this->callCount = &$callCount;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $uri = (string) $request->getUri();
                $method = $request->getMethod();

                // Handle GET /me for initialization
                if ($method === 'GET' && preg_match('#/me\?#', $uri)) {
                    return $this->factory->createResponse(200)->withBody(
                        $this->factory->createStream(json_encode(['id' => 'PAGE123', 'name' => 'MyBot']))
                    );
                }

                $this->callCount++;

                // Return incrementing message IDs so we can identify each call
                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'message_id' => 'mid.'.$this->callCount,
                        'recipient_id' => 'U999',
                    ]))
                );
            }
        };

        $adapter = new MessengerAdapter(
            pageAccessToken: 'test-page-token',
            appSecret: 'test_app_secret',
            verifyToken: 'test_verify_token',
            httpClient: $mockClient,
            psrFactory: $factory,
        );
        $adapter->initialize($this->createMock(Chat::class));

        $sent = $adapter->postMessage(
            'messenger:123456',
            new PostableMessage(
                content: 'Check this out',
                attachments: [new Attachment(url: 'https://example.com/photo.jpg', type: 'image')],
            )
        );

        // First call = attachment, second call = text follow-up
        $this->assertSame('mid.1', $sent->id);
        $this->assertCount(1, $sent->additionalMessages);
        $this->assertSame('mid.2', $sent->additionalMessages[0]->id);
        $this->assertSame('messenger:123456', $sent->additionalMessages[0]->threadId);
    }

    public function test_post_with_attachment_only_no_text_no_additional_messages(): void
    {
        $factory = $this->factory;
        $callCount = 0;

        $mockClient = new class($factory, $callCount) implements ClientInterface
        {
            private Psr17Factory $factory;

            private int $callCount;

            public function __construct(Psr17Factory $factory, int &$callCount)
            {
                $this->factory = $factory;
                $this->callCount = &$callCount;
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $uri = (string) $request->getUri();
                $method = $request->getMethod();

                // Handle GET /me for initialization
                if ($method === 'GET' && preg_match('#/me\?#', $uri)) {
                    return $this->factory->createResponse(200)->withBody(
                        $this->factory->createStream(json_encode(['id' => 'PAGE123', 'name' => 'MyBot']))
                    );
                }

                $this->callCount++;

                return $this->factory->createResponse(200)->withBody(
                    $this->factory->createStream(json_encode([
                        'message_id' => 'mid.'.$this->callCount,
                        'recipient_id' => 'U999',
                    ]))
                );
            }
        };

        $adapter = new MessengerAdapter(
            pageAccessToken: 'test-page-token',
            appSecret: 'test_app_secret',
            verifyToken: 'test_verify_token',
            httpClient: $mockClient,
            psrFactory: $factory,
        );
        $adapter->initialize($this->createMock(Chat::class));

        // Empty content string — no text follow-up
        $sent = $adapter->postMessage(
            'messenger:123456',
            new PostableMessage(
                content: '',
                attachments: [new Attachment(url: 'https://example.com/photo.jpg', type: 'image')],
            )
        );

        $this->assertSame('mid.1', $sent->id);
        $this->assertSame([], $sent->additionalMessages);
    }

    public function test_post_includes_raw_response(): void
    {
        $sent = $this->adapter->postMessage('messenger:123456', PostableMessage::text('Hello'));

        $this->assertNotNull($sent->raw);
        $this->assertIsArray($sent->raw);
        $this->assertSame('mid.123456', $sent->raw['message_id']);
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

    // --- ParseAction (postback handling) ---

    public function test_parse_action_with_encoded_postback(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => '100000000000001',
                'time' => 1772998084000,
                'messaging' => [[
                    'sender' => ['id' => '200000000000001'],
                    'recipient' => ['id' => '100000000000001'],
                    'timestamp' => 1772998084000,
                    'postback' => [
                        'title' => 'Say Hello',
                        'payload' => 'chat:{"a":"hello"}',
                    ],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('hello', $result['actionId']);
        $this->assertNull($result['value']);
        $this->assertSame('messenger:200000000000001', $result['threadId']);
        $this->assertSame('200000000000001', $result['userId']);
        $this->assertFalse($result['isBot']);
        $this->assertFalse($result['isMe']);
        $this->assertNull($result['triggerId']);
        $this->assertNull($result['callbackQueryId']);
    }

    public function test_parse_action_with_encoded_postback_with_value(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => '100000000000001',
                'time' => 1772998104000,
                'messaging' => [[
                    'sender' => ['id' => '200000000000001'],
                    'recipient' => ['id' => '100000000000001'],
                    'timestamp' => 1772998104000,
                    'postback' => [
                        'mid' => 'm_POSTBACK_001',
                        'title' => 'Buy Now',
                        'payload' => 'chat:{"a":"buy","v":"sku_123"}',
                    ],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('buy', $result['actionId']);
        $this->assertSame('sku_123', $result['value']);
        $this->assertSame('m_POSTBACK_001', $result['messageId']);
    }

    public function test_parse_action_with_legacy_payload(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => '100000000000001',
                'time' => 1772998094000,
                'messaging' => [[
                    'sender' => ['id' => '200000000000001'],
                    'recipient' => ['id' => '100000000000001'],
                    'timestamp' => 1772998094000,
                    'postback' => [
                        'title' => 'Get Started',
                        'payload' => 'GET_STARTED',
                    ],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('GET_STARTED', $result['actionId']);
        $this->assertSame('GET_STARTED', $result['value']);
    }

    public function test_parse_action_no_postback_returns_null(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => 'U1'],
                    'message' => ['mid' => 'm1', 'text' => 'hi'],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $this->assertNull($this->adapter->parseAction($request));
    }

    public function test_parse_action_wrong_object_returns_null(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('{"object":"not_page"}'));

        $this->assertNull($this->adapter->parseAction($request));
    }

    public function test_acknowledge_action_returns_null(): void
    {
        $this->assertNull($this->adapter->acknowledgeAction('some_id'));
        $this->assertNull($this->adapter->acknowledgeAction(null));
    }

    // --- Attachment type preservation ---

    public function test_extract_rich_attachment_types(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => '100000000000001',
                'time' => 1772998124000,
                'messaging' => [[
                    'sender' => ['id' => '200000000000001'],
                    'recipient' => ['id' => '100000000000001'],
                    'timestamp' => 1772998124000,
                    'message' => [
                        'mid' => 'm_RICH_001',
                        'attachments' => [
                            [
                                'type' => 'fallback',
                                'payload' => [
                                    'url' => 'https://example.com/link',
                                    'title' => 'Check this out',
                                ],
                            ],
                            [
                                'type' => 'reel',
                                'payload' => [
                                    'url' => 'https://example.com/reel',
                                    'title' => 'My Reel',
                                    'reel_video_id' => 12345,
                                ],
                            ],
                            [
                                'type' => 'post',
                                'payload' => [
                                    'url' => 'https://example.com/post',
                                    'title' => 'My Post',
                                    'id' => 67890,
                                ],
                            ],
                            [
                                'type' => 'appointment_booking',
                                'payload' => [
                                    'booking_id' => 'booking_001',
                                    'status' => 'confirmed',
                                    'start_time' => 1739612400,
                                    'end_time' => 1739616000,
                                    'timezone' => 'America/Los_Angeles',
                                ],
                            ],
                            [
                                'type' => 'image',
                                'payload' => [
                                    'url' => 'https://example.com/sticker',
                                    'sticker_id' => 369239263222822,
                                ],
                            ],
                        ],
                    ],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $message = $this->adapter->parseWebhook($request);
        $attachments = $message->attachments;

        $this->assertCount(5, $attachments);

        // fallback
        $this->assertSame('fallback', $attachments[0]->type);
        $this->assertSame('https://example.com/link', $attachments[0]->url);
        $this->assertSame(['title' => 'Check this out'], $attachments[0]->fetchMetadata);

        // reel
        $this->assertSame('reel', $attachments[1]->type);
        $this->assertSame('https://example.com/reel', $attachments[1]->url);
        $this->assertSame(['title' => 'My Reel', 'reel_video_id' => 12345], $attachments[1]->fetchMetadata);

        // post
        $this->assertSame('post', $attachments[2]->type);
        $this->assertSame('https://example.com/post', $attachments[2]->url);
        $this->assertSame(['title' => 'My Post', 'id' => 67890], $attachments[2]->fetchMetadata);

        // appointment_booking
        $this->assertSame('appointment_booking', $attachments[3]->type);
        $this->assertNull($attachments[3]->url);
        $this->assertSame([
            'booking_id' => 'booking_001',
            'status' => 'confirmed',
            'start_time' => 1739612400,
            'end_time' => 1739616000,
            'timezone' => 'America/Los_Angeles',
        ], $attachments[3]->fetchMetadata);

        // image with sticker_id
        $this->assertSame('image', $attachments[4]->type);
        $this->assertSame('https://example.com/sticker', $attachments[4]->url);
        $this->assertSame(['sticker_id' => 369239263222822], $attachments[4]->fetchMetadata);
    }

    // --- reply_to in outgoing messages ---

    public function test_post_message_with_reply_to(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.reply_001',
                        'recipient_id' => 'U999',
                    ]))
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

        $adapter->postMessage(
            'messenger:123456',
            new PostableMessage(
                content: 'Hello reply',
                replyToMessageId: 'm_ORIGINAL_001',
            ),
        );

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);

        $this->assertArrayHasKey('reply_to', $body);
        $this->assertSame(['mid' => 'm_ORIGINAL_001'], $body['reply_to']);
        $this->assertSame('Hello reply', $body['message']['text']);
    }

    public function test_post_message_without_reply_to(): void
    {
        $captured = [];
        $factory = new Psr17Factory;

        $mockClient = new class($captured) implements ClientInterface
        {
            public function __construct(private array &$captured) {}

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                $factory = new Psr17Factory;
                $this->captured[] = $request;

                return $factory->createResponse(200)->withBody(
                    $factory->createStream(json_encode([
                        'message_id' => 'mid.001',
                        'recipient_id' => 'U999',
                    ]))
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

        $adapter->postMessage('messenger:123456', PostableMessage::text('No reply'));

        $this->assertCount(1, $captured);
        $body = json_decode((string) $captured[0]->getBody(), true);

        $this->assertArrayNotHasKey('reply_to', $body);
    }

    // --- SentMessage.timestamp ---

    public function test_post_message_timestamp_is_time(): void
    {
        $before = (string) time();
        $sent = $this->adapter->postMessage('messenger:123456', PostableMessage::text('Hello'));
        $after = (string) (time() + 1);

        $this->assertGreaterThanOrEqual($before, $sent->timestamp);
        $this->assertLessThanOrEqual($after, $sent->timestamp);
    }

    // --- parseReaction messageId ---

    public function test_parse_reaction_uses_mid_for_message_id(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => '100000000000001',
                'time' => 1772998064000,
                'messaging' => [[
                    'sender' => ['id' => '200000000000001'],
                    'recipient' => ['id' => '100000000000001'],
                    'timestamp' => 1772998064000,
                    'reaction' => [
                        'mid' => 'm_FAKE_MSG_ID_001',
                        'action' => 'react',
                        'emoji' => '❤',
                        'reaction' => 'love',
                    ],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('m_FAKE_MSG_ID_001', $result['messageId']);
        $this->assertSame('❤', $result['emoji']);
        $this->assertTrue($result['added']);
    }

    public function test_parse_reaction_unreact(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'id' => '100000000000001',
                'time' => 1772998074000,
                'messaging' => [[
                    'sender' => ['id' => '200000000000001'],
                    'recipient' => ['id' => '100000000000001'],
                    'timestamp' => 1772998074000,
                    'reaction' => [
                        'mid' => 'm_FAKE_MSG_ID_001',
                        'action' => 'unreact',
                        'emoji' => '❤',
                        'reaction' => 'love',
                    ],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('m_FAKE_MSG_ID_001', $result['messageId']);
        $this->assertFalse($result['added']);
    }

    // --- parseStatus watermark as int ---

    public function test_parse_status_delivery_watermark_is_int(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => '12345'],
                    'recipient' => ['id' => '67890'],
                    'timestamp' => 1700000000,
                    'delivery' => [
                        'mids' => ['mid.1'],
                        'watermark' => 1700000000,
                    ],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertIsInt($result['timestamp']);
        $this->assertSame(1700000000, $result['timestamp']);
    }

    public function test_parse_status_read_watermark_is_int(): void
    {
        $payload = [
            'object' => 'page',
            'entry' => [[
                'messaging' => [[
                    'sender' => ['id' => '12345'],
                    'recipient' => ['id' => '67890'],
                    'timestamp' => 1700000001,
                    'read' => ['watermark' => 1700000001],
                ]],
            ]],
        ];

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertIsInt($result['timestamp']);
        $this->assertSame(1700000001, $result['timestamp']);
    }

    // --- Fixture-based integration test ---

    public function test_fixture_first_message(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $payload = $fixture['firstMessage'];
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($payload)));

        $message = $this->adapter->parseWebhook($request);

        $this->assertSame('m_FAKE_MSG_ID_001', $message->id);
        $this->assertSame('messenger:200000000000001', $message->threadId);
        $this->assertSame('What is BootDesk?', $message->text);
        $this->assertTrue($message->isDM);
    }

    public function test_fixture_delivery(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['deliveryConfirmation'])));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('delivered', $result['type']);
        $this->assertSame(['m_SENT_MSG_001'], $result['messageIds']);
        $this->assertIsInt($result['timestamp']);
    }

    public function test_fixture_read(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['readConfirmation'])));

        $result = $this->adapter->parseStatus($request);

        $this->assertNotNull($result);
        $this->assertSame('read', $result['type']);
        $this->assertIsInt($result['timestamp']);
    }

    public function test_fixture_reaction_added(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['reactionAdded'])));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertSame('m_FAKE_MSG_ID_001', $result['messageId']);
        $this->assertSame('❤', $result['emoji']);
        $this->assertTrue($result['added']);
        $this->assertSame('messenger:200000000000001', $result['threadId']);
    }

    public function test_fixture_reaction_removed(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['reactionRemoved'])));

        $result = $this->adapter->parseReaction($request);

        $this->assertNotNull($result);
        $this->assertFalse($result['added']);
    }

    public function test_fixture_postback_encoded(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['postbackEncoded'])));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('hello', $result['actionId']);
        $this->assertSame('messenger:200000000000001', $result['threadId']);
    }

    public function test_fixture_postback_legacy(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['postbackLegacy'])));

        $result = $this->adapter->parseAction($request);

        $this->assertNotNull($result);
        $this->assertSame('GET_STARTED', $result['actionId']);
        $this->assertSame('GET_STARTED', $result['value']);
    }

    public function test_fixture_image_attachment(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['imageAttachment'])));

        $message = $this->adapter->parseWebhook($request);

        $this->assertCount(1, $message->attachments);
        $this->assertSame('image', $message->attachments[0]->type);
        $this->assertSame('https://example.com/image.jpg', $message->attachments[0]->url);
    }

    public function test_fixture_echo_skipped(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['echoMessage'])));

        $this->expectException(AdapterException::class);
        $this->adapter->parseWebhook($request);
    }

    public function test_fixture_rich_attachments(): void
    {
        $fixture = json_decode(
            file_get_contents(__DIR__.'/fixtures/messenger.json'),
            true
        );

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream(json_encode($fixture['richAttachmentTypes'])));

        $message = $this->adapter->parseWebhook($request);
        $attachments = $message->attachments;

        $this->assertCount(6, $attachments);

        $this->assertSame('fallback', $attachments[0]->type);
        $this->assertSame('reel', $attachments[1]->type);
        $this->assertSame('post', $attachments[2]->type);
        $this->assertSame('appointment_booking', $attachments[3]->type);
        $this->assertSame('template', $attachments[4]->type);
        $this->assertSame('image', $attachments[5]->type);

        // Verify metadata
        $this->assertSame(['title' => 'Check this out'], $attachments[0]->fetchMetadata);
        $this->assertSame(['title' => 'My Reel', 'reel_video_id' => 12345], $attachments[1]->fetchMetadata);
        $this->assertArrayHasKey('product', $attachments[4]->fetchMetadata);
        $this->assertSame(['sticker_id' => 369239263222822], $attachments[5]->fetchMetadata);
    }

    public function test_parse_batched_multiple_messages(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE1',
                    'time' => 1000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'USER_A'],
                            'recipient' => ['id' => 'PAGE1'],
                            'timestamp' => 1000,
                            'message' => ['mid' => 'm1', 'text' => 'first'],
                        ],
                        [
                            'sender' => ['id' => 'USER_B'],
                            'recipient' => ['id' => 'PAGE1'],
                            'timestamp' => 1001,
                            'message' => ['mid' => 'm2', 'text' => 'second'],
                        ],
                    ],
                ],
                [
                    'id' => 'PAGE2',
                    'time' => 1002,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'USER_C'],
                            'recipient' => ['id' => 'PAGE2'],
                            'timestamp' => 1002,
                            'message' => ['mid' => 'm3', 'text' => 'third'],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $events = $this->adapter->parseBatchedWebhook($request);

        $this->assertCount(3, $events);
        $this->assertSame('message', $events[0]->type);
        $this->assertSame('messenger:USER_A', $events[0]->threadId);
        $this->assertSame('first', $events[0]->payload->text);
        $this->assertSame('PAGE1', $events[0]->originId);
        $this->assertSame('PAGE1', $events[0]->payload->originId);
        $this->assertSame('message', $events[1]->type);
        $this->assertSame('messenger:USER_B', $events[1]->threadId);
        $this->assertSame('second', $events[1]->payload->text);
        $this->assertSame('PAGE1', $events[1]->originId);
        $this->assertSame('message', $events[2]->type);
        $this->assertSame('messenger:USER_C', $events[2]->threadId);
        $this->assertSame('third', $events[2]->payload->text);
        $this->assertSame('PAGE2', $events[2]->originId);
        $this->assertSame('PAGE2', $events[2]->payload->originId);
    }

    public function test_parse_batched_mixed_event_types(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE1',
                    'time' => 1000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'USER_A'],
                            'recipient' => ['id' => 'PAGE1'],
                            'timestamp' => 1000,
                            'message' => ['mid' => 'm1', 'text' => 'hello'],
                        ],
                        [
                            'sender' => ['id' => 'USER_B'],
                            'recipient' => ['id' => 'PAGE1'],
                            'timestamp' => 1001,
                            'postback' => ['payload' => 'chat:{"a":"test","v":"1"}', 'mid' => 'pb1'],
                        ],
                        [
                            'sender' => ['id' => 'USER_C'],
                            'recipient' => ['id' => 'PAGE1'],
                            'timestamp' => 1002,
                            'reaction' => ['reaction' => '😊', 'action' => 'react', 'mid' => 'r1'],
                        ],
                        [
                            'sender' => ['id' => 'USER_D'],
                            'recipient' => ['id' => 'PAGE1'],
                            'timestamp' => 1003,
                            'delivery' => ['mids' => ['d1'], 'watermark' => 1003],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $events = $this->adapter->parseBatchedWebhook($request);

        $this->assertCount(4, $events);

        $this->assertSame('message', $events[0]->type);
        $this->assertSame('hello', $events[0]->payload->text);
        $this->assertSame('PAGE1', $events[0]->originId);

        $this->assertSame('action', $events[1]->type);
        $this->assertSame('test', $events[1]->payload['actionId']);
        $this->assertSame('1', $events[1]->payload['value']);
        $this->assertSame('PAGE1', $events[1]->originId);

        $this->assertSame('reaction', $events[2]->type);
        $this->assertSame('😊', $events[2]->payload['emoji']);
        $this->assertTrue($events[2]->payload['added']);
        $this->assertSame('PAGE1', $events[2]->originId);

        $this->assertSame('status', $events[3]->type);
        $this->assertSame('delivered', $events[3]->payload['type']);
        $this->assertSame('PAGE1', $events[3]->originId);
    }

    public function test_parse_batched_skips_echo_messages(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [
                [
                    'id' => 'PAGE1',
                    'time' => 1000,
                    'messaging' => [
                        [
                            'sender' => ['id' => 'PAGE1'],
                            'recipient' => ['id' => 'USER_A'],
                            'message' => ['mid' => 'm1', 'text' => 'echo', 'is_echo' => true],
                        ],
                        [
                            'sender' => ['id' => 'USER_A'],
                            'recipient' => ['id' => 'PAGE1'],
                            'message' => ['mid' => 'm2', 'text' => 'real'],
                        ],
                    ],
                ],
            ],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $events = $this->adapter->parseBatchedWebhook($request);

        $this->assertCount(1, $events);
        $this->assertSame('real', $events[0]->payload->text);
    }

    public function test_parse_batched_invalid_object(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('{"object":"not_page"}'));

        $this->assertSame([], $this->adapter->parseBatchedWebhook($request));
    }

    public function test_parse_batched_empty_payload(): void
    {
        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream('{}'));

        $this->assertSame([], $this->adapter->parseBatchedWebhook($request));
    }

    public function test_parse_batched_no_messaging(): void
    {
        $body = json_encode([
            'object' => 'page',
            'entry' => [['id' => 'PAGE1', 'time' => 1000]],
        ]);

        $request = $this->factory->createServerRequest('POST', '/webhook')
            ->withBody($this->factory->createStream($body));

        $this->assertSame([], $this->adapter->parseBatchedWebhook($request));
    }
}
