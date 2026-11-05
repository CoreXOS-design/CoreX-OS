<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Property #3317 (2026-11-05): Property24 answered a full send with
 * isOnPortal:false — "This listing has been permanently blocked." A later
 * toggle off/on + Reactivate got HTTP 200 on BackOnMarket, and CoreX wrote
 * 'submitted' with the error wiped, so the panel showed a clean "submitted"
 * for a listing Property24 had refused. Reactivate now reads the portal back.
 *
 * Wire shapes are the real ones: is-on-portal is text/plain "1" / "".
 */
class Property24ReactivateReadBackTest extends TestCase
{
    use RefreshDatabase;

    private function deactivatedListing(): Property
    {
        $agency = Agency::create([
            'name' => 'Second Agency', 'slug' => 'second-' . Str::random(6),
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
            'p24_syndication_status'  => 'deactivated',
            'p24_ref'                 => '117236464',
            'p24_last_error'          => null,
        ])->saveQuietly();

        return $p->fresh();
    }

    private function fakePortal(string $onPortalBody): void
    {
        Http::fake([
            '*is-on-portal*' => Http::response($onPortalBody, 200, ['Content-Type' => 'text/plain']),
            '*/status*'      => Http::response('', 200, ['Content-Type' => 'text/plain']),
        ]);
    }

    private function logRefusal(Property $p): void
    {
        DB::table('p24_syndication_logs')->insert([
            'property_id'      => $p->id,
            'action'           => 'submit',
            'response_payload' => json_encode([
                'reasons'       => ['This listing has been permanently blocked.', 'This listing is blocked.'],
                'isOnPortal'    => false,
                'listingNumber' => 117236464,
            ]),
            'status_code'      => 200,
            'created_at'       => now()->subMinutes(20),
        ]);
    }

    public function test_blocked_listing_reactivate_keeps_property24s_refusal_visible(): void
    {
        $this->fakePortal('');
        $p = $this->deactivatedListing();
        $this->logRefusal($p);

        $result = app(Property24SyndicationService::class)->reactivateListing($p);

        $this->assertFalse($result['success'], 'a refused listing must not be reported as reactivated');
        $fresh = $p->fresh();
        $this->assertSame('submitted', $fresh->p24_syndication_status, 'stays in the activation reconcile set');
        $this->assertStringContainsString('This listing has been permanently blocked.', $fresh->p24_last_error);
        $this->assertStringContainsString('117236464', $fresh->p24_last_error);
    }

    public function test_off_portal_without_a_known_refusal_warns_but_does_not_fail(): void
    {
        $this->fakePortal('');
        $p = $this->deactivatedListing();

        $result = app(Property24SyndicationService::class)->reactivateListing($p);

        $this->assertTrue($result['success']);
        $fresh = $p->fresh();
        $this->assertSame('submitted', $fresh->p24_syndication_status);
        $this->assertStringContainsString('not showing on the portal yet', $fresh->p24_last_error);
    }

    public function test_a_later_successful_send_is_not_mistaken_for_a_refusal(): void
    {
        $this->fakePortal('');
        $p = $this->deactivatedListing();
        $this->logRefusal($p);
        DB::table('p24_syndication_logs')->insert([
            'property_id' => $p->id, 'action' => 'submit', 'status_code' => 200,
            'response_payload' => json_encode(['isOnPortal' => true, 'listingNumber' => 117236464]),
            'created_at' => now()->subMinutes(5),
        ]);

        $result = app(Property24SyndicationService::class)->reactivateListing($p);

        $this->assertTrue($result['success']);
        $this->assertStringNotContainsString('permanently blocked', $p->fresh()->p24_last_error);
    }

    public function test_listing_confirmed_back_on_portal_goes_straight_to_active(): void
    {
        $this->fakePortal('1');
        $p = $this->deactivatedListing();
        $this->logRefusal($p);

        $result = app(Property24SyndicationService::class)->reactivateListing($p);

        $this->assertTrue($result['success']);
        $fresh = $p->fresh();
        $this->assertSame('active', $fresh->p24_syndication_status);
        $this->assertNull($fresh->p24_last_error);
        $this->assertNotNull($fresh->p24_activated_at);
    }

    public function test_no_usable_read_back_keeps_the_old_behaviour(): void
    {
        Http::fake([
            '*is-on-portal*' => Http::response('', 500),
            '*/status*'      => Http::response('', 200, ['Content-Type' => 'text/plain']),
        ]);
        $p = $this->deactivatedListing();

        $result = app(Property24SyndicationService::class)->reactivateListing($p);

        $this->assertTrue($result['success']);
        $this->assertSame('submitted', $p->fresh()->p24_syndication_status);
        $this->assertNull($p->fresh()->p24_last_error);
    }
}
