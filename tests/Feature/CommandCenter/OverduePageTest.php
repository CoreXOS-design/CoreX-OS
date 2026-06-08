<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\User;
use App\Services\CommandCenter\CommandCentreService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The "Overdue & Unresolved" Today card links to a dedicated drill-down page,
 * and neither that card nor "Today's Schedule" surface people-domain markers
 * (birthdays / anniversaries).
 */
final class OverduePageTest extends TestCase
{
    use RefreshDatabase;

    public function test_overdue_page_lists_overdue_task_and_excludes_birthdays(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();

        $this->makeTask($agencyId, $user->id, 'Call the seller back', now()->subDays(3));

        // A birthday (people) event that is "overdue" — must NOT appear.
        $this->makeEvent($agencyId, $user->id, 'Birthday — Jane', 'people', 'contact_birthday', now()->subDays(2), 'overdue');
        // A real overdue appointment — must appear.
        $this->makeEvent($agencyId, $user->id, 'Viewing follow-up', 'manual', 'viewing', now()->subDays(1), 'overdue');

        $resp = $this->actingAs($user)->get(route('command-center.overdue'));

        $resp->assertOk();
        $resp->assertSee('Call the seller back');
        $resp->assertSee('Viewing follow-up');
        $resp->assertDontSee('Birthday — Jane');
    }

    public function test_today_cards_exclude_people_events(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();

        // Birthday today (people) + a real appointment today.
        $this->makeEvent($agencyId, $user->id, 'Birthday — Sam', 'people', 'contact_birthday', now()->setTime(9, 0));
        $this->makeEvent($agencyId, $user->id, 'Buyer meeting', 'manual', 'meeting', now()->setTime(14, 0));
        // Birthday overdue (people) — should not feed the overdue card.
        $this->makeEvent($agencyId, $user->id, 'Birthday — Old', 'people', 'contact_birthday', now()->subDays(5), 'overdue');

        $cards = (new CommandCentreService())->getAgentCards($user);

        $schedule = collect($cards)->firstWhere('card_id', 'today_appointments');
        $titles = collect($schedule['items'])->pluck('title')->all();
        $this->assertContains('Buyer meeting', $titles);
        $this->assertNotContains('Birthday — Sam', $titles);

        // Overdue card points at the dedicated page and ignores the birthday.
        $overdue = collect($cards)->firstWhere('card_id', 'overdue_items');
        if ($overdue) {
            $this->assertSame(route('command-center.overdue'), $overdue['view_all_url']);
            $this->assertNotContains('Birthday — Old', collect($overdue['items'])->pluck('title')->all());
        } else {
            // No overdue card means the birthday correctly produced zero overdue items.
            $this->assertTrue(true);
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    private function seedAgencyUser(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);

        return [$agencyId, $user];
    }

    private function makeTask(int $agencyId, int $userId, string $title, $due): void
    {
        DB::table('command_tasks')->insert([
            'title'       => $title,
            'task_type'   => 'custom',
            'status'      => 'todo',
            'priority'    => 'normal',
            'assigned_to' => $userId,
            'due_date'    => $due,
            'agency_id'   => $agencyId,
            'branch_id'   => $agencyId,
            'created_at'  => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEvent(int $agencyId, int $userId, string $title, string $type, string $category, $date, string $status = 'pending'): void
    {
        DB::table('calendar_events')->insert([
            'user_id'    => $userId,
            'event_type' => $type,
            'category'   => $category,
            'title'      => $title,
            'event_date' => $date,
            'all_day'    => false,
            'priority'   => 'normal',
            'status'     => $status,
            'agency_id'  => $agencyId,
            'branch_id'  => $agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
