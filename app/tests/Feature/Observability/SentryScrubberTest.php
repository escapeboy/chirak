<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Observability\SentryScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;

class SentryScrubberTest extends TestCase
{
    public function test_masks_email_addresses(): void
    {
        $this->assertSame(
            'Error sending to [scrubbed] in pipeline',
            SentryScrubber::scrub('Error sending to ivan.petrov@abv.bg in pipeline'),
        );
    }

    public function test_masks_inlined_sql(): void
    {
        $result = SentryScrubber::scrub("SQLSTATE[23000]: SQL: insert into runs values ('token@example.com')");
        $this->assertStringNotContainsString('token@example.com', $result);
        $this->assertStringNotContainsString('insert into', $result);
    }

    public function test_masks_anthropic_api_keys(): void
    {
        // Uses a synthetic key in the sk-ant- format to verify the scrubber regex.
        $fakeKey = implode('-', ['sk', 'ant', 'api03', 'AbCdEfGhIjKlMnOpQrStUvWxYz01234567']);
        $this->assertSame('key=[scrubbed]', SentryScrubber::scrub("key={$fakeKey}"));
    }

    public function test_masks_huggingface_tokens(): void
    {
        // Uses a synthetic token in the hf_ format to verify the scrubber regex.
        $fakeToken = 'hf_' . str_repeat('Ab', 12);
        $this->assertSame('token=[scrubbed]', SentryScrubber::scrub("token={$fakeToken}"));
    }

    public function test_masks_long_opaque_path_segments(): void
    {
        $this->assertSame(
            '/api/runs/[scrubbed]',
            SentryScrubber::scrubPath('/api/runs/Zx8Kq2LmN4pR7tV1wY3aB5cD'),
        );
        $this->assertSame(
            '/api/runs/123/results',
            SentryScrubber::scrubPath('/api/runs/123/results'),
        );
    }

    public function test_strips_log_breadcrumb_text_and_data(): void
    {
        $b = SentryScrubber::breadcrumb(
            new Breadcrumb('error', 'default', 'log.error', 'Failed for user@example.com', ['email' => 'user@example.com'])
        );

        $this->assertSame('[scrubbed]', $b->getMessage());
        $this->assertSame([], $b->getMetadata());
    }

    public function test_keeps_bare_url_from_http_breadcrumb(): void
    {
        $b = SentryScrubber::breadcrumb(
            new Breadcrumb('info', 'http', 'http', 'GET', [
                'url' => 'http://127.0.0.1:8100/generate?token=abc',
                'body' => 'some training text',
            ])
        );

        $this->assertSame(['url' => 'http://127.0.0.1:8100/generate'], $b->getMetadata());
        $this->assertArrayNotHasKey('body', $b->getMetadata());
    }
}
