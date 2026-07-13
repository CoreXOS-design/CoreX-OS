<?php

namespace Tests\Feature\MultiTenancy;

use App\Models\Agency;
use App\Models\CommandCenter\CalendarEventClassSetting;
use Database\Seeders\CalendarEventClassSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * GLOBAL reference rows (agency_id NULL) must survive BelongsToAgency's
 * single-agency console fallback.
 *
 * The fallback stamps the only agency in the DB onto any un-agencied row so
 * seeders don't crash on NOT-NULL agency_id tables. It was also overwriting an
 * EXPLICIT null — so on a single-agency install (demo's corex_demo) the calendar
 * class defaults were written as agency-1 rows instead of global ones. The next
 * `deploy:sync-reference-data` then couldn't find its own global rows, re-inserted
 * them, and died on the (agency_id, event_class) unique key:
 *
 *   SQLSTATE[23000]: 1062 Duplicate entry '1-mandate_expiry' for key 'cecs_agency_class_unique'
 *
 * Live (two agencies) never armed the fallback, so the demo deploy broke while the
 * live deploy passed — the reason this went unnoticed.
 */
class GlobalReferenceRowStampTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    protected function setUp(): void
    {
        parent::setUp();

        // The fallback only arms at EXACTLY one agency — demo's shape (corex_demo),
        // not live's (agencies 1 and 7). Reproduce demo.
        $this->agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $this->assertSame(1, Agency::query()->count(), 'fixture expects a single-agency DB');
    }

    /** An explicit null agency_id is a deliberate GLOBAL row — never stamped. */
    public function test_explicit_null_agency_id_is_not_stamped_with_the_only_agency(): void
    {
        $row = CalendarEventClassSetting::withoutGlobalScopes()->create([
            'agency_id'   => null,
            'event_class' => 'test_global_class',
            'label'       => 'Test Global',
            'is_active'   => true,
        ] + $this->requiredThresholds());

        $this->assertNull($row->fresh()->agency_id);
    }

    /** The NOT-NULL fallback still fires when the caller never mentions agency_id. */
    public function test_omitted_agency_id_still_falls_back_to_the_only_agency(): void
    {
        $row = CalendarEventClassSetting::withoutGlobalScopes()->create([
            'event_class' => 'test_stamped_class',
            'label'       => 'Test Stamped',
            'is_active'   => true,
        ] + $this->requiredThresholds());

        $this->assertSame($this->agency->id, $row->fresh()->agency_id);
    }

    /** NOT-NULL RAG columns the table requires but this test doesn't care about. */
    private function requiredThresholds(): array
    {
        return [
            'green_days' => 30, 'amber_days' => 14, 'red_days' => 7, 'show_days' => 90,
            'green_visibility' => ['agent'], 'amber_visibility' => ['agent'], 'red_visibility' => ['agent'],
            'green_notifications' => [], 'amber_notifications' => [], 'red_notifications' => [],
        ];
    }

    /**
     * deploy:sync-reference-data runs this seeder on EVERY deploy, so it has to be
     * re-runnable on the same DB. Second run used to throw the 1062 above.
     */
    public function test_calendar_event_class_seeder_is_idempotent_on_a_single_agency_install(): void
    {
        $seed = fn () => Artisan::call('db:seed', [
            '--class' => CalendarEventClassSeeder::class,
            '--force' => true,
        ]);

        $seed();

        $globals = CalendarEventClassSetting::withoutGlobalScopes()->whereNull('agency_id')->count();
        $this->assertGreaterThan(0, $globals, 'seeder must write GLOBAL rows, not agency-owned ones');
        $this->assertSame(
            0,
            CalendarEventClassSetting::withoutGlobalScopes()->whereNotNull('agency_id')->count(),
            'seeder must not stamp its class defaults onto the only agency'
        );

        $seed(); // the deploy re-run — must not collide on cecs_agency_class_unique

        $this->assertSame(
            $globals,
            CalendarEventClassSetting::withoutGlobalScopes()->whereNull('agency_id')->count(),
            're-running the seeder must update in place, not duplicate'
        );
    }
}
