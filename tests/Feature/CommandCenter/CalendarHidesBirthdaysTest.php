<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The calendar hides birthday / anniversary markers by default (they cluttered
 * the grid), but they remain available when the category is explicitly selected.
 */
final class CalendarHidesBirthdaysTest extends TestCase
{
    use RefreshDatabase;

    public function test_birthdays_hidden_by_default_but_revealable_via_category(): void
    {
        [$agencyId, $user] = $this->seedAgencyUser();

        // Active class settings so the threshold resolver keeps the events.
        $this->classSetting('contact_birthday', 'informational');
        $this->classSetting('viewing', 'actionable');

        $when = now()->addDays(3);
        $this->makeEvent($agencyId, $user->id, 'Birthday — Jane', 'people', 'contact_birthday', $when);
        $this->makeEvent($agencyId, $user->id, 'Property viewing', 'manual', 'viewing', $when);

        $start = now()->startOfMonth()->toDateString();
        $end   = now()->addMonth()->endOfMonth()->toDateString();

        // Default feed — birthday suppressed, appointment kept.
        $default = $this->actingAs($user)
            ->getJson(route('command-center.calendar.events', ['start' => $start, 'end' => $end]))
            ->assertOk()
            ->json();
        $titles = collect($default)->pluck('title')->all();
        $this->assertContains('Property viewing', $titles);
        $this->assertNotContains('Birthday — Jane', $titles);

        // Explicitly selecting the birthday category reveals it.
        $revealed = $this->actingAs($user)
            ->getJson(route('command-center.calendar.events', [
                'start' => $start, 'end' => $end, 'categories' => ['contact_birthday'],
            ]))
            ->assertOk()
            ->json();
        $this->assertContains('Birthday — Jane', collect($revealed)->pluck('title')->all());
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

    private function classSetting(string $class, string $nature): void
    {
        DB::table('calendar_event_class_settings')->insert([
            'agency_id'    => null,
            'event_class'  => $class,
            'label'        => Str::headline($class),
            'is_active'    => true,
            'event_nature' => $nature,
            'green_days'   => 30,
            'amber_days'   => 14,
            'red_days'     => 3,
            'show_days'    => 60,
            'green_visibility'    => json_encode(['all']),
            'amber_visibility'    => json_encode(['all']),
            'red_visibility'      => json_encode(['all']),
            'green_notifications' => json_encode([]),
            'amber_notifications' => json_encode([]),
            'red_notifications'   => json_encode([]),
            'daily_digest_enabled' => false,
            'daily_digest_roles'  => json_encode([]),
            'created_at'   => now(), 'updated_at' => now(),
        ]);
    }

    private function makeEvent(int $agencyId, int $userId, string $title, string $type, string $category, $date): void
    {
        DB::table('calendar_events')->insert([
            'user_id'    => $userId,
            'event_type' => $type,
            'category'   => $category,
            'title'      => $title,
            'event_date' => $date,
            'all_day'    => true,
            'priority'   => 'normal',
            'status'     => 'pending',
            'agency_id'  => $agencyId,
            'branch_id'  => $agencyId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
