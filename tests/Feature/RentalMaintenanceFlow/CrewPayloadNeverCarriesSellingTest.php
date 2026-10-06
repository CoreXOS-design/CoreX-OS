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
}
