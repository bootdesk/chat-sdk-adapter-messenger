<?php

namespace BootDesk\ChatSDK\Messenger;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesActions;
use BootDesk\ChatSDK\Core\Contracts\HandlesBatchedWebhooks;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesSlashCommands;
use BootDesk\ChatSDK\Core\Contracts\HandlesStatuses;
use BootDesk\ChatSDK\Core\Contracts\HasAuthorInfo;
use BootDesk\ChatSDK\Core\Contracts\RequiresAsyncResponse;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\LocalizationType;
use BootDesk\ChatSDK\Core\LocalizationValue;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\EmojiResolver;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use BootDesk\ChatSDK\Core\WebhookEvent;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MessengerAdapter implements Adapter, HandlesActions, HandlesBatchedWebhooks, HandlesReactions, HandlesSlashCommands, HandlesStatuses, HasAuthorInfo, RequiresAsyncResponse
{
    protected ?string $botUserId = null;

    protected MessengerFormatConverter $formatConverter;

    protected MessengerWebhookVerifier $webhookVerifier;

    protected FileUploadConverter $fileUploadConverter;

    protected EmojiResolver $emojiResolver;

    public function __construct(
        protected readonly string $pageAccessToken,
        protected readonly ClientInterface $httpClient,
        protected readonly string $appSecret,
        string $verifyToken,
        protected readonly string $apiVersion = 'v25.0',
        protected readonly string $apiUrl = 'https://graph.facebook.com',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
        ?EmojiResolver $emojiResolver = null,
    ) {
        $this->formatConverter = new MessengerFormatConverter;
        $this->webhookVerifier = new MessengerWebhookVerifier($appSecret, $verifyToken, $psrFactory);
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;
        $this->emojiResolver = $emojiResolver ?? EmojiResolver::default();
    }

    public function getName(): string
    {
        return 'messenger';
    }

    public function getBotUserId(): ?string
    {
        return $this->botUserId;
    }

    public function verifyWebhook(ServerRequestInterface $request): ?ResponseInterface
    {
        // GET — verification challenge
        if ($request->getMethod() === 'GET') {
            $challenge = $this->webhookVerifier->handleVerificationChallenge($request);

            return $challenge ?? $this->jsonError(403, 'Verification failed');
        }

        // POST — verify HMAC signature
        $body = (string) $request->getBody();
        $signature = $request->getHeaderLine('x-hub-signature-256');

        if (! $this->webhookVerifier->verifySignature($body, $signature)) {
            return $this->jsonError(403, 'Invalid signature');
        }

        return null;
    }

    public function parseReaction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'page') {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $reaction = $event['reaction'] ?? null;
                if ($reaction === null) {
                    continue;
                }

                $emoji = $reaction['emoji'] ?? $reaction['reaction'] ?? '';
                $action = $reaction['action'] ?? '';
                $senderId = $event['sender']['id'] ?? '';

                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                return [
                    'author' => new Author(id: $senderId),
                    'emoji' => $this->emojiResolver->fromGChat($emoji),
                    'rawEmoji' => $emoji,
                    'added' => $action === 'react',
                    'threadId' => $threadId,
                    'messageId' => $reaction['mid'] ?? (string) ($event['timestamp'] ?? ''),
                    'userId' => $senderId,
                    'raw' => $payload,
                    'originId' => $originId,
                ];
            }
        }

        return null;
    }

    public function parseAction(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'page') {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $postback = $event['postback'] ?? null;
                if ($postback === null) {
                    continue;
                }

                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                $decoded = MessengerCards::decodeCallbackData($postback['payload'] ?? null);

                return [
                    'author' => new Author(id: $senderId),
                    'actionId' => $decoded['actionId'],
                    'value' => $decoded['value'],
                    'threadId' => $threadId,
                    'messageId' => $postback['mid'] ?? (string) ($event['timestamp'] ?? ''),
                    'userId' => $senderId,
                    'isBot' => false,
                    'isMe' => false,
                    'triggerId' => null,
                    'raw' => $payload,
                    'callbackQueryId' => null,
                    'originId' => $originId,
                ];
            }
        }

        return null;
    }

    public function acknowledgeAction(?string $callbackQueryId): ?ResponseInterface
    {
        return null;
    }

    public function parseStatus(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'page') {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                if (isset($event['delivery'])) {
                    return [
                        'type' => 'delivered',
                        'messageIds' => $event['delivery']['mids'] ?? [],
                        'threadId' => $threadId,
                        'userId' => $senderId,
                        'timestamp' => isset($event['delivery']['watermark']) ? (int) $event['delivery']['watermark'] : null,
                        'raw' => $payload,
                        'originId' => $originId,
                    ];
                }

                if (isset($event['read'])) {
                    return [
                        'type' => 'read',
                        'messageIds' => [],
                        'threadId' => $threadId,
                        'userId' => $senderId,
                        'timestamp' => isset($event['read']['watermark']) ? (int) $event['read']['watermark'] : null,
                        'raw' => $payload,
                        'originId' => $originId,
                    ];
                }
            }
        }

        return null;
    }

    public function parseSlashCommand(ServerRequestInterface $request): ?array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'page') {
            return null;
        }

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['messaging'] ?? [] as $event) {
                $message = $event['message'] ?? null;
                if ($message === null || ($message['is_echo'] ?? false)) {
                    continue;
                }

                $rawText = $message['text'] ?? '';

                if ($rawText === '' || $rawText[0] !== '/') {
                    continue;
                }

                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                $parts = explode(' ', $rawText, 2);
                $command = $parts[0];
                $text = $parts[1] ?? '';

                return [
                    'author' => new Author(id: $senderId),
                    'command' => $command,
                    'text' => $text,
                    'userId' => $senderId,
                    'isBot' => false,
                    'isMe' => false,
                    'channelId' => $threadId,
                    'triggerId' => null,
                    'raw' => $body,
                ];
            }
        }

        return null;
    }

    public function parseWebhook(ServerRequestInterface $request): Message
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if ($payload === null || ($payload['object'] ?? '') !== 'page') {
            throw new AdapterException('Invalid Messenger webhook payload');
        }

        // Walk entries to find the first user message
        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $message = $event['message'] ?? null;
                if ($message === null || ($message['is_echo'] ?? false)) {
                    continue;
                }

                $senderId = $event['sender']['id'] ?? '';
                $text = $message['text'] ?? '';
                $mid = $message['mid'] ?? uniqid('msg_');

                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                return new Message(
                    id: $mid,
                    threadId: $threadId,
                    author: new Author(id: $senderId, isBot: false),
                    text: $text,
                    attachments: $this->extractAttachments($message),
                    isDM: true,
                    raw: $body,
                    originId: $originId,
                );
            }
        }

        throw new AdapterException('No user message found in Messenger webhook payload');
    }

    public function parseBatchedWebhook(ServerRequestInterface $request): array
    {
        $body = (string) $request->getBody();
        $payload = json_decode($body, true);

        if (! is_array($payload) || ($payload['object'] ?? '') !== 'page') {
            return [];
        }

        $events = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $originId = $entry['id'] ?? null;
            foreach ($entry['messaging'] ?? [] as $event) {
                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                // Reaction
                if (isset($event['reaction'])) {
                    $reaction = $event['reaction'];
                    $rawEmoji = $reaction['emoji'] ?? $reaction['reaction'] ?? '';
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_REACTION,
                        threadId: $threadId,
                        payload: [
                            'author' => new Author(id: $senderId),
                            'emoji' => $this->emojiResolver->fromGChat($rawEmoji),
                            'rawEmoji' => $rawEmoji,
                            'added' => ($reaction['action'] ?? '') === 'react',
                            'messageId' => $reaction['mid'] ?? (string) ($event['timestamp'] ?? ''),
                            'userId' => $senderId,
                            'raw' => $payload,
                        ],
                        originId: $originId,
                    );

                    continue;
                }

                // Postback
                if (isset($event['postback'])) {
                    $postback = $event['postback'];
                    $decoded = MessengerCards::decodeCallbackData($postback['payload'] ?? null);
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_ACTION,
                        threadId: $threadId,
                        payload: [
                            'author' => new Author(id: $senderId),
                            'actionId' => $decoded['actionId'],
                            'value' => $decoded['value'],
                            'messageId' => $postback['mid'] ?? (string) ($event['timestamp'] ?? ''),
                            'userId' => $senderId,
                            'isBot' => false,
                            'isMe' => false,
                            'triggerId' => null,
                            'raw' => $payload,
                            'callbackQueryId' => null,
                        ],
                        originId: $originId,
                    );

                    continue;
                }

                // Delivery
                if (isset($event['delivery'])) {
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_STATUS,
                        threadId: $threadId,
                        payload: [
                            'type' => 'delivered',
                            'messageIds' => $event['delivery']['mids'] ?? [],
                            'userId' => $senderId,
                            'timestamp' => isset($event['delivery']['watermark']) ? (int) $event['delivery']['watermark'] : null,
                            'raw' => $payload,
                        ],
                        originId: $originId,
                    );

                    continue;
                }

                // Read
                if (isset($event['read'])) {
                    $events[] = new WebhookEvent(
                        type: WebhookEvent::TYPE_STATUS,
                        threadId: $threadId,
                        payload: [
                            'type' => 'read',
                            'messageIds' => [],
                            'userId' => $senderId,
                            'timestamp' => isset($event['read']['watermark']) ? (int) $event['read']['watermark'] : null,
                            'raw' => $payload,
                        ],
                        originId: $originId,
                    );

                    continue;
                }

                // User message (skip echoes)
                $message = $event['message'] ?? null;
                if ($message !== null && ! ($message['is_echo'] ?? false)) {
                    $text = $message['text'] ?? '';
                    $mid = $message['mid'] ?? uniqid('msg_');

                    // Check if this is a slash command
                    if ($text !== '' && $text[0] === '/') {
                        $parts = explode(' ', $text, 2);
                        $command = $parts[0];
                        $cmdText = $parts[1] ?? '';

                        $events[] = new WebhookEvent(
                            type: WebhookEvent::TYPE_SLASH_COMMAND,
                            threadId: $threadId,
                            payload: [
                                'author' => new Author(id: $senderId),
                                'command' => $command,
                                'text' => $cmdText,
                                'userId' => $senderId,
                                'isBot' => false,
                                'isMe' => false,
                                'channelId' => $threadId,
                                'triggerId' => null,
                                'raw' => $payload,
                            ],
                            originId: $originId,
                        );
                    } else {
                        $events[] = new WebhookEvent(
                            type: WebhookEvent::TYPE_MESSAGE,
                            threadId: $threadId,
                            payload: new Message(
                                id: $mid,
                                threadId: $threadId,
                                author: new Author(id: $senderId, isBot: false),
                                text: $text,
                                attachments: $this->extractAttachments($message),
                                isDM: true,
                                raw: $body,
                                originId: $originId,
                            ),
                            originId: $originId,
                        );
                    }
                }
            }
        }

        return $events;
    }

    public function encodeThreadId(mixed $platformData): string
    {
        return 'messenger:'.($platformData['recipientId'] ?? '');
    }

    public function decodeThreadId(string $threadId): mixed
    {
        $parts = explode(':', $threadId, 2);

        if ($parts[0] !== 'messenger' || ! isset($parts[1]) || $parts[1] === '') {
            throw new AdapterException("Invalid Messenger thread ID: {$threadId}");
        }

        return ['recipientId' => $parts[1]];
    }

    public function channelIdFromThreadId(string $threadId): string
    {
        return $threadId;
    }

    public function postMessage(string $threadId, PostableMessage $message): SentMessage
    {
        $decoded = $this->decodeThreadId($threadId);
        $recipientId = $decoded['recipientId'];

        // Convert files to attachments via the registered converter
        if ($message->files !== []) {
            $converted = [];
            foreach ($message->files as $file) {
                $converted[] = $this->fileUploadConverter->upload($file, $this);
            }
            $message = new PostableMessage(
                content: $message->content,
                replyToMessageId: $message->replyToMessageId,
                attachments: array_merge($message->attachments, $converted),
            );
        }

        $basePayload = [
            'recipient' => ['id' => $recipientId],
            'messaging_type' => 'RESPONSE',
        ];

        if ($message->replyToMessageId !== null) {
            $basePayload['reply_to'] = ['mid' => $message->replyToMessageId];
        }

        // Attachments take priority
        if ($message->attachments !== []) {
            $att = $message->attachments[0];
            $text = $this->formatConverter->renderPostable($message);

            $attachmentData = match ($att->type) {
                'image' => ['type' => 'image', 'payload' => ['url' => $att->url]],
                'video' => ['type' => 'video', 'payload' => ['url' => $att->url]],
                'audio' => ['type' => 'audio', 'payload' => ['url' => $att->url]],
                default => ['type' => 'file', 'payload' => ['url' => $att->url]],
            };

            $response = $this->graphApiCall('me/messages', [
                ...$basePayload,
                'message' => [
                    'attachment' => $attachmentData,
                ],
            ]);

            $additionalMessages = [];

            // Append text as a follow-up if present
            if ($text !== '') {
                $textResponse = $this->graphApiCall('me/messages', [
                    ...$basePayload,
                    'message' => ['text' => $this->truncate($text)],
                ]);

                $additionalMessages[] = new SentMessage(
                    id: $textResponse['message_id'] ?? '',
                    threadId: $threadId,
                    timestamp: (string) time(),
                    raw: $textResponse,
                );
            }

            return new SentMessage(
                id: $response['message_id'] ?? '',
                threadId: $threadId,
                timestamp: (string) time(),
                additionalMessages: $additionalMessages,
                raw: $response,
            );
        }

        if ($message->isTemplate()) {
            $template = $message->content;

            if (! $template instanceof MessengerTemplate) {
                throw new AdapterException('Unsupported template type for Messenger adapter');
            }

            $result = $template->toMessenger();

            $response = $this->graphApiCall('me/messages', [
                ...$basePayload,
                'message' => ['attachment' => $result['attachment']],
            ]);
        } elseif ($message->isCard()) {
            $cardResult = MessengerCards::toMessengerPayload($message->content);

            if ($cardResult['type'] === 'template') {
                $response = $this->graphApiCall('me/messages', [
                    ...$basePayload,
                    'message' => ['attachment' => $cardResult['attachment']],
                ]);
            } else {
                $response = $this->graphApiCall('me/messages', [
                    ...$basePayload,
                    'message' => ['text' => $this->truncate($cardResult['text'])],
                ]);
            }
        } else {
            $text = $this->formatConverter->renderPostable($message);
            $response = $this->graphApiCall('me/messages', [
                ...$basePayload,
                'message' => ['text' => $this->truncate($text)],
            ]);
        }

        return new SentMessage(
            id: $response['message_id'] ?? '',
            threadId: $threadId,
            timestamp: (string) time(),
            raw: $response,
        );
    }

    public function editMessage(string $threadId, string $messageId, PostableMessage $message): SentMessage
    {
        throw new AdapterException('Messenger does not support editing messages');
    }

    public function deleteMessage(string $threadId, string $messageId): void
    {
        throw new AdapterException('Messenger does not support deleting messages');
    }

    public function addReaction(string $threadId, string $messageId, string $emoji): void
    {
        throw new AdapterException('Messenger does not support reactions via API');
    }

    public function removeReaction(string $threadId, string $messageId, string $emoji): void
    {
        throw new AdapterException('Messenger does not support reactions via API');
    }

    public function startTyping(string $threadId): void
    {
        $decoded = $this->decodeThreadId($threadId);
        $this->graphApiCall('me/messages', [
            'recipient' => ['id' => $decoded['recipientId']],
            'sender_action' => 'typing_on',
        ]);
    }

    public function fetchMessages(string $threadId, ?FetchOptions $options = null): FetchResult
    {
        return new FetchResult(messages: []);
    }

    public function fetchThread(string $threadId): ThreadInfo
    {
        return new ThreadInfo(
            id: $threadId,
            channelId: $threadId,
            messageCount: 0,
        );
    }

    public function fetchChannelInfo(string $channelId): ?ChannelInfo
    {
        $decoded = $this->decodeThreadId($channelId);
        $response = $this->graphApiCall($decoded['recipientId'], [], 'GET', ['fields' => 'first_name,last_name']);

        $name = trim(($response['first_name'] ?? '').' '.($response['last_name'] ?? ''));

        return new ChannelInfo(
            id: $channelId,
            name: $name ?: $channelId,
            isPrivate: true,
        );
    }

    public function getUser(string $userId): ?UserInfo
    {
        $response = $this->graphApiCall($userId, [], 'GET', ['fields' => 'first_name,last_name,profile_pic']);

        $name = trim(($response['first_name'] ?? '').' '.($response['last_name'] ?? ''));

        return new UserInfo(
            id: $userId,
            name: $name ?: $userId,
        );
    }

    public function getAuthorInfo(Author $author): Author
    {
        $response = $this->graphApiCall($author->id, [], 'GET', ['fields' => 'first_name,last_name,locale,timezone,profile_pic']);

        $localizations = [];
        $profilePicture = $author->profilePicture;

        if (isset($response['profile_pic'])) {
            $profilePicture = $response['profile_pic'];
        }

        if (isset($response['locale'])) {
            $localizations[] = new LocalizationValue(LocalizationType::Locale, $response['locale']);
        }

        if (isset($response['timezone'])) {
            $localizations[] = new LocalizationValue(LocalizationType::Timezone, (string) $response['timezone']);
        }

        if ($localizations === [] && $profilePicture === $author->profilePicture) {
            return $author;
        }

        return (
            new Author(
                $author->id,
                $author->name,
                $author->email,
                $author->isMe,
                $author->isBot,
                $profilePicture,
            )
        )->withLocalizations(...$localizations);
    }

    public function openDM(string $userId): ?string
    {
        return $this->encodeThreadId(['recipientId' => $userId]);
    }

    public function getFormatConverter(): ?FormatConverter
    {
        return $this->formatConverter;
    }

    public function initialize(Chat $chat): void
    {
        try {
            $me = $this->graphApiCall('me', [], 'GET');
            $this->botUserId = $me['id'] ?? null;
        } catch (AdapterException) {
            // Bot identity unavailable — continue without it
        }
    }

    public function disconnect(): void
    {
        // No persistent connection
    }

    public function createResponse(): ?ResponseInterface
    {
        return null;
    }

    public function stream(string $threadId, iterable $textStream, array $options = []): ?SentMessage
    {
        $fullText = '';
        foreach ($textStream as $chunk) {
            $fullText .= $chunk;
        }

        if ($fullText === '') {
            return null;
        }

        return $this->postMessage($threadId, PostableMessage::text($fullText));
    }

    protected function truncate(string $text, int $limit = 2000): string
    {
        if (strlen($text) <= $limit) {
            return $text;
        }

        return substr($text, 0, $limit - 3).'...';
    }

    /** @return Attachment[] */
    protected function extractAttachments(array $message): array
    {
        $attachments = [];

        foreach ($message['attachments'] ?? [] as $att) {
            $rawType = $att['type'] ?? '';

            $type = match ($rawType) {
                'image', 'video', 'audio', 'file', 'fallback' => $rawType,
                'reel', 'ig_reel', 'post', 'ig_post', 'appointment_booking', 'template' => $rawType,
                default => 'file',
            };

            $payload = $att['payload'] ?? [];

            $metadata = match ($rawType) {
                'fallback', 'reel', 'ig_reel', 'post', 'ig_post' => array_filter([
                    'title' => $payload['title'] ?? null,
                    'reel_video_id' => $payload['reel_video_id'] ?? null,
                    'id' => $payload['id'] ?? null,
                ]),
                'appointment_booking' => array_filter([
                    'booking_id' => $payload['booking_id'] ?? null,
                    'status' => $payload['status'] ?? null,
                    'start_time' => $payload['start_time'] ?? null,
                    'end_time' => $payload['end_time'] ?? null,
                    'timezone' => $payload['timezone'] ?? null,
                ]),
                'template' => array_filter([
                    'product' => $payload['product'] ?? null,
                ]),
                'image' => array_filter([
                    'sticker_id' => $payload['sticker_id'] ?? null,
                ]),
                default => [],
            };

            $attachments[] = new Attachment(
                type: $type,
                url: $payload['url'] ?? null,
                mimeType: $payload['mime_type'] ?? null,
                fetchMetadata: $metadata !== [] ? $metadata : null,
            );
        }

        return $attachments;
    }

    protected function graphApiCall(string $endpoint, array $params, string $method = 'POST', array $queryParams = []): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;
        $proof = hash_hmac('sha256', $this->pageAccessToken, $this->appSecret);
        $url = "{$this->apiUrl}/{$this->apiVersion}/{$endpoint}?access_token={$this->pageAccessToken}&appsecret_proof={$proof}";

        if ($queryParams !== []) {
            $url .= '&'.http_build_query($queryParams);
        }

        if ($method === 'GET') {
            $request = $factory->createRequest('GET', $url);
        } else {
            $body = json_encode(array_filter($params, fn ($v): bool => $v !== null));
            $request = $factory->createRequest($method, $url)
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream($body));
        }

        $psrResponse = $this->httpClient->sendRequest($request);
        $responseBody = (string) $psrResponse->getBody();

        $data = json_decode($responseBody, true);

        if ($data === null) {
            return [];
        }

        if (! is_array($data)) {
            throw new AdapterException("Invalid JSON response from Messenger API: {$endpoint}");
        }

        if (isset($data['error'])) {
            $errorMsg = $data['error']['message'] ?? 'unknown_error';
            $errorCode = $data['error']['code'] ?? 0;
            $errorType = $data['error']['type'] ?? '';

            if (in_array($errorCode, [10, 190, 200], true) || $errorType === 'OAuthException') {
                throw new AuthenticationException("Messenger API authentication error ({$endpoint}): {$errorMsg}");
            }

            throw new AdapterException("Messenger API error ({$endpoint}): {$errorMsg}");
        }

        return $data;
    }

    protected function jsonError(int $status, string $message): ResponseInterface
    {
        $factory = $this->psrFactory ?? new Psr17Factory;

        return $factory->createResponse($status)
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream(json_encode(['error' => $message])));
    }
}
