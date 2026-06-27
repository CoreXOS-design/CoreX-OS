<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Property;
use App\Models\User;
use App\Services\Properties\PropertyBrochureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Printable Brochure — the always-first / always-A4 Ad Manager template.
 * Spec: .ai/specs/ad-manager.md §"Printable Brochure".
 *
 * Locks the contract that matters:
 *   1. The route streams a real A4 PDF (Content-Type application/pdf, %PDF body).
 *   2. The service builds a complete data set (rates/levy/features/parking)
 *      from a single property — and degrades cleanly with no images/agent.
 *   3. Property-access scope is enforced (a foreign-agency listing → 403),
 *      so a brochure can never leak a listing the user may not advertise.
 */
final class BrochurePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_brochure_route_streams_an_a4_pdf(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $agent    = $this->agencyUser($agency, $branch, 'agent');
        $property = $this->property($agency, $branch, $agent);

        $res = $this->actingAs($agent)->get(route('corex.properties.brochure', $property));

        $res->assertOk();
        $this->assertStringContainsString('application/pdf', strtolower((string) $res->headers->get('content-type')));
        // barryvdh ->stream() returns a regular Response with the PDF inline.
        $this->assertStringStartsWith('%PDF', $res->getContent());
    }

    public function test_dl_flag_forces_a_download_attachment(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $agent    = $this->agencyUser($agency, $branch, 'agent');
        $property = $this->property($agency, $branch, $agent);

        $res = $this->actingAs($agent)->get(route('corex.properties.brochure', ['property' => $property, 'dl' => 1]));

        $res->assertOk();
        $disposition = (string) $res->headers->get('content-disposition');
        $this->assertStringContainsString('attachment', strtolower($disposition));
        // Filename is "Brochure - {address}.pdf" (address → suburb, city, province here).
        $this->assertStringContainsString('Brochure - Glenmore, Port Edward', $disposition);
    }

    public function test_foreign_agency_listing_is_not_retrievable(): void
    {
        [$agencyA, $branchA] = $this->agencyWithBranch();
        [$agencyB, $branchB] = $this->agencyWithBranch();

        $owner    = $this->agencyUser($agencyA, $branchA, 'agent');
        $outsider = $this->agencyUser($agencyB, $branchB, 'agent');
        $property = $this->property($agencyA, $branchA, $owner);

        // BelongsToAgency (AgencyScope) filters the listing out of the outsider's
        // route-model binding entirely — it 404s, never leaking a foreign brochure.
        $this->actingAs($outsider)
            ->get(route('corex.properties.brochure', $property))
            ->assertNotFound();
    }

    public function test_service_builds_complete_data_and_degrades_without_images(): void
    {
        [$agency, $branch] = $this->agencyWithBranch();
        $agent    = $this->agencyUser($agency, $branch, 'agent');
        $property = $this->property($agency, $branch, $agent, [
            'rates_taxes'  => 705,
            'levy'         => 2336,
            'features_json' => ['Pet Friendly', 'Fibre', 'Electric Gate', 'Backup Water'],
            'spaces_json'  => ['spaces' => [['type' => 'Parking', 'count' => 1]]],
            'description'  => "First paragraph.\n\nSecond paragraph.",
        ]);

        $data = app(PropertyBrochureService::class)->data($property->fresh(), embed: false);

        $this->assertSame('R 705', $data['rates']);
        $this->assertSame('R 2,336', $data['levy']);
        $this->assertSame(1, $data['parking']);
        $this->assertContains('Pet Friendly', $data['features']['items']);
        $this->assertCount(2, $data['description']);
        // No images / null fields must not blow up the build.
        $this->assertSame([], $data['heroImages']);
        $this->assertSame($agent->name, $data['agentName']);
    }

    // ── helpers ───────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} [agencyId, defaultBranchId] */
    private function agencyWithBranch(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name'       => 'Test ' . Str::random(6),
            'slug'       => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id'  => $agencyId,
            'name'       => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$agencyId, $branchId];
    }

    private function agencyUser(int $agencyId, int $branchId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId,
            'branch_id' => $branchId,
            'role'      => $role,
        ]);
    }

    private function property(int $agencyId, int $branchId, User $agent, array $extra = []): Property
    {
        return Property::create(array_merge([
            'agency_id'     => $agencyId,
            'branch_id'     => $branchId,
            'agent_id'      => $agent->id,
            'title'         => 'Immaculate Two Bedroom, Pet Friendly, Townhouse!',
            'status'        => 'active',
            'listing_type'  => 'sale',
            'property_type' => 'townhouse',
            'price'         => 575000,
            'beds'          => 2,
            'baths'         => 1,
            'garages'       => 0,
            'suburb'        => 'Glenmore',
            'city'          => 'Port Edward',
            'province'      => 'KwaZulu-Natal',
        ], $extra));
    }
}
