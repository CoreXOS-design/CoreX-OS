<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalFaultReport;
use App\Models\RentalJobCard;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkCompletionRound;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsCompletionFlowWorld;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.3.5 / §17.12 — what a tenant and a landlord see of a WORK ORDER (the portal's Jobs).
 * Proven: the tenant NEVER sees a price, a quote or an approval in any field or any state; the landlord sees the owner-facing
 * amount only (the selling figure — never cost, markup or margin); plain stage labels for every state, never a raw status; the
 * completion rounds and the tenant's own dispute photos appear in the rounds and NOWHERE else; another tenant, another
 * landlord, another agency and an archived work order are all a 404; the fault report shows its work order's plain stage.
 */
final class ClientWorkOrderViewTest extends TestCase
{
    use BuildsCompletionFlowWorld;
    use RefreshDatabase;

    private RentalWorkOrder $workOrder;
    private RentalJobCard $card;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFlowWorld();
        [$this->workOrder, $this->card] = $this->internalJob();
    }

    private function asTenant(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->tenant), ['client']);
    }

    private function asLandlord(): void
    {
        Sanctum::actingAs($this->clientUserFor($this->landlord), ['client']);
    }

    private function selectQuote(float $amount, ?float $selling = null): void
    {
        $this->workOrder->quotes()->create([
            'agency_id' => $this->agency->id, 'rental_job_card_id' => $this->card->id, 'revision' => 1, 'agency_service_provider_id' => null,
            'amount' => $amount, 'selling_amount' => $selling, 'quote_date' => now()->toDateString(), 'is_selected' => true, 'detail_text' => 'Quote generated from job card',
        ]);
    }

    private function stageFor(string $audience = RentalWorkOrderClientViewService::AUDIENCE_TENANT): string
    {
        return app(RentalWorkOrderClientViewService::class)->stageLabel($this->workOrder->fresh(), $audience);
    }

    // ── The tenant never sees money ──────────────────────────────────────

    public function test_the_tenant_sees_their_work_orders_and_never_a_price_in_any_field(): void
    {
        $this->selectQuote(98765.43, 11223.44);
        $this->workOrder->forceFill(['cost_amount' => 55555.55, 'owner_approval_status' => 'approved', 'approved_amount' => 33333.33])->save();
        $this->card->forceFill(['total_amount' => 44444.44, 'total_cost' => 22222.22, 'markup_all_percent' => 17.5])->save();
        $this->asTenant();

        $list = $this->getJson('/api/v1/client/rentals/work-orders')->assertOk();
        $show = $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->assertOk();

        $this->assertSame([$this->workOrder->id], collect($list->json('work_orders'))->pluck('id')->all());
        foreach ([$list->getContent(), $show->getContent()] as $json) {
            foreach (['98765', '11223', '55555', '33333', '44444', '22222', '98,765'] as $figure) {
                $this->assertStringNotContainsString($figure, $json, "a price leaked to the tenant: {$figure}");
            }
            foreach (['owner_facing_amount', 'cost_amount', 'approved_amount', 'total_cost', 'markup', 'margin', 'selected_quote', 'owner_approval'] as $key) {
                $this->assertStringNotContainsString($key, $json, "forbidden key {$key} reached the tenant");
            }
        }
        $this->assertSame('Fix the geyser', $show->json('work_order.title'));
        $this->assertSame('our_team', $show->json('work_order.who'));
        $this->assertStringContainsString('14 Ocean View Drive', $show->json('work_order.property_address'));
    }

    public function test_another_tenant_another_agency_an_archived_order_and_nobody_get_nothing(): void
    {
        // every fixture first: a record built while a portal user is authenticated is stamped by THEIR identity
        $otherProperty = $this->makeProperty($this->agency, $this->admin, '4 Beach Road, Uvongo');
        $neighbour = $this->makeTenant($this->agency, $this->makeLease($this->agency, $otherProperty), ['first_name' => 'Bob']);
        [, , , , $capeTenant] = $this->otherAgencyWorld();

        $this->getJson('/api/v1/client/rentals/work-orders')->assertStatus(401);

        Sanctum::actingAs($this->clientUserFor($neighbour), ['client']);
        $this->assertSame([], $this->getJson('/api/v1/client/rentals/work-orders')->assertOk()->json('work_orders'));
        $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->assertStatus(404);

        Sanctum::actingAs($this->clientUserFor($capeTenant), ['client']);
        $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->assertStatus(404);

        $this->workOrder->delete();
        $this->asTenant();
        $this->assertSame([], $this->getJson('/api/v1/client/rentals/work-orders')->json('work_orders'));
        $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->assertStatus(404);
    }

    // ── Plain stage labels ───────────────────────────────────────────────

    public function test_every_state_reads_in_plain_words_never_a_raw_status(): void
    {
        $table = [];
        $this->workOrder->forceFill(['status' => 'reported', 'owner_approval_status' => 'not_required'])->save();   // (the shared world starts owner-approved)
        $this->card->forceFill(['status' => 'draft'])->save();
        $table['draft card, nothing arranged'] = [$this->stageFor(), 'Created'];

        $this->card->forceFill(['status' => RentalJobCard::STATUS_SCHEDULED])->save();
        $table['scheduled'] = [$this->stageFor(), 'Appointment set'];

        $this->card->forceFill(['status' => RentalJobCard::STATUS_IN_PROGRESS])->save();
        $table['crew started'] = [$this->stageFor(), 'In progress'];

        $this->workOrder->forceFill(['status' => 'in_progress'])->save();
        $table['work order in progress'] = [$this->stageFor(), 'In progress'];

        $this->workOrder->forceFill(['status' => 'reported', 'owner_approval_status' => 'pending'])->save();
        $this->card->forceFill(['status' => 'quoted'])->save();
        $table['landlord, waiting on the owner'] = [$this->stageFor('landlord'), 'Needs your decision'];
        $table['tenant, same moment'] = [$this->stageFor('tenant'), 'Created'];

        $this->workOrder->forceFill(['owner_approval_status' => 'approved'])->save();
        $this->card->forceFill(['status' => 'approved'])->save();
        $table['approved'] = [$this->stageFor('landlord'), 'Created'];

        $round = app(RentalCompletionService::class)->openRound($this->workOrder->fresh(), ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);
        $table['reported done (T1: waiting for the agent to close, no tenant hold)'] = [$this->stageFor(), 'Work reported finished'];

        app(RentalCompletionService::class)->respond($round, false, 'Still wet under the sink', [], ['contact' => $this->tenant, 'via' => 'portal']);
        app(RentalCompletionService::class)->sendBack($this->workOrder->fresh(), $this->admin);   // T1: the AGENT's send-back is what reopens it
        $table['disputed and sent back'] = [$this->stageFor(), 'Not complete — reopened'];

        $this->workOrder->forceFill(['status' => 'completed', 'completed_at' => now()])->save();
        $this->workOrder->completionRounds()->update(['outcome' => RentalWorkCompletionRound::OUTCOME_CONFIRMED]);
        $table['completed'] = [$this->stageFor(), 'Completed'];

        $this->workOrder->forceFill(['status' => 'cancelled'])->save();
        $table['cancelled'] = [$this->stageFor(), 'Cancelled'];

        foreach ($table as $when => [$actual, $expected]) {
            $this->assertSame($expected, $actual, $when);
        }
    }

    // ── The landlord sees the owner-facing amount only ───────────────────

    public function test_the_landlord_sees_the_owner_facing_amount_and_never_cost_markup_or_margin(): void
    {
        $this->selectQuote(1000.00, 1250.00);   // contractor/cost-side amount vs what the owner is charged
        $this->card->forceFill(['total_cost' => 777.77, 'markup_all_percent' => 25, 'total_amount' => 1250.00])->save();
        \App\Models\RentalJobCardLine::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_job_card_id' => $this->card->id, 'type' => 'part', 'description' => 'Element',
            'unit' => 'each', 'quantity' => 1, 'unit_price' => 1250, 'line_total' => 1250, 'unit_cost' => 888.88, 'cost_total' => 888.88, 'sort_order' => 1,
        ]);
        $this->asLandlord();

        $show = $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->assertOk();

        $this->assertEquals(1250.00, $show->json('work_order.owner_facing_amount'), 'the selling figure, via the one accessor');
        $json = $show->getContent();
        foreach (['777.77', '888.88', 'total_cost', 'unit_cost', 'cost_total', 'markup', 'margin'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "the landlord must never see: {$needle}");
        }
        $this->assertSame('Fix the geyser', $show->json('work_order.title'));

        // the list endpoint carries the same view, per work order
        $row = collect($this->getJson('/api/v1/client/rentals/landlord/work-orders')->assertOk()->json('work_orders'))->firstWhere('id', $this->workOrder->id);
        $this->assertSame($this->workOrder->id, $row['client']['id']);
        $this->assertEquals(1250.00, $row['client']['owner_facing_amount']);
        $this->assertStringNotContainsString('777.77', json_encode($row));
    }

    public function test_a_closed_work_order_shows_its_final_amount_to_the_landlord_only(): void
    {
        $this->workOrder->forceFill(['status' => 'completed', 'completed_at' => now(), 'cost_amount' => 2300.00, 'paid_by' => 'owner'])->save();

        $this->asLandlord();
        $this->assertEquals(2300.00, $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->json('work_order.owner_facing_amount'));

        $this->asTenant();
        $this->assertStringNotContainsString('2300', $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->getContent());
    }

    public function test_another_landlord_another_agency_and_an_archived_order_are_a_404_for_the_landlord(): void
    {
        $otherProperty = $this->makeProperty($this->agency, $this->admin, '4 Beach Road, Uvongo');
        $otherLandlord = $this->makeLandlord($this->agency, $otherProperty, ['first_name' => 'Anna']);
        $capeAgency = $this->makeAgency('Cape Town Rentals');
        $capeAgent = $this->makeAgent($capeAgency);
        $capeLandlord = $this->makeLandlord($capeAgency, $this->makeProperty($capeAgency, $capeAgent), ['first_name' => 'Cobus']);

        Sanctum::actingAs($this->clientUserFor($otherLandlord), ['client']);
        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->assertStatus(404);

        Sanctum::actingAs($this->clientUserFor($capeLandlord), ['client']);
        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->assertStatus(404);

        $this->workOrder->delete();
        $this->asLandlord();
        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->assertStatus(404);
    }

    public function test_a_tenant_cannot_open_the_landlord_endpoint(): void
    {
        $this->asTenant();

        $this->getJson("/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}")->assertStatus(404);
    }

    // ── Rounds, and the tenant's dispute photos live ONLY in the rounds ──

    public function test_dispute_photos_appear_inside_the_round_and_nowhere_else(): void
    {
        $this->makePhoto($this->card, 'completed');   // the crew's finished-job photo
        $round = app(RentalCompletionService::class)->openRound($this->workOrder, ['reported_by_label' => 'Sipho Dlamini', 'reported_via' => 'crew_link', 'rental_job_card_id' => $this->card->id]);
        app(RentalCompletionService::class)->respond($round, false, 'Still wet under the sink', [UploadedFile::fake()->image('wet.jpg')], ['contact' => $this->tenant, 'via' => 'portal']);
        app(RentalCompletionService::class)->sendBack($this->workOrder->fresh(), $this->admin);
        $disputePhoto = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_completion_round_id', $round->id)->sole();

        foreach (['tenant' => fn () => $this->asTenant(), 'landlord' => fn () => $this->asLandlord()] as $who => $login) {
            $login();
            $url = $who === 'tenant' ? "/api/v1/client/rentals/work-orders/{$this->workOrder->id}" : "/api/v1/client/rentals/landlord/work-orders/{$this->workOrder->id}";
            $order = $this->getJson($url)->assertOk()->json('work_order');

            $this->assertCount(1, $order['photos'], "{$who}: only the crew's finished-job photo is in the general gallery");
            $this->assertNotContains($disputePhoto->storage_path, array_column($order['photos'], 'url'), $who);
            $this->assertCount(1, $order['rounds']);
            $this->assertSame('disputed', $order['rounds'][0]['outcome']);
            $this->assertSame('Reported not complete', $order['rounds'][0]['outcome_label']);
            $this->assertSame('Still wet under the sink', $order['rounds'][0]['response_note']);
            $this->assertSame([$disputePhoto->storage_path], array_column($order['rounds'][0]['photos'], 'url'), $who);
            $this->assertSame('Not complete — reopened', $order['stage_label']);
        }
    }

    public function test_the_optional_question_is_offered_to_the_tenant_until_they_answer(): void
    {
        $round = app(RentalCompletionService::class)->openRound($this->workOrder, ['reported_by_label' => 'Team 1', 'reported_via' => 'crew_link']);
        $this->asTenant();

        $open = $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->json('work_order.awaiting_answer');
        $this->assertSame($round->id, $open['round_id']);
        $this->assertArrayNotHasKey('answer_due', $open, 'T1: no answer-by date');

        $round->forceFill(['window_ends_at' => now()->subMinute()])->save();
        $this->assertNotNull($this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->json('work_order.awaiting_answer'), 'T1: the question never expires');

        $round->forceFill(['window_ends_at' => now()->addDay(), 'outcome' => RentalWorkCompletionRound::OUTCOME_CONFIRMED])->save();
        $this->assertNull($this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->json('work_order.awaiting_answer'));
    }

    public function test_the_crew_photo_rule_still_decides_which_crew_photos_a_client_sees(): void
    {
        $this->makePhoto($this->card, 'in_progress');
        $this->makePhoto($this->card, 'completed');
        $this->makePhoto($this->card, 'reported');
        $this->asTenant();

        $this->assertCount(2, $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->json('work_order.photos'));

        $this->setCrewPhotoVisibility($this->agency, RentalPortalSetting::CREW_PHOTOS_COMPLETED_ONLY);
        $this->assertCount(1, $this->getJson("/api/v1/client/rentals/work-orders/{$this->workOrder->id}")->json('work_order.photos'));
    }

    public function test_an_external_job_shows_the_contractors_name_and_nothing_else_about_them(): void
    {
        $external = $this->externalJob();
        $this->asTenant();

        $order = $this->getJson("/api/v1/client/rentals/work-orders/{$external->id}")->assertOk()->json('work_order');

        $this->assertSame('external_contractor', $order['who']);
        $this->assertSame('Ramsgate Plumbing', $order['contractor_name']);
        $this->assertStringNotContainsString('@example.invalid', json_encode($order), 'no contractor contact details');
    }

    // ── The fault report shows its work order's stage ────────────────────

    public function test_the_tenants_fault_report_shows_the_work_orders_plain_stage(): void
    {
        $fault = $this->faultReport();
        $fault->forceFill(['rental_work_order_id' => $this->workOrder->id, 'status' => RentalFaultReport::STATUS_WORK_ORDER_RAISED])->save();
        $this->card->forceFill(['status' => RentalJobCard::STATUS_SCHEDULED])->save();
        $this->workOrder->forceFill(['status' => 'reported'])->save();
        $this->asTenant();

        $payload = $this->getJson("/api/v1/client/rentals/fault-reports/{$fault->id}")->assertOk()->json('fault_report');

        $this->assertSame($this->workOrder->id, $payload['work_order_stage']['id']);
        $this->assertSame('Appointment set', $payload['work_order_stage']['stage_label']);

        $plain = $this->faultReport(['title' => 'No work order yet']);
        $this->assertNull($this->getJson("/api/v1/client/rentals/fault-reports/{$plain->id}")->json('fault_report.work_order_stage'));
    }
}
