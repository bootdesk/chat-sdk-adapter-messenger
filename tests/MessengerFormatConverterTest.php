<?php

namespace BootDesk\ChatSDK\Messenger\Tests;

use BootDesk\ChatSDK\Core\PostableMessage;
use BootDesk\ChatSDK\Messenger\MessengerFormatConverter;
use PHPUnit\Framework\TestCase;

class MessengerFormatConverterTest extends TestCase
{
    private MessengerFormatConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new MessengerFormatConverter;
    }

    public function test_passthrough_text(): void
    {
        $ast = $this->converter->toAst('Hello world');
        $markdown = $this->converter->fromAst($ast);
        $this->assertStringContainsString('Hello world', $markdown);
    }

    public function test_bold_conversion(): void
    {
        $ast = $this->converter->toAst('This is **bold** text');
        $html = $this->converter->fromAst($ast);
        $this->assertStringContainsString('<strong>bold</strong>', $html);
    }

    public function test_render_postable_text(): void
    {
        $message = PostableMessage::text('Hello');
        $this->assertSame('Hello', $this->converter->renderPostable($message));
    }
}
