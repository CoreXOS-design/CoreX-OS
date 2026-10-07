<?php

declare(strict_types=1);

namespace Tests\Feature\RentalInspections;

use App\Mail\Rentals\RentalInspectionSigningLinkMail;
use App\Models\Role;
use App\Models\RolePermission;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAuditLog;
use App\Models\RentalInspectionDiscrepancy;
use App\Models\RentalInspectionObservation;
use App\Models\RentalInspectionPhoto;
use App\Models\RentalInspectionReopen;
use App\Models\RentalInspectionRoomNote;
use App\Models\RentalInspectionScan;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInspectionSigningLink;
use App\Models\SignedDocumentDistributionLog;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\RentalInspections\Concerns\BuildsSigningFixture;
use Tests\TestCase;

/**
 * §47 — Johan's rulings of 7 Oct 2026:
 *   1. A SIGNED report is locked. "Edit report" (warns, reason required) voids EVERY signature, however it was given, and
 *      sends the report back to the not-signed state; everyone signs again. Voided signatures are history, never counted,
 *      never printed; outstanding links are revoked; nobody is emailed until the agent resends.
 *   2. Once the report has been DISTRIBUTED (completed / copies sent) it can NEVER be edited or reopened by anyone, in any
 *      role, on any write path. The only way forward is a NEW inspection that replaces it.
 */
final class RentalInspectionSignedLockTest extends TestCase
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

    // ───────────────────────────── helpers ─────────────────────────────

    /** Every way the report's CONTENT can be written, as [label, method, url, payload]. @return array<int, array{0:string,1:string,2:string,3:array}> */
    private function contentWrites(RentalInspection $i): array
    {
        $photo = RentalInspectionPhoto::where('rental_inspection_id', $i->id)->first() ?? $this->photoOn($i);
        $obs = RentalInspectionObservation::where('rental_inspection_id', $i->id)->first();
        $image = UploadedFile::fake()->image('p.jpg');

        return [
            ['observation', 'postJson', route('corex.rental-inspections.observations.store', $i), ['rental_inspection_item_id' => $this->item->id, 'condition' => 'good', 'notes' => 'changed', 'source' => 'in_inspection']],
            ['room not applicable', 'postJson', route('corex.rental-inspections.rooms.mark-na', [$i, $this->room]), []],
            ['room all good', 'postJson', route('corex.rental-inspections.rooms.mark-good', [$i, $this->room]), []],
            ['everything all good', 'postJson', route('corex.rental-inspections.mark-all-good', $i), []],
            ['room note', 'postJson', route('corex.rental-inspections.rooms.notes.store', [$i, $this->room]), ['note' => 'a changed note']],
            ['overall notes', 'postJson', route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'changed']],
            ['details', 'postJson', route('corex.rental-inspections.details.update', $i), ['keys_count' => 9]],
            ['photo upload (tray)', 'postJson', route('corex.rental-inspections.photos.store', $i), ['photos' => [$image]]],
            ['photo upload (item)', 'postJson', route('corex.rental-inspections.observations.photos.store', [$i, $obs]), ['photo' => $image]],
            ['photo tag', 'postJson', route('corex.rental-inspections.photos.tag', [$i, $photo]), ['property_room_id' => $this->room->id]],
            ['photo tag (bulk)', 'postJson', route('corex.rental-inspections.photos.tag-bulk', $i), ['photo_ids' => [$photo->id], 'property_room_id' => $this->room->id]],
            ['photo untag', 'postJson', route('corex.rental-inspections.photos.untag', [$i, $photo]), []],
            ['photo archive', 'deleteJson', route('corex.rental-inspections.photos.archive', [$i, $photo]), []],
            ['photo note', 'postJson', route('corex.rental-inspections.photos.notes.store', [$i, $photo]), ['note' => 'a note', 'classification' => 'other']],
        ];
    }

    private function snapshotOf(RentalInspection $i): array
    {
        return [
            'observations' => RentalInspectionObservation::where('rental_inspection_id', $i->id)->count(),
            'photos' => RentalInspectionPhoto::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->whereNull('deleted_at')->count(),
            'room_notes' => RentalInspectionRoomNote::where('rental_inspection_id', $i->id)->count(),
            'fingerprint' => $i->fresh()->reportFingerprint(),
            'overall' => (string) $i->fresh()->overall_notes,
            'keys' => $i->fresh()->keys_count,
        ];
    }

    private function assertEveryContentWriteRefused(RentalInspection $i, ?string $reason): void
    {
        $before = $this->snapshotOf($i);
        foreach ($this->contentWrites($i) as [$label, $method, $url, $payload]) {
            $response = $this->$method($url, $payload);
            $this->assertSame(409, $response->status(), "{$label} must be refused (got {$response->status()}: " . substr((string) $response->getContent(), 0, 200) . ')');
            if ($reason) {
                $this->assertSame($reason, $response->json('reason'), $label);
            }
        }
        $this->assertSame($before, $this->snapshotOf($i), 'Nothing about the report changed.');
    }

    private function distribute(RentalInspection $i): RentalInspection
    {
        $this->everyoneSigns($i);
        $this->complete($i);

        return $i->fresh();
    }

    // ═══ 1. A signed report is locked ═══════════════════════════════════════════

    public function test_one_signature_locks_every_content_write_path(): void
    {
        $i = $this->ready();
        $this->contentWrites($i); // creates the photo while the report is still open
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');

        $this->assertTrue($i->fresh()->isSignedLocked());
        $this->assertEveryContentWriteRefused($i, 'signed_locked');
    }

    public function test_the_lock_message_says_what_to_do(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');

        $message = $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'x'])->json('message');
        $this->assertStringContainsString('signed, so it is locked', $message);
        $this->assertStringContainsString('Edit report', $message);
        $this->assertStringContainsString('clears ALL signatures', $message);
    }

    /** @dataProvider lockingSignatureRoutes */
    public function test_every_way_of_signing_locks_the_report(string $route): void
    {
        $i = $this->ready();
        $this->assertFalse($i->isSignedLocked());

        match ($route) {
            'link' => $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini'),
            'device' => $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$i, $this->issueLink($i, 'tenant', $this->tenant->id)]), $this->signPayload())->assertStatus(201),
            'corex' => $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'tenant', 'disposition' => 'signed', 'party_contact_id' => $this->tenant->id, 'signature_image' => self::PNG])->assertStatus(201),
            'paper' => $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'tenant', 'disposition' => 'wet_ink', 'party_contact_id' => $this->tenant->id, 'wet_ink_file' => UploadedFile::fake()->image('scan.jpg')])->assertStatus(201),
        };

        $this->assertTrue($i->fresh()->isSignedLocked(), $route);
        $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'x'])->assertStatus(409);
    }

    /** @return array<string, array{0:string}> */
    public static function lockingSignatureRoutes(): array
    {
        return ['link / QR' => ['link'], "agent's device" => ['device'], 'in CoreX' => ['corex'], 'paper (wet-ink)' => ['paper']];
    }

    public function test_the_agents_pin_signature_locks_the_report_too(): void
    {
        $i = $this->ready();
        $this->everyoneSigns($i); // the last of them is the agent's own signature

        $this->assertTrue($i->fresh()->hasAgentSignature());
        $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'x'])->assertStatus(409);
    }

    public function test_a_refusal_or_a_paper_marker_alone_does_not_lock_the_report(): void
    {
        $i = $this->ready();
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'tenant', 'disposition' => 'refused', 'party_contact_id' => $this->tenant->id, 'refusal_reason_preset' => 'disputes_condition'])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'tenant', 'disposition' => 'awaiting_wet_ink', 'party_contact_id' => $this->tenant2->id])->assertStatus(201);

        $this->assertFalse($i->fresh()->isSignedLocked());
        $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'fixed after the dispute'])->assertOk();
        $this->postJson(route('corex.rental-inspections.signing-links.issue', $i), ['party_role' => 'landlord', 'party_contact_id' => $this->landlord->id])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'nothing to reopen'])->assertStatus(409);
    }

    public function test_more_people_can_still_sign_a_locked_report(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->signByLink($i, 'tenant', $this->tenant2, 'Sipho Khumalo');
        $this->signByLink($i, 'landlord', $this->landlord, 'Pieter van Wyk');

        $this->assertCount(3, $this->liveSignatures($i));
    }

    public function test_the_details_form_autosave_that_changes_nothing_is_not_an_error_on_a_locked_report(): void
    {
        $i = $this->ready();
        $this->postJson(route('corex.rental-inspections.details.update', $i), ['keys_count' => 3])->assertOk();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');

        $this->postJson(route('corex.rental-inspections.details.update', $i), ['keys_count' => 3])->assertOk();
        $this->postJson(route('corex.rental-inspections.details.update', $i), ['keys_count' => 4])->assertStatus(409);
        $this->assertSame(3, $i->fresh()->keys_count);
    }

    public function test_the_checklist_cannot_change_underneath_a_signed_report_and_can_after_a_reopen(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $p = $this->property;
        $refused = [
            $this->postJson(route('corex.properties.rental-inspection-items.store', $p), ['kind' => 'item', 'label' => 'Extra item', 'property_room_id' => $this->room->id]),
            $this->postJson(route('corex.properties.rental-inspection-items.rename', [$p, $this->item]), ['label' => 'Renamed']),
            $this->postJson(route('corex.properties.rental-inspection-items.retire', [$p, $this->item])),
            $this->postJson(route('corex.properties.rental-inspection-items.restore', [$p, $this->item])),
            $this->postJson(route('corex.properties.rental-inspection-items.seed-from-advertising', $p)),
            $this->postJson(route('corex.properties.rental-inspection-rooms.add-missing-standard-items', [$p, $this->room]), ['labels' => ['Walls']]),
        ];
        foreach ($refused as $n => $response) {
            $this->assertSame(409, $response->status(), "checklist write #{$n}");
        }
        $this->assertSame('Lounge Ceiling', $this->item->fresh()->label);

        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Wrong item name'])->assertOk();
        $this->postJson(route('corex.properties.rental-inspection-items.rename', [$p, $this->item]), ['label' => 'Lounge ceiling (cornice)'])->assertOk();
        $this->assertSame('Lounge ceiling (cornice)', $this->item->fresh()->label);
    }

    // ═══ Edit report: voids EVERYTHING, kept as history ═════════════════════════

    public function test_edit_report_voids_every_signature_whatever_route_it_came_by_and_reopens_the_report(): void
    {
        $i = $this->ready();
        $this->everyoneSigns($i); // tenant by link, tenant2 on the device, landlord and agent in CoreX
        $this->assertCount(4, $this->liveSignatures($i));
        $fingerprint = $i->fresh()->reportFingerprint();

        $response = $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'The geyser was recorded as good, it is not.'])->assertOk();
        $response->assertJsonPath('voided', 4);

        $i->refresh();
        $this->assertSame(RentalInspection::STATUS_DRAFT, $i->status, 'back to the not-signed state');
        $this->assertNull($i->signing_deadline_at);
        $this->assertCount(0, $this->liveSignatures($i), 'no signature counts any more');
        $this->assertFalse($i->isSignedLocked());
        $this->assertFalse($i->hasAgentSignature());
        $this->assertCount(3, $i->outstandingSignatories(), 'both tenants and the landlord must sign again');
        $this->assertSame(4, RentalInspectionSignature::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->whereNotNull('voided_by_reopen_id')->count(), 'kept as history, never deleted');
        $this->assertSame(0, RentalInspectionSignature::withoutGlobalScopes()->where('rental_inspection_id', $i->id)->whereNull('superseded_at')->count());

        $reopen = RentalInspectionReopen::firstOrFail();
        $this->assertSame($this->admin->id, $reopen->reopened_by_user_id);
        $this->assertSame('The geyser was recorded as good, it is not.', $reopen->reason);
        $this->assertSame('awaiting_signature', $reopen->previous_status);
        $this->assertSame($fingerprint, $reopen->report_fingerprint, 'the report is locked while signed, so this IS what they signed');
        $this->assertSame('Lounge Ceiling', $reopen->report_snapshot['items'][0]['item']);
        $this->assertSame('good', $reopen->report_snapshot['items'][0]['condition']);
        $this->assertCount(4, $reopen->voided_signatures);
        $this->assertEqualsCanonicalizing(['tenant', 'tenant', 'landlord', 'agent'], array_column($reopen->voided_signatures, 'party_role'));
        $this->assertEqualsCanonicalizing(['link', 'agent_device', null, null], array_column($reopen->voided_signatures, 'signed_via'));
        $this->assertContains('Naledi Dlamini', array_column($reopen->voided_signatures, 'name'));
        // every signature carries a fingerprint of the report it was given on, whatever route recorded it
        $this->assertSame([$fingerprint], array_values(array_unique(array_column($reopen->voided_signatures, 'signed_report_fingerprint'))));
    }

    public function test_after_edit_report_the_content_is_editable_again(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'x'])->assertStatus(409);

        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Correcting a note'])->assertOk();

        $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'corrected'])->assertOk();
        $this->postJson(route('corex.rental-inspections.observations.store', $i), ['rental_inspection_item_id' => $this->item->id, 'condition' => 'good', 'notes' => 'corrected', 'source' => 'in_inspection'])->assertSuccessful();
        $this->assertSame('corrected', $i->fresh()->overall_notes);
    }

    public function test_edit_report_needs_a_reason_and_the_edit_permission(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');

        $this->postJson(route('corex.rental-inspections.reopen', $i), [])->assertStatus(422);
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'x'])->assertStatus(422);
        $this->assertCount(1, $this->liveSignatures($i), 'a refused attempt voids nothing');

        // An agent who may record but not edit inspection details cannot reopen a signed report.
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $this->agency->id], ['label' => 'Agent']);
        foreach (['rental_inspections.view' => 'all', 'rental_inspections.create' => null, 'access_properties' => null] as $key => $scope) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => $scope]);
        }
        PermissionService::clearCache();
        $this->actingAs($this->inspector);
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'I would like to change it'])->assertStatus(403);
        $this->assertCount(1, $this->liveSignatures($i));

        RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => 'rental_inspections.edit_details', 'agency_id' => $this->agency->id], ['scope' => null]);
        PermissionService::clearCache();
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Now permitted'])->assertOk();
    }

    public function test_edit_report_revokes_every_outstanding_link_and_emails_nobody(): void
    {
        $i = $this->ready();
        $signed = $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $unsigned = $this->issueLink($i, 'tenant', $this->tenant2->id);
        $this->linkService()->sendEmail($unsigned, $this->admin);
        Mail::fake(); // forget the one send above: from here on, nothing may go out on its own

        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Changing a condition'])->assertOk()->assertJsonPath('links_revoked', 2);

        Mail::assertNothingSent();
        foreach ([$signed, $unsigned] as $link) {
            $this->assertSame('revoked', $link->fresh()->status());
            $this->assertSame('reopened', $link->fresh()->revoked_reason);
        }
        $this->guest();
        $this->get($signed->url())->assertOk()->assertSee('Please contact your agent for a current link.');
        $this->postJson(route('rental-inspections.sign.submit', $unsigned->token), $this->signPayload())->assertStatus(404);
        $this->assertCount(0, $this->liveSignatures($i));
    }

    public function test_voided_signatures_never_print_or_count_and_show_as_history(): void
    {
        $i = $this->ready();
        $this->everyoneSigns($i);
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Wrong meter reading'])->assertOk();

        foreach ($i->fresh(['lease.tenants.contact', 'property', 'signatures'])->signatureSummaryRows() as $row) {
            $this->assertNull($row['signature'], "{$row['role']}: a voided signature is never presented as a signature (PDF, public page)");
        }
        $pdfRows = app(\App\Services\Rentals\RentalInspectionReportPdfService::class);
        $this->assertNotNull($pdfRows->generate($i->fresh())->output());

        $page = $this->get(route('corex.rental-inspections.show', $i))->assertOk()
            ->assertSee('data-qa="voided-signature"', false)->assertSee('Voided')->assertSee('Wrong meter reading')->assertSee('Aileen Agent');
        $this->assertStringNotContainsString('alt="Tenant', $page->getContent(), 'no voided signature image is rendered');

        $event = RentalInspectionAuditLog::where('event', 'report_reopened')->firstOrFail();
        $this->assertStringContainsString('Wrong meter reading', (string) $event->summary);
        $this->assertStringContainsString('4 signature(s) voided', (string) $event->summary);
        $this->assertSame($this->admin->id, $event->user_id);
        $this->get(route('corex.rental-inspections.show', $i))->assertSee('Report reopened for editing');
    }

    public function test_marking_ready_to_sign_again_issues_fresh_links_and_the_agent_is_told_to_resend(): void
    {
        $i = $this->ready();
        $oldTenant = $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $oldLandlord = $this->signByLink($i, 'landlord', $this->landlord, 'Pieter van Wyk');
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Changing a note'])->assertOk();
        $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'changed'])->assertOk();

        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $i))->assertOk()->assertJsonPath('fresh_links_issued', 2);

        $newTenant = RentalInspectionSigningLink::where('party_role', 'tenant')->where('party_contact_id', $this->tenant->id)->whereNull('revoked_at')->firstOrFail();
        $this->assertNotSame($oldTenant->token, $newTenant->token);
        $this->assertNull($newTenant->last_sent_at, 'issued, not sent');
        $this->assertNotNull(RentalInspectionSigningLink::where('party_role', 'landlord')->whereNull('revoked_at')->first());
        Mail::assertNothingSent();

        $panel = $this->getJson(route('corex.rental-inspections.signing-links.index', $i))->assertOk()->json();
        $this->assertTrue($panel['reopened']['resend_needed']);
        $this->assertSame('Changing a note', $panel['reopened']['reason']);
        $this->get(route('corex.rental-inspections.show', $i))->assertSee('data-qa="report-lock"', false)->assertSee('resend each person their link');

        // A person who had signed hears about it only when the agent resends — and is told why.
        $this->postJson(route('corex.rental-inspections.signing-links.email', [$i, $newTenant]))->assertOk();
        Mail::assertSent(RentalInspectionSigningLinkMail::class, function (RentalInspectionSigningLinkMail $m) {
            $html = $m->render();
            $this->assertTrue($m->reSign);
            $this->assertStringContainsString('changed since you last signed', $html);
            $this->assertStringContainsString('sign again', strtolower($m->envelope()->subject));

            return true;
        });
        $this->assertTrue($this->getJson(route('corex.rental-inspections.signing-links.index', $i))->json('reopened.resend_needed'), 'the landlord has not been sent theirs yet');
        $this->postJson(route('corex.rental-inspections.signing-links.email', [$i, RentalInspectionSigningLink::where('party_role', 'landlord')->whereNull('revoked_at')->first()]))->assertOk();
        $this->assertFalse($this->getJson(route('corex.rental-inspections.signing-links.index', $i))->json('reopened.resend_needed'));
    }

    public function test_the_new_link_page_says_the_report_changed_and_everyone_can_sign_again_and_complete(): void
    {
        $i = $this->ready();
        $this->everyoneSigns($i);
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Corrections'])->assertOk();
        $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'corrected'])->assertOk();
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $i))->assertOk();

        $fresh = RentalInspectionSigningLink::where('party_role', 'tenant')->where('party_contact_id', $this->tenant->id)->whereNull('revoked_at')->firstOrFail();
        $this->guest();
        $this->get($fresh->url())->assertOk()->assertSee('data-qa="resign-notice"', false)->assertSee('your earlier signature no longer counts');
        $this->actingAs($this->admin);

        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->signByLink($i, 'tenant', $this->tenant2, 'Sipho Khumalo');
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'landlord', 'disposition' => 'signed', 'party_contact_id' => $this->landlord->id, 'signature_image' => self::PNG])->assertStatus(201);
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'agent', 'disposition' => 'signed', 'signature_image' => self::PNG])->assertStatus(201);
        $this->complete($i);

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $i->fresh()->status);
        $this->assertCount(4, $this->liveSignatures($i));
        $this->assertSame(1, RentalInspectionReopen::count(), 'one report, one reopen — not a second copy');
        $this->assertSame(1, RentalInspection::where('lease_id', $this->lease->id)->count(), 'one report, not a second copy floating around');
    }

    public function test_an_unsigned_report_cannot_be_reopened_and_a_second_reopen_works(): void
    {
        $i = $this->ready();
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Nothing signed yet'])->assertStatus(409);

        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'First change'])->assertOk();
        $this->readyToSign($i);
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Second change'])->assertOk();

        $this->assertSame(2, RentalInspectionReopen::count());
        $this->assertSame(['First change', 'Second change'], RentalInspectionReopen::orderBy('id')->pluck('reason')->all());
    }

    public function test_the_reopen_history_is_append_only(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Change'])->assertOk();

        $row = RentalInspectionReopen::firstOrFail();
        $this->expectException(\LogicException::class);
        $row->update(['reason' => 'rewritten']);
    }

    public function test_the_edit_report_control_and_the_warning_are_on_the_screens(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');

        $this->get(route('corex.rental-inspections.show', $i))->assertOk()
            ->assertSee('data-qa="edit-report"', false)->assertSee('Editing clears ALL signatures. Everyone must sign again.')->assertSee('Why is the report being changed?');
        $this->get(route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'inspections']))->assertOk()
            ->assertSee('data-qa="report-lock"', false)->assertSee('Editing clears ALL signatures. Everyone must sign again.');
        $this->assertTrue($this->getJson(route('corex.rental-inspections.signing-links.index', $i))->json('lock.can_reopen'));
    }

    // ═══ 2. Distributed: never edited, never reopened, by anyone ════════════════

    public function test_distributed_means_completed_or_any_email_copy_sent_to_a_tenant_or_landlord(): void
    {
        $open = $this->ready();
        $this->assertFalse($open->isDistributed());
        $this->assertFalse($open->fresh()->isDistributed());

        // a copy that left CoreX counts even before the status says so
        SignedDocumentDistributionLog::create([
            'agency_id' => $this->agency->id, 'distributable_type' => RentalInspection::class, 'distributable_id' => $open->id,
            'channel' => 'email', 'mode' => 'manual', 'recipient_role' => 'tenant', 'recipient_email' => 'naledi@example.co.za', 'status' => 'sent',
        ]);
        $this->assertTrue($open->fresh()->isDistributed());

        $other = $this->ready('out');
        SignedDocumentDistributionLog::create([
            'agency_id' => $this->agency->id, 'distributable_type' => RentalInspection::class, 'distributable_id' => $other->id,
            'channel' => 'email', 'mode' => 'auto', 'recipient_role' => 'inspector', 'recipient_email' => 'ivan@cape.test', 'status' => 'sent',
        ]);
        $this->assertFalse($other->fresh()->isDistributed(), 'a copy to the agency\'s own people is not a distribution to the parties');

        $done = $this->distribute($this->ready('interim'));
        $this->assertTrue($done->isDistributed());
    }

    /** @dataProvider everyType */
    public function test_a_distributed_report_refuses_every_content_write_path(string $type): void
    {
        $i = $this->ready($type);
        $this->contentWrites($i);
        $i = $this->distribute($i);

        $this->assertEveryContentWriteRefused($i, 'not_recordable');
        $message = $this->postJson(route('corex.rental-inspections.overall-notes.update', $i), ['overall_notes' => 'x'])->json('message');
        $this->assertStringContainsString('sent to the parties', $message);
        $this->assertStringContainsString('new inspection that replaces it', $message);
    }

    /** @return array<string, array{0:string}> */
    public static function everyType(): array
    {
        return ['in' => ['in'], 'out' => ['out'], 'interim' => ['interim'], 'routine' => ['ad_hoc']];
    }

    public function test_a_distributed_report_refuses_signatures_attendance_links_and_status_changes(): void
    {
        $i = $this->ready();
        $link = $this->issueLink($i, 'tenant', $this->tenant->id);
        $unusedLink = $this->issueLink($i, 'landlord', $this->landlord->id);
        $this->everyoneSigns($i);
        $this->complete($i);
        $i->refresh();
        $liveBefore = $this->liveSignatures($i)->pluck('id')->sort()->values()->all();
        $linksBefore = RentalInspectionSigningLink::count();

        // signatures — every route
        $this->postJson(route('corex.rental-inspections.signatures.store', $i), ['party_role' => 'tenant', 'disposition' => 'refused', 'party_contact_id' => $this->tenant->id, 'refusal_reason_preset' => 'other', 'refusal_reason_note' => 'x'])->assertStatus(409);
        $signed = RentalInspectionSignature::where('rental_inspection_id', $i->id)->where('party_role', 'tenant')->first();
        $this->postJson(route('corex.rental-inspections.signatures.supersede-wet-ink', [$i, $signed]), ['wet_ink_file' => UploadedFile::fake()->image('s.jpg')])->assertStatus(409);
        $this->guest();
        $this->postJson(route('rental-inspections.sign.submit', $unusedLink->token), $this->signPayload(['typed_name' => 'Pieter van Wyk']))->assertStatus(409);
        $this->actingAs($this->admin);
        $this->postJson(route('corex.rental-inspections.signing-links.device-submit', [$i, $unusedLink]), $this->signPayload(['typed_name' => 'Pieter van Wyk']))->assertStatus(409);
        // links
        $this->postJson(route('corex.rental-inspections.signing-links.issue', $i), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant2->id])->assertStatus(409);
        $this->postJson(route('corex.rental-inspections.signing-links.email', [$i, $unusedLink]))->assertStatus(409);
        // attendance
        $this->postJson(route('corex.rental-inspections.attendance.store', $i), ['party_role' => 'tenant', 'party_contact_id' => $this->tenant->id, 'outcome' => 'did_not_attend'])->assertStatus(409);
        // status
        $this->postJson(route('corex.rental-inspections.start-awaiting-signature', $i))->assertStatus(409);
        $this->postJson(route('corex.rental-inspections.complete', $i))->assertStatus(409);

        $this->assertSame($liveBefore, $this->liveSignatures($i)->pluck('id')->sort()->values()->all());
        $this->assertSame($linksBefore, RentalInspectionSigningLink::count());
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $i->fresh()->status);
    }

    public function test_a_distributed_report_refuses_discrepancy_scan_and_photo_pairing_writes(): void
    {
        // An earlier, finished inspection on the tenancy holds the photo this one would be paired against.
        $earlier = RentalInspection::create(['agency_id' => $this->agency->id, 'lease_id' => $this->lease->id, 'type' => 'in', 'created_by_user_id' => $this->admin->id, 'status' => 'completed', 'completed_at' => now()->subDay()]);
        $earlierPhoto = $this->photoOn($earlier);
        $i = $this->ready(RentalInspection::TYPE_OUT);
        $obs = RentalInspectionObservation::where('rental_inspection_id', $i->id)->first();
        $photoA = $this->photoOn($i);
        $this->everyoneSigns($i);
        $this->complete($i);
        // rows that exist when someone tries to act on them after distribution
        $discrepancy = RentalInspectionDiscrepancy::forceCreate(['agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'rental_inspection_item_id' => $this->item->id, 'detected_at' => now()]);
        $scan = RentalInspectionScan::forceCreate([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $i->id, 'original_filename' => 's.pdf', 'storage_path' => 'x/s.pdf', 'mime_type' => 'application/pdf', 'status' => 'decoded',
        ]);

        $this->postJson(route('corex.rental-inspections.discrepancies.resolve', [$i, $discrepancy]), ['accepted_observation_id' => $obs->id])->assertStatus(409);
        $this->post(route('corex.rental-inspections.scans.apply', [$i, $scan]), ['condition_keys' => [$this->item->id => 'good']])->assertSessionHasErrors('scan');
        $this->postJson(route('corex.properties.rental-inspection-photo-matches.store', $this->property), ['photo_id' => $photoA->id, 'anchor_photo_id' => $earlierPhoto->id])->assertStatus(409);
        $this->postJson(route('corex.properties.rental-inspection-photo-matches.auto-pair', $this->property))->assertStatus(409);
        $this->assertNull($discrepancy->fresh()->resolved_at);
    }

    public function test_nobody_can_reopen_a_distributed_report_in_any_role(): void
    {
        $i = $this->distribute($this->ready());
        $liveBefore = $this->liveSignatures($i)->count();

        // the admin who ran it
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'It was wrong'])->assertStatus(409)
            ->assertJsonFragment(['message' => 'This report has been sent to the parties, so it can never be edited or reopened — by anyone. To correct it, start a new inspection that replaces it.']);

        // a user holding every inspection permission
        Role::firstOrCreate(['name' => 'agent', 'agency_id' => $this->agency->id], ['label' => 'Agent']);
        foreach (['rental_inspections.view', 'rental_inspections.create', 'rental_inspections.edit_details', 'rental_inspections.archive_completed', 'rental_inspections.resolve_discrepancy', 'access_properties'] as $key) {
            RolePermission::updateOrCreate(['role' => 'agent', 'permission_key' => $key, 'agency_id' => $this->agency->id], ['scope' => 'all']);
        }
        PermissionService::clearCache();
        $this->actingAs($this->inspector);
        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Manager override'])->assertStatus(409);

        // the service itself, called directly, refuses too — it is not only a controller rule
        try {
            app(\App\Services\Rentals\RentalInspectionReopenService::class)->reopen($i->fresh(), $this->admin, 'Direct call');
            $this->fail('A distributed report must never be reopened.');
        } catch (\App\Exceptions\RentalInspectionNotRecordableException $e) {
            $this->assertStringContainsString('never be edited or reopened', $e->getMessage());
        }

        $this->assertSame($liveBefore, $this->liveSignatures($i)->count());
        $this->assertSame(0, RentalInspectionReopen::count());
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $i->fresh()->status);
    }

    public function test_a_report_with_a_copy_already_emailed_cannot_be_reopened_even_if_not_marked_complete(): void
    {
        $i = $this->ready();
        $this->signByLink($i, 'tenant', $this->tenant, 'Naledi Dlamini');
        SignedDocumentDistributionLog::create([
            'agency_id' => $this->agency->id, 'distributable_type' => RentalInspection::class, 'distributable_id' => $i->id,
            'channel' => 'email', 'mode' => 'manual', 'recipient_role' => 'landlord', 'recipient_email' => 'pieter@example.co.za', 'status' => 'sent',
        ]);

        $this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'Late change'])->assertStatus(409);
        $this->assertFalse($i->fresh()->canBeReopened());
    }

    public function test_the_screens_say_it_plainly_and_offer_start_new_inspection(): void
    {
        $i = $this->distribute($this->ready());

        $this->get(route('corex.rental-inspections.show', $i))->assertOk()
            ->assertSee('data-qa="report-distributed"', false)->assertSee('can never be changed — by anyone')->assertSee('Start new inspection');
        $this->get(route('corex.properties.show', ['property' => $this->property->id, 'tab' => 'inspections']))->assertOk()
            ->assertSee('data-qa="report-distributed"', false)->assertSee('data-qa="start-new-inspection"', false);
        $lock = $this->getJson(route('corex.rental-inspections.signing-links.index', $i))->json('lock');
        $this->assertTrue($lock['distributed']);
        $this->assertFalse($lock['can_reopen']);
        $this->assertTrue($lock['can_replace']);
    }

    // ═══ Start new inspection — replaces / replaced by ═════════════════════════

    public function test_start_new_inspection_makes_a_linked_replacement_and_leaves_the_old_one_untouched(): void
    {
        $old = $this->distribute($this->ready(RentalInspection::TYPE_OUT));
        $before = [
            'status' => $old->status, 'completed_at' => $old->completed_at?->toIso8601String(), 'overall' => $old->overall_notes,
            'fingerprint' => $old->reportFingerprint(), 'signatures' => $this->liveSignatures($old)->pluck('id')->sort()->values()->all(),
            'public_token' => $old->public_token,
        ];

        $response = $this->postJson(route('corex.rental-inspections.replace', $old))->assertStatus(201);

        $new = RentalInspection::findOrFail($response->json('id'));
        $this->assertSame($this->property->id, $new->property_id);
        $this->assertSame($this->lease->id, $new->lease_id);
        $this->assertSame(RentalInspection::TYPE_OUT, $new->type, 'same type');
        $this->assertSame($old->id, $new->replaces_inspection_id);
        $this->assertSame($old->id, $new->previous_inspection_id, 'joins the chain after the old tail — nothing forks');
        $this->assertSame(RentalInspection::STATUS_DRAFT, $new->status);
        $this->assertSame($this->admin->id, $new->created_by_user_id);
        $this->assertSame($response->json('url'), route('corex.rental-inspections.show', $new));

        $old->refresh();
        $this->assertSame($before, [
            'status' => $old->status, 'completed_at' => $old->completed_at?->toIso8601String(), 'overall' => $old->overall_notes,
            'fingerprint' => $old->reportFingerprint(), 'signatures' => $this->liveSignatures($old)->pluck('id')->sort()->values()->all(),
            'public_token' => $old->public_token,
        ], 'the old inspection is not touched');

        // both histories say so
        $oldLog = RentalInspectionAuditLog::where('rental_inspection_id', $old->id)->where('event', 'replaced')->firstOrFail();
        $newLog = RentalInspectionAuditLog::where('rental_inspection_id', $new->id)->where('event', 'replaced')->firstOrFail();
        $this->assertStringContainsString("#{$new->id}", (string) $oldLog->summary);
        $this->assertStringContainsString("#{$old->id}", (string) $newLog->summary);

        // and both pages link to the other
        $this->get(route('corex.rental-inspections.show', $old))->assertSee(route('corex.rental-inspections.show', $new), false)->assertSee('Replaced by');
        $this->get(route('corex.rental-inspections.show', $new))->assertSee(route('corex.rental-inspections.show', $old), false);
        $lockOld = $this->getJson(route('corex.rental-inspections.signing-links.index', $old))->json('lock');
        $this->assertSame($new->id, $lockOld['replaced_by']['id']);
        $this->assertFalse($lockOld['can_replace'], 'already replaced');
        $this->assertSame($old->id, $this->getJson(route('corex.rental-inspections.signing-links.index', $new))->json('lock.replaces.id'));
    }

    public function test_the_replacement_is_a_normal_inspection_that_can_be_signed_and_the_old_one_cannot_be_replaced_twice(): void
    {
        $old = $this->distribute($this->ready(RentalInspection::TYPE_AD_HOC));
        $new = RentalInspection::findOrFail($this->postJson(route('corex.rental-inspections.replace', $old))->json('id'));
        $this->postJson(route('corex.rental-inspections.replace', $old))->assertStatus(409);

        RentalInspectionObservation::record([
            'agency_id' => $this->agency->id, 'rental_inspection_id' => $new->id, 'rental_inspection_item_id' => $this->item->id,
            'observed_by_user_id' => $this->admin->id, 'condition' => 'damaged', 'notes' => 'Corrected finding', 'source' => 'in_inspection',
        ]);
        $this->recordAttendanceForEveryParty($new);
        $this->readyToSign($new);
        $this->everyoneSigns($new);
        $this->complete($new);

        $this->assertSame(RentalInspection::STATUS_COMPLETED, $new->fresh()->status);
        $this->assertSame(RentalInspection::STATUS_COMPLETED, $old->fresh()->status);
    }

    public function test_start_new_inspection_is_refused_for_a_report_that_has_not_been_sent_and_while_another_is_open(): void
    {
        $open = $this->ready();
        $this->postJson(route('corex.rental-inspections.replace', $open))->assertStatus(409);
        $this->assertSame(0, RentalInspection::whereNotNull('replaces_inspection_id')->count());

        $old = $this->distribute($this->ready(RentalInspection::TYPE_AD_HOC));
        // an open inspection after it on the same tenancy: finish or cancel that first
        RentalInspection::startNext($old, RentalInspection::TYPE_OUT, $this->admin);
        $this->postJson(route('corex.rental-inspections.replace', $old))->assertStatus(409)->assertJsonFragment(['message' => 'Another inspection (#' . RentalInspection::where('previous_inspection_id', $old->id)->value('id') . ') is still open on this tenancy — finish or cancel it before starting the replacement.']);
    }

    public function test_another_agencys_user_cannot_replace_or_reopen(): void
    {
        $i = $this->distribute($this->ready());
        \Illuminate\Support\Facades\Auth::logout();
        $otherAgency = \App\Models\Agency::create(['name' => 'Other Agency', 'slug' => 'other-' . uniqid()]);
        $stranger = \App\Models\User::factory()->create(['agency_id' => $otherAgency->id, 'role' => 'admin']);
        $this->actingAs($stranger);

        $this->assertContains($this->postJson(route('corex.rental-inspections.replace', $i))->status(), [403, 404]);
        $this->assertContains($this->postJson(route('corex.rental-inspections.reopen', $i), ['reason' => 'No'])->status(), [403, 404]);
        $this->assertSame(0, RentalInspection::whereNotNull('replaces_inspection_id')->count());
    }
}
