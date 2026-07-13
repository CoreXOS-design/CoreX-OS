<?php

declare(strict_types=1);

namespace Tests\Feature\AgencyContext;

use App\Exceptions\MissingAgencyContextException;
use App\Models\AgentCapPeriod;
use App\Models\CommissionSetting;
use App\Models\User;
use App\Services\DealV2\AgencyServiceProviderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-253 (STANDARDS Rule 17) — no agency context is a REAL context.
 *
 * Owners/super-admins carry `agency_id = NULL`, and so do console commands, queued jobs and
 * webhooks. The code assumed a tenant always exists and did one of two damaging things:
 *
 *   `?? 1`  → silently wrote the row into AGENCY 1. No error, wrong tenant, and nobody finds
 *             out until another agency's data is sitting in Home Finders' books.
 *   `?: 0`  → wrote agency_id 0, which has no parent row → FK 1452 → a raw 500.
 *
 * The rule: READS resolve to the sentinel 0 and hit a `<= 0` guard that returns unsaved
 * defaults. WRITES derive the agency from the domain object, or refuse — they never invent one.
 */
final class AgencyContextGuardTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    // ── READS: the <= 0 guard returns defaults and writes NOTHING ────────

    public function test_commission_settings_for_a_no_agency_actor_returns_defaults_without_writing(): void
    {
        $before = CommissionSetting::withoutGlobalScopes()->count();

        $settings = CommissionSetting::forAgency(0);   // the sentinel a null agency resolves to

        $this->assertFalse($settings->exists, 'the guard must NOT persist a row');
        $this->assertSame($before, CommissionSetting::withoutGlobalScopes()->count(), 'no row was written');

        // ...and it is a usable settings object, not a bag of nulls.
        $this->assertSame(80, (int) $settings->commission_split_agent);
        $this->assertSame(160000.0, (float) $settings->annual_cap);
        $this->assertSame(3.50, (float) $settings->tier_1_percent);
    }

    /** The guard is what makes the sentinel safe. Without it this is an FK-1452 on write. */
    public function test_the_sentinel_never_creates_an_agency_zero_settings_row(): void
    {
        CommissionSetting::forAgency(0);
        CommissionSetting::forAgency(-1);

        $this->assertSame(0, CommissionSetting::withoutGlobalScopes()->where('agency_id', 0)->count());
        $this->assertDatabaseMissing('commission_settings', ['agency_id' => 0]);
    }

    /** A real agency still gets its real, persisted row — the guard changes nothing here. */
    public function test_a_real_agency_still_gets_its_persisted_settings_row(): void
    {
        $settings = CommissionSetting::forAgency($this->agencyId);

        $this->assertTrue($settings->exists);
        $this->assertSame($this->agencyId, (int) $settings->agency_id);
    }

    // ── WRITES: refuse, never invent a tenant ────────────────────────────

    /**
     * A cap period is a FINANCIAL record. The old `?? 1` opened one inside AGENCY 1 for any
     * owner/super-admin who so much as loaded the commission screen.
     */
    public function test_a_cap_period_is_never_opened_for_a_no_agency_actor(): void
    {
        $user = User::factory()->create(['agency_id' => $this->agencyId, 'role' => 'agent']);

        $this->expectException(MissingAgencyContextException::class);

        try {
            AgentCapPeriod::currentForUser($user->id, 0);
        } finally {
            // The point is not just the throw — it is that NOTHING was written anywhere.
            $this->assertSame(0, DB::table('agent_cap_periods')->count());
        }
    }

    /** The service-provider directory: an unguarded sentinel here was a latent FK-1452. */
    public function test_a_service_provider_is_never_created_for_a_no_agency_actor(): void
    {
        $this->expectException(MissingAgencyContextException::class);

        try {
            app(AgencyServiceProviderService::class)->findOrCreate(0, [
                'name' => 'BBB Attorneys', 'specialty' => 'transfer_attorney',
            ]);
        } finally {
            $this->assertSame(0, DB::table('agency_service_providers')->count());
        }
    }

    /** The refusal is a sentence a human can act on, not a stack trace (BUILD_STANDARD §4). */
    public function test_the_refusal_explains_itself_and_names_the_way_forward(): void
    {
        $e = new MissingAgencyContextException('a training course');

        $this->assertStringContainsString('not attached to an agency', $e->userMessage());
        $this->assertStringContainsString('Switch into an agency first', $e->userMessage());
    }

    // ── the end-to-end shape: a null-agency user cannot silently write into agency 1 ──

    public function test_a_super_admin_with_no_agency_cannot_file_feedback_into_agency_one(): void
    {
        $superAdmin = User::factory()->create([
            'agency_id' => null, 'branch_id' => null, 'role' => 'super_admin',
        ]);
        $this->assertNull($superAdmin->effectiveAgencyId(), 'precondition: no agency context');

        $before = DB::table('feedback_reports')->count();

        $this->actingAs($superAdmin)
            ->post(route('command-center.feedback.store'), [
                'title'    => 'Button is misaligned',
                'body'     => 'The save button overlaps the footer on mobile.',
                'page_url' => '/corex/properties',
            ]);

        // Whatever the response, the invariant is the same: no row was invented under agency 1.
        $this->assertSame($before, DB::table('feedback_reports')->count(),
            'a no-agency actor must not silently file into another tenant');
        $this->assertSame(0, DB::table('feedback_reports')->where('agency_id', 1)->count());
    }
}
