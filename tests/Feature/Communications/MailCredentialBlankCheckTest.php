<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Exceptions\Communications\OutgoingMailboxSendFailedException;
use App\Models\Agency as AgencyModel;
use App\Models\Branch;
use App\Models\Communications\CommunicationMailbox;
use App\Models\User;
use App\Services\Communications\ImapMailboxPoller;
use App\Services\Communications\ImapSentFolderAppender;
use App\Services\Communications\PerMailboxMailTransportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-09 (Johan, real-attempt-honesty incident) — the three real-connection
 * services (PerMailboxMailTransportBuilder::send, ImapSentFolderAppender::append,
 * ImapMailboxPoller::poll) each gated on empty($host)/empty($username)/empty($password)
 * before attempting a real connection — the same bug class as the controller-side
 * password write: a credential of literally "0" would be treated as missing and
 * the attempt refused with 'incomplete_credentials', never reaching the network at
 * all. Fixed with blank() (null/whitespace-only), which correctly treats "0" as a
 * real value.
 *
 * These tests prove the GUARD itself no longer misfires on "0" — they do not
 * (and must not) reach a real external mail server. host="0" is not a resolvable
 * hostname, so the real attempt that follows the guard fails fast on DNS/connect,
 * which is exactly the proof needed: the failure reason is a connect-class one,
 * never 'incomplete_credentials'.
 */
final class MailCredentialBlankCheckTest extends TestCase
{
    use RefreshDatabase;

    private function mailboxWithZeroCredentials(array $overrides = []): CommunicationMailbox
    {
        $agency = AgencyModel::create(['name' => 'T ' . Str::random(5), 'slug' => 't-' . Str::random(8)]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'D']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return CommunicationMailbox::create(array_merge([
            'agency_id' => $agency->id, 'user_id' => $agent->id, 'set_by' => 'agency', 'auth_type' => 'imap',
            'email_address' => 'zero-cred@example.test',
            'imap_host' => '0', 'imap_port' => 993, 'username' => '0', 'encrypted_password' => '0',
            'smtp_host' => '0', 'smtp_port' => 587, 'use_imap_credentials_for_smtp' => true,
            'poll_inbox' => true, 'poll_sent' => true, 'poll_interval_minutes' => 15, 'active' => true,
        ], $overrides));
    }

    public function test_transport_builder_does_not_treat_all_zero_credentials_as_incomplete(): void
    {
        Mail::fake(); // belt-and-braces: nothing must actually send even if DNS somehow resolved '0'
        $mailbox = $this->mailboxWithZeroCredentials();
        $mailable = new \Illuminate\Mail\Mailable();

        try {
            app(PerMailboxMailTransportBuilder::class)->send($mailbox, $mailable);
            $this->fail('host "0" is not a real mail server -- send() must throw');
        } catch (OutgoingMailboxSendFailedException $e) {
            $this->assertNotSame('incomplete_credentials', $e->sanitisedReason, 'a credential of "0" must not be treated as missing');
        }
    }

    public function test_imap_appender_does_not_treat_all_zero_credentials_as_incomplete(): void
    {
        $mailbox = $this->mailboxWithZeroCredentials();

        $result = app(ImapSentFolderAppender::class)->append($mailbox, "Subject: x\r\n\r\nbody");

        $this->assertFalse($result['ok']);
        $this->assertNotSame('incomplete_credentials', $result['reason'], 'a credential of "0" must not be treated as missing');
    }

    public function test_poller_does_not_treat_all_zero_credentials_as_incomplete(): void
    {
        $mailbox = $this->mailboxWithZeroCredentials();

        $result = app(ImapMailboxPoller::class)->poll($mailbox);

        $this->assertSame('error', $result['status']);
        $this->assertNotSame('incomplete_credentials', $result['reason'], 'a credential of "0" must not be treated as missing');
    }
}
