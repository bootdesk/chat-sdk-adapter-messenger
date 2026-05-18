<?php

namespace BootDesk\ChatSDK\Messenger\Tests;

use BootDesk\ChatSDK\Messenger\MessengerTemplate;
use PHPUnit\Framework\TestCase;

class MessengerTemplateTest extends TestCase
{
    public function test_button_template(): void
    {
        $tpl = MessengerTemplate::create('options')
            ->buttonTemplate('Choose an option', [
                ['type' => 'postback', 'title' => 'Yes', 'payload' => 'YES'],
                ['type' => 'postback', 'title' => 'No', 'payload' => 'NO'],
            ]);

        $result = $tpl->toMessenger();

        $this->assertSame('template', $result['type']);
        $this->assertSame('button', $result['attachment']['payload']['template_type']);
        $this->assertSame('Choose an option', $result['attachment']['payload']['text']);
        $this->assertCount(2, $result['attachment']['payload']['buttons']);
    }

    public function test_generic_template(): void
    {
        $tpl = MessengerTemplate::create('catalog')
            ->genericTemplate([
                [
                    'title' => 'Product A',
                    'subtitle' => 'Best seller',
                    'image_url' => 'https://example.com/a.jpg',
                    'buttons' => [
                        ['type' => 'web_url', 'title' => 'Buy', 'url' => 'https://shop.example.com/a'],
                    ],
                ],
            ]);

        $result = $tpl->toMessenger();

        $this->assertSame('generic', $result['attachment']['payload']['template_type']);
        $this->assertSame('Product A', $result['attachment']['payload']['elements'][0]['title']);
    }

    public function test_media_template(): void
    {
        $tpl = MessengerTemplate::create('intro')
            ->mediaTemplate('https://example.com/video.mp4', 'video', [
                'type' => 'web_url', 'title' => 'Learn More', 'url' => 'https://example.com',
            ]);

        $result = $tpl->toMessenger();

        $this->assertSame('media', $result['attachment']['payload']['template_type']);
        $this->assertSame('video', $result['attachment']['payload']['elements'][0]['media_type']);
        $this->assertSame('https://example.com/video.mp4', $result['attachment']['payload']['elements'][0]['url']);
    }

    public function test_media_template_without_button(): void
    {
        $tpl = MessengerTemplate::create('gallery')
            ->mediaTemplate('https://example.com/photo.png', 'image');

        $result = $tpl->toMessenger();

        $this->assertArrayNotHasKey('buttons', $result['attachment']['payload']['elements'][0]);
    }

    public function test_receipt_template(): void
    {
        $tpl = MessengerTemplate::create('receipt_demo')
            ->receiptTemplate(
                recipientName: 'John Doe',
                orderNumber: 'ORD-12345',
                currency: 'USD',
                paymentMethod: 'Visa',
                orderUrl: 'https://shop.example.com/orders/12345',
                elements: [
                    ['title' => 'Widget', 'quantity' => 1, 'price' => 29.99, 'currency' => 'USD'],
                ],
                summary: ['total_cost' => 29.99],
            );

        $result = $tpl->toMessenger();

        $this->assertSame('receipt', $result['attachment']['payload']['template_type']);
        $this->assertSame('John Doe', $result['attachment']['payload']['recipient_name']);
        $this->assertSame('ORD-12345', $result['attachment']['payload']['order_number']);
        $this->assertSame(29.99, $result['attachment']['payload']['summary']['total_cost']);
    }

    public function test_product_template(): void
    {
        $tpl = MessengerTemplate::create('product_demo')
            ->productTemplate('catalog_product_123');

        $result = $tpl->toMessenger();

        $this->assertSame('product', $result['attachment']['payload']['template_type']);
        $this->assertSame('catalog_product_123', $result['attachment']['payload']['elements'][0]['id']);
    }

    public function test_coupon_template(): void
    {
        $tpl = MessengerTemplate::create('promo')
            ->couponTemplate(
                title: 'Summer Sale',
                code: 'SUMMER20',
                redeemUrl: 'https://shop.example.com/sale',
                redeemButtonLabel: 'Shop Now',
                imageUrl: 'https://example.com/banner.jpg',
                subtitle: '20% off everything',
            );

        $result = $tpl->toMessenger();

        $this->assertSame('coupon', $result['attachment']['payload']['template_type']);
        $this->assertSame('Summer Sale', $result['attachment']['payload']['title']);
        $this->assertSame('SUMMER20', $result['attachment']['payload']['code']);
    }

    public function test_customer_feedback_template(): void
    {
        $tpl = MessengerTemplate::create('feedback')
            ->customerFeedbackTemplate(
                title: 'Rate your experience',
                businessAddress: '123 Main St',
                ratingOptions: [
                    ['type' => 'star', 'value' => 1, 'label' => 'Poor'],
                    ['type' => 'star', 'value' => 5, 'label' => 'Excellent'],
                ],
            );

        $result = $tpl->toMessenger();

        $this->assertSame('customer_feedback', $result['attachment']['payload']['template_type']);
        $this->assertSame('Rate your experience', $result['attachment']['payload']['title']);
    }

    public function test_to_string(): void
    {
        $tpl = MessengerTemplate::create('options')
            ->buttonTemplate('Pick one', []);

        $this->assertSame('Pick one', (string) $tpl);
    }

    public function test_to_array(): void
    {
        $tpl = MessengerTemplate::create('greeting')
            ->buttonTemplate('Hello', []);

        $this->assertSame([
            'template_type' => 'button',
            'name' => 'greeting',
        ], $tpl->toArray());
    }

    public function test_add_postback_button(): void
    {
        $tpl = MessengerTemplate::create('postback_test')
            ->buttonTemplate('Try the postback button!', [])
            ->addPostbackButton('Postback Button', 'DEVELOPER_DEFINED_PAYLOAD');

        $result = $tpl->toMessenger();

        $this->assertSame('button', $result['attachment']['payload']['template_type']);
        $this->assertSame('Try the postback button!', $result['attachment']['payload']['text']);
        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertCount(1, $buttons);
        $this->assertSame('postback', $buttons[0]['type']);
        $this->assertSame('Postback Button', $buttons[0]['title']);
        $this->assertSame('DEVELOPER_DEFINED_PAYLOAD', $buttons[0]['payload']);
    }

    public function test_add_web_url_button(): void
    {
        $tpl = MessengerTemplate::create('url_test')
            ->buttonTemplate('Try the URL button!', [])
            ->addWebUrlButton('URL Button', 'https://www.example.com/', 'full');

        $result = $tpl->toMessenger();

        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertCount(1, $buttons);
        $this->assertSame('web_url', $buttons[0]['type']);
        $this->assertSame('URL Button', $buttons[0]['title']);
        $this->assertSame('https://www.example.com/', $buttons[0]['url']);
        $this->assertSame('full', $buttons[0]['webview_height_ratio']);
    }

    public function test_add_web_url_button_without_ratio(): void
    {
        $tpl = MessengerTemplate::create('url_test')
            ->buttonTemplate('Try the URL button!', [])
            ->addWebUrlButton('URL Button', 'https://www.example.com/');

        $result = $tpl->toMessenger();

        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertArrayNotHasKey('webview_height_ratio', $buttons[0]);
    }

    public function test_add_phone_number_button(): void
    {
        $tpl = MessengerTemplate::create('phone_test')
            ->buttonTemplate('Need further assistance? Talk to a representative', [])
            ->addPhoneNumberButton('Call Representative', '+15105551234');

        $result = $tpl->toMessenger();

        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertCount(1, $buttons);
        $this->assertSame('phone_number', $buttons[0]['type']);
        $this->assertSame('Call Representative', $buttons[0]['title']);
        $this->assertSame('+15105551234', $buttons[0]['payload']);
    }

    public function test_add_account_link(): void
    {
        $tpl = MessengerTemplate::create('link_test')
            ->buttonTemplate('Try the log in button!', [])
            ->addAccountLink('https://www.example.com/authorize');

        $result = $tpl->toMessenger();

        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertCount(1, $buttons);
        $this->assertSame('account_link', $buttons[0]['type']);
        $this->assertSame('https://www.example.com/authorize', $buttons[0]['url']);
    }

    public function test_add_account_unlink(): void
    {
        $tpl = MessengerTemplate::create('unlink_test')
            ->buttonTemplate('Try the log out button!', [])
            ->addAccountUnlink();

        $result = $tpl->toMessenger();

        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertCount(1, $buttons);
        $this->assertSame('account_unlink', $buttons[0]['type']);
    }

    public function test_add_game_play_button(): void
    {
        $tpl = MessengerTemplate::create('game_test')
            ->buttonTemplate('Try the game play button!', [])
            ->addGamePlayButton('Play', 'GAME_PAYLOAD', ['player_id' => 'PLAYER_123']);

        $result = $tpl->toMessenger();

        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertCount(1, $buttons);
        $this->assertSame('game_play', $buttons[0]['type']);
        $this->assertSame('Play', $buttons[0]['title']);
        $this->assertSame('GAME_PAYLOAD', $buttons[0]['payload']);
        $this->assertSame(['player_id' => 'PLAYER_123'], $buttons[0]['game_metadata']);
    }

    public function test_add_game_play_button_without_metadata(): void
    {
        $tpl = MessengerTemplate::create('game_test')
            ->buttonTemplate('Try the game play button!', [])
            ->addGamePlayButton('Play', 'GAME_PAYLOAD');

        $result = $tpl->toMessenger();

        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertCount(1, $buttons);
        $this->assertArrayNotHasKey('game_metadata', $buttons[0]);
    }

    public function test_multiple_typed_buttons(): void
    {
        $tpl = MessengerTemplate::create('multi_button')
            ->buttonTemplate('Choose an action', [])
            ->addWebUrlButton('Visit Site', 'https://example.com')
            ->addPostbackButton('More Info', 'INFO_PAYLOAD')
            ->addPhoneNumberButton('Call Us', '+15551234567')
            ->addAccountLink('Login');

        $result = $tpl->toMessenger();

        $buttons = $result['attachment']['payload']['buttons'];
        $this->assertCount(4, $buttons);
        $this->assertSame('web_url', $buttons[0]['type']);
        $this->assertSame('postback', $buttons[1]['type']);
        $this->assertSame('phone_number', $buttons[2]['type']);
        $this->assertSame('account_link', $buttons[3]['type']);
    }
}
