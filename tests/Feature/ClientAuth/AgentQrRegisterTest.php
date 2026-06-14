<?php

namespace Tests\Feature\ClientAuth;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Scopes\AgencyScope;
use App\Models\Scopes\ContactScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests for the Agent-QR onboarding register endpoint.
 *
 * Covers the two-outcome contract:
 *   - brand-new email  → contact + client login created, attributed to the
 *     scanning agent, signed in (201 + token).
 *   - known email      → contact still linked to the scanning agent, but NO
 *     password set and NO session issued; the app is told to verify via OTP
 *     (200 + requires_verification, no token). This holds whether the email is
 *     known in THIS agency or a DIFFERENT one.
 *
 * Spec: .ai/specs/agent-qr-onboarding.md, .ai/specs/client-auth.md
 */
class AgentQrRegisterTest extends TestCase
{
    use RefreshDatabase;

    private function makeAgency(string $name = 'Agency A'): Agency
    {
        $agency = Agency::create([
            'name' => $name,
            'slug' => str()->slug($name . '-' . uniqid()),
        ]);
        Branch::create([
            'agency_id' => $agency->id,
            'name'      => $name . ' Main',
            'code'      => 'MAIN-' . $agency->id,
            'is_active' => true,
        ]);
        return $agency;
    }

    private function makeAgent(Agency $agency, string $slug): User
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');

        return User::factory()->create([
            'name'         => 'André Roets',
            'agency_id'    => $agency->id,
            'branch_id'    => $branchId,
            'is_active'    => true,
            'qr_code_slug' => $slug,
        ]);
    }

    private function makeContact(Agency $agency, array $overrides = []): Contact
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');

        return Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->create(array_merge([
                'agency_id'  => $agency->id,
                'branch_id'  => $branchId,
                'first_name' => 'Test',
                'last_name'  => 'Contact',
                'phone'      => '0820000000',
                'email'      => 'test+' . uniqid() . '@example.com',
            ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'first_name'            => 'Jane',
            'last_name'             => 'Prospect',
            'phone'                 => '0831112222',
            'email'                 => 'jane@example.com',
            'password'              => 'super-secret-123',
            'password_confirmation' => 'super-secret-123',
            'device_name'           => 'iPhone Test',
        ], $overrides);
    }

    public function test_new_email_creates_contact_attributed_to_agent_and_signs_in(): void
    {
        $agency = $this->makeAgency();
        $agent  = $this->makeAgent($agency, 'newslug001');

        $res = $this->postJson(
            "/api/v1/client-auth/agent-qr/{$agent->qr_code_slug}/register",
            $this->payload(['email' => 'newprospect@example.com'])
        );

        $res->assertStatus(201)
            ->assertJsonPath('existing', false)
            ->assertJsonStructure(['token', 'agent', 'agency', 'contact', 'client_user'])
            ->assertJsonPath('agent.full_name', 'André Roets')
            ->assertJsonPath('agency.id', $agency->id);

        // Contact created in the agent's agency, attributed to the agent.
        $contact = Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $agency->id)
            ->whereRaw('LOWER(email) = ?', ['newprospect@example.com'])
            ->first();

        $this->assertNotNull($contact, 'A contact should be created in the agent agency.');
        $this->assertSame($agent->id, $contact->created_by_user_id);
        $this->assertSame('Jane', $contact->first_name);
        $this->assertSame('0831112222', $contact->phone);
        $this->assertNotNull($contact->contact_source_id, 'Contact should be sourced.');

        // Client login created and linked to the contact.
        $clientUser = ClientUser::where('email', 'newprospect@example.com')->first();
        $this->assertNotNull($clientUser);
        $this->assertSame($clientUser->id, $contact->client_user_id);
        $this->assertTrue(Hash::check('super-secret-123', $clientUser->password));

        $this->assertDatabaseHas('contact_sources', [
            'agency_id' => $agency->id,
            'name'      => 'Agent QR',
        ]);
    }

    public function test_existing_email_in_same_agency_requires_verification_and_returns_no_token(): void
    {
        $agency = $this->makeAgency();
        $agent  = $this->makeAgent($agency, 'existslug01');

        $originalHash = Hash::make('the-real-password');
        $clientUser = ClientUser::create([
            'email'           => 'bob@example.com',
            'password'        => $originalHash,
            'password_set_at' => now(),
        ]);
        // Pre-existing contact in this agency, NOT yet attributed to the agent.
        $this->makeContact($agency, [
            'email'              => 'bob@example.com',
            'client_user_id'     => $clientUser->id,
            'created_by_user_id' => null,
        ]);

        $res = $this->postJson(
            "/api/v1/client-auth/agent-qr/{$agent->qr_code_slug}/register",
            $this->payload(['email' => 'bob@example.com'])
        );

        $res->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('requires_verification', true)
            ->assertJsonPath('verification', 'otp')
            ->assertJsonPath('agent.full_name', 'André Roets')
            ->assertJsonPath('agency.id', $agency->id);

        // CRITICAL: no session token handed back for a known identity.
        $this->assertArrayNotHasKey('token', $res->json());

        // CRITICAL: the supplied password must NOT have been written.
        $this->assertSame($originalHash, $clientUser->fresh()->password);
        $this->assertFalse(Hash::check('super-secret-123', $clientUser->fresh()->password));

        // Still linked to the scanning agent.
        $contact = Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $agency->id)
            ->whereRaw('LOWER(email) = ?', ['bob@example.com'])
            ->first();
        $this->assertSame($agent->id, $contact->created_by_user_id);
    }

    public function test_existing_email_in_different_agency_creates_contact_here_and_requires_verification(): void
    {
        $agencyA = $this->makeAgency('Agency A');
        $agencyB = $this->makeAgency('Agency B');
        $agentA  = $this->makeAgent($agencyA, 'crossslug01');

        $originalHash = Hash::make('bobs-other-password');
        $clientUser = ClientUser::create([
            'email'           => 'cross@example.com',
            'password'        => $originalHash,
            'password_set_at' => now(),
        ]);
        // Identity known only in agency B.
        $this->makeContact($agencyB, [
            'email'          => 'cross@example.com',
            'client_user_id' => $clientUser->id,
        ]);

        $res = $this->postJson(
            "/api/v1/client-auth/agent-qr/{$agentA->qr_code_slug}/register",
            $this->payload(['email' => 'cross@example.com'])
        );

        $res->assertOk()
            ->assertJsonPath('existing', true)
            ->assertJsonPath('requires_verification', true)
            ->assertJsonPath('verification', 'otp')
            ->assertJsonPath('agency.id', $agencyA->id);

        $this->assertArrayNotHasKey('token', $res->json());

        // Password untouched.
        $this->assertSame($originalHash, $clientUser->fresh()->password);

        // A NEW contact for THIS (agency A) is created, attributed to agent A,
        // and linked to the existing identity.
        $contactA = Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $agencyA->id)
            ->whereRaw('LOWER(email) = ?', ['cross@example.com'])
            ->first();

        $this->assertNotNull($contactA, 'A contact should be created in the scanning agency.');
        $this->assertSame($agentA->id, $contactA->created_by_user_id);
        $this->assertSame($clientUser->id, $contactA->client_user_id);
    }

    public function test_invalid_slug_returns_404(): void
    {
        $this->postJson(
            '/api/v1/client-auth/agent-qr/nosuchslug9/register',
            $this->payload()
        )->assertStatus(404);
    }

    public function test_repeated_new_scan_is_idempotent_and_does_not_duplicate_contact(): void
    {
        $agency = $this->makeAgency();
        $agent  = $this->makeAgent($agency, 'idemslug001');

        $first = $this->postJson(
            "/api/v1/client-auth/agent-qr/{$agent->qr_code_slug}/register",
            $this->payload(['email' => 'idem@example.com'])
        );
        $first->assertStatus(201);

        // Second scan: identity now known → requires verification, no new contact.
        $second = $this->postJson(
            "/api/v1/client-auth/agent-qr/{$agent->qr_code_slug}/register",
            $this->payload(['email' => 'idem@example.com'])
        );
        $second->assertOk()->assertJsonPath('requires_verification', true);

        $count = Contact::query()
            ->withoutGlobalScope(AgencyScope::class)
            ->withoutGlobalScope(ContactScope::class)
            ->where('agency_id', $agency->id)
            ->whereRaw('LOWER(email) = ?', ['idem@example.com'])
            ->count();

        $this->assertSame(1, $count, 'Repeated scans must not duplicate the contact.');
    }
}
