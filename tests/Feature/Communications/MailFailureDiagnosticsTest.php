<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Http\Controllers\Compliance\CommunicationMailboxController;
use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\HostCircuitBreaker;
use App\Services\Communications\ImapMailboxPoller;
use App\Services\Communications\ImapSentFolderAppender;
use App\Services\Communications\MailboxHealthRecorder;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-09 (Johan, diagnostics) — "fails should tell whoever is setting it
 * up why its failing... tell us why the server is rejecting the connection."
 * Proves the raw server response is captured end-to-end (poll failure, SMTP
 * send failure, IMAP Sent-folder append failure) and that the model surfaces
 * a taxonomy-correct friendly message for each reason — never a guessed
 * cause for text that matches nothing known. Fake hosts/in-process doubles
 * only; no connection to mail.hfcoastal.co.za.
 */
final class MailFailureDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function mailbox(array $overrides = []): CommunicationMailbox
    {
        return CommunicationMailbox::create(array_merge([
            'agency_id' => $this->agencyId, 'email_address' => 'office-' . Str::random(6) . '@diag-host.test',
            'imap_host' => 'diag-host.test', 'imap_port' => 993, 'username' => 'office@diag-host.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
            'poll_interval_minutes' => 15, 'active' => true,
        ], $overrides));
    }

    public function test_a_mailbox_not_found_poll_failure_is_classified_and_detail_is_kept(): void
    {
        $mailbox = $this->mailbox();
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('NO user unknown on this server');
            }
        };

        $poller->poll($mailbox);
        $mailbox->refresh();

        $this->assertSame('mailbox_not_found', $mailbox->last_error);
        $this->assertSame('NO user unknown on this server', $mailbox->last_error_detail);
        $this->assertStringContainsString('does not exist', $mailbox->lastErrorLabel());
    }

    public function test_an_unrecognised_poll_failure_is_honestly_unknown_with_raw_detail_kept(): void
    {
        $mailbox = $this->mailbox();
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('a server-specific error string nobody has seen before');
            }
        };

        $poller->poll($mailbox);
        $mailbox->refresh();

        $this->assertSame('unknown', $mailbox->last_error);
        $this->assertSame('a server-specific error string nobody has seen before', $mailbox->last_error_detail);
        $this->assertStringContainsString('raw server response', $mailbox->lastErrorLabel());
    }

    public function test_a_successful_poll_clears_the_raw_detail_not_just_the_reason(): void
    {
        $mailbox = $this->mailbox(['last_error' => 'unknown', 'last_error_at' => now(), 'last_error_detail' => 'some old raw text']);
        app(MailboxHealthRecorder::class)->recordSuccess($mailbox);
        $mailbox->refresh();

        $this->assertNull($mailbox->last_error_detail, 'a stale raw response must not linger once the mailbox has genuinely recovered');
    }

    public function test_test_connection_smtp_auth_failure_persists_the_raw_server_response(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->actingAs($admin);
        $mailbox = $this->mailbox(['smtp_host' => 'smtp.diag-host.test']);

        $transportBuilder = new class extends PerMailboxMailTransportBuilder {
            public function send(CommunicationMailbox $mailbox, \Illuminate\Mail\Mailable $mailable): string
            {
                throw new OutgoingMailboxSendFailedException(
                    'auth_failed',
                    'The server rejected the username or password.',
                    '535 5.7.8 Error: authentication failed: generic failure'
                );
            }
        };
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('not exercised here');
            }
        };
        $appender = new class($poller) extends ImapSentFolderAppender {
            public function append(CommunicationMailbox $mailbox, string $rawMime): array
            {
                return ['ok' => true, 'reason' => null, 'detail' => null];
            }
        };

        $controller = new CommunicationMailboxController();
        $rateLimiter = app(\App\Services\Communications\MailboxConnectionRateLimiter::class);
        $hostBreaker = app(HostCircuitBreaker::class);
        $controller->testConnection(new Request(), $mailbox, $transportBuilder, $appender, $rateLimiter, $hostBreaker);

        $mailbox->refresh();
        $this->assertSame('auth_failed', $mailbox->last_send_error);
        $this->assertSame('535 5.7.8 Error: authentication failed: generic failure', $mailbox->last_send_error_detail);
    }

    public function test_test_connection_imap_append_failure_persists_the_raw_server_response(): void
    {
        $admin = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->actingAs($admin);
        $mailbox = $this->mailbox();

        $transportBuilder = new class extends PerMailboxMailTransportBuilder {
            public function send(CommunicationMailbox $mailbox, \Illuminate\Mail\Mailable $mailable): string
            {
                return "Subject: test\r\n\r\nbody";
            }
        };
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public function connect(CommunicationMailbox $mailbox)
            {
                throw new \RuntimeException('not exercised here');
            }
        };
        $appender = new class($poller) extends ImapSentFolderAppender {
            public function append(CommunicationMailbox $mailbox, string $rawMime): array
            {
                return ['ok' => false, 'reason' => 'connection_refused', 'detail' => 'Connection refused by 41.1.2.3:993'];
            }
        };

        $controller = new CommunicationMailboxController();
        $rateLimiter = app(\App\Services\Communications\MailboxConnectionRateLimiter::class);
        $hostBreaker = app(HostCircuitBreaker::class);
        $response = $controller->testConnection(new Request(), $mailbox, $transportBuilder, $appender, $rateLimiter, $hostBreaker);

        $mailbox->refresh();
        $this->assertSame('connection_refused', $mailbox->last_sent_folder_append_error);
        $this->assertSame('Connection refused by 41.1.2.3:993', $mailbox->last_sent_folder_append_error_detail);

        $flashed = $response->getSession()->get('test_connection_result');
        $this->assertStringNotContainsStringIgnoringCase('password', $flashed['imap_append']['message'], 'a connection-refused message must never be worded as a credentials problem');
    }
}
