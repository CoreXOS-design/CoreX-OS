<?php

declare(strict_types=1);

namespace Tests\Feature\Communications;

use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationPending;
use App\Models\Communications\CommunicationWaDevice;
use App\Models\Contact;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-34 — WhatsApp ingest endpoint: per-device Bearer auth + known-contact gate
 * + WA message-id dedup. POSTs through the real route/middleware/controller.
 */
final class WaIngestTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private string $plainToken;
    private CommunicationWaDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $user = User::factory()->create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'agent', 'is_active' => true,
        ]);

        $this->plainToken = Str::random(48);
        $this->device = CommunicationWaDevice::create([
            'agency_id' => $this->agencyId, 'user_id' => $user->id,
            'wa_number' => '0820000000', 'device_token' => hash('sha256', $this->plainToken), 'active' => true,
        ]);
    }

    private function payload(array $msgOverrides = []): array
    {
        return ['messages' => [array_merge([
            'message_id' => 'WA-' . Str::random(8),
            'chat_id'    => '27821234567@c.us',
            'direction'  => 'in',
            'sender'     => '27821234567@c.us',
            'timestamp'  => now()->timestamp,
            'text'       => 'Hi, is the house still available?',
            'has_media'  => false,
        ], $msgOverrides)]];
    }

    public function test_known_number_is_archived_and_contact_linked(): void
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Wendy', 'last_name' => 'WA',
            'phone' => '0821234567', 'email' => null,
        ]);

        $this->withToken($this->plainToken)
            ->postJson(route('communications.wa.ingest'), $this->payload())
            ->assertOk()->assertJson(['success' => true, 'stats' => ['archived' => 1]]);

        $comm = Communication::firstWhere('agency_id', $this->agencyId);
        $this->assertNotNull($comm);
        $this->assertSame('whatsapp', $comm->channel);
        $this->assertSame('27821234567@c.us', $comm->thread_key);
        $this->assertDatabaseHas('communication_links', [
            'communication_id' => $comm->id, 'linkable_type' => Contact::class, 'linkable_id' => $contact->id,
            'link_method' => 'deterministic',
        ]);
    }

    public function test_unknown_number_parks_in_pending(): void
    {
        $this->withToken($this->plainToken)
            ->postJson(route('communications.wa.ingest'), $this->payload(['sender' => '27999999999@c.us', 'chat_id' => '27999999999@c.us']))
            ->assertOk()->assertJson(['stats' => ['pending' => 1]]);

        $this->assertSame(0, Communication::count());
        $this->assertSame(1, CommunicationPending::where('agency_id', $this->agencyId)->count());
    }

    public function test_same_message_id_is_deduped(): void
    {
        Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'W', 'last_name' => 'A', 'phone' => '0821234567']);
        $payload = $this->payload(['message_id' => 'WA-DUP-1']);

        $this->withToken($this->plainToken)->postJson(route('communications.wa.ingest'), $payload)
            ->assertOk()->assertJson(['stats' => ['archived' => 1]]);
        $this->withToken($this->plainToken)->postJson(route('communications.wa.ingest'), $payload)
            ->assertOk()->assertJson(['stats' => ['duplicate' => 1]]);

        $this->assertSame(1, Communication::where('external_id', 'WA-DUP-1')->count());
    }

    public function test_contact_check_distinguishes_known_from_unknown_numbers(): void
    {
        // Known contact (stored 0821234567 → normalised last-9 822123456 → matches 27821234567).
        Contact::create([
            'agency_id' => $this->agencyId, 'first_name' => 'Wendy', 'last_name' => 'WA', 'phone' => '0821234567',
        ]);

        $this->withToken($this->plainToken)
            ->postJson(route('communications.wa.contact-check'), [
                'numbers' => ['27821234567', '27999999999', '27821234567@c.us'],
            ])
            ->assertOk()
            ->assertJson(['success' => true, 'matches' => [
                '27821234567'       => true,   // known
                '27999999999'      => false,  // unknown
                '27821234567@c.us'  => true,   // jid suffix stripped server-side
            ]]);
    }

    public function test_contact_check_requires_a_valid_device_token(): void
    {
        Contact::create(['agency_id' => $this->agencyId, 'first_name' => 'W', 'last_name' => 'A', 'phone' => '0821234567']);

        $this->withToken('not-a-real-token')
            ->postJson(route('communications.wa.contact-check'), ['numbers' => ['27821234567']])
            ->assertStatus(401);
    }

    public function test_contact_check_rejects_empty_payload(): void
    {
        $this->withToken($this->plainToken)
            ->postJson(route('communications.wa.contact-check'), ['numbers' => []])
            ->assertStatus(422);
    }

    public function test_bad_token_is_rejected(): void
    {
        $this->withToken('not-a-real-token')
            ->postJson(route('communications.wa.ingest'), $this->payload())
            ->assertStatus(401);
        $this->assertSame(0, Communication::count());
    }

    public function test_revoked_device_token_is_rejected(): void
    {
        $this->device->forceFill(['active' => false])->save();

        $this->withToken($this->plainToken)
            ->postJson(route('communications.wa.ingest'), $this->payload())
            ->assertStatus(401);
    }
}
