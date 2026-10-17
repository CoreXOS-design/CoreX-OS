<?php

declare(strict_types=1);

namespace Tests\Feature\Properties;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Property;
use App\Models\PropertyNote;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Property page, Notes tab. Johan 2026-10-18: "the note buttons needs to be fixed - Add Note".
 *
 * Root cause: the controller answered with back()->with('tab', 'notes'). The page opens on
 * `request('tab') ?? session('tab')`, so an agent who arrived on a deep link such as ?tab=contacts was sent back
 * to that same URL, the ?tab=contacts in it beat the flashed 'notes', and the page reopened on Contacts: the note
 * was saved but never shown, which reads as "Add Note does nothing". The redirect now carries ?tab=notes itself.
 */
final class PropertyNoteRedirectTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private Branch $branch;
    private User $user;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();
        PermissionService::clearCache();
        $this->agency = Agency::create(['name' => 'Notes Agency', 'slug' => 'notes-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
        $this->property = Property::create([
            'title' => 'Notes property', 'agency_id' => $this->agency->id, 'agent_id' => $this->user->id,
            'branch_id' => $this->branch->id, 'status' => 'active', 'listing_type' => 'sale',
        ]);
    }

    private function showUrl(array $query = []): string
    {
        return route('corex.properties.show', ['property' => $this->property] + $query);
    }

    public function test_add_note_from_a_deep_linked_tab_lands_on_the_notes_tab_and_shows_the_note(): void
    {
        $response = $this->actingAs($this->user)
            ->from($this->showUrl(['tab' => 'contacts']))
            ->post(route('corex.properties.notes.store', $this->property), ['content' => 'Gate code is 1234']);

        $response->assertRedirect($this->showUrl(['tab' => 'notes']));
        $this->assertDatabaseHas('property_notes', ['property_id' => $this->property->id, 'content' => 'Gate code is 1234']);

        $page = $this->actingAs($this->user)->get($this->showUrl(['tab' => 'notes']));
        $page->assertOk();
        $page->assertSee("activeTab: 'notes'", false);
        $page->assertSee('Gate code is 1234');
    }

    public function test_add_note_from_the_plain_page_url_also_lands_on_notes(): void
    {
        $this->actingAs($this->user)
            ->from($this->showUrl())
            ->post(route('corex.properties.notes.store', $this->property), ['content' => 'Plain url note'])
            ->assertRedirect($this->showUrl(['tab' => 'notes']))
            ->assertSessionHas('success', 'Note added.');
    }

    public function test_an_empty_note_is_rejected_and_nothing_is_saved(): void
    {
        $this->actingAs($this->user)
            ->from($this->showUrl(['tab' => 'notes']))
            ->post(route('corex.properties.notes.store', $this->property), ['content' => ''])
            ->assertSessionHasErrors('content');

        $this->assertDatabaseCount('property_notes', 0);
    }

    public function test_delete_note_from_a_deep_linked_tab_lands_on_the_notes_tab(): void
    {
        $note = PropertyNote::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id,
            'user_id' => $this->user->id, 'content' => 'To be removed',
        ]);

        $this->actingAs($this->user)
            ->from($this->showUrl(['tab' => 'history']))
            ->delete(route('corex.properties.notes.destroy', [$this->property, $note]))
            ->assertRedirect($this->showUrl(['tab' => 'notes']))
            ->assertSessionHas('success', 'Note deleted.');

        $this->assertSoftDeleted('property_notes', ['id' => $note->id]);
    }
}
