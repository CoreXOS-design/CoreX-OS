<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SignatureController;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-387-label (Johan 2026-08-30) — the review page's Approve button label
 * computed "next party" (SignatureController.php:2690-2696) by walking the
 * signing order and taking the first party not yet in $completedParties,
 * without checking isSigningParticipant(). A deceased party
 * (STATUS_NOT_REQUIRED) is never "completed" either — they were exempted —
 * so at the terminal step the label read "Approve & Send to [deceased
 * name]" even though SignatureService::advanceToNextSigningParticipant()
 * (the code that ACTUALLY routes the document) has always correctly skipped
 * them. Wording-only: the document already advanced/finalised correctly;
 * only the label lied about it.
 *
 * Fix mirrors the real advance logic: a party at STATUS_NOT_REQUIRED is
 * excluded from "next party" candidacy the same way a completed one is. When
 * no real party remains, $nextParty is null and the EXISTING blade
 * conditional (review.blade.php:570-574) already falls back to "Approve &
 * Finalise" — no new wording introduced.
 */
final class ApproveButtonLabelSkipsNonParticipantsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{agent:User, document:Document, template:SignatureTemplate} */
    private function template(string $templateStatus): array
    {
        $agencyId = (int) Agency::create(['name' => 'ZZZ Label Test Agency ' . Str::random(6), 'slug' => 'zzz-label-' . Str::random(8)])->id;
        $branchId = (int) Branch::create(['agency_id' => $agencyId, 'name' => 'ZZZ Label Test Branch'])->id;
        $agent = User::factory()->create([
            'name' => 'ZZZ Label Test Agent', 'role' => 'agent',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'is_active' => true,
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'ZZZ Label Test Template', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'],
            'field_mappings' => [], 'owner_id' => $agent->id, 'agency_id' => $agencyId,
        ]);
        $document = Document::create([
            'name' => 'ZZZ Label Test Doc', 'document_type' => 'mandate', 'agency_id' => $agencyId,
            'owner_id' => $agent->id, 'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div class="corex-document-wrapper"><p>Body</p></div>'],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64), 'agency_id' => $agencyId,
            'status' => $templateStatus, 'created_by' => $agent->id,
            // canonicalPartyKey() only suffixes role_index > 1 — the first
            // instance of a role is the bare role name ("seller", not "seller_1").
            'signing_order_json' => ['agent', 'seller', 'seller_2'],
        ]);
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => $agent->name, 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_COMPLETED, 'signing_order' => 1,
        ]);

        return ['agent' => $agent, 'document' => $document, 'template' => $template];
    }

    public function test_terminal_step_with_deceased_party_reads_as_finalise(): void
    {
        ['agent' => $agent, 'document' => $document, 'template' => $template] = $this->template(SignatureTemplate::STATUS_PENDING_AGENT_APPROVAL);

        // Seller 1 deceased — exempted, never contacted. Seller 2 genuinely completed.
        // No REAL party remains: the label must read as a finalise action.
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'ZZZ Deceased Seller', 'signer_email' => 's1@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_NOT_REQUIRED,
            'signing_order' => 2, 'is_deceased' => true,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'seller', 'role_index' => 2,
            'signer_name' => 'ZZZ Real Seller Two', 'signer_email' => 's2@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_COMPLETED, 'signing_order' => 3,
        ]);

        $this->withoutVite();
        $request = \Illuminate\Http\Request::create('/docuperfect/documents/' . $document->id . '/signatures/review', 'GET');
        $request->setUserResolver(fn () => $agent);
        $view = app(SignatureController::class)->review($request, $document);
        $data = $view->getData();

        $this->assertArrayHasKey('nextParty', $data);
        $this->assertNull($data['nextParty'], 'no real party remains — nextParty must be null, not the deceased seller');

        $html = $view->render();
        $this->assertStringContainsString('Approve &amp; Finalise', $html, 'label reads as finalise when only a deceased party remains');
        $this->assertStringNotContainsString('Approve &amp; Send to ZZZ Deceased Seller', $html, 'never offers to send to the deceased party');
    }

    public function test_mid_chain_step_still_names_the_correct_next_real_party(): void
    {
        ['agent' => $agent, 'document' => $document, 'template' => $template] = $this->template(SignatureTemplate::STATUS_AWAITING_SUPERVISOR);

        // Seller 1 deceased (skipped), seller 2 genuinely still waiting — the REAL next party.
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'ZZZ Deceased Seller', 'signer_email' => 's1@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_NOT_REQUIRED,
            'signing_order' => 2, 'is_deceased' => true,
        ]);
        SignatureRequest::create([
            'signature_template_id' => $template->id, 'party_role' => 'seller', 'role_index' => 2,
            'signer_name' => 'ZZZ Real Seller Two', 'signer_email' => 's2@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => SignatureRequest::STATUS_WAITING, 'signing_order' => 3,
        ]);

        $this->withoutVite();
        $request = \Illuminate\Http\Request::create('/docuperfect/documents/' . $document->id . '/signatures/review', 'GET');
        $request->setUserResolver(fn () => $agent);
        $view = app(SignatureController::class)->review($request, $document);
        $data = $view->getData();

        $this->assertArrayHasKey('nextParty', $data);
        $this->assertSame('seller_2', $data['nextParty'], 'the deceased seller_1 (bare "seller" key) is skipped; the real seller_2 is correctly identified as next');

        // Label resolution (progress lookup -> display name) is pre-existing,
        // unrelated machinery this fix doesn't touch — confirm the button still
        // renders a real "send to" label (not "Finalise"), matching the correct
        // nextParty value asserted above.
        $html = $view->render();
        $this->assertStringContainsString('Approve &amp; Send to', $html, 'a real next party still gets a send-to label, unchanged behaviour');
        $this->assertStringNotContainsString('Approve &amp; Finalise', $html, 'must not read as finalise while a real party is still outstanding');
    }
}
