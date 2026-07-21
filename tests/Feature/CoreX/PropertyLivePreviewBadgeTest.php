<?php

declare(strict_types=1);

namespace Tests\Feature\CoreX;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The over-gallery status badge on the public live-preview page.
 *
 * Regression: the badge was mapped from `status` alone, so 'active' was
 * hard-coded to "For Sale" and every on-market RENTAL was mislabelled. The
 * label now comes from Property::statusBadge(), the canonical derivation.
 */
final class PropertyLivePreviewBadgeTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;

    private Branch $branch;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();

        $this->agency = Agency::create([
            'name' => 'Badge Test Agency',
            'slug' => 'badge-test-'.uniqid(),
        ]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    /**
     * A marketable property — the live-preview route 404s unless the listing is
     * marketable, and a compliance snapshot satisfies that gate.
     *
     * @param  array<string, mixed>  $attrs
     */
    private function makeProperty(array $attrs = []): Property
    {
        return Property::create(array_merge([
            'title' => 'Penthouse with sea view',
            'agency_id' => $this->agency->id,
            'agent_id' => $this->user->id,
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'listing_type' => 'sale',
            'compliance_snapshot_at' => now(),
        ], $attrs));
    }

    private function preview(Property $property, string $query = ''): \Illuminate\Testing\TestResponse
    {
        return $this->get("/corex/properties/{$property->id}/preview/a-slug".$query);
    }

    public function test_active_rental_is_badged_to_let_not_for_sale(): void
    {
        $property = $this->makeProperty(['listing_type' => 'rental']);

        $response = $this->preview($property);

        $response->assertOk();
        $response->assertSee('To Let');
        $response->assertDontSee('For Sale');
    }

    public function test_legacy_to_let_listing_type_is_badged_to_let(): void
    {
        $property = $this->makeProperty(['listing_type' => 'to_let']);

        $response = $this->preview($property);

        $response->assertOk();
        $response->assertSee('To Let');
        $response->assertDontSee('For Sale');
    }

    public function test_active_sale_is_still_badged_for_sale(): void
    {
        $property = $this->makeProperty(['listing_type' => 'sale']);

        $response = $this->preview($property);

        $response->assertOk();
        $response->assertSee('For Sale');
        $response->assertDontSee('To Let');
    }

    /**
     * The badge is a property of the listing, not of the viewer — the `agent`
     * query param only chooses whose contact details are shown, so it must not
     * change the label. This is the exact symptom that surfaced the bug: the
     * link read "To Let" with one query string and "For Sale" without it.
     */
    public function test_badge_does_not_depend_on_the_agent_query_param(): void
    {
        $property = $this->makeProperty(['listing_type' => 'rental']);

        foreach (['', '?agent=me', '?agent=none', '?agent=listing'] as $query) {
            $response = $this->preview($property, $query);

            $response->assertOk();
            $response->assertSee('To Let');
            $response->assertDontSee('For Sale');
        }
    }

    public function test_sold_rental_is_badged_sold(): void
    {
        $property = $this->makeProperty(['listing_type' => 'rental', 'status' => 'sold']);

        $response = $this->preview($property);

        $response->assertOk();
        $response->assertSee('Sold');
        $response->assertDontSee('To Let');
    }
}
