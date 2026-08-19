<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Prospecting\TrackedProperty;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Deeds-capture precedence + audit trail (.ai/specs/deeds-capture.md §6, Johan
 * 2026-08-19). A live bug: a fresh, correct deeds re-capture on an EXISTING
 * tracked property silently lost its GPS correction to a stale CMA-report
 * import, and the Deeds Capture screen showed "enriched" with no way to see
 * what actually happened.
 *
 * Proves, through the real POST /api/v1/deeds-capture path:
 *  - a field the capture did not read (or read as absent) NEVER overwrites an
 *    existing populated value — the guard that matters more than precedence;
 *  - a field the capture DID read overwrites a stale existing value (deeds
 *    capture wins);
 *  - placeholder strings ('-', 'N/A', whitespace-only) are treated as absent,
 *    on both the incoming payload AND a previously-corrupted stored value;
 *  - an idempotent re-capture of an unchanged value produces no audit noise;
 *  - the audit trail (source_chain[].field_changes) records exactly what
 *    changed, distinguishing a gain (filled) from a correction (replaced).
 */
final class DeedsCapturePrecedenceTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]);
        Sanctum::actingAs($this->user);
    }

    private function ingest(string $ref, array $property, array $sale = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson(route('v1.deeds-capture'), [
            'source'   => 'cmainfo',
            'captures' => [[
                'source_ref' => $ref,
                'property'   => $property,
                'sale'       => $sale,
            ]],
        ]);
    }

    public function test_fresh_capture_fills_empty_fields_and_records_gains(): void
    {
        $ref = 'ERF-' . Str::random(8);
        $resp = $this->ingest($ref, [
            'street_number' => '39', 'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach',
            'erf_number' => '234', 'latitude' => -30.83, 'longitude' => 30.39,
        ]);
        $resp->assertOk();

        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();
        $this->assertSame('234', $tp->erf_number);
        $this->assertEqualsWithDelta(-30.83, (float) $tp->latitude, 0.0001);
    }

    public function test_replace_wins_on_existing_property_and_absent_field_is_never_overwritten(): void
    {
        $ref = 'ERF-' . Str::random(8);

        // First capture (simulates the older 2026-06-03 CMA-report import): creates the TP.
        $this->ingest($ref, [
            'street_number' => '75', 'street_name' => 'Marine Drive', 'suburb' => 'Uvongo Beach',
            'erf_number' => '668', 'deeds_office' => 'Pietermaritzburg',
            'latitude' => -30.8308040, 'longitude' => 30.3963730,
        ])->assertOk();

        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();
        $this->assertSame('Pietermaritzburg', $tp->deeds_office);

        // Second capture (the fresh, correct panel read): corrected GPS present;
        // deeds_office OMITTED entirely (this capture didn't read it).
        $resp = $this->ingest($ref, [
            'latitude' => -30.830085, 'longitude' => 30.398908,
        ]);
        $resp->assertOk();
        $this->assertFalse($resp->json('results.0.created'), 'must MATCH the existing TP, not create a second one');

        $tp->refresh();
        $this->assertEqualsWithDelta(-30.830085, (float) $tp->latitude, 0.0000001, 'deeds capture must correct stale GPS');
        $this->assertEqualsWithDelta(30.398908, (float) $tp->longitude, 0.0000001);
        $this->assertSame('Pietermaritzburg', $tp->deeds_office, 'a field this capture did not read must NEVER be wiped');

        $entry = end($tp->source_chain);
        $this->assertSame('deeds_capture', $entry['type']);
        $changed = collect($entry['field_changes'])->keyBy('field');
        $this->assertSame('replaced', $changed['latitude']['change_type']);
        $this->assertEqualsWithDelta(-30.8308040, (float) $changed['latitude']['previous'], 0.0000001);
        $this->assertArrayNotHasKey('deeds_office', $changed, 'an absent field must not appear in the audit trail at all');
    }

    public function test_placeholder_strings_are_treated_as_absent_both_incoming_and_previously_stored(): void
    {
        $ref = 'ERF-' . Str::random(8);

        $this->ingest($ref, [
            'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach',
            'title_deed_number' => 'T1234/2020',
        ])->assertOk();
        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();

        // Placeholder INCOMING — must not wipe the existing title deed number.
        $resp1 = $this->ingest($ref, ['title_deed_number' => '-']);
        $resp1->assertOk();
        $tp->refresh();
        $this->assertSame('T1234/2020', $tp->title_deed_number);

        // Simulate a row corrupted by the pre-fix bug: a literal placeholder
        // already sitting in the DB (bond_holder) — real corruption observed
        // live on QA1's tracked_property 402 (bond_holder = '-') before this fix.
        $tp->forceFill(['bond_holder' => '-'])->save();

        $resp2 = $this->ingest($ref, [], ['bond_holder' => 'ABSA Bank']);
        $resp2->assertOk();
        $tp->refresh();
        $this->assertSame('ABSA Bank', $tp->bond_holder);
        $entry = end($tp->source_chain);
        $changed = collect($entry['field_changes'])->keyBy('field');
        $this->assertSame(
            'filled',
            $changed['bond_holder']['change_type'],
            'a stored placeholder must read as absent, so the correction shows as a gain, not a replace'
        );
    }

    /**
     * The exact gap Johan found live (2026-08-19 morning): a field already
     * poisoned with a stored placeholder can never be cleaned by a capture
     * that ALSO only has a placeholder to offer for it — "absent never
     * overwrites" made the junk permanent, because the incoming placeholder
     * normalised to nothing and the key silently vanished before enrich()
     * ever compared it against what was stored. The pre-existing
     * "placeholder-both-sides" test above only covered existing-junk +
     * incoming-REAL (a fill) — never existing-junk + incoming-ALSO-absent,
     * which is the case that actually got through to production.
     */
    public function test_stored_placeholder_is_cleared_when_incoming_is_also_absent(): void
    {
        $ref = 'ERF-' . Str::random(8);
        $this->ingest($ref, ['street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach'])->assertOk();
        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();

        // Simulate the real corruption found live on tracked_property 402/11570.
        $tp->forceFill(['property_type' => '-'])->save();

        // A capture that ALSO only has a placeholder for this field.
        $this->ingest($ref, ['property_type' => '-'])->assertOk();
        $tp->refresh();

        $this->assertNull($tp->property_type, 'a stored placeholder must be cleared, not preserved forever');
        $entry = end($tp->source_chain);
        $changed = collect($entry['field_changes'])->keyBy('field');
        $this->assertSame('cleared', $changed['property_type']['change_type']);
        $this->assertSame('-', $changed['property_type']['previous'], 'the audit trail shows what junk was removed');
    }

    public function test_absent_never_overwrites_still_holds_for_a_genuine_existing_value(): void
    {
        $ref = 'ERF-' . Str::random(8);
        $this->ingest($ref, [
            'street_name' => 'Bairn Street', 'suburb' => 'Uvongo Beach',
            'deeds_office' => 'Pietermaritzburg',
        ])->assertOk();
        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();

        // The clearing fix must NEVER touch a genuine existing value — only
        // stored junk. This is the guard that protects real data; it must
        // hold exactly as before.
        $this->ingest($ref, ['deeds_office' => '-'])->assertOk();
        $tp->refresh();

        $this->assertSame('Pietermaritzburg', $tp->deeds_office);
        $entry = end($tp->source_chain);
        $changed = collect($entry['field_changes'] ?? [])->keyBy('field');
        $this->assertArrayNotHasKey('deeds_office', $changed, 'a genuine value must not even appear in the audit trail when nothing happened to it');
    }

    public function test_idempotent_recapture_of_an_unchanged_value_produces_no_audit_noise(): void
    {
        $ref = 'ERF-' . Str::random(8);
        $this->ingest($ref, ['latitude' => -30.830085, 'longitude' => 30.398908])->assertOk();
        $tp = TrackedProperty::withoutGlobalScopes()->where('agency_id', $this->agencyId)->latest('id')->firstOrFail();

        // Re-capture with the EXACT same coordinates (decimal(10,7) round-trips
        // them with different string padding — the audit must compare
        // numerically, not by string, or every re-capture looks like a change).
        $this->ingest($ref, ['latitude' => -30.830085, 'longitude' => 30.398908])->assertOk();
        $tp->refresh();

        $entry = end($tp->source_chain);
        $this->assertArrayNotHasKey(
            'field_changes',
            $entry,
            'an unchanged re-capture must not report a correction that did not happen'
        );
    }
}
