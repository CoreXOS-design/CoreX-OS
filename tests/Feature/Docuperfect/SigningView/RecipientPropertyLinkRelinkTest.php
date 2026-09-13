<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pipeline-adjacent test (ESignWizardController is not on CLAUDE.md's
 * pipeline-gate list, but this write path is load-bearing regardless) for
 * the contact_property hard-delete fix.
 *
 * saveStep()'s recipients-step auto-link block (e.g. "Johan, 2026-08-26 —
 * property 6060, Piet Begrafnis wrongly linked as Owner") can match an
 * EXISTING contact via legacy duplicate-detection and link them to the
 * property. That matching logic is NOT what changed and cannot break —
 * this test does not route through it. What changed is the write itself,
 * extracted into ESignWizardController::linkRecipientToProperty(), tested
 * directly: does it restore a previously-soft-deleted link instead of
 * colliding with it. Full investigation: .ai/specs/rental-applications.md,
 * "The contact_property hard-delete fix".
 */
final class RecipientPropertyLinkRelinkTest extends TestCase
{
    use RefreshDatabase;

    private function invokeLink(int $contactId, int $propertyId, string $role): void
    {
        $method = new ReflectionMethod(ESignWizardController::class, 'linkRecipientToProperty');
        $method->setAccessible(true);
        $method->invoke(app(ESignWizardController::class), $contactId, $propertyId, $role);
    }

    public function test_relinks_a_previously_unlinked_contact_instead_of_colliding(): void
    {
        $this->withoutVite();
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ramsgate']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'House in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'sale',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '1 Test Road',
        ]);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'email' => 'sipho-esign@example.co.za', 'phone' => '0821234567',
        ]);

        // Previously linked to this exact property, then unlinked — a
        // real, pre-existing state once unlink is soft-delete everywhere.
        \App\Services\Property\ContactPropertyLinker::link($contact->id, $property->id, 'owner');
        \App\Services\Property\ContactPropertyLinker::unlink($contact->id, $property->id);
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertNotNull(ContactProperty::onlyTrashed()->first());

        $this->invokeLink($contact->id, $property->id, 'seller');

        // Restored the one existing row, not a duplicate.
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertSame(
            1,
            ContactProperty::withTrashed()->where('contact_id', $contact->id)->where('property_id', $property->id)->count()
        );
        $this->assertTrue(
            $contact->properties()->where('properties.id', $property->id)->wherePivot('role', 'owner')->exists()
        );
    }

    public function test_a_genuinely_new_link_creates_exactly_one_row(): void
    {
        $this->withoutVite();
        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ramsgate']);
        $agent = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id, 'agent_id' => $agent->id,
            'title' => 'House in Ramsgate', 'status' => 'active', 'property_type' => 'house', 'listing_type' => 'rental',
            'suburb' => 'Ramsgate', 'city' => 'Margate', 'province' => 'KwaZulu-Natal', 'address' => '2 Test Road',
        ]);
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'New', 'last_name' => 'Recipient', 'email' => 'new-recipient@example.co.za', 'phone' => '0827654321',
        ]);

        $this->invokeLink($contact->id, $property->id, 'tenant');

        $this->assertDatabaseCount('contact_property', 1);
        $this->assertTrue(
            $contact->properties()->where('properties.id', $property->id)->wherePivot('role', 'tenant')->exists()
        );
    }
}
