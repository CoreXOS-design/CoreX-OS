<?php

namespace Tests\Feature\System;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\DevSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\System\QueueWatchdogService;
use App\Services\System\QueueWorkerProcessLister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * .ai/specs/queue-worker-monitoring.md section 9 (AT-523) - the queue watchdog: a stuck queue, a queue with no worker and a new failed job are found, shown as a
 * red line on System Health, told to the System Owners in the bell once (again after 6 h), and emailed ONLY to can.assurance@gmail.com.
 */
final class QueueWatchdogTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        File::delete(QueueWatchdogService::statePath());
        $agency = Agency::create(['name' => 'Wd', 'slug' => 'wd-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'HQ']);
        Role::unguarded(fn () => Role::create(['name' => 'super_admin', 'label' => 'System Owner', 'is_owner' => true]));
        $this->owner = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);
        \Illuminate\Support\Facades\Mail::fake();
    }

    protected function tearDown(): void
    {
        File::delete(QueueWatchdogService::statePath());
        parent::tearDown();
    }

    private function consumed(array $queues): void
    {
        $this->app->bind(QueueWorkerProcessLister::class, fn () => new class($queues) extends QueueWorkerProcessLister {
            public function __construct(private array $q) {}

            public function consumedQueues(): ?array
            {
                return $this->q;
            }
        });
    }

    private function job(string $queue, int $ageSeconds, bool $reserved = false): void
    {
        DB::table('jobs')->insert(['queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reserved ? time() : null, 'available_at' => time() - $ageSeconds, 'created_at' => time() - $ageSeconds]);
    }

    private function failed(string $class = 'App\\Jobs\\X'): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) \Illuminate\Support\Str::uuid(), 'connection' => 'database', 'queue' => 'default',
            'payload' => json_encode(['displayName' => $class]), 'exception' => 'Boom', 'failed_at' => now()]);
    }

    public function test_a_queue_with_jobs_and_no_worker_is_found(): void
    {
        $this->consumed(['default']);
        $this->job('thumbnails', 120 * 3600);

        $f = app(QueueWatchdogService::class)->evaluate();

        $this->assertCount(1, $f);
        $this->assertSame('no_worker', $f[0]['type']);
        $this->assertSame('thumbnails', $f[0]['queue']);
        $this->assertSame(7200, $f[0]['oldest_minutes']);
        $this->assertStringContainsString('NO WORKER', $f[0]['text']);
    }

    public function test_a_queue_with_a_worker_whose_oldest_job_is_over_the_limit_is_stuck_and_a_young_one_is_not(): void
    {
        $this->consumed(['default', 'mail']);
        $this->job('default', 45 * 60);
        $this->job('mail', 5 * 60);

        $f = app(QueueWatchdogService::class)->evaluate();

        $this->assertSame(['stuck:default'], array_map(fn ($x) => $x['type'] . ':' . $x['queue'], $f));
    }

    public function test_the_limit_is_a_setting(): void
    {
        $this->consumed(['default']);
        $this->job('default', 45 * 60);
        DevSetting::set('queue_watchdog_max_age_minutes', '60');

        $this->assertSame([], app(QueueWatchdogService::class)->evaluate());
        $this->assertSame(60, QueueWatchdogService::maxAgeMinutes());
    }

    public function test_a_job_being_worked_on_is_not_waiting(): void
    {
        $this->consumed(['default']);
        $this->job('default', 90 * 60, reserved: true);

        $this->assertSame([], app(QueueWatchdogService::class)->evaluate());
    }

    public function test_the_first_run_sets_a_baseline_so_old_failures_do_not_alert_and_a_new_one_does(): void
    {
        $this->consumed(['default']);
        $this->failed('App\\Jobs\\Old');
        $wd = app(QueueWatchdogService::class);

        $first = $wd->run(false);
        $this->assertSame([], $first['findings'], 'what already failed is not news');

        $this->failed('App\\Jobs\\Fresh');
        $second = $wd->run(false);
        $this->assertSame('failed', $second['findings'][0]['type']);
        $this->assertStringContainsString('App\\Jobs\\Fresh', $second['findings'][0]['text']);
    }

    public function test_owners_are_told_in_the_bell_once_and_the_email_goes_only_to_the_test_mailbox(): void
    {
        $this->consumed(['default']);
        $this->job('thumbnails', 3600 * 5);
        $wd = app(QueueWatchdogService::class);

        $wd->run(true);
        $wd->run(true);   // a second run while the problem lasts says nothing more

        $notes = $this->owner->fresh()->notifications()->get();
        $this->assertCount(1, $notes);
        $this->assertSame('system.queue_watchdog', $notes->first()->data['event_key']);
        $this->assertStringContainsString('thumbnails', $notes->first()->data['title']);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\QueueWatchdogMail::class, 1);
        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\QueueWatchdogMail::class, fn ($m) => $m->hasTo('can.assurance@gmail.com') && count($m->to) === 1 && $m->cc === [] && $m->bcc === []);
    }

    public function test_a_cleared_problem_is_forgotten_so_it_can_alert_again(): void
    {
        $this->consumed(['default']);
        $this->job('thumbnails', 3600 * 5);
        $wd = app(QueueWatchdogService::class);
        $wd->run(true);
        DB::table('jobs')->delete();
        $this->assertSame([], $wd->run(true)['findings']);
        $this->assertSame([], QueueWatchdogService::readState()['notified']);

        $this->job('thumbnails', 3600 * 5);
        $this->assertCount(1, $wd->run(true)['notified'], 'back again -> told again');
    }

    public function test_the_state_the_system_health_page_reads_and_the_stale_flag(): void
    {
        $this->assertTrue(QueueWatchdogService::publicState()['stale'], 'never ran');
        $this->consumed(['default']);
        app(QueueWatchdogService::class)->run(false);
        $this->assertFalse(QueueWatchdogService::publicState()['stale']);
        $s = QueueWatchdogService::readState();
        $s['last_run'] = now()->subMinutes(50)->toIso8601String();
        File::put(QueueWatchdogService::statePath(), json_encode($s));
        $this->assertTrue(QueueWatchdogService::publicState()['stale'], 'the watchdog itself stopped reporting');
    }

    public function test_the_system_health_snapshot_carries_the_watchdog_and_the_page_has_the_red_line(): void
    {
        $this->consumed(['default']);
        $this->job('thumbnails', 3600);
        app(QueueWatchdogService::class)->run(false);

        $snap = app(\App\Services\System\ServerHealthService::class)->snapshot();
        $this->assertSame('no_worker', $snap['corex']['watchdog']['findings'][0]['type']);
        $this->assertStringContainsString('data-queue-watchdog-red', file_get_contents(resource_path('views/admin/system-health/index.blade.php')));
    }

    public function test_the_code_dispatches_to_the_queues_we_know_and_coverage_names_the_missing_ones(): void
    {
        $queues = app(QueueWatchdogService::class)->dispatchedQueues();
        foreach (['default', 'mail', 'thumbnails', 'training-videos', 'p24import', 'p24images', 'matching', 'webhooks', 'buyer-matching', 'bg_removal'] as $q) {
            $this->assertContains($q, $queues, $q);
        }
        $this->consumed(['default', 'mail']);
        $c = app(QueueWatchdogService::class)->coverage();
        $this->assertContains('thumbnails', $c['missing']);
        $this->assertNotContains('mail', $c['missing']);
    }

    public function test_the_commands_exit_codes(): void
    {
        $this->consumed(['default']);
        $this->artisan('corex:queue-watchdog', ['--no-notify' => true])->assertExitCode(0);
        $this->job('thumbnails', 3600);
        $this->artisan('corex:queue-watchdog', ['--no-notify' => true])->assertExitCode(1);
        $this->artisan('corex:queue-workers-check')->assertExitCode(1);
    }
}
