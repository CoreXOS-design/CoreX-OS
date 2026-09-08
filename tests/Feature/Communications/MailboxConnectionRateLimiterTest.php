<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\CommunicationMailbox;
use App\Services\Communications\MailboxConnectionRateLimiter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 2026-09-08/09 (Johan) — proves the fix for the actual, confirmed cause of
 * today's Afrihost ban: Test Connection opened ~40 real logins to one host
 * from one IP within minutes because nothing throttled repeated clicks. Not
 * the poller, not bad credentials — this is what needed fixing.
 *
 * Deliberately never contacts any real host — this class only decides
 * allow/refuse; the network call is made by the caller AFTER checking
 * tooManyAttempts(), which these tests never do.
 */
final class MailboxConnectionRateLimiterTest extends TestCase
{
    use RefreshDatabase;

    private function mailbox(array $overrides = []): CommunicationMailbox
    {
        $agency = \App\Models\Agency::create(['name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8)]);

        return CommunicationMailbox::create(array_merge([
            'agency_id' => $agency->id, 'email_address' => 'office@ratelimit-test.invalid',
            'imap_host' => 'ratelimit-test.invalid', 'imap_port' => 993, 'username' => 'office@ratelimit-test.invalid',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
            'poll_interval_minutes' => 15, 'active' => true,
        ], $overrides));
    }

    protected function tearDown(): void
    {
        RateLimiter::clear('communications:test-connection:ratelimit-test.invalid');
        RateLimiter::clear('communications:test-connection:ratelimit-smtp-test.invalid');
        parent::tearDown();
    }

    public function test_the_configured_number_of_attempts_are_allowed_then_the_next_is_refused(): void
    {
        config(['communications.test_connection_rate_limit_max_attempts' => 3, 'communications.test_connection_rate_limit_window_seconds' => 300]);
        $mailbox = $this->mailbox();
        $limiter = app(MailboxConnectionRateLimiter::class);

        for ($i = 1; $i <= 3; $i++) {
            $this->assertFalse($limiter->tooManyAttempts($mailbox), "attempt {$i} of 3 must be allowed");
            $limiter->hit($mailbox);
        }

        $this->assertTrue($limiter->tooManyAttempts($mailbox), 'the 4th attempt within the window must be refused -- this is the exact burst that caused the ban');
    }

    public function test_checking_the_limit_never_itself_counts_as_an_attempt(): void
    {
        config(['communications.test_connection_rate_limit_max_attempts' => 2]);
        $mailbox = $this->mailbox();
        $limiter = app(MailboxConnectionRateLimiter::class);

        // Check ten times without ever calling hit() -- a refused/never-attempted
        // click must not itself burn part of the budget.
        for ($i = 0; $i < 10; $i++) {
            $this->assertFalse($limiter->tooManyAttempts($mailbox));
        }
    }

    public function test_the_limit_is_shared_across_mailboxes_on_the_same_host_not_per_mailbox(): void
    {
        // This is the exact scenario from this morning: 20 DIFFERENT mailbox
        // rows, all pointed at the SAME real host. A per-mailbox limit would
        // have let every single one through its own individual quota -- still
        // a burst of dozens of real logins to the one host that got banned.
        config(['communications.test_connection_rate_limit_max_attempts' => 2]);
        $limiter = app(MailboxConnectionRateLimiter::class);

        $mailboxA = $this->mailbox(['email_address' => 'a@ratelimit-test.invalid', 'username' => 'a@ratelimit-test.invalid']);
        $mailboxB = $this->mailbox(['email_address' => 'b@ratelimit-test.invalid', 'username' => 'b@ratelimit-test.invalid']);

        $limiter->hit($mailboxA);
        $limiter->hit($mailboxB); // different mailbox, SAME host -- must count against the same budget

        $this->assertTrue($limiter->tooManyAttempts($mailboxA), 'the shared host budget is exhausted, regardless of which mailbox is asking');
        $this->assertTrue($limiter->tooManyAttempts($mailboxB));
    }

    public function test_a_different_host_has_its_own_independent_limit(): void
    {
        config(['communications.test_connection_rate_limit_max_attempts' => 1]);
        $limiter = app(MailboxConnectionRateLimiter::class);

        $hostA = $this->mailbox(['imap_host' => 'ratelimit-test.invalid']);
        $hostB = $this->mailbox(['imap_host' => 'ratelimit-other-test.invalid', 'email_address' => 'x@ratelimit-other-test.invalid', 'username' => 'x@ratelimit-other-test.invalid']);

        $limiter->hit($hostA);

        $this->assertTrue($limiter->tooManyAttempts($hostA));
        $this->assertFalse($limiter->tooManyAttempts($hostB), 'a different host must not be affected by another host exhausting its own limit');

        RateLimiter::clear('communications:test-connection:ratelimit-other-test.invalid');
    }

    public function test_smtp_host_is_also_throttled_when_it_differs_from_imap_and_outgoing_is_enabled(): void
    {
        // Test Connection's SMTP leg goes to a DIFFERENT real server than the
        // IMAP leg whenever the agency configured a distinct SMTP host and
        // opted out of sharing IMAP credentials -- that leg's login attempts
        // must be throttled too, or a burst there could still trip a ban.
        config(['communications.test_connection_rate_limit_max_attempts' => 1]);
        $mailbox = $this->mailbox([
            'outgoing_enabled' => true,
            'use_imap_credentials_for_smtp' => false,
            'smtp_host' => 'ratelimit-smtp-test.invalid',
            'smtp_port' => 587,
        ]);
        $limiter = app(MailboxConnectionRateLimiter::class);

        $limiter->hit($mailbox);

        $this->assertTrue(
            \Illuminate\Support\Facades\RateLimiter::tooManyAttempts('communications:test-connection:ratelimit-smtp-test.invalid', 1),
            'the distinct SMTP host must have been hit too, not just the IMAP host'
        );
    }

    public function test_throttled_message_is_plain_english_and_names_the_host(): void
    {
        config(['communications.test_connection_rate_limit_max_attempts' => 1]);
        $mailbox = $this->mailbox();
        $limiter = app(MailboxConnectionRateLimiter::class);
        $limiter->hit($mailbox);

        $message = $limiter->throttledMessage($mailbox);

        $this->assertStringContainsString('ratelimit-test.invalid', $message);
        $this->assertStringContainsString('wait', strtolower($message));
        $this->assertStringNotContainsString('Exception', $message, 'never a raw exception string shown to the user');
    }

    public function test_agency_override_narrows_the_limit_below_the_config_default(): void
    {
        config(['communications.test_connection_rate_limit_max_attempts' => 5]);
        $mailbox = $this->mailbox();
        // Not in Agency::$fillable (same as the existing communication_failure_alert_threshold
        // override) -- set via a raw update, matching the established test pattern in
        // MailboxHealthTest.php::test_agency_threshold_override_is_honoured().
        \Illuminate\Support\Facades\DB::table('agencies')->where('id', $mailbox->agency_id)->update(['communication_test_connection_max_attempts' => 1]);
        $mailbox->refresh();
        $limiter = app(MailboxConnectionRateLimiter::class);

        $limiter->hit($mailbox);

        $this->assertTrue($limiter->tooManyAttempts($mailbox), 'the agency override (1) must win over the config default (5)');
    }
}
