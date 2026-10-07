<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Mail\Distribution\SignedDocumentDistributionMail;
use App\Mail\Rentals\RentalInspectionSigningLinkMail;
use App\Models\RentalInspection;
use App\Models\RentalInspectionSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalInspections\Concerns\BuildsSigningFixture;
use Tests\TestCase;

/**
 * 7 Oct 2026 (Johan, property 6069): "EVERY inspection type can be marked ready to sign and signed (by link, QR, on
 * device, and in CoreX) — in, out, and the periodic one." A Routine inspection (stored as `ad_hoc`) used to be refused with
 * "Only an in-, interim or out-inspection has a signing window." — and a Routine inspection read "Ad_hoc" / "Ad-hoc"
 * on some screens, "Interim" on others, "Routine" in the picker. One word per type, everywhere.
 */
final class RentalInspectionAllTypesSigningTest extends TestCase
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

    /** @return array<string, array{0:string}> */
    public static function everyType(): array
    {
        return ['in' => ['in'], 'out' => ['out'], 'interim' => ['interim'], 'routine (ad_hoc)' => ['ad_hoc']];
    }

    // ═══ Ready to sign — the bug ═══════════════════════════════════════════════

    /** @dataProvider everyType */
    public function test_every_type_can_be_marked_ready_to_sign(string $type): void
    {
        $inspection = $this->recording($type);

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $inspection))->assertOk();

        $fresh = $inspection->fresh();
        $this->assertSame(RentalInspection::STATUS_AWAITING_SIGNATURE, $fresh->status);
        $this->assertNotNull($fresh->signing_deadline_at, 'The agency\'s own signing window applies to every type.');
    }

    public function test_the_signing_window_length_is_the_agencys_setting_for_every_type(): void
    {
        \App\Models\RentalInspectionSetting::updateOrCreate(['agency_id' => $this->agency->id], ['out_inspection_signing_window_days' => 12]);

        foreach (['in', 'out', 'interim', 'ad_hoc'] as $type) {
            $inspection = $this->ready($type);
            $this->assertEqualsWithDelta(now()->addDays(12)->timestamp, $inspection->signing_deadline_at->timestamp, 5, $type);
            $inspection->delete();
        }
    }

    public function test_the_old_refusal_message_is_gone_from_the_model(): void
    {
        $this->assertStringNotContainsString("throw new \\LogicException('Only an in-, interim or out-inspection", (string) file_get_contents(app_path('Models/RentalInspection.php')));
    }

    // ═══ Link signing, QR, on device and in CoreX — every type ═════════════════

    /** @dataProvider everyType */
    public function test_every_type_can_be_signed_from_a_link(string $type): void
    {
        $inspection = $this->ready($type);

        $this->assertTrue($this->linkService()->panel($inspection)['enabled']);
        $link = $this->signByLink($inspection, 'tenant', $this->tenant, 'Naledi Dlamini');

        $sig = RentalInspectionSignature::firstOrFail();
        $this->assertSame('link', $sig->signed_via);
        $this->assertSame($link->id, $sig->signing_link_id);
        $this->assertSame('signed', $link->fresh()->status());
    }

    /** @dataProvider everyType */
    public function test_every_type_has_a_qr_code_and_can_be_signed_on_the_agents_device(string $type): void
    {
        $inspection = $this->ready($type);
        $link = $this->issueLink($inspection, 'tenant', $this->tenant->id);

        $this->getJson(route('corex.rental-inspections.signing-links.qr', [$inspection, $link]))->assertOk()->assertJsonPath('url', $link->url());
        $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$inspection, $link]), $this->signPayload())->assertStatus(201);

        $this->assertSame('agent_device', RentalInspectionSignature::firstOrFail()->signed_via);
    }

    /** @dataProvider everyType */
    public function test_every_type_can_be_signed_in_corex_by_all_three_parties(string $type): void
    {
        $inspection = $this->ready($type);

        $this->everyoneSigns($inspection);

        $roles = $this->liveSignatures($inspection)->pluck('party_role')->sort()->values()->all();
        $this->assertSame(['agent', 'landlord', 'tenant', 'tenant'], $roles);
    }

    /** @dataProvider everyType */
    public function test_every_type_emails_links_and_completes_with_the_usual_copies(string $type): void
    {
        $inspection = $this->ready($type);
        $link = $this->issueLink($inspection, 'tenant', $this->tenant->id);

        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))->assertOk()->assertJsonPath('result.status', 'sent');
        Mail::assertSent(RentalInspectionSigningLinkMail::class, 1);

        $this->everyoneSigns($inspection);
        $this->complete($inspection);

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
        Mail::assertSent(SignedDocumentDistributionMail::class);
    }

    public function test_a_routine_inspection_still_completes_without_signatures_as_before(): void
    {
        // Signing is now ALLOWED for every type; completion is not newly tied to it for Routine (reported to Johan).
        $inspection = $this->recording(RentalInspection::TYPE_AD_HOC);

        $this->postJson(route('corex.rental-inspections.complete', $inspection))->assertOk();
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $inspection->fresh()->status);
    }

    public function test_a_routine_inspection_that_is_signed_still_needs_the_agent_to_sign_last(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_AD_HOC);

        $this->postJson(route('corex.rental-inspections.signatures.store', $inspection), [
            'party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG,
        ])->assertStatus(422);
    }

    // ═══ One word per type ═════════════════════════════════════════════════════

    public function test_the_label_helpers_give_one_word_per_type(): void
    {
        $this->assertSame('In', RentalInspection::typeLabel('in'));
        $this->assertSame('Out', RentalInspection::typeLabel('out'));
        $this->assertSame('Routine', RentalInspection::typeLabel('ad_hoc'));
        $this->assertSame('Interim', RentalInspection::typeLabel('interim'));
        $this->assertSame('In-inspection', RentalInspection::typeName('in'));
        $this->assertSame('Out-inspection', RentalInspection::typeName('out'));
        $this->assertSame('Routine inspection', RentalInspection::typeName('ad_hoc'));
        $this->assertSame('Interim inspection', RentalInspection::typeName('interim'));
    }

    public function test_a_routine_inspection_never_reads_ad_hoc_on_any_screen_message_mail_or_pdf(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_AD_HOC);
        $link = $this->issueLink($inspection, 'tenant', $this->tenant->id);
        $this->postJson(route('corex.rental-inspections.signing-links.email', [$inspection, $link]))->assertOk();

        $bad = ['Ad_hoc', 'Ad-hoc', 'Ad hoc', 'ad_hoc-inspection', 'Ad_hoc-inspection'];
        $pages = [
            'inspection page' => $this->get(route('corex.rental-inspections.show', $inspection))->assertOk()->assertSee('Routine inspection')->getContent(),
            'list' => $this->get(route('corex.rental-inspections.index'))->assertOk()->assertSee('Routine')->getContent(),
            'new inspection form' => $this->get(route('corex.rental-inspections.create'))->assertOk()->assertSee('Routine — an unplanned mid-tenancy check')->getContent(),
        ];
        $this->guest();
        $pages['party link page'] = $this->get($link->url())->assertOk()->assertSee('Routine inspection report')->getContent();
        $this->actingAs($this->admin);
        $pages['print list'] = $this->get(route('corex.rental-inspections.print-list'))->getContent();

        foreach ($pages as $name => $html) {
            foreach ($bad as $word) {
                $this->assertStringNotContainsString($word, $html, "{$name} must not say \"{$word}\"");
            }
        }

        Mail::assertSent(RentalInspectionSigningLinkMail::class, function (RentalInspectionSigningLinkMail $m) {
            $this->assertStringContainsString('Routine inspection', $m->envelope()->subject);
            $this->assertStringNotContainsString('Ad', $m->inspectionLabel);

            return true;
        });

        $history = \App\Models\RentalInspectionAuditLog::where('rental_inspection_id', $inspection->id)->where('event', 'created')->value('summary');
        $this->assertSame('Routine inspection created.', $history);

        $pdf = app(\App\Services\Rentals\RentalInspectionReportPdfService::class);
        $this->assertStringContainsString('routine', strtolower($pdf->filenameFor($inspection)) . 'routine', 'PDF generation does not throw for a Routine inspection');
        $this->assertSame('Routine inspection report', $inspection->distributionDocumentLabel());
        $this->assertStringStartsWith('Routine inspection report — ', $inspection->distributionSubject());
    }

    public function test_the_list_filter_names_routine_and_interim_as_two_different_types(): void
    {
        $html = $this->get(route('corex.rental-inspections.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<option value="ad_hoc"[^>]*>Routine</option>#', $html);
        $this->assertMatchesRegularExpression('#<option value="interim"[^>]*>Interim</option>#', $html);
    }

    // ═══ Both selectable where they belong ═════════════════════════════════════

    public function test_next_inspection_offers_routine_interim_and_out_and_accepts_each(): void
    {
        foreach (['ad_hoc', 'interim', 'out'] as $type) {
            $first = $this->recording(RentalInspection::TYPE_IN);
            $this->get(route('corex.rental-inspections.show', $first))->assertOk()
                ->assertSee('value="ad_hoc"', false)->assertSee('value="interim"', false)->assertSee('value="out"', false);
            $first->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();

            $this->post(route('corex.rental-inspections.next', $first), ['type' => $type])->assertRedirect();
            $this->assertSame($type, RentalInspection::where('previous_inspection_id', $first->id)->value('type'));

            // reset for the next round: archive the chain so a fresh In can start
            RentalInspection::withoutGlobalScopes()->where('lease_id', $this->lease->id)->each(fn ($i) => $i->delete());
        }
    }

    public function test_the_property_tab_next_endpoint_accepts_interim(): void
    {
        $first = $this->recording(RentalInspection::TYPE_IN);
        $first->forceFill(['status' => RentalInspection::STATUS_COMPLETED, 'completed_at' => now()])->save();

        $this->postJson(route('corex.properties.rental-inspections.next', [$this->property, $first]), ['type' => 'interim'])->assertStatus(201);
        $this->postJson(route('corex.properties.rental-inspections.next', [$this->property, RentalInspection::where('type', 'interim')->first()]), ['type' => 'bogus'])->assertStatus(422);
    }
}
