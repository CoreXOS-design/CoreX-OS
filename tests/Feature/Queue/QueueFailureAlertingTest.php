<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Mail\FeedbackReportMail;
use App\Mail\OversightNudgeMail;
use App\Mail\QueueFailedJobsGrowthAlertMail;
use App\Mail\QueueJobFailureDigestMail;
use App\Models\DevSetting;
use App\Models\OversightNudge;
use App\Models\User;
use App\Support\Queue\QueueFailureAlerter;
use Illuminate\Contracts\Queue\Job as QueueJobContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * CX — 2026-08-23. Covers: the OversightNudgeMail/FeedbackReportMail mail-
 * namespace fix (root cause of 10,356 failed_jobs rows), Queue::failing()'s
 * new alerting (App\Support\Queue\QueueFailureAlerter — the fix for a hook
 * that previously did nothing), and the debounce/aggregation contract
 * ("10,000 failures of the same class produce a digest, not 10,000 alerts").
 */
final class QueueFailureAlertingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    // ──────────────────────── Mail-namespace fix ────────────────────────

    public function test_oversight_nudge_mail_renders_without_the_mail_namespace_error(): void
    {
        $manager = User::factory()->create();
        $nudge = new OversightNudge([
            'agency_id' => $manager->agency_id, 'from_user_id' => $manager->id, 'to_user_id' => $manager->id,
            'category' => 'stale_listing_follow_up', 'message' => 'Test nudge', 'sent_at' => now(),
        ]);
        $nudge->id = 1;

        $html = (new OversightNudgeMail($nudge, $manager))->render();

        $this->assertStringContainsString('A manager has nudged you', $html);
    }

    public function test_feedback_report_mail_renders_without_the_mail_namespace_error(): void
    {
        $report = (object) ['type' => 'bug', 'title' => 'Test report', 'severity' => 'high', 'description' => 'x'];
        $html = (new FeedbackReportMail($report, null, collect()))->render();

        $this->assertNotEmpty($html);
    }

    // ──────────────────────── Queue::failing() alerting ────────────────────────

    public function test_a_job_failure_logs_critical_unconditionally(): void
    {
        Log::spy();
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));

        QueueFailureAlerter::handle($this->fakeFailedEvent('App\\Jobs\\SomeJob', 'default'));

        Log::shouldHaveReceived('critical')
            ->once()
            ->withArgs(fn ($msg, $ctx) => str_contains($msg, 'App\\Jobs\\SomeJob')
                && $ctx['job_class'] === 'App\\Jobs\\SomeJob'
                && $ctx['exception_class'] === \RuntimeException::class);
    }

    public function test_the_log_fires_even_when_no_alert_emails_are_configured(): void
    {
        Log::spy();
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));
        Mail::fake();

        QueueFailureAlerter::handle($this->fakeFailedEvent('App\\Jobs\\NoRecipientsJob', 'default'));

        Log::shouldHaveReceived('critical')->once();
        Mail::assertNothingSent();
    }

    public function test_first_failure_of_a_class_sends_a_digest_email(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode(['ops@example.test']));
        Mail::fake();

        QueueFailureAlerter::handle($this->fakeFailedEvent('App\\Jobs\\FirstFailureJob', 'default'));

        Mail::assertSent(QueueJobFailureDigestMail::class, fn ($m) => $m->jobClass === 'App\\Jobs\\FirstFailureJob');
    }

    public function test_ten_thousand_failures_of_the_same_class_produce_one_digest_not_ten_thousand(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode(['ops@example.test']));
        Mail::fake();

        for ($i = 0; $i < 25; $i++) {
            QueueFailureAlerter::handle($this->fakeFailedEvent('App\\Jobs\\SpamProneJob', 'default'));
        }

        Mail::assertSent(QueueJobFailureDigestMail::class, 1);
    }

    public function test_different_job_classes_each_get_their_own_alert_even_within_the_same_window(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode(['ops@example.test']));
        Mail::fake();

        QueueFailureAlerter::handle($this->fakeFailedEvent('App\\Jobs\\ClassA', 'default'));
        QueueFailureAlerter::handle($this->fakeFailedEvent('App\\Jobs\\ClassB', 'default'));

        Mail::assertSent(QueueJobFailureDigestMail::class, 2);
    }

    public function test_the_digest_mailable_renders_with_the_correct_queue_and_connection_not_the_mailables_own_reserved_properties(): void
    {
        // Regression guard: Mailable::buildViewData() auto-injects ALL public
        // properties via reflection, including ones inherited from the
        // Queueable trait ($queue, $connection) — those silently override an
        // identically-named `with()` key. Found during manual verification
        // (2026-08-23): the view rendered "on the `` queue ( connection)"
        // until the constructor/view variables were renamed off those names.
        $mail = new QueueJobFailureDigestMail(
            jobClass: 'App\\Jobs\\SomeJob',
            queueName: 'my-real-queue',
            queueConnection: 'my-real-connection',
            exceptionClass: 'RuntimeException',
            exceptionMessage: 'boom',
            recentCount: 3,
            windowLabel: '15 min',
            host: 'test-host',
            checkedAt: '2026-01-01 00:00:00',
        );

        $html = $mail->render();

        $this->assertStringContainsString('my-real-queue', $html);
        $this->assertStringContainsString('my-real-connection', $html);
    }

    public function test_the_growth_alert_mailable_renders_cleanly(): void
    {
        $html = (new QueueFailedJobsGrowthAlertMail(420, 15, 16420, 'test-host', '2026-01-01 00:00:00'))->render();

        $this->assertStringContainsString('420', $html);
        $this->assertStringContainsString('16420', $html);
    }

    public function test_a_broken_mail_send_does_not_prevent_the_critical_log_from_firing(): void
    {
        // The circularity Johan asked about: if the alert channel itself is
        // mail and mail is broken, the alert must not go silent. The log
        // fires BEFORE the mail attempt and is unconditional; a mail
        // exception is caught and logged separately, never suppressing it.
        Log::spy();
        DevSetting::set('queue_backlog_alert_emails', json_encode(['ops@example.test']));
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('SMTP is down'));

        QueueFailureAlerter::handle($this->fakeFailedEvent('App\\Jobs\\WhenMailIsBrokenJob', 'default'));

        Log::shouldHaveReceived('critical')->once();
        Log::shouldHaveReceived('error')->once()->withArgs(fn ($msg) => str_contains($msg, 'failed to send digest email'));
    }

    private function fakeFailedEvent(string $jobClass, string $queue): JobFailed
    {
        $job = new class($jobClass, $queue) implements QueueJobContract {
            public function __construct(private string $class, private string $queueName) {}
            public function uuid() { return 'test-uuid'; }
            public function getJobId() { return 'test-job-id'; }
            public function payload() { return []; }
            public function fire() {}
            public function release($delay = 0) {}
            public function isReleased() { return false; }
            public function delete() {}
            public function isDeleted() { return false; }
            public function isDeletedOrReleased() { return false; }
            public function attempts() { return 1; }
            public function hasFailed() { return true; }
            public function markAsFailed() {}
            public function fail($e = null) {}
            public function maxTries() { return null; }
            public function maxExceptions() { return null; }
            public function timeout() { return null; }
            public function retryUntil() { return null; }
            public function getName() { return $this->class; }
            public function resolveName() { return $this->class; }
            public function resolveQueuedJobClass() { return $this->class; }
            public function getConnectionName() { return 'database'; }
            public function getQueue() { return $this->queueName; }
            public function getRawBody() { return ''; }
        };

        return new JobFailed('database', $job, new \RuntimeException('Deliberate test failure'));
    }
}
