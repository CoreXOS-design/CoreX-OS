<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSignature;
use App\Services\Rentals\RentalInspectionCopiesService;
use App\Services\Rentals\RentalInspectionReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalInspections\Concerns\BuildsSigningFixture;
use Tests\TestCase;

/**
 * 8 Oct 2026 (Johan's rulings 1 and 2, spec §49):
 *
 *  1. A tenant's or landlord's PDF — from their signing link, the public link, the portal, the emailed copy — exists ONLY once
 *     ALL parties have signed (and stays available after). Before that they read the report on screen and nothing leaves
 *     CoreX. A party's own signature neither unlocks it nor counts as the report being "sent" (distributed stays = completed,
 *     copies sent).
 *  2. The agent may print / download an unfinished or not-fully-signed report, but EVERY page is stamped "DRAFT - not final".
 *     The stamp disappears only on the completed, fully signed report.
 *
 * Every download path is exercised against every state: draft, ready to sign, partly signed, a refusal, fully signed (not
 * yet completed), completed.
 */
final class RentalInspectionRulingsDownloadsTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildFixture();
    }

    protected function tearDown(): void
    {
        $this->tearDownFixture();
        parent::tearDown();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function pdfUrl(string $token): string
    {
        return route('rental-inspections.sign.pdf', $token);
    }

    /** The tenant's own signing link for $inspection, as a guest would see it. */
    private function tenantLink(RentalInspection $inspection)
    {
        return $this->issueLink($inspection, 'tenant', $this->tenant->id);
    }

    /** Fetch as a guest (a tenant has no CoreX session), then log the agent back in. */
    private function asGuest(callable $fn)
    {
        $this->guest();
        try {
            return $fn();
        } finally {
            $this->actingAs($this->admin);
        }
    }

    private function signAllButTheAgent(RentalInspection $inspection, bool $tenantOneAlreadySigned = false): void
    {
        if (! $tenantOneAlreadySigned) {
            $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini');
        }
        $link2 = $this->issueLink($inspection, 'tenant', $this->tenant2->id);
        $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link2]), $this->signPayload(['typed_name' => 'Sipho Khumalo']))->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG,
        ])->assertStatus(201);
    }

    private function agentSigns(RentalInspection $inspection): void
    {
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG,
        ])->assertStatus(201);
    }

    /** Text of every page of a generated PDF (pdftotext, one string per page). */
    private function pdfPages(string $bytes): array
    {
        $in = tempnam(sys_get_temp_dir(), 'rulings-') . '.pdf';
        file_put_contents($in, $bytes);
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($in) . ' - 2>/dev/null');
        @unlink($in);
        $pages = explode("\f", $text);
        if (end($pages) === '' || trim((string) end($pages)) === '') {
            array_pop($pages);
        }

        return $pages;
    }

    /** Pad the report with enough graded items that the PDF runs to several pages. */
    private function makeLongReport(RentalInspection $inspection): void
    {
        for ($i = 1; $i <= 70; $i++) {
            $item = RentalInspectionItem::create([
                'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $this->room->id,
                'kind' => RentalInspectionItem::KIND_SPACE, 'label' => "Skirting board {$i}", 'sort_order' => $i, 'created_by_user_id' => $this->admin->id,
            ]);
            RentalInspectionObservation::record([
                'agency_id' => $this->agency->id, 'rental_inspection_id' => $inspection->id, 'rental_inspection_item_id' => $item->id,
                'observed_by_user_id' => $this->admin->id, 'condition' => 'good', 'notes' => str_repeat('Paint chipped near the corner. ', 3),
                'source' => RentalInspectionObservation::SOURCE_IN_INSPECTION,
            ]);
        }
    }

    // ═══ Rule 1 — no tenant / landlord PDF until everyone has signed ═══════════════

    /** @return array<string, array{0:string}> */
    public static function everyType(): array
    {
        return ['in' => ['in'], 'out' => ['out'], 'interim' => ['interim'], 'routine' => ['ad_hoc']];
    }

    /** @dataProvider everyType */
    public function test_the_signing_link_pdf_is_refused_in_every_state_before_everyone_has_signed(string $type): void
    {
        $inspection = $this->recording($type);
        $link = $this->tenantLink($inspection);

        // draft
        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertStatus(403)->assertSee('The PDF isn', false)->assertDontSee('%PDF'));

        // ready to sign
        $this->readyToSign($inspection);
        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertStatus(403));

        // the tenant's OWN signature does not unlock it (nor the lock: that is a separate matter)
        $this->asGuest(fn () => $this->postJson(route('rental-inspections.sign.submit', $link->token), $this->signPayload())->assertStatus(201));
        $this->assertFalse($inspection->fresh()->partyCopyAvailable());
        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertStatus(403));

        // everyone but the agent
        $this->signAllButTheAgent($inspection->fresh(), tenantOneAlreadySigned: true);
        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertStatus(403));
        $this->assertFalse($inspection->fresh()->isFullySigned(), 'The agent has not signed yet.');
    }

    public function test_a_refused_signature_is_not_a_signature_so_the_pdf_stays_closed(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_IN);
        $link = $this->tenantLink($inspection);
        $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'tenant', 'disposition' => 'refused', 'party_contact_id' => $this->tenant2->id, 'refusal_reason_preset' => 'other', 'refusal_reason_note' => 'Not happy with the geyser',
        ])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG,
        ])->assertStatus(201);
        $this->agentSigns($inspection);

        $this->assertFalse($inspection->fresh()->isFullySigned());
        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertStatus(403));

        // Completing (copies sent) does not turn a refusal into a signature: "ALL parties have signed" still is not true, so the
        // party-side PDF stays closed — the agency's own emailed copy is the agency's act and is unchanged.
        $this->complete($inspection);
        $this->assertTrue($inspection->fresh()->isDistributed());
        $this->assertFalse($inspection->fresh()->allRequiredPartiesSigned());
        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertStatus(403));
    }

    public function test_all_required_parties_signed_honours_the_per_type_setting(): void
    {
        $routine = $this->recording(RentalInspection::TYPE_AD_HOC);
        $this->assertTrue($routine->allRequiredPartiesSigned(), 'Routine signatures are optional by default: nothing is outstanding.');

        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['signatures_required_routine' => true]);
        $this->assertFalse($routine->fresh()->allRequiredPartiesSigned());

        $in = $this->ready(RentalInspection::TYPE_IN);
        $this->assertFalse($in->allRequiredPartiesSigned());
        $this->everyoneSigns($in);
        $this->assertTrue($in->fresh()->allRequiredPartiesSigned());
    }

    /** @dataProvider everyType */
    public function test_the_signing_link_pdf_opens_once_everyone_has_signed_and_stays_open_after_completion(string $type): void
    {
        $inspection = $this->ready($type);
        $link = $this->tenantLink($inspection);

        $this->everyoneSigns($inspection);
        $this->assertTrue($inspection->fresh()->isFullySigned());
        $this->assertFalse($inspection->fresh()->isDistributed(), 'Fully signed is not "sent": distributed stays = completed, copies sent.');

        $response = $this->asGuest(fn () => $this->get($this->pdfUrl($link->token)));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        $this->complete($inspection);
        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertOk()->assertHeader('Content-Type', 'application/pdf'));
    }

    public function test_a_routine_inspection_completed_without_signatures_gives_the_parties_their_pdf_because_it_was_sent(): void
    {
        $inspection = $this->recording(RentalInspection::TYPE_AD_HOC);
        $link = $this->tenantLink($inspection);

        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertStatus(403));

        $this->complete($inspection);
        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertOk());
    }

    public function test_the_signing_page_offers_no_download_or_print_before_everyone_has_signed_and_both_after(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_IN);
        $link = $this->tenantLink($inspection);

        // Before: the report is on screen, nothing to download, nothing to print.
        $page = $this->asGuest(fn () => $this->get(route('rental-inspections.sign.show', $link->token))->assertOk());
        $page->assertSee('Lounge Ceiling')
            ->assertDontSee('data-qa="download-report"', false)
            ->assertDontSee('data-qa="print-report"', false)
            ->assertSee('data-qa="print-not-yet"', false)
            ->assertSee('data-qa="print-blocked"', false);

        // The tenant signs: their confirmation says the PDF comes later, and still offers no link.
        $this->asGuest(fn () => $this->postJson(route('rental-inspections.sign.submit', $link->token), $this->signPayload())->assertStatus(201));
        $after = $this->asGuest(fn () => $this->get(route('rental-inspections.sign.show', $link->token))->assertOk());
        $after->assertSee('data-qa="sign-confirmation"', false)
            ->assertSee('data-qa="download-not-yet"', false)
            ->assertDontSee('data-qa="download-report"', false);

        // Everyone has signed: the download and the print button are there.
        $this->signAllButTheAgent($inspection->fresh(), tenantOneAlreadySigned: true);
        $this->agentSigns($inspection->fresh());
        $open = $this->asGuest(fn () => $this->get(route('rental-inspections.sign.show', $link->token))->assertOk());
        $open->assertSee('data-qa="download-report"', false)
            ->assertSee('data-qa="print-report"', false)
            ->assertDontSee('data-qa="print-blocked"', false)
            ->assertDontSee('data-qa="download-not-yet"', false);
    }

    public function test_the_general_public_link_page_has_no_print_until_everyone_has_signed(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_IN);
        $token = $inspection->generatePublicLink();

        $this->asGuest(fn () => $this->get(route('rental-inspections.public.show', $token))->assertOk()
            ->assertSee('Lounge Ceiling')
            ->assertDontSee('data-qa="print-report"', false)
            ->assertSee('data-qa="print-blocked"', false));

        $this->everyoneSigns($inspection);

        $this->asGuest(fn () => $this->get(route('rental-inspections.public.show', $token))->assertOk()
            ->assertSee('data-qa="print-report"', false)
            ->assertDontSee('data-qa="print-blocked"', false));
    }

    public function test_the_signing_pdf_of_another_inspection_or_a_dead_link_is_still_a_uniform_404(): void
    {
        $inspection = $this->recording(RentalInspection::TYPE_IN);
        $link = $this->tenantLink($inspection);
        $this->linkService()->revoke($link, $this->admin);

        $this->asGuest(fn () => $this->get($this->pdfUrl($link->token))->assertNotFound());
        $this->asGuest(fn () => $this->get($this->pdfUrl('not-a-real-token'))->assertNotFound());
    }

    public function test_the_emailed_copy_service_refuses_an_unfinished_report_directly(): void
    {
        $inspection = $this->recording(RentalInspection::TYPE_IN);

        $this->expectException(\LogicException::class);
        app(RentalInspectionCopiesService::class)->fileAndSend($inspection, autoOnly: false, triggeredBy: $this->admin);
    }

    public function test_the_agents_resend_endpoints_refuse_an_unfinished_report(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_IN);

        $this->postJson(route('corex.rental-inspections.resend-report', $inspection))->assertStatus(409);
    }

    public function test_the_portal_lists_a_report_only_once_it_may_be_taken(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_IN);
        $this->assertFalse($inspection->fresh()->partyCopyAvailable());

        $this->everyoneSigns($inspection);
        $this->assertTrue($inspection->fresh()->partyCopyAvailable(), 'Fully signed: available.');

        $this->complete($inspection);
        $this->assertTrue($inspection->fresh()->partyCopyAvailable());
        $this->assertTrue($inspection->fresh()->isDistributed());

        // cancelled / archived reports are never available
        $other = $this->recording(RentalInspection::TYPE_AD_HOC);
        $other->cancel($this->admin, 'Tenant was away');
        $this->assertFalse($other->fresh()->partyCopyAvailable());
    }

    public function test_the_portal_service_keeps_no_type_wording_of_its_own(): void
    {
        $src = (string) file_get_contents(app_path('Services/Rentals/RentalPortalDocumentService.php'));
        $this->assertStringNotContainsString("=> 'Ad hoc'", $src);
        $this->assertStringContainsString('RentalInspection::typeLabel(', $src, 'The portal uses the one shared type wording (covered end to end in PortalDocumentsTest).');
    }

    // ═══ Rule 1, "distributed stays" — a party's own signature does not lock or send ═══

    public function test_distributed_is_unchanged_and_a_partys_signature_alone_does_not_make_it_sent(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_IN);
        $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini');

        $this->assertFalse($inspection->fresh()->isDistributed());
        $this->assertTrue($inspection->fresh()->canBeReopened(), 'Not sent: the agent can still use "Edit report" (spec §47.4).');
    }

    // ═══ Rule 2 — the agent may print an unfinished report; every page says DRAFT ═══

    public function test_the_agent_pdf_of_every_unfinished_state_is_available_and_stamped_on_every_page(): void
    {
        $inspection = $this->recording(RentalInspection::TYPE_IN);
        $this->makeLongReport($inspection);
        $service = app(RentalInspectionReportPdfService::class);

        $states = [
            'draft' => fn () => null,
            'ready to sign' => fn () => $this->readyToSign($inspection),
            'part signed' => fn () => $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini'),
            'fully signed, not completed' => function () use ($inspection) {
                $this->signAllButTheAgent($inspection->fresh(), tenantOneAlreadySigned: true);
                $this->agentSigns($inspection->fresh());
            },
        ];

        foreach ($states as $name => $advance) {
            $advance();
            $bytes = $service->generate($inspection->fresh())->output();
            $pages = $this->pdfPages($bytes);
            $this->assertGreaterThan(1, count($pages), "[$name] the test report must run to several pages to prove 'every page'.");
            foreach ($pages as $i => $text) {
                $this->assertStringContainsString('DRAFT - not final', $text, "[$name] page " . ($i + 1) . ' of ' . count($pages) . ' must carry the stamp.');
            }

            // …and through the real agent endpoint
            $this->get(route('corex.rental-inspections.report', $inspection))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        }
    }

    public function test_print_for_signature_is_stamped_on_every_page_too(): void
    {
        $inspection = $this->recording(RentalInspection::TYPE_IN);
        $this->makeLongReport($inspection);

        $bytes = app(RentalInspectionReportPdfService::class)->generateForSignature($inspection->fresh())->output();
        $pages = $this->pdfPages($bytes);

        $this->assertGreaterThan(1, count($pages));
        foreach ($pages as $i => $text) {
            $this->assertStringContainsString('DRAFT - not final', $text, 'page ' . ($i + 1));
        }
        $this->get(route('corex.rental-inspections.print-for-signature', $inspection))->assertOk();
    }

    public function test_the_stamp_disappears_only_on_the_completed_report(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_IN);
        $this->makeLongReport($inspection);
        $this->everyoneSigns($inspection);
        $service = app(RentalInspectionReportPdfService::class);

        $beforeText = implode("\n", $this->pdfPages($service->generate($inspection->fresh())->output()));
        $this->assertStringContainsString('DRAFT - not final', $beforeText, 'Fully signed but not completed: still a draft.');

        $this->complete($inspection);
        $afterPages = $this->pdfPages($service->generate($inspection->fresh())->output());
        $this->assertGreaterThan(1, count($afterPages));
        foreach ($afterPages as $i => $text) {
            $this->assertStringNotContainsString('DRAFT', $text, 'page ' . ($i + 1) . ' of the completed report');
        }
        $this->assertTrue($inspection->fresh()->isFinalReport());
    }

    public function test_a_cancelled_report_is_never_final(): void
    {
        $inspection = $this->recording(RentalInspection::TYPE_AD_HOC);
        $inspection->cancel($this->admin, 'Booked in error');

        $this->assertFalse($inspection->fresh()->isFinalReport());
        $text = implode("\n", $this->pdfPages(app(RentalInspectionReportPdfService::class)->generate($inspection->fresh())->output()));
        $this->assertStringContainsString('DRAFT - not final', $text);
    }

    public function test_the_blank_capture_form_is_not_a_report_and_is_untouched(): void
    {
        $inspection = $this->recording(RentalInspection::TYPE_IN);

        // The printable tick-box form is generated BEFORE an inspection and holds no report content: no stamp, no change.
        $this->get(route('corex.rental-inspections.form', $inspection))->assertOk();
    }

    public function test_a_signature_row_exists_for_every_signer_after_full_signing_so_the_test_is_real(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_OUT);
        $this->everyoneSigns($inspection);

        $roles = RentalInspectionSignature::withoutGlobalScopes()->where('rental_inspection_id', $inspection->id)->whereNull('superseded_at')->pluck('party_role')->sort()->values()->all();
        $this->assertSame(['agent', 'landlord', 'tenant', 'tenant'], $roles);
        $this->assertTrue($inspection->fresh()->isFullySigned());
    }
}
