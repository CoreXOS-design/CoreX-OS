<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingClaim;
use App\Models\ProspectingListing;
use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use App\Services\Prospecting\CaptureClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/property-identity.md §16.5 (Johan, 29 Oct ruling): own stock matched to Market Intelligence counts as claimed. After a join the
 * file's adverts are recognised as ours, claimed for the listing's agent in the agency's work record, and the own-stock flag is on;
 * never another agency's view; never another agent's live claim.
 */
final class OwnStockClaimOnJoinTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Agency $other;
    private User $agent;
    private User $rival;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agency = Agency::create(['name' => 'Agency A', 'slug' => 'agency-a']);
        $this->other = Agency::create(['name' => 'Agency B', 'slug' => 'agency-b']);
        $br = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $br->id, 'role' => 'agent', 'is_active' => true]);
        $this->rival = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $br->id, 'role' => 'agent', 'is_active' => true]);
        $this->admin = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $br->id, 'role' => 'admin', 'is_active' => true]);
    }

    private function file(?int $agencyId = null): TrackedProperty
    {
        return TrackedProperty::queryWithoutAgencyScope()->create(['agency_id' => $agencyId ?? $this->agency->id, 'status' => 'active', 'street_number' => '9', 'street_name' => 'Beach Road', 'suburb' => 'Uvongo', 'first_seen_at' => now()]);
    }

    private function advert(TrackedProperty $tp, string $ref): ProspectingListing
    {
        return ProspectingListing::create([
            'agency_id' => $tp->agency_id, 'captured_by_user_id' => $this->admin->id, 'tracked_property_id' => $tp->id, 'portal_source' => 'p24', 'portal_ref' => $ref,
            'portal_url' => 'https://www.property24.com/x/' . $ref, 'address' => '9 Beach Road, Uvongo', 'suburb' => 'Uvongo', 'price' => 1_500_000,
            'first_seen_at' => now(), 'last_seen_at' => now(), 'is_active' => true,
        ]);
    }

    private function property(TrackedProperty $tp, array $attrs = []): int
    {
        return DB::table('properties')->insertGetId(array_merge([
            'agency_id' => $tp->agency_id, 'address' => '9 Beach Road, Uvongo', 'suburb' => 'Uvongo', 'status' => 'active', 'title' => '9 Beach Road', 'agent_id' => $this->agent->id,
            'branch_id' => $this->agent->branch_id, 'external_id' => (string) Str::uuid(), 'listing_type' => 'sale',
            'tracked_property_id' => $tp->id, 'identity_confidence' => 'high', 'identity_method' => 'address_unit', 'created_at' => now(), 'updated_at' => now(),
        ], $attrs));
    }

    public function test_a_joined_listing_recognises_and_claims_the_files_adverts_for_its_agent_and_turns_own_stock_on(): void
    {
        $tp = $this->file();
        $a = $this->advert($tp, 'P24-100000601');
        $b = $this->advert($tp, 'P24-100000602');
        $pid = $this->property($tp);

        $r = app(CaptureClaimService::class)->claimFromJoin($pid);

        $this->assertSame(CaptureClaimService::OUTCOME_CLAIMED, $r['outcome']);
        $this->assertSame(2, $r['matched']);
        $this->assertSame(2, $r['claimed']);
        $this->assertTrue($r['own_stock']);
        $this->assertSame($pid, (int) $a->refresh()->matched_property_id);
        $this->assertSame(2, ProspectingClaim::withoutGlobalScopes()->where('user_id', $this->agent->id)->where('property_id', $pid)->where('is_active', true)->count());
        $work = DB::table('tracked_property_agency_work')->where('tracked_property_id', $tp->id)->first();
        $this->assertSame((int) $this->agent->id, (int) $work->claimed_by_user_id, 'claimed in the agency\'s work record by the listing\'s agent');
        $this->assertSame(1, (int) $work->is_own_stock);
        $this->assertSame($pid, (int) $work->own_stock_property_id);
        $this->assertSame('linked', $work->own_stock_source);

        // a re-run does nothing new
        $again = app(CaptureClaimService::class)->claimFromJoin($pid);
        $this->assertSame(0, $again['claimed']);
        $this->assertSame(2, ProspectingClaim::withoutGlobalScopes()->where('property_id', $pid)->count());
    }

    public function test_another_agents_live_claim_is_never_taken_but_the_advert_is_still_recognised_as_ours(): void
    {
        $tp = $this->file();
        $a = $this->advert($tp, 'P24-100000611');
        ProspectingClaim::withoutEvents(fn () => ProspectingClaim::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $a->id, 'user_id' => $this->rival->id, 'status' => 'claimed', 'claimed_at' => now(), 'last_updated_at' => now(), 'is_active' => true,
        ]));
        $pid = $this->property($tp);

        $r = app(CaptureClaimService::class)->claimFromJoin($pid);

        $this->assertSame(CaptureClaimService::OUTCOME_HELD_BY_OTHER, $r['outcome']);
        $this->assertSame(0, ProspectingClaim::withoutGlobalScopes()->where('user_id', $this->agent->id)->count());
        $this->assertSame((int) $this->rival->id, (int) ProspectingClaim::withoutGlobalScopes()->where('prospecting_listing_id', $a->id)->value('user_id'));
        $this->assertSame($pid, (int) $a->refresh()->matched_property_id);
    }

    public function test_a_prospecting_or_draft_row_is_not_stock_and_claims_nothing(): void
    {
        $tp = $this->file();
        $a = $this->advert($tp, 'P24-100000621');
        $pid = $this->property($tp, ['status' => 'prospecting']);

        $r = app(CaptureClaimService::class)->claimFromJoin($pid);

        $this->assertSame('not_own_stock', $r['outcome']);
        $this->assertNull($a->refresh()->matched_property_id);
        $this->assertSame(0, ProspectingClaim::withoutGlobalScopes()->count());
    }

    public function test_another_agencys_file_and_adverts_are_never_touched(): void
    {
        $mine = $this->file();
        $theirs = $this->file($this->other->id);
        $this->advert($mine, 'P24-100000631');
        $foreign = $this->advert($theirs, 'P24-100000631');
        $pid = $this->property($mine);

        app(CaptureClaimService::class)->claimFromJoin($pid);

        $this->assertNull($foreign->refresh()->matched_property_id);
        $this->assertSame(0, ProspectingClaim::withoutGlobalScopes()->where('agency_id', $this->other->id)->count());
        $this->assertSame(0, DB::table('tracked_property_agency_work')->where('agency_id', $this->other->id)->count());
    }

    public function test_an_own_stock_claim_never_goes_stale(): void
    {
        $tp = $this->file();
        $a = $this->advert($tp, 'P24-100000641');
        $pid = $this->property($tp);
        app(CaptureClaimService::class)->claimFromJoin($pid);
        $claim = ProspectingClaim::withoutGlobalScopes()->where('property_id', $pid)->firstOrFail();
        $claim->forceFill(['claimed_at' => now()->subDays(90), 'last_updated_at' => now()->subDays(90)])->save();

        $this->assertTrue($claim->isOwnStockClaim());
        $this->assertFalse($claim->needsStaleWarning(7));
        $this->assertFalse($claim->isStaleForReview(10));

        // an ordinary prospect claim of the same age still goes stale
        $b = $this->advert($this->file(), 'P24-100000642');
        $plain = ProspectingClaim::withoutEvents(fn () => ProspectingClaim::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'prospecting_listing_id' => $b->id, 'user_id' => $this->rival->id, 'status' => 'claimed', 'claimed_at' => now()->subDays(90), 'last_updated_at' => now()->subDays(90), 'is_active' => true,
        ]));
        $this->assertTrue($plain->needsStaleWarning(7));
    }

    public function test_the_review_screen_same_property_claims_for_the_agent(): void
    {
        $tp = $this->file();
        $a = $this->advert($tp, 'P24-100000651');
        $pid = $this->property($tp, ['tracked_property_id' => null, 'identity_confidence' => null, 'identity_method' => null]);
        $d = DB::table('property_match_decisions')->insertGetId([
            'agency_id' => $this->agency->id, 'subject_type' => 'identity_possible', 'subject_key' => 'property:' . $pid, 'matched_type' => 'tracked_property', 'matched_id' => $tp->id,
            'strategy' => 'address_unit', 'reason' => 'Probable match: same street', 'incoming_facts' => json_encode(['kind' => 'own_stock', 'property_id' => $pid]),
            'decided_at' => now(), 'outcome' => 'pending', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)->post(route('corex.possible-matches.confirm', $d))->assertRedirect();

        $this->assertSame($pid, (int) $a->refresh()->matched_property_id);
        $this->assertSame(1, ProspectingClaim::withoutGlobalScopes()->where('user_id', $this->agent->id)->where('property_id', $pid)->count());
        $this->assertSame(1, (int) DB::table('tracked_property_agency_work')->where('tracked_property_id', $tp->id)->value('is_own_stock'));
    }

    public function test_the_resolver_claims_when_an_existing_listing_is_joined_to_its_file_going_forward(): void
    {
        $tp = $this->file();
        $a = $this->advert($tp, 'P24-100000671');
        $pid = $this->property($tp, ['tracked_property_id' => null, 'identity_confidence' => null, 'identity_method' => null, 'street_number' => '9', 'street_name' => 'Beach Road']);

        $result = app(\App\Services\Prospecting\PropertyIdentityResolver::class)->linkProperty(\App\Models\Property::withoutGlobalScopes()->findOrFail($pid));

        $this->assertNotNull($result);
        $this->assertSame($tp->id, (int) DB::table('properties')->where('id', $pid)->value('tracked_property_id'), 'the door joined the listing to the file');
        $this->assertSame($pid, (int) $a->refresh()->matched_property_id);
        $this->assertSame(1, ProspectingClaim::withoutGlobalScopes()->where('user_id', $this->agent->id)->where('property_id', $pid)->count());
        $this->assertSame(1, (int) DB::table('tracked_property_agency_work')->where('tracked_property_id', $tp->id)->value('is_own_stock'));
    }


    public function test_the_backfill_plans_first_then_claims_once_and_leaves_the_test_agency_out(): void
    {
        $tp = $this->file();
        $this->advert($tp, 'P24-100000661');
        $pid = $this->property($tp);
        $testTp = $this->file($this->other->id);
        $this->advert($testTp, 'P24-100000662');
        $this->property($testTp, ['agent_id' => $this->agent->id]);

        Artisan::call('property-identity:claim-joined', ['--dry-run' => true, '--exclude-agency' => (string) $this->other->id]);
        $this->assertSame(0, ProspectingClaim::withoutGlobalScopes()->count(), 'a dry run writes nothing');
        $this->assertSame(0, DB::table('prospecting_listings')->whereNotNull('matched_property_id')->count());

        Artisan::call('property-identity:claim-joined', ['--apply' => true, '--exclude-agency' => (string) $this->other->id, '--journal-dir' => sys_get_temp_dir()]);
        $this->assertSame(1, ProspectingClaim::withoutGlobalScopes()->where('property_id', $pid)->count());
        $this->assertSame(0, ProspectingClaim::withoutGlobalScopes()->where('agency_id', $this->other->id)->count(), 'the excluded agency is untouched');

        Artisan::call('property-identity:claim-joined', ['--apply' => true, '--exclude-agency' => (string) $this->other->id, '--journal-dir' => sys_get_temp_dir()]);
        $this->assertSame(1, ProspectingClaim::withoutGlobalScopes()->count(), 'idempotent');
    }
}
