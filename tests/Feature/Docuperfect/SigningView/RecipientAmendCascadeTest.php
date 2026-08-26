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
 * AT-373 increment 4 — the SEQUENTIAL re-initial cascade (decision ii — LEGAL).
 *
 * After the approval chain approves a recipient's mid-flow edit, the ALREADY-SIGNED recipients must
 * re-initial the change ONE PARTY AT A TIME in signing_order — never the parallel broadcast the legacy
 * requeueAllPartiesForInitialing did. The invariant this test pins: exactly one party is active at any
 * moment; when the already-signed worklist is exhausted the normal walk resumes into the not-yet-reached
 * recipients (full signing).
 *
 * Fixture: agent(1, signed) · seller(2, already signed recipient) · buyer(3, editor at their turn) ·
 * tenant(4, not yet reached). Chain above the recipients = [agent] (m=1).
 */
final class RecipientAmendCascadeTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** @return array{tpl: SignatureTemplate, agent: User, buyerReq: SignatureRequest, changeId: string} */
    private function seedThreeRecipientEditedDoc(): array
    {
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is '
            . 'seven percent (7%) of the price.</p></div>';
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Cs Agency', 'slug' => 'cs-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Cs Branch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Cs Agent', 'email' => 'cs-' . Str::random(6) . '@x.test', 'branch_id' => $branchId,
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Cs tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller', 'buyer', 'tenant'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'Cs Doc', 'document_type' => 'mandate', 'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => $body],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_BUYER, 'created_by' => $uid,
        ]);
        $mk = function (string $role, int $order, string $status, string $name) use ($tpl) {
            return SignatureRequest::create([
                'signature_template_id' => $tpl->id, 'party_role' => $role, 'role_index' => 1,
                'signer_name' => $name, 'signer_email' => $role . '@x.test', 'token' => Str::random(48),
                'token_expires_at' => now()->addDays(30), 'status' => $status, 'signing_order' => $order,
            ]);
        };
        $mk('agent', 1, 'completed', 'Cs Agent');
        $mk('seller', 2, 'completed', 'Petro Nel');            // already-signed recipient
        $buyerReq = $mk('buyer', 3, 'pending', 'Ben Buyer');   // the editor, at their turn
        $mk('tenant', 4, 'waiting', 'Thabo Tenant');           // not yet reached

        // The buyer (editor) amends at their turn and initials their OWN slot.
        $edit = app(SelectionEditService::class)->strikeSelection(
            $tpl->fresh(), 'seven percent (7%)', 'The fee is ', ' of the price', 'six percent (6%)', null, 'inline'
        );
        $this->assertTrue($edit['ok'] ?? false, 'precondition: the edit authors');
        $changeId = $edit['change_id'];
        app(SignatureService::class)->recordChangeInitial($tpl->fresh(), $changeId, 'Ben Buyer', 'buyer', self::PNG);

        return ['tpl' => $tpl->fresh(), 'agent' => User::find($uid), 'buyerReq' => $buyerReq->fresh(), 'changeId' => $changeId];
    }

    /** How many recipient requests are currently active (PENDING) — the one-active invariant probe. */
    private function activeRecipients(SignatureTemplate $tpl): array
    {
        return $tpl->requests()
            ->whereIn('party_role', ['seller', 'buyer', 'tenant'])
            ->where('status', SignatureRequest::STATUS_PENDING)
            ->pluck('party_role')->sort()->values()->all();
    }

    public function test_cascade_activates_one_already_signed_recipient_then_resumes_walk(): void
    {
        Mail::fake();
        Notification::fake();
        ['tpl' => $tpl, 'agent' => $agent, 'buyerReq' => $buyerReq, 'changeId' => $changeId] = $this->seedThreeRecipientEditedDoc();
        $svc = app(SignatureService::class);

        // 1) Editor completes → chain review; agent (chain top) initials + approves.
        $svc->handlePartyCompletion($tpl->fresh(), 'buyer', $buyerReq);
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->fresh()->status);
        $svc->recordChangeInitial($tpl->fresh(), $changeId, 'Cs Agent', 'agent', self::PNG);
        $svc->approveAmendmentNode($tpl->fresh(), $agent);

        // 2) Chain approved → the SEQUENTIAL cascade activates ONLY the already-signed seller.
        $tpl->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_INITIALING, $tpl->status, 'cascade begins');
        $this->assertSame('recipient_cascade', $svc->amendmentCycle($tpl)['phase'] ?? null);
        $this->assertSame(['seller'], $this->activeRecipients($tpl),
            'exactly ONE party is active — the lowest-order already-signed recipient (seller)');
        // tenant (not yet reached) is untouched — still waiting, never broadcast to.
        $this->assertSame('waiting', $tpl->requests()->where('party_role', 'tenant')->value('status'));

        // 3) Seller initials the change and completes their initial-only turn.
        $svc->recordChangeInitial($tpl->fresh(), $changeId, 'Petro Nel', 'seller', self::PNG);
        $sellerReq = $tpl->requests()->where('party_role', 'seller')->first();
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);

        // 4) Worklist exhausted → cascade concludes, cycle cleared, the walk resumes into the
        //    not-yet-reached recipient (tenant) for full signing. Still exactly one active party.
        $tpl->refresh();
        $this->assertNull($svc->amendmentCycle($tpl), 'the cascade cycle is cleared');
        $this->assertSame(SignatureTemplate::STATUS_AWAITING_TENANT, $tpl->status,
            'the normal walk resumes into the not-yet-reached recipient');
        $this->assertSame(['tenant'], $this->activeRecipients($tpl), 'one active party — tenant now signs');
    }
}
