<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Services\Docuperfect\SigningFieldValueProjector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * ES-5 — Editable-at-Signing end-to-end VALUE PERSISTENCE.
 *
 * The recipient-loop B1–B3 work made the right fields *surface* as editable to
 * the right recipient (proved by RealTemplate111EndToEndTest). This suite
 * proves the next link that was broken: a value a recipient TYPES actually
 * persists, survives across signers without colliding seller_1 with seller_2,
 * and reaches the filed document — driven through the real
 * /sign/{token}/save-web-fields + /complete-web pipeline.
 *
 * Shared-keying contract: values are keyed by the rendered `data-field` (the
 * mangled `{name}__r{role_index}` the loop stamps), the same identity scheme
 * as SignatureRequest::role_identity. No parallel keying is introduced.
 *
 * Touches the pipeline-gate files SigningController + SigningFieldValueProjector.
 */
final class EditableAtSigningValuePersistenceTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    /**
     * Pull the rendered `data-field` key for a given logical field that is
     * stamped editable for the current viewer (carries data-viewer-editable).
     * Attribute order is not guaranteed, so match both orders.
     */
    private function editableFieldKey(string $body, string $logical): ?string
    {
        // data-field before data-viewer-editable
        if (preg_match('/data-field="(' . preg_quote($logical, '/') . '(?:__r\d+)?)"[^>]*data-viewer-editable="1"/', $body, $m)) {
            return $m[1];
        }
        // data-viewer-editable before data-field
        if (preg_match('/data-viewer-editable="1"[^>]*data-field="(' . preg_quote($logical, '/') . '(?:__r\d+)?)"/', $body, $m)) {
            return $m[1];
        }
        return null;
    }

    private function saveField(string $token, string $fieldKey, string $value, string $identity): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/sign/' . $token . '/save-web-fields', [
                'fields' => [
                    $fieldKey => [
                        'value'          => $value,
                        'identity'       => $identity,
                        'original_field' => preg_replace('/__r\d+$/', '', $fieldKey),
                    ],
                ],
            ]);
    }

    /**
     * The core multi-recipient guarantee: seller_1 and seller_2 each edit the
     * "same" logical seller_address; both values persist distinctly (no
     * collision) and both render back onto their own surfaces.
     */
    public function test_two_sellers_edits_persist_without_collision(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $seller2 = $this->recipient($session['recipients'], 'seller', 2);

        // Discover the rendered editable field key for each seller.
        $body1 = $this->extractRenderedDocumentHtml($this->asRecipient($seller1));
        $body2 = $this->extractRenderedDocumentHtml($this->asRecipient($seller2));
        $key1 = $this->editableFieldKey($body1, 'seller_address');
        $key2 = $this->editableFieldKey($body2, 'seller_address');
        $this->assertNotNull($key1, 'seller_1 must have an editable seller_address surface');
        $this->assertNotNull($key2, 'seller_2 must have an editable seller_address surface');
        $this->assertNotSame($key1, $key2, 'seller_1 and seller_2 editable keys must be distinct (per-recipient mangling)');

        $addr1 = '12 Marine Drive, Margate';
        $addr2 = '88 Outlook Road, Uvongo';

        $this->saveField($seller1->token, $key1, $addr1, 'seller_1')->assertOk();
        $this->saveField($seller2->token, $key2, $addr2, 'seller_2')->assertOk();

        // No collision in storage — both keys retained with distinct values.
        $document = $session['document']->fresh();
        $store = $document->web_template_data['signing_field_values'] ?? [];
        $this->assertSame($addr1, $store[$key1] ?? null, 'seller_1 value must persist under its own key');
        $this->assertSame($addr2, $store[$key2] ?? null, 'seller_2 value must persist under its own key');

        // Cross-signer visibility — when seller_2 reloads, BOTH addresses are
        // projected onto the document body (seller_1's as static text on its
        // clone, seller_2's pre-filling its own surface).
        $reloaded = $this->extractRenderedDocumentHtml($this->asRecipient($seller2));
        $this->assertStringContainsString($addr1, $reloaded, "seller_1's edit must be visible to seller_2");
        $this->assertStringContainsString($addr2, $reloaded, "seller_2's own edit must be pre-filled");
    }

    /**
     * Identity gate: seller_1 cannot write seller_2's surface even by posting
     * seller_2's mangled key (the value is rejected; no cross-write).
     */
    public function test_recipient_cannot_write_another_recipients_field(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $seller2 = $this->recipient($session['recipients'], 'seller', 2);

        $body2 = $this->extractRenderedDocumentHtml($this->asRecipient($seller2));
        $key2 = $this->editableFieldKey($body2, 'seller_address');
        $this->assertNotNull($key2);

        // seller_1 posting seller_2's key under seller_1's identity → 403.
        $this->saveField($seller1->token, $key2, 'hijacked', 'seller_1')->assertStatus(403);

        $store = $session['document']->fresh()->web_template_data['signing_field_values'] ?? [];
        $this->assertArrayNotHasKey($key2, $store, "seller_2's field must not be written by seller_1");
    }

    /** Optional-empty is absorbed: saving an empty value is accepted, no 500. */
    public function test_optional_empty_value_is_absorbed(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);

        $body1 = $this->extractRenderedDocumentHtml($this->asRecipient($seller1));
        $key1 = $this->editableFieldKey($body1, 'seller_address');
        $this->assertNotNull($key1);

        $this->saveField($seller1->token, $key1, '', 'seller_1')->assertOk();
        $store = $session['document']->fresh()->web_template_data['signing_field_values'] ?? [];
        $this->assertSame('', $store[$key1] ?? null);
    }

    /**
     * Direct projector contract — value baked onto a span (render path) and an
     * input frozen to text (filed-artifact path). Proves the shared-keying
     * match + the input→text bake.
     */
    public function test_projector_bakes_values_by_field_key(): void
    {
        $projector = app(SigningFieldValueProjector::class);

        // Render path — span text replaced.
        $html = '<p><span class="x" data-field="seller_address__r2" data-recipient-identity="seller_2">[Seller Address]</span></p>';
        $out = $projector->project($html, ['seller_address__r2' => '88 Outlook Road']);
        $this->assertStringContainsString('88 Outlook Road', $out);
        $this->assertStringNotContainsString('[Seller Address]', $out);

        // Filed-artifact path — an <input> with no serialised value is frozen
        // to a text span carrying the value.
        $inputHtml = '<input type="text" class="field-editable" name="seller_address__r2" data-field="seller_address__r2" data-viewer-editable="1">';
        $baked = $projector->project($inputHtml, ['seller_address__r2' => '88 Outlook Road'], bakeInputsToText: true);
        $this->assertStringContainsString('88 Outlook Road', $baked);
        $this->assertStringNotContainsString('<input', $baked);

        // Non-matching keys are no-ops (no corruption).
        $untouched = $projector->project($html, ['unrelated_key' => 'x']);
        $this->assertStringContainsString('[Seller Address]', $untouched);
    }

    /**
     * Completion path — the posted paginated DOM (inputs with no serialised
     * value) gets the authoritative field values baked in, so the filed
     * signed_paginated_html carries the edit for the flattened PDF.
     */
    public function test_completion_bakes_field_values_into_signed_paginated_html(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller2 = $this->recipient($session['recipients'], 'seller', 2);

        $body2 = $this->extractRenderedDocumentHtml($this->asRecipient($seller2));
        $key2 = $this->editableFieldKey($body2, 'seller_address');
        $this->assertNotNull($key2);

        $addr2 = '88 Outlook Road, Uvongo';
        // Simulate the browser submitting the paginated DOM: an editable input
        // whose typed value is NOT serialised in innerHTML, plus the separate
        // identity-tagged field_values payload.
        $paginated = '<div class="corex-document-wrapper"><div class="corex-a4-page">'
            . '<input type="text" class="field-editable" name="' . $key2 . '" data-field="' . $key2 . '" data-viewer-editable="1">'
            . '</div></div>';

        $resp = $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/sign/' . $seller2->token . '/complete-web', [
                'consented'      => true,
                'field_values'   => [
                    $key2 => ['value' => $addr2, 'identity' => 'seller_2', 'original_field' => 'seller_address'],
                ],
                'paginated_html' => $paginated,
            ]);
        $resp->assertOk();

        $document = $session['document']->fresh();
        $store = $document->web_template_data['signing_field_values'] ?? [];
        $this->assertSame($addr2, $store[$key2] ?? null, 'completion must persist the value under its identity key');

        // The filed artifact carries the value as baked text (no live input).
        $signed = (string) $document->signed_paginated_html;
        $this->assertStringContainsString($addr2, $signed, 'filed signed_paginated_html must carry the edited value');
        $this->assertStringNotContainsString('<input', $signed, 'editable inputs must be frozen to text in the filed artifact');
    }

    // ── ES-5 cross-recipient write hole (Tinker-found) — instance-ownership gate ──

    /**
     * Post a raw payload with a caller-chosen original_field — lets a test
     * craft the exact (storage key, claimed logical field) pair an attacker
     * controls (the stripping saveField() helper can't express a clean
     * original_field alongside a malformed key).
     */
    private function saveRawField(string $token, string $fieldKey, string $originalField, string $value, string $identity): \Illuminate\Testing\TestResponse
    {
        return $this->withoutMiddleware(ValidateCsrfToken::class)
            ->postJson('/sign/' . $token . '/save-web-fields', [
                'fields' => [
                    $fieldKey => [
                        'value'          => $value,
                        'identity'       => $identity,
                        'original_field' => $originalField,
                    ],
                ],
            ]);
    }

    /** Fail-safe: a malformed "__r" suffix (non-numeric / empty) is denied, never authorised. */
    public function test_malformed_instance_suffix_is_denied(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        // Truthful clean original_field, but a malformed mangled storage key.
        $this->saveRawField($seller1->token, 'seller_address__rX', 'seller_address', 'x', 'seller_1')->assertStatus(403);
        $this->saveRawField($seller1->token, 'seller_address__r', 'seller_address', 'x', 'seller_1')->assertStatus(403);
    }

    /** Fail-safe: __r0 (invalid instance index) is denied. */
    public function test_instance_zero_suffix_is_denied(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $this->saveRawField($seller1->token, 'seller_address__r0', 'seller_address', 'x', 'seller_1')->assertStatus(403);
    }

    /** Fail-safe: an instance beyond the viewer's own (incl. beyond party count) is denied. */
    public function test_instance_beyond_viewer_is_denied(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $this->saveRawField($seller1->token, 'seller_address__r9', 'seller_address', 'x', 'seller_1')->assertStatus(403);
    }

    /** A denied cross-recipient write is recorded in the immutable audit log. */
    public function test_cross_recipient_write_is_audited(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $before = \App\Models\Docuperfect\SignatureAuditLog::where('action', 'web_fields_save_denied')->count();
        $this->saveRawField($seller1->token, 'seller_address__r2', 'seller_address', 'hijack', 'seller_1')->assertStatus(403);
        $after = \App\Models\Docuperfect\SignatureAuditLog::where('action', 'web_fields_save_denied')->count();
        $this->assertSame($before + 1, $after, 'a denied cross-recipient write must write a web_fields_save_denied audit row');
    }

    /** No regression: each seller still writes their OWN instance (the ES-5 flow dd1533c2 fixed). */
    public function test_each_seller_can_still_write_own_instance(): void
    {
        $session = $this->buildCanonicalTemplate111Session(sellerCount: 2);
        $seller1 = $this->recipient($session['recipients'], 'seller', 1);
        $seller2 = $this->recipient($session['recipients'], 'seller', 2);
        $key1 = $this->editableFieldKey($this->extractRenderedDocumentHtml($this->asRecipient($seller1)), 'seller_address');
        $key2 = $this->editableFieldKey($this->extractRenderedDocumentHtml($this->asRecipient($seller2)), 'seller_address');
        $this->saveField($seller1->token, $key1, '1 Own Road', 'seller_1')->assertOk();
        $this->saveField($seller2->token, $key2, '2 Own Road', 'seller_2')->assertOk();
    }
}
