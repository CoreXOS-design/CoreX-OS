<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Services\Docuperfect\RoleBlockExpansionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Johan, 2026-08-26 (authorised scope, cc2 verifying independently) — a
 * party with no captured address did not render a blank address line; a
 * null-value field used to SKIP replaceTextContent() entirely, silently
 * leaving whatever the clone inherited from the un-cloned SOURCE node —
 * which, for a multi-recipient role, WebTemplateDataService already joined
 * every OTHER recipient sharing the role into ONE flat value before any
 * cloning happened. Result: a party who captured no address/phone of their
 * own printed a DIFFERENT party's real home address and mobile number on
 * their own line of a signed legal document. Privacy breach + document-
 * integrity defect, not a display nicety.
 *
 * Covers RoleBlockExpansionService::mutateCloneForInstance()'s prefill —
 * the one place this decision is made, reached by every document type that
 * clones a role block per recipient (e-sign, wet-ink, the signing page, the
 * wizard preview, the composed/stored canonical HTML, the generated PDF —
 * traced via expandWithLooping()'s callers before this fix landed).
 * Exercised directly via reflection against a hand-built DOM fragment +
 * real persisted Contact/SignatureRequest fixtures, matching the AT-292
 * id_number fallback this change does not touch or weaken.
 */
final class RoleBlockNoDataRendersBlankTest extends TestCase
{
    use RefreshDatabase;

    private int $agencyId;
    private int $branchId;

    protected function setUp(): void
    {
        parent::setUp();
        $agency = Agency::create(['name' => 'Test Agency ' . uniqid(), 'slug' => 'test-agency-' . uniqid()]);
        $this->agencyId = (int) $agency->id;
        $this->branchId = (int) \Illuminate\Support\Facades\DB::table('branches')->insertGetId([
            'agency_id' => $this->agencyId, 'name' => 'Default',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeSignatureRequest(?string $address, ?string $phone): SignatureRequest
    {
        $contact = Contact::create([
            'agency_id' => $this->agencyId, 'branch_id' => $this->branchId,
            'contact_kind' => Contact::TYPE_NATURAL_PERSON,
            'first_name' => 'Test', 'last_name' => 'Party' . uniqid(),
            'email' => 'test.party' . uniqid() . '@example.test',
            'address' => $address, 'phone' => $phone,
        ]);

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
        $sigTemplate = SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64),
            'status' => SignatureTemplate::STATUS_SIGNING, 'created_by' => $creator->id,
        ]);

        return SignatureRequest::create([
            'signature_template_id' => $sigTemplate->id,
            'party_role' => 'seller', 'role_index' => 1, 'signing_order' => 1,
            'signer_name' => $contact->full_name, 'signer_email' => $contact->email,
            'signer_id_number' => null, 'token' => Str::random(64),
            'token_expires_at' => now()->addDays(14),
            'contact_id' => $contact->id,
        ]);
    }

    private function mutateClone(\DOMDocument $dom, \DOMElement $clone, ?SignatureRequest $recipient): void
    {
        $service = app(RoleBlockExpansionService::class);
        $m = new ReflectionMethod(RoleBlockExpansionService::class, 'mutateCloneForInstance');
        $m->setAccessible(true);
        $m->invoke($service, $dom, $clone, 'seller', 1, 1, $recipient, true, false, 1, false);
    }

    /** The exact bug: no address on file must render BLANK, never the stale/joined value already in the clone. */
    public function test_recipient_with_no_address_renders_blank_not_stale_value(): void
    {
        $recipient = $this->makeSignatureRequest(address: null, phone: null);

        $dom = new \DOMDocument();
        $dom->loadHTML('<div data-field="seller_address">STALE JOINED VALUE FROM ANOTHER PARTY</div>', LIBXML_NOERROR);
        $clone = $dom->getElementsByTagName('div')->item(0);

        $this->mutateClone($dom, $clone, $recipient);

        $this->assertSame('', trim($clone->textContent), 'A recipient with no address must render blank, never a stale/leaked value.');
    }

    /** A recipient WITH a real address is unaffected — still renders their own value. */
    public function test_recipient_with_address_still_renders_their_own_value(): void
    {
        $recipient = $this->makeSignatureRequest(address: '42 Real Street, Shelly Beach', phone: null);

        $dom = new \DOMDocument();
        $dom->loadHTML('<div data-field="seller_address">STALE JOINED VALUE FROM ANOTHER PARTY</div>', LIBXML_NOERROR);
        $clone = $dom->getElementsByTagName('div')->item(0);

        $this->mutateClone($dom, $clone, $recipient);

        $this->assertSame('42 Real Street, Shelly Beach', trim($clone->textContent));
    }

    /** Phone follows the identical rule. */
    public function test_recipient_with_no_phone_renders_blank_not_stale_value(): void
    {
        $recipient = $this->makeSignatureRequest(address: null, phone: null);

        $dom = new \DOMDocument();
        $dom->loadHTML('<div data-field="seller_phone">0000000000 (another party\'s number)</div>', LIBXML_NOERROR);
        $clone = $dom->getElementsByTagName('div')->item(0);

        $this->mutateClone($dom, $clone, $recipient);

        $this->assertSame('', trim($clone->textContent));
    }

    /** AT-292's id_number fallback (the one legitimate case) must survive untouched. */
    public function test_id_number_still_falls_back_to_the_recipients_own_typed_signer_id_number(): void
    {
        $recipient = $this->makeSignatureRequest(address: null, phone: null);
        $recipient->update(['signer_id_number' => '8001015800081']);

        $dom = new \DOMDocument();
        $dom->loadHTML('<div data-field="seller_id_number">stale</div>', LIBXML_NOERROR);
        $clone = $dom->getElementsByTagName('div')->item(0);

        $this->mutateClone($dom, $clone, $recipient);

        $this->assertSame('8001015800081', trim($clone->textContent), 'The recipient\'s own typed ID must still win over blank.');
    }

    /** When even the id_number fallback has nothing, ID also renders blank rather than leaking. */
    public function test_id_number_with_nothing_anywhere_renders_blank(): void
    {
        $recipient = $this->makeSignatureRequest(address: null, phone: null);
        // signer_id_number left null.

        $dom = new \DOMDocument();
        $dom->loadHTML('<div data-field="seller_id_number">STALE ID FROM ANOTHER PARTY</div>', LIBXML_NOERROR);
        $clone = $dom->getElementsByTagName('div')->item(0);

        $this->mutateClone($dom, $clone, $recipient);

        $this->assertSame('', trim($clone->textContent));
    }
}
