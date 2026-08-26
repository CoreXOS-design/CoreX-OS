<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\SelectionEditService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-373 increment 5 — editor RE-ACCEPTANCE after a reject (decision iii).
 *
 * When a chain node rejects a recipient's amendment, the change is reverted (inc6) and the editing
 * party must RE-ACCEPT the reverted document via a SECOND mandatory ECT-Act acknowledgment. Their
 * signature is preserved (a consent, not a re-sign); on re-acceptance the walk resumes from their
 * position. Both ticks are mandatory — server-enforced.
 */
final class EditorReacceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** Seed a doc where the sole recipient edited + was chain-REJECTED → editor_reacceptance. */
    private function seedRejectedEditorDoc(): SignatureTemplate
    {
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is '
            . 'seven percent (7%) of the price.</p></div>';
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Rx Agency', 'slug' => 'rx-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Rx Branch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Rx Agent', 'email' => 'rx-' . Str::random(6) . '@x.test', 'branch_id' => $branchId,
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Rx tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Rx Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $body],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SELLER, 'created_by' => $uid,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Rx Agent', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        $sellerReq = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Petro Nel', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);

        $svc = app(SignatureService::class);
        $edit = app(SelectionEditService::class)->strikeSelection(
            $tpl->fresh(), 'seven percent (7%)', 'The fee is ', ' of the price', 'six percent (6%)', null, 'inline'
        );
        $svc->recordChangeInitial($tpl->fresh(), $edit['change_id'], 'Petro Nel', 'seller', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);
        // The chain top (agent) REJECTS the amendment.
        $svc->rejectAmendmentNode($tpl->fresh(), User::find($uid), 'Fee stays at 7%.');

        return $tpl->fresh();
    }

    public function test_reject_reactivates_editor_for_reacceptance(): void
    {
        Mail::fake();
        Notification::fake();
        $tpl = $this->seedRejectedEditorDoc();

        $this->assertSame(SignatureTemplate::STATUS_EDITOR_REACCEPTANCE, $tpl->status);
        $seller = $tpl->requests()->where('party_role', 'seller')->first();
        $this->assertSame(SignatureRequest::STATUS_PENDING, $seller->status,
            'the editor is reactivated so they can re-accept the reverted document');
        $cycle = $tpl->document->fresh()->web_template_data['amendment_cycle'] ?? null;
        $this->assertSame('rejected', $cycle['phase'] ?? null);
        $this->assertSame($seller->id, $cycle['editor_request_id'] ?? null);
    }

    public function test_reaccept_requires_both_ticks(): void
    {
        Mail::fake();
        Notification::fake();
        $tpl = $this->seedRejectedEditorDoc();
        $token = $tpl->requests()->where('party_role', 'seller')->value('token');

        // Only one tick → rejected by the server; the document stays in re-acceptance.
        $this->post(route('signatures.external.reaccept', $token), ['ect_act_ack' => '1'])
            ->assertSessionHasErrors('amendment_removed_ack');
        $this->assertSame(SignatureTemplate::STATUS_EDITOR_REACCEPTANCE, $tpl->fresh()->status);

        // Neither tick → both flagged.
        $this->post(route('signatures.external.reaccept', $token), [])
            ->assertSessionHasErrors(['ect_act_ack', 'amendment_removed_ack']);
        $this->assertSame(SignatureTemplate::STATUS_EDITOR_REACCEPTANCE, $tpl->fresh()->status);
    }

    public function test_reaccept_with_both_ticks_resumes_the_walk(): void
    {
        Mail::fake();
        Notification::fake();
        $tpl = $this->seedRejectedEditorDoc();
        $token = $tpl->requests()->where('party_role', 'seller')->value('token');

        $this->post(route('signatures.external.reaccept', $token), [
            'ect_act_ack' => '1', 'amendment_removed_ack' => '1',
        ])->assertRedirect(route('signatures.external.completed', $token));

        $tpl->refresh();
        // The editor is COMPLETED again (their signature never left), the cycle is cleared, and the
        // walk resumes — the sole recipient is done, so the AT-322 final gate holds the document.
        $this->assertSame(SignatureRequest::STATUS_COMPLETED,
            $tpl->requests()->where('party_role', 'seller')->value('status'));
        $this->assertArrayNotHasKey('amendment_cycle', $tpl->document->fresh()->web_template_data ?? []);
        $this->assertSame(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL, $tpl->status);
    }
}
