<?php

declare(strict_types=1);

namespace Tests\Feature\Contacts;

use App\Models\Contact;
use App\Models\ContactNote;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Web note editing — new capability added alongside the mobile API
 * (Johan, 2026-09-08: "add editing everywhere"). Notes previously had no
 * update route at all (add + delete only). See
 * .ai/specs/contact-notes-testimonials.md §1.
 */
final class ContactNoteUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_agent_can_edit_own_note_body(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        $note = $this->makeNote($agencyId, $contact->id, $agent->id, 'Original text');

        $this->actingAs($agent)
            ->put(route('corex.contacts.notes.update', [$contact, $note]), ['body' => 'Edited text'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Edited text', $note->fresh()->body);
    }

    public function test_edit_from_the_contact_page_body_only_form_does_not_clear_existing_type(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        $note = $this->makeNote($agencyId, $contact->id, $agent->id, 'Called client', 'Contacted');

        // The contact page's edit form only ever posts `body` — no `type` field.
        $this->actingAs($agent)
            ->put(route('corex.contacts.notes.update', [$contact, $note]), ['body' => 'Called client again'])
            ->assertSessionHasNoErrors();

        $fresh = $note->fresh();
        $this->assertSame('Called client again', $fresh->body);
        $this->assertSame('Contacted', $fresh->type, 'existing quick-pick type must survive a body-only edit');
    }

    public function test_cannot_edit_a_note_belonging_to_a_different_contact(): void
    {
        [$agencyId, $agent] = $this->seedFixture();
        $contact = $this->makeContact($agencyId, $agent->id);
        $otherContact = $this->makeContact($agencyId, $agent->id);
        $note = $this->makeNote($agencyId, $otherContact->id, $agent->id, 'Belongs elsewhere');

        $this->actingAs($agent)
            ->put(route('corex.contacts.notes.update', [$contact, $note]), ['body' => 'Hijacked'])
            ->assertNotFound();

        $this->assertSame('Belongs elsewhere', $note->fresh()->body);
    }

    // ── Helpers (mirrors ContactAgentAssignmentTest's fixtures) ──────────

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

    private function makeNote(int $agencyId, int $contactId, int $userId, string $body, ?string $type = null): ContactNote
    {
        return ContactNote::create([
            'agency_id'  => $agencyId,
            'contact_id' => $contactId,
            'user_id'    => $userId,
            'type'       => $type,
            'body'       => $body,
        ]);
    }
}
