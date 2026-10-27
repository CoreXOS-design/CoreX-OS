<?php

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\RolePermission;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * .ai/specs/other-agency-stock.md 5c point 4 (Johan, 27 Oct 2026): Other Agency Stock exists to be SHARED with a buyer, so its own
 * property page draws Share (same chooser as a live listing) and does not tell the agent "Marketing blocked". Syndication and the
 * other blocked actions stay shut.
 */
class OtherAgencyStockShareTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_other_agency_stock_page_draws_share_and_no_marketing_blocked_message(): void
    {
        PermissionService::clearCache();
        $agency = Agency::create(['name' => 'Coastal', 'slug' => 'coastal-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);
        RolePermission::create(['role' => 'agent', 'permission_key' => 'properties.view', 'scope' => 'all', 'agency_id' => $agency->id]);
        foreach (['access_properties', 'create_presentations', 'access_presentations', 'outreach.compose', 'properties.share', 'compliance.whistleblow.create'] as $key) {
            RolePermission::create(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $agency->id]);
        }

        $oas = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id, 'external_id' => (string) Str::uuid(),
            'title' => 'Imported listing', 'suburb' => 'Uvongo', 'property_type' => 'house', 'status' => Property::STATUS_OTHER_AGENCY_STOCK,
            'price' => 1500000, 'description' => 'Sea views', 'beds' => 3, 'baths' => 2, 'garages' => 1, 'city' => 'Margate', 'province' => 'KwaZulu-Natal',
        ]);

        $html = $this->actingAs($agent)->get(route('corex.properties.show', $oas))->assertOk()->getContent();

        // Share is there, with the same chooser as a live listing, pointing at this property's public preview.
        $this->assertMatchesRegularExpression('/<button\b[^>]*data-oas-action="share_listing"/', $html);
        $this->assertStringContainsString('corexPropertyShareChooser', $html);
        foreach (['Copy link', 'WhatsApp', 'Email', 'My details', "Listing agent's details"] as $channel) {
            $this->assertStringContainsString($channel, $html);
        }
        $this->assertStringContainsString(route('corex.properties.preview', $oas), $html);

        // No "Marketing blocked" anywhere, and Live Preview is a live control.
        $this->assertStringNotContainsString('Marketing blocked', $html);
        $this->assertDoesNotMatchRegularExpression('/<button\b[^>]*data-oas-action="live_preview"[^>]*\bdisabled\b/s', $html);

        // The header badge beside the Compliance Status button is not BLOCKED (the button itself stays), while an ordinary listing
        // that cannot be marketed still reads BLOCKED.
        $this->assertStringContainsString('Compliance Status', $html);
        $this->assertMatchesRegularExpression('/Compliance Status\s*<span[^>]*>\s*N\/A\s*<\/span>/', $html);
        $this->assertDoesNotMatchRegularExpression('/Compliance Status\s*<span[^>]*>\s*BLOCKED\s*<\/span>/', $html);
        $normal = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id, 'external_id' => (string) Str::uuid(),
            'title' => 'Ordinary listing', 'suburb' => 'Uvongo', 'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
        ]);
        $normalHtml = $this->actingAs($agent)->get(route('corex.properties.show', $normal))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/Compliance Status\s*<span[^>]*>\s*BLOCKED\s*<\/span>/', $normalHtml);

        // Syndication and the rest stay shut.
        foreach (['syndication', 'ad_builder', 'market_property', 'pitch_seller', 'duplicate'] as $key) {
            $this->assertMatchesRegularExpression('/<button\b[^>]*data-oas-action="' . $key . '"[^>]*\bdisabled\b|<button\b[^>]*\bdisabled\b[^>]*data-oas-action="' . $key . '"/s', $html, "{$key} must stay disabled");
        }
        preg_match('/<button\b[^>]*data-oas-action="syndication"[^>]*>/s', $html, $syn);
        $this->assertStringNotContainsString('synOpen=true', $syn[0] ?? 'x', 'the Syndication button must not open the panel');
    }
}
