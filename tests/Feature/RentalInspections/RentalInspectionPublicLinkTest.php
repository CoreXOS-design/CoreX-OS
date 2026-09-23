<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Johan, 2026-09-23, approved — "signed, expiring, read-only... it must
 * work for someone with NO CoreX login... revocable... it shows the
 * inspection and its photos and NOTHING else." Covers
 * RentalInspection::generatePublicLink()/revokePublicLink()/
 * findByPublicToken(), and RentalInspectionPublicController's scoping.
 */
final class RentalInspectionPublicLinkTest extends TestCase
{
    use RefreshDatabase;

    private function agencySetup(string $suffix): array
    {
        $agency = Agency::create(['name' => "Public Link Test {$suffix}", 'slug' => 'public-link-test-' . $suffix . '-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => "Public Link Property {$suffix}", 'status' => 'active', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'address' => "{$suffix} Test Road",
        ]);
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonths(3),
            'created_by_user_id' => $agent->id,
        ]);
        $inspection = RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $agent->id,
        ]);
        $item = RentalInspectionItem::create([
            'agency_id' => $agency->id, 'property_id' => $property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => "Ceiling {$suffix}", 'created_by_user_id' => $agent->id,
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id, 'observed_by_user_id' => $agent->id,
            'condition' => 'good', 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);

        return compact('agency', 'agent', 'property', 'lease', 'inspection', 'item');
    }

    public function test_generate_and_find_by_public_token_round_trips(): void
    {
        $inspection = $this->agencySetup('a')['inspection'];

        $token = $inspection->generatePublicLink();

        self::assertNotEmpty($token);
        self::assertTrue($inspection->publicLinkIsValid());
        $found = RentalInspection::findByPublicToken($token);
        self::assertNotNull($found);
        self::assertTrue($found->is($inspection));
    }

    public function test_an_expired_token_never_resolves(): void
    {
        $inspection = $this->agencySetup('a')['inspection'];
        $inspection->generatePublicLink(-1); // expiry in the past — the same shape a stale link takes once its window closes.

        self::assertNull(RentalInspection::findByPublicToken($inspection->public_token));
    }

    public function test_regenerating_invalidates_the_previous_token(): void
    {
        $inspection = $this->agencySetup('a')['inspection'];
        $oldToken = $inspection->generatePublicLink();

        $newToken = $inspection->generatePublicLink();

        self::assertNotSame($oldToken, $newToken);
        self::assertNull(RentalInspection::findByPublicToken($oldToken));
        self::assertNotNull(RentalInspection::findByPublicToken($newToken));
    }

    public function test_revoking_clears_the_token_entirely(): void
    {
        $inspection = $this->agencySetup('a')['inspection'];
        $token = $inspection->generatePublicLink();

        $inspection->revokePublicLink();

        self::assertNull(RentalInspection::findByPublicToken($token));
        self::assertFalse($inspection->fresh()->publicLinkIsValid());
    }

    public function test_default_expiry_is_ninety_days(): void
    {
        $inspection = $this->agencySetup('a')['inspection'];

        $inspection->generatePublicLink();

        self::assertEqualsWithDelta(
            now()->addDays(90)->timestamp,
            $inspection->public_token_expires_at->timestamp,
            5, // seconds of test-runtime slack
        );
    }

    public function test_public_show_renders_only_this_inspections_own_data(): void
    {
        $a = $this->agencySetup('a');
        $b = $this->agencySetup('b');
        $tokenA = $a['inspection']->generatePublicLink();

        $resp = $this->get(route('rental-inspections.public.show', $tokenA));

        $resp->assertOk();
        $resp->assertSee('Ceiling a');
        $resp->assertSee($a['property']->buildDisplayAddress());
        // Cross-agency leak check — agency B's own item label/address must
        // never appear on agency A's public page.
        $resp->assertDontSee('Ceiling b');
        $resp->assertDontSee($b['property']->buildDisplayAddress());
    }

    public function test_public_show_with_an_invalid_token_renders_the_unavailable_page_not_the_real_data(): void
    {
        $a = $this->agencySetup('a');
        $a['inspection']->generatePublicLink();

        $resp = $this->get(route('rental-inspections.public.show', 'not-a-real-token'));

        $resp->assertOk();
        $resp->assertDontSee('Ceiling a');
        $resp->assertDontSee($a['property']->buildDisplayAddress());
    }

    public function test_public_show_with_a_revoked_token_renders_the_unavailable_page(): void
    {
        $a = $this->agencySetup('a');
        $token = $a['inspection']->generatePublicLink();
        $a['inspection']->revokePublicLink();

        $resp = $this->get(route('rental-inspections.public.show', $token));

        $resp->assertOk();
        $resp->assertDontSee('Ceiling a');
    }
}
