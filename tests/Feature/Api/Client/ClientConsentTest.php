<?php

namespace Tests\Feature\Api\Client;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\ContactConsentRecord;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Feature tests for the client-side consent API
 * (GET/POST /api/v1/client/consent).
 *
 * Spec: .ai/specs/contact-consent.md §6.
 *
 * Same DB-test caveat as the other client tests — needs the test DB up
 * (MySQL/MariaDB on PATH, see CLAUDE.md non-negotiable #12a).
 */
class ClientConsentTest extends TestCase
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

    private function makeContact(Agency $agency, array $overrides = []): Contact
    {
        $branchId = Branch::query()->where('agency_id', $agency->id)->value('id');
        return Contact::query()->withoutGlobalScope(AgencyScope::class)->create(array_merge([
            'agency_id'  => $agency->id,
            'branch_id'  => $branchId,
            'first_name' => 'Bob',
            'last_name'  => 'Buyer',
            'phone'      => '0820000000',
            'email'      => 'buyer+' . uniqid() . '@example.com',
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

    public function test_index_returns_all_seven_types_neutral_by_default(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $token   = $this->authClient($agency, $contact);

        $res = $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson('/api/v1/client/consent')
            ->assertOk()
            ->assertJsonCount(7, 'consents');

        foreach ($res->json('consents') as $row) {
            $this->assertNull($row['decision']);
        }
    }

    public function test_client_can_give_consent(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/consent', ['type' => 'channel_email', 'decision' => 'given'])
            ->assertOk();

        $rec = ContactConsentRecord::query()->withoutGlobalScope(AgencyScope::class)
            ->where('contact_id', $contact->id)->whereNull('revoked_at')->first();
        $this->assertNotNull($rec);
        $this->assertSame('channel_email', $rec->consent_type);
        $this->assertSame('given', $rec->decision);
        $this->assertSame('client_app', $rec->source);
        $this->assertNull($rec->given_by_user_id);

        // Channel granted → not opted out.
        $this->assertFalse((bool) $contact->fresh()->opt_out_email);
    }

    public function test_declining_a_channel_opts_out_and_blocks_sending(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/consent', ['type' => 'channel_whatsapp', 'decision' => 'declined'])
            ->assertOk()
            ->assertJsonFragment(['type' => 'channel_whatsapp', 'decision' => 'declined']);

        $fresh = $contact->fresh();
        $this->assertTrue((bool) $fresh->opt_out_whatsapp);
        $this->assertFalse($fresh->canSendVia('whatsapp'));
    }

    public function test_setting_a_new_decision_supersedes_the_old_one(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/consent', ['type' => 'channel_sms', 'decision' => 'given'])->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/consent', ['type' => 'channel_sms', 'decision' => 'declined'])->assertOk();

        // Exactly one ACTIVE record, and it is the declined one.
        $active = ContactConsentRecord::query()->withoutGlobalScope(AgencyScope::class)
            ->where('contact_id', $contact->id)
            ->where('consent_type', 'channel_sms')
            ->whereNull('revoked_at')->get();
        $this->assertCount(1, $active);
        $this->assertSame('declined', $active->first()->decision);

        // The full history is preserved (the given row is revoked, not deleted).
        $all = ContactConsentRecord::query()->withoutGlobalScope(AgencyScope::class)
            ->where('contact_id', $contact->id)->where('consent_type', 'channel_sms')->get();
        $this->assertCount(2, $all);
    }

    public function test_clear_returns_to_not_recorded(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/consent', ['type' => 'fica_processing', 'decision' => 'given'])->assertOk();
        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/consent', ['type' => 'fica_processing', 'decision' => 'clear'])->assertOk();

        $this->assertNull($contact->fresh()->consentDecision('fica_processing'));
    }

    public function test_invalid_type_is_rejected(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/consent', ['type' => 'nope', 'decision' => 'given'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['type']);
    }

    public function test_invalid_decision_is_rejected(): void
    {
        $agency  = $this->makeAgency();
        $contact = $this->makeContact($agency);
        $token   = $this->authClient($agency, $contact);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/v1/client/consent', ['type' => 'channel_email', 'decision' => 'maybe'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['decision']);
    }

    public function test_requires_client_ability(): void
    {
        $this->getJson('/api/v1/client/consent')->assertStatus(401);
        $this->postJson('/api/v1/client/consent', ['type' => 'channel_email', 'decision' => 'given'])->assertStatus(401);
    }
}
