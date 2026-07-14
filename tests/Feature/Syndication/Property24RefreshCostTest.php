<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Property;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * THE REFRESH COST CONTRACT.
 *
 * Pressing Refresh on a listing where nothing changed must cost exactly ONE P24
 * call — the listing POST. No photo re-upload, no agent profile push, no agent
 * photo upload, no 90s agent-list scan.
 *
 * This lock exists because that contract has now been broken twice, in production,
 * and both times it was caught by an agent saying "Refresh feels slow" rather than
 * by anything in the codebase:
 *
 *   1. Every refresh re-uploaded the entire photo gallery (60s+ per refresh),
 *      fixed by the properties.p24_image_signature gate.
 *   2. Months later an unconditional agent profile push + agent photo upload was
 *      added to the submit path — per agent, on every refresh — quietly undoing
 *      most of that win and putting a 15-120s GET /agencies/{id}/agents back on
 *      the critical path.
 *
 * A slow refresh breaks no assertion and fails no pipeline; it just wastes the
 * agent's afternoon. So the cost itself is the assertion. If you are here because
 * this test failed: you added a call to the submit path that fires when nothing
 * changed. Gate it on a signature — do not raise the budget.
 *
 * The companion runtime guard is Property24SyndicationService::auditRefreshCost,
 * which logs the same violation as a WARNING on the live system.
 */
class Property24RefreshCostTest extends TestCase
{
    use RefreshDatabase;

    private const P24_AGENCY_ID = 29159;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.property24_syndication.api_url' => 'https://p24.test']);
        Cache::flush();

        // Reset the client's static in-process agent memo so each test starts cold.
        $memo = new \ReflectionProperty(Property24ApiClient::class, 'agentsCache');
        $memo->setAccessible(true);
        $memo->setValue(null, []);
    }

    /** A listing that is already live on P24, with its agent fully in sync. */
    private function liveListing(): Property
    {
        $agency = Agency::create([
            'name'           => 'Coastal',
            'slug'           => 'coastal',
            'p24_agency_id'  => (string) self::P24_AGENCY_ID,
            'p24_username'   => 'u',
            'p24_password'   => 'p',
        ]);

        $agent = User::factory()->create([
            'agency_id'           => $agency->id,
            'name'                => 'Retha Kelly',
            'email'               => 'retha@example.test',
            'p24_agent_id'        => 440413,
            'p24_agent_agency_id' => self::P24_AGENCY_ID,
            'agent_photo_path'    => null,   // no photo → nothing to upload
        ]);

        $property = Property::factory()->create([
            'agency_id' => $agency->id,
            'agent_id'  => $agent->id,
            'p24_ref'   => '117411168',
            'p24_syndication_status' => 'active',
        ]);

        // Fingerprint the CURRENT gallery + agent profile as "what P24 already
        // holds" — i.e. the state immediately after a successful sync.
        $property->forceFill(['p24_image_signature' => $property->p24ImageSignature()])->saveQuietly();
        $agent->forceFill(['p24_profile_signature' => $this->profileSignature($agent)])->saveQuietly();

        return $property->fresh();
    }

    /** The signature the service will compute for this agent's profile payload. */
    private function profileSignature(User $agent): string
    {
        $service = app(Property24SyndicationService::class);
        $method  = new \ReflectionMethod($service, 'agentProfilePayload');
        $method->setAccessible(true);

        return md5((string) json_encode(
            $method->invoke($service, $agent, (int) $agent->p24_agent_id, self::P24_AGENCY_ID)
        ));
    }

    public function test_refreshing_an_unchanged_listing_costs_exactly_one_p24_call(): void
    {
        $property = $this->liveListing();

        Http::fake([
            '*/listings'  => Http::response(['listingNumber' => 117411168, 'isOnPortal' => true], 200),
            '*'           => Http::response([], 200),
        ]);

        $result = app(Property24SyndicationService::class)->submitListing($property);

        $this->assertTrue($result['success'], 'the refresh itself must still succeed');

        // THE ASSERTION. One call: POST /listings. Nothing else.
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/listings') && $request->method() === 'POST');

        // And it must have told P24 to KEEP its photos rather than re-sending them.
        Http::assertSent(function ($request) {
            $this->assertArrayHasKey('photos', $request->data(), 'the payload must carry an explicit photos key');
            $this->assertNull($request->data()['photos'], 'an unchanged gallery must send photos:null, never re-upload');
            return true;
        });
    }

    public function test_an_unchanged_refresh_never_scans_the_agent_list(): void
    {
        $property = $this->liveListing();

        Http::fake([
            '*/listings' => Http::response(['listingNumber' => 117411168, 'isOnPortal' => true], 200),
            '*'          => Http::response([], 200),
        ]);

        app(Property24SyndicationService::class)->submitListing($property);

        // GET /agencies/{id}/agents is a 610KB response that takes 15-90s on P24's
        // side and has timed out at 120s in production. It must NEVER sit on the
        // path of a routine refresh — the agent id is already on the user row.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/agents'));
    }

    public function test_a_changed_agent_photo_is_re_uploaded(): void
    {
        // The gate must not become a wall: when the agent's photo genuinely changes,
        // the refresh MUST push it. A cheap skip that never re-syncs is worse than
        // the slow path it replaced.
        $property = $this->liveListing();
        $agent    = $property->agent;

        \Illuminate\Support\Facades\Storage::fake('public');
        \Illuminate\Support\Facades\Storage::disk('public')->put('agents/1/photo.jpg', $this->jpegBytes());
        $agent->forceFill([
            'agent_photo_path'    => 'agents/1/photo.jpg',
            'p24_photo_signature' => 'disk:stale-signature-from-the-previous-photo',
        ])->saveQuietly();

        Http::fake([
            '*/listings' => Http::response(['listingNumber' => 117411168, 'isOnPortal' => true], 200),
            '*'          => Http::response([], 200),
        ]);

        app(Property24SyndicationService::class)->submitListing($property->fresh());

        Http::assertSent(fn ($request) => str_contains($request->url(), '/profile-picture') && $request->method() === 'PUT');

        // ...and the new photo's signature is recorded, so the NEXT refresh skips it.
        $this->assertNotSame(
            'disk:stale-signature-from-the-previous-photo',
            $agent->fresh()->p24_photo_signature,
            'a successful photo upload must stamp the new signature'
        );
    }

    private function jpegBytes(): string
    {
        $img = imagecreatetruecolor(10, 10);
        ob_start();
        imagejpeg($img);
        $bytes = (string) ob_get_clean();
        imagedestroy($img);

        return $bytes;
    }
}
