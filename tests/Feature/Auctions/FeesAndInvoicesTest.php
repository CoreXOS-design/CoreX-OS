<?php

declare(strict_types=1);

namespace Tests\Feature\Auctions;

use App\Mail\Auctions\AuctionBidderMail;
use App\Models\Agency;
use App\Models\AgencyAuctionSettings;
use App\Models\Auction;
use App\Models\AuctionInvoice;
use App\Models\AuctionLot;
use App\Models\ClientUser;
use App\Models\Contact;
use App\Models\Deal;
use App\Models\PerformanceSetting;
use App\Services\Auctions\AuctionFeeCalculator;
use App\Services\Auctions\AuctionInvoiceService;
use App\Services\Auctions\BidEngine;
use App\Services\Auctions\LotResultService;
use App\Services\Property\ContactPropertyLinker;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/** Fees, deposits and bidder invoices (.ai/specs/auctions.md §17). */
final class FeesAndInvoicesTest extends BiddingTestCase
{
    private function settings(array $attrs): void
    {
        AgencyAuctionSettings::updateOrCreate(['agency_id' => $this->agency->id], $attrs);
    }

    private function calc(float $hammer = 1000000): array
    {
        return app(AuctionFeeCalculator::class)->forSale($this->auc->fresh()->load('increments'), $hammer);
    }

    /** A timed lot that ended with one bid of $amount, sold outright (reserve is R150 000). */
    private function sold(float $amount = 1000000, string $email = 'win@example.com'): AuctionLot
    {
        $this->lot->forceFill(['opening_bid' => 100000])->save();
        $reg = $this->registration($this->buyer($email));
        app(BidEngine::class)->place($this->lot, $reg, $amount);
        $this->lot->forceFill(['closes_at' => now()->subMinute()])->save();
        app(LotResultService::class)->conclude($this->lot->id);

        return $this->lot->fresh();
    }

    private function invoiceFor(AuctionLot $lot): AuctionInvoice
    {
        return AuctionInvoice::withoutGlobalScopes()->where('lot_id', $lot->id)->firstOrFail();
    }

    private function seller(): void
    {
        $seller = Contact::create(['first_name' => 'Sarah', 'last_name' => 'Seller', 'email' => 'sarah@example.com', 'phone' => '0829990000', 'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id]);
        ContactPropertyLinker::link($seller->id, $this->lot->property_id, 'seller');
    }

    // ── the sum ────────────────────────────────────────────────────────────

    public function test_the_worked_example_from_the_spec(): void
    {
        $this->settings(['buyers_commission_percent' => 7.5, 'deposit_percent' => 10]);

        $f = $this->calc(1000000);

        $this->assertSame(75000.0, $f['commission_amount']);
        $this->assertSame(15.0, $f['vat_rate']);
        $this->assertSame(11250.0, $f['vat_amount']);
        $this->assertSame(1086250.0, $f['total_amount']);
        $this->assertSame(100000.0, $f['deposit_amount']);
        $this->assertSame(986250.0, $f['balance_amount']);
        $this->assertSame(86250.0, app(AuctionFeeCalculator::class)->dealCommission($this->auc->fresh(), 1000000));
    }

    public function test_by_default_an_agency_charges_no_commission_and_a_ten_percent_deposit(): void
    {
        $f = $this->calc(500000);

        $this->assertSame(0.0, $f['commission_amount']);
        $this->assertSame(0.0, $f['vat_amount'], 'no VAT where there is no commission');
        $this->assertSame(500000.0, $f['total_amount']);
        $this->assertSame(50000.0, $f['deposit_amount']);
    }

    public function test_the_minimum_commission_applies_and_the_auction_overrides_the_agency_default(): void
    {
        $this->settings(['buyers_commission_percent' => 5, 'buyers_commission_minimum' => 20000]);
        $this->assertSame(20000.0, $this->calc(100000)['commission_amount'], '5% of R100 000 is below the R20 000 minimum');
        $this->assertSame(50000.0, $this->calc(1000000)['commission_amount']);

        $this->auc->forceFill(['buyers_commission_percent' => 10, 'deposit_percent' => 25])->save();
        $f = $this->calc(1000000);
        $this->assertSame(100000.0, $f['commission_amount']);
        $this->assertSame(250000.0, $f['deposit_amount']);

        $this->auc->forceFill(['buyers_commission_percent' => 0])->save();
        $this->assertSame(0.0, $this->calc(1000000)['commission_amount'], 'an explicit 0 on the auction means none');
    }

    public function test_due_dates_follow_the_agency_settings_and_the_vat_rate_is_the_agencys_own(): void
    {
        $this->settings(['buyers_commission_percent' => 10, 'deposit_due_days' => 5, 'balance_due_days' => 21]);
        PerformanceSetting::updateOrCreate(['key' => 'vat_rate', 'agency_id' => $this->agency->id], ['value' => 14]);

        $f = $this->calc(100000);

        $this->assertSame(1400.0, $f['vat_amount']);
        $this->assertTrue($f['deposit_due_at']->isSameDay(now()->addDays(5)));
        $this->assertTrue($f['balance_due_at']->isSameDay(now()->addDays(21)));
    }

    public function test_the_fee_notice_is_plain_english_and_empty_when_there_is_nothing_to_say(): void
    {
        $this->settings(['buyers_commission_percent' => 7.5, 'buyers_commission_minimum' => 15000, 'deposit_percent' => 10, 'deposit_due_days' => 3]);
        $notice = app(AuctionFeeCalculator::class)->notice($this->auc->fresh());
        $this->assertStringContainsString("buyer's commission of 7.5%", $notice);
        $this->assertStringContainsString('plus VAT', $notice);
        $this->assertStringContainsString('minimum R 15 000', $notice);
        $this->assertStringContainsString('deposit of 10% of the price within 3 days', $notice);

        $this->settings(['buyers_commission_percent' => 0, 'deposit_percent' => 0]);
        $this->assertSame('', app(AuctionFeeCalculator::class)->notice($this->auc->fresh()));
    }

    // ── the draft invoice and the deal ─────────────────────────────────────

    public function test_selling_a_lot_drafts_one_invoice_with_the_figures_frozen(): void
    {
        $this->settings(['buyers_commission_percent' => 7.5, 'deposit_percent' => 10, 'payment_details' => "Cape Bank\nAcc 123"]);
        $lot = $this->sold(1000000);

        $inv = $this->invoiceFor($lot);
        $this->assertSame('draft', $inv->status);
        $this->assertNull($inv->number);
        $this->assertEquals(1086250, (float) $inv->total_amount);
        $this->assertEquals(100000, (float) $inv->deposit_amount);
        $this->assertSame("Cape Bank\nAcc 123", $inv->payment_details);
        $this->assertSame($lot->winning_bid_id ? (int) \App\Models\AuctionBid::find($lot->winning_bid_id)->registration_id : 0, (int) $inv->registration_id);

        // changing the settings afterwards never rewrites it
        $this->settings(['buyers_commission_percent' => 20]);
        event(new \App\Events\Auction\AuctionLotSold($lot->fresh()));
        $this->assertEquals(1086250, (float) $inv->fresh()->total_amount);
        $this->assertSame(1, AuctionInvoice::withoutGlobalScopes()->where('lot_id', $lot->id)->count(), 'a second sold event drafts no second invoice');
    }

    public function test_the_deal_carries_the_buyers_commission_including_vat(): void
    {
        $this->settings(['buyers_commission_percent' => 7.5]);
        $this->seller();
        $lot = $this->sold(1000000);

        $deal = Deal::findOrFail($lot->deal_id);
        $this->assertEquals(86250, (float) $deal->total_commission);
        $this->assertStringContainsString("Buyer's commission", (string) $deal->remarks);
        $this->assertSame($lot->deal_id, $this->invoiceFor($lot)->deal_id, 'the invoice knows its deal');
    }

    public function test_a_below_reserve_sale_is_invoiced_only_once_the_seller_confirms(): void
    {
        $this->lot->forceFill(['reserve_price' => 500000])->save();
        $lot = $this->sold(300000);
        $this->assertSame('sold_subject_to_confirmation', $lot->status);
        $this->assertSame(0, AuctionInvoice::withoutGlobalScopes()->where('lot_id', $lot->id)->count());

        app(LotResultService::class)->confirmSale($lot);

        $this->assertSame('draft', $this->invoiceFor($lot)->status);
        $this->assertEquals(300000, (float) $this->invoiceFor($lot)->hammer_price);
    }

    public function test_a_sold_lot_with_no_invoice_can_be_invoiced_from_its_page(): void
    {
        $lot = $this->sold(200000);
        AuctionInvoice::withoutGlobalScopes()->where('lot_id', $lot->id)->forceDelete();

        $this->get(route('corex.auctions.lots.edit', $lot->id))->assertOk()->assertSee('Create the bidder');
        $this->post(route('corex.auctions.invoices.for-lot', $lot->id))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('draft', $this->invoiceFor($lot)->status);
        $this->get(route('corex.auctions.lots.edit', $lot->id))->assertSee('data-lot-invoice', false);
    }

    // ── issuing ────────────────────────────────────────────────────────────

    public function test_issuing_numbers_the_invoice_and_emails_the_buyer_the_pdf_as_the_agency(): void
    {
        Mail::fake();
        $this->settings(['buyers_commission_percent' => 5, 'reference_prefix' => 'CPA', 'payment_details' => 'Cape Bank 123']);
        $inv = $this->invoiceFor($this->sold(400000));

        $this->post(route('corex.auctions.invoices.issue', $inv->id))->assertSessionHasNoErrors();

        $inv->refresh();
        $this->assertMatchesRegularExpression('/^CPA-INV-\d{4}-0001$/', $inv->number);
        $this->assertSame('issued', $inv->status);
        $this->assertNotNull($inv->issued_at);
        $this->assertSame($this->admin->id, (int) $inv->issued_by);
        Mail::assertSent(AuctionBidderMail::class, fn ($m) => $m->kind === 'invoice' && $m->hasTo('win@example.com') && $m->agencyName === 'Cape Auctions'
            && str_starts_with((string) $m->attachmentBytes, '%PDF') && str_contains((string) $m->attachmentName, 'CPA-INV'));
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionInvoiceIssued']);
    }

    public function test_numbers_run_on_within_the_agency_and_an_invoice_is_issued_once(): void
    {
        Mail::fake();
        $first = $this->invoiceFor($this->sold(400000));
        $this->post(route('corex.auctions.invoices.issue', $first->id));
        $this->post(route('corex.auctions.invoices.issue', $first->id))->assertSessionHasErrors('invoice');

        $second = AuctionInvoice::withoutAgencyStamping(fn () => AuctionInvoice::create($first->only(['agency_id', 'auction_id', 'lot_id', 'registration_id', 'hammer_price', 'total_amount']) + ['status' => 'draft']));
        $this->post(route('corex.auctions.invoices.issue', $second->id));

        $this->assertStringEndsWith('0001', $first->fresh()->number);
        $this->assertStringEndsWith('0002', $second->fresh()->number);
    }

    public function test_an_invoice_with_nothing_to_pay_cannot_be_issued(): void
    {
        $inv = $this->invoiceFor($this->sold(400000));
        $inv->forceFill(['total_amount' => 0])->save();

        $this->post(route('corex.auctions.invoices.issue', $inv->id))->assertSessionHasErrors('invoice');
        $this->assertSame('draft', $inv->fresh()->status);
    }

    public function test_the_draft_payment_details_and_notes_are_editable_only_while_a_draft(): void
    {
        Mail::fake();
        $inv = $this->invoiceFor($this->sold(400000));

        $this->put(route('corex.auctions.invoices.notes', $inv->id), ['payment_details' => 'New bank', 'notes' => 'Thanks'])->assertSessionHasNoErrors();
        $this->assertSame('New bank', $inv->fresh()->payment_details);

        $this->post(route('corex.auctions.invoices.issue', $inv->id));
        $this->put(route('corex.auctions.invoices.notes', $inv->id), ['payment_details' => 'Changed after issue'])->assertStatus(422);
        $this->assertSame('New bank', $inv->fresh()->payment_details);
    }

    // ── payments ───────────────────────────────────────────────────────────

    private function issued(float $amount = 1000000): AuctionInvoice
    {
        Mail::fake();
        $this->settings(['buyers_commission_percent' => 7.5, 'deposit_percent' => 10]);
        $inv = $this->invoiceFor($this->sold($amount));
        app(AuctionInvoiceService::class)->issue($inv, $this->admin);

        return $inv->fresh();
    }

    private function pay(AuctionInvoice $inv, float $amount, string $kind = 'deposit')
    {
        return $this->post(route('corex.auctions.invoices.payments.store', $inv->id), ['kind' => $kind, 'amount' => $amount, 'received_at' => now()->toDateString(), 'reference' => 'EFT 991']);
    }

    public function test_payments_move_the_invoice_from_issued_to_part_paid_to_paid(): void
    {
        $inv = $this->issued();
        $this->assertEquals(1086250, (float) $inv->total_amount);

        $this->pay($inv, 100000)->assertSessionHasNoErrors();
        $this->assertSame('part_paid', $inv->fresh()->status);
        $this->assertSame(986250.0, $inv->fresh()->outstanding());

        $this->pay($inv, 986250, 'balance')->assertSessionHasNoErrors();
        $this->assertSame('paid', $inv->fresh()->status);
        $this->assertNotNull($inv->fresh()->paid_at);
        $this->assertSame(0.0, $inv->fresh()->outstanding());
        $this->assertDatabaseHas('domain_event_log', ['event_name' => 'App\\Events\\Auction\\AuctionPaymentRecorded']);
    }

    public function test_more_than_is_owing_zero_and_a_future_date_are_refused(): void
    {
        $inv = $this->issued();

        $this->pay($inv, 2000000)->assertSessionHasErrors('amount');
        $this->pay($inv, 0)->assertSessionHasErrors('amount');
        $this->post(route('corex.auctions.invoices.payments.store', $inv->id), ['kind' => 'deposit', 'amount' => 100, 'received_at' => now()->addDay()->toDateString()])->assertSessionHasErrors('received_at');
        $this->assertSame(0, $inv->payments()->count());
    }

    public function test_payments_cannot_be_recorded_on_a_draft_or_a_cancelled_invoice(): void
    {
        $draft = $this->invoiceFor($this->sold(400000));
        $this->pay($draft, 100)->assertSessionHasErrors('invoice');

        $this->post(route('corex.auctions.invoices.cancel', $draft->id), ['reason' => 'Wrong buyer']);
        $this->pay($draft, 100)->assertSessionHasErrors('invoice');
    }

    public function test_a_payment_recorded_by_mistake_is_voided_with_a_reason_and_the_status_follows(): void
    {
        $inv = $this->issued();
        $this->pay($inv, 100000);
        $payment = $inv->payments()->first();

        $this->delete(route('corex.auctions.invoices.payments.void', [$inv->id, $payment->id]), [])->assertSessionHasErrors('reason');
        $this->delete(route('corex.auctions.invoices.payments.void', [$inv->id, $payment->id]), ['reason' => 'Wrong invoice'])->assertSessionHasNoErrors();

        $this->assertSoftDeleted('auction_invoice_payments', ['id' => $payment->id]);
        $this->assertSame('issued', $inv->fresh()->status);
        $this->assertSame(0.0, $inv->fresh()->paidTotal());
        $this->get(route('corex.auctions.invoices.show', $inv->id))->assertOk()->assertSee('voided: Wrong invoice');
    }

    public function test_the_bank_guarantee_is_ticked_and_unticked(): void
    {
        $inv = $this->issued();

        $this->post(route('corex.auctions.invoices.guarantee', $inv->id), ['received' => '1', 'note' => 'Cape Bank ref 77']);
        $this->assertNotNull($inv->fresh()->guarantee_received_at);
        $this->assertSame('Cape Bank ref 77', $inv->fresh()->guarantee_note);
        $this->get(route('corex.auctions.invoices.show', $inv->id))->assertSee('Cape Bank ref 77');

        $this->post(route('corex.auctions.invoices.guarantee', $inv->id), ['received' => '0']);
        $this->assertNull($inv->fresh()->guarantee_received_at);
    }

    public function test_cancelling_needs_a_reason_and_no_money_and_restoring_keeps_the_number(): void
    {
        $inv = $this->issued();
        $number = $inv->number;
        $this->post(route('corex.auctions.invoices.cancel', $inv->id), [])->assertSessionHasErrors('reason');

        $this->pay($inv, 50000);
        $this->post(route('corex.auctions.invoices.cancel', $inv->id), ['reason' => 'Mistake'])->assertSessionHasErrors('invoice');
        $this->delete(route('corex.auctions.invoices.payments.void', [$inv->id, $inv->payments()->first()->id]), ['reason' => 'Test']);

        $this->post(route('corex.auctions.invoices.cancel', $inv->id), ['reason' => 'Mistake'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $inv->fresh()->status);

        $this->post(route('corex.auctions.invoices.restore', $inv->id));
        $this->assertSame('draft', $inv->fresh()->status);
        $this->assertSame($number, $inv->fresh()->number, 'a number is never reused');
    }

    public function test_a_cancelled_invoice_frees_the_lot_to_be_invoiced_again_but_not_twice(): void
    {
        $lot = $this->sold(400000);
        $inv = $this->invoiceFor($lot);
        $this->post(route('corex.auctions.invoices.cancel', $inv->id), ['reason' => 'Wrong figures']);

        $this->post(route('corex.auctions.invoices.for-lot', $lot->id))->assertSessionHasNoErrors();
        $this->assertSame(2, AuctionInvoice::withoutGlobalScopes()->where('lot_id', $lot->id)->count());

        $this->post(route('corex.auctions.invoices.restore', $inv->id))->assertSessionHasErrors('invoice');   // a newer live invoice exists
    }

    public function test_an_invoice_is_overdue_once_a_due_date_passes_unpaid(): void
    {
        $inv = $this->issued();
        $this->assertFalse($inv->isOverdue());

        $inv->forceFill(['deposit_due_at' => now()->subDay()])->save();
        $this->assertTrue($inv->fresh()->isOverdue());

        $this->pay($inv, 100000);
        $this->assertFalse($inv->fresh()->isOverdue(), 'deposit paid, balance not yet due');

        $inv->forceFill(['balance_due_at' => now()->subDay()])->save();
        $this->assertTrue($inv->fresh()->isOverdue());
    }

    // ── the screen ─────────────────────────────────────────────────────────

    public function test_the_invoices_list_searches_filters_sorts_and_has_an_empty_state(): void
    {
        $this->get(route('corex.auctions.invoices.index'))->assertOk()->assertSee('No invoices yet');

        $inv = $this->issued();

        $this->get(route('corex.auctions.invoices.index'))->assertOk()->assertSee($inv->number)->assertSee('R 1 086 250.00')->assertSee('Still owing');
        $this->get(route('corex.auctions.invoices.index', ['search' => $inv->number]))->assertSee($inv->number);
        $this->get(route('corex.auctions.invoices.index', ['search' => 'win@example.com']))->assertSee($inv->number);
        $this->get(route('corex.auctions.invoices.index', ['search' => 'zzzz']))->assertSee('No invoices match these filters');
        $this->get(route('corex.auctions.invoices.index', ['status' => 'paid']))->assertDontSee($inv->number);
        $this->get(route('corex.auctions.invoices.index', ['only' => 'outstanding']))->assertSee($inv->number);
        $this->get(route('corex.auctions.invoices.index', ['only' => 'overdue']))->assertDontSee($inv->number);
        $this->get(route('corex.auctions.invoices.index', ['sort' => 'total_amount', 'dir' => 'asc']))->assertOk();

        $inv->forceFill(['deposit_due_at' => now()->subDay()])->save();
        $this->get(route('corex.auctions.invoices.index', ['only' => 'overdue']))->assertSee($inv->number)->assertSee('overdue');
    }

    public function test_the_invoice_page_shows_the_working_and_the_pdf_opens(): void
    {
        $inv = $this->issued();

        $this->get(route('corex.auctions.invoices.show', $inv->id))->assertOk()
            ->assertSee("Buyer's commission @ 7.5%", false)->assertSee('R 75 000.00')->assertSee('R 11 250.00')->assertSee('R 1 086 250.00')->assertSee('data-record-payment', false);

        $pdf = $this->get(route('corex.auctions.invoices.pdf', $inv->id))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_another_agencys_invoice_is_unreachable(): void
    {
        $rival = Agency::create(['name' => 'Rival', 'slug' => 'rv-'.uniqid()]);
        $lot = $this->sold(400000);
        $foreign = AuctionInvoice::withoutAgencyStamping(fn () => AuctionInvoice::create([
            'agency_id' => $rival->id, 'auction_id' => $this->auc->id, 'lot_id' => $lot->id, 'registration_id' => $lot->bids()->first()->registration_id, 'hammer_price' => 1, 'total_amount' => 1, 'status' => 'draft',
        ]));

        $this->get(route('corex.auctions.invoices.show', $foreign->id))->assertNotFound();
        $this->post(route('corex.auctions.invoices.issue', $foreign->id))->assertNotFound();
        $this->get(route('corex.auctions.invoices.pdf', $foreign->id))->assertNotFound();
    }

    public function test_an_agent_can_view_but_not_issue_or_record_payments(): void
    {
        Mail::fake();
        $inv = $this->issued();
        \Illuminate\Support\Facades\Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        \App\Services\PermissionService::clearCache();
        $agent = \App\Models\User::factory()->create(['agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'role' => 'agent']);
        $this->lot->property->forceFill(['agent_id' => $agent->id])->save();
        $this->actingAs($agent);

        $this->get(route('corex.auctions.invoices.show', $inv->id))->assertOk()->assertDontSee('data-record-payment', false);
        $this->pay($inv, 100)->assertForbidden();
        $this->post(route('corex.auctions.invoices.cancel', $inv->id), ['reason' => 'x'])->assertForbidden();
    }

    // ── what the buyer sees ────────────────────────────────────────────────

    private function asBuyer(string $email): ClientUser
    {
        Auth::guard('web')->forgetUser();
        $this->flushSession();
        $u = $this->buyer($email);
        Auth::guard('client-web')->login($u);

        return $u;
    }

    public function test_my_account_lists_my_issued_invoices_and_serves_only_my_pdf(): void
    {
        $inv = $this->issued();
        $draftLot = app(\App\Services\Auctions\AuctionLotService::class)->saveForProperty($this->property('Other house'), ['auction_id' => $this->auc->id], $this->admin);
        $draft = AuctionInvoice::withoutAgencyStamping(fn () => AuctionInvoice::create(['agency_id' => $this->agency->id, 'auction_id' => $this->auc->id, 'lot_id' => $draftLot->id, 'registration_id' => $inv->registration_id, 'hammer_price' => 1, 'total_amount' => 1, 'status' => 'draft']));
        $slug = $this->agency->slug;

        $this->asBuyer('win@example.com');
        $this->get("/auctions/{$slug}/account")->assertOk()->assertSee('My invoices')->assertSee($inv->number)->assertSee('Owing R 1 086 250.00');
        $pdf = $this->get("/auctions/{$slug}/account/invoices/{$inv->id}/pdf")->assertOk();
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        $this->get("/auctions/{$slug}/account/invoices/{$draft->id}/pdf")->assertNotFound();

        $this->asBuyer('someone-else@example.com');
        $this->get("/auctions/{$slug}/account")->assertDontSee('My invoices');
        $this->get("/auctions/{$slug}/account/invoices/{$inv->id}/pdf")->assertNotFound();
    }

    public function test_fees_are_disclosed_on_the_auction_the_lot_and_the_registration_pages(): void
    {
        $this->settings(['buyers_commission_percent' => 7.5, 'deposit_percent' => 10, 'deposit_due_days' => 3]);
        $this->asBuyer('new@example.com');
        $base = '/auctions/' . $this->agency->slug;

        foreach (["{$base}/a/{$this->auc->id}", "{$base}/a/{$this->auc->id}/lots/{$this->lot->id}", "{$base}/a/{$this->auc->id}/register"] as $url) {
            $this->get($url)->assertOk()->assertSee('data-fee-notice', false)->assertSee(e("buyer's commission of 7.5%"), false);
        }

        $this->settings(['buyers_commission_percent' => 0, 'deposit_percent' => 0]);
        $this->get("{$base}/a/{$this->auc->id}")->assertDontSee('data-fee-notice', false);
    }

    // ── settings, wizard, auction form, results ───────────────────────────

    public function test_the_fee_settings_save_from_the_settings_screen_and_the_wizard_without_wiping_each_other(): void
    {
        $this->post(route('corex.settings.auctions.update'), [
            'site_title' => 'Cape Auctions', 'buyers_commission_percent' => 6, 'buyers_commission_minimum' => 10000, 'deposit_percent' => 15,
            'deposit_due_days' => 2, 'balance_due_days' => 14, 'payment_details' => 'Cape Bank 555', 'show_past_auctions' => '1',
        ])->assertSessionHasNoErrors();

        $id = $this->agency->id;
        $this->assertSame(6.0, AgencyAuctionSettings::buyersCommissionPercentFor($id));
        $this->assertSame(10000.0, AgencyAuctionSettings::buyersCommissionMinimumFor($id));
        $this->assertSame(15.0, AgencyAuctionSettings::depositPercentFor($id));
        $this->assertSame(2, AgencyAuctionSettings::depositDueDaysFor($id));
        $this->assertSame(14, AgencyAuctionSettings::balanceDueDaysFor($id));
        $this->assertSame('Cape Bank 555', AgencyAuctionSettings::paymentDetailsFor($id));

        $request = \Illuminate\Http\Request::create('/x', 'POST', ['deposit_percent' => 20]);
        $request->setUserResolver(fn () => $this->admin);
        app(\App\Http\Controllers\CoreX\Auctions\AuctionSettingsController::class)->updateWizard($request);
        $this->assertSame(20.0, AgencyAuctionSettings::depositPercentFor($id));
        $this->assertSame(6.0, AgencyAuctionSettings::buyersCommissionPercentFor($id), 'a field the wizard did not post is untouched');
        $this->assertSame('Cape Bank 555', AgencyAuctionSettings::paymentDetailsFor($id));
    }

    public function test_bad_fee_settings_are_refused(): void
    {
        $this->post(route('corex.settings.auctions.update'), ['buyers_commission_percent' => 80])->assertSessionHasErrors('buyers_commission_percent');
        $this->post(route('corex.settings.auctions.update'), ['deposit_percent' => 120])->assertSessionHasErrors('deposit_percent');
        $this->post(route('corex.settings.auctions.update'), ['deposit_due_days' => -1])->assertSessionHasErrors('deposit_due_days');
    }

    public function test_the_auction_form_saves_per_auction_fee_overrides(): void
    {
        $this->put(route('corex.auctions.update', $this->auc->id), [
            'title' => $this->auc->title, 'format' => 'timed', 'type' => 'real_estate', 'currency' => 'ZAR',
            'buyers_commission_percent' => 9, 'deposit_percent' => 20,
        ])->assertSessionHasNoErrors();

        $this->assertSame(9.0, (float) $this->auc->fresh()->buyers_commission_percent);
        $this->assertSame(20.0, (float) $this->auc->fresh()->deposit_percent);
        $this->get(route('corex.auctions.edit', $this->auc->id))->assertOk()->assertSee('data-auction-fees', false);
    }

    public function test_results_show_the_commission_earned_on_issued_invoices_only(): void
    {
        $this->settings(['buyers_commission_percent' => 7.5]);
        $lot = $this->sold(1000000);
        $inv = $this->invoiceFor($lot);

        $this->assertStringContainsString('R 0', $this->get(route('corex.auctions.results'))->assertOk()->getContent());   // a draft earns nothing yet

        Mail::fake();
        app(AuctionInvoiceService::class)->issue($inv, $this->admin);
        $this->get(route('corex.auctions.results'))->assertOk()->assertSee('Commission earned')->assertSee('R 75 000');
    }
}
