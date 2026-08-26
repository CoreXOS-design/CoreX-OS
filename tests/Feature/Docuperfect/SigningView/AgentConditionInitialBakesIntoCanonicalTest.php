<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\SignatureController;
use App\Models\Docuperfect\ConditionInitial;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentCondition;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AT-373 BUG 1 — the AGENT's per-condition initial must render on the DOCUMENT BODY, exactly like a
 * recipient's. The agent-review page serves the STORED canonical verbatim (forDisplay, version >= 1), so an
 * OC initial only shows on the body if it has been BAKED into that canonical. The recipient's external
 * initialCondition endpoint bakes via refreshInsertableBlocks; the agent's INTERNAL endpoint did not — so the
 * agent's initial (present in signed_initials + a ConditionInitial row) never appeared on the body while the
 * recipient's, baked at signing, did. This test proves the internal endpoint now bakes the agent's initial
 * into the stored canonical too.
 */
final class AgentConditionInitialBakesIntoCanonicalTest extends TestCase
{
    use RefreshDatabase;

    private const PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';

    public function test_agent_internal_condition_initial_bakes_into_the_stored_canonical(): void
    {
        $uid = (int) DB::table('users')->insertGetId([
            'name' => 'Johan Reichel', 'email' => 'ar-' . Str::random(6) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Ar tmpl', 'render_type' => 'web', 'template_type' => 'cds', 'category' => 'sales',
            'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'], 'field_mappings' => [], 'owner_id' => $uid,
        ]);

        // A BAKED canonical (version 1) carrying an insertable-block region for the Other Conditions — the
        // shape forDisplay serves verbatim to the agent-review body. It already holds the SELLER's baked
        // initial; the AGENT's is what must appear after the endpoint.
        $canonical = '<div class="corex-document-wrapper"><p class="corex-clause">The fee is 7%.</p>'
            . '<div class="insertable-block" data-block-id="other_conditions" data-purpose="other_conditions">'
            . '<ol><li class="condition-row">Seller to leave the light fittings.</li></ol></div></div>';

        $doc = Document::create([
            'name' => 'EXCLUSIVE AUTHORITY TO SELL - bake test', 'document_type' => 'mandate',
            'owner_id' => $uid, 'template_id' => $docTmpl->id,
            'web_template_data' => [
                'merged_html'       => $canonical,
                'canonical_html'    => $canonical,
                'canonical_version' => 1,
                'signed_initials'   => ['seller' => ['condition_1' => self::PNG]],
            ],
        ]);
        $tpl = SignatureTemplate::create([
            'document_id' => $doc->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_AMENDMENT_CHAIN_REVIEW, 'created_by' => $uid,
            'parties_json' => [
                ['role' => 'agent', 'role_index' => 1, 'role_label' => 'agent'],
                ['role' => 'seller', 'role_index' => 1, 'role_label' => 'seller'],
            ],
        ]);
        $agentReq = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'agent', 'role_index' => 1,
            'signer_name' => 'Johan Reichel', 'signer_email' => 'a@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 1,
        ]);
        $sellerReq = SignatureRequest::create([
            'signature_template_id' => $tpl->id, 'party_role' => 'seller', 'role_index' => 1,
            'signer_name' => 'Anine Van der Westhuizen', 'signer_email' => 's@x.test', 'token' => Str::random(48),
            'token_expires_at' => now()->addDays(30), 'status' => 'completed', 'signing_order' => 2,
        ]);
        $condition = DocumentCondition::create([
            'signature_template_id' => $tpl->id, 'block_id' => 'other_conditions', 'block_purpose' => 'other_conditions',
            'condition_number' => 1, 'content' => 'Seller to leave the light fittings.',
            'added_by_party_id' => $sellerReq->id, 'added_via' => 'recipient_signing', 'source' => 'custom',
        ]);
        ConditionInitial::create([
            'initialable_type' => DocumentCondition::class, 'initialable_id' => $condition->id,
            'party_key' => 'seller', 'signature_request_id' => $sellerReq->id,
        ]);

        // Pre-condition: the stored canonical carries NO agent initial on the body yet.
        $before = (string) $doc->fresh()->web_template_data['canonical_html'];
        $this->assertStringNotContainsString('data-party-key="agent"', $before, 'agent initial is NOT yet on the body');

        // The agent initials the Other Condition via the INTERNAL agent-review endpoint.
        $request = \Illuminate\Http\Request::create(
            '/docuperfect/documents/' . $doc->id . '/signatures/condition/' . $condition->id . '/initial', 'POST',
            ['initial_image' => self::PNG]
        );
        $request->setUserResolver(fn () => User::find($uid));
        $resp = app(SignatureController::class)->initialCondition($request, $doc->fresh(), $condition->fresh());
        $this->assertSame(200, $resp->getStatusCode());

        // BUG 1 — the agent's initial is now BAKED into the stored canonical the review body serves verbatim.
        $after = (string) $doc->fresh()->web_template_data['canonical_html'];
        $this->assertStringContainsString('data-party-key="agent"', $after, 'the agent OC initial is baked onto the document body');
        $this->assertStringContainsString('data-party-key="seller"', $after, 'the seller initial is retained (not clobbered)');
    }
}
