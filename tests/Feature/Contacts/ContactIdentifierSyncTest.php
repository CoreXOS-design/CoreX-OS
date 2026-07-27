<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\User;
use App\Services\ContactDuplicateService;
use App\Services\Contacts\ContactIdentifierService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-125 step 3 — multi-identifier writes: syncIdentifiers (form/API), the
 * reverse mirror-sync (importers/single-field paths), and multi-identifier dedup.
 */
final class ContactIdentifierSyncTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;

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
        $this->actingAs(User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin',
        ]));
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Multi', 'last_name' => 'Id',
            'phone' => '', 'email' => null,
        ]);
    }

    private function svc(): ContactIdentifierService
    {
        return app(ContactIdentifierService::class);
    }

    public function test_sync_creates_multiple_with_one_primary_and_mirror(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'label' => 'Mobile', 'is_primary' => false],
            ['value' => '0822222222', 'label' => 'Work', 'is_primary' => true],
        ], [
            ['value' => 'primary@example.com', 'is_primary' => true],
            ['value' => 'secondary@example.com', 'is_primary' => false],
        ]);

        $contact->refresh();
        $this->assertSame(2, $contact->phones()->count());
        $this->assertSame(2, $contact->emails()->count());
        $this->assertSame('0822222222', $contact->phone, 'phone mirror = marked-primary phone');
        $this->assertSame('primary@example.com', $contact->email, 'email mirror = marked-primary email');
        $this->assertSame(1, $contact->phones()->where('is_primary', true)->count());
        $this->assertSame(1, $contact->emails()->where('is_primary', true)->count());
        $this->assertSame('Work', $contact->phones()->where('is_primary', true)->first()->label);
    }

    public function test_sync_upserts_adds_changes_primary_and_soft_deletes(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true],
            ['value' => '0822222222', 'is_primary' => false],
        ], []);

        // Re-sync: keep #1 (now non-primary), drop #2, add #3 as primary.
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => false],
            ['value' => '0833333333', 'is_primary' => true],
        ], []);

        $contact->refresh();
        $this->assertSame(2, $contact->phones()->count(), 'one removed, one added');
        $this->assertSame('0833333333', $contact->phone, 'mirror = new primary');
        $this->assertSame(1, $contact->phones()->where('is_primary', true)->count());
        $this->assertSame(1, $contact->phones()->onlyTrashed()->count(), '0822222222 soft-deleted (no hard delete)');
    }

    public function test_sync_dedupes_within_contact(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821234567', 'is_primary' => true],
            ['value' => '082 123 4567', 'is_primary' => false], // same number, formatted
        ], []);

        $contact->refresh();
        $this->assertSame(1, $contact->phones()->count(), 'duplicate identifier collapsed to one row');
    }

    public function test_sync_email_only_and_then_emptying_nulls_mirror(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [], [['value' => 'only@example.com', 'is_primary' => true]]);
        $contact->refresh();
        $this->assertNull($contact->phone);
        $this->assertSame('only@example.com', $contact->email);

        // Remove all emails → mirror nulls (controller guards "at least one"; the
        // service itself just reflects the empty set).
        $this->svc()->syncIdentifiers($contact, [], []);
        $contact->refresh();
        $this->assertNull($contact->email);
        $this->assertSame(0, $contact->emails()->count());
    }

    public function test_reverse_sync_creates_child_rows_for_a_single_field_create(): void
    {
        // Importer / single-field path: Contact::create with mirror values only.
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Single', 'last_name' => 'Field',
            'phone' => '0829990001', 'email' => 'single@example.com',
        ]);
        $contact->refresh();

        $this->assertSame(1, $contact->phones()->count(), 'primary phone child row auto-created');
        $this->assertSame(1, $contact->emails()->count(), 'primary email child row auto-created');
        $this->assertTrue($contact->phones()->first()->is_primary);
        $this->assertSame('829990001', $contact->phones()->first()->phone_normalised);

        // Idempotent: saving again creates no duplicates.
        $contact->touch();
        $this->assertSame(1, $contact->fresh()->phones()->count());
    }

    public function test_multi_identifier_dedup_matches_a_secondary(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true],
            ['value' => '0822222222', 'is_primary' => false],
        ], [['value' => 'a@example.com', 'is_primary' => true]]);

        $dups = app(ContactDuplicateService::class)->findDuplicatesForIdentifiers(
            ['0822222222'], [], null, $this->agencyId
        );
        $this->assertTrue($dups->contains('id', $contact->id), 'incoming number matching a SECONDARY finds the contact');

        // ignore-self excludes the contact (used on edit).
        $dupsSelf = app(ContactDuplicateService::class)->findDuplicatesForIdentifiers(
            ['0822222222'], [], null, $this->agencyId, $contact->id
        );
        $this->assertFalse($dupsSelf->contains('id', $contact->id));
    }

    /**
     * Contact-details Phase 1 — "agent could not load a USA number". A US
     * number defaults to ZA/+27 when no country_iso is posted (pre-Phase-1
     * behaviour: every existing caller that doesn't yet know about the field),
     * proving new writers are never broken by the new columns.
     */
    public function test_sync_persists_default_za_dial_code_when_not_specified(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821234567', 'is_primary' => true],
        ], []);

        $phone = $contact->refresh()->phones()->first();
        $this->assertSame('ZA', $phone->country_iso);
        $this->assertSame('+27', $phone->dial_code);
    }

    /** A US number posted with country_iso=US persists its own dial code. */
    public function test_sync_persists_a_non_za_country_and_dial_code(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '+1 415 555 2671', 'is_primary' => true, 'country_iso' => 'US', 'dial_code' => '+1'],
        ], []);

        $phone = $contact->refresh()->phones()->first();
        $this->assertSame('US', $phone->country_iso);
        $this->assertSame('+1', $phone->dial_code);
        $this->assertSame('+1 415 555 2671', $contact->phone, 'mirror keeps the raw international value');
    }

    /**
     * THE BUG: before Phase 1, normalizePhone() collapsed EVERY number to its
     * last 9 digits. A US number and an unrelated ZA number sharing those last
     * 9 digits would resolve to the SAME dedup key — a false "duplicate" that
     * silently redirected the agent to the wrong contact (or, depending on the
     * agency's duplicate_mode, blocked the save outright). This proves it no
     * longer happens: two numbers that only match on their last 9 digits are
     * no longer treated as the same identifier.
     */
    public function test_international_number_no_longer_collides_on_last_nine_digits(): void
    {
        $za = $this->contact();
        $this->svc()->syncIdentifiers($za, [
            ['value' => '0155552671', 'is_primary' => true], // ZA local number ending ...155552671
        ], []);

        $us = $this->contact();
        $this->svc()->syncIdentifiers($us, [
            // Same trailing 9 digits as the ZA number above, but a real US number.
            ['value' => '+1 415 555 2671', 'is_primary' => true, 'country_iso' => 'US', 'dial_code' => '+1'],
        ], []);

        $zaKey = $za->refresh()->phones()->first()->phone_normalised;
        $usKey = $us->refresh()->phones()->first()->phone_normalised;

        $this->assertSame('155552671', $zaKey, 'ZA normalisation is UNCHANGED — last-9 core');
        $this->assertSame('14155552671', $usKey, 'US number keeps its full digits, no collapse');
        $this->assertNotSame($zaKey, $usKey, 'the two contacts no longer share a dedup key');

        // findDuplicatesForIdentifiers must not treat the US contact as a dupe
        // of the ZA one just because their last 9 digits used to match.
        $dups = app(ContactDuplicateService::class)->findDuplicatesForIdentifiers(
            ['+1 415 555 2671'], [], null, $this->agencyId
        );
        $this->assertFalse($dups->contains('id', $za->id), 'no false-positive collision with the ZA contact');
    }

    /** ZA behaviour is byte-identical after the Phase 1 normalizer change. */
    public function test_za_normalisation_unchanged_after_international_fix(): void
    {
        $svc = app(ContactDuplicateService::class);
        $this->assertSame('821234567', $svc->normalizePhone('082 123 4567'));
        $this->assertSame('821234567', $svc->normalizePhone('+27 82 123 4567'));
        $this->assertSame('821234567', $svc->normalizePhone('27821234567'));
        $this->assertSame('821234567', $svc->normalizePhone('821234567'));
    }

    /** Non-ZA numbers keep their full digits — no last-9 collapse. */
    public function test_non_za_normalisation_keeps_full_digits(): void
    {
        $svc = app(ContactDuplicateService::class);
        $this->assertSame('14155552671', $svc->normalizePhone('+1 415 555 2671'));
        $this->assertSame('14155552671', $svc->normalizePhone('1 (415) 555-2671'));
        $this->assertSame('447911123456', $svc->normalizePhone('+44 7911 123456'));
    }
}
