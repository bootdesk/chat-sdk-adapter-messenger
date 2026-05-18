<?php

namespace BootDesk\ChatSDK\Messenger;

use BootDesk\ChatSDK\Core\Attachment;
use BootDesk\ChatSDK\Core\Author;
use BootDesk\ChatSDK\Core\ChannelInfo;
use BootDesk\ChatSDK\Core\Chat;
use BootDesk\ChatSDK\Core\Contracts\Adapter;
use BootDesk\ChatSDK\Core\Contracts\FileUploadConverter;
use BootDesk\ChatSDK\Core\Contracts\FormatConverter;
use BootDesk\ChatSDK\Core\Contracts\HandlesReactions;
use BootDesk\ChatSDK\Core\Contracts\HandlesStatuses;
use BootDesk\ChatSDK\Core\Exceptions\AdapterException;
use BootDesk\ChatSDK\Core\Exceptions\AuthenticationException;
use BootDesk\ChatSDK\Core\FetchOptions;
use BootDesk\ChatSDK\Core\FetchResult;
use BootDesk\ChatSDK\Core\Message;
use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Core\SentMessage;
use BootDesk\ChatSDK\Core\Support\NullFileUploadConverter;
use BootDesk\ChatSDK\Core\ThreadInfo;
use BootDesk\ChatSDK\Core\UserInfo;
use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

class MessengerAdapter implements Adapter, HandlesReactions, HandlesStatuses
{
    protected ?string $botUserId = null;

    protected MessengerFormatConverter $formatConverter;

    protected MessengerWebhookVerifier $webhookVerifier;

    protected FileUploadConverter $fileUploadConverter;

    public function __construct(
        protected readonly string $pageAccessToken,
        protected readonly ClientInterface $httpClient,
        string $appSecret,
        string $verifyToken,
        protected readonly string $apiVersion = 'v21.0',
        protected readonly string $apiUrl = 'https://graph.facebook.com',
        protected readonly ?Psr17Factory $psrFactory = null,
        ?FileUploadConverter $fileUploadConverter = null,
    ) {
        $this->formatConverter = new MessengerFormatConverter;
        $this->webhookVerifier = new MessengerWebhookVerifier($appSecret, $verifyToken, $psrFactory);
        $this->fileUploadConverter = $fileUploadConverter ?? new NullFileUploadConverter;
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
                    'emoji' => $emoji,
                    'rawEmoji' => $emoji,
                    'added' => $action === 'react',
                    'threadId' => $threadId,
                    'messageId' => $reaction['mid'] ?? $event['timestamp'] ?? '',
                    'userId' => $senderId,
                    'raw' => $payload,
                ];
            }
        }

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
            foreach ($entry['messaging'] ?? [] as $event) {
                $senderId = $event['sender']['id'] ?? '';
                $threadId = $this->encodeThreadId(['recipientId' => $senderId]);

                if (isset($event['delivery'])) {
                    return [
                        'type' => 'delivered',
                        'messageIds' => $event['delivery']['mids'] ?? [],
                        'threadId' => $threadId,
                        'userId' => $senderId,
                        'timestamp' => $event['delivery']['watermark'] ?? null,
                        'raw' => $payload,
                    ];
                }

                if (isset($event['read'])) {
                    return [
                        'type' => 'read',
                        'messageIds' => [],
                        'threadId' => $threadId,
                        'userId' => $senderId,
                        'timestamp' => $event['read']['watermark'] ?? null,
                        'raw' => $payload,
                    ];
                }
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
                );
            }
        }

        throw new AdapterException('No user message found in Messenger webhook payload');
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

        // Attachments take priority
        if ($message->attachments !== []) {
            $att = $message->attachments[0];
            $text = $message->getTextContent();

            $attachmentData = match ($att->type) {
                'image' => ['type' => 'image', 'payload' => ['url' => $att->url]],
                'video' => ['type' => 'video', 'payload' => ['url' => $att->url]],
                'audio' => ['type' => 'audio', 'payload' => ['url' => $att->url]],
                default => ['type' => 'file', 'payload' => ['url' => $att->url]],
            };

            $response = $this->graphApiCall('me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => [
                    'attachment' => $attachmentData,
                ],
                'messaging_type' => 'RESPONSE',
            ]);

            // Append text as a follow-up if present
            if ($text !== '') {
                $this->graphApiCall('me/messages', [
                    'recipient' => ['id' => $recipientId],
                    'message' => ['text' => $this->truncate($text)],
                    'messaging_type' => 'RESPONSE',
                ]);
            }

            return new SentMessage(
                id: $response['message_id'] ?? '',
                threadId: $threadId,
                timestamp: (string) ($response['recipient_id'] ?? ''),
            );
        }

        if ($message->isTemplate()) {
            $template = $message->content;

            if (! $template instanceof MessengerTemplate) {
                throw new AdapterException('Unsupported template type for Messenger adapter');
            }

            $result = $template->toMessenger();

            $response = $this->graphApiCall('me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => $result['attachment'],
                'messaging_type' => 'RESPONSE',
            ]);
        } elseif ($message->isCard()) {
            $cardResult = MessengerCards::toMessengerPayload($message->content);

            if ($cardResult['type'] === 'template') {
                $response = $this->graphApiCall('me/messages', [
                    'recipient' => ['id' => $recipientId],
                    'message' => $cardResult['attachment'],
                    'messaging_type' => 'RESPONSE',
                ]);
            } else {
                $response = $this->graphApiCall('me/messages', [
                    'recipient' => ['id' => $recipientId],
                    'message' => ['text' => $this->truncate($cardResult['text'])],
                    'messaging_type' => 'RESPONSE',
                ]);
            }
        } else {
            $text = $this->formatConverter->renderPostable($message);
            $response = $this->graphApiCall('me/messages', [
                'recipient' => ['id' => $recipientId],
                'message' => ['text' => $this->truncate($text)],
                'messaging_type' => 'RESPONSE',
            ]);
        }

        return new SentMessage(
            id: $response['message_id'] ?? '',
            threadId: $threadId,
            timestamp: (string) ($response['recipient_id'] ?? ''),
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
            $type = match ($att['type'] ?? '') {
                'image' => 'image',
                'video' => 'video',
                'audio' => 'audio',
                default => 'file',
            };

            $attachments[] = new Attachment(
                type: $type,
                url: $att['payload']['url'] ?? null,
                mimeType: $att['payload']['mime_type'] ?? null,
            );
        }

        return $attachments;
    }

    protected function graphApiCall(string $endpoint, array $params, string $method = 'POST', array $queryParams = []): array
    {
        $factory = $this->psrFactory ?? new Psr17Factory;
        $url = "{$this->apiUrl}/{$this->apiVersion}/{$endpoint}?access_token={$this->pageAccessToken}";

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
