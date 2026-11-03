<?php

declare(strict_types=1);

namespace Tests\Feature\RentalFaultFlow;

use App\Models\RentalAuthorisation;
use App\Models\RentalWorkOrder;
use App\Services\Rentals\RentalAuthorisationService;
use App\Services\Rentals\RentalQuoteAutoAdvanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalMaintenanceFlow\Concerns\BuildsApprovalFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rentals-faults-work-orders.md section 31.4 (S4, one click per stage). Cost authority stays; the agent just no longer walks the chain by hand:
 * a quote is selected for them, inside the owner's repair limit it is authorised at once (the go-ahead follows), above it the agent has ONE button - "Owner
 * authorised by phone/WhatsApp" (who / when / how recorded) - or "Send to owner for approval".
 */
final class OneClickSendAndAuthoriseTest extends TestCase
{
    use BuildsApprovalFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->approvalWorld('OneClick');
        $this->linkLandlord();
    }

    private function wo(): RentalWorkOrder
    {
        return $this->externalWorkOrder();
    }

    private function quote(RentalWorkOrder $wo, float $amount)
    {
        return $wo->recordQuote(['agency_service_provider_id' => $this->supplier('Quote Plumbing')->id, 'amount' => $amount, 'quote_date' => now()->toDateString(), 'detail_text' => 'x'], null, 'via contractor link');
    }

    private function costRows(RentalWorkOrder $wo)
    {
        return RentalAuthorisation::withoutGlobalScopes()->where('rental_work_order_id', $wo->id)->where('point', 'cost')->orderBy('id')->get();
    }

    public function test_a_quote_from_the_link_is_selected_and_inside_the_limit_it_is_authorised_at_once(): void
    {
        $this->ownerLimits(1500.0);
        $wo = $this->wo();
        $q = $this->quote($wo, 1200.0);

        $out = app(RentalQuoteAutoAdvanceService::class)->afterQuote($wo, $q, $this->admin);

        $this->assertTrue($out['selected']);
        $this->assertTrue($out['authorised']);
        $this->assertTrue($q->fresh()->is_selected);
        $rows = $this->costRows($wo);
        $this->assertCount(1, $rows);
        $this->assertTrue($rows->first()->isAuthorised());
        $this->assertSame(1200.0, (float) $rows->first()->amount_up_to);
        $this->assertStringContainsString('automatically', (string) $rows->first()->note);
        $this->assertTrue(app(RentalAuthorisationService::class)->hasCostAuthority($wo->fresh()));
    }

    public function test_above_the_limit_the_quote_is_selected_but_nothing_is_authorised_and_the_owner_is_asked(): void
    {
        $this->ownerLimits(1000.0);
        $wo = $this->wo();
        $q = $this->quote($wo, 2400.0);

        $out = app(RentalQuoteAutoAdvanceService::class)->afterQuote($wo, $q, $this->admin);

        $this->assertTrue($out['selected']);
        $this->assertFalse($out['authorised']);
        $this->assertCount(0, $this->costRows($wo));
        $this->assertSame(RentalWorkOrder::APPROVAL_PENDING, $wo->fresh()->owner_approval_status);
    }

    public function test_no_limit_set_means_the_owner_decides_never_an_automatic_authorisation(): void
    {
        $wo = $this->wo();
        $q = $this->quote($wo, 300.0);
        $out = app(RentalQuoteAutoAdvanceService::class)->afterQuote($wo, $q, $this->admin);
        $this->assertFalse($out['authorised']);
        $this->assertCount(0, $this->costRows($wo));
    }

    public function test_a_second_quote_does_not_replace_a_selected_one_from_another_supplier(): void
    {
        $this->ownerLimits(5000.0);
        $wo = $this->wo();
        $first = $this->quote($wo, 900.0);
        app(RentalQuoteAutoAdvanceService::class)->afterQuote($wo, $first, $this->admin);
        $other = $wo->recordQuote(['agency_service_provider_id' => $this->supplier('Other Co', 'other@example.invalid')->id, 'amount' => 700.0, 'quote_date' => now()->toDateString(), 'detail_text' => 'y'], null);

        $out = app(RentalQuoteAutoAdvanceService::class)->afterQuote($wo->fresh(), $other, $this->admin);

        $this->assertFalse($out['selected']);
        $this->assertTrue($first->fresh()->is_selected);
        $this->assertFalse($other->fresh()->is_selected);
    }

    public function test_one_click_owner_authorised_records_who_when_and_how_and_authorises_the_asked_amount(): void
    {
        $this->ownerLimits(1000.0);
        $wo = $this->wo();
        $q = $this->quote($wo, 2400.0);
        app(RentalQuoteAutoAdvanceService::class)->afterQuote($wo, $q, $this->admin);

        $res = $this->actingAs($this->admin)->post(route('corex.rental-work-orders.cost.owner-authorised', $wo->fresh()));

        $res->assertSessionHasNoErrors();
        $row = $this->costRows($wo)->last();
        $this->assertNotNull($row);
        $this->assertTrue($row->isAuthorised());
        $this->assertSame(2400.0, (float) $row->amount_up_to);
        $this->assertSame($this->admin->id, (int) $row->by_user_id, 'who');
        $this->assertNotNull($row->told_at, 'when');
        $this->assertSame(RentalAuthorisation::VIA_PHONE_WHATSAPP, $row->told_via, 'how');
        $this->assertNotNull($row->owner_contact_id, 'which owner');
        $this->assertSame('phone / WhatsApp', RentalAuthorisation::viaLabel($row->told_via));
    }

    public function test_the_next_bar_offers_the_one_click_owner_button_above_the_limit_and_nothing_chained_inside_it(): void
    {
        $this->ownerLimits(1000.0);
        $wo = $this->wo();
        $q = $this->quote($wo, 2400.0);
        app(RentalQuoteAutoAdvanceService::class)->afterQuote($wo, $q, $this->admin);
        $html = $this->actingAs($this->admin)->get(route('corex.rental-work-orders.show', $wo->fresh()))->assertOk()->getContent();

        $this->assertStringContainsString('Owner authorised by phone/WhatsApp', $html);
        $this->assertStringContainsString('/cost/owner-authorised', $html);
        $this->assertStringNotContainsString('data-wo-go', $html);
    }

    public function test_the_fault_picker_has_a_send_to_button_per_contractor_and_per_crew_and_the_pick_creates_the_work_order(): void
    {
        $html = file_get_contents(resource_path('views/corex/rental-fault-reports/_raise-work-order-form.blade.php'));
        $this->assertStringContainsString('name="pick" value="s:{{ $c[\'id\'] }}"', $html);
        $this->assertStringContainsString('name="pick" value="c:{{ $c->id }}"', $html);
        $this->assertStringContainsString('Send to {{ $c[\'name\'] }}', $html);
        $this->assertStringNotContainsString('type="radio"', $html, 'no radio, no second step');
    }
}
