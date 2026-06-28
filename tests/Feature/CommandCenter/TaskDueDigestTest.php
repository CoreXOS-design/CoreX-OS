<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Mail\CommandCenter\TaskDueDigest;
use App\Models\CommandCenter\CommandTask;
use App\Models\User;
use App\Notifications\TaskDueReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Task due reminders must NOT email one message per task (inbox flood — see the
 * Cindy Pietersen case, 129 unread). ProcessReminders sends per-task in-app
 * notifications but a single aggregated TaskDueDigest email per user per run.
 */
final class TaskDueDigestTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_digest_email_for_many_due_tasks_not_one_each(): void
    {
        Mail::fake();

        [$agencyId, $branchId] = $this->seedAgency();
        $user = $this->makeUser($agencyId, $branchId);

        // Three tasks due inside the 4h reminder window (default).
        $this->makeDueTask($agencyId, $branchId, $user->id, 'Upload signed mandate');
        $this->makeDueTask($agencyId, $branchId, $user->id, 'Upload owner ID copy');
        $this->makeDueTask($agencyId, $branchId, $user->id, 'Upload proof of ownership');

        $this->artisan('command-center:reminders')->assertSuccessful();

        // Exactly ONE email, addressed to the user, carrying all three tasks.
        Mail::assertSent(TaskDueDigest::class, 1);
        Mail::assertSent(TaskDueDigest::class, fn (TaskDueDigest $m) =>
            $m->hasTo($user->email) && $m->tasks->count() === 3 && $m->taskCount === 3
        );

        // Per-task in-app (database) notifications still land.
        $this->assertSame(3, DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->where('type', TaskDueReminderNotification::class)
            ->count());
    }

    public function test_dedup_prevents_a_second_run_from_re_emailing(): void
    {
        Mail::fake();

        [$agencyId, $branchId] = $this->seedAgency();
        $user = $this->makeUser($agencyId, $branchId);
        $this->makeDueTask($agencyId, $branchId, $user->id, 'Upload signed mandate');

        $this->artisan('command-center:reminders')->assertSuccessful();
        $this->artisan('command-center:reminders')->assertSuccessful();

        // reminder_sent marking means the second run finds nothing new → still 1.
        Mail::assertSent(TaskDueDigest::class, 1);
    }

    public function test_no_email_when_user_opted_out_of_email(): void
    {
        Mail::fake();

        [$agencyId, $branchId] = $this->seedAgency();
        $user = $this->makeUser($agencyId, $branchId);
        DB::table('user_dashboard_settings')->insert([
            'user_id' => $user->id,
            'notify_in_app' => true, 'notify_email' => false,
            'task_due_reminders' => true, 'task_reminder_hours_before' => 4,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->makeDueTask($agencyId, $branchId, $user->id, 'Upload signed mandate');

        $this->artisan('command-center:reminders')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_reminder_notification_is_in_app_only(): void
    {
        // Guards the flood fix structurally: if the mail channel is ever re-added
        // to this notification, per-task emails return. via() must stay database.
        $task = new CommandTask(['title' => 'x']);
        $user = new User();

        $this->assertSame(['database'], (new TaskDueReminderNotification($task))->via($user));
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} [agencyId, branchId] */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Branch 1',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $branchId];
    }

    private function makeUser(int $agencyId, int $branchId): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'role' => 'agent',
        ]);
    }

    private function makeDueTask(int $agencyId, int $branchId, int $userId, string $title): void
    {
        DB::table('command_tasks')->insert([
            'title' => $title, 'task_type' => 'custom', 'status' => 'todo',
            'priority' => 'normal', 'assigned_to' => $userId,
            'send_reminder' => true,
            'due_date' => now()->addHours(2),
            'agency_id' => $agencyId, 'branch_id' => $branchId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
