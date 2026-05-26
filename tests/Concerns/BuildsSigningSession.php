<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/**
 * Test trait — builds a real signing session against the canonical
 * template-111 fixture (tests/Fixtures/templates/template-111-canonical.blade.php).
 *
 * The intent is to drive the REAL signing pipeline end-to-end:
 *
 *   Template (DocuperfectTemplate) is created with the fixture HTML as
 *   merged_html so SurfaceNormalizer → LetterheadRefresher →
 *   InsertableBlockRenderer → RoleBlockExpansionService all run against
 *   the same shape live agents produce in the CDS builder.
 *
 *   SignatureRequests are created via the REAL
 *   `SignatureService::createSigningRequest()` path (the same code the
 *   wizard invokes) — so the B1 role-index split, role_identity
 *   accessor, audit-log row, and signing-order assignment are all
 *   exercised.
 *
 *   HTTP requests hit the REAL `/sign/{token}` route through Laravel's
 *   test kernel so the controller, blade view, and Alpine state shipped
 *   to the browser are all observable in `$response->getContent()`.
 *
 * Reject model::create shortcuts that bypass the pipeline. If a
 * downstream service expects a column populated by the wizard, the
 * trait must make sure that column gets populated by going through the
 * wizard's code path, not by injecting the value into the model.
 */
trait BuildsSigningSession
{
    /**
     * Build a real signing session against the canonical fixture.
     *
     * @param  int  $sellerCount  How many seller recipients to create (>=1).
     * @param  bool $includeAgent Whether to add an agent recipient.
     * @return array{
     *   template: DocuperfectTemplate,
     *   document: Document,
     *   signatureTemplate: SignatureTemplate,
     *   recipients: Collection<int, SignatureRequest>,
     *   creator: User,
     * }
     */
    protected function buildCanonicalTemplate111Session(
        int $sellerCount = 3,
        bool $includeAgent = true,
    ): array {
        $creator = $this->seedAgentUser();
        $mergedHtml = $this->loadCanonicalFixtureHtml();

        $template = DocuperfectTemplate::create([
            'name'           => 'Canonical Template 111 (test fixture)',
            'render_type'    => 'web',
            // SigningController::show() routes into the web-template branch
            // ONLY when render_type === 'web' AND blade_view is truthy.
            // The blade_view value is unused at request time because
            // merged_html is set on the document — but it must be present
            // for the branch to fire. Without it the controller falls
            // through to the PDF path and the RoleBlockExpansionService
            // never runs.
            'blade_view'     => 'test-fixtures.template-111-canonical',
            'template_type'  => 'cds',
            'category'       => 'sales',
            'signing_parties'=> $includeAgent ? ['owner_party', 'agent'] : ['owner_party'],
            'field_mappings' => $this->canonicalFieldMappingsForFixture(),
            'owner_id'       => $creator->id,
        ]);

        $document = Document::create([
            'name'              => 'Canonical Doc',
            'document_type'     => 'agreement',
            'owner_id'          => $creator->id,
            'template_id'       => $template->id,
            'web_template_data' => ['merged_html' => $mergedHtml],
        ]);

        $signatureTemplate = SignatureTemplate::create([
            'document_id'   => $document->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_SIGNING,
            'created_by'    => $creator->id,
        ]);

        /** @var SignatureService $signatureService */
        $signatureService = app(SignatureService::class);
        $recipients = collect();

        for ($i = 1; $i <= $sellerCount; $i++) {
            $name = $this->canonicalSellerName($i);
            $recipients->push($signatureService->createSigningRequest(
                template:    $signatureTemplate,
                partyRole:   'seller',
                signerName:  $name,
                signerEmail: strtolower(str_replace(' ', '.', $name)) . '@x.test',
                roleIndex:   $i,
            ));
        }

        if ($includeAgent) {
            $recipients->push($signatureService->createSigningRequest(
                template:    $signatureTemplate,
                partyRole:   'agent',
                signerName:  'Listing Agent',
                signerEmail: 'agent-' . Str::random(6) . '@hfc.test',
                roleIndex:   1,
                sentBy:      $creator,
            ));
        }

        // Transition every recipient out of STATUS_WAITING into PENDING so
        // the signing surface renders (not the "wait for notification"
        // landing page). Mirrors the post-send state — what recipients
        // actually see when they open their link. Bypasses the email
        // dispatch in SignatureService::sendSigningRequest() since tests
        // don't need outbound mail.
        SignatureRequest::where('signature_template_id', $signatureTemplate->id)
            ->update([
                'status' => SignatureRequest::STATUS_PENDING,
                'sent_at' => now(),
            ]);
        $recipients->each(fn(SignatureRequest $r) => $r->refresh());

        return [
            'template'          => $template,
            'document'          => $document,
            'signatureTemplate' => $signatureTemplate,
            'recipients'        => $recipients,
            'creator'           => $creator,
        ];
    }

    /**
     * Hit the real /sign/{token} route as the supplied recipient.
     */
    protected function asRecipient(SignatureRequest $recipient): TestResponse
    {
        return $this->get('/sign/' . $recipient->token);
    }

    /**
     * Pull the rendered document body out of the response.
     *
     * The merged_html lives inside Alpine state as a JSON-encoded string
     * (`webTemplateHtml: "..."`) where every double-quote in the HTML is
     * escaped to `"`. Decoding that lets assertions match the actual
     * stamps (`data-recipient-identity="seller_1"`) rather than fighting
     * the JSON escape sequence. Returns the raw HTML — newline-preserved,
     * unescaped — so substring + regex assertions work the same way the
     * browser sees them.
     */
    protected function extractRenderedDocumentHtml(TestResponse $response): string
    {
        $body = (string) $response->getContent();
        if (!preg_match('/webTemplateHtml:\s*("(?:\\\\.|[^"\\\\])*")/s', $body, $m)) {
            return '';
        }
        $decoded = json_decode($m[1], true);
        return is_string($decoded) ? $decoded : '';
    }

    /**
     * Hit the real /sign/{token} route as an authenticated dispatching
     * agent viewing the recipient's link. Used to verify that the agent's
     * session permissions do NOT inherit recipient-scoped affordances.
     */
    protected function asDispatchingAgentViewing(User $agent, SignatureRequest $recipient): TestResponse
    {
        return $this->actingAs($agent)->get('/sign/' . $recipient->token);
    }

    /**
     * Pull a single recipient by role + index.
     *
     * @param  Collection<int, SignatureRequest> $recipients
     */
    protected function recipient(Collection $recipients, string $partyRole, int $roleIndex): SignatureRequest
    {
        $hit = $recipients->first(fn(SignatureRequest $r) => $r->party_role === $partyRole && (int) $r->role_index === $roleIndex);
        if ($hit === null) {
            throw new \RuntimeException("No recipient for role={$partyRole} idx={$roleIndex}");
        }
        return $hit;
    }

    private function seedAgentUser(): User
    {
        $userId = (int) DB::table('users')->insertGetId([
            'name'  => 'Listing Agent',
            'email' => 'la-' . Str::random(6) . '@hfc.test',
            'password' => bcrypt('p'),
            'role'  => 'agent',
            'phone' => '+27 76 618 5578',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return User::findOrFail($userId);
    }

    private function loadCanonicalFixtureHtml(): string
    {
        $path = base_path('tests/Fixtures/templates/template-111-canonical.blade.php');
        if (!is_file($path)) {
            throw new \RuntimeException("Canonical fixture missing at {$path}");
        }
        $raw = file_get_contents($path) ?: '';
        // Strip the Blade comment header (the {{-- … --}} block at the top).
        return (string) preg_replace('/^\s*\{\{--.*?--\}\}\s*/s', '', $raw);
    }

    /**
     * Field-mappings shape mirrors live template 111 — one entry per
     * `data-field` value emitted by the fixture HTML. Includes
     * `editable_by` so the B3 viewer-editability stamping has the
     * authority data it needs.
     *
     * @return array<string, array<string, mixed>>
     */
    private function canonicalFieldMappingsForFixture(): array
    {
        $seller = ['party' => 'seller', 'editable_by' => ['owner_party', 'agent']];
        $agent  = ['party' => 'agent',  'editable_by' => ['agent']];
        $property = ['party' => 'agent', 'editable_by' => ['agent']];

        return [
            'tag-canon-snsid' => ['label' => 'Seller Name Surname ID', 'field_name' => 'seller_name_surname_id'] + $seller,
            'tag-canon-fn'    => ['label' => 'Seller First Name',      'field_name' => 'seller_first_name']     + $seller,
            'tag-canon-ln'    => ['label' => 'Seller Last Name',       'field_name' => 'seller_last_name']      + $seller,
            'tag-canon-idn'   => ['label' => 'Seller ID Number',       'field_name' => 'seller_id_number']      + $seller,
            'tag-canon-addr'  => ['label' => 'Seller Address',         'field_name' => 'seller_address']        + $seller,
            'tag-canon-ph'    => ['label' => 'Seller Phone',           'field_name' => 'seller_phone']          + $seller,
            'tag-canon-em'    => ['label' => 'Seller Email',           'field_name' => 'seller_email']          + $seller,
            'tag-canon-pa'    => ['label' => 'Property Address',       'field_name' => 'property_address']      + $property,
            'tag-canon-pe'    => ['label' => 'Property Erf Number',    'field_name' => 'property_erf_number']   + $property,
            'tag-canon-an'    => ['label' => 'Agent Name',             'field_name' => 'agent_name']            + $agent,
            'tag-canon-aff'   => ['label' => 'Agent FFC',              'field_name' => 'agent_ffc']             + $agent,
        ];
    }

    private function canonicalSellerName(int $index): string
    {
        $names = [
            1 => 'James Van Der Merwe',
            2 => 'Steve Jobs',
            3 => 'Charlie Charlton',
            4 => 'Dana Drake',
            5 => 'Ethan Engel',
        ];
        return $names[$index] ?? "Seller {$index}";
    }
}
