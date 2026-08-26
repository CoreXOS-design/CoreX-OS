<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\SelectionEditService;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-373 (Issue C surfacing) — a recipient's amendment RETURNED to the agent for approval
 * (amendment_chain_review) must appear in an ACTIONABLE bucket on the agent's My E-Sign Documents.
 *
 * The reported gap (Johan, doc 726): routing correctly held the doc for the agent, but the state was
 * in NO dashboard bucket, so the agent had no entry point — the ceremony sat invisible and stuck.
 */
final class AmendmentApprovalSurfacedTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    /** Drive a recipient amendment to amendment_chain_review; return [agent, template]. */
    private function seedReturnedToAgent(): array
    {
        Mail::fake();
        Notification::fake();
        $body = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is '
            . 'seven percent (7%) of the price.</p></div>';
        $agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Sc Agency', 'slug' => 'sc-' . Str::random(6), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $agencyId, 'name' => 'Sc Branch', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Johan Reichel', 'email' => 'sc-' . Str::random(6) . '@x.test', 'branch_id' => $branchId,
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Sc tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);
        $doc = Document::create([
            'name' => 'EXCLUSIVE AUTHORITY TO SELL - surfacing test', 'document_type' => 'mandate',
            'owner_id' => $uid, 'template_id' => $docTmpl->id, 'web_template_data' => ['merged_html' => $body],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AWAITING_SELLER, 'created_by' => $uid,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Johan Reichel', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        $sellerReq = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Anine Van der Westhuizen', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'pending', 'signing_order' => 2,
        ]);

        $svc = app(SignatureService::class);
        $edit = app(SelectionEditService::class)->strikeSelection(
            $tpl->fresh(), 'seven percent (7%)', 'The fee is ', ' of the price', 'six percent (6%)', null, 'inline'
        );
        $svc->recordChangeInitial($tpl->fresh(), $edit['change_id'], 'Anine Van der Westhuizen', 'seller', self::PNG);
        $svc->handlePartyCompletion($tpl->fresh(), 'seller', $sellerReq);

        $tpl->refresh();
        $this->assertSame(SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, $tpl->status, 'precondition: returned to agent');
        return [User::find($uid), $tpl];
    }

    public function test_amended_doc_appears_in_the_agents_amendment_approval_bucket(): void
    {
        [$agent, $tpl] = $this->seedReturnedToAgent();

        $request = Request::create('/docuperfect/esign/my-documents', 'GET');
        $request->setUserResolver(fn () => $agent);
        $view = app(ESignWizardController::class)->myDocuments($request);
        $data = $view->getData();

        $this->assertArrayHasKey('amendment_approval', $data['groups'], 'the bucket exists');
        $this->assertTrue(
            $data['groups']['amendment_approval']->contains(fn ($t) => (int) $t->id === (int) $tpl->id),
            'the returned-to-agent document appears in the amendment_approval bucket'
        );
        $this->assertSame(1, $data['counts']['amendment_approval'], 'the dashboard tile counts it');

        // And it is NOT lost into some other bucket / invisible.
        $this->assertFalse($data['groups']['awaiting']->contains(fn ($t) => (int) $t->id === (int) $tpl->id));
    }

    public function test_agent_gets_an_email_when_the_amendment_returns(): void
    {
        // seedReturnedToAgent runs handlePartyCompletion under Mail::fake — assert the return email fired.
        [$agent] = $this->seedReturnedToAgent();
        Mail::assertSent(\App\Mail\Signatures\PartySignedNotificationMail::class, function ($mail) use ($agent) {
            return $mail->hasTo($agent->email);
        });
    }
}
