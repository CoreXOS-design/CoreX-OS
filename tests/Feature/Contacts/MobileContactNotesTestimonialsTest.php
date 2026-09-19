<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\ContactTestimonial;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Mobile agent-facing CRUD for a contact's Notes & Testimonials — same
 * tables/validation/authorization as the web tab, so writes from either
 * client are visible on the other. Spec: .ai/specs/contact-notes-testimonials.md
 */
final class MobileContactNotesTestimonialsTest extends TestCase
{
    use RefreshDatabase;

    // ── Notes ──────────────────────────────────────────────────────────

    public function test_note_round_trips_create_list_update_delete(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        Sanctum::actingAs($agent);

        $created = $this->postJson(route('v1.mobile.contacts.notes.store', $contact), [
            'type' => 'Contacted',
            'body' => 'Called the seller',
        ])->assertCreated()->json('note');

        $this->assertDatabaseHas('contact_notes', [
            'id' => $created['id'], 'contact_id' => $contact->id, 'type' => 'Contacted',
        ]);

        $this->getJson(route('v1.mobile.contacts.notes.index', $contact))
            ->assertOk()
            ->assertJsonFragment(['id' => $created['id'], 'body' => 'Called the seller']);

        $this->putJson(route('v1.mobile.contacts.notes.update', [$contact, $created['id']]), [
            'body' => 'Called the seller, follow up Monday',
        ])->assertOk()->assertJsonPath('note.body', 'Called the seller, follow up Monday');

        // Body-only update must not clear the quick-pick type set at creation.
        $this->assertDatabaseHas('contact_notes', ['id' => $created['id'], 'type' => 'Contacted']);

        $this->deleteJson(route('v1.mobile.contacts.notes.destroy', [$contact, $created['id']]))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSoftDeleted('contact_notes', ['id' => $created['id']]);
    }

    public function test_note_created_on_mobile_is_visible_on_web(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        Sanctum::actingAs($agent);

        $this->postJson(route('v1.mobile.contacts.notes.store', $contact), ['body' => 'From the phone'])
            ->assertCreated();

        $this->actingAs($agent)
            ->get(route('corex.contacts.show', $contact))
            ->assertOk()
            ->assertSee('From the phone');
    }

    public function test_cannot_write_a_note_on_a_contact_outside_visibility_scope(): void
    {
        [, $agent] = $this->seedFixture();
        $foreignAgencyId = $this->makeAgency();
        $foreignContact = $this->makeContact($foreignAgencyId, $this->makeUser($foreignAgencyId, 'agent')->id);
        Sanctum::actingAs($agent);

        // The global ContactScope filters a foreign-agency contact out of route-model
        // binding entirely, so this never reaches the controller's own authorization
        // check — it 404s, not 403s. Same behavior as MobileContactController::show().
        $this->postJson(route('v1.mobile.contacts.notes.store', $foreignContact), ['body' => 'Should not land'])
            ->assertNotFound();

        $this->assertDatabaseMissing('contact_notes', ['body' => 'Should not land']);
    }

    // ── Testimonials ──────────────────────────────────────────────────

    public function test_testimonial_round_trips_create_list_update_delete(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        Sanctum::actingAs($agent);

        $created = $this->postJson(route('v1.mobile.contacts.testimonials.store', $contact), [
            'body' => 'Great service from start to finish.',
            'rating' => 5,
        ])->assertCreated()->json('testimonial');

        $this->assertSame(false, $created['published'], 'capture must never auto-publish');

        $this->getJson(route('v1.mobile.contacts.testimonials.index', $contact))
            ->assertOk()
            ->assertJsonFragment(['id' => $created['id']]);

        $this->putJson(route('v1.mobile.contacts.testimonials.update', [$contact, $created['id']]), [
            'body' => 'Great service from start to finish, would recommend.',
            'rating' => 5,
        ])->assertOk()->assertJsonPath('testimonial.body', 'Great service from start to finish, would recommend.');

        $this->deleteJson(route('v1.mobile.contacts.testimonials.destroy', [$contact, $created['id']]))
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSoftDeleted('contact_testimonials', ['id' => $created['id']]);
    }

    public function test_testimonial_agent_id_outside_contacts_agency_falls_back_to_capturing_user(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        $foreignAgent = $this->makeUser($this->makeAgency(), 'agent');
        Sanctum::actingAs($agent);

        $testimonial = $this->postJson(route('v1.mobile.contacts.testimonials.store', $contact), [
            'body' => 'Cross-tenant tagging must not be allowed.',
            'agent_id' => $foreignAgent->id,
        ])->assertCreated()->json('testimonial');

        $this->assertSame($agent->id, $testimonial['agent_id']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────

    /** @return array{0:int,1:User} */
    private function seedFixture(): array
    {
        $agencyId = $this->makeAgency();
        $agent = $this->makeUser($agencyId, 'agent');

        return [$agencyId, $agent];
    }

    private function makeAgency(): int
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
        return $agencyId;
    }

    private function makeUser(int $agencyId, string $role): User
    {
        return User::factory()->create([
            'agency_id' => $agencyId, 'branch_id' => $agencyId, 'role' => $role,
        ]);
    }

    private function makeContact(int $agencyId, int $createdBy): Contact
    {
        return Contact::withoutGlobalScopes()->create([
            'agency_id' => $agencyId,
            'branch_id' => $agencyId,
            'created_by_user_id' => $createdBy,
            'agent_id'  => $createdBy,
            'first_name' => 'Sam',
            'last_name' => 'Buyer',
            'phone' => '08255' . random_int(10000, 99999),
        ]);
    }
}
