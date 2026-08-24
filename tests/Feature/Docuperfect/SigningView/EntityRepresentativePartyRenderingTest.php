<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Contact;
use App\Models\ContactRepresentative;
use App\Models\Docuperfect\SignatureRequest;
use App\Services\Docuperfect\RoleBlockExpansionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The piece .ai/specs/contact-entity-type.md §6.2 flagged pipeline-gated and
 * never built when the rest of the entity-rep foundation shipped (2026-08-15/16):
 * the document BODY clause naming an entity/estate party. Everything else in
 * that branch (recipient builder, signature-block caption) already renders
 * "herein represented by" correctly — this is what makes the CONTRACT TEXT
 * itself say it, e.g. Johan's own example: "the late estate of (seller)
 * herein represented by (executor)".
 */
final class EntityRepresentativePartyRenderingTest extends TestCase
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

    private function makeContact(array $attrs): Contact
    {
        return Contact::create(array_merge([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
        ], $attrs));
    }

    private function link(Contact $entity, Contact $rep, ?string $capacity, bool $proxy = false, bool $primary = false): void
    {
        ContactRepresentative::create([
            'entity_contact_id' => $entity->id, 'representative_contact_id' => $rep->id,
            'capacity' => $capacity, 'signs_as_proxy' => $proxy, 'is_primary' => $primary,
        ]);
    }

    private function seller(Contact $contact): SignatureRequest
    {
        $r = new SignatureRequest();
        $r->party_role = 'seller';
        $r->role_index = 1;
        $r->signer_name = $contact->full_name;
        $r->contact_id = $contact->id;
        return $r;
    }

    private function render(string $subName, Contact $contact): string
    {
        $html = '<div data-role-block="seller"><span data-field="seller_' . $subName . '">placeholder</span></div>';

        return app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, collect([$this->seller($contact)]),
        );
    }

    /** REGRESSION — natural-person rendering must be byte-identical to today. This is the one that would go unnoticed. */
    public function test_natural_person_name_surname_id_unchanged(): void
    {
        $person = $this->makeContact([
            'first_name' => 'Jo', 'last_name' => 'Soap', 'id_number' => '8001015800086',
        ]);

        $out = $this->render('name_surname_id', $person);

        $this->assertStringContainsString('Jo Soap (ID: 8001015800086)', $out);
        $this->assertStringNotContainsString('herein represented by', $out);
    }

    public function test_natural_person_full_name_unchanged(): void
    {
        $person = $this->makeContact(['first_name' => 'Jo', 'last_name' => 'Soap']);

        $out = $this->render('name', $person);

        $this->assertStringContainsString('Jo Soap', $out);
        $this->assertStringNotContainsString('(ID:', $out);
    }

    /** Deceased estate + Executor — Johan's literal example. */
    public function test_deceased_estate_herein_represented_by_executor(): void
    {
        $estate = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Estate Late John Smith',
            'entity_reg_no' => '1234/2026', 'first_name' => 'Estate Late John Smith', 'last_name' => '',
        ]);
        $executor = $this->makeContact([
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Jane', 'last_name' => 'Smith',
        ]);
        $this->link($estate, $executor, 'Executor', primary: true);

        $out = $this->render('name_surname_id', $estate);

        $this->assertStringContainsString(
            'Estate Late John Smith (Reg: 1234/2026), herein represented by Jane Smith (Executor)',
            $out
        );
    }

    /** Company + Director. */
    public function test_company_herein_represented_by_director(): void
    {
        $company = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Acme Properties (Pty) Ltd',
            'entity_reg_no' => '2015/123456/07', 'first_name' => 'Acme Properties (Pty) Ltd', 'last_name' => '',
        ]);
        $director = $this->makeContact([
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'John', 'last_name' => 'Director',
        ]);
        $this->link($company, $director, 'Director', primary: true);

        $out = $this->render('name_surname_id', $company);

        $this->assertStringContainsString(
            'Acme Properties (Pty) Ltd (Reg: 2015/123456/07), herein represented by John Director (Director)',
            $out
        );
    }

    /** Trust + Trustee. */
    public function test_trust_herein_represented_by_trustee(): void
    {
        $trust = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'The Smith Family Trust',
            'entity_reg_no' => 'IT1234/2020', 'first_name' => 'The Smith Family Trust', 'last_name' => '',
        ]);
        $trustee = $this->makeContact([
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Jane', 'last_name' => 'Trustee',
        ]);
        $this->link($trust, $trustee, 'Trustee', primary: true);

        $out = $this->render('name_surname_id', $trust);

        $this->assertStringContainsString(
            'The Smith Family Trust (Reg: IT1234/2020), herein represented by Jane Trustee (Trustee)',
            $out
        );
    }

    /** Proxy signer renders the distinct "duly authorised representative" wording. */
    public function test_proxy_representative_renders_proxy_wording(): void
    {
        $company = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Acme Properties (Pty) Ltd',
            'entity_reg_no' => '2015/123456/07', 'first_name' => 'Acme Properties (Pty) Ltd', 'last_name' => '',
        ]);
        $proxy = $this->makeContact([
            'contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Mary', 'last_name' => 'Proxy',
        ]);
        $this->link($company, $proxy, 'Director', proxy: true);

        $out = $this->render('name_surname_id', $company);

        $this->assertStringContainsString('duly authorised representative', $out);
        $this->assertStringContainsString('Mary Proxy', $out);
    }

    /**
     * Fault 3, round 5 (Johan, 2026-08-24) — this test's own name and
     * assertions used to encode the bug: multiple reps, none proxied, and
     * the clause named only the PRIMARY, silently dropping the others. That
     * was Johan's exact flow-280 finding — three real directors, one named
     * three times, two named nowhere — and this test asserted it as
     * correct. Display and signing are different questions: every
     * representative is named, always; only a flagged proxy changes who
     * SIGNS. Renamed and re-asserted to match.
     */
    public function test_multiple_reps_no_proxy_names_everyone(): void
    {
        $company = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Acme Properties (Pty) Ltd',
            'first_name' => 'Acme Properties (Pty) Ltd', 'last_name' => '',
        ]);
        $director1 = $this->makeContact(['contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'First', 'last_name' => 'Director']);
        $director2 = $this->makeContact(['contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Second', 'last_name' => 'Director']);
        $this->link($company, $director1, 'Director', primary: false);
        $this->link($company, $director2, 'Director', primary: true);

        $out = $this->render('name_surname_id', $company);

        $this->assertStringContainsString(
            'Acme Properties (Pty) Ltd, herein represented by First Director (Director) and Second Director (Director)',
            $out
        );
    }

    /** Entity with NO linked representative — the spec's own valid "scraper case". No dangling clause. */
    public function test_rep_less_entity_renders_name_only(): void
    {
        $entity = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Rep-less Pty',
            'entity_reg_no' => '2020/999999/07', 'first_name' => 'Rep-less Pty', 'last_name' => '',
        ]);

        $out = $this->render('name_surname_id', $entity);

        $this->assertStringContainsString('Rep-less Pty (Reg: 2020/999999/07)', $out);
        $this->assertStringNotContainsString('herein represented by', $out);
    }

    /** Blank entity_reg_no must not leave a dangling "(Reg: )" — the exact class of bug the spec warned about for id_number. */
    public function test_blank_reg_no_does_not_leave_dangling_parens(): void
    {
        $entity = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'No Reg Pty',
            'entity_reg_no' => null, 'first_name' => 'No Reg Pty', 'last_name' => '',
        ]);

        $out = $this->render('name_surname_id', $entity);

        $this->assertStringContainsString('No Reg Pty', $out);
        $this->assertStringNotContainsString('(Reg: )', $out);
        $this->assertStringNotContainsString('Reg:', $out);
    }

    /** Plain full_name (no identifying number) for an entity must NOT include the reg no — mirrors natural-person's plain name case. */
    public function test_entity_full_name_excludes_reg_no(): void
    {
        $company = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Acme Properties (Pty) Ltd',
            'entity_reg_no' => '2015/123456/07', 'first_name' => 'Acme Properties (Pty) Ltd', 'last_name' => '',
        ]);
        $director = $this->makeContact(['contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'John', 'last_name' => 'Director']);
        $this->link($company, $director, 'Director', primary: true);

        $out = $this->render('name', $company);

        $this->assertStringContainsString('Acme Properties (Pty) Ltd, herein represented by John Director (Director)', $out);
        $this->assertStringNotContainsString('Reg:', $out);
    }

    // ── Snapshot immutability (Johan, 2026-08-24 — the audit requirement) ──

    private function sellerWithSnapshot(Contact $contact, ?string $clauseText): SignatureRequest
    {
        $r = $this->seller($contact);
        $r->party_clause_text = $clauseText;
        return $r;
    }

    private function renderWithRecipient(string $subName, Contact $contact, SignatureRequest $recipient): string
    {
        $html = '<div data-role-block="seller"><span data-field="seller_' . $subName . '">placeholder</span></div>';

        return app(RoleBlockExpansionService::class)->expandWithLooping(
            null, $html, collect([$recipient]),
        );
    }

    /**
     * CRITICAL (Johan, 2026-08-24, "the part I care most about" / the audit
     * requirement in his later message): once a SignatureRequest carries a
     * resolved party_clause_text snapshot, it renders VERBATIM regardless of
     * what would be computed live now. This is the mechanism the future
     * recipient-template binding layer will also rely on — proven here
     * against the current EsignRecipientPreset-driven composition, since
     * the snapshot check in renderEntityParty() happens before any
     * particular composition strategy runs.
     */
    public function test_snapshot_survives_downstream_state_changing_after_generation(): void
    {
        $company = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Acme Ltd',
            'first_name' => 'Acme Ltd', 'last_name' => '',
        ]);
        $director = $this->makeContact(['contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'John', 'last_name' => 'Director']);
        $this->link($company, $director, 'Director', primary: true);

        // GENERATION TIME — resolve once, exactly as ESignWizardController::expandEntityRecipients() does.
        $originalText = app(RoleBlockExpansionService::class)->composeEntityPartyText($company);
        $this->assertStringContainsString('Acme Ltd, herein represented by John Director (Director)', $originalText);

        $recipient = $this->sellerWithSnapshot($company, $originalText);

        // Downstream state changes AFTER the snapshot was taken — the representative's
        // capacity is edited, and a second representative is marked primary instead.
        $company->representatives()->updateExistingPivot($director->id, ['capacity' => 'CEO', 'is_primary' => false]);
        $newPrimary = $this->makeContact(['contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'Jane', 'last_name' => 'Newcomer']);
        $this->link($company, $newPrimary, 'Director', primary: true);

        $out = $this->renderWithRecipient('name_surname_id', $company, $recipient);

        $this->assertStringContainsString($originalText, $out, 'The frozen snapshot must render, not a re-resolution against current state.');
        $this->assertStringNotContainsString('CEO', $out);
        $this->assertStringNotContainsString('Jane Newcomer', $out, 'A representative added AFTER generation must never appear in an already-generated document.');
    }

    /** Before generation (no SignatureRequest / no snapshot yet), rendering IS live — that is correct, not a bug: nothing has been "generated" yet to freeze. */
    public function test_pre_generation_preview_is_live_not_frozen(): void
    {
        $company = $this->makeContact([
            'contact_kind' => Contact::TYPE_ENTITY, 'entity_name' => 'Acme Ltd',
            'first_name' => 'Acme Ltd', 'last_name' => '',
        ]);
        $director = $this->makeContact(['contact_kind' => Contact::TYPE_NATURAL_PERSON, 'first_name' => 'John', 'last_name' => 'Director']);
        $this->link($company, $director, 'Director', primary: true);

        $before = $this->render('name_surname_id', $company); // no snapshot on this SignatureRequest
        $this->assertStringContainsString('John Director', $before);

        $company->representatives()->updateExistingPivot($director->id, ['capacity' => 'CEO']);

        $after = $this->render('name_surname_id', $company); // still no SignatureRequest snapshot — still pre-generation
        $this->assertStringContainsString('CEO', $after, 'Pre-generation preview should reflect current state — it is not frozen yet.');
    }
}
