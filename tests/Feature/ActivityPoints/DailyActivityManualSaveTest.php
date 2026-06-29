<?php

declare(strict_types=1);

namespace Tests\Feature\ActivityPoints;

use App\Models\ActivityDefinition;
use App\Models\DailyActivityEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Locks the manual daily-activity save as a NON-DESTRUCTIVE diff.
 *
 * The original handler treated each form submission as the complete truth
 * for the day and hard-deleted any definition posted as 0. A stale/cached
 * form (browser Back / bfcache) re-posted 0 for already-saved cells and
 * silently wiped them — the "I filled it in and it disappears every time I
 * leave" bug. The save now applies the form as a diff against the baseline
 * the form was rendered with: untouched cells are left alone, only an
 * explicit change acts, and deletes are scoped to source='manual'.
 */
final class DailyActivityManualSaveTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_form_resubmit_does_not_wipe_saved_entries(): void
    {
        [$user, $def, $date] = $this->fixtures();

        // First real save: form rendered blank (baseline 0), user enters 3.
        $this->actingAs($user)->post('/agent/daily', [
            'activity_date' => $date,
            'baseline' => [$def->id => 0],
            'values'   => [$def->id => 3],
        ])->assertRedirect();

        $this->assertSame(3, $this->manualValue($user, $def, $date));

        // Stale Back-button form: it re-posts the PRE-save snapshot
        // (baseline 0, value 0). The diff sees no change -> must NOT delete.
        $this->actingAs($user)->post('/agent/daily', [
            'activity_date' => $date,
            'baseline' => [$def->id => 0],
            'values'   => [$def->id => 0],
        ])->assertRedirect();

        $this->assertSame(
            3,
            $this->manualValue($user, $def, $date),
            'A stale form re-posting its own baseline must not wipe saved entries.',
        );
    }

    public function test_explicit_clear_deletes_the_manual_cell(): void
    {
        [$user, $def, $date] = $this->fixtures();

        $this->seedManual($user, $def, $date, 4);

        // User clears a cell they previously had at 4: baseline 4, value 0.
        $this->actingAs($user)->post('/agent/daily', [
            'activity_date' => $date,
            'baseline' => [$def->id => 4],
            'values'   => [$def->id => 0],
        ])->assertRedirect();

        $this->assertNull(
            $this->manualRow($user, $def, $date),
            'An explicit change from a positive baseline to 0 must clear the cell.',
        );
    }

    public function test_changed_value_is_updated_in_place(): void
    {
        [$user, $def, $date] = $this->fixtures();

        $this->seedManual($user, $def, $date, 2);

        $this->actingAs($user)->post('/agent/daily', [
            'activity_date' => $date,
            'baseline' => [$def->id => 2],
            'values'   => [$def->id => 7],
        ])->assertRedirect();

        $this->assertSame(7, $this->manualValue($user, $def, $date));
        // Exactly one manual row — no duplicate created.
        $this->assertSame(1, DailyActivityEntry::query()
            ->where('user_id', $user->id)
            ->where('activity_definition_id', $def->id)
            ->where('activity_date', $date)
            ->where('source', DailyActivityEntry::SOURCE_MANUAL)
            ->count());
    }

    public function test_manual_save_never_touches_auto_rows_for_the_same_cell(): void
    {
        [$user, $def, $date] = $this->fixtures();

        // An auto-credited row sharing (def, user, date) — e.g. instant spine.
        $auto = DailyActivityEntry::create([
            'activity_date'          => $date,
            'period'                 => substr($date, 0, 7),
            'user_id'                => $user->id,
            'agency_id'              => $user->agency_id,
            'branch_id'              => $user->branch_id,
            'activity_definition_id' => $def->id,
            'value'                  => 1,
            'point_state'            => DailyActivityEntry::STATE_CONFIRMED,
            'source'                 => DailyActivityEntry::SOURCE_AUTO_INSTANT,
        ]);

        // Manual save of the same definition.
        $this->actingAs($user)->post('/agent/daily', [
            'activity_date' => $date,
            'baseline' => [$def->id => 0],
            'values'   => [$def->id => 5],
        ])->assertRedirect();

        // Auto row untouched.
        $auto->refresh();
        $this->assertSame(1, (int) $auto->value);
        $this->assertSame(DailyActivityEntry::SOURCE_AUTO_INSTANT, $auto->source);

        // Separate manual row created.
        $this->assertSame(5, $this->manualValue($user, $def, $date));

        // A subsequent explicit clear removes ONLY the manual row.
        $this->actingAs($user)->post('/agent/daily', [
            'activity_date' => $date,
            'baseline' => [$def->id => 5],
            'values'   => [$def->id => 0],
        ])->assertRedirect();

        $this->assertNull($this->manualRow($user, $def, $date));
        $this->assertNotNull(DailyActivityEntry::find($auto->id), 'Auto row must survive a manual clear.');
    }

    // ── helpers ──

    private function fixtures(): array
    {
        $agencyId = $this->makeAgency();
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'agent',
        ]);
        $def = ActivityDefinition::create([
            'name'       => 'Manual save ' . Str::random(6),
            'weight'     => 10,
            'sort_order' => 1,
            'scope'      => ActivityDefinition::SCOPE_SYSTEM,
            'is_enabled' => true,
        ]);

        return [$user, $def, now()->toDateString()];
    }

    private function seedManual(User $user, ActivityDefinition $def, string $date, int $value): void
    {
        DailyActivityEntry::create([
            'activity_date'          => $date,
            'period'                 => substr($date, 0, 7),
            'user_id'                => $user->id,
            'agency_id'              => $user->agency_id,
            'branch_id'              => $user->branch_id,
            'activity_definition_id' => $def->id,
            'value'                  => $value,
            'point_state'            => DailyActivityEntry::STATE_CONFIRMED,
            'source'                 => DailyActivityEntry::SOURCE_MANUAL,
        ]);
    }

    private function manualRow(User $user, ActivityDefinition $def, string $date): ?DailyActivityEntry
    {
        return DailyActivityEntry::query()
            ->where('user_id', $user->id)
            ->where('activity_definition_id', $def->id)
            ->where('activity_date', $date)
            ->where('source', DailyActivityEntry::SOURCE_MANUAL)
            ->first();
    }

    private function manualValue(User $user, ActivityDefinition $def, string $date): ?int
    {
        $row = $this->manualRow($user, $def, $date);

        return $row ? (int) $row->value : null;
    }

    private function makeAgency(): int
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

        return $agencyId;
    }
}
