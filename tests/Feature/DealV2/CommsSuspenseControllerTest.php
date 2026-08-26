<?php

namespace Tests\Feature\DealV2;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationAttachment;
use App\Models\Communications\CommunicationFilingSuspense;
use App\Models\Deal;
use App\Models\DealV2\AgencyServiceProvider;
use App\Models\DealV2\AgencyServiceProviderContact;
use App\Models\Document;
use App\Models\Property;
use App\Models\Scopes\AgencyScope;
use App\Models\User;
use App\Services\Communications\CommunicationStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-231 P2b — the review-screen (suspense queue) controller: list pending,
 * confirm-to-deal (verify + file + learn), reassign, reject (dismiss).
 * Permission gate is wired via route middleware (`permission:deal_comms_suspense.*`);
 * the test DB leaves role_permissions unseeded → PermissionService fails open, so
 * these functional tests run with the gate satisfied.
 */
class CommsSuspenseControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    private function world(): array
    {
        $agency = Agency::create(['name' => 'HFC', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Shelly Beach']);
        $user   = User::factory()->create(['agency_id' => $agency->id, 'branch_id' => $branch->id, 'role' => 'admin']);
        $property = Property::withoutEvents(fn () => Property::withoutGlobalScope(AgencyScope::class)->create([
            'external_id' => 'T-' . Str::random(6), 'title' => 'Home', 'address' => '12 Marine Dr', 'suburb' => 'Shelly Beach',
            'agent_id' => $user->id, 'agency_id' => $agency->id, 'branch_id' => $branch->id,
        ]));
        $firm = AgencyServiceProvider::create(['agency_id' => $agency->id, 'name' => 'VDS', 'specialty' => 'transfer_attorney', 'email' => 'firm@vds.co.za', 'is_active' => true, 'created_by_id' => $user->id]);
        $attorney = AgencyServiceProviderContact::create(['agency_id' => $agency->id, 'service_provider_id' => $firm->id, 'attorney_name' => 'Botha', 'email' => 'botha@vds.co.za', 'is_active' => true, 'created_by_id' => $user->id]);
        $deal = $this->deal($agency->id, $branch->id, $user->id, $property->id, $firm->id, $attorney->id);

        return compact('agency', 'branch', 'user', 'property', 'firm', 'attorney', 'deal');
    }

    private function deal(int $agencyId, int $branchId, int $userId, int $propertyId, int $firmId, int $attorneyId): Deal
    {
        $twinId = DB::table('deals_v2')->insertGetId([
            'reference' => 'DR2-' . Str::random(5), 'deal_type' => 'bond', 'listing_agent_id' => $userId,
            'purchase_price' => 1_950_000, 'commission_amount' => 97_500, 'commission_vat' => 14_625,
            'offer_date' => '2026-03-01', 'branch_id' => $branchId, 'agency_id' => $agencyId,
            'created_by_id' => $userId, 'property_id' => $propertyId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        return Deal::withoutEvents(fn () => Deal::withoutGlobalScopes()->create([
            'period' => '2026-03', 'deal_date' => '2026-03-01', 'property_value' => 1_950_000, 'total_commission' => 112_125,
            'reference' => 'REG-' . Str::random(5), 'deal_no' => (string) random_int(1000, 9999), 'deal_type' => 'bond',
            'seller_name' => 'Seller', 'property_address' => '12 Marine Dr',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'property_id' => $propertyId, 'deal_v2_id' => $twinId,
            'attorney_provider_id' => $firmId, 'attorney_contact_id' => $attorneyId,
        ]));
    }

    private function parked(array $w): CommunicationFilingSuspense
    {
        $comm = Communication::create([
            'agency_id' => $w['agency']->id, 'channel' => 'email', 'direction' => 'inbound',
            'external_id' => 'mid-' . Str::random(10), 'thread_key' => null,
            'from_identifier' => 'botha@vds.co.za', 'participant_identifiers' => ['botha@vds.co.za'],
            'occurred_at' => now(), 'captured_at' => now(),
            'subject' => 'Re: Documents [CX-D' . $w['deal']->id . ']', 'body_text' => 'The signed COC is attached.',
            'has_attachments' => true, 'owner_user_id' => $w['user']->id,
        ]);
        $stored = app(CommunicationStorageService::class)->store($w['agency']->id, 'attachment', 'PDFBYTES-' . Str::random(6));
        CommunicationAttachment::create([
            'agency_id' => $w['agency']->id, 'communication_id' => $comm->id, 'filename' => 'COC.pdf',
            'mime' => 'application/pdf', 'size_bytes' => 8, 'content_hash' => $stored['content_hash'], 'storage_path' => $stored['path'],
        ]);
        return CommunicationFilingSuspense::create([
            'agency_id' => $w['agency']->id, 'communication_id' => $comm->id, 'channel' => 'email',
            'suggested_deal_id' => $w['deal']->id, 'confidence' => 'high', 'status' => 'pending',
            'matched_signal_type' => 'cx_token', 'matched_signal_value' => 'cx-d' . $w['deal']->id,
            'attorney_provider_id' => $w['firm']->id, 'attorney_provider_contact_id' => $w['attorney']->id,
        ]);
    }

    public function test_queue_lists_pending_items(): void
    {
        $w = $this->world();
        $this->parked($w);

        $this->actingAs($w['user'])
            ->get(route('corex.comms-suspense.index'))
            ->assertOk()
            ->assertSee('Documents')        // the parked email subject
            ->assertSee('Confirm');         // the confirm action
    }

    public function test_confirm_files_to_the_deal_and_closes_the_suspense(): void
    {
        $w = $this->world();
        $s = $this->parked($w);

        $this->actingAs($w['user'])
            ->post(route('corex.comms-suspense.verify', $s), ['deal_id' => $w['deal']->id])
            ->assertRedirect();

        $this->assertSame('verified', $s->fresh()->status);
        $twinId = $w['deal']->deal_v2_id;
        $this->assertTrue(
            Document::withoutGlobalScopes()->where('source_type', 'inbound_email')->where('deal_id', $twinId)->exists(),
            'the attachment filed as a document on the deal'
        );
    }

    public function test_reject_dismisses_without_filing(): void
    {
        $w = $this->world();
        $s = $this->parked($w);

        $this->actingAs($w['user'])
            ->post(route('corex.comms-suspense.dismiss', $s), ['reason' => 'not deal related'])
            ->assertRedirect();

        $this->assertSame('dismissed', $s->fresh()->status);
        $this->assertSame(0, Document::withoutGlobalScopes()->where('source_type', 'inbound_email')->count());
    }

    public function test_reassign_moves_a_filed_correspondence_to_the_corrected_deal(): void
    {
        $w = $this->world();
        $s = $this->parked($w);
        // File it to deal A.
        $this->actingAs($w['user'])->post(route('corex.comms-suspense.verify', $s), ['deal_id' => $w['deal']->id])->assertRedirect();

        // A second deal for the same firm.
        $dealB = $this->deal($w['agency']->id, $w['branch']->id, $w['user']->id, $w['property']->id, $w['firm']->id, $w['attorney']->id);

        $this->actingAs($w['user'])
            ->post(route('corex.comms-suspense.reassign', $s), ['deal_id' => $dealB->id])
            ->assertRedirect();

        $this->assertSame($dealB->id, (int) $s->fresh()->resolved_deal_id);
        $this->assertTrue(
            Document::withoutGlobalScopes()->where('source_type', 'inbound_email')->where('deal_id', $dealB->deal_v2_id)->exists(),
            're-filed to the corrected deal'
        );
    }
}
