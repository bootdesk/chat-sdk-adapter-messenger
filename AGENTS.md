# adapter-messenger

Facebook Messenger adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\Messenger`

## files
- `MessengerAdapter` — implements `Adapter` using Messenger Send API
- `MessengerFormatConverter` — Messenger text ↔ CommonMark AST
- `MessengerCards` — Card model → Messenger Generic Template / Button Template
- `MessengerTemplate` — structured message template builder
- `MessengerWebhookVerifier` — verify_token challenge + HMAC signature

## registration
`src/register.php` registers `'messenger' => MessengerAdapter::class` via `AdapterRegistry`

## constructor
```php
new MessengerAdapter(
    string $pageAccessToken,
    string $appSecret,
    string $verifyToken,
    ClientInterface $httpClient,
    ?Psr17Factory $psrFactory = null,
);
```

## thread ID format
`messenger:{pageId}:{senderId}` — e.g. `messenger:123:987654321`

## webhook flow
1. `verifyWebhook` — responds to `hub.verify_token` challenge
2. `parseWebhook` — extracts messages, postbacks, message_deliveries, optins

## features
- Send text, generic templates, button templates, quick replies
- Sender Actions (typing_on, typing_off, mark_seen)
- Fetch user profile (first_name, last_name, profile_pic)
- No message editing/deletion support
- No reaction support
- Streaming: concatenates chunks into single message
- Messenger Profile API for persistent menu, get started button, greeting

## config (laravel)
```php
'messenger' => [
    'page_access_token' => env('MESSENGER_PAGE_ACCESS_TOKEN'),
    'app_secret' => env('MESSENGER_APP_SECRET'),
    'verify_token' => env('MESSENGER_VERIFY_TOKEN'),
],
```
