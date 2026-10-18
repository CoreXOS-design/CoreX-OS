<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Models\RentalInspection;
use App\Models\RentalInspectionItem;
use App\Models\RentalInspectionItemFinding;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionSetting;
use App\Services\Rentals\RentalInspectionReportPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\RentalInspections\Concerns\BuildsSigningFixture;
use Tests\TestCase;

/**
 * 8 Oct 2026 (Johan's rulings 5 and 6, spec §49):
 *
 *  5. After a report is sent, a tenant fault report inside the window and move-out comparison findings may still be added —
 *     but each is shown AND printed clearly marked "Added after the report was sent", with the date and who, apart from the
 *     locked report body (which does not change by a single line).
 *  6. A sent (or signed) report keeps the checklist item wording it was sent with: renaming or retiring a checklist item
 *     later never changes how the report reads.
 */
final class RentalInspectionRulingsAfterSentAndWordingTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningFixture;

    private const MARK = 'Added after the report was sent';

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

    /** A completed, fully signed inspection of $type with the one item graded good. */
    private function sent(string $type = RentalInspection::TYPE_IN): RentalInspection
    {
        $inspection = $this->ready($type);
        $this->everyoneSigns($inspection);
        $this->complete($inspection);

        return $inspection->fresh();
    }

    private function pdfText(RentalInspection $inspection): string
    {
        $in = tempnam(sys_get_temp_dir(), 'after-sent-') . '.pdf';
        file_put_contents($in, app(RentalInspectionReportPdfService::class)->generate($inspection->fresh())->output());
        $text = (string) shell_exec('pdftotext -layout ' . escapeshellarg($in) . ' - 2>/dev/null');
        @unlink($in);

        return $text;
    }

    /** Tenant fault report (damaged + note) filed through the real endpoint, inside the window, on a completed In. */
    private function tenantFaultReport(RentalInspection $in, string $note = 'Ceiling is leaking above the lounge after rain'): void
    {
        $this->postJson(route('corex.rental-inspections.observations.store', $in), [
            'rental_inspection_item_id' => $this->item->id, 'condition' => 'damaged', 'notes' => $note,
            'source' => RentalInspectionObservation::SOURCE_TENANT_FAULT_REPORT,
        ])->assertOk();
    }

    private function guestGet(string $url)
    {
        $this->guest();
        try {
            return $this->get($url);
        } finally {
            $this->actingAs($this->admin);
        }
    }

    // ═══ Rule 5 — added after the report was sent ═══════════════════════════════

    public function test_nothing_is_marked_on_a_report_that_has_not_been_sent(): void
    {
        $inspection = $this->ready(RentalInspection::TYPE_IN);

        $this->assertStringNotContainsString(strtolower(self::MARK), strtolower($this->pdfText($inspection)));
        $this->get(route('corex.rental-inspections.show', $inspection))->assertOk()->assertDontSee(self::MARK);
    }

    public function test_a_tenant_fault_report_is_still_accepted_after_the_report_is_sent(): void
    {
        $in = $this->sent();

        $this->tenantFaultReport($in);

        $this->assertSame(2, RentalInspectionObservation::withoutGlobalScopes()->where('rental_inspection_id', $in->id)->count());
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $in->fresh()->status, 'The sent report itself is untouched.');
    }

    public function test_the_pdf_prints_the_fault_report_in_its_own_marked_block_and_the_body_does_not_change(): void
    {
        $in = $this->sent();
        $before = $this->pdfText($in);
        $this->assertStringContainsString('Good', $before);

        $this->tenantFaultReport($in);
        $after = $this->pdfText($in);

        // marked, with the note, who and the date
        $this->assertStringContainsStringIgnoringCase(self::MARK, $after);
        $this->assertStringContainsString('Tenant fault report', $after);
        $this->assertStringContainsString('Ceiling is leaking above the lounge after rain', $after);
        $this->assertStringContainsString('Aileen Agent', $after);
        $this->assertStringContainsString(now()->format('d M Y'), substr($after, (int) stripos($after, self::MARK)));

        // the locked body: everything BEFORE the marked block reads exactly as it did (the item is still Good; the fault
        // report is not folded into the item's condition)
        $position = (int) stripos($after, self::MARK);
        $body = substr($after, 0, $position);
        $this->assertStringContainsString('Good', $body);
        $this->assertStringNotContainsString('Ceiling is leaking', $body);
        $this->assertStringNotContainsString('Damaged', $body);
    }

    public function test_the_public_link_and_the_signing_link_show_the_marked_block_apart_from_the_body(): void
    {
        $in = $this->sent();
        $token = $in->generatePublicLink();
        // A party's own link stays a read-only view of the signed report after completion (it cannot be issued afresh then).
        $link = \App\Models\RentalInspectionSigningLink::withoutGlobalScopes()->where('rental_inspection_id', $in->id)->firstOrFail();
        $this->tenantFaultReport($in);

        foreach ([route('rental-inspections.public.show', $token), route('rental-inspections.sign.show', $link->token)] as $url) {
            $html = $this->guestGet($url)->assertOk()->getContent();
            $at = strpos($html, 'data-qa="added-after-sent"');
            $this->assertNotFalse($at, "Marked block missing on {$url}");

            $this->assertStringContainsString(self::MARK, $html);
            $block = substr($html, $at);
            $this->assertStringContainsString('Ceiling is leaking above the lounge after rain', $block);
            $this->assertStringContainsString('Aileen Agent', $block);
            $this->assertStringContainsString(now()->format('d M Y'), $block);

            $body = substr($html, 0, $at);
            $this->assertStringContainsString('Good', $body);
            $this->assertStringNotContainsString('Ceiling is leaking', $body, 'The note must appear only in the marked block.');
        }
    }

    public function test_the_inspection_page_shows_it_marked_and_keeps_the_body_unchanged(): void
    {
        $in = $this->sent();
        $this->tenantFaultReport($in);

        $html = $this->get(route('corex.rental-inspections.show', $in))->assertOk()->getContent();
        $at = strpos($html, 'data-qa="added-after-sent"');
        $this->assertNotFalse($at);
        $this->assertStringContainsString('Tenant fault report', substr($html, $at));
        $body = substr($html, 0, $at);
        $this->assertStringContainsString('Lounge Ceiling', $body);
        $this->assertStringNotContainsString('Ceiling is leaking', $body);
    }

    public function test_a_move_out_finding_added_after_the_out_is_sent_is_marked_in_the_pdf_page_and_links(): void
    {
        $out = $this->sent(RentalInspection::TYPE_OUT);
        $token = $out->generatePublicLink();

        $this->travel(1)->minutes();
        RentalInspectionItemFinding::record($out->fresh(), $this->item, RentalInspectionItemFinding::DISPOSITION_CHARGE_TENANT, 'Ceiling stain is beyond fair wear and tear', $this->admin);

        $text = $this->pdfText($out);
        $this->assertStringContainsStringIgnoringCase(self::MARK, $text);
        $this->assertStringContainsString('Move-out finding', $text);
        $this->assertStringContainsString('Charge to tenant', $text);
        $this->assertStringContainsString('Ceiling stain is beyond fair wear and tear', $text);
        $this->assertStringContainsString('Aileen Agent', $text);

        $page = $this->get(route('corex.rental-inspections.show', $out))->assertOk();
        $page->assertSee(self::MARK)->assertSee('Move-out finding')->assertSee('Ceiling stain is beyond fair wear and tear');

        $this->guestGet(route('rental-inspections.public.show', $token))->assertOk()
            ->assertSee(self::MARK)->assertSee('Charge to tenant')->assertSee('Ceiling stain is beyond fair wear and tear');
    }

    public function test_a_finding_recorded_before_the_report_was_sent_is_not_in_the_marked_block(): void
    {
        $out = $this->ready(RentalInspection::TYPE_OUT);
        RentalInspectionItemFinding::record($out, $this->item, RentalInspectionItemFinding::DISPOSITION_PRE_EXISTING, 'Stain was there at move-in', $this->admin);
        $this->everyoneSigns($out);
        $this->travel(1)->minutes();
        $this->complete($out);

        $this->assertStringNotContainsString(strtolower(self::MARK), strtolower($this->pdfText($out)));
    }

    public function test_a_corrected_finding_shows_only_its_live_version(): void
    {
        $out = $this->sent(RentalInspection::TYPE_OUT);
        $this->travel(1)->minutes();
        RentalInspectionItemFinding::record($out->fresh(), $this->item, RentalInspectionItemFinding::DISPOSITION_FLAGGED, 'first thought', $this->admin);
        RentalInspectionItemFinding::record($out->fresh(), $this->item, RentalInspectionItemFinding::DISPOSITION_WEAR_AND_TEAR, 'on reflection, wear and tear', $this->admin);

        $text = $this->pdfText($out);
        $this->assertStringContainsString('on reflection, wear and tear', $text);
        $this->assertStringNotContainsString('first thought', $text);
    }

    public function test_the_added_entries_are_scoped_to_their_own_inspection(): void
    {
        $in = $this->sent();
        $this->tenantFaultReport($in);

        $other = $this->recording(RentalInspection::TYPE_AD_HOC);
        $this->complete($other);

        $this->assertStringNotContainsString(strtolower(self::MARK), strtolower($this->pdfText($other)));
    }

    // ═══ Rule 6 — a sent (or signed) report keeps its checklist wording ═════════════

    public function test_completing_fixes_the_wording_and_a_later_rename_never_changes_how_it_reads(): void
    {
        $in = $this->sent();
        $this->assertSame([(string) $this->item->id => 'Lounge Ceiling'], array_map('strval', (array) $in->checklist_wording_snapshot));
        $token = $in->generatePublicLink();

        $this->postJson(route('corex.properties.rental-inspection-items.rename', [$this->property, $this->item]), ['label' => 'Living room ceiling (repainted)'])->assertOk();
        $this->assertSame('Living room ceiling (repainted)', $this->item->fresh()->label, 'The checklist itself is renamed…');

        // …but the sent report reads as it was sent, on every surface
        $this->assertStringContainsString('Lounge Ceiling', $this->pdfText($in));
        $this->assertStringNotContainsString('Living room ceiling', $this->pdfText($in));
        $this->guestGet(route('rental-inspections.public.show', $token))->assertOk()->assertSee('Lounge Ceiling')->assertDontSee('Living room ceiling');
        $this->get(route('corex.rental-inspections.show', $in))->assertOk()->assertSee('Lounge Ceiling')->assertDontSee('Living room ceiling');

        // nothing was ever written back to the checklist by showing the report
        $this->assertSame('Living room ceiling (repainted)', RentalInspectionItem::withoutGlobalScopes()->find($this->item->id)->label);
    }

    public function test_retiring_an_item_later_does_not_change_the_sent_report(): void
    {
        $in = $this->sent();
        $token = $in->generatePublicLink();

        $this->postJson(route('corex.properties.rental-inspection-items.retire', [$this->property, $this->item]))->assertOk();

        $this->assertStringContainsString('Lounge Ceiling', $this->pdfText($in));
        $this->guestGet(route('rental-inspections.public.show', $token))->assertOk()->assertSee('Lounge Ceiling');
    }

    public function test_renaming_then_retiring_then_a_new_item_added_later_the_sent_report_is_unchanged(): void
    {
        $in = $this->sent();
        $before = $this->pdfText($in);

        $this->postJson(route('corex.properties.rental-inspection-items.rename', [$this->property, $this->item]), ['label' => 'Ceiling'])->assertOk();
        $this->postJson(route('corex.properties.rental-inspection-items.retire', [$this->property, $this->item]))->assertOk();
        RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $this->room->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Brand new item', 'sort_order' => 5, 'created_by_user_id' => $this->admin->id,
        ]);

        $strip = fn (string $t) => preg_replace('/\s+/', ' ', $t);
        $this->assertSame($strip($before), $strip($this->pdfText($in)));
    }

    public function test_the_wording_is_fixed_when_the_first_party_signs_not_only_at_completion(): void
    {
        $in = $this->ready(RentalInspection::TYPE_IN);
        $this->assertEmpty($in->fresh()->checklist_wording_snapshot, 'Nobody has signed: wording is still live.');

        $this->signByLink($in, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->assertSame('Lounge Ceiling', ((array) $in->fresh()->checklist_wording_snapshot)[$this->item->id] ?? null);

        // even a rename that bypassed the signed-lock guard (a direct write) cannot change what the signed report reads
        $this->item->forceFill(['label' => 'Renamed behind the lock'])->save();
        $this->signAllRest($in);
        $this->complete($in);
        $this->assertStringContainsString('Lounge Ceiling', $this->pdfText($in));
        $this->assertStringNotContainsString('Renamed behind the lock', $this->pdfText($in));
    }

    private function signAllRest(RentalInspection $in): void
    {
        $link2 = $this->issueLink($in, 'tenant', $this->tenant2->id);
        $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$in, $link2]), $this->signPayload(['typed_name' => 'Sipho Khumalo']))->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $in), ['party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $in), ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG])->assertStatus(201);
    }

    public function test_the_signature_page_a_party_reads_shows_the_wording_they_signed(): void
    {
        $in = $this->ready(RentalInspection::TYPE_IN);
        $link = $this->issueLink($in, 'tenant', $this->tenant->id);
        $this->signByLink($in, 'tenant', $this->tenant2, 'Sipho Khumalo');
        $this->item->forceFill(['label' => 'Renamed behind the lock'])->save();

        $this->guestGet(route('rental-inspections.sign.show', $link->token))->assertOk()->assertSee('Lounge Ceiling')->assertDontSee('Renamed behind the lock');
    }

    public function test_edit_report_clears_the_wording_and_the_next_signature_fixes_the_new_wording(): void
    {
        $in = $this->ready(RentalInspection::TYPE_IN);
        $this->signByLink($in, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->assertNotEmpty($in->fresh()->checklist_wording_snapshot);

        $this->postJson(route('corex.rental-inspections.reopen', $in), ['reason' => 'Wrong wording on the ceiling item'])->assertOk();
        $this->assertEmpty($in->fresh()->checklist_wording_snapshot, 'Nobody\'s signature stands: the wording is free again.');

        // the agent legitimately renames now (nothing is signed), then everyone signs again
        $this->postJson(route('corex.properties.rental-inspection-items.rename', [$this->property, $this->item]), ['label' => 'Lounge ceiling (damp stain)'])->assertOk();
        $this->readyToSign($in->fresh());
        $this->signByLink($in->fresh(), 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->assertSame('Lounge ceiling (damp stain)', ((array) $in->fresh()->checklist_wording_snapshot)[$this->item->id] ?? null);
    }

    public function test_the_reopen_record_keeps_the_wording_the_signers_saw(): void
    {
        $in = $this->ready(RentalInspection::TYPE_IN);
        $this->signByLink($in, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->postJson(route('corex.rental-inspections.reopen', $in), ['reason' => 'Agent noticed a missing note'])->assertOk();

        $reopen = $in->fresh()->reopens()->firstOrFail();
        $this->assertSame('Lounge Ceiling', $reopen->report_snapshot['items'][0]['item']);
    }

    public function test_a_report_sent_before_this_build_has_no_snapshot_and_reads_the_live_wording_as_before(): void
    {
        $in = $this->sent();
        \Illuminate\Support\Facades\DB::table('rental_inspections')->where('id', $in->id)->update(['checklist_wording_snapshot' => null, 'checklist_wording_snapshot_at' => null]);
        $this->item->forceFill(['label' => 'Live wording today'])->save();

        $this->assertStringContainsString('Live wording today', $this->pdfText($in), 'No data was repaired: an old report keeps reading from the live checklist.');
        $this->assertSame([], app(\App\Services\Rentals\RentalInspectionAddedAfterSentService::class)->entriesFor($in->fresh())->all());
    }

    public function test_the_snapshot_holds_every_item_of_the_property_live_or_retired(): void
    {
        $retired = RentalInspectionItem::create([
            'agency_id' => $this->agency->id, 'property_id' => $this->property->id, 'property_room_id' => $this->room->id,
            'kind' => RentalInspectionItem::KIND_SPACE, 'label' => 'Old retired thing', 'sort_order' => 9, 'is_retired' => true, 'created_by_user_id' => $this->admin->id,
        ]);

        $in = $this->sent();

        $snapshot = (array) $in->checklist_wording_snapshot;
        $this->assertSame('Lounge Ceiling', $snapshot[$this->item->id]);
        $this->assertSame('Old retired thing', $snapshot[$retired->id]);
    }

    public function test_the_snapshot_is_taken_once_a_second_signature_or_completion_never_overwrites_it(): void
    {
        $in = $this->ready(RentalInspection::TYPE_IN);
        $this->signByLink($in, 'tenant', $this->tenant, 'Naledi Dlamini');
        $first = $in->fresh()->checklist_wording_snapshot_at;

        $this->travel(5)->minutes();
        $this->item->forceFill(['label' => 'Changed later'])->save();
        $this->signAllRest($in);
        $this->complete($in);

        $fresh = $in->fresh();
        $this->assertEquals($first, $fresh->checklist_wording_snapshot_at);
        $this->assertSame('Lounge Ceiling', ((array) $fresh->checklist_wording_snapshot)[$this->item->id]);
    }

    public function test_a_second_agencys_report_is_unaffected_by_the_first_agencys_checklist(): void
    {
        $in = $this->sent();
        $other = \App\Models\Agency::create(['name' => 'Durban Lettings', 'slug' => 'durban-' . uniqid()]);

        $this->assertNotSame($other->id, $in->agency_id);
        $this->assertSame(RentalInspectionSetting::signaturesRequiredFor($other->id, 'in'), true);
        $this->assertSame([(string) $this->item->id], array_map('strval', array_keys((array) $in->checklist_wording_snapshot)));
    }
}
