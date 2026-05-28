<?php

declare(strict_types=1);

namespace Tests\Feature\SellerOutreach;

use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase B Fix — regression coverage for EntryPointController::storeFromProspecting.
 *
 * Backstory: commit ea2b0295 (2026-05-25, "A.2.5 quick-create ID number field
 * with POPIA audit") wrapped the existing Contact::create([...]) call in
 * array_filter(..., fn($v) => $v !== null && $v !== ''). That stripped empty
 * `last_name` and null `phone` from the create payload, hitting the schema's
 * NOT-NULL constraints on those columns. Result: every Pitch Now submit
 * where the agent skipped last_name (or used email-only contact) 500'd
 * with SQLSTATE 1364 — silently broke the entire downstream chain
 * (Property promotion, TP linkage, pivot row, composer redirect, claim
 * conversion, 48h feedback obligation).
 *
 * Pre-A.2.5 tests in QuickCreateIdNumberTest always submitted last_name —
 * the empty-last_name path was never exercised. These tests close that gap.
 */
final class EntryPointStoreFromProspectingTest extends TestCase
{
    use RefreshDatabase;

    public function test_post_succeeds_with_only_first_name_and_phone(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedProspectingListing($agencyId, [
            'address' => '14 Empty Lastname Street',
            'suburb'  => 'Margate',
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->post(
                route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]),
                [
                    'first_name' => 'Phoneonly',
                    // NO last_name — the agent's actual shortcut path.
                    'phone'      => '0821234567',
                    // NO email, NO id_number.
                ],
            );

        $resp->assertStatus(302);
        $resp->assertRedirectContains('/outreach/compose');

        // Contact must exist, with last_name stored as empty string (the
        // contacts.last_name column is NOT NULL with no default; the fix
        // routes '' through array_merge so it survives the create call).
        $contact = Contact::where('first_name', 'Phoneonly')->first();
        $this->assertNotNull($contact, 'Contact must be created when only first_name + phone provided');
        $this->assertSame('', (string) $contact->last_name);
        $this->assertSame('0821234567', $contact->phone);
        $this->assertSame($agencyId, (int) $contact->agency_id);

        // Property must also be created (the same transaction that creates
        // the contact promotes the listing to a Property).
        $matchedPropertyId = DB::table('prospecting_listings')
            ->where('id', $listingId)
            ->value('matched_property_id');
        $this->assertNotNull($matchedPropertyId, 'Listing must be matched to a promoted Property');
        $this->assertDatabaseHas('properties', ['id' => $matchedPropertyId]);

        // Contact ↔ property pivot must be written with role=seller.
        $this->assertDatabaseHas('contact_property', [
            'contact_id'  => $contact->id,
            'property_id' => $matchedPropertyId,
            'role'        => 'seller',
        ]);
    }

    public function test_post_succeeds_with_only_first_name_and_email(): void
    {
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedProspectingListing($agencyId, [
            'address' => '7 Email Only Avenue',
            'suburb'  => 'Margate',
        ]);

        $resp = $this->actingAs(User::find($userId))
            ->post(
                route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]),
                [
                    'first_name' => 'Emailonly',
                    // NO last_name, NO phone — email-only contact.
                    'email'      => 'emailonly@test.example',
                ],
            );

        $resp->assertStatus(302);
        $resp->assertRedirectContains('/outreach/compose');

        // contacts.phone is NOT NULL in the schema (pre-existing latent bug
        // independent of the array_filter regression). Decision: default
        // empty phone to '' rather than make the column nullable. Rationale:
        // (a) mirrors the existing last_name='' pattern that's been in the
        // codebase since the contact-capture flow shipped; (b) keeps the
        // schema unchanged so a broad column-nullability change doesn't
        // cascade into Composer / SellerOutreachSender / wa.me URL builder
        // assumptions; (c) downstream code (recipient_phone_snapshot on
        // SellerOutreachSend, whatsappUrl validation) already handles
        // empty/missing phone defensively.
        $contact = Contact::where('first_name', 'Emailonly')->first();
        $this->assertNotNull($contact);
        $this->assertSame('emailonly@test.example', $contact->email);
        $this->assertSame('', (string) $contact->phone);
        $this->assertSame('', (string) $contact->last_name);
    }

    public function test_post_succeeds_with_all_fields_filled(): void
    {
        // Baseline — make sure the array_merge fix didn't break the happy
        // path the existing tests already validated.
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedProspectingListing($agencyId, [
            'address' => '21 Complete Form Road',
            'suburb'  => 'Uvongo',
        ]);

        $this->actingAs(User::find($userId))
            ->post(
                route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]),
                [
                    'first_name' => 'Filled',
                    'last_name'  => 'Out',
                    'phone'      => '0827654321',
                    'email'      => 'filled@test.example',
                ],
            )
            ->assertStatus(302)
            ->assertRedirectContains('/outreach/compose');

        $contact = Contact::where('first_name', 'Filled')->first();
        $this->assertNotNull($contact);
        $this->assertSame('Out', $contact->last_name);
        $this->assertSame('0827654321', $contact->phone);
        $this->assertSame('filled@test.example', $contact->email);
    }

    public function test_post_still_requires_phone_or_email(): void
    {
        // The array_merge fix preserves the "phone OR email required"
        // pre-create guard — neither submitted means the form bounces
        // back with errors, not a 500.
        [$agencyId, $userId] = $this->seedAgency();
        $listingId = $this->seedProspectingListing($agencyId, [
            'address' => '99 Neither Provided Lane',
        ]);

        $this->actingAs(User::find($userId))
            ->post(
                route('seller-outreach.entry.store-from-prospecting', ['prospectingListingId' => $listingId]),
                [
                    'first_name' => 'NoContact',
                    // Neither phone nor email — the controller guard rejects.
                ],
            )
            ->assertSessionHasErrors('contact_required');

        $this->assertSame(0, Contact::where('first_name', 'NoContact')->count());
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:int} */
    private function seedAgency(): array
    {
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6),
            'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $agencyId, 'agency_id' => $agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // promoteListingToProperty() needs an agency-level admin/agent to
        // assign the promoted Property to, since super_admin is rejected as
        // a Property agent. Seed an agent alongside the super_admin so the
        // fallback in resolvePromotionAgentId() resolves cleanly.
        User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId,
            'role' => 'agent', 'name' => 'Agency Agent',
        ]);
        $user = User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => 'super_admin',
        ]);
        return [$agencyId, $user->id];
    }

    private function seedProspectingListing(int $agencyId, array $overrides): int
    {
        $defaultCapturedBy = (int) DB::table('users')
            ->where('agency_id', $agencyId)
            ->orderBy('id')
            ->value('id');

        return (int) DB::table('prospecting_listings')->insertGetId(array_merge([
            'agency_id'           => $agencyId,
            'portal_source'       => 'p24',
            'portal_ref'          => 'test-' . Str::random(10),
            'portal_url'          => 'https://example.test/' . Str::random(6),
            'captured_by_user_id' => $defaultCapturedBy,
            'is_active'           => true,
            'address'             => '99 Unknown',
            'suburb'              => 'Unknown',
            'price'               => 0,
            'first_seen_at'       => now(),
            'last_seen_at'        => now(),
            'created_at'          => now(),
            'updated_at'          => now(),
        ], $overrides));
    }
}
