<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Exceptions\DanglingSlotBindingException;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\RecipientTemplate;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "The critical part is knowing how to link them in the recipient built
 * clause" (Johan, 2026-08-24). Piet's exact scenario: person -> entity ->
 * person, three deep, no special case for the depth. Plus the dangling
 * binding — the failure mode Johan named as where the real bugs live.
 */
final class RecipientTemplateSlotBindingTest extends TestCase
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

    private function makeSignatureTemplate(): SignatureTemplate
    {
        $creator = \App\Models\User::factory()->create(['agency_id' => $this->agencyId]);
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

    /** Piet -> Estate Pty Ltd -> Koos. Three deep, same mechanism as a two-level chain — no special case. */
    public function test_three_deep_chain_resolves_person_entity_person(): void
    {
        $signatureTemplate = $this->makeSignatureTemplate();
        $service = app(SignatureService::class);

        $piet = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'Piet', signerEmail: 'piet@x.test', roleIndex: 1,
        );
        $piet->update(['is_deceased' => true, 'recipient_local_key' => 'piet-key']);

        $koos = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'Koos', signerEmail: 'koos@x.test', roleIndex: 2,
        );
        $koos->update(['recipient_local_key' => 'koos-key']);

        $estateContact = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Estate Pty Ltd',
            'first_name' => 'Estate Pty Ltd', 'last_name' => '',
        ]);

        $template = RecipientTemplate::create([
            'agency_id' => null, 'role_token' => 'seller', 'key' => 'deceased_via_entity',
            'name' => 'Deceased, represented by an entity',
            'text_template' => '{deceased} herein represented by {entity} represented by {executor}',
            'party_slots' => [
                ['key' => 'deceased', 'label' => 'Deceased'],
                ['key' => 'entity', 'label' => 'Representing Entity'],
                ['key' => 'executor', 'label' => 'Executor'],
            ],
            'is_default' => true,
        ]);

        $bindings = [
            'deceased' => ['type' => 'self'],
            'entity' => ['type' => 'contact', 'contact_id' => $estateContact->id],
            'executor' => ['type' => 'recipient', 'recipient_local_key' => 'koos-key'],
        ];

        $text = $template->resolveBoundText($piet, $bindings);

        $this->assertSame('Piet herein represented by Estate Pty Ltd represented by Koos', $text);

        // GENERATION TIME — frozen exactly like every other party_clause_text.
        $piet->update([
            'recipient_template_id' => $template->id, 'slot_bindings' => $bindings, 'party_clause_text' => $text,
        ]);
        $piet->refresh();
        $this->assertSame('Piet herein represented by Estate Pty Ltd represented by Koos', $piet->party_clause_text);
    }

    /** Recipient removed after binding, before finalisation — must block, never render blank or half-built. */
    public function test_dangling_recipient_binding_blocks_resolution(): void
    {
        $signatureTemplate = $this->makeSignatureTemplate();
        $service = app(SignatureService::class);

        $piet = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'Piet', signerEmail: 'piet@x.test', roleIndex: 1,
        );
        $piet->update(['is_deceased' => true, 'recipient_local_key' => 'piet-key']);

        $template = RecipientTemplate::create([
            'agency_id' => null, 'role_token' => 'seller', 'key' => 'deceased_simple',
            'name' => 'Deceased, represented directly',
            'text_template' => '{deceased} herein represented by {executor}',
            'party_slots' => [
                ['key' => 'deceased', 'label' => 'Deceased'],
                ['key' => 'executor', 'label' => 'Executor'],
            ],
            'is_default' => true,
        ]);

        // The executor recipient was never created (removed, or the key is simply wrong) —
        // this is exactly the "removed after binding, before finalisation" case.
        $bindings = [
            'deceased' => ['type' => 'self'],
            'executor' => ['type' => 'recipient', 'recipient_local_key' => 'does-not-exist'],
        ];

        $this->expectException(DanglingSlotBindingException::class);
        $this->expectExceptionMessage('"Executor" was removed or changed');

        $template->resolveBoundText($piet, $bindings);
    }

    /** A slot with no binding at all (agent never finished linking it) — same block, same reason. */
    public function test_unbound_slot_blocks_resolution(): void
    {
        $signatureTemplate = $this->makeSignatureTemplate();
        $service = app(SignatureService::class);

        $piet = $service->createSigningRequest(
            template: $signatureTemplate, partyRole: 'seller',
            signerName: 'Piet', signerEmail: 'piet@x.test', roleIndex: 1,
        );
        $piet->update(['is_deceased' => true, 'recipient_local_key' => 'piet-key']);

        $template = RecipientTemplate::create([
            'agency_id' => null, 'role_token' => 'seller', 'key' => 'deceased_simple2',
            'name' => 'Deceased, represented directly',
            'text_template' => '{deceased} herein represented by {executor}',
            'party_slots' => [
                ['key' => 'deceased', 'label' => 'Deceased'],
                ['key' => 'executor', 'label' => 'Executor'],
            ],
            'is_default' => true,
        ]);

        $this->expectException(DanglingSlotBindingException::class);

        $template->resolveBoundText($piet, ['deceased' => ['type' => 'self']]); // 'executor' never bound
    }
}
