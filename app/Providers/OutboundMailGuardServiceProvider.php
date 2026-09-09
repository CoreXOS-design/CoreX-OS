<?php

namespace App\Providers;

use App\Models\OutboundMailGuardCapture;
use App\Support\OutboundMailGuard;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * AT-URGENT-2026-09-08/09 — the outbound-mail safety guard, now a genuine
 * kill switch (see OutboundMailGuard's own docblock for the full design).
 *
 * Registers ONE listener on Illuminate\Mail\Events\MessageSending. Every
 * outbound message in this application funnels through
 * Illuminate\Mail\Mailer::send() -> shouldSendMessage() -> this event,
 * regardless of which mailer sent it: the default mailer, the 'otp' and
 * 'corex' named mailers, Notifications' mail channel, queued mail, and the
 * per-mailbox direct-SMTP feature (PerMailboxMailTransportBuilder, which
 * builds its Mailer with app('events') as the 4th constructor argument, so
 * it fires this exact same event).
 *
 * Laravel's own Mailer::shouldSendMessage() treats a listener returning
 * false as a veto — the transport's send() is never called, so no TCP
 * connection to any real mail server is attempted. That is the actual
 * safety boundary, not a recipient rewrite on a transport we don't trust.
 *
 * 2026-09-09 — an intercepted message is now ALWAYS captured durably
 * (OutboundMailGuardCapture) before anything else. That is the source of
 * truth Johan asked for ("142 messages were held, here they are") — it
 * works identically whether or not a local sink exists, which matters
 * because live has none. The redirected-copy-to-Mailpit send below is kept
 * ONLY as a developer convenience on environments that actually have one
 * (OutboundMailGuard::hasLocalSink()) — its failure never affects the
 * capture record and never re-opens the gate.
 */
class OutboundMailGuardServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app['events']->listen(MessageSending::class, function (MessageSending $event) {
            return $this->guard($event);
        });
    }

    private function guard(MessageSending $event): bool
    {
        if (! OutboundMailGuard::isActive()) {
            return true;
        }

        // This is our own redirected copy being sent through the sink
        // transport below — never re-intercept it, or nothing would ever
        // actually reach Mailpit.
        if ($event->message->getHeaders()->has(OutboundMailGuard::REDIRECTED_HEADER)) {
            return true;
        }

        $original = $event->message;

        $originalTo = $this->formatAddresses($original->getTo());
        $originalCc = $this->formatAddresses($original->getCc());
        $originalBcc = $this->formatAddresses($original->getBcc());

        Log::warning('OUTBOUND MAIL INTERCEPTED', [
            'app_env' => config('app.env'),
            'app_url' => config('app.url'),
            'subject' => $original->getSubject(),
            'to' => $originalTo,
            'cc' => $originalCc,
            'bcc' => $originalBcc,
        ]);

        $forwarded = false;
        if (OutboundMailGuard::hasLocalSink()) {
            try {
                $this->sendRedirectedCopy($original, $originalTo, $originalCc, $originalBcc);
                $forwarded = true;
            } catch (\Throwable $e) {
                // The redirect landing in Mailpit is a convenience for testing,
                // not the safety boundary. Its failure must never re-open the
                // gate — the original send stays cancelled either way.
                Log::error('OUTBOUND MAIL GUARD — redirected copy failed to send, original send stays blocked', [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->capture($original, $originalTo, $originalCc, $originalBcc, $forwarded);

        return false;
    }

    /**
     * The actual safety-net record. Never allowed to re-open the gate on
     * failure, same principle as the sink-forward above — a DB error here
     * must not turn an intercepted send into a real one.
     */
    private function capture(Email $original, string $to, string $cc, string $bcc, bool $forwarded): void
    {
        try {
            OutboundMailGuardCapture::create([
                'to_addresses' => $to,
                'cc_addresses' => $cc !== '' ? $cc : null,
                'bcc_addresses' => $bcc !== '' ? $bcc : null,
                'subject' => (string) $original->getSubject(),
                'raw_mime' => $original->toString(),
                'environment' => (string) config('app.env'),
                'forwarded_to_sink' => $forwarded,
                'captured_at' => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('OUTBOUND MAIL GUARD — failed to persist capture record', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function sendRedirectedCopy(Email $original, string $to, string $cc, string $bcc): void
    {
        $copy = clone $original;

        $copy->to(OutboundMailGuard::sinkAddress());
        $copy->cc();
        $copy->bcc();
        $copy->getHeaders()->addTextHeader(OutboundMailGuard::REDIRECTED_HEADER, '1');

        $banner = "This message was intercepted by the CoreX outbound mail guard.\n"
            . "Environment: " . (string) config('app.env') . " (" . (string) config('app.url') . ")\n"
            . "It would have gone to:\n"
            . "  To:  {$to}\n"
            . "  Cc:  " . ($cc !== '' ? $cc : '(none)') . "\n"
            . "  Bcc: " . ($bcc !== '' ? $bcc : '(none)') . "\n"
            . str_repeat('-', 60) . "\n\n";

        $copy->subject('[GUARDED] ' . (string) $original->getSubject());

        $text = $original->getTextBody();
        $copy->text($banner . (is_string($text) ? $text : ''));

        $html = $original->getHtmlBody();
        if (is_string($html) && $html !== '') {
            $htmlBanner = '<pre style="background:#fee2e2;border:2px solid #dc2626;padding:12px;'
                . 'font-family:monospace;white-space:pre-wrap;">' . htmlspecialchars($banner) . '</pre>';
            $copy->html($htmlBanner . $html);
        }

        $transport = new EsmtpTransport(OutboundMailGuard::sinkHost(), OutboundMailGuard::sinkPort(), false);
        $transport->send($copy);
    }

    /**
     * @param  \Symfony\Component\Mime\Address[]  $addresses
     */
    private function formatAddresses(array $addresses): string
    {
        return implode(', ', array_map(fn ($a) => $a->toString(), $addresses));
    }
}
