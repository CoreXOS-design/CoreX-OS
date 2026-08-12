<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\CommandCenter\CalendarEventClassSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Regression for the 2026-08-12 audit finding: cecs_agency_class_unique
 * (agency_id, event_class) doesn't stop two GLOBAL rows (agency_id IS NULL)
 * sharing an event_class — MySQL never treats two NULLs as equal in a
 * unique index (same landmine as roles_name_agency_unique, fixed the same
 * day in RoleProvisioningService). Found live: two duplicate 'manual' rows,
 * silently resolved non-deterministically by ->first() with no ordering.
 *
 * These tests prove resolution stays deterministic (oldest row wins) even
 * if a duplicate global row exists again.
 */
final class CalendarEventClassGlobalDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_global_default_prefers_the_oldest_row_when_duplicated(): void
    {
        $older = $this->insertGlobalRow('dupe_test_class', 'Older (canonical)');
        $newer = $this->insertGlobalRow('dupe_test_class', 'Newer (duplicate)');

        $this->assertTrue($older < $newer, 'test setup: older id must sort before newer id');

        $resolved = CalendarEventClassSetting::globalDefault('dupe_test_class');

        $this->assertNotNull($resolved);
        $this->assertSame($older, $resolved->id);
        $this->assertSame('Older (canonical)', $resolved->label);
    }

    public function test_for_agency_and_class_resolves_deterministically_with_duplicate_globals(): void
    {
        $older = $this->insertGlobalRow('dupe_test_class_2', 'Older (canonical)');
        $this->insertGlobalRow('dupe_test_class_2', 'Newer (duplicate)');

        $first  = CalendarEventClassSetting::forAgencyAndClass(null, 'dupe_test_class_2');
        $second = CalendarEventClassSetting::forAgencyAndClass(null, 'dupe_test_class_2');

        $this->assertSame($older, $first?->id);
        $this->assertSame($first?->id, $second?->id, 'Repeated resolution must not flip between the duplicate rows');
    }

    private function insertGlobalRow(string $eventClass, string $label): int
    {
        return (int) DB::table('calendar_event_class_settings')->insertGetId([
            'agency_id'            => null,
            'event_class'          => $eventClass,
            'label'                => $label,
            'is_active'            => true,
            'green_days'           => 30,
            'amber_days'           => 14,
            'red_days'             => 7,
            'show_days'            => 90,
            'green_visibility'     => json_encode(['agent']),
            'amber_visibility'     => json_encode(['agent']),
            'red_visibility'       => json_encode(['agent']),
            'green_notifications'  => json_encode([]),
            'amber_notifications'  => json_encode([]),
            'red_notifications'    => json_encode([]),
            'daily_digest_enabled' => false,
            'daily_digest_roles'   => json_encode([]),
            'created_at'           => now(),
            'updated_at'           => now(),
        ]);
    }
}
