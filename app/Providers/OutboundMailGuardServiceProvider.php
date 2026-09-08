<?php

namespace App\Providers;

use App\Support\OutboundMailGuard;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\ServiceProvider;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Email;

/**
 * AT-URGENT-2026-09-08 — the hard outbound-mail safety guard.
 *
 * Registers ONE listener on Illuminate\Mail\Events\MessageSending. Every
 * outbound message in this application funnels through
 * Illuminate\Mail\Mailer::send() -> shouldSendMessage() -> this event,
 * regardless of which mailer sent it: the default mailer, the 'otp' and
 * 'corex' named mailers (which are wired to bypass the default mailer on
 * purpose), Notifications' mail channel, queued mail (re-fires identically
 * on a worker, since service providers boot the same way there), and the
 * per-mailbox direct-SMTP feature (App\Services\Communications\
 * PerMailboxMailTransportBuilder) — confirmed by reading that class
 * directly: it builds its Mailer with `app('events')` as the 4th
 * constructor argument, so it fires this exact same event. Nothing in
 * that file or its three "Test Connection" callers needed to change.
 *
 * Laravel's own Mailer::shouldSendMessage() treats a listener returning
 * false as a veto — the transport's send() is never called, so no TCP
 * connection to any real mail server is attempted. That is the actual
 * safety boundary, not a recipient rewrite on a transport we don't trust.
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
        if (OutboundMailGuard::isProductionConfirmed()) {
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

        Log::warning('OUTBOUND MAIL BLOCKED — non-production environment', [
            'app_env' => config('app.env'),
            'app_url' => config('app.url'),
            'subject' => $original->getSubject(),
            'to' => $originalTo,
            'cc' => $originalCc,
            'bcc' => $originalBcc,
        ]);

        try {
            $this->sendRedirectedCopy($original, $originalTo, $originalCc, $originalBcc);
        } catch (\Throwable $e) {
            // The redirect landing in Mailpit is a convenience for testing,
            // not the safety boundary. Its failure must never re-open the
            // gate — the original send stays cancelled either way.
            Log::error('OUTBOUND MAIL GUARD — redirected copy failed to send, original send stays blocked', [
                'error' => $e->getMessage(),
            ]);
        }

        return false;
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
