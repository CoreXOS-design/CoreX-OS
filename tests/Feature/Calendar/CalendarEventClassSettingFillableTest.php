<?php

declare(strict_types=1);

namespace Tests\Feature\Calendar;

use App\Models\CommandCenter\CalendarEventClassSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CAL-7 Class 6 — fillable-vs-schema regression guard.
 *
 * Every column on calendar_event_class_settings that the application is
 * meant to set must be present in the model's $fillable, otherwise
 * mass-assignment silently drops the value and the row gets the DB
 * default. The historical pattern (allow_multiple_properties, actor_role,
 * completion_behaviour, feedback_mode) shows this trap is repeatedly
 * loaded by new migrations that forget to update $fillable.
 *
 * The test compares the columns on the table against the model's
 * $fillable and fails on any "real" (non-housekeeping) column missing.
 * It does NOT require id, timestamps, soft-delete, or agency_id (the
 * BelongsToAgency trait auto-fills agency_id on save, so it doesn't need
 * to be mass-assigned by callers — but it IS already in fillable).
 */
final class CalendarEventClassSettingFillableTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_real_column_is_fillable(): void
    {
        $columns = Schema::getColumnListing((new CalendarEventClassSetting)->getTable());

        // Housekeeping columns that should NEVER be mass-assigned:
        $exempt = [
            'id',
            'created_at',
            'updated_at',
            'deleted_at',
        ];

        $expected = array_values(array_diff($columns, $exempt));
        $actual = (new CalendarEventClassSetting)->getFillable();

        $missing = array_values(array_diff($expected, $actual));
        $extra   = array_values(array_diff($actual, $columns));

        // Surface every missing column so the developer fixing this sees
        // them all at once.
        $this->assertSame(
            [],
            $missing,
            "Columns on calendar_event_class_settings are NOT in \$fillable:\n  "
            . implode("\n  ", $missing)
            . "\n\nAdd them to App\\Models\\CommandCenter\\CalendarEventClassSetting::\$fillable "
            . "or extend the \$exempt list in this test if they truly should not be mass-assigned.\n"
            . "\$fillable currently:\n  " . implode("\n  ", $actual)
        );

        $this->assertEmpty(
            $extra,
            "\$fillable references columns that don't exist on the table: " . implode(', ', $extra)
        );
    }

    public function test_feedback_mode_round_trips_via_mass_assignment(): void
    {
        $row = CalendarEventClassSetting::withoutGlobalScopes()->create([
            'agency_id' => null,
            'event_class' => 'cal7_test_class',
            'feedback_mode' => 'per_property',
            'is_active' => true,
            // Schema-required (NOT NULL no-default) fields. The point of
            // this test is to round-trip feedback_mode; the others are
            // here to satisfy the migration.
            'green_days' => 30,
            'amber_days' => 14,
            'red_days'   => 7,
            'show_days'  => 90,
            'green_visibility' => ['agent'],
            'amber_visibility' => ['agent'],
            'red_visibility'   => ['agent'],
            'green_notifications' => [],
            'amber_notifications' => [],
            'red_notifications'   => [],
            'daily_digest_enabled' => false,
            'daily_digest_roles'  => [],
            'label'               => 'CAL-7 test class',
        ]);

        $this->assertSame(
            'per_property',
            $row->fresh()->feedback_mode,
            "feedback_mode dropped on create() — \$fillable doesn't include it."
        );
    }
}
