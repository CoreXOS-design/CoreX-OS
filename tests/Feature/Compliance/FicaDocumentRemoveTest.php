<?php

declare(strict_types=1);

namespace Tests\Feature\Compliance;

use App\Models\Compliance\FicaOfficerAppointment;
use App\Models\Contact;
use App\Models\Document;
use App\Models\FicaSubmission;
use App\Models\Role;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Live bug, 28 Oct 2026 (/corex/compliance/fica/9908): an agent attached the wrong documents to an
 * ONLINE FICA and had no way to take them off. The remove / unlink actions existed on the server,
 * but since the 6 Oct questions-and-answers rewrite the buttons were only drawn for paper (wet-ink)
 * intakes.
 *
 * Proves: on an online FICA still under review the requesting agent sees Remove next to every
 * uploaded document (question rows AND "other documents") and Unlink on linked contact documents;
 * Remove archives (soft delete) and Unlink only drops the link — the contact's document stays; both
 * write a fica_status_history row with who and which file; nothing is offered or allowed once the
 * agent has signed off or the record is approved; the CO screen stays read-only; another branch and
 * another agency are refused and nothing changes.
 */
final class FicaDocumentRemoveTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchA;
    private int $branchB;
    private User $requester;     // branch manager, branch A — sent the FICA
    private User $branchMgrB;    // branch manager, branch B — outside the record's branch
    private User $primaryCo;     // appointed primary compliance officer

    protected function setUp(): void
    {
        parent::setUp();

        // Real role grants in the production posture (same pattern as FicaQuestionsAnswersReviewTest).
        $now = now();
        foreach ([['super_admin', 'System Owner', 1, 1], ['admin', 'Administrator', 0, 2], ['branch_manager', 'Branch Manager', 0, 3], ['agent', 'Agent', 0, 4]] as [$name, $label, $owner, $sort]) {
            DB::table('roles')->insert(['name' => $name, 'label' => $label, 'is_owner' => $owner, 'can_be_deleted' => 0, 'sort_order' => $sort,
                'agency_id' => null, 'created_at' => $now, 'updated_at' => $now]);
        }
        Artisan::call('corex:sync-permissions', ['--seed-defaults' => true]);
        Role::clearCache();
        PermissionService::clearCache();
        PermissionService::forceProductionPosture();

        $this->agencyId = $this->agency();
        $this->branchA = (int) DB::table('branches')->insertGetId(['agency_id' => $this->agencyId, 'name' => 'A', 'created_at' => $now, 'updated_at' => $now]);
        $this->branchB = (int) DB::table('branches')->insertGetId(['agency_id' => $this->agencyId, 'name' => 'B', 'created_at' => $now, 'updated_at' => $now]);

        $this->requester  = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA, 'role' => 'branch_manager']);
        $this->branchMgrB = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchB, 'role' => 'branch_manager']);
        $this->primaryCo  = User::factory()->create(['agency_id' => $this->agencyId, 'branch_id' => $this->branchA, 'role' => 'super_admin']);

        FicaOfficerAppointment::create([
            'agency_id' => $this->agencyId, 'branch_id' => null, 'user_id' => $this->primaryCo->id, 'role' => FicaOfficerAppointment::ROLE_PRIMARY,
            'full_name' => $this->primaryCo->name, 'appointed_on' => now()->toDateString(), 'appointed_by' => $this->primaryCo->id,
        ]);
    }

    private function agency(): int
    {
        return (int) DB::table('agencies')->insertGetId([
            'name' => 'FICA Remove ' . Str::random(5), 'slug' => 'fica-remove-' . Str::random(8),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function submission(string $status = 'submitted'): FicaSubmission
    {
        $contact = Contact::withoutEvents(fn () => Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchA,
            'first_name' => 'Ayanda', 'last_name' => 'Dlamini', 'created_by_user_id' => $this->requester->id,
        ]));

        return FicaSubmission::withoutEvents(fn () => FicaSubmission::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchA, 'contact_id' => $contact->id,
            'requested_by' => $this->requester->id, 'token' => Str::random(40), 'token_expires_at' => now()->addDays(30),
            'entity_type' => 'natural', 'status' => $status, 'intake_type' => 'online', 'signed_at' => now()->subDay(),
            'form_data' => ['entity_type' => 'natural', 'personal' => ['full_name' => 'Ayanda Dlamini']],
        ]));
    }

    private function document(FicaSubmission $s, string $type, string $name): int
    {
        return (int) DB::table('fica_documents')->insertGetId([
            'fica_submission_id' => $s->id, 'agency_id' => $this->agencyId, 'document_type' => $type,
            'file_path' => 'fica/' . $s->id . '/' . Str::random(8), 'file_name' => $name, 'file_size' => 2048,
            'mime_type' => 'application/pdf', 'status' => 'uploaded', 'uploaded_at' => now(),
            'uploaded_by' => $this->requester->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function linkedDocument(FicaSubmission $s, string $name): Document
    {
        $doc = Document::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchA, 'original_name' => $name,
            'storage_path' => 'contacts/' . Str::random(8) . '.pdf', 'disk' => 'local', 'mime_type' => 'application/pdf', 'size' => 1024,
            'uploaded_by' => $this->requester->id,
        ]);
        DB::table('fica_submission_documents')->insert([
            'fica_submission_id' => $s->id, 'document_id' => $doc->id, 'document_type' => 'id_copy',
            'linked_by' => $this->requester->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $doc;
    }

    private function removeUrl(FicaSubmission $s, int $docId): string
    {
        return route('compliance.fica.documents.remove', [$s, $docId]);
    }

    // ── The button is there while the review is open ────────────────────

    public function test_requester_sees_remove_on_every_document_of_an_online_fica_under_review(): void
    {
        $s = $this->submission();
        $idCopy = $this->document($s, 'id_copy', 'Wrong person ID.pdf');           // sits on its question row
        $form   = $this->document($s, 'fica_form', 'Someone else FICA form.pdf');   // "other documents" or its row
        $linked = $this->linkedDocument($s, 'Linked proof.pdf');

        $html = $this->actingAs($this->requester)->get(route('compliance.fica.show', $s))->assertOk()->getContent();

        $this->assertStringContainsString($this->removeUrl($s, $idCopy), $html);
        $this->assertStringContainsString($this->removeUrl($s, $form), $html);
        $this->assertStringContainsString(route('compliance.fica.linked-documents.unlink', [$s, $linked]), $html);
    }

    public function test_no_remove_once_the_agent_has_signed_off_or_the_record_is_approved(): void
    {
        foreach (['agent_approved', 'approved'] as $status) {
            $s = $this->submission($status);
            $doc = $this->document($s, 'id_copy', 'ID.pdf');

            $html = $this->actingAs($this->requester)->get(route('compliance.fica.show', $s))->assertOk()->getContent();
            $this->assertStringNotContainsString($this->removeUrl($s, $doc), $html, $status);

            $this->actingAs($this->requester)->post($this->removeUrl($s, $doc))->assertStatus(400);
            $this->assertDatabaseHas('fica_documents', ['id' => $doc, 'deleted_at' => null]);
            $this->assertSame(0, DB::table('fica_status_history')->where('fica_submission_id', $s->id)->count(), $status);
        }
    }

    public function test_the_compliance_officer_screen_stays_read_only(): void
    {
        $s = $this->submission('submitted');
        $doc = $this->document($s, 'id_copy', 'ID.pdf');

        $html = $this->actingAs($this->primaryCo)->get(route('compliance.fica.compliance-review', $s))->getContent();
        $this->assertStringNotContainsString($this->removeUrl($s, $doc), $html);
    }

    // ── What Remove / Unlink do ─────────────────────────────────────────

    public function test_remove_archives_the_document_and_writes_an_audit_row(): void
    {
        $s = $this->submission('under_review');
        $doc = $this->document($s, 'id_copy', 'Wrong person ID.pdf');
        $keep = $this->document($s, 'proof_of_address', 'Right proof.pdf');

        $this->actingAs($this->requester)->post($this->removeUrl($s, $doc))->assertRedirect();

        $this->assertSoftDeleted('fica_documents', ['id' => $doc]);
        $this->assertDatabaseHas('fica_documents', ['id' => $keep, 'deleted_at' => null]);
        $this->assertSame('under_review', $s->fresh()->status);

        $row = DB::table('fica_status_history')->where('fica_submission_id', $s->id)->sole();
        $this->assertSame('document_removed', $row->action);
        $this->assertSame((int) $this->requester->id, (int) $row->actor_user_id);
        $this->assertSame('under_review', $row->from_status);
        $this->assertSame('under_review', $row->to_status);
        $meta = json_decode((string) $row->meta, true);
        $this->assertSame($doc, (int) $meta['fica_document_id']);
        $this->assertSame('id_copy', $meta['document_type']);
        $this->assertSame('Wrong person ID.pdf', $meta['file_name']);

        $html = $this->actingAs($this->requester)->get(route('compliance.fica.show', $s))->assertOk()->getContent();
        // Gone from the review itself; it now appears only under "Removed documents" (with its Restore button).
        [$review, $archived] = array_pad(explode('data-fica-archived-documents', $html, 2), 2, '');
        $this->assertStringNotContainsString('Wrong person ID.pdf', $review);
        $this->assertStringContainsString('Wrong person ID.pdf', $archived);
        $this->assertStringContainsString('Right proof.pdf', $review);
    }

    public function test_unlink_drops_only_the_link_keeps_the_contact_document_and_writes_an_audit_row(): void
    {
        $s = $this->submission('corrections_requested');
        $doc = $this->linkedDocument($s, 'Linked wrong ID.pdf');

        $this->actingAs($this->requester)->post(route('compliance.fica.linked-documents.unlink', [$s, $doc]))->assertRedirect();

        $this->assertDatabaseMissing('fica_submission_documents', ['fica_submission_id' => $s->id, 'document_id' => $doc->id]);
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'deleted_at' => null]);

        $row = DB::table('fica_status_history')->where('fica_submission_id', $s->id)->sole();
        $this->assertSame('document_unlinked', $row->action);
        $this->assertSame((int) $this->requester->id, (int) $row->actor_user_id);
        $meta = json_decode((string) $row->meta, true);
        $this->assertSame($doc->id, (int) $meta['document_id']);
        $this->assertSame('id_copy', $meta['document_type']);
        $this->assertSame('Linked wrong ID.pdf', $meta['file_name']);
    }

    // ── Restore ─────────────────────────────────────────────────────────

    public function test_a_removed_document_is_listed_and_can_be_restored_with_an_audit_row(): void
    {
        $s = $this->submission('under_review');
        $doc = $this->document($s, 'id_copy', 'Wrong person ID.pdf');
        $this->actingAs($this->requester)->post($this->removeUrl($s, $doc))->assertRedirect();

        $restoreUrl = route('compliance.fica.documents.restore', [$s, $doc]);
        $html = $this->actingAs($this->requester)->get(route('compliance.fica.show', $s))->assertOk()->getContent();
        $this->assertStringContainsString('Removed documents', $html);
        $this->assertStringContainsString($restoreUrl, $html);

        $this->actingAs($this->requester)->post($restoreUrl)->assertRedirect();

        $this->assertDatabaseHas('fica_documents', ['id' => $doc, 'deleted_at' => null]);
        $this->assertSame('under_review', $s->fresh()->status);
        $row = DB::table('fica_status_history')->where('fica_submission_id', $s->id)->where('action', 'document_restored')->sole();
        $this->assertSame((int) $this->requester->id, (int) $row->actor_user_id);
        $meta = json_decode((string) $row->meta, true);
        $this->assertSame($doc, (int) $meta['fica_document_id']);
        $this->assertSame('Wrong person ID.pdf', $meta['file_name']);

        $html = $this->actingAs($this->requester)->get(route('compliance.fica.show', $s))->assertOk()->getContent();
        $this->assertStringNotContainsString($restoreUrl, $html);
        $this->assertStringContainsString('Wrong person ID.pdf', $html);
    }

    public function test_restore_is_refused_once_the_stage_has_passed_and_for_a_live_document(): void
    {
        $s = $this->submission('under_review');
        $doc = $this->document($s, 'id_copy', 'ID.pdf');
        $live = $this->document($s, 'proof_of_address', 'Live.pdf');
        $this->actingAs($this->requester)->post($this->removeUrl($s, $doc))->assertRedirect();

        // A document that is not archived cannot be "restored".
        $this->actingAs($this->requester)->post(route('compliance.fica.documents.restore', [$s, $live]))->assertNotFound();

        $s->forceFill(['status' => 'agent_approved'])->save();
        $this->actingAs($this->requester)->post(route('compliance.fica.documents.restore', [$s, $doc]))->assertStatus(400);
        $this->assertSoftDeleted('fica_documents', ['id' => $doc]);
        $this->assertSame(1, DB::table('fica_status_history')->where('fica_submission_id', $s->id)->count(), 'only the removal is on the ledger');
    }

    public function test_restore_is_refused_for_another_branch_and_another_agency(): void
    {
        $s = $this->submission();
        $doc = $this->document($s, 'id_copy', 'ID.pdf');
        // Created BEFORE anyone is signed in: the agency trait stamps a new user into the signed-in user's agency.
        $outsider = User::factory()->create(['agency_id' => $this->agency(), 'role' => 'branch_manager']);
        $this->actingAs($this->requester)->post($this->removeUrl($s, $doc))->assertRedirect();

        foreach ([$this->branchMgrB, $outsider] as $user) {
            // Refused by the scope check (403) or, for another agency, by the agency scope on the route binding (404).
            $this->flushSession();
            $res = $this->actingAs($user)->post(route('compliance.fica.documents.restore', [$s, $doc]));
            $this->assertContains($res->status(), [403, 404]);
        }

        $this->assertSoftDeleted('fica_documents', ['id' => $doc]);
        $this->assertSame(0, DB::table('fica_status_history')->where('fica_submission_id', $s->id)->where('action', 'document_restored')->count());
    }

    // ── Scope ───────────────────────────────────────────────────────────

    public function test_another_branch_and_another_agency_are_refused_and_nothing_changes(): void
    {
        $s = $this->submission();
        $doc = $this->document($s, 'id_copy', 'ID.pdf');
        $linked = $this->linkedDocument($s, 'Linked.pdf');

        $otherAgency = $this->agency();
        $outsider = User::factory()->create(['agency_id' => $otherAgency, 'role' => 'branch_manager']);

        // Refused either by the scope check (403) or, for another agency, by the agency scope on the
        // route binding (404) — never a redirect back with "removed".
        foreach ([$this->branchMgrB, $outsider] as $user) {
            $this->assertContains($this->actingAs($user)->post($this->removeUrl($s, $doc))->status(), [403, 404]);
            $this->assertContains($this->actingAs($user)->post(route('compliance.fica.linked-documents.unlink', [$s, $linked]))->status(), [403, 404]);
        }

        $this->assertDatabaseHas('fica_documents', ['id' => $doc, 'deleted_at' => null]);
        $this->assertDatabaseHas('fica_submission_documents', ['fica_submission_id' => $s->id, 'document_id' => $linked->id]);
        $this->assertSame(0, DB::table('fica_status_history')->where('fica_submission_id', $s->id)->count());
    }
}
