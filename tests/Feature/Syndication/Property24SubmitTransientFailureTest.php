<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24ListingMapper;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * A cURL-28 read-timeout on POST /listings (Property24 accepted the request but
 * never responded within the read timeout) is a TRANSIENT P24-side failure. The
 * submit/update path must NOT record it as a permanent 'error':
 *
 *  • A live listing (has p24_ref) stays 'active' — it's still on the portal;
 *    clobbering it to 'error' mislabelled it as broken AND stripped every UI
 *    recovery button (they require active|submitted|submitting), stranding it.
 *  • A first-ever submit (no p24_ref) becomes 'pending' (retryable), never 'error'.
 *
 * This mirrors the existing isTransientFailure() handling already proven for
 * deactivate/reactivate (AT-P24 #5), extended to the submit path.
 * Reported live: property 1357 (ref 117201765), 5 timeouts on 2026-07-04.
 */
class Property24SubmitTransientFailureTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Build a service whose mapper is stubbed (so the test exercises ONLY the
     * transient-failure branch, not suburb/photo mapping) and whose HTTP layer
     * throws a cURL-28 ConnectionException on the listing save.
     */
    private function serviceWithTimeoutOnSave(User $agent): Property24SyndicationService
    {
        Http::fake([
            // POST /listing/v53/listings — the save — times out (0 bytes, cURL 28).
            '*/listings' => function () {
                throw new ConnectionException(
                    'cURL error 28: Operation timed out after 120000 milliseconds with 0 bytes received'
                );
            },
            // Everything else (GET agencies/{id}/agents, PUT /agents profile push)
            // succeeds — the listing agent is already registered under this agency.
            '*' => Http::response(
                [['id' => 77, 'sourceReference' => 'CoreX-Agent-' . $agent->id, 'agencyId' => 123]],
                200,
                ['Content-Type' => 'application/json']
            ),
        ]);

        $mapper = new class extends Property24ListingMapper {
            public function map(Property $property, bool $includePhotos = true): array
            {
                return ['sourceReference' => 'CoreX-' . $property->id, 'photos' => null];
            }

            public function validate(array $payload): array
            {
                return [];
            }
        };

        return new Property24SyndicationService(new Property24ApiClient(), $mapper);
    }

    private function makeProperty(Agency $agency, Branch $branch, User $agent, array $overrides): Property
    {
        $p = Property::withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'external_id' => (string) Str::uuid(), 'title' => 'Listing', 'suburb' => 'Uvongo',
            'property_type' => 'house', 'status' => 'active', 'price' => 1500000,
        ]);
        $p->forceFill(array_merge(['p24_syndication_enabled' => true], $overrides))->save();

        return $p;
    }

    private function seedWorld(): array
    {
        Queue::fake(); // isolate from MatchPropertyJob / observers
        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal',
            'p24_username' => 'u', 'p24_password' => 'p', 'p24_agency_id' => '123',
        ]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Main']);
        $agent  = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'agent']);

        return [$agency, $branch, $agent];
    }

    public function test_timeout_on_a_live_listing_keeps_it_active_not_error(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, [
            'p24_syndication_status' => 'submitting', // controller sets this before dispatch
            'p24_ref'                => '117201765',
        ]);

        $result = $this->serviceWithTimeoutOnSave($agent)->submitListing($p);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['transient'] ?? false, 'a P24 timeout must be reported transient');

        $fresh = $p->fresh();
        $this->assertSame('active', $fresh->p24_syndication_status, 'a live listing must NOT be clobbered to error');
        $this->assertSame('117201765', $fresh->p24_ref, 'the ref must be preserved');
        $this->assertStringContainsString('still live', $fresh->p24_last_error);
        $this->assertStringNotContainsStringIgnoringCase('cURL', (string) $fresh->p24_last_error, 'no raw cURL text in the agent-facing note');
    }

    public function test_timeout_on_a_first_submit_marks_pending_not_error(): void
    {
        [$agency, $branch, $agent] = $this->seedWorld();
        $p = $this->makeProperty($agency, $branch, $agent, [
            'p24_syndication_status' => 'submitting',
            'p24_ref'                => null, // never submitted before
        ]);

        $result = $this->serviceWithTimeoutOnSave($agent)->submitListing($p);

        $this->assertFalse($result['success']);
        $this->assertTrue($result['transient'] ?? false);

        $fresh = $p->fresh();
        $this->assertSame('pending', $fresh->p24_syndication_status, 'a first-submit timeout must be retryable, not a hard error');
        $this->assertNull($fresh->p24_ref);
        $this->assertStringContainsString('retry', $fresh->p24_last_error);
    }
}
