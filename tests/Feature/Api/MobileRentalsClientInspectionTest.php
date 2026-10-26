<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\PropertyRoom;
use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionRoomNote;
use App\Models\RentalInspectionSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Api\Concerns\BuildsMobileRentalsWorld;
use Tests\TestCase;

/**
 * AT-508 (Johan, 25 Oct 2026) - the tenant and the owner can open the move-in and the move-out inspection that was sent to them.
 */
final class MobileRentalsClientInspectionTest extends TestCase
{
    use RefreshDatabase;
    use BuildsMobileRentalsWorld;

    private const CLIENT = '/api/v1/client/rentals';

    private PropertyRoom $room;
    private RentalInspectionItem $ceiling;
    private RentalInspection $in;
    private RentalInspection $out;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildWorld();
        \Illuminate\Support\Facades\Storage::fake('local');

        $this->room = PropertyRoom::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'type' => 'bedroom', 'label' => 'Bedroom 1', 'source' => 'manual', 'sort_order' => 1]);
        $this->ceiling = RentalInspectionItem::create(['agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $this->room->id, 'kind' => RentalInspectionItem::KIND_SPACE, 'space_type' => 'bedroom', 'label' => 'Ceiling', 'sort_order' => 1]);

        $this->in = $this->inspection('in', 'completed', 'Good', 'Freshly painted');
        $this->out = $this->inspection('out', 'completed', 'Damaged', 'Crack above window');
    }

    private function inspection(string $type, string $status, string $condition, string $note): RentalInspection
    {
        $i = RentalInspection::create([
            'agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id, 'type' => $type, 'status' => $status,
            'created_by_user_id' => $this->agent->id, 'completed_at' => $status === 'completed' ? now()->subDays($type === 'in' ? 60 : 1) : null,
            'electricity_meter_reading' => '12345', 'overall_notes' => 'Handed over clean.',
        ]);
        $o = RentalInspectionObservation::create(['agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'rental_inspection_item_id' => $this->ceiling->id, 'observed_by_user_id' => $this->agent->id, 'condition' => $condition, 'notes' => $note, 'source' => 'in_inspection']);
        RentalInspectionPhoto::create(['agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'rental_inspection_observation_id' => $o->id, 'property_room_id' => $this->room->id, 'storage_path' => 'inspection-photos/x.jpg', 'taken_at' => now()]);
        RentalInspectionRoomNote::create(['agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'property_room_id' => $this->room->id, 'note' => 'Damp smell', 'created_by_user_id' => $this->agent->id]);
        RentalInspectionSignature::create(['agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'party_role' => 'agent', 'disposition' => 'signed', 'disposition_recorded_at' => now(), 'recorded_by_user_id' => $this->agent->id]);
        RentalInspectionSignature::create(['agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'party_role' => 'tenant', 'party_contact_id' => $this->tenant->id, 'disposition' => 'signed', 'disposition_recorded_at' => now(), 'recorded_by_user_id' => $this->agent->id]);

        return $i;
    }

    private function keys(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $out[] = $prefix . $k;
            if (is_array($v)) {
                $out = array_merge($out, $this->keys($v, $prefix . $k . '.'));
            }
        }

        return $out;
    }

    public function test_the_list_marks_a_sent_inspection_as_openable_and_a_booked_date_as_not(): void
    {
        RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id, 'type' => 'out', 'status' => 'draft', 'created_by_user_id' => $this->agent->id, 'scheduled_for' => now()->addDays(20)->toDateString()]);

        $rows = collect($this->asClient($this->tenantLogin)->getJson(self::CLIENT . '/overview')->json('homes.0.inspections'));
        $all = collect($rows['upcoming'])->merge($rows['past']);
        $this->assertTrue($all->firstWhere('id', $this->out->id)['viewable']);
        $this->assertFalse($all->firstWhere('when', 'upcoming')['viewable']);
    }

    public function test_the_tenant_opens_the_move_out_with_the_move_in_beside_it_and_sees_nothing_about_the_lessor(): void
    {
        $res = $this->asClient($this->tenantLogin)->getJson(self::CLIENT . "/inspections/{$this->out->id}")->assertOk();
        $i = $res->json('inspection');

        $this->assertSame('out', $i['type']);
        $this->assertSame('Bedroom 1', $i['rooms'][0]['label']);
        $this->assertSame('Damp smell', $i['rooms'][0]['note']);
        $item = $i['rooms'][0]['items'][0];
        $this->assertSame('Ceiling', $item['label']);
        $this->assertSame('damaged', strtolower($item['condition']));
        $this->assertSame('Crack above window', $item['notes']);
        $this->assertCount(1, $item['photos']);
        $this->assertNotEmpty($item['photos'][0]['url']);
        $this->assertSame('Freshly painted', $item['move_in']['notes'], 'how it stood at move-in');
        $this->assertSame($this->in->id, $i['related']['id']);
        $this->assertSame(['you_signed' => true, 'agent_signed' => true], $i['signatures']);

        $keys = implode(' ', $this->keys($res->json()));
        $this->assertDoesNotMatchRegularExpression('/owner|landlord|lessor|discrepanc|attendance|user_id/i', $keys);
        $this->assertStringNotContainsString('Lenny', $res->getContent());

        $in = $this->asClient($this->tenantLogin)->getJson(self::CLIENT . "/inspections/{$this->in->id}")->assertOk()->json('inspection');
        $this->assertArrayNotHasKey('move_in', $in['rooms'][0]['items'][0]);
        $this->assertSame($this->out->id, $in['related']['id']);
    }

    public function test_the_owner_opens_both_and_also_sees_whether_the_tenant_signed(): void
    {
        $res = $this->asClient($this->ownerLogin)->getJson(self::CLIENT . "/landlord/inspections/{$this->out->id}")->assertOk();
        $this->assertSame(['you_signed' => false, 'agent_signed' => true, 'tenant_signed' => true], $res->json('inspection.signatures'));
        $this->asClient($this->ownerLogin)->getJson(self::CLIENT . "/landlord/inspections/{$this->in->id}")->assertOk();
    }

    public function test_a_draft_a_booked_date_someone_elses_inspection_and_the_other_side_are_a_404(): void
    {
        $draft = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'property_id' => $this->property->id, 'type' => 'out', 'status' => 'draft', 'created_by_user_id' => $this->agent->id, 'scheduled_for' => now()->addDays(20)->toDateString()]);
        $this->asClient($this->tenantLogin)->getJson(self::CLIENT . "/inspections/{$draft->id}")->assertNotFound();

        // a different tenant on another property
        $other = $this->contact('Olive');
        $otherLogin = $this->loginFor($other);
        $this->asClient($otherLogin)->getJson(self::CLIENT . "/inspections/{$this->out->id}")->assertNotFound();
        // the owner route does not serve a tenant, and vice versa
        $this->asClient($this->tenantLogin)->getJson(self::CLIENT . "/landlord/inspections/{$this->out->id}")->assertNotFound();
        $this->asClient($this->ownerLogin)->getJson(self::CLIENT . "/inspections/{$this->out->id}")->assertNotFound();
    }
}
