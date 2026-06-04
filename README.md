# bootdesk/chat-sdk-adapter-messenger

Facebook Messenger adapter for the laravel-bootdesk multi-platform messaging framework.

## Install

```bash
composer require bootdesk/chat-sdk-adapter-messenger
```

Requires a PSR-18 HTTP client (`guzzlehttp/guzzle`, `symfony/http-client`, etc.) and a PSR-17 factory (`nyholm/psr7` bundled).

## Configuration

| Variable            | Description                 | Example                 |
| ------------------- | --------------------------- | ----------------------- |
| `page_access_token` | Facebook Page Access Token  | `EAAx...`               |
| `http_client`       | PSR-18 HTTP client instance | `new GuzzleHttp\Client` |
| `app_secret`        | Facebook App Secret         | `abc123...`             |
| `verify_token`      | Webhook Verify Token        | `my-verify-token`       |

```php
use BootDesk\ChatSDK\Messenger\MessengerAdapter;

$adapter = new MessengerAdapter(
    pageAccessToken: env('MESSENGER_PAGE_ACCESS_TOKEN'),
    httpClient: new \GuzzleHttp\Client,
    appSecret: env('MESSENGER_APP_SECRET'),
    verifyToken: env('MESSENGER_VERIFY_TOKEN'),
);
```

### Laravel

The `ChatServiceProvider` auto-binds `Psr\Http\Client\ClientInterface` to `GuzzleHttp\Client`. Add to `config/chat.php`:

```php
'messenger' => [
    'page_access_token' => env('MESSENGER_PAGE_ACCESS_TOKEN'),
    'app_secret'        => env('MESSENGER_APP_SECRET'),
    'verify_token'      => env('MESSENGER_VERIFY_TOKEN'),
],
```

## Quick Example

```php
// Send a message to a user
$adapter->postMessage('messenger:1234567890', 'Hello from laravel-bootdesk!');
```

## Text Formatting

Messenger supports `*bold*`, `_italic_`, `~strikethrough~`, `` `monospace` ``, and ``` ```code blocks``` ``` syntax. The SDK's `MessengerFormatConverter` automatically converts standard markdown (`**bold**`, `~~strike~~`) to Messenger format when sending, and converts Messenger syntax back to standard markdown when receiving.

## Message Templates

Messenger supports rich message templates via `PostableMessage::template()` with `MessengerTemplate`:

```php
use BootDesk\ChatSDK\Messenger\MessengerTemplate;

// Button template
$adapter->postMessage('messenger:1234567890', PostableMessage::template(
    MessengerTemplate::create('options')
        ->buttonTemplate('Choose an option', [
            ['type' => 'postback', 'title' => 'Yes', 'payload' => 'YES'],
            ['type' => 'postback', 'title' => 'No', 'payload' => 'NO'],
        ])
));

// Generic template (carousel)
$adapter->postMessage('messenger:1234567890', PostableMessage::template(
    MessengerTemplate::create('catalog')
        ->genericTemplate([
            [
                'title' => 'Product A',
                'subtitle' => 'Best seller',
                'image_url' => 'https://example.com/a.jpg',
                'default_action' => ['type' => 'web_url', 'url' => 'https://example.com/a'],
                'buttons' => [['type' => 'web_url', 'title' => 'Buy', 'url' => 'https://example.com/a/buy']],
            ],
        ])
));

// Media template
$adapter->postMessage('messenger:1234567890', PostableMessage::template(
    MessengerTemplate::create('intro')
        ->mediaTemplate('https://example.com/video.mp4', 'video', [
            'type' => 'web_url', 'title' => 'Learn More', 'url' => 'https://example.com',
        ])
));
```

### All Template Types

| Method                                                                 | Template Type                                        |
| ---------------------------------------------------------------------- | ---------------------------------------------------- |
| `buttonTemplate(string $text, array $buttons)`                         | Button — text with up to 3 buttons                   |
| `genericTemplate(array $elements)`                                     | Generic — cards with title, subtitle, image, buttons |
| `mediaTemplate(string $url, string $mediaType, ?array $button)`        | Media — image/video with optional button             |
| `receiptTemplate(recipientName, orderNumber, currency, ...)`           | Receipt — order confirmation                         |
| `productTemplate(string $productId)`                                   | Product — catalog product card                       |
| `couponTemplate(title, code, ...)`                                     | Coupon — promotional offer                           |
| `customerFeedbackTemplate(title, businessAddress, ratingOptions, ...)` | Customer Feedback — native survey                    |

Cards from the SDK `Card` class (title, sections, images, buttons) are automatically rendered as **button** or **generic** templates.

## Thread ID Format

| Format                 | Description           |
| ---------------------- | --------------------- |
| `messenger:{senderId}` | One thread per sender |

## Webhook

Facebook sends webhook events to your endpoint. Verify requests using HMAC signature verification with the app secret (`X-Hub-Signature-256` header).

## Feature Matrix

| Feature            | Supported |
| ------------------ | --------- |
| Post messages      | ✓         |
| Edit messages      | ✗         |
| Delete messages    | ✓         |
| Reactions          | ✓         |
| Slash commands     | ✓         |
| Typing indicator   | ✓         |
| Fetch messages     | ✗         |
| Fetch thread info  | ✗         |
| Fetch channel info | ✗         |
| Get user           | ✗         |
| Open DM            | ✗         |
| Stream             | ✓         |

## Notes

Facebook Messenger Platform. Supports quick replies, persistent menu, and get started button. Rich templates available via `MessengerTemplate` (button, generic, media, receipt, product, coupon, customer feedback).

## Documentationn

Full API documentation: https://bootdesk.github.io/chat-sdk

## License

MIT
