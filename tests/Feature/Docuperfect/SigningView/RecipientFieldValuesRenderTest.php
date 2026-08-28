<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSigningSession;
use Tests\TestCase;

/**
 * Johan/conductor, 2026-08-28 — a recipient's SIGNING-TIME field completion
 * (a domicilium address blank at send, or a pre-filled one they correct) was
 * saved into web_template_data['field_values'] by SigningController::
 * completeWeb() but nothing ever read it back into the document. See
 * CanonicalInkComposer::applyFieldValues() for the fix; this is its
 * regression guard.
 *
 * Builds its own minimal canonical_html directly (not through compose()/
 * RoleBlockExpansionService) so each scenario's starting DOM state — blank,
 * pre-filled, multiple same-role instances — is explicit and controlled,
 * rather than relying on the (separately tested) expansion pipeline to
 * produce it. canonical_version is seeded at 1 so completeWeb()'s v0->v1
 * recompose branch never fires and never overwrites this hand-built body.
 */
final class RecipientFieldValuesRenderTest extends TestCase
{
    use RefreshDatabase;
    use BuildsSigningSession;

    /**
     * @return array{document: Document, signatureTemplate: SignatureTemplate, requests: array<int, SignatureRequest>}
     */
    private function buildSession(string $addressSpanFor1, array $extraSpans, int $sellerCount): array
    {
        $creator = $this->seedAgentUser();

        $sellerBlocks = '';
        $sellerBlocks .= '<p>Physical address: <span class="corex-field-value" data-field="seller_address__r1" data-recipient-identity="seller_1">' . $addressSpanFor1 . '</span></p>';
        foreach ($extraSpans as $identity => $content) {
            $sellerBlocks .= '<p>Physical address: <span class="corex-field-value" data-field="seller_address__r' . substr($identity, -1) . '" data-recipient-identity="' . $identity . '">' . $content . '</span></p>';
        }

        $html = '<div class="contract corex-document"><h1>Test Mandate</h1>' . $sellerBlocks
            . '<p class="signature-line" data-marker-party="agent" data-marker-type="signature"></p></div>';

        $template = DocuperfectTemplate::create([
            'name'           => 'Field Values Render Test Fixture',
            'render_type'    => 'web',
            'blade_view'     => 'test-fixtures.template-111-canonical', // unused (canonical_html pre-seeded); must be truthy for the web branch
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> ['owner_party', 'agent'],
            'field_mappings' => [
                'tag-addr' => ['label' => 'Seller Address', 'field_name' => 'seller_address', 'party' => 'seller', 'editable_by' => ['owner_party', 'agent']],
            ],
            'owner_id'       => $creator->id,
        ]);

        $document = Document::create([
            'name'              => 'Field Values Render Test Doc',
            'document_type'     => 'agreement',
            'owner_id'          => $creator->id,
            'agency_id'         => $creator->agency_id,
            'template_id'       => $template->id,
            'web_template_data' => [
                'merged_html'      => $html,
                'canonical_html'   => $html,
                'canonical_version' => 1, // already "baked once" -- completeWeb() must not recompose
            ],
        ]);

        $signatureTemplate = SignatureTemplate::create([
            'document_id'   => $document->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_SIGNING,
            'created_by'    => $creator->id,
        ]);

        $signatureService = app(SignatureService::class);
        $requests = [];
        for ($i = 1; $i <= $sellerCount; $i++) {
            $requests[$i] = $signatureService->createSigningRequest(
                template:    $signatureTemplate,
                partyRole:   'seller',
                signerName:  "Seller {$i}",
                signerEmail: "seller{$i}@x.test",
                roleIndex:   $i,
            );
        }
        $requests['agent'] = $signatureService->createSigningRequest(
            template:    $signatureTemplate,
            partyRole:   'agent',
            signerName:  'Listing Agent',
            signerEmail: 'agent-' . Str::random(6) . '@hfc.test',
            roleIndex:   1,
            sentBy:      $creator,
        );

        SignatureRequest::where('signature_template_id', $signatureTemplate->id)
            ->update(['status' => SignatureRequest::STATUS_PENDING, 'sent_at' => now()]);
        foreach ($requests as $r) {
            $r->refresh();
        }

        return ['document' => $document, 'signatureTemplate' => $signatureTemplate, 'requests' => $requests];
    }

    private function completeWeb(SignatureRequest $recipient, array $fieldValues): \Illuminate\Testing\TestResponse
    {
        $img = 'data:image/png;base64,iVBORw0KGgo=';

        return $this->postJson('/sign/' . $recipient->token . '/complete-web', [
            'consented'    => true,
            'signatures'   => ['seller-sig-0' => $img, 'agent-sig-0' => $img],
            'initials'     => [],
            'field_values' => $fieldValues,
        ]);
    }

    /** (a) blank at creation, recipient types a value -> value appears in canonical_html. */
    public function test_blank_field_filled_by_recipient_renders(): void
    {
        $session = $this->buildSession(addressSpanFor1: '', extraSpans: [], sellerCount: 1);
        $seller1 = $session['requests'][1];

        $resp = $this->completeWeb($seller1, ['seller_address__r1' => 'NEW BLANK-FILL VALUE']);
        $this->assertNotSame(422, $resp->getStatusCode(), (string) $resp->getContent());

        $html = (string) (Document::find($session['document']->id)->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString('NEW BLANK-FILL VALUE', $html);
    }

    /** (b) pre-filled at creation, recipient EDITS it -> the edit wins, the old value is gone. */
    public function test_prefilled_field_edited_by_recipient_overwrites_old_value(): void
    {
        $session = $this->buildSession(addressSpanFor1: 'ORIGINAL PRE-FILLED VALUE', extraSpans: [], sellerCount: 1);
        $seller1 = $session['requests'][1];

        $resp = $this->completeWeb($seller1, ['seller_address__r1' => 'RECIPIENT EDITED VALUE']);
        $this->assertNotSame(422, $resp->getStatusCode(), (string) $resp->getContent());

        $html = (string) (Document::find($session['document']->id)->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString('RECIPIENT EDITED VALUE', $html);
        $this->assertStringNotContainsString('ORIGINAL PRE-FILLED VALUE', $html);
    }

    /**
     * (c) two same-role recipients (seller 2 and seller 3), each types a
     * DIFFERENT value -> each gets their own, neither gets the other's.
     * Also proves the scope check itself: seller 2's own completion carries
     * a (malicious-or-buggy) key for seller 3's span too, and it must be
     * refused.
     */
    public function test_same_role_siblings_never_receive_each_others_value(): void
    {
        $session = $this->buildSession(
            addressSpanFor1: 'SELLER 1 UNTOUCHED',
            extraSpans: ['seller_2' => 'SELLER 2 STARTING VALUE', 'seller_3' => 'SELLER 3 STARTING VALUE'],
            sellerCount: 3,
        );
        $seller2 = $session['requests'][2];
        $seller3 = $session['requests'][3];
        $docId = $session['document']->id;

        // Seller 2 submits their own value AND attempts to also write seller 3's span.
        $resp2 = $this->completeWeb($seller2, [
            'seller_address__r2' => 'SELLER 2 OWN VALUE',
            'seller_address__r3' => 'HIJACK ATTEMPT FROM SELLER 2',
        ]);
        $this->assertNotSame(422, $resp2->getStatusCode(), (string) $resp2->getContent());

        $htmlAfter2 = (string) (Document::find($docId)->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString('SELLER 2 OWN VALUE', $htmlAfter2, 'seller 2 must receive their own value');
        $this->assertStringNotContainsString('HIJACK ATTEMPT FROM SELLER 2', $htmlAfter2, 'seller 2 must NOT be able to write seller 3\'s span');
        $this->assertStringContainsString('SELLER 3 STARTING VALUE', $htmlAfter2, 'seller 3\'s span must be untouched by seller 2\'s completion');
        $this->assertStringContainsString('SELLER 1 UNTOUCHED', $htmlAfter2, 'seller 1\'s span must be untouched');

        $seller3->refresh();
        if ($seller3->status === SignatureRequest::STATUS_WAITING) {
            $seller3->update(['status' => SignatureRequest::STATUS_PENDING, 'sent_at' => now()]);
        }
        $resp3 = $this->completeWeb($seller3, ['seller_address__r3' => 'SELLER 3 OWN VALUE']);
        $this->assertNotSame(422, $resp3->getStatusCode(), (string) $resp3->getContent());

        $htmlAfter3 = (string) (Document::find($docId)->web_template_data['canonical_html'] ?? '');
        $this->assertStringContainsString('SELLER 2 OWN VALUE', $htmlAfter3, 'seller 2\'s value must survive seller 3\'s completion');
        $this->assertStringContainsString('SELLER 3 OWN VALUE', $htmlAfter3, 'seller 3 must receive their own value');
        $this->assertStringNotContainsString('SELLER 3 STARTING VALUE', $htmlAfter3);
        $this->assertStringNotContainsString('HIJACK ATTEMPT FROM SELLER 2', $htmlAfter3);
    }

    /** (d) a field_values key with no matching span -> no crash, nothing stamped. */
    public function test_field_value_with_no_matching_span_does_not_crash(): void
    {
        $session = $this->buildSession(addressSpanFor1: 'STAYS AS IS', extraSpans: [], sellerCount: 1);
        $seller1 = $session['requests'][1];

        $before = (string) (Document::find($session['document']->id)->web_template_data['canonical_html'] ?? '');

        $resp = $this->completeWeb($seller1, ['nonexistent_field__r99' => 'NOWHERE TO GO']);
        $this->assertNotSame(422, $resp->getStatusCode(), (string) $resp->getContent());
        $this->assertNotSame(500, $resp->getStatusCode());

        $after = (string) (Document::find($session['document']->id)->web_template_data['canonical_html'] ?? '');
        $this->assertStringNotContainsString('NOWHERE TO GO', $after);
        $this->assertStringContainsString('STAYS AS IS', $after);
    }
}
