<?php

namespace App\Console\Commands\Docuperfect;

use App\Models\Agency;
use App\Models\Branch;
use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\DocumentType;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Models\Docuperfect\Template;
use App\Models\User;
use App\Services\Docuperfect\SignatureService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Builds a fully synthetic render_type='web' signing fixture — agency, agent, document,
 * template, signature template, two completed signature requests — and, with --complete,
 * drives it through SignatureService::completeDocument() directly.
 *
 * Exists because neither staging nor live has ever had a completed render_type='web'
 * signature template (checked directly, both zero) — there is no real data anywhere to
 * test the e-sign async-completion path against, and none to anonymise/copy either.
 * Entirely synthetic by construction, so it carries zero real client data risk. Reusable
 * for any future work on this pipeline, not a one-off script.
 *
 * Every identifier and email uses a fixture-tagged / .invalid domain so a real SMTP send
 * attempt (staging's MAIL_MAILER is smtp) can never reach a real inbox.
 */
class MakeSigningFixtureCommand extends Command
{
    protected $signature = 'docuperfect:make-signing-fixture
        {--complete : Immediately drive the fixture through SignatureService::completeDocument()}
        {--pages=6 : Number of contract-shaped pages to seed into merged_html/canonical_html}';

    protected $description = 'Create a synthetic render_type=web signing fixture (agency/document/template/requests) for testing the e-sign completion path';

    public function handle(SignatureService $signatureService): int
    {
        $agency = Agency::firstOrCreate(
            ['slug' => 'esign-async-fixture'],
            ['name' => 'ESIGN-ASYNC-FIXTURE Agency'],
        );

        $branch = Branch::firstOrCreate(
            ['agency_id' => $agency->id, 'name' => 'Fixture Branch'],
            [],
        );

        $agent = User::firstOrCreate(
            ['email' => 'esign-async-fixture-agent@example.invalid'],
            [
                'name' => 'Fixture Agent',
                'agency_id' => $agency->id,
                'branch_id' => $branch->id,
                'role' => 'agent',
                'password' => bcrypt(Str::random(32)),
            ],
        );

        $docType = DocumentType::where('slug', 'addendum')->first();

        $pages = (int) $this->option('pages');
        $html = $this->buildContractHtml($pages);

        $template = Template::create([
            'agency_id' => $agency->id,
            'name' => 'ESIGN-ASYNC-FIXTURE Addendum ' . now()->format('Y-m-d H:i:s'),
            'template_type' => 'addendum',
            'render_type' => 'web',
            'document_type_id' => $docType?->id,
            'page_count' => $pages,
            'is_esign' => true,
            'owner_id' => $agent->id,
        ]);

        $document = Document::create([
            'name' => 'ESIGN-ASYNC-FIXTURE Signed Addendum',
            'agency_id' => $agency->id,
            'owner_id' => $agent->id,
            'branch_id' => $branch->id,
            'template_id' => $template->id,
            'document_type' => 'addendum',
            'document_hash' => Str::random(64),
            'web_template_data' => [
                'canonical_html' => $html,
                'merged_html' => $html,
                'canonical_version' => 1,
                'template_ids' => [$template->id],
            ],
            'signed_paginated_html' => $html,
        ]);

        $signatureTemplate = SignatureTemplate::create([
            'agency_id' => $agency->id,
            'document_id' => $document->id,
            'document_hash' => $document->document_hash,
            'status' => SignatureTemplate::STATUS_SIGNING,
            'created_by' => $agent->id,
        ]);

        SignatureRequest::create([
            'signature_template_id' => $signatureTemplate->id,
            'party_role' => 'agent',
            'role_index' => 1,
            'signing_order' => 1,
            'signer_name' => 'Fixture Agent',
            'signer_email' => 'esign-async-fixture-agent@example.invalid',
            'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14),
            'status' => SignatureRequest::STATUS_COMPLETED,
            'completed_at' => now(),
            'sent_by' => $agent->id,
        ]);

        $sellerRequest = SignatureRequest::create([
            'signature_template_id' => $signatureTemplate->id,
            'party_role' => 'seller',
            'role_index' => 1,
            'signing_order' => 2,
            'signer_name' => 'Fixture Seller',
            'signer_email' => 'esign-async-fixture-seller@example.invalid',
            'signer_id_number' => '0000000000000',
            'token' => Str::random(48),
            'token_expires_at' => now()->addDays(14),
            'status' => SignatureRequest::STATUS_COMPLETED,
            'completed_at' => now(),
            'sent_by' => $agent->id,
        ]);

        $this->info("Fixture created:");
        $this->line("  signature_template_id = {$signatureTemplate->id}");
        $this->line("  document_id            = {$document->id}");
        $this->line("  download token (seller) = {$sellerRequest->token}");
        $this->line("  async_completion flag   = " . (config('docuperfect.async_completion') ? 'ON' : 'OFF'));
        $this->line("  async_completion_pdf_sync = " . (config('docuperfect.async_completion_pdf_sync') ? 'ON' : 'OFF'));

        if ($this->option('complete')) {
            $this->line('Calling SignatureService::completeDocument() ...');
            $t0 = microtime(true);
            $signatureService->completeDocument($signatureTemplate);
            $elapsedMs = (int) round((microtime(true) - $t0) * 1000);

            $signatureTemplate->refresh();
            $this->info("completeDocument() returned in {$elapsedMs}ms (agent-visible response time under this flag combination)");
            $this->line("  status                     = {$signatureTemplate->status}");
            $this->line("  signed_pdf_path            = " . ($signatureTemplate->signed_pdf_path ?? '(not set)'));
            $this->line("  signed_pdf_client_path     = " . ($signatureTemplate->signed_pdf_client_path ?? '(not set)'));
            $this->line("  completion_emails_sent_at  = " . ($signatureTemplate->completion_emails_sent_at ?? '(not set)'));

            $linkedContacts = \Illuminate\Support\Facades\DB::table('document_contact')
                ->where('document_id', $document->id)->count();
            $filedDocs = \App\Models\Document::where('source_type', 'esign')->where('source_id', $signatureTemplate->id)->count();
            $this->line("  document_contact rows      = {$linkedContacts}");
            $this->line("  filed Document rows        = {$filedDocs}");
        }

        return self::SUCCESS;
    }

    private function buildContractHtml(int $pages): string
    {
        $body = '';
        for ($i = 1; $i <= $pages; $i++) {
            $body .= "<div class=\"corex-a4-page\"><h1>Addendum — Clause Set {$i}</h1>";
            for ($p = 1; $p <= 5; $p++) {
                $body .= "<p>Clause {$i}.{$p}: fixture text for the ESIGN-ASYNC-FIXTURE staging test, not a real agreement.</p>";
            }
            $body .= '</div>';
        }

        return '<!DOCTYPE html><html><head><meta charset="utf-8">'
            . '<style>.corex-a4-page{page-break-after:always;padding:20px;} body{font-family:Arial,sans-serif;font-size:12px;}</style>'
            . "</head><body>{$body}</body></html>";
    }
}
