<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\User;
use App\Services\PropertyMatchScoringService;
use App\Services\SellerOutreach\SellerOutreachComposerService;
use Database\Seeders\HfcConsentTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AT-145 — buyer-count canonical reconciliation for seller outreach.
 *
 * Guards the fix from the AT-144 buyer-feed truthfulness audit:
 *  1. The composer's per-property {matching_buyer_count} EQUALS the canonical
 *     count (PropertyMatchScoringService::countableActiveBuyerCountForProperty)
 *     for a known property — no divergence from the buyer engine.
 *  2. A buyer-claim template with a canonical count of 0 is BLOCKED (no_buyers
 *     send-gate) and is not sendable.
 *  3. With >=1 canonical match the token renders the REAL number and no raw
 *     buyer-claim token leaks into the body.
 *  4. Address-only mode makes NO per-property claim (token collapses, no gate).
 *  5. The count honours the AT-71 ->countable() gate — an empty-wishlist active
 *     buyer is not counted.
 */
final class BuyerCountCanonicalReconciliationTest extends TestCase
{
    use RefreshDatabase;

    /** 1 + 3 — composer count == canonical count, token renders the real number. */
    public function test_composer_matching_count_equals_canonical_and_renders_real_number(): void
    {
        [$agencyId, $agent] = $this->seedAgencyAndAgent();
        $seller = $this->seedSeller($agencyId);
        $propertyId = $this->seedProperty($agencyId, $agent->id, price: 1_200_000, beds: 3, type: 'house', status: 'active');

        // One COUNTABLE, ACTIVE (new-state) buyer whose wishlist matches the
        // property on price+type+beds (score 100).
        $this->seedMatchingBuyer($agencyId, $agent->id, priceMin: 1_000_000, priceMax: 1_400_000, beds: 3, type: 'house');

        $property = \App\Models\Property::withoutGlobalScopes()->find($propertyId);
        $canonical = app(PropertyMatchScoringService::class)->countableActiveBuyerCountForProperty($property);
        $this->assertGreaterThanOrEqual(1, $canonical, 'setup should yield at least one canonical matching buyer');

        $ctx = $this->compose($agencyId, $seller, $property, $agent, $this->body('Buyer Demand Marketing'));

        // The anti-divergence guarantee: the composer's per-property count IS the
        // canonical engine's count (carried raw in __matching_buyer_count).
        $this->assertSame($canonical, $ctx->mergeFields['__matching_buyer_count']);
        // With >=1 the display value is the real number.
        $this->assertSame((string) $canonical, (string) $ctx->mergeFields['matching_buyer_count']);

        // Token renders the real number; no raw buyer-claim token remains.
        $this->assertStringContainsString("{$canonical} active buyer(s)", $ctx->renderedBody);
        $this->assertStringNotContainsString('{matching_buyer_count}', $ctx->renderedBody);
        $this->assertStringNotContainsString('{?matching_buyer_count}', $ctx->renderedBody);

        // Not blocked by no_buyers when a buyer really matches.
        $this->assertArrayNotHasKey('no_buyers', $ctx->validationIssues);
    }

    /** 2 — zero canonical matches → no_buyers gate blocks, claim collapses. */
    public function test_zero_canonical_matches_blocks_buyer_claim_template(): void
    {
        [$agencyId, $agent] = $this->seedAgencyAndAgent();
        $seller = $this->seedSeller($agencyId);
        // No matching buyer seeded → canonical 0.
        $propertyId = $this->seedProperty($agencyId, $agent->id, price: 9_500_000, beds: 5, type: 'house', status: 'active');
        $property = \App\Models\Property::withoutGlobalScopes()->find($propertyId);

        $this->assertSame(0, app(PropertyMatchScoringService::class)->countableActiveBuyerCountForProperty($property));

        $ctx = $this->compose($agencyId, $seller, $property, $agent, $this->body('Buyer Demand Marketing'));

        // Raw count is a genuine 0 (property mode); display collapses to '' so no
        // false claim renders; the gate blocks the send.
        $this->assertSame(0, $ctx->mergeFields['__matching_buyer_count']);
        $this->assertSame('', (string) $ctx->mergeFields['matching_buyer_count']);
        $this->assertArrayHasKey('no_buyers', $ctx->validationIssues);
        $this->assertFalse($ctx->isSendable(), 'a buyer-claim template must not be sendable at zero matches');
        // The false claim never renders — the optional segment collapsed.
        $this->assertStringNotContainsString('active buyer(s)', $ctx->renderedBody);
    }

    /** 4 — address-only mode makes no per-property claim and does not gate. */
    public function test_address_only_mode_makes_no_buyer_claim(): void
    {
        [$agencyId, $agent] = $this->seedAgencyAndAgent();
        $seller = $this->seedSeller($agencyId, withStructuredAddress: true);

        $ctx = $this->compose($agencyId, $seller, null, $agent, $this->body('Buyer Demand Marketing'));

        $this->assertSame('', (string) $ctx->mergeFields['matching_buyer_count']);
        $this->assertArrayNotHasKey('no_buyers', $ctx->validationIssues);
        $this->assertStringNotContainsString('active buyer(s)', $ctx->renderedBody);
    }

    /** 5 — the count honours ->countable(): an empty-wishlist active buyer is excluded. */
    public function test_empty_wishlist_active_buyer_is_not_counted(): void
    {
        [$agencyId, $agent] = $this->seedAgencyAndAgent();
        $propertyId = $this->seedProperty($agencyId, $agent->id, price: 1_200_000, beds: 3, type: 'house', status: 'active');
        $this->seedMatchingBuyer($agencyId, $agent->id, priceMin: 1_000_000, priceMax: 1_400_000, beds: 3, type: 'house');
        $property = \App\Models\Property::withoutGlobalScopes()->find($propertyId);

        $before = app(PropertyMatchScoringService::class)->countableActiveBuyerCountForProperty($property);

        // Active + new-state buyer, but EMPTY wishlist (no criteria) → uncountable.
        $emptyBuyer = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'Empty', 'last_name' => 'Wishlist',
            'phone' => '+2782' . random_int(1000000, 9999999),
            'is_buyer' => 1, 'buyer_state' => 'new',
        ]);
        DB::table('contact_matches')->insert([
            'agency_id' => $agencyId, 'contact_id' => $emptyBuyer->id,
            'status' => 'active', 'listing_type' => 'sale',
            'created_by_user_id' => $agent->id, 'is_primary' => 1,
            'share_token' => Str::random(40), 'suburbs' => '[]', 'p24_suburb_ids' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $after = app(PropertyMatchScoringService::class)->countableActiveBuyerCountForProperty($property);
        $this->assertSame($before, $after, 'an empty (uncountable) wishlist must not increment the canonical count');
    }

    /** AT-144 — the matched-buyer BASIS is frozen into facts_snapshot (auditability). */
    public function test_matched_buyer_basis_is_snapshotted_with_the_exact_buyers(): void
    {
        [$agencyId, $agent] = $this->seedAgencyAndAgent();
        $seller = $this->seedSeller($agencyId);
        $propertyId = $this->seedProperty($agencyId, $agent->id, price: 1_200_000, beds: 3, type: 'house', status: 'active');
        $this->seedMatchingBuyer($agencyId, $agent->id, priceMin: 1_000_000, priceMax: 1_400_000, beds: 3, type: 'house');
        $property = \App\Models\Property::withoutGlobalScopes()->find($propertyId);

        $svc = app(PropertyMatchScoringService::class);
        $canonical = $svc->countableActiveBuyerCountForProperty($property);
        $basis = $svc->countableActiveBuyerBasisForProperty($property);

        // Basis accessor == count accessor, and exposes the EXACT buyers.
        $this->assertSame($canonical, $basis['count']);
        $this->assertCount($canonical, $basis['contact_ids']);
        $this->assertGreaterThanOrEqual(1, $canonical);
        $this->assertArrayHasKey('score', $basis['buyers'][0]);
        $this->assertArrayHasKey('tier', $basis['buyers'][0]);
        $buyerId = (int) Contact::where('agency_id', $agencyId)
            ->where('first_name', 'Active')->where('last_name', 'Buyer')->value('id');
        $this->assertContains($buyerId, $basis['contact_ids']);

        // The SEND snapshot freezes it — a seller challenge is answerable with facts.
        $ctx = $this->compose($agencyId, $seller, $property, $agent, $this->body('Active Buyer Match — Your Property (DISABLED)'));
        $mb = $ctx->factsSnapshot['matched_buyer_basis'] ?? null;
        $this->assertIsArray($mb);
        $this->assertSame($canonical, $mb['count']);
        $this->assertSame($basis['contact_ids'], $mb['contact_ids']);
        $this->assertNotEmpty($mb['engine']);
        $this->assertNotEmpty($mb['gate']);
        $this->assertSame($propertyId, $mb['property_id']);
    }

    /** AT-144 — zero matches → snapshot basis is an honest empty record + gate fires. */
    public function test_zero_matches_snapshot_basis_is_empty(): void
    {
        [$agencyId, $agent] = $this->seedAgencyAndAgent();
        $seller = $this->seedSeller($agencyId);
        $propertyId = $this->seedProperty($agencyId, $agent->id, price: 9_500_000, beds: 5, type: 'house', status: 'active');
        $property = \App\Models\Property::withoutGlobalScopes()->find($propertyId);

        $ctx = $this->compose($agencyId, $seller, $property, $agent, $this->body('Active Buyer Match — Your Property (DISABLED)'));
        $mb = $ctx->factsSnapshot['matched_buyer_basis'] ?? null;
        $this->assertSame(0, $mb['count']);
        $this->assertSame([], $mb['contact_ids']);
        $this->assertArrayHasKey('no_buyers', $ctx->validationIssues);
    }

    /** AT-144 — the new buyer-demand template ships DISABLED, per-property token only. */
    public function test_active_buyer_match_template_ships_disabled_and_token_based(): void
    {
        $ref = new ReflectionMethod(HfcConsentTemplatesSeeder::class, 'templates');
        $ref->setAccessible(true);
        $tpl = null;
        foreach ($ref->invoke(new HfcConsentTemplatesSeeder()) as $t) {
            if ($t['name'] === 'Active Buyer Match — Your Property (DISABLED)') {
                $tpl = $t;
                break;
            }
        }
        $this->assertNotNull($tpl, 'new AT-144 template must exist in the seeder');
        $this->assertFalse($tpl['is_active'], 'ships DISABLED pending Johan wording pick');
        $this->assertStringContainsString('{?matching_buyer_count}', $tpl['body']);
        $this->assertStringContainsString('{/matching_buyer_count}', $tpl['body']);
        // per-property claim ONLY — never the looser town-level {buyer_count}.
        $this->assertStringNotContainsString('{buyer_count}', $tpl['body']);
    }

    /** AT-144 — a property under a SOLE/EXCLUSIVE mandate blocks the buyer claim (open stock only). */
    public function test_sole_mandate_property_blocks_the_buyer_claim(): void
    {
        [$agencyId, $agent] = $this->seedAgencyAndAgent();
        $seller = $this->seedSeller($agencyId);
        $propertyId = $this->seedProperty($agencyId, $agent->id, price: 1_200_000, beds: 3, type: 'house', status: 'active');
        $this->seedMatchingBuyer($agencyId, $agent->id, priceMin: 1_000_000, priceMax: 1_400_000, beds: 3, type: 'house');
        $property = \App\Models\Property::withoutGlobalScopes()->find($propertyId);
        $body = $this->body('Active Buyer Match — Your Property (DISABLED)');

        // Open stock → allowed (no mandate_conflict).
        $property->mandate_type = 'Open';
        $property->save();
        $this->assertArrayNotHasKey('mandate_conflict', $this->compose($agencyId, $seller, $property, $agent, $body)->validationIssues);

        // Sole mandate → blocked, not sendable.
        $property->mandate_type = 'sole';
        $property->save();
        $ctx = $this->compose($agencyId, $seller, $property, $agent, $body);
        $this->assertArrayHasKey('mandate_conflict', $ctx->validationIssues);
        $this->assertFalse($ctx->isSendable(), 'a buyer-claim send must be blocked on a sole/exclusive mandate');
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function compose(int $agencyId, Contact $seller, ?\App\Models\Property $property, User $agent, string $body)
    {
        return app(SellerOutreachComposerService::class)
            ->composeContext($agencyId, $seller, $property, 'whatsapp', null, $agent, $body, null);
    }

    /** The ACTUAL shipped seeder body for a template name (tests real copy). */
    private function body(string $name): string
    {
        $ref = new ReflectionMethod(HfcConsentTemplatesSeeder::class, 'templates');
        $ref->setAccessible(true);
        foreach ($ref->invoke(new HfcConsentTemplatesSeeder()) as $t) {
            if ($t['name'] === $name) {
                return $t['body'];
            }
        }
        $this->fail("Template '{$name}' not found in seeder");
    }

    /** @return array{0:int,1:User} */
    private function seedAgencyAndAgent(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $agent = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $agent];
    }

    private function seedSeller(int $agencyId, bool $withStructuredAddress = false): Contact
    {
        return Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'Thabo', 'last_name' => 'Nkosi',
            'phone' => '+27821234567',
            'email' => 'seller-' . Str::random(6) . '@example.test',
            'street_number' => $withStructuredAddress ? '18' : null,
            'street_name' => $withStructuredAddress ? 'Golf Course Road' : null,
            'suburb' => $withStructuredAddress ? 'Uvongo' : null,
        ]);
    }

    private function seedProperty(int $agencyId, int $userId, int $price, int $beds, string $type, string $status): int
    {
        return (int) DB::table('properties')->insertGetId([
            'external_id' => 'TEST-' . Str::random(8),
            'title' => 'Test property',
            'address' => '12 Test Road',
            'street_number' => '12', 'street_name' => 'Test Road',
            'suburb' => 'Uvongo',
            'price' => $price, 'beds' => $beds,
            'property_type' => $type, 'listing_type' => 'sale',
            'status' => $status, 'is_demo' => false,
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'agent_id' => $userId,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedMatchingBuyer(int $agencyId, int $userId, int $priceMin, int $priceMax, int $beds, string $type): void
    {
        $buyer = Contact::create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'first_name' => 'Active', 'last_name' => 'Buyer',
            'phone' => '+2782' . random_int(1000000, 9999999),
            'is_buyer' => 1, 'buyer_state' => 'new',
        ]);
        DB::table('contact_matches')->insert([
            'agency_id' => $agencyId, 'contact_id' => $buyer->id,
            'status' => 'active', 'listing_type' => 'sale',
            'property_type' => $type,
            'price_min' => $priceMin, 'price_max' => $priceMax, 'beds_min' => $beds,
            'created_by_user_id' => $userId, 'is_primary' => 1,
            'share_token' => Str::random(40), 'suburbs' => '[]', 'p24_suburb_ids' => '[]',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
