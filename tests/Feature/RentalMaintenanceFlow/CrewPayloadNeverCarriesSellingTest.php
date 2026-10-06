<?php

declare(strict_types=1);

namespace Tests\Feature\RentalMaintenanceFlow;

use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalPortalSetting;
use App\Models\RentalWorkOrderSetting;
use App\Services\Rentals\CrewJobService;
use App\Services\Rentals\CrewViewContext;
use App\Services\Rentals\RentalCrewScheduleService;
use App\Services\Rentals\RentalSecureAccessTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\RentalCrewLinks\Concerns\BuildsCrewLinkFixtures;
use Tests\TestCase;

/**
 * .ai/specs/rental-work-orders.md §17.4.7 / §17.21.1 — a PERMANENT guard, kept for the life of the feature:
 *
 *     "Crew works on actual costs, not selling."
 *
 * Whatever the settings, a crew (per-job link, crew page, the shared payload, the crew page's "what to load" list,
 * and the worker's printed job card) must NEVER be handed a selling price, a markup, a margin or an owner amount.
 * Every selling figure below is deliberately distinctive (R4,321.99 x 2, R7,777.77 x 3) so a leak cannot be
 * mistaken for a cost or for chance.
 *
 * Builds 1-3 add to the crew view (price requests, approval chips, dispute banner): this test must stay green
 * through all of them — extend the payloads it inspects, never relax what it asserts.
 */
final class CrewPayloadNeverCarriesSellingTest extends TestCase
{
    use BuildsCrewLinkFixtures;
    use RefreshDatabase;

    /** Every way the distinctive selling numbers could be printed (raw, thousands separator, with/without cents). */
    private const SELLING_FIGURES = [
        '4321.99', '4,321.99', '8643.98', '8,643.98',          // part: unit price, line total (2 x 4,321.99)
        '7777.77', '7,777.77', '23333.31', '23,333.31',        // labour: unit price, line total (3 x 7,777.77)
        '31977.29', '31,977.29',                               // the whole job at selling
    ];

    /** Keys that would reveal selling, markup, margin or an owner amount. */
    private const FORBIDDEN_KEYS = ['unit_price', 'line_total', 'markup', 'margin', 'selling', 'approved_amount', 'owner_facing', 'quote', 'landlord', 'total_amount'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildCrewLinkWorld('Selling Guard');
    }

    private function sellingCard(): RentalJobCard
    {
        $card = $this->makeJobCard(['scheduled_at' => now()->addHours(3)]);
        RentalJobCardLine::where('rental_job_card_id', $card->id)->where('type', 'part')
            ->update(['unit_price' => 4321.99, 'line_total' => 8643.98, 'unit_cost' => 111.11, 'cost_total' => 222.22, 'markup_type' => 'percent', 'markup_value' => 3789.00, 'selling_basis' => 'line_markup']);
        RentalJobCardLine::where('rental_job_card_id', $card->id)->where('type', 'labour')
            ->update(['unit_price' => 7777.77, 'line_total' => 23333.31, 'unit_cost' => 55.55, 'cost_total' => 166.65]);
        $card->forceFill(['total_amount' => 31977.29, 'total_cost' => 388.87, 'markup_all_percent' => 12.5])->save();

        return $card->fresh();
    }

    /** @return array<string, array{0: bool, 1: bool}> */
    public static function settingCombinations(): array
    {
        return [
            'costs off, tenant off' => [false, false],
            'costs ON, tenant off' => [true, false],
            'costs off, tenant ON' => [false, true],
            'costs ON, tenant ON' => [true, true],
        ];
    }

    private function applySettings(bool $showCosts, bool $showTenant): void
    {
        RentalPortalSetting::updateOrCreate(['agency_id' => $this->agency->id], ['crew_link_show_costs' => $showCosts, 'crew_link_show_tenant_contact' => $showTenant]);
        RentalWorkOrderSetting::updateOrCreate(['agency_id' => $this->agency->id], ['capture_prices_on_job_cards' => true, 'show_costs_on_printed_job_card' => $showCosts]);
    }

    private function assertNoSelling(string $body, string $where): void
    {
        foreach (self::SELLING_FIGURES as $figure) {
            $this->assertStringNotContainsString($figure, $body, "SELLING figure {$figure} leaked to the crew in: {$where}");
        }
    }

    private function ctx(RentalJobCard $card, bool $showCosts, bool $showTenant): CrewViewContext
    {
        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);

        return new CrewViewContext(
            agencyId: $this->agency->id, crewId: $this->crew->id, tokenId: $issued['token']->id,
            via: CrewViewContext::VIA_JOB_LINK, showCosts: $showCosts, showTenantContact: $showTenant,
            ip: '203.0.113.9', userAgent: 'TestPhone/1.0', actorLabel: 'via crew link — Team 1',
        );
    }

    #[DataProvider('settingCombinations')]
    public function test_the_shared_payload_never_carries_selling_markup_margin_or_an_owner_amount(bool $showCosts, bool $showTenant): void
    {
        $this->applySettings($showCosts, $showTenant);
        $card = $this->sellingCard();

        $payload = app(CrewJobService::class)->payload($card, $this->ctx($card, $showCosts, $showTenant));
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertNoSelling($json, 'CrewJobService::payload()');
        foreach (self::FORBIDDEN_KEYS as $key) {
            $this->assertStringNotContainsString('"' . $key, $json, "forbidden key {$key} present in the crew payload");
        }
        // every plug-in block, current and future, is covered by the same rule
        foreach ($payload['blocks'] as $name => $block) {
            $this->assertNoSelling(json_encode($block), "payload block {$name}");
        }
    }

    #[DataProvider('settingCombinations')]
    public function test_the_per_job_link_page_never_shows_selling(bool $showCosts, bool $showTenant): void
    {
        $this->applySettings($showCosts, $showTenant);
        $card = $this->sellingCard();
        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);

        $html = $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk()->getContent();

        $this->assertNoSelling($html, 'GET /secure/job-cards/{token}');
        if ($showCosts) {
            $this->assertStringContainsString('222.22', $html, 'the crew sees the cost the office entered');
            $this->assertStringContainsString('388.87', $html);
        } else {
            $this->assertStringNotContainsString('222.22', $html);
            $this->assertStringNotContainsString('388.87', $html);
        }
    }

    #[DataProvider('settingCombinations')]
    public function test_the_crew_page_and_its_job_view_never_show_selling(bool $showCosts, bool $showTenant): void
    {
        $this->applySettings($showCosts, $showTenant);
        $card = $this->sellingCard();
        $raw = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];

        $page = $this->get("/secure/crews/{$raw}")->assertOk()->getContent();
        $job = $this->get("/secure/crews/{$raw}/job-cards/{$card->id}")->assertOk()->getContent();

        $this->assertNoSelling($page, 'GET /secure/crews/{token}');
        $this->assertNoSelling($job, 'GET /secure/crews/{token}/job-cards/{card}');
        if ($showCosts) {
            $this->assertStringContainsString('222.22', $page, "the crew page's \"what to load\" shows the part's COST");
        } else {
            $this->assertStringNotContainsString('222.22', $page);
        }
    }

    #[DataProvider('settingCombinations')]
    public function test_what_to_load_never_carries_selling(bool $showCosts, bool $showTenant): void
    {
        $this->applySettings($showCosts, $showTenant);
        $this->sellingCard();

        $schedule = app(RentalCrewScheduleService::class)->schedule($this->agency->id, $this->crew->id);

        $this->assertNoSelling(json_encode($schedule['materials']), 'RentalCrewScheduleService::schedule()[materials]');
        $this->assertNotEmpty($schedule['materials']);
        $this->assertSame($showCosts ? '222.22' : null, $schedule['materials'][0]['total_value']);
    }

    #[DataProvider('settingCombinations')]
    public function test_the_workers_printed_job_card_never_shows_selling(bool $showCosts, bool $showTenant): void
    {
        $this->applySettings($showCosts, $showTenant);
        $card = $this->sellingCard();

        $html = view('corex.rental-job-cards.print', [
            'jobCard' => $card, 'costsOn' => $showCosts, 'vatNumber' => null, 'logo' => null, 'agencyName' => 'Selling Guard Agency',
        ])->render();

        $this->assertNoSelling($html, 'the worker-facing printed job card');
        $this->assertStringNotContainsString('Unit price', $html);
        if ($showCosts) {
            $this->assertStringContainsString('222.22', $html);
            $this->assertStringContainsString('388.87', $html);
        } else {
            $this->assertStringNotContainsString('222.22', $html);
        }
    }

    // ── Build 3: the dispute block (§17.10.6) is part of the crew view, so it is part of the guard ─────────────────

    /** A disputed job carrying the tenant's note and photos — and, deliberately, the selling figures on its lines. */
    private function disputedCard(): RentalJobCard
    {
        $card = $this->sellingCard();
        $workOrder = $card->workOrder;
        $workOrder->forceFill(['cost_amount' => 31977.29, 'approved_amount' => 31977.29])->save();
        $round = \App\Models\RentalWorkCompletionRound::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $workOrder->id, 'rental_job_card_id' => $card->id, 'round_no' => 1,
            'opened_at' => now()->subDay(), 'reported_by_label' => 'Team 1', 'reported_via' => 'crew_link', 'outcome' => 'disputed',
            'responded_at' => now(), 'responded_via' => 'portal', 'response_note' => 'The tap still drips after the repair',
        ]);
        \App\Models\RentalWorkOrderPhoto::withoutGlobalScopes()->create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $workOrder->id, 'rental_job_card_id' => $card->id, 'rental_completion_round_id' => $round->id,
            'photo_type' => 'dispute', 'uploaded_via' => 'tenant', 'storage_path' => '/storage/properties/1/drip.jpg', 'file_size_bytes' => 1000,
        ]);
        $workOrder->forceFill(['status' => 'disputed'])->save();
        $card->forceFill(['status' => RentalJobCard::STATUS_DISPUTED])->save();

        return $card->fresh();
    }

    #[DataProvider('settingCombinations')]
    public function test_a_disputed_job_shows_the_crew_the_complaint_and_never_selling(bool $showCosts, bool $showTenant): void
    {
        $this->applySettings($showCosts, $showTenant);
        $card = $this->disputedCard();

        $payload = app(CrewJobService::class)->payload($card, $this->ctx($card, $showCosts, $showTenant));
        $json = json_encode($payload, JSON_THROW_ON_ERROR);

        $this->assertSame('The tap still drips after the repair', $payload['blocks']['dispute']['note']);
        $this->assertSame([['url' => '/storage/properties/1/drip.jpg']], $payload['blocks']['dispute']['photos']);
        $this->assertSame(0, collect($payload['photos'])->where('type', 'dispute')->count(), 'dispute photos are NOT in the general gallery');
        $this->assertNoSelling($json, 'CrewJobService::payload() of a disputed job');
        foreach (self::FORBIDDEN_KEYS as $key) {
            $this->assertStringNotContainsString('"' . $key, $json, "forbidden key {$key} in a disputed job's payload");
        }
        $this->assertStringNotContainsString('Thandi', $json, 'the crew is told what is wrong, not who said it');

        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);
        $html = $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk()->getContent();
        $this->assertNoSelling($html, 'GET /secure/job-cards/{token} of a disputed job');
        $this->assertStringContainsString('The tenant says this is not complete', $html);

        $page = app(RentalSecureAccessTokenService::class)->issueForCrew($this->crew, $this->admin)['raw_token'];
        $this->assertNoSelling($this->get("/secure/crews/{$page}/job-cards/{$card->id}")->assertOk()->getContent(), 'GET /secure/crews/{token}/job-cards/{card} of a disputed job');
    }

    public function test_the_real_pdf_service_passes_the_cost_setting_not_the_old_price_setting(): void
    {
        $this->applySettings(true, false);
        $card = $this->sellingCard();

        // The real service path (not a hand-built view) renders without error and is a PDF.
        $pdf = app(\App\Services\Rentals\RentalDocumentPdfService::class)->jobCardPrintPdf($card)->output();

        $this->assertStringStartsWith('%PDF', $pdf);
    }

    /**
     * Build 2 (§17.7 / §17.8.4) adds the approval chips to the crew view: "Approved to proceed (emergency)" and, per extra, Approved /
     * Awaiting owner — do not start / Declined by owner. Extended here, never relaxed: whatever the owner approved, the extra and the
     * new total are, the crew gets the STATE and nothing else.
     */
    #[DataProvider('settingCombinations')]
    public function test_the_approval_chips_never_carry_selling_an_approved_amount_or_an_owner(bool $showCosts, bool $showTenant): void
    {
        $this->applySettings($showCosts, $showTenant);
        $card = $this->sellingCard();
        $wo = \App\Models\RentalWorkOrder::create([
            'agency_id' => $this->agency->id, 'branch_id' => $this->branch->id, 'property_id' => $this->property->id, 'assignment_type' => 'internal',
            'title' => 'Selling guard job', 'description' => 'x', 'status' => 'in_progress', 'owner_approval_status' => 'approved',
            'approved_amount' => 31977.29, 'approval_basis' => 'owner_decision', 'reported_by_type' => 'agent_noticed', 'reported_at' => now(), 'created_by_user_id' => $this->admin->id,
        ]);
        $card->forceFill(['rental_work_order_id' => $wo->id])->save();
        $variation = \App\Models\RentalWorkOrderVariation::create([
            'agency_id' => $this->agency->id, 'rental_work_order_id' => $wo->id, 'rental_job_card_id' => $card->id, 'status' => 'awaiting_owner', 'origin' => 'office_edit',
            'baseline_amount' => 31977.29, 'extra_amount' => 8643.98, 'new_total' => 40621.27, 'price_change_amount' => 0, 'raised_at' => now(),
        ]);
        RentalJobCardLine::where('rental_job_card_id', $card->id)->where('type', 'part')->update(['rental_work_order_variation_id' => $variation->id]);

        $payload = app(CrewJobService::class)->payload($card->fresh(), $this->ctx($card, $showCosts, $showTenant));
        $approval = $payload['blocks']['approval'];

        $this->assertTrue($approval['hold']);
        $this->assertSame('Awaiting owner — do not start', $approval['extras'][0]['label']);
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertNoSelling($json, 'the approval block of CrewJobService::payload()');
        foreach (['40621.27', '40,621.27', '31977.29', '8643.98', 'approved_amount', 'baseline', 'extra_amount', 'new_total'] as $needle) {
            $this->assertStringNotContainsString($needle, json_encode($approval), "the approval block must not carry {$needle}");
        }

        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card->fresh(), $this->admin);
        $html = $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk()->assertSee('Awaiting owner — do not start')->getContent();
        $this->assertNoSelling($html, 'GET /secure/job-cards/{token} with a variation awaiting the owner');
        $this->assertStringNotContainsString('40,621.27', $html);
    }

    /**
     * Build 1 (§17.5) added the crew's own "Parts & labour" lines: draft, sent, accepted, rejected and declined-by-owner. Whatever
     * state a crew line is in — and even if its selling columns somehow hold a figure — the pricing block and the page built from
     * it never carry selling, markup, margin or an owner amount. Extends the guard; relaxes nothing.
     */
    #[DataProvider('settingCombinations')]
    public function test_the_crew_parts_and_labour_block_never_carries_selling_in_any_line_state(bool $showCosts, bool $showTenant): void
    {
        $this->applySettings($showCosts, $showTenant);
        $card = $this->sellingCard();
        foreach (['crew_draft', 'awaiting_office', 'accepted', 'rejected', 'declined_by_owner'] as $i => $state) {
            RentalJobCardLine::forceCreate([
                'agency_id' => $this->agency->id, 'rental_job_card_id' => $card->id, 'type' => 'part', 'description' => 'Crew line ' . $state,
                'quantity' => 1, 'unit' => 'each', 'sort_order' => 50 + $i, 'unit_cost' => 12.34, 'cost_total' => 12.34,
                // deliberately carrying the distinctive SELLING figures: a leak would be unmistakable
                'unit_price' => 4321.99, 'line_total' => 4321.99, 'markup_type' => 'percent', 'markup_value' => 3789.00, 'selling_basis' => 'line_markup',
                'origin' => 'crew_extra', 'office_status' => $state, 'reject_reason' => $state === 'rejected' ? 'Not needed' : null,
                'crew_added_by_label' => 'via crew link — Team 1', 'crew_added_at' => now(),
            ]);
        }
        $payload = app(CrewJobService::class)->payload($card->fresh(), $this->ctx($card, $showCosts, $showTenant));
        // a NEW link revokes the one ctx() issued, so the page's link is minted last
        $issued = app(RentalSecureAccessTokenService::class)->issueForJobCard($card, $this->admin);
        $block = $payload['blocks']['pricing'];
        $json = json_encode($block, JSON_THROW_ON_ERROR);

        $this->assertCount(5, $block['lines'], 'the crew sees all of their own lines, in every state');
        $this->assertNoSelling($json, 'CrewPricingBlock');
        foreach (self::FORBIDDEN_KEYS as $key) {
            $this->assertStringNotContainsString('"' . $key, $json, "forbidden key {$key} present in the crew pricing block");
        }
        $this->assertNoSelling(json_encode($payload), 'the whole crew payload with crew lines in it');

        $html = $this->get('/secure/job-cards/' . $issued['raw_token'])->assertOk()->getContent();
        $this->assertNoSelling($html, 'the per-job page with the Parts & labour panel');
        $this->assertStringContainsString('Parts &amp; labour', $html);
        $this->assertStringContainsString('12.34', $html, "the crew's own cost entry");
    }
}
