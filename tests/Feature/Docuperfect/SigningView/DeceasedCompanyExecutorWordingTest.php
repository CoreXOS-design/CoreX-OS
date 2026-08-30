<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Http\Controllers\Docuperfect\ESignWizardController;
use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\RecipientTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Shape 5 wording — cc3, 2026-08-30. Before this fix, a deceased seller's
 * "Estate Late Company" recipient template named the executor company as a
 * bare, always-blank {executor_company} token (that token only ever reads
 * SignatureRequest::supplier_firm_name, populated for a Deal-Register-
 * supplier-sourced executor — never for an ordinary linked Contact, which
 * is what the real "search a contact" picker in the wizard actually
 * produces). The finished document read "duly authorised by  as the
 * Executor/Executrix..." — the company never named at all.
 *
 * Fix: RecipientTemplate::resolveSlotDisplayName()/resolveSlotSubTokens()'s
 * type='recipient' branch now prefers the bound recipient's own
 * party_clause_text (frozen by expandEntityRecipients() via
 * RoleBlockExpansionService::composeEntityPartyText() — the SAME text the
 * document body itself already uses to name this exact party) over the
 * bare signer name. The template text itself now matches cc6's proven
 * natural-executor pattern (estate_late_natural) exactly — "Estate Late
 * {deceased_representative} duly represented by the Executor/Executrix of
 * the estate {executor_representative} {executor_representative_id}." —
 * rather than inventing a second wording shape; the company's name now
 * simply arrives correctly-populated inside {executor_representative}
 * instead of via a separate, broken token.
 */
final class DeceasedCompanyExecutorWordingTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agencyId = (int) DB::table('agencies')->insertGetId([
            'name' => 'Test Agency', 'slug' => 'test-' . Str::random(6),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->branchId = (int) DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Test Branch',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function expand(array $recipients, $user): array
    {
        $m = new ReflectionMethod(ESignWizardController::class, 'expandEntityRecipients');
        $m->setAccessible(true);

        return $m->invoke(app(ESignWizardController::class), $recipients, $user, true);
    }

    private function makeSignatureTemplate(User $creator): SignatureTemplate
    {
        $docTemplate = DocuperfectTemplate::create([
            'name' => 'Test Template', 'render_type' => 'web', 'blade_view' => 'test-fixtures.dummy',
            'template_type' => 'cds', 'category' => 'sales', 'owner_id' => $creator->id,
        ]);
        $document = Document::create([
            'name' => 'Test Doc', 'document_type' => 'agreement', 'owner_id' => $creator->id,
            'agency_id' => $this->agencyId, 'template_id' => $docTemplate->id,
            'web_template_data' => ['merged_html' => '<div></div>'],
        ]);

        return SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $creator->id,
        ]);
    }

    /** (d) The finished document's wording names the estate, the deceased, the executor company, AND the representative. */
    public function test_company_executor_chain_names_all_four_parties(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId]);
        $signatureTemplate = $this->makeSignatureTemplate($agent);
        $service = app(SignatureService::class);

        $executorCo = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Estate Trustees (Pty) Ltd',
            'entity_reg_no' => '2020/555001/07', 'first_name' => 'Estate Trustees (Pty) Ltd', 'last_name' => '',
        ]);
        $rep = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Jane', 'last_name' => 'Director',
            'id_number' => '8001015800111', 'email' => 'jane.director@x.test',
        ]);
        ContactRepresentative::create([
            'entity_contact_id' => $executorCo->id, 'representative_contact_id' => $rep->id,
            'capacity' => 'Director', 'signs_as_proxy' => false, 'is_primary' => true,
        ]);

        // Mirrors the real send path: raw recipients -> expandEntityRecipients()
        // -> real SignatureRequest rows created from the expanded array.
        $recipients = [
            [
                'name' => 'John Smith', 'role' => 'seller',
                '_recipient_local_key' => 'deceased-key', '_is_deceased' => true,
                '_recipient_template_id' => 1,
                '_slot_bindings' => [
                    'deceased' => ['type' => 'self'],
                    'executor' => ['type' => 'recipient', 'recipient_local_key' => 'executor-key'],
                ],
            ],
            [
                'name' => 'Estate Trustees (Pty) Ltd', 'role' => 'seller',
                '_recipient_local_key' => 'executor-key', '_is_deceased' => false,
                '_contact_id' => $executorCo->id,
            ],
        ];
        $expanded = $this->expand($recipients, $agent);
        $repRow = collect($expanded)->firstWhere('_contact_id', $rep->id);
        $this->assertNotNull($repRow);

        $deceasedSigReq = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'John Smith', signerEmail: 'john.smith.deceased@x.test', roleIndex: 1,
            isDeceased: true, recipientLocalKey: 'deceased-key',
        );
        $repSigReq = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: $repRow['name'], signerEmail: $repRow['email'], roleIndex: 2,
            signerIdNumber: $repRow['id_number'], partyClauseText: $repRow['_party_clause_text'],
            recipientLocalKey: $repRow['_recipient_local_key'],
        );
        $this->assertSame('executor-key', $repSigReq->recipient_local_key,
            'Fix 1 regression guard: the representative row must carry the key the deceased row binds to.');

        // Mirror cc6's proven natural-executor pattern exactly — no new wording shape invented.
        $template = RecipientTemplate::create([
            'agency_id' => null, 'role_token' => 'seller', 'key' => 'late_estate_company_test',
            'name' => 'Estate Late Company',
            'text_template' => 'Estate Late {deceased_representative} duly represented by the '
                . 'Executor/Executrix of the estate {executor_representative} {executor_representative_id}.',
            'party_slots' => [
                ['key' => 'deceased', 'label' => 'Deceased'],
                ['key' => 'executor', 'label' => 'Executor'],
            ],
            'is_default' => false,
        ]);

        $text = $template->resolveBoundText($deceasedSigReq, [
            'deceased' => ['type' => 'self'],
            'executor' => ['type' => 'recipient', 'recipient_local_key' => 'executor-key'],
        ]);

        // All four: the estate/deceased, the executor COMPANY (name + reg no),
        // the representative, and their authority (capacity).
        $this->assertStringContainsString('Estate Late John Smith', $text);
        $this->assertStringContainsString('Estate Trustees (Pty) Ltd', $text, 'The executor COMPANY must be named — this is the exact defect being fixed.');
        $this->assertStringContainsString('2020/555001/07', $text, "The company's registration number must appear.");
        $this->assertStringContainsString('Jane Director', $text, 'The representative who actually signs must be named.');
        $this->assertStringContainsString('Director', $text, "The representative's capacity/authority must appear.");
        $this->assertStringContainsString('8001015800111', $text, "The representative's own ID number must appear.");

        $this->assertSame(
            'Estate Late John Smith duly represented by the Executor/Executrix of the estate '
            . 'Estate Trustees (Pty) Ltd (Reg: 2020/555001/07), herein represented by '
            . 'Jane Director (ID: 8001015800111, Director).',
            $text
        );
    }

    /** (c) REGRESSION — cc6's natural-executor wording must be completely unaffected by this fix. */
    public function test_natural_person_executor_wording_unchanged(): void
    {
        $agent = User::factory()->create(['agency_id' => $this->agencyId]);
        $signatureTemplate = $this->makeSignatureTemplate($agent);
        $service = app(SignatureService::class);

        $naturalExecutor = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Piet', 'last_name' => 'Executor',
            'id_number' => '7001015800112',
        ]);

        $deceasedSigReq = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'John Smith', signerEmail: 'john.smith.deceased2@x.test', roleIndex: 1,
            isDeceased: true, recipientLocalKey: 'deceased-key-2',
        );
        // Natural-person executor never goes through expandEntityRecipients()'s
        // entity branch at all, so it never gets a party_clause_text — the
        // fallback path (bare signer_name/id) must still fire, unchanged.
        $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: $naturalExecutor->full_name, signerEmail: 'piet@x.test', roleIndex: 2,
            signerIdNumber: $naturalExecutor->id_number, recipientLocalKey: 'executor-key-2',
        );

        $template = RecipientTemplate::create([
            'agency_id' => null, 'role_token' => 'seller', 'key' => 'estate_late_natural_test',
            'name' => 'Estate late Natural',
            'text_template' => 'Estate Late {deceased_representative} duly represented by the '
                . 'Executor/Executrix of the estate {executor_representative} {executor_representative_id}.',
            'party_slots' => [
                ['key' => 'deceased', 'label' => 'Deceased'],
                ['key' => 'executor', 'label' => 'Executor'],
            ],
            'is_default' => false,
        ]);

        $text = $template->resolveBoundText($deceasedSigReq, [
            'deceased' => ['type' => 'self'],
            'executor' => ['type' => 'recipient', 'recipient_local_key' => 'executor-key-2'],
        ]);

        $this->assertSame(
            'Estate Late John Smith duly represented by the Executor/Executrix of the estate Piet Executor 7001015800112.',
            $text
        );
    }
}
