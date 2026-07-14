<?php

declare(strict_types=1);

namespace Tests\Feature\ESign;

use App\Mail\Signatures\SignedDocumentMail;
use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\SignatureAuditLog;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionMethod;
use Tests\TestCase;

/**
 * HD-7 / P2-4 — a pack signed as ONE ceremony is distributed as the MANY documents it really is.
 *
 * THE DEFECT WAS A SEQUENCE, NOT A MAPPING. completeDocument() emailed the parties at step 3 and
 * filed the per-document PDFs at step 5. So when the emails were written, the only file that existed
 * was the single merged PDF of the whole pack. Nobody ever decided to staple a Mandate to a
 * Disclosure and send it as one document — the ceremony sent the only thing that existed yet. Filing
 * now runs BEFORE distribution and hands back what it wrote, and the reorder IS the fix.
 *
 * THE POPIA GUARD. A pack carries supporting ATTACHMENT slots as well as signed documents — a party's
 * FICA evidence, an ID copy. Those are attached TO the ceremony; they are not products OF it. Once
 * distribution loops a pack, mailing one to every other signer is one iteration away — so the loop
 * filters on an explicit `is_signed_document` marker rather than on the luck of what happens to be in
 * the array. That refusal is tested here, because "it works today" is not a control.
 */
final class CompletionDistributionTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;
    private SignatureService $service;
    private ReflectionMethod $send;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Storage::fake('local');

        $agency = Agency::create(['name' => 'Home Finders Coastal', 'slug' => 'hfc-' . uniqid()]);
        $branch = Branch::create(['agency_id' => $agency->id, 'name' => 'Ramsgate']);
        $this->agent = User::factory()->create([
            'agency_id' => $agency->id,
            'branch_id' => $branch->id,
            'role'      => 'agent',
        ]);
        $this->actingAs($this->agent);

        $this->service = app(SignatureService::class);
        $this->send = new ReflectionMethod(SignatureService::class, 'sendCompletionEmails');
        $this->send->setAccessible(true);
    }

    /** A signed ceremony with two completed sellers. */
    private function ceremony(): SignatureTemplate
    {
        $document = Document::create([
            'name'     => 'Sales Mandate Pack — 27 Marine Drive, Ramsgate',
            'owner_id' => $this->agent->id,
        ]);

        $template = SignatureTemplate::create([
            'document_id'   => $document->id,
            'document_hash' => Str::random(64),
            'status'        => SignatureTemplate::STATUS_COMPLETED,
            'created_by'    => $this->agent->id,
        ]);

        foreach ([['Johan Muller', 1], ['Marlene Muller', 2]] as [$name, $order]) {
            SignatureRequest::create([
                'signature_template_id' => $template->id,
                'party_role'            => 'seller',
                'role_index'            => $order,
                'signing_order'         => $order,
                'signer_name'           => $name,
                'signer_email'          => Str::slug($name) . '@example.co.za',
                'token'                 => Str::random(48),
                'token_expires_at'      => now()->addDays(14),
                'status'                => SignatureRequest::STATUS_COMPLETED,
                'completed_at'          => now(),
            ]);
        }

        return $template;
    }

    /** Write a fake signed PDF onto the faked local disk and return its disk-relative path. */
    private function pdf(string $path): string
    {
        Storage::disk('local')->put($path, '%PDF-1.4 fake');

        return $path;
    }

    /**
     * THE FIX. Two signed documents → two attachments, each under its own name. Not one stapled PDF.
     */
    public function test_a_pack_is_distributed_as_many_documents_not_one_merged_file(): void
    {
        $template = $this->ceremony();
        $merged = $this->pdf('docuperfect/signed/merged_client.pdf');

        $signedDocuments = [
            ['path' => $this->pdf('docuperfect/individual/1_client.pdf'), 'name' => 'Sole Mandate (Signed).pdf',      'template_id' => 1, 'is_signed_document' => true],
            ['path' => $this->pdf('docuperfect/individual/2_client.pdf'), 'name' => 'Seller Disclosure (Signed).pdf', 'template_id' => 2, 'is_signed_document' => true],
        ];

        $this->send->invoke($this->service, $template, ['client' => $merged, 'internal' => $merged], $signedDocuments);

        Mail::assertSent(SignedDocumentMail::class, function (SignedDocumentMail $mail) {
            $names = array_column($mail->documents, 'name');

            return count($mail->documents) === 2
                && in_array('Sole Mandate (Signed).pdf', $names, true)
                && in_array('Seller Disclosure (Signed).pdf', $names, true)
                // and the mailable really renders them as two separate attachments
                && count($mail->attachments()) === 2;
        });

        // One to each completed seller.
        Mail::assertSent(SignedDocumentMail::class, 2);
    }

    /**
     * THE POPIA GUARD. A supporting attachment (one party's FICA evidence) is filed against the
     * ceremony but is NOT a document the parties signed — it must never be mailed to the others.
     */
    public function test_a_supporting_attachment_is_never_distributed_to_the_other_parties(): void
    {
        $template = $this->ceremony();
        $merged = $this->pdf('docuperfect/signed/merged_client.pdf');

        $signedDocuments = [
            ['path' => $this->pdf('docuperfect/individual/1_client.pdf'), 'name' => 'Sole Mandate (Signed).pdf', 'template_id' => 1, 'is_signed_document' => true],
            // Johan's FICA evidence — attached TO the ceremony, not a product OF it.
            ['path' => $this->pdf('docuperfect/individual/fica.pdf'), 'name' => 'Johan Muller — FICA ID.pdf', 'template_id' => null, 'is_signed_document' => false],
        ];

        $this->send->invoke($this->service, $template, ['client' => $merged, 'internal' => $merged], $signedDocuments);

        Mail::assertSent(SignedDocumentMail::class, function (SignedDocumentMail $mail) {
            $names = array_column($mail->documents, 'name');

            return count($mail->documents) === 1
                && $names === ['Sole Mandate (Signed).pdf']
                && ! in_array('Johan Muller — FICA ID.pdf', $names, true);
        });
    }

    /** The evidence timeline must answer "was the Disclosure sent to Marlene?" — not just "was something sent?". */
    public function test_one_audit_row_is_written_per_document_per_recipient(): void
    {
        $template = $this->ceremony();
        $merged = $this->pdf('docuperfect/signed/merged_client.pdf');

        $signedDocuments = [
            ['path' => $this->pdf('docuperfect/individual/1_client.pdf'), 'name' => 'Sole Mandate (Signed).pdf',      'template_id' => 1, 'is_signed_document' => true],
            ['path' => $this->pdf('docuperfect/individual/2_client.pdf'), 'name' => 'Seller Disclosure (Signed).pdf', 'template_id' => 2, 'is_signed_document' => true],
        ];

        $this->send->invoke($this->service, $template, ['client' => $merged, 'internal' => $merged], $signedDocuments);

        $rows = SignatureAuditLog::where('signature_template_id', $template->id)
            ->where('action', SignatureAuditLog::ACTION_SIGNED_PDF_EMAILED)
            ->get();

        // 2 documents × 2 recipients.
        $this->assertCount(4, $rows);

        $marleneDisclosure = $rows->first(fn ($r) => ($r->metadata_json['recipient_name'] ?? null) === 'Marlene Muller'
            && ($r->metadata_json['document_name'] ?? null) === 'Seller Disclosure (Signed).pdf');

        $this->assertNotNull($marleneDisclosure,
            'The timeline must be able to prove the Disclosure specifically reached Marlene.');
    }

    /** A single-template signing still sends its one merged document — that is the honest answer, not a degraded path. */
    public function test_a_single_document_signing_falls_back_to_the_merged_copy(): void
    {
        $template = $this->ceremony();
        $merged = $this->pdf('docuperfect/signed/merged_client.pdf');

        $this->send->invoke($this->service, $template, ['client' => $merged, 'internal' => $merged], []);

        Mail::assertSent(SignedDocumentMail::class, function (SignedDocumentMail $mail) {
            return count($mail->attachments()) === 1;
        });
    }

    /** A filed document whose PDF has vanished must not block delivery of the ones that exist. */
    public function test_a_missing_pdf_is_skipped_rather_than_failing_the_send(): void
    {
        $template = $this->ceremony();
        $merged = $this->pdf('docuperfect/signed/merged_client.pdf');

        $signedDocuments = [
            ['path' => $this->pdf('docuperfect/individual/1_client.pdf'), 'name' => 'Sole Mandate (Signed).pdf', 'template_id' => 1, 'is_signed_document' => true],
            ['path' => 'docuperfect/individual/gone.pdf', 'name' => 'Vanished (Signed).pdf', 'template_id' => 2, 'is_signed_document' => true],
        ];

        $this->send->invoke($this->service, $template, ['client' => $merged, 'internal' => $merged], $signedDocuments);

        Mail::assertSent(SignedDocumentMail::class, function (SignedDocumentMail $mail) {
            return count($mail->documents) === 1
                && $mail->documents[0]['name'] === 'Sole Mandate (Signed).pdf';
        });
    }

    /** The agent is never emailed the client copies — they get the in-app notification. */
    public function test_the_agent_is_not_emailed_the_client_copies(): void
    {
        $template = $this->ceremony();
        SignatureRequest::create([
            'signature_template_id' => $template->id,
            'party_role'            => 'agent',
            'role_index'            => 1,
            'signing_order'         => 0,
            'signer_name'           => $this->agent->name,
            'signer_email'          => $this->agent->email,
            'token'                 => Str::random(48),
            'token_expires_at'      => now()->addDays(14),
            'status'                => SignatureRequest::STATUS_COMPLETED,
            'completed_at'          => now(),
        ]);

        $merged = $this->pdf('docuperfect/signed/merged_client.pdf');
        $this->send->invoke($this->service, $template, ['client' => $merged, 'internal' => $merged], []);

        Mail::assertNotSent(SignedDocumentMail::class, fn (SignedDocumentMail $m) => $m->recipientName === $this->agent->name);
        Mail::assertSent(SignedDocumentMail::class, 2); // the two sellers only
    }
}
