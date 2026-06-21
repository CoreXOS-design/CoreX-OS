<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Regression: PropertyObserver::saved() must use getChanges(), not getDirty(),
 * to detect a status change on a P24-syndicated property. saved()'s first call
 * (onPropertyUpdated → updateQuietly) runs a nested save that syncs original, so
 * getDirty() is empty by the time the P24 block runs — which previously made all
 * P24 status/field auto-sync dead code (sold/withdrawn never reached P24).
 *
 * Audit: .ai/audits/mandate-expiry-desyndication-2026-06-20.md
 */
class Property24ObserverStatusSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_marking_a_syndicated_property_sold_pushes_status_to_p24(): void
    {
        Queue::fake();                 // isolate from MatchPropertyJob etc.
        Http::fake(['*' => Http::response(['success' => true], 200)]);

        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $user = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'super_admin']);

        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $user->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
        ]);
        $p->forceFill([
            'p24_syndication_enabled' => true,
            'p24_syndication_status'  => 'active',
            'p24_ref'                 => '99887766',
        ])->save();

        // Off-market transition.
        $p->update(['status' => 'sold']);

        // The observer pushed the status to P24 and marked the local row deactivated.
        Http::assertSent(fn ($request) => str_contains($request->url(), '99887766'));
        $this->assertSame('deactivated', $p->fresh()->p24_syndication_status);
    }
}
