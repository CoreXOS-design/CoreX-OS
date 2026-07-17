<?php

namespace Tests\Feature\Syndication;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * p24:reconcile-portal-presence — the COLD half of the reconcile.
 *
 * The 15-min SyncProperty24Activations job only sweeps enabled listings at
 * submitted/pending/active. Everything claiming to be OFF the portal was
 * reconciled by nothing: the command's suspect set was 'deactivated' ALONE, so
 * 'error' (159 rows) and 'rejected' (30 rows) were never checked — each of which
 * reads as "not advertised" while potentially hiding a publicly live listing.
 *
 * These lock the widened suspect set and the stranded-advert alarm.
 */
class Property24PortalPresenceSweepTest extends TestCase
{
    use RefreshDatabase;

    private function makeProperty(string $p24Status, string $marketStatus = 'active', bool $enabled = true): Property
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
            'property_type' => 'house', 'status' => $marketStatus, 'price' => 1000000,
        ]);

        $p->forceFill([
            'p24_syndication_enabled' => $enabled,
            'p24_syndication_status'  => $p24Status,
            'p24_ref'                 => (string) random_int(100000000, 999999999),
        ])->save();

        return $p;
    }

    /** The whole point: 'error' and 'rejected' must be in the default suspect set. */
    public function test_claims_off_portal_set_covers_error_and_rejected(): void
    {
        $this->assertSame(
            ['deactivated', 'error', 'rejected'],
            Property::P24_CLAIMS_OFF_PORTAL_STATUSES
        );
        $this->assertContains(Property::PORTAL_OFF_STATUS, Property::P24_CLAIMS_OFF_PORTAL_STATUSES);

        // The hot set belongs to SyncProperty24Activations — never sweep it here.
        foreach (['submitted', 'pending', 'active'] as $hot) {
            $this->assertNotContains($hot, Property::P24_CLAIMS_OFF_PORTAL_STATUSES);
        }
        // Terminal-but-legitimately-listed must not be treated as claiming off.
        foreach (Property::P24_ON_PORTAL_TERMINAL_STATUSES as $terminal) {
            $this->assertNotContains($terminal, Property::P24_CLAIMS_OFF_PORTAL_STATUSES);
        }
    }

    /** An 'error' row that P24 is NOT carrying is corrected to 'deactivated'. */
    public function test_error_row_not_on_portal_is_corrected(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('error');

        $this->artisan('p24:reconcile-portal-presence', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame('deactivated', $p->fresh()->p24_syndication_status);
    }

    /** A 'rejected' row that P24 IS carrying is the stranded case — status told truthfully. */
    public function test_rejected_row_live_on_portal_is_told_truthfully(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('1', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('rejected', 'active');

        $this->artisan('p24:reconcile-portal-presence', ['--sleep' => 0])->assertSuccessful();

        $this->assertSame('active', $p->fresh()->p24_syndication_status);
    }

    /** Dry run must never write. */
    public function test_dry_run_writes_nothing(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('error');

        $this->artisan('p24:reconcile-portal-presence', ['--dry-run' => true, '--sleep' => 0])->assertSuccessful();

        $this->assertSame('error', $p->fresh()->p24_syndication_status);
    }

    /**
     * A withdrawn property that P24 is still advertising must NOT be silently
     * "corrected" into looking fine — the sweep runs without --withdraw, so the
     * alarm is the only thing standing between this and an advert nobody notices.
     */
    public function test_stranded_advert_is_logged(): void
    {
        Http::fake(['*is-on-portal*' => Http::response('1', 200, ['Content-Type' => 'text/plain'])]);

        $p = $this->makeProperty('deactivated', 'withdrawn');

        $this->artisan('p24:reconcile-portal-presence', ['--sleep' => 0])
            ->expectsOutputToContain('still needs --withdraw')
            ->assertSuccessful();
    }

    /** Without --withdraw the sweep makes NO portal-mutating calls. */
    public function test_sweep_without_withdraw_never_pushes_to_portal(): void
    {
        Http::fake([
            '*is-on-portal*' => Http::response('1', 200, ['Content-Type' => 'text/plain']),
            '*'              => Http::response('{}', 200, ['Content-Type' => 'application/json']),
        ]);

        $this->makeProperty('deactivated', 'withdrawn');

        $this->artisan('p24:reconcile-portal-presence', ['--sleep' => 0])->assertSuccessful();

        Http::assertNotSent(fn ($request) => in_array($request->method(), ['POST', 'PUT'], true));
    }
}
