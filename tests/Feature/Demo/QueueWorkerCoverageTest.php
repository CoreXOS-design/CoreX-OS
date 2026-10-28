<?php

declare(strict_types=1);

namespace Tests\Feature\Demo;

use App\Console\Commands\QueueWorkerCoverage;
use App\Services\System\QueueWorkerProcessLister;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * corex:queue-worker-coverage flags a queue that has jobs waiting and no worker on this host naming it (the `thumbnails` lane on the
 * demo and Staging boxes: 780 jobs sat idle with every other check quiet).
 */
final class QueueWorkerCoverageTest extends TestCase
{
    use RefreshDatabase;

    private const PS = <<<'PS'
/usr/sbin/php-fpm8.2: pool www
php /corex-staging/artisan queue:work --queue=default,p24import,matching --sleep=3 --tries=3
php /corex-staging/artisan queue:work --queue=mail --sleep=1
/usr/bin/php8.2 /corex-demo/artisan queue:work --queue=thumbnails --sleep=3
php /corex-staging/artisan queue:work database
php /corex-staging/artisan schedule:run
PS;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function job(string $queue, int $ageSeconds, ?int $reservedAt = null): void
    {
        DB::table('jobs')->insert([
            'queue' => $queue, 'payload' => '{}', 'attempts' => 0, 'reserved_at' => $reservedAt,
            'available_at' => now()->timestamp - $ageSeconds, 'created_at' => now()->timestamp - $ageSeconds,
        ]);
    }

    private function listerSeeing(array $queues): void
    {
        $this->app->instance(QueueWorkerProcessLister::class, new class($queues) extends QueueWorkerProcessLister {
            public function __construct(private ?array $q) {}
            public function consumedQueues(): ?array { return $this->q; }
        });
    }

    public function test_the_process_table_is_read_per_queue_and_only_for_this_checkout(): void
    {
        $q = QueueWorkerProcessLister::parse(self::PS, '/corex-staging/artisan');
        sort($q);
        $this->assertSame(['default', 'mail', 'matching', 'p24import'], $q, "the demo checkout's thumbnails worker is not this site's worker; a bare queue:work is the default queue");

        $all = QueueWorkerProcessLister::parse(self::PS);
        $this->assertContains('thumbnails', $all, 'with no path to compare, every worker counts');
        $this->assertSame([], QueueWorkerProcessLister::parse("php-fpm: pool www\nnginx: worker process"), 'a web worker is not a queue worker');
        $this->assertSame(['x'], QueueWorkerProcessLister::parse('php artisan queue:work --queue x', '/corex-staging/artisan'), 'a relative artisan and the space form of --queue');
    }

    public function test_a_queue_with_jobs_waiting_and_no_worker_is_flagged_loudly_and_the_others_are_not(): void
    {
        Log::spy();
        $this->job('thumbnails', 3600);
        $this->job('thumbnails', 1800);
        $this->job('default', 3600);
        $this->listerSeeing(['default', 'mail']);

        $this->artisan('corex:queue-worker-coverage')->assertExitCode(1)->expectsOutputToContain('Queue [thumbnails] has NO WORKER');

        Log::shouldHaveReceived('critical')->once()->withArgs(fn ($m, $ctx = []) => str_contains($m, 'thumbnails') && ($ctx['waiting'] ?? 0) === 2);
    }

    public function test_everything_covered_is_healthy(): void
    {
        $this->job('thumbnails', 3600);
        $this->job('default', 3600);
        $this->listerSeeing(['default', 'thumbnails']);

        $this->artisan('corex:queue-worker-coverage')->assertExitCode(0);
    }

    public function test_a_fresh_job_a_reserved_job_and_an_empty_table_are_not_a_fault(): void
    {
        $this->listerSeeing(['default']);
        $this->artisan('corex:queue-worker-coverage')->assertExitCode(0);

        $this->job('thumbnails', 10);                                        // younger than the 2 minute grace: a worker restart, not a fault
        $this->job('thumbnails', 3600, now()->timestamp - 5);                // being worked right now
        $this->artisan('corex:queue-worker-coverage')->assertExitCode(0);
    }

    public function test_a_box_where_no_worker_runs_at_all_flags_every_waiting_queue(): void
    {
        $this->job('thumbnails', 3600);
        $this->listerSeeing([]);

        $this->artisan('corex:queue-worker-coverage')->assertExitCode(1);
    }

    public function test_when_the_process_table_cannot_be_read_it_says_so_instead_of_reporting_healthy(): void
    {
        Log::spy();
        $this->job('thumbnails', 3600);
        $this->app->instance(QueueWorkerProcessLister::class, new class extends QueueWorkerProcessLister {
            public function consumedQueues(): ?array { return null; }
        });

        $this->artisan('corex:queue-worker-coverage')->assertExitCode(1)->expectsOutputToContain('Could not read the process table');
        Log::shouldHaveReceived('warning')->withArgs(fn ($m) => str_contains($m, 'process table'))->once();
    }

    public function test_it_is_scheduled_every_five_minutes(): void
    {
        $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'corex:queue-worker-coverage'));
        $this->assertNotNull($event, 'scheduled');
        $this->assertSame('*/5 * * * *', $event->expression);
    }
}
