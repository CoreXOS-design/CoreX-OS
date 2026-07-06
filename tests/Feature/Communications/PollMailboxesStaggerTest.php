<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Jobs\Communications\PollMailboxJob;
use App\Models\Communications\CommunicationMailbox;
use App\Models\PerformanceSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Queue-starvation fix — the mailbox poll herd. All mailboxes sharing an
 * interval fall due in the same tick; un-staggered, the whole fleet lands on
 * the queue at once and each slow IMAP poll head-of-line-blocks every other
 * job. These tests prove dispatch is spread by an operator-tunable interval:
 * delays 0, s, 2s, … honoured from settings, a safety cap, and a 0-disables
 * escape hatch.
 */
final class PollMailboxesStaggerTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-06 12:00:00'));

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'T ' . Str::random(5), 'slug' => 'tt-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Create N active, never-polled (⇒ due) mailboxes, returned in id order. */
    private function dueMailboxes(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $ids[] = CommunicationMailbox::create([
                'agency_id' => $this->agencyId, 'email_address' => "box{$i}@agency.test",
                'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => "box{$i}@agency.test",
                'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
                'poll_interval_minutes' => 15, 'active' => true, 'last_polled_at' => null,
            ])->id;
        }
        return $ids;
    }

    /** delay-in-seconds-from-now for each dispatched PollMailboxJob, keyed by mailboxId. */
    private function dispatchedDelays(): array
    {
        $delays = [];
        Queue::assertPushed(PollMailboxJob::class, function (PollMailboxJob $job) use (&$delays) {
            $delays[$job->mailboxId] = $job->delay === null ? null : (int) now()->diffInSeconds($job->delay, false);
            return true;
        });
        ksort($delays);
        return array_values($delays);
    }

    public function test_default_stagger_is_five_seconds_per_mailbox(): void
    {
        Queue::fake();
        $this->dueMailboxes(4);

        $this->artisan('communications:poll-mailboxes')->assertSuccessful();

        // index 0 = immediate (null delay), then +5s each.
        $this->assertSame([null, 5, 10, 15], $this->dispatchedDelays());
    }

    public function test_stagger_interval_is_read_from_settings(): void
    {
        PerformanceSetting::create(['key' => 'mailbox_poll_stagger_seconds', 'value' => '10']);
        Queue::fake();
        $this->dueMailboxes(3);

        $this->artisan('communications:poll-mailboxes')->assertSuccessful();

        $this->assertSame([null, 10, 20], $this->dispatchedDelays());
    }

    public function test_zero_stagger_dispatches_all_immediately(): void
    {
        PerformanceSetting::create(['key' => 'mailbox_poll_stagger_seconds', 'value' => '0']);
        Queue::fake();
        $this->dueMailboxes(3);

        $this->artisan('communications:poll-mailboxes')->assertSuccessful();

        $this->assertSame([null, null, null], $this->dispatchedDelays());
    }

    public function test_delay_is_capped_at_configured_maximum(): void
    {
        PerformanceSetting::create(['key' => 'mailbox_poll_stagger_seconds', 'value' => '100']);
        PerformanceSetting::create(['key' => 'mailbox_poll_stagger_max_seconds', 'value' => '250']);
        Queue::fake();
        $this->dueMailboxes(5);

        $this->artisan('communications:poll-mailboxes')->assertSuccessful();

        // 0,100,200,300→cap 250,400→cap 250
        $this->assertSame([null, 100, 200, 250, 250], $this->dispatchedDelays());
    }

    public function test_only_due_mailboxes_are_dispatched(): void
    {
        Queue::fake();
        $this->dueMailboxes(2);
        // A freshly-polled mailbox on a 15-min interval is NOT due.
        CommunicationMailbox::create([
            'agency_id' => $this->agencyId, 'email_address' => 'fresh@agency.test',
            'imap_host' => 'imap.agency.test', 'imap_port' => 993, 'username' => 'fresh@agency.test',
            'encrypted_password' => 'secret', 'poll_inbox' => true, 'poll_sent' => false,
            'poll_interval_minutes' => 15, 'active' => true, 'last_polled_at' => now()->subMinutes(2),
        ]);

        $this->artisan('communications:poll-mailboxes')->assertSuccessful();

        Queue::assertPushed(PollMailboxJob::class, 2);
    }
}
