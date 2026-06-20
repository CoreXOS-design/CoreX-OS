<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P24-2 — is-on-portal returns a bare JSON boolean. The client wraps it as
 * ['data' => true]; syncActivationStatus must read the scalar, not array-access
 * it (which yielded null and left listings stuck 'submitted' forever).
 *
 * Audit: .ai/audits/syndication-bug-sweep-2026-06-20.md (P24-2)
 */
class Property24ActivationReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_bare_boolean_is_on_portal_flips_submitted_to_active(): void
    {
        // is-on-portal → bare JSON `true`.
        Http::fake(['*is-on-portal*' => Http::response('true', 200, ['Content-Type' => 'application/json'])]);

        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'L', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1000000,
        ]);
        $p->forceFill([
            'p24_syndication_enabled' => true,
            'p24_syndication_status'  => 'submitted',
            'p24_ref'                 => '99887766',
        ])->save();

        $result = app(Property24SyndicationService::class)->syncActivationStatus($p);

        $this->assertTrue($result['success']);
        $this->assertSame('active', $p->fresh()->p24_syndication_status);
    }
}
