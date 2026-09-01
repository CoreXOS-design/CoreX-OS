<?php

namespace Tests\Feature\Api;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\MobilePhotoEvent;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Photo upload telemetry ingest.
 *
 * The governing rule under test: this endpoint must never cost an agent a photo.
 * It is flushed opportunistically alongside real uploads, so junk, an unknown
 * phase or someone else's listing must be counted and skipped — never fail the
 * batch and never make the client retry forever.
 *
 * Spec: .ai/specs/mobile-photo-upload-telemetry.md
 */
class MobilePhotoEventTest extends TestCase
{
    use RefreshDatabase;

    private Agency $agency;
    private User $user;
    private Property $property;

    protected function setUp(): void
    {
        parent::setUp();

        $this->agency = Agency::create(['name' => 'Coastal Realty', 'slug' => 'coastal-realty']);
        $branch = Branch::create(['agency_id' => $this->agency->id, 'name' => 'Main']);
        $this->user = User::factory()->create([
            'agency_id' => $this->agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);
        $this->property = Property::create([
            'agency_id'     => $this->agency->id,
            'agent_id'      => $this->user->id,
            'branch_id'     => $branch->id,
            'title'         => 'Sea-view 3 bed',
            'suburb'        => 'Margate',
            'property_type' => 'house',
            'listing_type'  => 'sale',
            'status'        => 'active',
            'price'         => 2495000,
        ]);
    }

    private function post(array $events)
    {
        return $this->actingAs($this->user)
            ->postJson('/api/v1/mobile/photo-events', ['events' => $events]);
    }

    public function test_it_records_what_the_phone_reports(): void
    {
        $res = $this->post([
            ['property_id' => $this->property->id, 'client_upload_id' => 'abc_1', 'phase' => 'captured', 'occurred_at' => 1788170293929],
            ['property_id' => $this->property->id, 'client_upload_id' => 'abc_1', 'phase' => 'queued'],
        ]);

        $res->assertStatus(200)->assertJsonPath('recorded', 2);

        $this->assertSame(2, MobilePhotoEvent::where('client_upload_id', 'abc_1')->count());
        $this->assertNotNull(
            MobilePhotoEvent::where('client_upload_id', 'abc_1')->where('phase', 'captured')->first()->occurred_at,
            'Epoch-millisecond timestamps from the phone must be parsed, not dropped.'
        );
    }

    public function test_a_client_cannot_claim_a_photo_arrived(): void
    {
        // `received` is the server's own word, written in uploadImage(). If a
        // client could assert it, the log could show an arrival that never
        // happened — the exact question this table exists to settle.
        $res = $this->post([
            ['property_id' => $this->property->id, 'client_upload_id' => 'abc_2', 'phase' => 'received'],
        ]);

        $res->assertStatus(200)->assertJsonPath('recorded', 0)->assertJsonPath('skipped', 1);
        $this->assertSame(0, MobilePhotoEvent::where('phase', 'received')->count());
    }

    public function test_a_replayed_batch_does_not_multiply_rows(): void
    {
        $event = ['property_id' => $this->property->id, 'client_upload_id' => 'abc_3', 'phase' => 'captured'];

        $this->post([$event])->assertStatus(200);
        $this->post([$event])->assertStatus(200);

        $this->assertSame(1, MobilePhotoEvent::where('client_upload_id', 'abc_3')->count());
    }

    public function test_junk_is_skipped_without_failing_the_batch(): void
    {
        $res = $this->post([
            'not-an-array',
            ['client_upload_id' => 'no_property', 'phase' => 'captured'],
            ['property_id' => $this->property->id, 'phase' => 'captured'],
            ['property_id' => $this->property->id, 'client_upload_id' => 'abc_4', 'phase' => 'not_a_real_phase'],
            ['property_id' => $this->property->id, 'client_upload_id' => 'abc_5', 'phase' => 'captured'],
        ]);

        $res->assertStatus(200)->assertJsonPath('recorded', 1)->assertJsonPath('skipped', 4);
    }

    public function test_another_agencys_listing_is_refused(): void
    {
        $other = Agency::create(['name' => 'Rival Realty', 'slug' => 'rival-realty']);
        $otherBranch = Branch::create(['agency_id' => $other->id, 'name' => 'Main']);
        $otherProperty = Property::create([
            'agency_id' => $other->id, 'branch_id' => $otherBranch->id,
            'title' => 'Not yours', 'suburb' => 'Ramsgate', 'property_type' => 'house',
            'listing_type' => 'sale', 'status' => 'active', 'price' => 1000000,
        ]);

        $res = $this->post([
            ['property_id' => $otherProperty->id, 'client_upload_id' => 'abc_6', 'phase' => 'captured'],
        ]);

        $res->assertStatus(200)->assertJsonPath('recorded', 0)->assertJsonPath('skipped', 1);
        $this->assertSame(0, MobilePhotoEvent::count());
    }

    public function test_an_empty_or_malformed_body_is_not_an_error(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/mobile/photo-events', [])
            ->assertStatus(200)
            ->assertJsonPath('recorded', 0);
    }
}
