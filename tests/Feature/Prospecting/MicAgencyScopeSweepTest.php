<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ProspectingClaim;
use App\Models\Prospecting\TrackedPropertyAgencyWork;
use App\Models\Prospecting\TrackedPropertyComment;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\User;
use App\Services\Prospecting\AgencyWorkService;
use App\Services\Prospecting\OnMarketStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\RoadToSold\Concerns\RtsFixtures;
use Tests\TestCase;

/**
 * Lane B step 3: the agency layer is private at the QUERY layer. Agency A holds a tracked property with an owner (names + ID number from a paid
 * deeds capture), a comment, a claim, a prospecting status and an email-alert row. An admin of agency B opens every Market Intelligence,
 * Tracked Properties and Deeds screen - by id and as lists - and none of A's private facts appear. A's admin sees them (control).
 */
final class MicAgencyScopeSweepTest extends TestCase
{
    use RefreshDatabase, RtsFixtures;

    private const STREET = 'Zebra Crossing Lane';
    private const OWNER = 'Zelda Zimmerman';
    private const ID_NUMBER = '7001015009087';
    private const COMMENT = 'zz-secret-comment-text';
    private const REASON = 'zz-secret-status-reason';
    private const ALERT_NO = '888777666';

    private Agency $b;
    private User $adminA;
    private User $adminB;
    private int $tpId;
    private int $listingId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpAgency();
        $this->b = Agency::create(['name' => 'Agency B ' . uniqid(), 'slug' => 'b-' . uniqid()]);
        $branchB = Branch::create(['agency_id' => $this->b->id, 'name' => 'B Main']);
        $this->adminA = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);
        $this->adminB = User::factory()->create(['agency_id' => $this->b->id, 'branch_id' => $branchB->id, 'role' => 'admin', 'is_admin' => true, 'is_active' => true]);

        $tp = $this->tp(['street_number' => '5', 'street_name' => self::STREET, 'suburb' => 'Uvongo', 'suburb_normalised' => 'uvongo', 'capture_kind' => 'deeds_capture', 'bond_holder' => 'Zebra Bank', 'bond_amount' => 987654]);
        $this->tpId = (int) $tp->id;
        $this->listingId = $this->listing(['address' => '5 ' . self::STREET, 'normalized_address' => '5 zebra crossing lane', 'suburb' => 'Uvongo', 'portal_ref' => 'P24-999111222'], $tp);
        TrackedPropertyOwner::create(['tracked_property_id' => $tp->id, 'name' => self::OWNER, 'id_number' => self::ID_NUMBER, 'is_primary' => true]);
        TrackedPropertyComment::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'tracked_property_id' => $tp->id, 'user_id' => $this->adminA->id, 'body' => self::COMMENT]);
        ProspectingClaim::withoutGlobalScopes()->create(['agency_id' => $this->agency->id, 'prospecting_listing_id' => $this->listingId, 'user_id' => $this->adminA->id, 'status' => 'claimed', 'claimed_at' => now(), 'is_active' => true]);
        $svc = new AgencyWorkService(new OnMarketStockService());
        $svc->setStatus($this->agency->id, $tp->id, 'not_selling', self::REASON, $this->adminA->id);
        DB::table('p24_listings')->insert([
            'agency_id' => $this->agency->id, 'p24_listing_number' => self::ALERT_NO, 'asking_price' => 1_750_000, 'suburb' => 'Uvongo', 'listing_status' => 'active',
            'first_seen_date' => now()->toDateString(), 'last_seen_date' => now()->toDateString(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function secrets(): array
    {
        return ['street' => self::STREET, 'owner' => self::OWNER, 'id number' => self::ID_NUMBER, 'comment' => self::COMMENT, 'status reason' => self::REASON, 'alert number' => self::ALERT_NO, 'bond holder' => 'Zebra Bank'];
    }

    private function assertNoSecrets(string $body, string $where): void
    {
        foreach ($this->secrets() as $what => $needle) {
            $this->assertStringNotContainsString($needle, $body, "agency A's {$what} leaked into agency B's view of {$where}");
        }
    }

    /** @return array<string, string> label => url (lists, exports, snapshots) */
    private function lists(): array
    {
        return [
            'MIC work list' => route('market-intelligence.work'),
            'MIC work list (our stock ticked)' => route('market-intelligence.work', ['include_in_stock' => 1]),
            'MIC work list searched' => route('market-intelligence.work', ['search' => 'Zebra', 'q' => 'Zebra']),
            'opportunities' => route('market-intelligence.opportunities'),
            'opportunities searched' => route('market-intelligence.opportunities', ['search' => 'Zebra', 'q' => 'Zebra']),
            'portal alerts awaiting address' => route('market-intelligence.portal-alerts'),
            'stale claim review' => route('market-intelligence.stale-review'),
            'team' => route('market-intelligence.team'),
            'market pulse' => route('market-intelligence.market-pulse'),
            'analyse' => route('market-intelligence.analyse'),
            'snapshot json' => route('market-intelligence.snapshot'),
            'suburb deep dive' => route('market-intelligence.suburb-deep-dive', ['suburb' => 'Uvongo']),
            'suburb report' => route('market-intelligence.suburb-report', ['suburb' => 'Uvongo']),
            'segment listings' => route('market-intelligence.segment.listings', ['dimension' => 'suburb', 'value' => 'Uvongo']),
            'deeds capture' => route('corex.deeds-capture.index'),
            'deeds capture searched' => route('corex.deeds-capture.index', ['q' => 'Zebra', 'search' => 'Zebra']),
        ];
    }

    /** @return array<string, string> label => url (one record, by id) */
    private function byId(): array
    {
        return [
            'MIC listing' => route('market-intelligence.show', $this->listingId),
            'MIC listing details' => route('market-intelligence.details', $this->listingId),
            'MIC buyer matches' => route('market-intelligence.buyer-matches', $this->listingId),
            'MIC match tooltip' => route('market-intelligence.match-tooltip', $this->listingId),
            'MIC thumbnail' => route('market-intelligence.thumbnail', $this->listingId),
            'opportunity detail' => route('market-intelligence.opportunities.show', $this->tpId),
            'tracked property' => route('corex.tracked-properties.show', $this->tpId),
            'tracked property comments' => route('corex.tracked-properties.comments.index', $this->tpId),
            'outreach compose from tracked property' => route('seller-outreach.entry.from-tracked-property', $this->tpId),
            'outreach compose from MIC row' => route('seller-outreach.entry.from-prospecting', $this->listingId),
            'deed poll' => route('seller-outreach.entry.deed-poll-prospecting', $this->listingId),
            'pitch ready' => route('seller-outreach.entry.pitch-ready-prospecting', $this->listingId),
        ];
    }

    public function test_agency_b_never_sees_agency_as_private_facts_on_any_list_or_export(): void
    {
        $this->actingAs($this->adminB);
        foreach ($this->lists() as $label => $url) {
            $res = $this->get($url);
            $this->assertContains($res->getStatusCode(), [200, 301, 302, 403, 404], "{$label} answered {$res->getStatusCode()}");
            $this->assertNoSecrets((string) $res->getContent(), $label);
        }
    }

    public function test_agency_b_cannot_open_agency_as_records_by_id(): void
    {
        $this->actingAs($this->adminB);
        foreach ($this->byId() as $label => $url) {
            $res = $this->get($url);
            $this->assertNotSame(200, $res->getStatusCode(), "{$label}: agency B opened agency A's record by id");
            $this->assertNoSecrets((string) $res->getContent(), $label);
        }
    }

    public function test_the_owning_agency_does_see_its_own_record_so_the_sweep_is_not_vacuous(): void
    {
        $this->actingAs($this->adminA);
        $res = $this->get(route('market-intelligence.opportunities.show', $this->tpId))->assertOk();
        $this->assertStringContainsString(self::STREET, (string) $res->getContent(), 'agency A opens its own tracked property - the same page agency B gets a 404 for');
    }

    public function test_agency_bs_writes_on_agency_as_records_are_refused(): void
    {
        $this->actingAs($this->adminB);
        $this->post(route('corex.tracked-properties.comments.store', $this->tpId), ['body' => 'intruder note'])->assertStatus(404);
        $this->assertSame(0, TrackedPropertyComment::withoutGlobalScopes()->where('body', 'intruder note')->count());
        $this->assertSame(1, TrackedPropertyAgencyWork::withoutGlobalScopes()->where('tracked_property_id', $this->tpId)->count());
        $this->assertSame('not_selling', TrackedPropertyAgencyWork::withoutGlobalScopes()->where('tracked_property_id', $this->tpId)->value('prospecting_status'));
    }
}
