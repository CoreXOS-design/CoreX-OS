<?php

declare(strict_types=1);

namespace Tests\Feature\Intelligence;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PropertyIntelligenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Intelligence-tab "Days on Market" counts from the real listing date only
 * (`listed_date`), never from the import / activation / created date.
 * Regression: "402 Glyndale, imported in June, showed ~104 days" — P24-imported stock has
 * no listing date, and the tile fell back to the import day. Spec: seller-live-link.md §2.
 */
final class DaysOnMarketStartDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_property_with_no_listed_date_has_no_days_on_market(): void
    {
        $property = $this->makeProperty([
            'listed_date'      => null,
            'p24_activated_at' => now()->subDays(104),
            'pp_activated_at'  => now()->subDays(100),
            'published_at'     => now()->subDays(104),
            'created_at'       => now()->subDays(104),
        ]);

        $this->assertNull(app(PropertyIntelligenceService::class)->getComplianceStatus($property->id)['days_on_market']);
    }

    public function test_days_on_market_counts_from_listed_date_not_the_later_import_date(): void
    {
        $property = $this->makeProperty([
            'listed_date'      => now()->subDays(20)->toDateString(),
            'p24_activated_at' => now()->subDays(104),
            'created_at'       => now()->subDays(104),
        ]);

        $this->assertSame(20, app(PropertyIntelligenceService::class)->getComplianceStatus($property->id)['days_on_market']);
    }

    public function test_tile_shows_a_dash_when_the_listing_date_is_unknown(): void
    {
        $property = $this->makeProperty(['listed_date' => null, 'p24_activated_at' => now()->subDays(104)]);
        $agent = User::find($property->agent_id);

        $html = $this->actingAs($agent)->get(route('corex.properties.show', $property))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/>\s*—\s*<\/div>\s*<div[^>]*>\s*Days on Market/u', $html);
    }

    private function makeProperty(array $extra = []): Property
    {
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);

        $created = $extra['created_at'] ?? null;
        unset($extra['created_at']);
        $property = Property::withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Unit 402 Glyndale Sands', 'suburb' => 'Uvongo',
            'property_type' => 'apartment', 'listing_type' => 'sale', 'status' => 'active', 'price' => 1_000_000,
        ], $extra));
        if ($created) {
            Property::withoutGlobalScopes()->where('id', $property->id)->update(['created_at' => $created]);
        }

        return $property->fresh();
    }
}
