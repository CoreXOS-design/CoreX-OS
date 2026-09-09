<?php

declare(strict_types=1);

namespace Tests\Unit\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\ImapMailboxPoller;
use App\Services\Communications\ImapSentFolderAppender;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * AT-URGENT-2026-09-08 — cc2 confirmed independently, and packet capture
 * proved, that append() wrote a real message into a real agency mailbox's
 * real Sent folder from QA1, because this raw IMAP write has no relationship
 * to Illuminate\Mail and so was never covered by OutboundMailGuardServiceProvider.
 * This proves the fix: blocked before any connection attempt, in every
 * non-production shape, with production behaviour completely unchanged.
 */
final class ImapSentFolderAppenderGuardTest extends TestCase
{
    private function mailbox(): CommunicationMailbox
    {
        return new CommunicationMailbox([
            'email_address' => 'agent@example.test',
            'imap_host' => 'imap.example.test',
            'imap_port' => 993,
            'username' => 'agent@example.test',
            'encrypted_password' => 'secret',
        ]);
    }

    public function test_append_is_blocked_before_any_connection_when_not_production(): void
    {
        config(['app.env' => 'local', 'app.url' => 'https://qatesting1.corexos.co.za']);

        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public int $connectCalls = 0;

            public function connect(CommunicationMailbox $mailbox)
            {
                $this->connectCalls++;
                throw new \RuntimeException('connect() must never be called when the guard is active.');
            }
        };

        $appender = new ImapSentFolderAppender($poller);

        Log::shouldReceive('warning')
            ->once()
            ->with('OUTBOUND MAIL INTERCEPTED', \Mockery::on(
                fn ($context) => $context['action'] === 'imap_sent_folder_append'
            ));

        $result = $appender->append($this->mailbox(), 'Subject: test\r\n\r\nbody');

        $this->assertSame(['ok' => false, 'reason' => 'intercepted', 'detail' => null], $result);
        $this->assertSame(0, $poller->connectCalls, 'The IMAP connection must never be opened when the guard is active — that is the entire point of the fix.');
    }

    public function test_append_is_blocked_when_env_is_production_but_host_is_not_the_live_host(): void
    {
        // Demo box shape: APP_ENV=production, but not corexos.co.za — must still be blocked.
        config(['app.env' => 'production', 'app.url' => 'https://demo1.corexos.co.za']);

        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public int $connectCalls = 0;

            public function connect(CommunicationMailbox $mailbox)
            {
                $this->connectCalls++;
                throw new \RuntimeException('connect() must never be called when the guard is active.');
            }
        };

        $appender = new ImapSentFolderAppender($poller);
        Log::shouldReceive('warning')->once();

        $result = $appender->append($this->mailbox(), 'Subject: test\r\n\r\nbody');

        $this->assertSame('intercepted', $result['reason']);
        $this->assertSame(0, $poller->connectCalls);
    }

    public function test_append_proceeds_to_a_real_connection_attempt_on_the_confirmed_live_host(): void
    {
        config(['app.env' => 'production', 'app.url' => 'https://corexos.co.za']);

        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public int $connectCalls = 0;

            public function connect(CommunicationMailbox $mailbox)
            {
                $this->connectCalls++;
                // Fail past the guard check for a reason OTHER than the block,
                // proving we actually reached the real connection logic.
                throw new \RuntimeException('simulated connect failure — proves we got past the guard');
            }
        };

        $appender = new ImapSentFolderAppender($poller);

        $result = $appender->append($this->mailbox(), 'Subject: test\r\n\r\nbody');

        $this->assertSame(1, $poller->connectCalls, 'On the confirmed live host, append() must behave exactly as before — a real connection attempt is made.');
        $this->assertNotSame('intercepted', $result['reason']);
        // 2026-09-09 (diagnostics) — a message matching none of MailFailureClassifier's
        // known patterns classifies honestly as 'unknown', not a guessed 'connect_failed' —
        // "never invent a cause" (Johan). The raw text is still captured as $result['detail'].
        $this->assertSame('unknown', $result['reason']);
        $this->assertSame('simulated connect failure — proves we got past the guard', $result['detail']);
    }
}
