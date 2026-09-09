<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Http\Controllers\Compliance\CommunicationMailboxController;
use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use App\Services\Communications\EmailArchiveIngestor;
use App\Services\Communications\ImapMailboxPoller;
use App\Services\Communications\ImapSentFolderAppender;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-URGENT-2026-09-08 — proves the Test Connection caller does not turn a
 * deliberate, expected safety skip into a red "Fail" shown to the agent.
 * Exercises the real controller method (not a copy of its logic) with a
 * guard-shaped SMTP failure, the same exception PerMailboxMailTransportBuilder
 * actually throws when the mail guard blocks the send.
 */
final class TestConnectionBlockedAppendTest extends TestCase
{
    use RefreshDatabase;

    public function test_blocked_append_is_not_reported_as_a_failure(): void
    {
        config(['app.env' => 'local', 'app.url' => 'https://qatesting1.corexos.co.za']);

        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'D',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $admin = User::factory()->create(['agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'admin']);
        $this->actingAs($admin);

        $mailbox = CommunicationMailbox::create([
            'agency_id' => $agencyId, 'email_address' => 'office@agency.test',
            'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => 'office@agency.test',
            'encrypted_password' => 'secret', 'poll_interval_minutes' => 15, 'active' => true,
            'smtp_host' => 'smtp.agency.test', 'smtp_port' => 587, 'smtp_encryption' => 'tls',
        ]);

        // Same shape PerMailboxMailTransportBuilder::send() actually throws when
        // OutboundMailGuardServiceProvider blocks the MessageSending event —
        // see PerMailboxMailTransportBuilder.php line 96.
        $blockedSmtpBuilder = new class extends PerMailboxMailTransportBuilder {
            public function send(CommunicationMailbox $mailbox, \Illuminate\Mail\Mailable $mailable): string
            {
                throw new OutgoingMailboxSendFailedException('send_rejected', 'The mail server did not confirm the message was sent.');
            }
        };

        // Real appender, real guard check, with a poller that would prove a
        // real connection if the guard somehow let it through.
        $poller = new class(app(EmailArchiveIngestor::class)) extends ImapMailboxPoller {
            public int $connectCalls = 0;

            public function connect(CommunicationMailbox $mailbox)
            {
                $this->connectCalls++;
                throw new \RuntimeException('must never be reached when the guard is active');
            }
        };
        $appender = new ImapSentFolderAppender($poller);

        $controller = new CommunicationMailboxController();
        $rateLimiter = app(\App\Services\Communications\MailboxConnectionRateLimiter::class);
        $response = $controller->testConnection(new Request(), $mailbox, $blockedSmtpBuilder, $appender, $rateLimiter);

        $flashed = $response->getSession()->get('test_connection_result');

        $this->assertSame(0, $poller->connectCalls, 'No real IMAP connection may be attempted when the guard is active.');
        $this->assertFalse($flashed['smtp']['ok'], 'The SMTP leg is genuinely blocked in this environment — that part is unchanged.');
        $this->assertTrue($flashed['imap_append']['ok'], 'A guard-blocked append must not render as a Fail — nothing was attempted, it is not an error.');
        $this->assertStringNotContainsStringIgnoringCase('fail', $flashed['imap_append']['message']);
        $this->assertStringContainsString('interception is currently on', $flashed['imap_append']['message']);

        $mailbox->refresh();
        $this->assertNull($mailbox->last_sent_folder_append_error, 'A skipped append must not record an "error" reason against the mailbox — nothing about its real append health is known from this attempt.');
    }
}
