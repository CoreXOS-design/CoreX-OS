<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Mail\Rentals\RentalContractorWorkOrderMail;
use App\Mail\Rentals\RentalMaintenanceMail;
use App\Mail\Rentals\RentalOwnerFinalStatementMail;
use App\Mail\Rentals\RentalOwnerQuoteMail;
use App\Mail\Rentals\RentalOwnerVariationAutoMail;
use App\Mail\Rentals\RentalOwnerVariationMail;
use App\Mail\Rentals\RentalWorkOrderOwnerMail;
use App\Mail\Rentals\RentalWorkOrderSupplierMail;
use App\Mail\Signatures\BaseSignatureMail;
use App\Models\RentalJobCard;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalJobCardService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.16 — every owner / contractor mail of the approvals flow goes through the AGENCY MAILBOX PATH
 * (RentalMailDispatcher, sent AS the agent who pressed the button), never a plain `Mail::to()->send(Mailable)`. The retired plain mails
 * (RentalWorkOrderOwnerMail, RentalWorkOrderSupplierMail, the plain "decision needed" for work orders) are never sent any more.
 */
final class OwnerMailsAgencyMailboxTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('Mailbox');
        $this->property->forceFill(['rental_variation_tolerance_percent' => 10])->save();
    }

    public function test_the_whole_flow_uses_the_dispatcher_and_never_a_plain_mailable(): void
    {
        // internal job: quote → owner approves → auto extra → big extra → close
        [$card, $wo] = $this->internalJob(1000.0);
        $wo = $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok']);
        $this->travel(5)->seconds();
        app(RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'Valve', 'quantity' => 1, 'unit_price' => 50], $this->admin);
        $this->travel(5)->seconds();
        app(RentalJobCardService::class)->addLine($card->fresh(), ['type' => 'part', 'description' => 'Big thing', 'quantity' => 1, 'unit_price' => 900], $this->admin);
        $wo->fresh()->variations()->where('status', 'awaiting_owner')->get()->each(fn ($v) => app(\App\Services\Rentals\RentalApprovalGateService::class)->recordVariationDecision($v, 'approve', ['via' => 'agent_capture', 'evidence_type' => 'email', 'evidence_text' => 'ok'], ['user' => $this->admin]));

        // external job: quote over the limit → approved → sent to the contractor → closed
        $supplier = $this->supplier();
        $ext = $this->externalWorkOrder();
        $ext->selectQuote($ext->recordQuote(['agency_service_provider_id' => $supplier->id, 'amount' => 2000, 'quote_date' => now(), 'detail_text' => 'q'], $this->admin), $this->admin);
        $ext->fresh()->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok']);
        $this->actingAs($this->admin)->post(route('corex.rental-work-orders.assign-supplier', $ext), ['agency_service_provider_id' => $supplier->id])->assertSessionHasNoErrors();
        $ext->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 2000]);

        $classes = array_map(fn ($m) => $m::class, $this->sent());
        foreach ([RentalOwnerQuoteMail::class, RentalOwnerVariationAutoMail::class, RentalOwnerVariationMail::class, RentalContractorWorkOrderMail::class, RentalOwnerFinalStatementMail::class] as $expected) {
            $this->assertContains($expected, $classes, "{$expected} must go through the dispatcher");
        }
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Mail::assertNotSent(RentalWorkOrderOwnerMail::class);
        Mail::assertNotSent(RentalWorkOrderSupplierMail::class);
    }

    public function test_every_mail_is_a_signature_mail_sent_as_the_agent_to_a_safe_address_and_none_is_queued(): void
    {
        [$card] = $this->internalJob(1500.0);
        $wo = $this->sendQuote($card);
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok']);
        $wo->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 1500]);

        $this->assertNotEmpty($this->mailbox->sent);
        foreach ($this->mailbox->sent as [$to, $mail]) {
            $this->assertStringEndsWith('@example.invalid', (string) $to);
            $this->assertInstanceOf(BaseSignatureMail::class, $mail);
            $this->assertInstanceOf(RentalMaintenanceMail::class, $mail);
            $this->assertNotInstanceOf(ShouldQueue::class, $mail);
            $this->assertSame($this->admin->id, $mail->sendingAgentId(), 'sent AS the property\'s responsible agent / the agent who pressed the button');
        }
    }

    public function test_the_owner_mails_name_the_agency_and_never_a_hardcoded_one_nor_a_cost_word(): void
    {
        [$card] = $this->internalJob(1500.0);
        $this->sendQuote($card);

        $mail = $this->sent(RentalOwnerQuoteMail::class)[0];
        $html = $mail->render();

        $this->assertStringContainsString($this->agency->name, $html);
        $text = $this->visibleText($html);
        $this->assertStringNotContainsString('HFC', $text);
        $this->assertStringNotContainsString('Home Finders', $text);
        $this->assertStringNotContainsStringIgnoringCase('margin', $text);
        $this->assertStringContainsString($this->agency->name, $mail->envelope()->subject);
    }

    public function test_a_mail_that_cannot_be_sent_never_breaks_the_office_action(): void
    {
        $boom = new class extends \App\Services\Rentals\RentalMailDispatcher {
            public function __construct() {}

            public function send(?string $recipientEmail, BaseSignatureMail $mail): void
            {
                throw new \RuntimeException('SMTP is down');
            }
        };
        $this->app->instance(\App\Services\Rentals\RentalMailDispatcher::class, $boom);
        [$card] = $this->internalJob(1500.0);
        $wo = $this->sendQuote($card);   // the quote mail fails silently…
        $wo->recordApproval($this->admin, ['decision' => 'approved', 'evidence_type' => 'email', 'evidence_text' => 'ok']);

        $wo->fresh()->complete($this->admin, ['paid_by' => 'owner', 'cost_amount' => 1500]);   // …and so does the final statement

        $this->assertSame(RentalWorkOrder::STATUS_COMPLETED, $wo->fresh()->status, 'closing still works');
        $this->assertNotSame(RentalJobCard::STATUS_DRAFT, $card->fresh()->status, 'and so did sending the quote');
    }

    public function test_an_owner_with_no_email_is_noted_not_an_error(): void
    {
        $this->landlord->forceFill(['email' => null])->save();
        [$card] = $this->internalJob(1500.0);

        $wo = $this->sendQuote($card);

        $this->assertSame([], $this->sent());
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->owner_approval_status);
    }
}
