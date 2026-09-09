<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Mail\QueueBacklogAlertMail;
use App\Models\OutboundMailGuardCapture;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * AT-URGENT-2026-09-09 — "There is no Mailpit on live... it must be
 * captured, visible, and countable." Proves an intercepted send is
 * durably recorded, independent of whether a local sink exists — the
 * scenario that specifically matters for production, where it never does.
 */
final class OutboundMailGuardCaptureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_an_intercepted_send_is_captured_durably_on_an_environment_with_no_local_sink(): void
    {
        // Simulates production's shape: sends by default, but with a mail
        // host that is NOT the local Mailpit-style catcher — hasLocalSink()
        // must be false here, same as real production.
        config([
            'app.env' => 'production',
            'app.url' => 'https://corexos.co.za',
            'mail.mailers.smtp.host' => 'smtp.real-provider.example',
            'mail.mailers.smtp.port' => 587,
        ]);
        \App\Models\DevSetting::set(\App\Support\OutboundMailGuard::TOGGLE_KEY, '1'); // force intercept on production

        $this->assertSame(0, OutboundMailGuardCapture::count());

        Mail::to('someone@example.test')->send(new QueueBacklogAlertMail(
            lane: 'default', ageSeconds: 10, backlog: 1, maxAge: 60,
            supervisor: 'x', host: 'x', checkedAt: 'x',
        ));

        $this->assertSame(1, OutboundMailGuardCapture::count());
        $capture = OutboundMailGuardCapture::first();
        $this->assertSame('production', $capture->environment);
        $this->assertStringContainsString('someone@example.test', $capture->to_addresses);
        $this->assertFalse($capture->forwarded_to_sink, 'no local sink exists on this environment shape — must not claim one was tried and worked');
        $this->assertStringContainsString('Queue backlog', $capture->raw_mime, 'the FULL original message must be preserved, not a redacted summary');
    }

    public function test_an_intercepted_send_is_forwarded_to_the_sink_when_one_exists(): void
    {
        // QA1/Staging shape — mail hosts still point at the local catcher.
        config([
            'app.env' => 'qa',
            'app.url' => 'https://qatesting1.corexos.co.za',
            'mail.mailers.smtp.host' => '127.0.0.1',
            'mail.mailers.smtp.port' => 1025,
            'mail.mailers.corex.host' => '127.0.0.1',
            'mail.mailers.corex.port' => 1025,
            'mail.mailers.otp.host' => '127.0.0.1',
            'mail.mailers.otp.port' => 1025,
        ]);

        // The sink forward itself will fail in this sandbox (no real Mailpit
        // listening) — that must not stop the capture from being written.
        Mail::to('someone@example.test')->send(new QueueBacklogAlertMail(
            lane: 'default', ageSeconds: 10, backlog: 1, maxAge: 60,
            supervisor: 'x', host: 'x', checkedAt: 'x',
        ));

        $this->assertSame(1, OutboundMailGuardCapture::count(), 'capture must be written even when the best-effort sink forward fails');
    }
}
