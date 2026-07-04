<?php

declare(strict_types=1);

namespace Tests\Feature\CommandCenter;

use App\Models\AgencyContactSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression — AT-178 calendar reminder-lead lookup.
 *
 * CalendarController::sharedViewData() calls
 *   AgencyContactSettings::forAgency((int) ($user->agency_id ?: 0))
 * A global super_admin has a null agency_id, coerced to 0 by that caller.
 * forAgency() used to firstOrCreate a row with agency_id 0, which has no parent
 * in `agencies` → 1452 FK violation → 500 on the calendar for every owner.
 *
 * The fix: forAgency() never persists for agency_id <= 0; it returns an unsaved
 * defaults instance whose null-safe accessors still yield correct values.
 */
final class AgencySettingsNullAgencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_for_agency_zero_returns_unsaved_defaults_and_never_persists(): void
    {
        $before = DB::table('agency_contact_settings')->count();

        $settings = AgencyContactSettings::forAgency(0);

        // Instance returned, NOT persisted (no FK-violating write).
        $this->assertFalse($settings->exists, 'no-agency settings must be an unsaved instance');
        $this->assertSame(
            $before,
            DB::table('agency_contact_settings')->count(),
            'forAgency(0) must not create a row'
        );
        $this->assertDatabaseMissing('agency_contact_settings', ['agency_id' => 0]);

        // Null-safe accessors still return sane defaults (the calendar path).
        $this->assertNotEmpty($settings->calendarReminderLeadOptions());
        $this->assertGreaterThanOrEqual(1, $settings->calendarMaxOccurrences());
    }

    public function test_for_agency_negative_is_also_absorbed(): void
    {
        $settings = AgencyContactSettings::forAgency(-5);
        $this->assertFalse($settings->exists);
        $this->assertDatabaseMissing('agency_contact_settings', ['agency_id' => -5]);
    }

    public function test_for_agency_valid_id_still_persists_defaults(): void
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'HFC Coastal', 'slug' => 'hfc-coastal-' . uniqid(),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $settings = AgencyContactSettings::forAgency($agencyId);

        $this->assertTrue($settings->exists, 'a real agency must get a persisted settings row');
        $this->assertDatabaseHas('agency_contact_settings', ['agency_id' => $agencyId]);

        // Idempotent — a second call returns the same row, not a duplicate.
        $again = AgencyContactSettings::forAgency($agencyId);
        $this->assertSame($settings->getKey(), $again->getKey());
    }
}
