<?php

declare(strict_types=1);

namespace Tests\Feature\RentalApplications;

use App\Mail\RentalApplicationInviteMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApplication;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Johan, QA1 21 Oct 2026: "a rental application does not want to send whenever a PROPERTY is selected". The server side was proven fine
 * (create + send with a property, for every kind of rental property on QA1, answers like the no-property case). The real cause was in the
 * browser: the "Link a property" search box sits INSIDE the application form, and that form marks itself edited on every input event that
 * reaches it - so just typing in the property search greyed Send out ("Save your changes first") and linking the property did not release it.
 * The control now stops its own input/change events at its wrapper. (A real-browser proof on QA1 is in the commit message.)
 */
final class RentalApplicationPropertySendTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private Agency $agency;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->agency = Agency::create(['name' => 'Test Agency', 'slug' => 'ta-' . uniqid()]);
        $this->branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->agent = User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'admin']);
    }

    private function rentalProperty(string $status = 'to_let'): Property
    {
        return Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => '12 Marine Drive', 'address' => '12 Marine Drive', 'status' => $status, 'listing_type' => 'rental',
        ]);
    }

    private function contact(): Contact
    {
        return Contact::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id,
            'first_name' => 'Pat', 'last_name' => 'Applicant', 'email' => 'pat-' . uniqid() . '@example.com',
        ]);
    }

    public function test_create_and_send_with_a_property_works_like_without_one_for_any_rental_status(): void
    {
        foreach ([null, 'to_let', 'let_out', 'active'] as $status) {
            Mail::fake();
            $contact = $this->contact();
            $payload = ['contact_id' => $contact->id] + ($status ? ['property_id' => $this->rentalProperty($status)->id] : []);

            $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), $payload)->assertSessionHasNoErrors();
            $app = RentalApplication::where('contact_id', $contact->id)->firstOrFail();
            $this->assertSame($status !== null, $app->property_id !== null, "property linked as asked ({$status})");

            $this->actingAs($this->agent)->post(route('corex.rental-applications.send', $app))
                ->assertRedirect(route('corex.rental-applications.index'))
                ->assertSessionHas('success');
            Mail::assertQueued(RentalApplicationInviteMail::class, fn ($m) => $m->hasTo($contact->email));
            $this->assertSame('sent', $app->fresh()->status, "status moved to sent ({$status})");
        }
    }

    public function test_a_property_that_may_not_be_linked_says_so_on_the_create_screen(): void
    {
        $sale = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->agent->id, 'branch_id' => $this->branch->id,
            'title' => 'For sale', 'address' => '1 Sale Road', 'status' => 'active', 'listing_type' => 'sale',
        ]);

        $this->actingAs($this->agent)->post(route('corex.rental-applications.store'), ['contact_id' => $this->contact()->id, 'property_id' => $sale->id])
            ->assertSessionHasErrors('property_id');
        $this->assertStringContainsString("isn't available to link", session('errors')->first('property_id'));
    }

    public function test_the_property_link_control_keeps_its_typing_away_from_the_forms_edited_flag(): void
    {
        $contact = $this->contact();
        $app = RentalApplication::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'contact_id' => $contact->id, 'property_id' => $this->rentalProperty()->id,
            'created_by_user_id' => $this->agent->id, 'status' => 'draft', 'token' => 'tok-' . uniqid(), 'token_expires_at' => now()->addDays(14),
        ]);

        $html = $this->actingAs($this->agent)->get(route('corex.rental-applications.show', $app))->assertOk()->getContent();

        // the big form still tracks real edits ...
        $this->assertStringContainsString('@input="dirty = true" @change="dirty = true"', $html);
        // ... but the property link control (inside it) stops its own input/change events at its wrapper
        $this->assertMatchesRegularExpression('#<div[^>]*data-qa="application-property-link"[^>]*@input\.stop[^>]*@change\.stop#s', $html);
    }
}
