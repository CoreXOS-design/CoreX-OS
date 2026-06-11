<?php

declare(strict_types=1);

namespace Tests\Feature\RecipientLoop;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * B3 — Per-recipient editable scope at the save endpoint.
 * Seller 1's token cannot persist values for Seller 2's fields. The
 * server is the security gate; DOM trust is never the security layer.
 */
final class EditableScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_seller_1_can_save_their_own_field_value(): void
    {
        [$tmpl, $seller1, $seller2] = $this->seedTwoSellerTemplate();

        $resp = $this->postJson('/sign/' . $seller1->token . '/save-web-fields', [
            'fields' => [
                'seller_address__r1' => [
                    'value'          => '1 Apple Ave',
                    'identity'       => 'seller_1',
                    'original_field' => 'seller_address',
                ],
            ],
        ]);

        $resp->assertOk();
        $resp->assertJson(['ok' => true, 'saved' => 1]);

        $document = $tmpl->document->fresh();
        // ES-5 (dd1533c2): signing-time edits persist in the identity-keyed
        // signing_field_values store under the rendered key, not a flat top-level key.
        $this->assertSame('1 Apple Ave', $document->web_template_data['signing_field_values']['seller_address__r1'] ?? null);
    }

    public function test_seller_1_token_writing_seller_2_field_is_403_and_audited(): void
    {
        [$tmpl, $seller1, $seller2] = $this->seedTwoSellerTemplate();

        $resp = $this->postJson('/sign/' . $seller1->token . '/save-web-fields', [
            'fields' => [
                'seller_address__r2' => [
                    'value'          => 'Steven\'s sneaky address',
                    'identity'       => 'seller_2',     // not seller_1's identity
                    'original_field' => 'seller_address',
                ],
            ],
        ]);

        $resp->assertStatus(403);
        $resp->assertJson(['ok' => false]);

        // No write should have landed.
        $document = $tmpl->document->fresh();
        $this->assertSame('', $document->web_template_data['seller_address'] ?? '');

        // Audit row recorded with the actor and the denied identity.
        $audit = SignatureAuditLog::where('signature_template_id', $tmpl->id)
            ->where('action', 'web_fields_save_denied')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame('seller_1', $audit->metadata_json['actor_role_identity'] ?? null);
        $this->assertSame('seller_2', $audit->metadata_json['denied_identity'] ?? null);
    }

    public function test_seller_2_can_save_their_own_field_value(): void
    {
        [$tmpl, $seller1, $seller2] = $this->seedTwoSellerTemplate();

        $resp = $this->postJson('/sign/' . $seller2->token . '/save-web-fields', [
            'fields' => [
                'seller_address__r2' => [
                    'value'          => '2 Steve Lane',
                    'identity'       => 'seller_2',
                    'original_field' => 'seller_address',
                ],
            ],
        ]);

        $resp->assertOk();
        $document = $tmpl->document->fresh();
        $this->assertSame('2 Steve Lane', $document->web_template_data['signing_field_values']['seller_address__r2'] ?? null);
    }

    public function test_agent_can_save_any_seller_field(): void
    {
        [$tmpl, $seller1, $seller2] = $this->seedTwoSellerTemplate();
        $agent = $this->seedSignatureRequest($tmpl, 'agent', 1, 'Alice Agent');

        // Field's editable_by allows 'agent', so agent saves OK regardless
        // of which seller's identity the payload claims.
        $resp = $this->postJson('/sign/' . $agent->token . '/save-web-fields', [
            'fields' => [
                'seller_address__r1' => [
                    'value'          => 'agent-filled',
                    'identity'       => 'seller_1',
                    'original_field' => 'seller_address',
                ],
            ],
        ]);

        $resp->assertOk();
    }

    public function test_legacy_flat_payload_still_accepted_for_role_match(): void
    {
        // Legacy clients (pre-B3) sent { field_name: "value" } without
        // an identity wrapper. The endpoint must still accept those for
        // single-recipient documents — backward compat.
        [$tmpl, $seller1] = $this->seedTwoSellerTemplate();

        $resp = $this->postJson('/sign/' . $seller1->token . '/save-web-fields', [
            'fields' => ['seller_address' => 'legacy-flat-value'],
        ]);

        $resp->assertOk();
        $document = $tmpl->document->fresh();
        // Legacy flat payload persists under the bare (un-mangled) key in the store.
        $this->assertSame('legacy-flat-value', $document->web_template_data['signing_field_values']['seller_address'] ?? null);
    }

    // ── Helpers ──

    /**
     * Seed a SignatureTemplate whose field_mappings carry an "editable_by"
     * for a single seller_address field. Returns [template, seller1Req,
     * seller2Req].
     *
     * @return array{0: SignatureTemplate, 1: SignatureRequest, 2: SignatureRequest}
     */
    private function seedTwoSellerTemplate(): array
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name' => 'Owner', 'email' => 'owner-' . Str::random(8) . '@x.test',
            'password' => bcrypt('p'), 'role' => 'agent',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $docTmpl = DocuperfectTemplate::create([
            'name'           => 'Two-seller test',
            'render_type'    => 'web',
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> ['owner_party', 'owner_party', 'agent'],
            'field_mappings' => [
                'tag-test-1' => [
                    'field_name'  => 'seller_address',
                    'label'       => 'Seller Address',
                    'party'       => 'seller',
                    'editable_by' => ['owner_party', 'agent'],
                ],
            ],
            'owner_id'       => $userId,
        ]);
        $doc = Document::create([
            'name'         => 'Doc',
            'document_type'=> 'agreement',
            'owner_id'     => $userId,
            'template_id'  => $docTmpl->id,
            'web_template_data' => ['seller_address' => ''],
        ]);
        $sigTmpl = SignatureTemplate::create([
            'document_id'   => $doc->id,
            'document_hash' => Str::random(64),
            'status'        => 'draft',
            'created_by'    => $userId,
        ]);

        $seller1 = $this->seedSignatureRequest($sigTmpl, 'seller', 1, 'James Vdm');
        $seller2 = $this->seedSignatureRequest($sigTmpl, 'seller', 2, 'Steve Jobs');

        return [$sigTmpl, $seller1, $seller2];
    }

    private function seedSignatureRequest(
        SignatureTemplate $sigTmpl,
        string $partyRole,
        int $roleIndex,
        string $signerName,
    ): SignatureRequest {
        return SignatureRequest::create([
            'signature_template_id' => $sigTmpl->id,
            'party_role'   => $partyRole,
            'role_index'   => $roleIndex,
            'signer_name'  => $signerName,
            'signer_email' => strtolower(str_replace(' ', '.', $signerName)) . '@x.test',
            'token'        => Str::random(48),
            'token_expires_at' => now()->addDays(30),
            'status'       => 'pending',
            'signing_order'=> $roleIndex,
        ]);
    }
}
