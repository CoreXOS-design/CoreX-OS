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

    /**
     * Contact-details Phase 2 — a managed label persists on BOTH a phone and
     * an email row via the same shared list, and the legacy string `label`
     * mirror stays in sync with whichever label is selected.
     */
    public function test_sync_persists_a_managed_label_on_phone_and_email(): void
    {
        $label = \App\Models\ContactIdentifierLabel::create([
            'agency_id' => $this->agencyId, 'name' => 'Personal', 'sort_order' => 0,
        ]);

        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true, 'label_id' => $label->id],
        ], [
            ['value' => 'a@example.com', 'is_primary' => true, 'label_id' => $label->id],
        ]);

        $contact->refresh();
        $phone = $contact->phones()->first();
        $email = $contact->emails()->first();
        $this->assertSame($label->id, $phone->contact_identifier_label_id);
        $this->assertSame('Personal', $phone->label, 'string mirror kept in sync with the selected label');
        $this->assertSame($label->id, $email->contact_identifier_label_id);
        $this->assertSame('Personal', $email->label);
    }

    /** A contact with several numbers can label EACH one independently. */
    public function test_a_contact_with_multiple_numbers_can_label_each(): void
    {
        $personal = \App\Models\ContactIdentifierLabel::create(['agency_id' => $this->agencyId, 'name' => 'Personal']);
        $business = \App\Models\ContactIdentifierLabel::create(['agency_id' => $this->agencyId, 'name' => 'Business']);

        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true,  'label_id' => $personal->id],
            ['value' => '0822222222', 'is_primary' => false, 'label_id' => $business->id],
            ['value' => '0823333333', 'is_primary' => false, 'label_id' => null], // no label
        ], []);

        $contact->refresh();
        $byPhone = $contact->phones()->get()->keyBy('phone');
        $this->assertSame($personal->id, $byPhone['0821111111']->contact_identifier_label_id);
        $this->assertSame($business->id, $byPhone['0822222222']->contact_identifier_label_id);
        $this->assertNull($byPhone['0823333333']->contact_identifier_label_id);
    }

    /** Existing (pre-Phase-2) identifier rows are unaffected — label stays NULL until re-picked. */
    public function test_existing_data_unaffected_by_the_label_feature(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true], // no label_id key at all — simulates pre-Phase-2 caller
        ], []);

        $phone = $contact->refresh()->phones()->first();
        $this->assertNull($phone->contact_identifier_label_id);
        $this->assertSame('0821111111', $phone->phone, 'number itself untouched');
    }

    /**
     * Contact-details Phase 3 — a single WhatsApp-flagged number is
     * unambiguously the primary WhatsApp number, even without an explicit
     * is_primary_whatsapp flag. Primary contact and primary WhatsApp can be
     * DIFFERENT numbers (the whole point of the split).
     */
    public function test_single_whatsapp_number_becomes_primary_whatsapp_automatically(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true, 'is_whatsapp' => false], // office line, primary contact
            ['value' => '0822222222', 'is_primary' => false, 'is_whatsapp' => true],  // personal cell, WhatsApp
        ], []);

        $contact->refresh();
        $this->assertSame('0821111111', $contact->primaryPhone->phone, 'primary CONTACT number unchanged');
        $this->assertSame('0822222222', $contact->primaryWhatsAppPhone->phone, 'primary WHATSAPP is the OTHER number');
        $this->assertSame('0822222222', $contact->whatsAppPhone()->phone);
    }

    /** With 2+ WhatsApp-flagged numbers, the explicit is_primary_whatsapp flag wins. */
    public function test_explicit_primary_whatsapp_wins_among_multiple_whatsapp_numbers(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true, 'is_whatsapp' => true, 'is_primary_whatsapp' => false],
            ['value' => '0822222222', 'is_primary' => false, 'is_whatsapp' => true, 'is_primary_whatsapp' => true],
        ], []);

        $contact->refresh();
        $this->assertSame('0822222222', $contact->primaryWhatsAppPhone->phone);
        $this->assertFalse($contact->phones()->where('phone', '0821111111')->first()->is_primary_whatsapp);
    }

    /** No number flagged is_whatsapp → no primary-WhatsApp designation (normal, common state). */
    public function test_no_whatsapp_flag_means_no_primary_whatsapp_designation(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true],
        ], []);

        $contact->refresh();
        $this->assertNull($contact->primaryWhatsAppPhone);
        $this->assertSame('0821111111', $contact->whatsAppPhone()->phone, 'falls back to primary contact number');
    }

    /** Re-syncing without any is_whatsapp flag clears a stale prior designation. */
    public function test_removing_whatsapp_flag_on_resync_clears_primary_whatsapp(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true, 'is_whatsapp' => true],
        ], []);
        $this->assertNotNull($contact->refresh()->primaryWhatsAppPhone);

        // Re-save with the WhatsApp checkbox now unticked.
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '0821111111', 'is_primary' => true, 'is_whatsapp' => false],
        ], []);

        $this->assertNull($contact->refresh()->primaryWhatsAppPhone);
    }

    /**
     * REGRESSION — caught by the QA1 demo contact: a direct syncIdentifiers()
     * caller (API/importer/console — anything bypassing ContactController)
     * that supplies country_iso='US' WITHOUT also supplying dial_code used to
     * persist dial_code='+27' (the DEFAULT, never resolved from the ISO) —
     * country_iso and dial_code silently disagreed. dial_code is now ALWAYS
     * derived from country_iso inside the service (resolveCountry()), so a
     * caller that only knows the ISO still gets the correct pair.
     */
    public function test_dial_code_is_derived_from_country_iso_even_without_being_posted(): void
    {
        $contact = $this->contact();
        $this->svc()->syncIdentifiers($contact, [
            ['value' => '+1 415 555 2671', 'is_primary' => true, 'country_iso' => 'US'], // no dial_code key at all
        ], []);

        $phone = $contact->refresh()->phones()->first();
        $this->assertSame('US', $phone->country_iso);
        $this->assertSame('+1', $phone->dial_code, 'derived from country_iso, not left at a stale/default value');
    }
}
