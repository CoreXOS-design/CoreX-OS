<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSignature;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * 2026-10-06 (cc1, security) — the public inspection report link
 * (/rental-inspection-report/{token}, and its token-gated signature-file
 * route) must stop working the moment the inspection is ARCHIVED
 * (soft-deleted) or CANCELLED, and work again — on the SAME token, so the
 * QR already printed on the PDF revives — when an archived one is RESTORED.
 * Expiry, revoke and regenerate rules are unchanged.
 *
 * Before the fix RentalInspection::findByPublicToken() ran
 * withoutGlobalScopes(), which strips SoftDeletes, and never looked at
 * status — so both kept serving photos, signatures and tenant names for the
 * rest of the link's 90 days (rental-inspections.md §44a).
 *
 * Input paths covered: archived · cancelled · restored · archived-then-
 * restored-then-archived again · archive via the real HTTP route · restore
 * via the real HTTP route · expired token still dead after restore · revoked
 * token still dead after restore · regenerated old token still dead ·
 * signature file route (archived / cancelled / restored) · never-existed
 * token gives the identical page · token is not cleared by archiving.
 */
final class RentalInspectionPublicLinkLifecycleTest extends TestCase
{
    use RefreshDatabase;

    /** 1x1 PNG, the same fixture the audit-fixes test uses. */
    private const PNG_B64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=';

    // Static text of rental-inspections/public/unavailable.blade.php, chosen with no
    // apostrophe so assertSee()'s HTML-escaping cannot make it miss the raw page.
    private const UNAVAILABLE = 'Please contact your agent for a current link.';

    private function setupInspection(string $suffix = 'a'): array
    {
        $agency = Agency::create(['name' => "Link Lifecycle {$suffix}", 'slug' => 'link-lifecycle-' . $suffix . '-' . uniqid()]);
        $branch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $agency->id]);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::forceCreate([
            'agency_id' => $agency->id, 'agent_id' => $agent->id, 'branch_id' => $branch->id,
            'title' => "Lifecycle Property {$suffix}", 'status' => 'active', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'address' => "{$suffix} Marine Drive",
        ]);
        $lease = Lease::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'property_id' => $property->id,
            'status' => Lease::STATUS_ACTIVE, 'rental_amount' => 10000, 'start_date' => now()->subMonths(3),
            'created_by_user_id' => $agent->id,
        ]);
        $inspection = RentalInspection::create([
            'agency_id' => $agency->id, 'lease_id' => $lease->id, 'type' => RentalInspection::TYPE_IN,
            'created_by_user_id' => $agent->id,
        ]);
        $item = RentalInspectionItem::create([
            'agency_id' => $agency->id, 'property_id' => $property->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => "Lounge Ceiling {$suffix}", 'created_by_user_id' => $agent->id,
        ]);
        RentalInspectionObservation::record([
            'agency_id' => $agency->id, 'rental_inspection_id' => $inspection->id,
            'rental_inspection_item_id' => $item->id, 'observed_by_user_id' => $agent->id,
            'condition' => 'good', 'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
        ]);

        return compact('agency', 'agent', 'property', 'lease', 'inspection', 'item');
    }

    /** A real signed row with a real file on the (faked) private disk, so a 404 can only come from the inspection's state. */
    private function addSignature(array $s): RentalInspectionSignature
    {
        Storage::fake('local');
        Storage::disk('local')->put('inspection-test/sig.png', base64_decode(self::PNG_B64));

        return RentalInspectionSignature::forceCreate([
            'agency_id' => $s['agency']->id,
            'rental_inspection_id' => $s['inspection']->id,
            'party_role' => RentalInspectionSignature::PARTY_AGENT,
            'disposition' => RentalInspectionSignature::DISPOSITION_SIGNED,
            'party_signature_path' => RentalInspectionSignature::PRIVATE_PREFIX . 'inspection-test/sig.png',
            'recorded_by_user_id' => $s['agent']->id,
            'disposition_recorded_at' => now(),
        ]);
    }

    private function assertPageLive(string $token, string $suffix = 'a'): void
    {
        $resp = $this->get(route('rental-inspections.public.show', $token));
        $resp->assertOk();
        $resp->assertSee("Lounge Ceiling {$suffix}");
        $resp->assertDontSee(self::UNAVAILABLE);
    }

    private function assertPageDead(string $token, string $suffix = 'a'): void
    {
        $resp = $this->get(route('rental-inspections.public.show', $token));
        // The standard "link isn't available" page — never an exception page,
        // and never any of the inspection's own data.
        $resp->assertOk();
        $resp->assertSee(self::UNAVAILABLE);
        $resp->assertDontSee("Lounge Ceiling {$suffix}");
        $resp->assertDontSee('Marine Drive');
        $resp->assertDontSee('Whoops');
        $resp->assertDontSee('Stack trace');
    }

    // ── ARCHIVED ────────────────────────────────────────────────────────

    public function test_an_archived_inspections_public_page_shows_the_unavailable_page(): void
    {
        $s = $this->setupInspection();
        $token = $s['inspection']->generatePublicLink();
        $this->assertPageLive($token); // control: it works before archiving

        $s['inspection']->delete();

        self::assertNull(RentalInspection::findByPublicToken($token));
        $this->assertPageDead($token);
    }

    public function test_archiving_does_not_clear_the_token(): void
    {
        $s = $this->setupInspection();
        $token = $s['inspection']->generatePublicLink();

        $s['inspection']->delete();

        // The token must survive so a restore can revive the same link/QR.
        self::assertSame($token, RentalInspection::withTrashed()->find($s['inspection']->id)->public_token);
    }

    public function test_an_archived_inspections_signature_file_route_is_dead(): void
    {
        $s = $this->setupInspection();
        $sig = $this->addSignature($s);
        $token = $s['inspection']->generatePublicLink();
        $url = route('rental-inspections.public.signature-file', [$token, $sig->id, 'signature']);

        $this->get($url)->assertOk(); // control: the file is really served while live

        $s['inspection']->delete();

        $this->get($url)->assertNotFound();
    }

    // ── CANCELLED ───────────────────────────────────────────────────────

    public function test_a_cancelled_inspections_public_page_shows_the_unavailable_page(): void
    {
        Mail::fake();
        $s = $this->setupInspection();
        $token = $s['inspection']->generatePublicLink();
        $this->assertPageLive($token);

        $s['inspection']->cancel($s['agent'], 'Started on the wrong property');

        self::assertSame(RentalInspection::STATUS_CANCELLED, $s['inspection']->fresh()->status);
        self::assertNull(RentalInspection::findByPublicToken($token));
        $this->assertPageDead($token);
    }

    public function test_a_cancelled_inspections_signature_file_route_is_dead(): void
    {
        Mail::fake();
        $s = $this->setupInspection();
        $sig = $this->addSignature($s);
        $token = $s['inspection']->generatePublicLink();
        $url = route('rental-inspections.public.signature-file', [$token, $sig->id, 'signature']);
        $this->get($url)->assertOk();

        $s['inspection']->cancel($s['agent'], 'Duplicate booking');

        $this->get($url)->assertNotFound();
    }

    public function test_the_agent_screen_does_not_call_a_cancelled_inspections_link_live(): void
    {
        Mail::fake();
        $s = $this->setupInspection();
        $s['inspection']->generatePublicLink();
        $s['inspection']->cancel($s['agent'], 'Wrong tenant');

        $fresh = $s['inspection']->fresh();
        self::assertTrue($fresh->publicLinkIsValid(), 'token itself is untouched');
        self::assertFalse($fresh->publicLinkIsAvailable());

        $this->actingAs($s['agent'])
            ->get(route('corex.rental-inspections.show', $fresh))
            ->assertOk()
            ->assertSee('The link is switched off while this inspection is cancelled.')
            ->assertDontSee('Live until');
    }

    // ── RESTORED ────────────────────────────────────────────────────────

    public function test_restoring_an_archived_inspection_brings_the_same_link_back(): void
    {
        $s = $this->setupInspection();
        $token = $s['inspection']->generatePublicLink();
        $s['inspection']->delete();
        $this->assertPageDead($token);

        RentalInspection::withTrashed()->find($s['inspection']->id)->restore();

        self::assertNotNull(RentalInspection::findByPublicToken($token));
        $this->assertPageLive($token); // same token, not a regenerated one
    }

    public function test_archive_restore_archive_again_is_dead_again(): void
    {
        $s = $this->setupInspection();
        $token = $s['inspection']->generatePublicLink();

        $s['inspection']->delete();
        RentalInspection::withTrashed()->find($s['inspection']->id)->restore();
        $this->assertPageLive($token);

        RentalInspection::find($s['inspection']->id)->delete();
        $this->assertPageDead($token);
    }

    public function test_restored_signature_file_route_serves_again(): void
    {
        $s = $this->setupInspection();
        $sig = $this->addSignature($s);
        $token = $s['inspection']->generatePublicLink();
        $url = route('rental-inspections.public.signature-file', [$token, $sig->id, 'signature']);

        $s['inspection']->delete();
        $this->get($url)->assertNotFound();

        RentalInspection::withTrashed()->find($s['inspection']->id)->restore();
        $this->get($url)->assertOk();
    }

    // ── The real HTTP archive / restore path an agent uses ──────────────

    public function test_archive_and_restore_through_the_agents_own_screens(): void
    {
        $s = $this->setupInspection();
        $token = $s['inspection']->generatePublicLink();
        $this->assertPageLive($token);

        $this->actingAs($s['agent'])
            ->delete(route('corex.rental-inspections.destroy', $s['inspection']))
            ->assertRedirect(route('corex.rental-inspections.index'));
        auth()->logout();
        $this->assertPageDead($token);

        $this->actingAs($s['agent'])
            ->post(route('corex.rental-inspections.restore', $s['inspection']->id))
            ->assertRedirect();
        auth()->logout();
        $this->assertPageLive($token);
    }

    // ── Rules that must NOT have changed ────────────────────────────────

    public function test_an_expired_token_stays_dead_even_after_a_restore(): void
    {
        $s = $this->setupInspection();
        $s['inspection']->generatePublicLink(-1);
        $token = $s['inspection']->public_token;

        $s['inspection']->delete();
        RentalInspection::withTrashed()->find($s['inspection']->id)->restore();

        self::assertNull(RentalInspection::findByPublicToken($token));
        $this->assertPageDead($token);
    }

    public function test_a_revoked_token_stays_dead_after_a_restore(): void
    {
        $s = $this->setupInspection();
        $token = $s['inspection']->generatePublicLink();
        $s['inspection']->revokePublicLink();

        $s['inspection']->delete();
        RentalInspection::withTrashed()->find($s['inspection']->id)->restore();

        $this->assertPageDead($token);
    }

    public function test_a_replaced_token_stays_dead_and_the_new_one_follows_the_lifecycle(): void
    {
        $s = $this->setupInspection();
        $old = $s['inspection']->generatePublicLink();
        $new = $s['inspection']->generatePublicLink();

        $this->assertPageDead($old);
        $this->assertPageLive($new);

        $s['inspection']->delete();
        $this->assertPageDead($new);
        RentalInspection::withTrashed()->find($s['inspection']->id)->restore();
        $this->assertPageLive($new);
        $this->assertPageDead($old);
    }

    public function test_a_token_that_never_existed_gives_the_identical_page(): void
    {
        $s = $this->setupInspection();
        $s['inspection']->generatePublicLink();

        $dead = $this->get(route('rental-inspections.public.show', 'never-issued-token'));
        $s['inspection']->delete();
        $archived = $this->get(route('rental-inspections.public.show', $s['inspection']->public_token));

        // Nothing distinguishes "archived" from "wrong token" to a stranger.
        self::assertSame($dead->getStatusCode(), $archived->getStatusCode());
        self::assertSame(
            preg_replace('/\s+/', ' ', strip_tags($dead->getContent())),
            preg_replace('/\s+/', ' ', strip_tags($archived->getContent())),
        );
    }

    public function test_one_agencys_archived_inspection_does_not_affect_another_agencys_live_link(): void
    {
        $a = $this->setupInspection('a');
        $b = $this->setupInspection('b');
        $tokenA = $a['inspection']->generatePublicLink();
        $tokenB = $b['inspection']->generatePublicLink();

        $a['inspection']->delete();

        $this->assertPageDead($tokenA, 'a');
        $this->assertPageLive($tokenB, 'b');
    }
}
