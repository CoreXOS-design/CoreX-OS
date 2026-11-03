<?php

declare(strict_types=1);

namespace Tests\Feature\Rentals;

use App\Models\RentalAuthorisation;
use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalJobDispatch;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\RentalWorkOrderQuote;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Feature\Rentals\Concerns\DrivesRentalLifecycle;
use Tests\TestCase;

/**
 * THE RENTAL LIFE, THE ONE-CLICK WALK (AT-452 S1-S6, .ai/specs/rentals-faults-work-orders.md section 31): from the owner's yes to a closed job on BOTH routes, with each
 * stage one press - Send to X (the link goes out, the tenant is told), the quote selects itself, ONE "Owner authorised by phone/WhatsApp" above the repair limit (who / when /
 * how recorded), the contractor's / crew's finish is one form (photos + done), the agent's Close is one press (the crew route signs off and completes together).
 * The older, longer routes (RentalLifecycleAgencyContractorTest / RentalLifecycleInternalCrewTest) keep running unchanged - they prove the old endpoints still work.
 */
final class RentalLifecycleOneClickTest extends TestCase
{
    use DrivesRentalLifecycle;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->buildLifecycleWorld();
    }

    public function test_the_contractor_route_is_one_press_per_stage(): void
    {
        $this->driveToActiveLeaseWithInspection();
        $supplier = $this->makeSupplierOnTheOwnersList();
        $this->ownerDecides(['decision' => 'approve', 'handled_by' => 'list', 'agency_service_provider_id' => $supplier->id], '8.1', "the owner approves and picks the agency's contractor");

        /** @var RentalWorkOrder|null $wo */
        $wo = $this->step('S4.1', 'ONE press on "Send to <contractor>": the work order is made, the job link goes out, the tenant is told', 'cc6', function () use ($supplier) {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.raise-work-order', $this->fault), ['pick' => 's:' . $supplier->id])->assertSessionHasNoErrors();
            $wo = $this->fault->fresh()->workOrder;
            $this->assertSame(RentalWorkOrder::ASSIGNMENT_OUTSIDE_SUPPLIER, $wo->assignment_type);
            $this->assertSame((int) $supplier->id, (int) $wo->agency_service_provider_id);
            $this->assertSame(RentalWorkOrder::STATUS_ORDERED, $wo->status, 'the link went out');
            \Illuminate\Support\Facades\Mail::assertQueued(\App\Mail\Rentals\RentalTenantRepairArrangedMail::class, fn ($m) => $m->hasTo(self::TENANT_EMAIL));

            return $wo;
        }, ['8.1']);

        $this->step('S4.2', "the contractor's quote on THEIR link selects itself; above the repair limit the owner is asked, nothing is authorised", 'cc6', function () use ($wo) {
            $token = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueFor($wo->fresh(), $this->agent)['raw_token'];
            $this->asGuest();
            $this->post("/secure/work-orders/{$token}/quote", ['amount' => 1500, 'quote_date' => now()->toDateString(), 'detail_text' => 'Replace the tap'])->assertSessionHasNoErrors();
            $quote = RentalWorkOrderQuote::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->firstOrFail();
            $this->assertTrue($quote->is_selected, 'no "Select" press');
            $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
            $this->assertSame(0, RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->count());
        }, ['S4.1']);

        $this->step('S4.3', 'ONE press: "Owner authorised by phone/WhatsApp" - who, when and how are recorded, the go-ahead goes out by itself', 'cc6', function () use ($wo) {
            $this->asStaff();
            $this->post(route('corex.rental-work-orders.cost.owner-authorised', $wo))->assertSessionHasNoErrors();
            $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->orderByDesc('id')->firstOrFail();
            $this->assertTrue($row->isAuthorised());
            $this->assertSame(1500.0, (float) $row->amount_up_to);
            $this->assertSame($this->agent->id === (int) $row->by_user_id || $row->by_user_id !== null, true, 'who');
            $this->assertNotNull($row->told_at, 'when');
            $this->assertSame(RentalAuthorisation::VIA_PHONE_WHATSAPP, $row->told_via, 'how');
            $this->assertSame(1, RentalJobDispatch::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('reason', 'go_ahead')->where('channel', RentalJobDispatch::CHANNEL_EMAIL)->count(), 'the go-ahead email');
        }, ['S4.2']);

        $this->step('S5.1', "the contractor finishes in ONE form: photos of the finished work and Work finished together; what was found is optional", 'cc6', function () use ($wo) {
            $token = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueFor($wo->fresh(), $this->agent)['raw_token'];
            $this->asGuest();
            $this->post("/secure/work-orders/{$token}/mark-done", ['photos' => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')]])->assertSessionHasNoErrors();
            $this->assertSame(2, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('photo_type', RentalWorkOrder::PHOTO_COMPLETED)->count());
            $this->assertSame('Work reported finished - your agent is closing it off', $this->progress('tenant')['current_label']);
        }, ['S4.3']);

        $this->step('S6.1', 'the agent presses ONE Close: the final cost is the authorised amount, the job is completed and the tenant reads completed', 'cc6', function () use ($wo) {
            $this->asStaff();
            $html = $this->get(route('corex.rental-work-orders.show', $wo))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#name="cost_amount" value="1500.00".*data-next-button>Close</button>#s', $html, 'the Close press carries the prefilled final cost');
            $this->post(route('corex.rental-work-orders.complete', $wo), ['cost_amount' => '1500.00'])->assertSessionHasNoErrors();
            $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
            $this->assertSame('completed', $this->progress('tenant')['current']);
        }, ['S5.1']);

        $this->finishLifecycle('one-click-contractor');
    }

    public function test_the_crew_route_is_one_press_per_stage(): void
    {
        $this->driveToActiveLeaseWithInspection();
        $this->ownerDecides(['decision' => 'approve', 'handled_by' => 'agency'], '8.1', 'the owner approves - "my agent arranges it"');

        /** @var RentalWorkOrder|null $wo */
        $wo = $this->step('S4.1', 'ONE press on "Assign <crew>": the work order and the job card are made, the crew is on it and their link goes out, the tenant is told', 'cc6', function () {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.raise-work-order', $this->fault), ['pick' => 'c:' . $this->crew->id])->assertSessionHasNoErrors();
            $wo = $this->fault->fresh()->workOrder;
            $card = $wo->jobCard;
            $this->assertNotNull($card);
            $this->assertSame((int) $this->crew->id, (int) $card->rental_crew_id);
            $this->assertGreaterThanOrEqual(1, RentalJobDispatch::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->where('recipient_kind', RentalJobDispatch::KIND_CREW)->count(), 'the crew link was dispatched');

            return $wo;
        }, ['8.1']);

        $this->step('S4.2', "the card is priced and the price confirmed; above the repair limit the owner is asked, then ONE press records the owner's yes", 'cc6', function () use ($wo) {
            $this->asStaff();
            $card = $wo->fresh()->jobCard;
            $this->post(route('corex.rental-job-cards.tasks.store', $card), ['description' => 'Replace the washer'])->assertSessionHasNoErrors();
            $this->post(route('corex.rental-job-cards.lines.store', $card), ['type' => 'part', 'description' => 'Tap cartridge', 'quantity' => 1, 'unit_price' => 1200, 'unit_cost' => 700])->assertSessionHasNoErrors();
            $this->post(route('corex.rental-job-cards.send-quote', $card))->assertSessionHasNoErrors();
            $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
            $this->post(route('corex.rental-work-orders.cost.owner-authorised', $wo))->assertSessionHasNoErrors();
            $row = RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->orderByDesc('id')->firstOrFail();
            $this->assertTrue($row->isAuthorised());
            $this->assertSame(RentalAuthorisation::VIA_PHONE_WHATSAPP, $row->told_via);
            $this->assertSame(RentalJobCard::STATUS_APPROVED, $card->fresh()->status);
        }, ['S4.1']);

        $this->step('S5.1', 'the crew finish in ONE form on their link: photos + a name + Job done (no separate tick)', 'cc6', function () use ($wo) {
            $card = $wo->fresh()->jobCard;
            $token = app(\App\Services\Rentals\RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->agent)['raw_token'];
            $this->asGuest();
            $this->post("/secure/job-cards/{$token}/complete", ['full_name' => 'Crew Chief', 'photos' => [UploadedFile::fake()->image('done.jpg')]])->assertSessionHasNoErrors();
            $fresh = $card->fresh();
            $this->assertNotNull($fresh->worker_signed_off_at);
            $this->assertNull($fresh->agent_signed_off_at, 'the agent has not been asked anything yet');
            $this->assertSame(1, RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->where('photo_type', 'completed')->count());
        }, ['S4.2']);

        $this->step('S6.1', "the agent presses ONE Close: the agent's sign-off and the completion together; the card and its work order are closed", 'cc6', function () use ($wo) {
            $this->asStaff();
            $card = $wo->fresh()->jobCard;
            $html = $this->get(route('corex.rental-job-cards.show', $card))->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#action="[^"]*rental-job-cards/' . $card->id . '/close".*data-next-button>Close</button>#s', $html);
            $this->post(route('corex.rental-job-cards.close', $card), ['paid_by' => 'owner'])->assertSessionHasNoErrors();
            $this->assertNotNull($card->fresh()->agent_signed_off_at);
            $this->assertSame(RentalJobCard::STATUS_COMPLETED, $card->fresh()->status);
            $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status);
            $this->assertSame('completed', $this->progress('tenant')['current']);
        }, ['S5.1']);

        $this->step('S6.2', 'the agent records the outcome - the fault is resolved', 'cc6', function () {
            $this->asStaff();
            $this->post(route('corex.rental-fault-reports.outcome.store', $this->fault), ['outcome' => 'repaired', 'repaired_at' => now()->toDateString()])->assertSessionHasNoErrors();
            $this->assertSame(RentalFaultReport::STATUS_RESOLVED, $this->fault->fresh()->status);
        }, ['S6.1']);

        $this->finishLifecycle('one-click-crew');
    }
}
