<?php

namespace Tests\Feature\Api\Client;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\ContactTestimonial;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Notifications\PillarEventNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Feature tests for the client-side testimonial API
 * (POST/GET /api/v1/client/testimonials).
 *
 * Spec: .ai/specs/testimonials.md §13.
 *
 * Same DB-test caveat as the other client tests — needs the test DB up
 * (MySQL/MariaDB on PATH, see CLAUDE.md non-negotiable #12a).
 */
class ClientTestimonialTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $name = 'Agency A'): Agency
    {
        $agency = Agency::create(['name' => $name, 'slug' => str()->slug($name . '-' . uniqid())]);
        Branch::create([
            'agency_id' => $agency->id,
            'name'      => $name . ' Main',
            'code'      => 'MAIN-' . $agency->id,
            'is_active' => true,
        ]);
        return $agency;
    }

    private function makeAgent(Agency $agency): User
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');
        return User::query()->withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id,
            'branch_id' => $branchId,
            'name'      => 'Thandi Mbeki',
            'email'     => 'agent+' . uniqid() . '@example.com',
            'password'  => Hash::make('pw-12345678'),
            'role'      => 'agent',
            'is_active' => true,
        ]);
    }

    private function makeContact(Agency $agency, ?User $agent = null, array $overrides = []): Contact
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');
        return Contact::query()->withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id'          => $agency->id,
            'branch_id'          => $branchId,
            'first_name'         => 'Bob',
            'last_name'          => 'Buyer',
            'phone'              => '0820000000',
            'email'              => 'buyer+' . uniqid() . '@example.com',
            'created_by_user_id' => $agent?->id,
        ], $overrides));
    }

    private function authClient(Agency $agency, Contact $contact): string
    {
        $cu = ClientUser::create([
            'email'             => $contact->email,
            'password'          => Hash::make('pw-12345678'),
            'current_agency_id' => $agency->id,
        ]);
        $contact->forceFill(['client_user_id' => $cu->id])->save();
        return $cu->createToken('t', ['client'])->plainTextToken;
    }

    public function test_client_can_submit_a_testimonial_unpublished(): void
    {
        Notification::fake();

        $agency  = $this->makeAgency();
        $agent   = $this->makeAgent($agency);
        $contact = $this->makeContact($agency, $agent);
        $token   = $this->authClient($agency, $contact);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/testimonials', [
                'body'   => 'Thandi went above and beyond — found us our dream home.',
                'rating' => 5,
            ]);

        $res->assertCreated()
            ->assertJsonPath('testimonial.rating', 5)
            ->assertJsonPath('testimonial.published', false)
            ->assertJsonPath('testimonial.display_name', 'Bob Buyer');

        $row = ContactTestimonial::query()->withoutGlobalScope(AgencyScope::class)->first();
        $this->assertNotNull($row);
        $this->assertSame($contact->id, $row->contact_id);
        $this->assertSame($agency->id, $row->agency_id);
        $this->assertSame($agent->id, $row->agent_id);
        $this->assertNull($row->user_id);
        $this->assertFalse((bool) $row->published);
    }

    public function test_submitting_notifies_the_connected_agent_in_app_and_email(): void
    {
        Notification::fake();

        $agency  = $this->makeAgency();
        $agent   = $this->makeAgent($agency);
        $contact = $this->makeContact($agency, $agent);
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/testimonials', ['body' => 'Brilliant service!', 'rating' => 5])
            ->assertCreated();

        Notification::assertSentTo(
            $agent,
            PillarEventNotification::class,
            function (PillarEventNotification $n) use ($agent) {
                // Mail goes through the dedicated 'corex' mailer (mail@corexos.co.za)
                // so it delivers even where the default mailer is a sink (staging).
                $mail = $n->toMail($agent);
                $fromOk = collect($mail->from)->first() === config('mail.mailers.corex.from_address');

                return in_array('mail', $n->channels, true)
                    && in_array('database', $n->channels, true)
                    && $n->eventKey === 'contact.testimonial_submitted'
                    && $n->mailer === 'corex'
                    && $fromOk;
            }
        );

        // Regression lock: the listener must fire EXACTLY once. It is wired by
        // Laravel auto-discovery only — adding an explicit Event::listen in
        // AppServiceProvider double-registers it and sends two emails.
        Notification::assertSentToTimes($agent, PillarEventNotification::class, 1);
    }

    public function test_body_is_required(): void
    {
        $agency  = $this->makeAgency();
        $agent   = $this->makeAgent($agency);
        $contact = $this->makeContact($agency, $agent);
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/testimonials', ['rating' => 4])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_rating_out_of_range_is_rejected(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency, $this->makeAgent($agency));
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/testimonials', ['body' => 'Great', 'rating' => 9])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['rating']);
    }

    public function test_lazy_path_quote_only_works_and_no_agent_no_crash(): void
    {
        Notification::fake();

        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency, null); // no connected agent
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/testimonials', ['body' => 'Loved it.'])
            ->assertCreated()
            ->assertJsonPath('testimonial.rating', null)
            ->assertJsonPath('testimonial.display_name', 'Bob Buyer');

        Notification::assertNothingSent();
    }

    public function test_index_lists_only_own_testimonials(): void
    {
        $agency  = $this->makeAgency();
        $agent   = $this->makeAgent($agency);
        $mine    = $this->makeContact($agency, $agent, ['first_name' => 'Me']);
        $other   = $this->makeContact($agency, $agent, ['first_name' => 'Other']);

        ContactTestimonial::query()->withoutGlobalScope(AgencyScope::class)->create([
            'agency_id' => $agency->id, 'contact_id' => $other->id, 'body' => 'Not mine', 'display_name' => 'Other', 'published' => true,
        ]);

        $token = $this->authClient($agency, $mine);
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/testimonials', ['body' => 'Mine'])
            ->assertCreated();

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/testimonials')
            ->assertOk()
            ->assertJsonCount(1, 'testimonials')
            ->assertJsonPath('testimonials.0.body', 'Mine');
    }

    public function test_requires_client_ability(): void
    {
        $this->postJson('/api/v1/client/testimonials', ['body' => 'x'])->assertStatus(401);
        $this->getJson('/api/v1/client/testimonials')->assertStatus(401);
    }
}
