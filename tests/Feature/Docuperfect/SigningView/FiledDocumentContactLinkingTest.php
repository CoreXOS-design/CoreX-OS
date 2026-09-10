<?php

declare(strict_types=1);

namespace Tests\Feature\Docuperfect\SigningView;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Contact;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template as DocuperfectTemplate;
use App\Models\Property;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * cc4, 2026-09-03 — Johan's headline e-sign demo ("sign a document, watch it
 * appear on BOTH the property AND the contact") broke live: filed documents
 * for packs 16/17/18 showed zero contact-side links. Root cause:
 * SignatureService::resolveSigningContacts() resolved the filed document's
 * contact purely by matching signer_email text against the contact's
 * current stored email, discarding signature_requests.contact_id — the
 * authoritative signal set at send time whenever the agent actually bound a
 * real Contact. The recipient card's email stays editable after that
 * binding (see createSignatureRequest()'s own docblock), so any drift
 * between the two silently dropped the contact-side link even though the
 * system already knew exactly who signed.
 *
 * Of the 7 signature_requests system-wide that had contact_id set, 6 had a
 * mismatched signer_email and were all silently unlinked before this fix.
 */
final class FiledDocumentContactLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function invokePrivate(object $object, string $method, array $args)
    {
        $ref = new \ReflectionMethod($object, $method);
        $ref->setAccessible(true);

        return $ref->invoke($object, ...$args);
    }

    /** @return array{agent:User, property:Property, document:Document, template:SignatureTemplate, contact:Contact} */
    private function fixture(): array
    {
        $agencyId = (int) Agency::create(['name' => 'ZZZ Contact Link Test Agency ' . Str::random(6), 'slug' => 'zzz-contact-link-' . Str::random(8)])->id;
        $branchId = (int) Branch::create(['agency_id' => $agencyId, 'name' => 'ZZZ Contact Link Test Branch'])->id;
        $agent = User::factory()->create([
            'name' => 'ZZZ Contact Link Test Agent', 'role' => 'agent',
            'agency_id' => $agencyId, 'branch_id' => $branchId, 'is_active' => true,
        ]);
        $property = Property::create([
            'external_id' => (string) Str::uuid(), 'agent_id' => $agent->id, 'branch_id' => $branchId,
            'title' => 'ZZZ Contact Link Test Property', 'address' => '1 Contact Link Way',
        ]);
        $contact = Contact::create([
            'agency_id' => $agencyId, 'first_name' => 'Real', 'last_name' => 'Seller',
            'email' => 'real.seller@example.com',
        ]);

        $docTmpl = DocuperfectTemplate::create([
            'name' => 'ZZZ CONTACT LINK MANDATE', 'render_type' => 'web', 'template_type' => 'cds',
            'category' => 'sales', 'blade_view' => 'x', 'signing_parties' => ['agent', 'seller'],
            'field_mappings' => [], 'owner_id' => $agent->id, 'agency_id' => $agencyId,
        ]);
        $document = Document::create([
            'name' => 'ZZZ CONTACT LINK MANDATE — ' . $property->address . ' — ' . now()->format('d-m-y'),
            'document_type' => 'mandate', 'agency_id' => $agencyId,
            'owner_id' => $agent->id, 'template_id' => $docTmpl->id, 'property_id' => $property->id,
            'web_template_data' => ['merged_html' => '<div class="corex-document-wrapper"><p>Body</p></div>'],
        ]);
        $template = SignatureTemplate::create([
            'document_id' => $document->id, 'document_hash' => Str::random(64), 'agency_id' => $agencyId,
            'status' => SignatureTemplate::STATUS_COMPLETED, 'created_by' => $agent->id,
        ]);

        return ['agent' => $agent, 'property' => $property, 'document' => $document, 'template' => $template, 'contact' => $contact];
    }

    public function test_contact_id_wins_over_a_mismatched_signer_email(): void
    {
        ['template' => $template, 'contact' => $contact] = $this->fixture();

        // The exact shape of the bug: a real Contact was bound at send time
        // (contact_id set), but the recipient card's email — independently
        // editable — reads something else entirely by completion time.
        SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role' => 'seller',
            'signing_order' => 1,
            'signer_name' => 'Real Seller',
            'signer_email' => 'stale-typed-email@example.com',
            'contact_id' => $contact->id,
            'token' => Str::random(40),
            'token_expires_at' => now()->addDays(14),
            'status' => 'completed',
        ]);

        $svc = app(\App\Services\Docuperfect\SignatureService::class);
        $links = $this->invokePrivate($svc, 'resolveSigningContacts', [$template]);

        $this->assertSame([$contact->id => 'seller'], $links, 'contact_id must win even though signer_email does not match the contact record');
    }

    public function test_a_genuinely_unbound_signer_still_resolves_by_email(): void
    {
        ['template' => $template, 'contact' => $contact] = $this->fixture();

        // No contact_id at all — the free-text-signer path this fix must not break.
        SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role' => 'seller',
            'signing_order' => 1,
            'signer_name' => 'Real Seller',
            'signer_email' => $contact->email,
            'contact_id' => null,
            'token' => Str::random(40),
            'token_expires_at' => now()->addDays(14),
            'status' => 'completed',
        ]);

        $svc = app(\App\Services\Docuperfect\SignatureService::class);
        $links = $this->invokePrivate($svc, 'resolveSigningContacts', [$template]);

        $this->assertSame([$contact->id => 'seller'], $links, 'email-based resolution must still work for an unbound signer');
    }

    public function test_filed_document_links_to_the_bound_contact_despite_mismatched_email(): void
    {
        ['property' => $property, 'document' => $document, 'template' => $template, 'contact' => $contact] = $this->fixture();

        SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role' => 'seller',
            'signing_order' => 1,
            'signer_name' => 'Real Seller',
            'signer_email' => 'stale-typed-email@example.com',
            'contact_id' => $contact->id,
            'token' => Str::random(40),
            'token_expires_at' => now()->addDays(14),
            'status' => 'completed',
        ]);

        Storage::fake('local');
        Storage::disk('local')->put('zzz-contact-link.pdf', 'fake-pdf-bytes');

        $svc = app(\App\Services\Docuperfect\SignatureService::class);
        $template->load('requests');
        $contactLinks = $this->invokePrivate($svc, 'resolveSigningContacts', [$template]);
        $filed = $this->invokePrivate($svc, 'fileSingleDocument', [$template, $document, 'zzz-contact-link.pdf', $property->id, $contactLinks]);

        $this->assertNotNull($filed);
        $filedDoc = \App\Models\Document::where('storage_path', 'zzz-contact-link.pdf')->where('source_type', 'esign')->firstOrFail();
        $this->assertTrue(
            $filedDoc->contacts()->where('contact_id', $contact->id)->exists(),
            'the filed document must appear on the real seller contact, not silently unlinked'
        );
    }
}
