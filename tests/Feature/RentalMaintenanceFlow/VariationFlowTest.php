<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalOwnerVariationAutoMail;
use App\Mail\Rentals\RentalOwnerVariationMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Property;
use App\Models\RentalApproval;
use App\Models\RentalApprovalDecision;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderVariation;
use App\Models\User;
use App\Services\Rentals\CrewApprovalBlock;
use App\Services\Rentals\CrewViewContext;
use App\Services\Rentals\RentalJobCardService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.7 — variations: extra work after the owner approved an amount. Within the owner's terms it is
 * auto-approved, logged and the owner is told; beyond them it waits for the owner (the main job carries on, the extra waits);
 * the owner decides in the portal or the office captures the reply; a decision must carry the revision the owner saw (stale → 409);
 * a decline takes the lines out; a total that falls back withdraws the request; nothing is ever deleted.
 */
final class VariationFlowTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    private RentalJobCard $card;
    private RentalWorkOrder $wo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Variation');
        $this->property->forceFill(['rental_variation_tolerance_percent' => 10])->save();
        [$card] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'Owner approved R1,000']);
        $this->card = $card->fresh();
        $this->wo = $wo->fresh();
        $this->mailbox->sent = [];   // forget the quote mail
        $this->travel(5)->seconds();  // every extra below is clearly later than the approval
    }

    private function addExtra(string $description, float $price, array $extra = []): RentalJobCardLine
    {
        $this->travel(2)->seconds();

        return app(RentalJobCardService::class)->addLine($this->card->fresh(), array_merge(['type' => 'part', 'description' => $description, 'quantity' => 1, 'unit' => 'each', 'unit_price' => $price], $extra), $this->admin);
    }

    private function openVariation(): ?RentalWorkOrderVariation
    {
        return $this->wo->fresh()->openVariation();
    }

    private function asLandlord(?Contact $contact = null): void
    {
        Sanctum::actingAs($this->clientFor($contact ?? $this->landlord), ['client']);
    }

    private function crewBlock(): array
    {
        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card->fresh(), $this->admin);
        $ctx = new CrewViewContext(
            agencyId: $this->agency->id, crewId: $this->crew->id, tokenId: $issued['token']->id, via: CrewViewContext::VIA_JOB_LINK,
            showCosts: false, showTenantContact: false, ip: '203.0.113.9', userAgent: 'TestPhone', actorLabel: 'via crew link',
        );

        return CrewApprovalBlock::for($this->card->fresh(), $ctx);
    }

    // ── within the owner's terms ─────────────────────────────────────

    public function test_an_extra_within_the_tolerance_is_auto_approved_logged_and_the_owner_is_told(): void
    {
        $line = $this->addExtra('Isolator valve', 80.0);

        $variation = $this->wo->fresh()->variations()->sole();
        $this->assertSame(RentalWorkOrderVariation::STATUS_AUTO_APPROVED, $variation->status);
        $this->assertSame('1000.00', $variation->baseline_amount);
        $this->assertSame('80.00', $variation->extra_amount);
        $this->assertSame('1080.00', $variation->new_total);
        $this->assertSame('variation_tolerance', $variation->term_basis);
        $this->assertSame('1000.00', $this->wo->fresh()->approved_amount, 'an auto-approved extra never moves the approved amount');
        $this->assertSame($variation->id, $line->fresh()->rental_work_order_variation_id);
        $this->assertSame(RentalJobCardLine::OFFICE_ACCEPTED, $line->fresh()->office_status);
        $this->assertNull($this->openVariation());

        $row = RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_variation_id', $variation->id)->sole();
        $this->assertSame('auto_approved', $row->decision);
        $this->assertSame('variation_tolerance', $row->term_key);

        $mail = $this->sent(RentalOwnerVariationAutoMail::class);
        $this->assertCount(1, $mail, 'the owner is told about every auto-approved extra');
        $html = $mail[0]->render();
        $this->assertStringContainsString('Isolator valve', $html);
        $this->assertStringContainsString('R1,080.00', $html);
        $this->assertStringContainsString('10 % tolerance you agreed', $html);
        $this->assertSame([], $this->sent(RentalOwnerVariationMail::class));
    }

    public function test_the_owner_is_not_emailed_about_an_auto_approved_extra_when_the_agency_switches_that_off(): void
    {
        $this->setting(['notify_landlord_on_auto_variation' => false]);

        $this->addExtra('Isolator valve', 80.0);

        $this->assertSame(RentalWorkOrderVariation::STATUS_AUTO_APPROVED, $this->wo->fresh()->variations()->sole()->status);
        $this->assertSame([], $this->sent());
    }

    public function test_pressing_the_same_total_again_does_not_raise_a_second_variation(): void
    {
        $line = $this->addExtra('Isolator valve', 80.0);
        $this->assertSame(1, $this->wo->fresh()->variations()->count());

        app(RentalJobCardService::class)->updateLine($this->card->fresh(), $line->fresh(), ['unit_price' => 80], $this->admin);

        $this->assertSame(1, $this->wo->fresh()->variations()->count(), 'nothing changed in the total');
        $this->assertCount(1, $this->sent(RentalOwnerVariationAutoMail::class));
    }

    public function test_a_decrease_never_needs_approval(): void
    {
        $line = $this->addExtra('Isolator valve', 80.0);

        app(RentalJobCardService::class)->archiveLine($this->card->fresh(), $line->fresh(), $this->admin);

        $this->assertNull($this->openVariation());
        $this->assertSame(1, $this->wo->fresh()->variations()->count(), 'the earlier auto-approved step stays as history');
    }

    // ── beyond the owner's terms ─────────────────────────────────────

    public function test_an_extra_beyond_the_tolerance_waits_for_the_owner_and_the_owner_is_asked(): void
    {
        $line = $this->addExtra('Replace the whole manifold', 300.0, ['unit' => 'each']);

        $open = $this->openVariation();
        $this->assertNotNull($open);
        $this->assertSame(RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $open->status);
        $this->assertSame('300.00', $open->extra_amount);
        $this->assertSame('1300.00', $open->new_total);
        $this->assertSame($open->id, $line->fresh()->rental_work_order_variation_id);
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $this->wo->fresh()->owner_approval_status, 'the main job keeps its approval');
        $this->assertSame('1000.00', $this->wo->fresh()->approved_amount);
        $this->assertNotNull($open->term_text, 'the estimate wording is snapshotted on the request');

        $mails = $this->sent(RentalOwnerVariationMail::class);
        $this->assertCount(1, $mails);
        $this->assertSame($this->landlord->email, $this->mailbox->sent[0][0]);
        $this->assertStringContainsString('Variation Notice', (string) $mails[0]->attachmentName());
        $html = $mails[0]->render();
        $this->assertStringContainsString('R1,300.00', $html);
        $this->assertStringContainsString('estimate', strtolower($html));
        $this->assertStringNotContainsStringIgnoringCase('margin', $this->visibleText($html));
        $this->assertNotNull($open->fresh()->mail_sent_at);
    }

    public function test_the_variation_notice_pdf_is_selling_only_and_renders(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $variation = $this->openVariation();

        $pdf = app(\App\Services\Rentals\RentalDocumentPdfService::class)->variationNoticePdf($variation)->output();

        $this->assertStringStartsWith('%PDF', $pdf);
        $html = view('corex.rental-work-orders.variation-notice-pdf', [
            'variation' => $variation, 'workOrder' => $this->wo->fresh(), 'lines' => $variation->lines()->get(), 'original' => ['amount' => 1000.0, 'revision' => 1, 'date' => '2026-10-06'],
            'photos' => [], 'vatRegistered' => false, 'vatNumber' => null, 'logo' => null, 'agencyName' => 'Variation Agency',
        ])->render();
        $this->assertStringContainsString('Replace the whole manifold', $html);
        $this->assertStringContainsString('R1,300.00', $html);
        $text = $this->visibleText($html);
        $this->assertStringNotContainsStringIgnoringCase('margin', $text);
        $this->assertStringNotContainsStringIgnoringCase('markup', $text);
        $this->assertStringContainsString('estimate', strtolower($text));
    }

    public function test_the_crew_sees_the_state_of_every_extra_and_never_an_amount(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $this->addExtra('Isolator valve', 20.0);   // joins the open request

        $block = $this->crewBlock();

        $this->assertTrue($block['hold']);
        $this->assertSame(['Awaiting owner — do not start', 'Awaiting owner — do not start'], array_column($block['extras'], 'label'));
        $json = json_encode($block);
        foreach (['300', '320', '1300', '1000', 'baseline', 'approved_amount', 'quote', 'landlord'] as $needle) {
            $this->assertStringNotContainsString($needle, $json, "the crew block must not carry {$needle}");
        }

        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($this->card->fresh(), $this->admin);
        $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk()->assertSee('Awaiting owner — do not start')->assertSee('Replace the whole manifold');
    }

    public function test_more_extras_join_the_open_request_and_bump_its_revision(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $first = $this->openVariation();
        $this->assertSame(1, $first->revision);

        $this->addExtra('New isolator', 150.0);

        $open = $this->openVariation();
        $this->assertSame($first->id, $open->id, 'one open request, not two');
        $this->assertSame(2, $open->revision);
        $this->assertSame('450.00', $open->extra_amount);
        $this->assertSame('1450.00', $open->new_total);
        $this->assertSame(2, $open->lines()->count());
        $mails = $this->sent(RentalOwnerVariationMail::class);
        $this->assertCount(2, $mails, 'the owner is sent the updated request');
        $this->assertStringContainsString('Updated request', $mails[1]->envelope()->subject);
    }

    public function test_the_total_falling_back_withdraws_the_open_request(): void
    {
        $line = $this->addExtra('Replace the whole manifold', 300.0);
        $this->assertNotNull($this->openVariation());

        app(RentalJobCardService::class)->archiveLine($this->card->fresh(), $line->fresh(), $this->admin);

        $this->assertNull($this->openVariation());
        $this->assertSame(RentalWorkOrderVariation::STATUS_WITHDRAWN, $this->wo->fresh()->variations()->sole()->status);
    }

    public function test_cancelling_the_card_withdraws_an_open_request(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);

        $this->card->fresh()->cancel($this->admin, 'Owner changed their mind');

        $this->assertSame(RentalWorkOrderVariation::STATUS_WITHDRAWN, $this->wo->fresh()->variations()->sole()->status);
    }

    // ── the owner decides in the portal ──────────────────────────────

    public function test_the_owner_approves_in_the_portal(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $open = $this->openVariation();
        $this->asLandlord();

        $listed = $this->getJson('/api/v1/client/rentals/landlord/decisions')->assertOk()->json('variations');
        $this->assertCount(1, $listed);
        $this->assertEquals(1300.0, $listed[0]['new_total']);
        $this->assertSame('Replace the whole manifold', $listed[0]['lines'][0]['description']);
        $this->assertStringNotContainsStringIgnoringCase('cost', json_encode($listed), 'owner-facing figures only');

        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $open->id . '/decision', ['decision' => 'approve', 'revision' => 1, 'note' => 'Go ahead'])
            ->assertOk()->assertJsonPath('variation.status', 'approved');

        $fresh = $this->wo->fresh();
        $this->assertSame('1300.00', $fresh->approved_amount, 'the owner-approved total is the new baseline');
        $this->assertSame(RentalWorkOrder::BASIS_OWNER_DECISION, $fresh->approval_basis);
        $row = RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_variation_id', $open->id)->where('decision', 'approved')->sole();
        $this->assertSame('owner', $row->decided_by);
        $this->assertSame($this->landlord->id, (int) $row->decided_by_contact_id);
        $this->assertSame('owner_decision', $row->term_key);
        $this->assertStringContainsString('Approved by the owner in the portal', $row->note);
        $evidence = RentalApproval::withoutGlobalScopes()->where('rental_work_order_variation_id', $open->id)->sole();
        $this->assertSame(RentalApproval::EVIDENCE_PORTAL, $evidence->evidence_type);
        $this->assertSame($this->landlord->id, (int) $evidence->recorded_by_contact_id);

        $this->getJson('/api/v1/client/rentals/landlord/decisions')->assertOk()->assertJsonCount(0, 'variations');
        $this->assertSame('approved', $this->crewBlock()['extras'][0]['state']);
    }

    public function test_the_owner_declines_in_the_portal_and_the_lines_come_out(): void
    {
        $line = $this->addExtra('Replace the whole manifold', 300.0);
        $open = $this->openVariation();
        $this->asLandlord();

        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $open->id . '/decision', ['decision' => 'decline', 'revision' => 1])->assertOk();

        $this->assertSame(RentalWorkOrderVariation::STATUS_DECLINED, $open->fresh()->status);
        $this->assertSame(RentalJobCardLine::OFFICE_DECLINED_BY_OWNER, $line->fresh()->office_status);
        $this->assertSame('1000.00', $this->wo->fresh()->approved_amount, 'the approved scope stands');
        $this->assertSame(RentalWorkOrder::APPROVAL_APPROVED, $this->wo->fresh()->owner_approval_status);
        $this->assertSame('declined', $this->crewBlock()['extras'][0]['state']);
        $this->assertSame('Declined by owner', $this->crewBlock()['extras'][0]['label']);
        $this->assertSame(0, RentalJobCardLine::withoutGlobalScopes()->onlyTrashed()->count(), 'declined, never deleted');
    }

    public function test_a_stale_revision_is_refused_with_409_and_changes_nothing(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $open = $this->openVariation();
        $this->addExtra('New isolator', 150.0);   // revision 2 now
        $this->asLandlord();

        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $open->id . '/decision', ['decision' => 'approve', 'revision' => 1])
            ->assertStatus(409)->assertJsonPath('message', 'This request changed — please refresh and look at the latest version.');

        $this->assertSame(RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $open->fresh()->status);
        $this->assertSame('1000.00', $this->wo->fresh()->approved_amount);
    }

    public function test_a_decision_on_something_not_waiting_is_a_422(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $open = $this->openVariation();
        $this->asLandlord();
        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $open->id . '/decision', ['decision' => 'decline', 'revision' => 1])->assertOk();

        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $open->id . '/decision', ['decision' => 'approve', 'revision' => 1])->assertStatus(422);
        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $open->id . '/decision', ['decision' => 'maybe', 'revision' => 1])->assertStatus(422);
        // a decision that does not say which revision it saw is refused outright
        $this->addExtra('Another extra', 300.0);
        $second = $this->openVariation();
        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $second->id . '/decision', ['decision' => 'approve'])->assertStatus(422);
        $this->assertSame(RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $second->fresh()->status);
    }

    public function test_another_landlord_and_another_agency_cannot_reach_the_request(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $open = $this->openVariation();

        // a landlord of a DIFFERENT property in the same agency
        $otherProperty = Property::forceCreate([
            'agency_id' => $this->agency->id, 'agent_id' => $this->admin->id, 'branch_id' => $this->branch->id, 'title' => '9 Other Road', 'status' => 'active', 'listing_type' => 'rental',
        ]);
        $neighbour = Contact::create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'first_name' => 'Nina', 'last_name' => 'Neighbour', 'email' => 'nina-' . uniqid() . '@example.invalid']);
        $otherProperty->contacts()->attach($neighbour->id, ['role' => 'landlord']);
        $this->asLandlord($neighbour);
        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $open->id . '/decision', ['decision' => 'approve', 'revision' => 1])->assertStatus(404);
        $this->getJson('/api/v1/client/rentals/landlord/decisions')->assertOk()->assertJsonCount(0, 'variations');

        // a landlord of another agency
        $other = Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $otherBranch = Branch::forceCreate(['name' => 'Main', 'agency_id' => $other->id]);
        $foreign = Contact::create(['agency_id' => $other->id, 'branch_id' => $otherBranch->id, 'first_name' => 'Fiona', 'last_name' => 'Foreign', 'email' => 'fiona-' . uniqid() . '@example.invalid']);
        $this->asLandlord($foreign);
        $this->postJson('/api/v1/client/rentals/landlord/variations/' . $open->id . '/decision', ['decision' => 'approve', 'revision' => 1])->assertStatus(404);

        $this->assertSame(RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $open->fresh()->status);
    }

    // ── the office captures the owner's reply ────────────────────────

    public function test_the_office_records_the_owners_reply_and_it_is_the_same_decision(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $open = $this->openVariation();

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.variations.decision', [$this->wo, $open]), [
            'decision' => 'approve', 'evidence_type' => 'whatsapp', 'evidence_text' => 'Owner replied "fine, do it" on WhatsApp', 'revision' => 1,
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(RentalWorkOrderVariation::STATUS_APPROVED, $open->fresh()->status);
        $this->assertSame('agent_capture', $open->fresh()->decided_via);
        $this->assertSame($this->admin->id, (int) $open->fresh()->decided_by_user_id);
        $this->assertSame('1300.00', $this->wo->fresh()->approved_amount);
        $evidence = RentalApproval::withoutGlobalScopes()->where('rental_work_order_variation_id', $open->id)->sole();
        $this->assertSame('whatsapp', $evidence->evidence_type);
        $this->assertStringContainsString('fine, do it', $evidence->evidence_text);
        $this->assertStringContainsString('recorded by ' . $this->admin->name, RentalApprovalDecision::withoutGlobalScopes()->where('rental_work_order_variation_id', $open->id)->where('decision', 'approved')->value('note'));
    }

    public function test_the_office_reply_needs_the_evidence_text_and_the_permission(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $open = $this->openVariation();

        $this->actingAs($this->admin)->from('/back')->post(route('corex.rental-work-orders.variations.decision', [$this->wo, $open]), [
            'decision' => 'approve', 'evidence_type' => 'email', 'evidence_text' => '',
        ])->assertSessionHasErrors('evidence_text');
        $this->assertSame(RentalWorkOrderVariation::STATUS_AWAITING_OWNER, $open->fresh()->status);

        $agent = $this->agentWith(['rental_work_orders.view' => 'all']);   // may open work orders, may not record decisions
        $this->actingAs($agent)->post(route('corex.rental-work-orders.variations.decision', [$this->wo, $open]), [
            'decision' => 'approve', 'evidence_type' => 'email', 'evidence_text' => 'x',
        ])->assertForbidden();
        $this->actingAs($agent)->post(route('corex.rental-work-orders.variations.resend', [$this->wo, $open]))->assertForbidden();
    }

    public function test_resend_request_mails_the_owner_again(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);
        $open = $this->openVariation();
        $this->mailbox->sent = [];

        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.variations.resend', [$this->wo, $open]))->assertRedirect()->assertSessionHas('success');

        $this->assertCount(1, $this->sent(RentalOwnerVariationMail::class));
    }

    public function test_the_work_order_and_job_card_screens_render_the_variation_panel(): void
    {
        $this->addExtra('Replace the whole manifold', 300.0);

        $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $this->wo))
            ->assertOk()->assertSee('Extra work (variations)')->assertSee('Awaiting owner')->assertSee('Replace the whole manifold')
            ->assertSee('Why was this approved?')->assertSee('Variation awaiting owner');
        $this->actingAs($this->admin)->get(route('corex.rental-job-cards.show', $this->card))
            ->assertOk()->assertSee('Variation awaiting owner')->assertSee('The owner has approved this job')->assertDontSee('Re-send revised quote');
    }

    public function test_the_resend_revised_quote_is_refused_after_approval(): void
    {
        $this->actingAs($this->admin)->post(route('corex.rental-job-cards.send-quote', $this->card))->assertSessionHasErrors('rental_job_card');
        $this->assertSame(1, $this->card->quoteRevisions()->count());
    }
}
