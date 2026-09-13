<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\ContactProperty;
use App\Models\Docuperfect\Flow;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Pipeline-gate test (CLAUDE.md — ESignWizardController is on the pipeline
 * file list) for the contact_property hard-delete fix: saveStep()'s
 * recipients-step auto-link block (Contact -> Property, e.g. "Johan,
 * 2026-08-26 — property 6060, Piet Begrafnis wrongly linked as Owner")
 * matched an EXISTING contact by email/id_number and linked them to the
 * property via a bare syncWithoutDetaching(). If that contact had
 * previously been linked to this exact property and later unlinked
 * (soft-deleted, per the hard-delete fix), a bare sync would blind-insert
 * and collide with the unique index. Full investigation:
 * .ai/specs/rental-applications.md, "The contact_property hard-delete fix".
 */
final class RecipientPropertyLinkRelinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_the_recipients_step_relinks_a_previously_unlinked_matched_contact(): void
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
        // id_number stored WITH separators, matched by the recipient step's
        // duplicate-detection using a DIGITS-ONLY value — this is what
        // actually routes through the auto-link branch that hits the
        // property link (an exact-string id_number check earlier in
        // resolveContact() would short-circuit before reaching it if the
        // two forms matched literally, which they deliberately don't here).
        $contact = Contact::create([
            'agency_id' => $agency->id, 'branch_id' => $branch->id,
            'first_name' => 'Sipho', 'last_name' => 'Ndlovu', 'id_number' => '800101-5000-08', 'phone' => '0821234567',
        ]);

        // Previously linked to this exact property, then unlinked — a real,
        // pre-existing state once unlink is soft-delete everywhere.
        \App\Services\Property\ContactPropertyLinker::link($contact->id, $property->id, 'owner');
        \App\Services\Property\ContactPropertyLinker::unlink($contact->id, $property->id);
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertNotNull(ContactProperty::onlyTrashed()->first());

        $flow = Flow::create([
            'type' => 'esign',
            'user_id' => $agent->id,
            'property_id' => $property->id,
            'status' => 'draft',
            'current_step' => 3,
            'step_data' => [
                'property' => ['property_id' => $property->id, '_property_source' => 'properties'],
            ],
        ]);

        $this->actingAs($agent);
        $request = Request::create('/x', 'POST', [], [], [], [], json_encode([
            'data' => [
                'recipients' => [
                    [
                        'name' => 'Sipho Ndlovu',
                        'email' => '',
                        'id_number' => '8001015000008',
                        'role' => 'seller',
                        '_contact_id' => null,
                    ],
                ],
            ],
        ]));
        $request->headers->set('CONTENT_TYPE', 'application/json');
        $request->setUserResolver(fn () => $agent);

        $method = new ReflectionMethod(ESignWizardController::class, 'saveStep');
        $method->setAccessible(true);
        $method->invoke(app(ESignWizardController::class), $request, $flow->id, 3);

        // Restored the one existing row, not a duplicate, and no swallowed
        // duplicate-key exception (the invoke() above would have thrown).
        $this->assertDatabaseCount('contact_property', 1);
        $this->assertTrue(
            $contact->properties()->where('properties.id', $property->id)->exists()
        );
    }
}
