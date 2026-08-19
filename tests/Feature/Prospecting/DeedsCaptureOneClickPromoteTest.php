<?php

declare(strict_types=1);

namespace Tests\Feature\Prospecting;

use App\Models\Contact;
use App\Models\Prospecting\TrackedProperty;
use App\Models\Prospecting\TrackedPropertyOwner;
use App\Models\Prospecting\TvaContactCapture;
use App\Models\Prospecting\TvaContactCaptureItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * One-click promote+ingest (2026-08-19, Johan, verbatim from the night before):
 * "the user should now tick the contact details they want, and clicking the
 * promote to property + contact should be clicked, not click ingest at the
 * bottom... users will instantly get confused if they click promote and it
 * did not capture contact details, and same if they click ingest and the
 * numbers dissapear they immediately will call it broken."
 *
 * Real incident this fixes: contact 16825 (Martha Maria Botha) was created
 * with zero phone/email rows after a promote — the agent had ticked numbers
 * but there was no way for a single click to carry them. This suite proves
 * the merged action end to end through the real promote() controller method.
 */
final class DeedsCaptureOneClickPromoteTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test ' . Str::random(6), 'slug' => 'test-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('branches')->insert([
            'id' => $this->agencyId, 'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->user = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->agencyId, 'role' => 'admin']);
        $this->actingAs($this->user);
    }

    private function trackedProperty(string $suffix): TrackedProperty
    {
        $tp = TrackedProperty::create([
            'agency_id' => $this->agencyId, 'street_number' => '60', 'street_name' => "Colin Drive {$suffix}",
            'suburb' => "Uvongo Beach {$suffix}", 'erf_number' => 'ERF-' . $suffix,
            'capture_kind' => 'deeds_capture', 'deeds_captured_at' => now(),
        ]);
        $owner = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->user->branch_id,
            'first_name' => 'Owner', 'last_name' => $suffix, 'phone' => '', 'id_number' => Str::random(13),
        ]);
        TrackedPropertyOwner::create([
            'tracked_property_id' => $tp->id, 'contact_id' => $owner->id, 'name' => "Owner {$suffix}",
            'is_primary' => true, 'role' => 'owner', 'ownership_status' => 'current',
        ]);

        return $tp;
    }

    private function tvaCapture(): array
    {
        $capture = TvaContactCapture::create([
            'agency_id' => $this->agencyId, 'captured_by_user_id' => $this->user->id,
            'id_number' => Str::random(13), 'first_name' => 'Tva', 'surname' => 'Person', 'source' => 'tva',
        ]);
        $item1 = TvaContactCaptureItem::create(['tva_contact_capture_id' => $capture->id, 'type' => 'cell', 'value' => '0821112222', 'date' => now(), 'link_date' => now()]);
        $item2 = TvaContactCaptureItem::create(['tva_contact_capture_id' => $capture->id, 'type' => 'cell', 'value' => '0823334444', 'date' => now(), 'link_date' => now()]);
        $item3 = TvaContactCaptureItem::create(['tva_contact_capture_id' => $capture->id, 'type' => 'email', 'value' => 'x@example.com', 'date' => now(), 'link_date' => now()]);

        return [$capture, $item1, $item2, $item3];
    }

    public function test_ticking_two_numbers_lands_both_in_one_click(): void
    {
        $tp = $this->trackedProperty('A');
        [$capture, $item1, $item2, $item3] = $this->tvaCapture();

        $response = $this->post(route('corex.deeds-capture.promote', $tp->id), [
            'tva' => [$capture->id => ['item_ids' => [$item1->id, $item2->id], 'target' => 'new']],
        ]);

        $response->assertRedirect(route('corex.deeds-capture.index'));
        $this->assertNotNull($tp->fresh()->promoted_to_property_id);
        $this->assertStringContainsString('Ingested 2 contact value', session('success'));

        $item1->refresh();
        $contact = Contact::withoutGlobalScopes()->find($item1->ingested_contact_id);
        $this->assertNotNull($contact);
        $this->assertSame(2, $contact->phones()->count());

        $item3->refresh();
        $this->assertNotNull($item3->ingested_at, 'the untouched 3rd item in the SAME ticked capture is discarded (one-shot rule)');
        $this->assertNull($item3->ingested_contact_id);
    }

    public function test_ticking_nothing_promotes_cleanly_with_no_numbers(): void
    {
        $tp = $this->trackedProperty('B');
        [, $item1] = $this->tvaCapture();

        $response = $this->post(route('corex.deeds-capture.promote', $tp->id), []);

        $response->assertRedirect(route('corex.deeds-capture.index'));
        $this->assertNotNull($tp->fresh()->promoted_to_property_id);
        $this->assertStringNotContainsString('Ingested', session('success'));

        $item1->refresh();
        $this->assertNull($item1->ingested_at, 'a capture never present in the request at all is left completely untouched');
    }

    public function test_transaction_rolls_back_fully_when_the_contact_write_fails(): void
    {
        $tp = $this->trackedProperty('C');
        [$capture, $item1] = $this->tvaCapture();

        $response = $this->post(route('corex.deeds-capture.promote', $tp->id), [
            'tva' => [$capture->id => ['item_ids' => [$item1->id], 'target' => 'existing', 'contact_id' => 999999999]],
        ]);

        $response->assertStatus(422);
        $this->assertNull($tp->fresh()->promoted_to_property_id, 'a failed contact write must roll back the property promotion too');
        $item1->refresh();
        $this->assertNull($item1->ingested_at, 'the ticked item must not be marked ingested when the transaction rolled back');
    }
}
