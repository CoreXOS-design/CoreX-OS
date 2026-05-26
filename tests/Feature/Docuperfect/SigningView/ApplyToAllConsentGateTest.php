<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * E-sign reset Commit 1 — Apply-to-All consent gate is the FIRST commit
 * because it closes legal exposure with zero blast radius.
 *
 * The gate is `signingRequest.party_role === 'agent'` ALONE. The viewing
 * browser session's permissions are NOT consulted — a dispatching agent
 * who opens a recipient's link in their own browser must NOT inherit the
 * apply-to-all bypass.
 *
 * Assertions run against `$response->getContent()` (the actual HTML
 * returned to the browser). The Alpine state at sign.blade.php:1375
 * carries `isAgent: true|false` — that boolean is the testable signal.
 */
final class ApplyToAllConsentGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_recipient_token_does_not_grant_agent_bypass(): void
    {
        [, $seller] = $this->seedSession(sellerCount: 1);
        $response = $this->get('/sign/' . $seller->token);
        $response->assertOk();
        $this->assertStringContainsString('isAgent: false', $response->getContent());
        $this->assertStringNotContainsString('isAgent: true', $response->getContent());
    }

    public function test_agent_token_grants_agent_bypass(): void
    {
        [, , $agent] = $this->seedSession(sellerCount: 1, includeAgent: true);
        $response = $this->get('/sign/' . $agent->token);
        $response->assertOk();
        $this->assertStringContainsString('isAgent: true', $response->getContent());
    }

    /**
     * The legal-loophole regression test. A dispatching agent (authed user
     * with manage_documents permission) opening a RECIPIENT's signing
     * token MUST see the recipient's consent surface — they must NOT
     * inherit the apply-to-all bypass that belongs to the agent token.
     */
    public function test_dispatching_agent_viewing_recipient_link_does_not_inherit_bypass(): void
    {
        [, $seller] = $this->seedSession(sellerCount: 1);
        $dispatchingAgent = $this->seedDispatchingAgent();

        $response = $this
            ->actingAs($dispatchingAgent)
            ->get('/sign/' . $seller->token);

        $response->assertOk();
        // The recipient signing surface renders, gate stays false.
        $this->assertStringContainsString('isAgent: false', $response->getContent());
        $this->assertStringNotContainsString('isAgent: true', $response->getContent());
    }

    // ── Helpers ──

    /**
     * @return array{0: SignatureTemplate, 1: SignatureRequest, 2?: SignatureRequest}
     */
    private function seedSession(int $sellerCount = 1, bool $includeAgent = false): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Listing Agent', 'email' => 'la-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name' => 'Gate test',
            'render_type' => 'web',
            'template_type' => 'cds',
            'category' => 'sales',
            'signing_parties' => ['owner_party', 'agent'],
            'field_mappings' => [],
            'owner_id' => $userId,
        ]);
        $doc = Document::create([
            'name' => 'Doc',
            'document_type' => 'agreement',
            'owner_id' => $userId,
            'template_id' => $docTmpl->id,
            'web_template_data' => ['merged_html' => '<div>placeholder body</div>'],
        ]);
        $sigTmpl = SignatureTemplate::create([
            'document_id' => $doc->id,
            'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $userId,
        ]);
        $seller = SignatureRequest::create([
            'signature_template_id' => $sigTmpl->id,
            'party_role'   => 'seller',
            'role_index'   => 1,
            'signer_name'  => 'Seller One',
            'signer_email' => 'seller-one@x.test',
            'token'        => Str::random(48),
            'token_expires_at' => now()->addDays(30),
            'status'       => 'pending',
            'signing_order'=> 1,
        ]);
        $agent = null;
        if ($includeAgent) {
            $agent = SignatureRequest::create([
                'signature_template_id' => $sigTmpl->id,
                'party_role'   => 'agent',
                'role_index'   => 1,
                'signer_name'  => 'Agent One',
                'signer_email' => 'agent-one@x.test',
                'token'        => Str::random(48),
                'token_expires_at' => now()->addDays(30),
                'status'       => 'pending',
                'signing_order'=> 2,
            ]);
        }
        return $includeAgent ? [$sigTmpl, $seller, $agent] : [$sigTmpl, $seller];
    }

    private function seedDispatchingAgent(): User
    {
        // The real CoreX dispatch flow grants the agent the
        // `manage_documents` permission. We simulate the same shape so
        // the regression actually fires the previous bug if it returns.
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Dispatching Agent',
            'email' => 'dispatch-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'is_admin' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        return User::findOrFail($userId);
    }
}
