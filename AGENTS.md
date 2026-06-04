# adapter-messenger

Facebook Messenger adapter for bootdesk/chat-sdk-core. Namespace: `BootDesk\ChatSDK\Messenger`

## files
- `MessengerAdapter` — implements `Adapter` using Messenger Send API
- `MessengerFormatConverter` — Messenger text ↔ CommonMark AST. Uses `*bold*`, `_italic_`, `~strikethrough~`, `` `code` ``, ``` ```block``` ``` syntax. `renderPostable()` converts standard markdown (`**bold**`, `~~strike~~`) to Messenger format. Lists/tables rendered as plain pipe text. No link support in text body.
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
`messenger:{recipientId}` — e.g. `messenger:987654321` (PSID only)

## webhook flow
1. `verifyWebhook` — responds to `hub.verify_token` challenge; verifies HMAC-SHA256 on POST
2. `parseWebhook` — extracts user messages (skips echo)
3. `parseAction` — extracts `messaging_postbacks` (postback buttons, Get Started, persistent menu); implements `HandlesActions`
4. `parseSlashCommand` — extracts messages starting with `/`; implements `HandlesSlashCommands`
5. `parseReaction` — extracts `message_reactions` (react/unreact); implements `HandlesReactions`
6. `parseStatus` — extracts `message_deliveries` and `message_reads`; implements `HandlesStatuses`

## features
- Send text, generic templates, button templates, quick replies
- Sender Actions (typing_on, typing_off, mark_seen)
- Fetch user profile (first_name, last_name, profile_pic)
- Slash commands (`/command`) with arguments
- Reactions (emoji react/unreact)
- No message editing/deletion support
- Streaming: concatenates chunks into single message
- Messenger Profile API for persistent menu, get started button, greeting
- Batched webhook support (multiple events per request)

## config (laravel)
```php
'messenger' => [
    'page_access_token' => env('MESSENGER_PAGE_ACCESS_TOKEN'),
    'app_secret' => env('MESSENGER_APP_SECRET'),
    'verify_token' => env('MESSENGER_VERIFY_TOKEN'),
],
```
