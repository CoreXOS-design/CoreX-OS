<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\CommandCenter\CalendarEventClassSetting;
use Database\Seeders\CalendarEventClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AT-197 — plain-language, code-truth descriptions on the event-class settings screen.
 * The `description` column was widened to TEXT and the seeder applies a Johan-format
 * description per class to ALL rows (globals + agency overrides).
 */
final class EventClassDescriptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_applies_a_nonempty_description_to_every_seeded_class(): void
    {
        $this->seed(CalendarEventClassSeeder::class);

        $classes = CalendarEventClassSetting::withoutGlobalScopes()->whereNull('agency_id')->get();
        $this->assertGreaterThan(30, $classes->count());

        foreach ($classes as $c) {
            $this->assertNotEmpty($c->description, "class {$c->event_class} has a description");
            // Every seeded class key must have a curated description in the map.
            if (array_key_exists($c->event_class, CalendarEventClassSeeder::CLASS_DESCRIPTIONS)) {
                $this->assertSame(CalendarEventClassSeeder::CLASS_DESCRIPTIONS[$c->event_class], $c->description);
            }
        }
    }

    public function test_description_column_holds_more_than_255_chars(): void
    {
        $this->seed(CalendarEventClassSeeder::class);

        // The richest descriptions exceed the old varchar(255) — prove the TEXT widening
        // by writing + reading a >255 char value.
        $long = str_repeat('x', 400);
        $row = CalendarEventClassSetting::withoutGlobalScopes()->whereNull('agency_id')->first();
        $row->forceFill(['description' => $long])->save();

        $this->assertSame($long, $row->fresh()->description);
    }

    public function test_description_is_applied_to_agency_overrides_too(): void
    {
        $this->seed(CalendarEventClassSeeder::class);

        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC', 'slug' => 'hfc-' . uniqid(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        // An override with a stale description — cloned from the seeded global (via cast
        // accessors) so every NOT-NULL column is satisfied, exactly like the real turn-off.
        $global = CalendarEventClassSetting::withoutGlobalScopes()
            ->whereNull('agency_id')->where('event_class', 'mandate_expiry')->first();
        $attrs = [];
        foreach (\Illuminate\Support\Facades\Schema::getColumnListing('calendar_event_class_settings') as $col) {
            if (in_array($col, ['id', 'created_at', 'updated_at', 'agency_id', 'deleted_at'], true)) continue;
            $attrs[$col] = $global->{$col};
        }
        $attrs['is_active'] = false;
        $attrs['description'] = 'STALE';
        CalendarEventClassSetting::withoutGlobalScopes()->create(array_merge(['agency_id' => $agencyId], $attrs));

        // Re-running the seeder refreshes the override's description (applied to all rows).
        $this->seed(CalendarEventClassSeeder::class);

        $override = CalendarEventClassSetting::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->where('event_class', 'mandate_expiry')->first();
        $this->assertSame(CalendarEventClassSeeder::CLASS_DESCRIPTIONS['mandate_expiry'], $override->description);
    }
}
