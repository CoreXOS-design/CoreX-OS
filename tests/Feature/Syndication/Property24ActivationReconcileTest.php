<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Syndication\Property24\Property24ApiClient;
use App\Services\Syndication\Property24\Property24SyndicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P24 is-on-portal reconcile.
 *
 * P24 answers is-on-portal as text/plain with a PHP-style boolean cast: "1" for
 * true, an EMPTY body for false. request() wraps non-JSON bodies as ['raw' => …],
 * so the live payloads are ['raw' => '1'] and ['raw' => ''] — confirmed against
 * 137,766 production status_checks over 7 days, which held those two values and
 * nothing else.
 *
 * The original test here faked Content-Type: application/json with body 'true' —
 * a shape P24 does not send. That decodes to a real bool and hit the one branch
 * that worked, so the suite stayed green while the reconcile was dead in
 * production for every listing: ~137k API calls a week resolving to no-ops,
 * listings P24 had activated frozen at 'submitted' ("Submitted, awaiting
 * activation…") indefinitely, and listings P24 had dropped still reading 'active'.
 *
 * These tests therefore assert the REAL wire shapes first.
 */
class Property24ActivationReconcileTest extends TestCase
{
    use RefreshDatabase;

    private function makeProperty(string $status, ?string $ref = '99887766'): Property
    {
        $agency = Agency::create([
            'name' => 'Coastal', 'slug' => 'coastal-' . Str::random(6),
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
            'p24_syndication_status'  => $status,
            'p24_ref'                 => $ref,
        ])->save();

        return $p;
    }

    /** The real "on portal" answer: text/plain body "1". */
    public function test_raw_one_flips_submitted_to_active(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('1', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('submitted');

        $result = app(Property24SyndicationService::class)->syncActivationStatus($p);

        $this->assertTrue($result['success']);
        $this->assertSame('active', $p->fresh()->p24_syndication_status);
        $this->assertNotNull($p->fresh()->p24_activated_at);
    }

    /** The real "not on portal" answer: an EMPTY text/plain body. */
    public function test_raw_empty_demotes_active_to_submitted(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('active');

        app(Property24SyndicationService::class)->syncActivationStatus($p);

        $this->assertSame('submitted', $p->fresh()->p24_syndication_status);
        $this->assertSame('Listing not currently on portal', $p->fresh()->p24_last_error);
    }

    /** An empty answer must not resurrect a listing that was never activated. */
    public function test_raw_empty_leaves_submitted_alone(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('submitted');

        app(Property24SyndicationService::class)->syncActivationStatus($p);

        $this->assertSame('submitted', $p->fresh()->p24_syndication_status);
    }

    /** Back-compat: the bare JSON boolean shape must still reconcile. */
    public function test_bare_json_boolean_still_flips_submitted_to_active(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('true', 200, ['Content-Type' => 'application/json'])]);

        $p = $this->makeProperty('submitted');

        app(Property24SyndicationService::class)->syncActivationStatus($p);

        $this->assertSame('active', $p->fresh()->p24_syndication_status);
    }

    /** An unrecognised body must never be read as "not on portal". */
    public function test_unknown_payload_leaves_status_untouched(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('<html>503 unavailable</html>', 200, ['Content-Type' => 'text/html'])]);

        $p = $this->makeProperty('active');

        app(Property24SyndicationService::class)->syncActivationStatus($p);

        $this->assertSame('active', $p->fresh()->p24_syndication_status);
    }

    /**
     * isOnPortal() is the delist guard. It cast the ['raw' => …] ARRAY to bool —
     * always true, since a non-empty array is truthy — so it reported every
     * listing as still live, including ones P24 had dropped.
     */
    public function test_delist_guard_reports_false_when_not_on_portal(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('active');

        $this->assertFalse(app(Property24SyndicationService::class)->isOnPortal($p));
    }

    public function test_delist_guard_reports_true_when_on_portal(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('1', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('active');

        $this->assertTrue(app(Property24SyndicationService::class)->isOnPortal($p));
    }

    /** A failed call is "we don't know" — never "not on portal". */
    public function test_delist_guard_returns_null_when_call_fails(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('', 500)]);

        $p = $this->makeProperty('active');

        $this->assertNull(app(Property24SyndicationService::class)->isOnPortal($p));
    }

    /**
     * @dataProvider onPortalPayloads
     */
    public function test_payload_interpretation(mixed $payload, ?bool $expected): void
    {
        $this->assertSame($expected, Property24ApiClient::interpretOnPortalPayload($payload));
    }

    public static function onPortalPayloads(): array
    {
        return [
            'live raw one'      => [['raw' => '1'], true],
            'live raw empty'    => [['raw' => ''], false],
            'raw zero'          => [['raw' => '0'], false],
            'raw whitespace'    => [['raw' => ' 1 '], true],
            'bare bool true'    => [true, true],
            'bare bool false'   => [false, false],
            'string true'       => ['true', true],
            'string True'       => ['True', true],
            'string false'      => ['false', false],
            'int one'           => [1, true],
            'int zero'          => [0, false],
            'object shape'      => [['isOnPortal' => true], true],
            'object shape pasc' => [['IsOnPortal' => false], false],
            'html body'         => [['raw' => '<html>oops</html>'], null],
            'null'              => [null, null],
            'unrelated array'   => [['foo' => 'bar'], null],
        ];
    }
}
