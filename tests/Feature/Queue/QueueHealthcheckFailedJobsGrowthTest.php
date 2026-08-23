<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Mail\QueueFailedJobsGrowthAlertMail;
use App\Models\DevSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * CX — 2026-08-23. The blind-spot fix: a failing job is deleted from `jobs`
 * the moment it fails, so the original oldest-waiting-job check alone would
 * report a rapidly-failing queue as healthy. checkFailedJobsGrowth() closes
 * that gap by tracking failed_jobs COUNT growth between runs.
 */
final class QueueHealthcheckFailedJobsGrowthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function insertFailedJobs(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            DB::table('failed_jobs')->insert([
                'uuid' => (string) \Illuminate\Support\Str::uuid(),
                'connection' => 'database',
                'queue' => 'default',
                'payload' => json_encode(['displayName' => 'App\\Jobs\\FakeJob']),
                'exception' => 'RuntimeException: fake',
                'failed_at' => now(),
            ]);
        }
    }

    public function test_first_ever_run_records_a_baseline_without_alerting(): void
    {
        $this->insertFailedJobs(50);

        $exitCode = Artisan::call('corex:queue-healthcheck');

        $this->assertSame(0, $exitCode, 'first run has nothing to compare against — must not alert');
    }

    public function test_small_growth_under_threshold_stays_healthy(): void
    {
        $this->insertFailedJobs(10);
        Artisan::call('corex:queue-healthcheck'); // establishes baseline
        $this->insertFailedJobs(5); // +5, under the default 25 threshold

        $exitCode = Artisan::call('corex:queue-healthcheck');

        $this->assertSame(0, $exitCode);
    }

    public function test_growth_past_threshold_is_unhealthy_and_logs_critical(): void
    {
        Log::spy();
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));
        $this->insertFailedJobs(10);
        Artisan::call('corex:queue-healthcheck', ['--max-new-failures' => 20]);
        $this->insertFailedJobs(21); // +21, over the 20 threshold

        $exitCode = Artisan::call('corex:queue-healthcheck', ['--max-new-failures' => 20]);

        $this->assertSame(1, $exitCode);
        Log::shouldHaveReceived('critical')->withArgs(fn ($msg) => str_contains($msg, 'GROWING FAST'));
    }

    public function test_growth_past_threshold_sends_an_alert_email_when_configured(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode(['ops@example.test']));
        Mail::fake();
        $this->insertFailedJobs(10);
        Artisan::call('corex:queue-healthcheck', ['--max-new-failures' => 20]);
        $this->insertFailedJobs(21);

        Artisan::call('corex:queue-healthcheck', ['--max-new-failures' => 20]);

        Mail::assertSent(QueueFailedJobsGrowthAlertMail::class);
    }

    public function test_a_persistently_large_backlog_does_not_retrigger_every_run(): void
    {
        // The historical 16,882-row backlog must not itself trip this check
        // forever — only NEW growth since the last run should.
        Log::spy();
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));
        $this->insertFailedJobs(500); // large existing total
        Artisan::call('corex:queue-healthcheck'); // baseline includes the 500

        $exitCode = Artisan::call('corex:queue-healthcheck'); // no new rows since baseline

        $this->assertSame(0, $exitCode, 'a large pre-existing total must not itself be treated as growth');
    }
}
