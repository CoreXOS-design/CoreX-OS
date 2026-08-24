<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Every App\Jobs\* class runs on the 'database' queue connection (none call
 * onConnection() — verified by inspection). That connection has ONE
 * retry_after for every job: if a job's own $timeout exceeds it, the database
 * queue driver considers the job abandoned and makes it available again
 * WHILE IT IS STILL RUNNING. The re-dispatched copy then fails instantly with
 * MaxAttemptsExceededException — Laravel checks attempts() against tries()
 * before ever invoking handle() again — masking the real (or non-existent)
 * error under a generic "attempted too many times" with no useful trace.
 *
 * This is exactly what happened to SyncProperty24Activations (tries=1,
 * timeout=300s, retry_after was 90s): it failed nearly every 15-minute run
 * for 9 days before anyone noticed. See config/queue.php connections.database
 * and .env DB_QUEUE_RETRY_AFTER.
 *
 * This test reads $timeout defaults via reflection (no instantiation, no
 * framework boot) and DB_QUEUE_RETRY_AFTER straight out of .env, so it stays
 * a plain, fast PHPUnit test.
 */
final class JobTimeoutRetryAfterGuardTest extends TestCase
{
    public function test_every_job_timeout_fits_under_the_database_queue_retry_after(): void
    {
        $retryAfter = $this->databaseQueueRetryAfter();

        $offenders = [];
        foreach ($this->jobClassesWithTimeout() as $class => $timeout) {
            if ($timeout >= $retryAfter) {
                $offenders[] = "{$class} (\$timeout={$timeout}s) >= retry_after ({$retryAfter}s)";
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "One or more queued jobs can run longer than the database queue's retry_after. ".
            "This causes the worker to re-dispatch a copy of a job that is still legitimately ".
            "running, which then dies instantly with MaxAttemptsExceededException. Either raise ".
            "DB_QUEUE_RETRY_AFTER in .env past the longest job timeout, or shorten the offending ".
            "job's \$timeout:\n" . implode("\n", $offenders)
        );
    }

    /** @return array<class-string,int> class => $timeout (only jobs that declare one) */
    private function jobClassesWithTimeout(): array
    {
        $result = [];
        $dir = base_path_for_tests('app/Jobs');

        foreach (glob($dir . '/*.php') ?: [] as $file) {
            $class = 'App\\Jobs\\' . basename($file, '.php');
            if (! class_exists($class)) {
                continue;
            }

            $reflection = new ReflectionClass($class);
            if (! $reflection->implementsInterface(ShouldQueue::class)) {
                continue;
            }
            if (! $reflection->hasProperty('timeout')) {
                continue;
            }

            $property = $reflection->getProperty('timeout');
            $default = $property->getDefaultValue();
            if (is_int($default)) {
                $result[$class] = $default;
            }
        }

        return $result;
    }

    private function databaseQueueRetryAfter(): int
    {
        $envPath = base_path_for_tests('.env');
        $contents = is_readable($envPath) ? (string) file_get_contents($envPath) : '';

        if (preg_match('/^DB_QUEUE_RETRY_AFTER=(\d+)/m', $contents, $m)) {
            return (int) $m[1];
        }

        // Matches the fallback in config/queue.php's 'database' connection.
        return 90;
    }
}

if (! function_exists(__NAMESPACE__ . '\\base_path_for_tests')) {
    function base_path_for_tests(string $path = ''): string
    {
        static $base;
        $base ??= dirname(__DIR__, 3);

        return $path === '' ? $base : $base . '/' . ltrim($path, '/');
    }
}
