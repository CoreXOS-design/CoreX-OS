<?php

declare(strict_types=1);

namespace Tests\Feature\Dr2;

use App\Models\Contact;
use App\Models\Deal;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-243 — the PURCHASER is derived, never flagged.
 *
 * A property lists every buyer who ever made an offer. Exactly one of them actually
 * bought — the buyer of the deal in the committed lane (Granted / Registered), of which
 * Wave 2 guarantees at most one per property. Because it is derived from deal state, the
 * whole lifecycle follows for free and there is no flag to drift:
 *
 *   grant        → purchaser appears
 *   fall-through → purchaser disappears (no committed deal → nobody bought)
 *   resale       → the NEW granted deal's buyer is the purchaser
 *
 * Input paths proven: no deals · offers but no grant · grant with joint buyers · other
 * buyers stay listed · fall-through · re-grant of a sibling · resale · deal with no
 * captured parties (legacy) claims nobody rather than guessing.
 */
final class Dr2PurchaserDerivationTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $agent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Coastal ' . Str::random(6), 'slug' => 'coastal-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Margate',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->agent = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]);
    }

    public function test_a_property_with_no_deals_claims_no_purchaser(): void
    {
        $property = $this->property();

        $this->assertNull($property->purchasingDeal());
        $this->assertSame([], $property->purchaserContactIds());
    }

    public function test_offers_alone_do_not_make_a_purchaser(): void
    {
        $property = $this->property();
        $thandi = $this->contact('Thandi', 'Mkhize');
        $pieter = $this->contact('Pieter', 'van der Merwe');

        // Two live offers, nothing granted. An offer is not a purchase.
        $this->deal($property, 'P', [$thandi]);
        $this->deal($property, 'P', [$pieter]);

        $this->assertSame([], $property->fresh()->purchaserContactIds());
    }

    public function test_the_granted_deals_buyer_is_the_purchaser_and_the_others_remain_listed(): void
    {
        $property = $this->property();
        $thandi = $this->contact('Thandi', 'Mkhize');
        $pieter = $this->contact('Pieter', 'van der Merwe');
        $ayesha = $this->contact('Ayesha', 'Patel');

        // Three offers on one property — all three are linked to the property as buyers.
        $losing1 = $this->deal($property, 'P', [$thandi]);
        $winning = $this->deal($property, 'G', [$pieter]);
        $losing2 = $this->deal($property, 'P', [$ayesha]);
        $this->linkToProperty($property, [$thandi, $pieter, $ayesha], 'buyer');

        $property = $property->fresh();

        // Exactly the granted deal's buyer — not "all the property's buyers".
        $this->assertSame([$pieter->id], $property->purchaserContactIds());
        $this->assertSame($winning->id, $property->purchasingDeal()->id);

        // The other buyers are STILL linked to the property (a fallen-through grant makes
        // the next buyer gold — they must not be pruned).
        $linkedIds = $property->contacts->pluck('id')->all();
        $this->assertContains($thandi->id, $linkedIds);
        $this->assertContains($ayesha->id, $linkedIds);
    }

    public function test_joint_buyers_on_the_granted_deal_are_both_purchasers(): void
    {
        $property = $this->property();
        $a = $this->contact('Sipho', 'Ndlovu');
        $b = $this->contact('Lerato', 'Ndlovu');

        $this->deal($property, 'G', [$a, $b]);

        $ids = $property->fresh()->purchaserContactIds();
        sort($ids);
        $this->assertSame([$a->id, $b->id], $ids, 'joint buyers both bought');
    }

    public function test_a_fall_through_reverts_the_purchaser_by_itself(): void
    {
        $property = $this->property();
        $pieter = $this->contact('Pieter', 'van der Merwe');
        $granted = $this->deal($property, 'G', [$pieter]);

        $this->assertSame([$pieter->id], $property->fresh()->purchaserContactIds());

        // The deal falls through. Nothing about the purchaser is "unset" — the derivation
        // simply stops finding a committed deal.
        $granted->accepted_status = 'D';
        $granted->save();

        $this->assertSame([], $property->fresh()->purchaserContactIds(), 'a declined deal bought nothing');
    }

    public function test_re_granting_a_sibling_after_a_fall_through_moves_the_purchaser(): void
    {
        $property = $this->property();
        $pieter = $this->contact('Pieter', 'van der Merwe');
        $thandi = $this->contact('Thandi', 'Mkhize');

        $first  = $this->deal($property, 'G', [$pieter]);
        $backup = $this->deal($property, 'P', [$thandi]);

        $this->assertSame([$pieter->id], $property->fresh()->purchaserContactIds());

        // The granted deal collapses; the backup buyer is re-granted (DR2's documented
        // re-grant path — a declined deal stays re-grantable while nothing else is committed).
        $first->accepted_status = 'D';
        $first->save();
        $backup->accepted_status = 'G';
        $backup->save();

        $this->assertSame([$thandi->id], $property->fresh()->purchaserContactIds(),
            'the purchaser follows the deal register, with nothing to update by hand');
    }

    public function test_a_registered_deal_still_names_its_purchaser(): void
    {
        $property = $this->property();
        $buyer = $this->contact('Nomsa', 'Dlamini');
        $this->deal($property, 'R', [$buyer]);

        $this->assertSame([$buyer->id], $property->fresh()->purchaserContactIds(),
            'transfer registered — they most certainly bought it');
    }

    public function test_resale_derives_the_new_purchaser(): void
    {
        $property = $this->property();
        $first = $this->contact('Nomsa', 'Dlamini');
        $second = $this->contact('Johan', 'Botha');

        // Bought, registered, and years later sold again on the same record.
        $sale1 = $this->deal($property, 'R', [$first]);
        $this->assertSame([$first->id], $property->fresh()->purchaserContactIds());

        // The old sale closes out and a new deal is granted on the property.
        $sale1->accepted_status = 'D';
        $sale1->saveQuietly();
        $this->deal($property, 'G', [$second]);

        $this->assertSame([$second->id], $property->fresh()->purchaserContactIds(),
            'the current purchaser is the current committed deal, not the historic one');
    }

    /** The legacy path: a granted deal whose parties were never captured claims nobody. */
    public function test_a_granted_deal_with_no_captured_parties_claims_no_purchaser(): void
    {
        $property = $this->property();
        $someone = $this->contact('Thandi', 'Mkhize');
        $this->linkToProperty($property, [$someone], 'buyer');

        // Granted, but the deal itself names no parties (pre-AT-243 capture).
        $this->deal($property, 'G', []);

        // We do NOT promote "the property's only buyer" to purchaser on a hunch — an
        // unknown purchaser reads as unknown rather than putting the wrong name on a sale.
        $this->assertSame([], $property->fresh()->purchaserContactIds());
    }

    // ── helpers ──────────────────────────────────────────────────────────

    private function property(): Property
    {
        return Property::create([
            'agency_id'     => $this->agencyId,
            'agent_id'      => $this->agent->id,
            'title'         => '14 Marine Drive, Shelly Beach',
            'status'        => 'active',
            'property_type' => 'house',
            'price'         => 2_150_000,
        ]);
    }

    private function contact(string $first, string $last): Contact
    {
        return Contact::create([
            'agency_id'  => $this->agencyId,
            'first_name' => $first,
            'last_name'  => $last,
            'is_buyer'   => true,
        ]);
    }

    /** @param array<int,Contact> $buyers */
    private function deal(Property $property, string $status, array $buyers): Deal
    {
        $deal = Deal::create([
            'agency_id'        => $this->agencyId,
            'branch_id'        => $this->agencyId,
            'property_id'      => $property->id,
            'period'           => '2026-03',
            'deal_date'        => '2026-03-01',
            'property_value'   => 2_150_000,
            'total_commission' => 107_500,
            'accepted_status'  => $status,
            'buyer_name'       => collect($buyers)->map(fn ($b) => $b->first_name . ' ' . $b->last_name)->implode(' & '),
        ]);

        foreach ($buyers as $b) {
            DB::table('deal_contacts')->insert([
                'deal_id' => $deal->id, 'contact_id' => $b->id, 'role' => 'buyer',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        return $deal;
    }

    /** @param array<int,Contact> $contacts */
    private function linkToProperty(Property $property, array $contacts, string $role): void
    {
        foreach ($contacts as $c) {
            $property->contacts()->syncWithoutDetaching([$c->id => ['role' => $role]]);
        }
    }
}
