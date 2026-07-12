<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\AgencyDealSyncSettings;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * DR2 Wave 2 — Deal → Property → Portal status sync (Johan's design). Event-driven
 * (DealCreated / DealStageAdvanced / DealClosed → property status), agency-configurable,
 * OFF by default, prior status captured for the decline-revert companion.
 */
final class Wave2DealPropertyStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'W2 Co', 'slug' => 'w2-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Main', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'agent']);

        $this->property = Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'W2-' . Str::random(8), 'title' => '9 Sync Rd', 'address' => '9 Sync Rd',
            'agent_id' => $agent->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'listing_type' => 'sale', 'status' => 'for_sale',
        ]));
    }

    private function settings(array $over): void
    {
        AgencyDealSyncSettings::forAgency($this->agencyId)->update($over);
    }

    private function makeDeal(array $over = []): Deal
    {
        return Deal::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'deal_no' => (string) random_int(6000, 9999), 'period' => '2026-06', 'deal_date' => '2026-06-10',
            'property_id' => $this->property->id, 'accepted_status' => 'P', 'commission_status' => 'Not Paid',
            'property_value' => 1_000_000, 'total_commission' => 57_500,
            'listing_split_percent' => 50, 'selling_split_percent' => 50,
        ], $over));
    }

    private function acceptedOf(Deal $d): string
    {
        return (string) Deal::withoutGlobalScopes()->find($d->id)->accepted_status;
    }

    // ── Wave 2 refinement: grant cascades ────────────────────────────────────

    /** 4 offers = 4 pending deals; granting one AUTO-DECLINES the other three (audited). */
    public function test_grant_cascades_auto_declines_all_other_active_deals(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $a = $this->makeDeal(['deal_no' => '7001']);
        $b = $this->makeDeal(['deal_no' => '7002']);
        $c = $this->makeDeal(['deal_no' => '7003']);
        $d = $this->makeDeal(['deal_no' => '7004']);
        $this->assertSame('under_offer', $this->property->refresh()->status);

        $a->update(['accepted_status' => 'G']); // grant A

        $this->assertSame('G', $this->acceptedOf($a));
        foreach ([$b, $c, $d] as $sib) {
            $this->assertSame('D', $this->acceptedOf($sib), 'every other active deal must auto-decline');
        }
        $this->assertDatabaseHas('deal_logs', ['deal_id' => $b->id, 'event_type' => 'auto_declined']);
        // Granted deal keeps the property live (sold milestone OFF) — stays under-offer.
        $this->assertSame('under_offer', $this->property->refresh()->status);
    }

    /** Granted deal falls through → auto-declined sibling is RE-GRANTABLE; property re-flags. */
    public function test_fall_through_re_grant_is_allowed_and_reflags_property(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true, 'revert_property_on_deal_declined' => true]);
        $a = $this->makeDeal(['deal_no' => '7101']);
        $b = $this->makeDeal(['deal_no' => '7102']);

        $a->update(['accepted_status' => 'G']);          // grant A → B auto-declined
        $this->assertSame('D', $this->acceptedOf($b));

        $a->update(['accepted_status' => 'D']);          // A falls through → no active deal → revert
        $this->assertSame('for_sale', $this->property->refresh()->status);

        $b->update(['accepted_status' => 'G']);          // re-grant the auto-declined B (D→G) — legal
        $this->assertSame('G', $this->acceptedOf($b));
        $this->assertSame('under_offer', $this->property->refresh()->status, 're-grant must re-flag under-offer');
    }

    /** The block survives for exactly one case: granting while ANOTHER deal is granted. */
    public function test_second_grant_while_one_granted_is_blocked(): void
    {
        $a = $this->makeDeal(['deal_no' => '7201']);
        $b = $this->makeDeal(['deal_no' => '7202']);
        $a->update(['accepted_status' => 'G']);          // A granted (B auto-declined)

        $svc = app(\App\Services\Deal\DealPropertyStatusService::class);
        $bFresh = Deal::withoutGlobalScopes()->find($b->id);

        $conflict = $svc->existingCommittedDeal($bFresh);
        $this->assertNotNull($conflict);
        $this->assertSame($a->id, $conflict->id);

        $this->expectException(\App\Exceptions\Deal\DuplicateGrantException::class);
        $svc->assertCanGrant($bFresh);
    }

    /**
     * The creation-time gap: a NEW offer captured on a property that ALREADY carries
     * a granted deal is CAPTURED (never lost) but AUTO-DECLINED on save, audited.
     */
    public function test_new_capture_on_committed_property_auto_declines_and_audits(): void
    {
        $granted = $this->makeDeal(['deal_no' => '7301']);
        $granted->update(['accepted_status' => 'G']);          // property now committed
        $this->assertSame('G', $this->acceptedOf($granted));

        $late = $this->makeDeal(['deal_no' => '7302']);         // new offer, captured Pending

        $this->assertSame('D', $this->acceptedOf($late), 'new capture on a committed property lands Declined');
        $this->assertDatabaseHas('deal_logs', [
            'deal_id' => $late->id, 'event_type' => 'auto_declined', 'to_value' => 'D',
        ]);
    }

    /** A Registered deal is committed too — a later pending capture is auto-declined. */
    public function test_new_capture_on_registered_property_auto_declines(): void
    {
        $reg = $this->makeDeal(['deal_no' => '7311']);
        $reg->update(['accepted_status' => 'G']);
        $reg->update(['accepted_status' => 'R']);
        $this->assertSame('R', $this->acceptedOf($reg));

        $late = $this->makeDeal(['deal_no' => '7312']);
        $this->assertSame('D', $this->acceptedOf($late), 'a registered property is committed — new offer declined');
    }

    /** Multiple pending offers with NO grant yet all stay Pending — the multi-offer premise. */
    public function test_new_capture_stays_pending_when_no_committed_deal(): void
    {
        $a = $this->makeDeal(['deal_no' => '7401']);
        $b = $this->makeDeal(['deal_no' => '7402']);
        $this->assertSame('P', $this->acceptedOf($a));
        $this->assertSame('P', $this->acceptedOf($b), 'a second pending offer is legal when none is granted');
    }

    // ── Wave 2: resale / duplicate-address search guard ──────────────────────

    /** Property search excludes off-market (sold) twins by default; ?all=1 reveals them, flagged. */
    public function test_resale_search_excludes_off_market_by_default_and_flags_on_all(): void
    {
        $agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId, 'role' => 'admin', 'is_active' => true,
        ]);
        $mk = fn (string $status) => Property::withoutEvents(fn () => Property::withoutGlobalScopes()->create([
            'external_id' => 'TWIN-' . Str::random(8), 'title' => '12 Resale Rd', 'address' => '12 Resale Rd',
            'agent_id' => $agent->id, 'branch_id' => $this->branchId, 'agency_id' => $this->agencyId,
            'listing_type' => 'sale', 'status' => $status,
        ]));
        $active = $mk('for_sale');
        $sold   = $mk('sold');

        $this->actingAs($agent)->withoutVite();

        $default = collect($this->getJson(route('deals-dr2.search.properties', ['q' => 'Resale Rd']))->assertOk()->json());
        $this->assertTrue($default->contains('id', $active->id), 'the live listing must appear');
        $this->assertFalse($default->contains('id', $sold->id), 'the sold twin must be hidden by default');

        $all = collect($this->getJson(route('deals-dr2.search.properties', ['q' => 'Resale Rd', 'all' => 1]))->assertOk()->json());
        $soldRow = $all->firstWhere('id', $sold->id);
        $this->assertNotNull($soldRow, 'show-all reveals the sold twin');
        $this->assertFalse($soldRow['on_market'], 'the sold twin is flagged off-market for the warn');
    }

    public function test_off_by_default_deal_created_does_not_touch_property(): void
    {
        $this->makeDeal();
        $this->assertSame('for_sale', $this->property->fresh()->status, 'default OFF — no status change');
    }

    public function test_flag_on_marks_under_offer_and_captures_prior(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $this->makeDeal();
        $p = $this->property->fresh();
        $this->assertSame('under_offer', $p->status);
        $this->assertSame('for_sale', $p->pre_deal_offer_status);
    }

    public function test_no_linked_property_is_a_noop(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $this->makeDeal(['property_id' => null]);
        $this->assertSame('for_sale', $this->property->fresh()->status);
    }

    public function test_off_market_property_is_not_flagged(): void
    {
        Property::withoutEvents(fn () => $this->property->update(['status' => 'sold']));
        $this->settings(['flag_property_under_offer_on_deal' => true]);
        $this->makeDeal();
        $this->assertSame('sold', $this->property->fresh()->status, 'off-market never moved to under_offer');
    }

    public function test_sold_at_granted_milestone(): void
    {
        $this->settings(['sold_milestone' => 'granted']);
        $deal = $this->makeDeal();
        $deal->update(['accepted_status' => 'G']);
        $this->assertSame('sold', $this->property->fresh()->status);
    }

    public function test_registered_milestone_waits_for_R(): void
    {
        $this->settings(['sold_milestone' => 'registered']);
        $deal = $this->makeDeal();
        $deal->update(['accepted_status' => 'G']);
        $this->assertNotSame('sold', $this->property->fresh()->status, 'Granted does not sell when milestone=registered');
        $deal->update(['accepted_status' => 'R']);
        $this->assertSame('sold', $this->property->fresh()->status);
    }

    public function test_decline_reverts_to_prior_on_market_status(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true]); // revert defaults ON
        $deal = $this->makeDeal();
        $this->assertSame('under_offer', $this->property->fresh()->status);
        $deal->update(['accepted_status' => 'D']); // Declined → DealClosed(lost) → revert
        $p = $this->property->fresh();
        $this->assertSame('for_sale', $p->status);
        $this->assertNull($p->pre_deal_offer_status);
    }

    public function test_revert_off_leaves_under_offer(): void
    {
        $this->settings(['flag_property_under_offer_on_deal' => true, 'revert_property_on_deal_declined' => false]);
        $deal = $this->makeDeal();
        $deal->update(['accepted_status' => 'D']);
        $this->assertSame('under_offer', $this->property->fresh()->status);
    }
}
