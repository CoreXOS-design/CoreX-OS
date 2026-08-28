<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Mail\QueueBacklogAlertMail;
use App\Models\DevSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * CX — 2026-08-28. The backlog alarm judged the WHOLE `jobs` table against one
 * 600s deadline. Once TranscribeVoiceNoteJob got its own `transcription` lane
 * (2026-08-27) that stopped measuring health: the nightly voice-note batch runs
 * one note at a time by design and always parks its own head past 600s, so the
 * alarm fired at 22:15 on three consecutive nights while every worker was
 * RUNNING and every latency-sensitive lane was on time.
 *
 * The contract these tests lock:
 *   - a LATENCY lane alarms on age, exactly as fast as it did before;
 *   - a BATCH lane alarms only when it stops MOVING, never on depth alone;
 *   - a noisy batch lane can never mask or delay a real stall on another lane.
 */
final class QueueHealthcheckLaneAwarenessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();

        config([
            'queue_alerting.backlog.default_supervisor' => 'corex-worker-live:*',
            'queue_alerting.backlog.lanes' => [
                'default'       => ['supervisor' => 'corex-worker-live:*'],
                'transcription' => [
                    'max_age'           => 900,
                    'requires_progress' => true,
                    'progress_window'   => 1500,
                    'supervisor'        => 'corex-worker-live-transcription:*',
                ],
            ],
        ]);
    }

    /** Insert a waiting (unreserved, runnable) job whose head position is $ageSeconds old. */
    private function waitingJob(string $queue, int $ageSeconds): void
    {
        DB::table('jobs')->insert([
            'queue'        => $queue,
            'payload'      => json_encode(['displayName' => 'App\\Jobs\\FakeJob']),
            'attempts'     => 0,
            'reserved_at'  => null,
            'available_at' => now()->timestamp - $ageSeconds,
            'created_at'   => now()->timestamp - $ageSeconds,
        ]);
    }

    private function run(): int
    {
        return Artisan::call('corex:queue-healthcheck');
    }

    public function test_a_latency_lane_past_its_threshold_still_alarms(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));

        $this->waitingJob('default', 700); // > the 600s default threshold

        $this->assertSame(1, $this->run(), 'the important lane must alarm on age, as it always has');
    }

    public function test_a_latency_lane_under_its_threshold_stays_quiet(): void
    {
        $this->waitingJob('default', 120);

        $this->assertSame(0, $this->run());
    }

    public function test_the_real_2215_false_alarm_no_longer_fires(): void
    {
        // Reproduced from live: 23 voice notes waiting, oldest 713s. That tripped the
        // old single 600s threshold on three consecutive nights. It is inside the
        // transcription lane's OWN 900s threshold, so it never reaches the progress
        // check — a batch lane is simply allowed the time its work actually takes.
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));

        for ($i = 0; $i < 23; $i++) {
            $this->waitingJob('transcription', 713 - $i);
        }

        $this->assertSame(0, $this->run(), 'a batch lane draining normally is not a fault');
    }

    public function test_a_batch_lane_past_its_own_threshold_stays_quiet_while_it_keeps_moving(): void
    {
        // The other half: a batch bigger than usual runs past even the lane's own
        // 900s threshold. Depth still is not the fault — only stopping is. This is
        // the path that stops the alarm coming back the first night someone records
        // forty voice notes instead of eighteen.
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));

        for ($i = 0; $i < 23; $i++) {
            $this->waitingJob('transcription', 1200 - $i);
        }
        $this->assertSame(0, $this->run(), 'first sighting records the head');

        // Notes completed: the head of the lane has advanced.
        DB::table('jobs')->where('queue', 'transcription')->delete();
        for ($i = 0; $i < 18; $i++) {
            $this->waitingJob('transcription', 1200 - $i);
        }

        $this->assertSame(0, $this->run(), 'past its threshold but still moving — not a fault');
    }

    public function test_a_batch_lane_alarms_once_its_head_stops_moving(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));

        $start = Carbon::create(2026, 8, 28, 22, 15, 0);
        Carbon::setTestNow($start);

        $this->waitingJob('transcription', 1000); // past max_age, head recorded on this run
        $this->assertSame(0, $this->run(), 'first sighting of this head — cannot know yet whether it is stuck');

        // Same head, still unreserved, well past the 1500s stall window.
        Carbon::setTestNow($start->copy()->addSeconds(1600));

        $this->assertSame(1, $this->run(), 'a batch lane whose head has not advanced IS wedged');

        Carbon::setTestNow();
    }

    public function test_a_batch_lane_whose_head_keeps_advancing_never_alarms(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode([]));

        $start = Carbon::create(2026, 8, 28, 22, 0, 0);
        Carbon::setTestNow($start);

        // A long batch: far longer than the stall window overall, but the head
        // advances every run because notes keep completing.
        for ($tick = 0; $tick < 8; $tick++) {
            Carbon::setTestNow($start->copy()->addSeconds($tick * 300));

            DB::table('jobs')->where('queue', 'transcription')->delete();
            for ($i = 0; $i < 10; $i++) {
                $this->waitingJob('transcription', 1000 - $i); // head is fresh each tick
            }

            $this->assertSame(0, $this->run(), "tick {$tick}: a moving batch lane must stay quiet");
        }

        Carbon::setTestNow();
    }

    public function test_a_noisy_batch_lane_does_not_mask_a_real_stall_elsewhere(): void
    {
        // The regression that matters most: the whole point of the batch lane is
        // that it is allowed to be deep, and that must never buy silence for a
        // lane agents are actually waiting on.
        DevSetting::set('queue_backlog_alert_emails', json_encode(['ops@example.test']));
        Mail::fake();

        for ($i = 0; $i < 30; $i++) {
            $this->waitingJob('transcription', 2000 - $i);
        }
        $this->waitingJob('default', 700);

        $this->assertSame(1, $this->run());

        Mail::assertSent(QueueBacklogAlertMail::class, fn ($mail) => $mail->lane === 'default');
        Mail::assertNotSent(QueueBacklogAlertMail::class, fn ($mail) => $mail->lane === 'transcription');
    }

    public function test_the_alert_names_the_lane_and_its_own_worker(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode(['ops@example.test']));
        Mail::fake();

        $this->waitingJob('default', 700);
        $this->run();

        Mail::assertSent(QueueBacklogAlertMail::class, function ($mail) {
            return $mail->lane === 'default'
                && $mail->supervisor === 'corex-worker-live:*'
                && $mail->maxAge === 600;
        });
    }

    public function test_each_lane_is_throttled_independently(): void
    {
        DevSetting::set('queue_backlog_alert_emails', json_encode(['ops@example.test']));
        Mail::fake();

        $this->waitingJob('default', 700);
        $this->run();
        Mail::assertSentCount(1);

        // A second lane going down inside the first lane's 15-minute throttle window
        // must still page immediately — the old single global key swallowed this.
        $this->waitingJob('matching', 700);
        $this->run();

        Mail::assertSentCount(2);
        Mail::assertSent(QueueBacklogAlertMail::class, fn ($mail) => $mail->lane === 'matching');
    }
}
