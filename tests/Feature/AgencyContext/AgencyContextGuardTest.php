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
        // Properties stamp a branch, and the FK is enforced.
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
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

    // ── AT-260: the write that could not run outside a web request ───────

    /**
     * `PropertySellerLink::ensureExists()` was unusable from any console command, queued job or
     * webhook. `agency_id` is NOT NULL and nothing supplied it — BelongsToAgency fills it from
     * the ACTING USER, and there is no acting user in a console — so MySQL rejected the insert
     * with a 1364 and the job died. Found by seeding qa1 walk data from the CLI.
     *
     * The link belongs to the PROPERTY's tenant, so the property is now the source.
     */
    public function test_a_seller_link_can_be_created_with_no_authenticated_user(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'role' => 'agent']);
        $property = \App\Models\Property::create([
            'agency_id' => $this->agencyId, 'agent_id' => $agent->id, 'branch_id' => $this->agencyId,
            'title' => '1 Alamien Avenue, Uvongo', 'address' => '1 Alamien Avenue',
            'suburb' => 'Uvongo', 'status' => 'active', 'property_type' => 'House', 'price' => 2_000_000,
        ]);
        $seller = \App\Models\Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Marius', 'last_name' => 'van Rensburg',
        ]);

        // The console context: nobody is logged in.
        $this->assertGuest();

        $link = \App\Models\PropertySellerLink::ensureExists($property->id, $seller->id);

        $this->assertTrue($link->exists, 'the link must be creatable with no acting user');
        $this->assertSame($this->agencyId, (int) $link->agency_id, "the PROPERTY's tenant, derived");
        $this->assertNull($link->generated_by_user_id, 'and it is NOT falsely attributed to user 1');

        // Idempotent — a second call returns the same link, not a duplicate.
        $again = \App\Models\PropertySellerLink::ensureExists($property->id, $seller->id);
        $this->assertSame($link->id, $again->id);
    }

    /** ...and with no property to derive from, it refuses rather than inventing a tenant. */
    public function test_a_seller_link_for_a_missing_property_is_refused(): void
    {
        $seller = \App\Models\Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Ghost', 'last_name' => 'Seller',
        ]);

        $this->expectException(MissingAgencyContextException::class);
        \App\Models\PropertySellerLink::ensureExists(999999, $seller->id);
    }

    // ── the calendar sites (m1's domain, reassigned) ─────────────────────

    /**
     * The calendar's attendee picker searched AGENCY 1's contact book for a user who belongs to
     * no agency, and offered those people as attendees. A no-tenant user must see nobody.
     */
    public function test_the_calendar_attendee_search_does_not_leak_agency_one_contacts(): void
    {
        // A real contact in a real agency — the one that must NOT be offered.
        \App\Models\Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Thandi', 'last_name' => 'Mkhize',
            'phone' => '0713345567',
        ]);

        $superAdmin = User::factory()->create([
            'agency_id' => null, 'branch_id' => null, 'role' => 'super_admin',
        ]);
        $this->assertNull($superAdmin->agency_id, 'precondition: no agency context');

        $found = app(\App\Services\CommandCenter\CalendarEventService::class)
            ->searchAttendees($superAdmin, 'Thandi');

        $this->assertCount(0, $found,
            "A user with no agency must not be shown another tenant's contacts.");
    }

    /** ...and an agency-bound user still finds their own people — no collateral damage. */
    public function test_the_calendar_attendee_search_still_finds_the_users_own_contacts(): void
    {
        \App\Models\Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Thandi', 'last_name' => 'Mkhize',
            'phone' => '0713345567',
        ]);

        $agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent',
        ]);

        $found = app(\App\Services\CommandCenter\CalendarEventService::class)
            ->searchAttendees($agent, 'Thandi');

        $this->assertCount(1, $found);
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
