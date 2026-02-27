<?php

namespace App\Console\Commands;

use App\Models\Docuperfect\Document;
use App\Models\Docuperfect\Signature;
use App\Models\Docuperfect\SignatureMarker;
use App\Models\Docuperfect\SignatureRequest;
use App\Models\Docuperfect\SignatureTemplate;
use App\Services\Docuperfect\DocumentFlattener;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class ResetDocumentSigning extends Command
{
    protected $signature = 'docuperfect:reset-signing
                            {document_id : The ID of the docuperfect_documents record}
                            {--to=setup : Reset stage: setup, agent-signed, or tenant-signed}';

    protected $description = 'Reset a document\'s signing state for testing purposes';

    public function handle(): int
    {
        $documentId = $this->argument('document_id');
        $stage = $this->option('to');

        if (!in_array($stage, ['setup', 'agent-signed', 'tenant-signed'])) {
            $this->error("Invalid stage: {$stage}. Must be one of: setup, agent-signed, tenant-signed");
            return 1;
        }

        $document = Document::find($documentId);
        if (!$document) {
            $this->error("Document #{$documentId} not found.");
            return 1;
        }

        $template = SignatureTemplate::where('document_id', $document->id)->first();
        if (!$template) {
            $this->error("No signature template found for document #{$documentId}.");
            return 1;
        }

        // Show current state
        $this->showState($document, $template, 'Current State');

        // Confirm
        if (!$this->confirm("Reset document #{$documentId} \"{$document->name}\" to '{$stage}'?")) {
            $this->info('Aborted.');
            return 0;
        }

        // Execute reset
        match ($stage) {
            'setup' => $this->resetToSetup($template),
            'agent-signed' => $this->resetToAgentSigned($template),
            'tenant-signed' => $this->resetToTenantSigned($template),
        };

        // Refresh and show new state
        $template->refresh();
        $this->newLine();
        $this->showState($document, $template, 'New State');

        $this->info('Reset complete.');
        return 0;
    }

    private function showState(Document $document, SignatureTemplate $template, string $label): void
    {
        $template->loadMissing(['requests', 'markers', 'signatures']);

        $this->info("=== {$label} ===");
        $this->table(
            ['Field', 'Value'],
            [
                ['Document', "#{$document->id} — {$document->name}"],
                ['Template Status', $template->status],
                ['Requests', $template->requests->count()],
                ['Markers', $template->markers->count()],
                ['Signatures', $template->signatures->count()],
                ['Has Flattened', !empty($template->flattened_pages_json) ? 'Yes' : 'No'],
                ['Signed PDF', $template->signed_pdf_path ? 'Yes' : 'No'],
            ]
        );

        if ($template->requests->isNotEmpty()) {
            $rows = $template->requests->map(fn($r) => [
                $r->party_role,
                $r->signer_name,
                $r->status,
                $r->signing_method ?? '-',
                $r->completed_at?->format('d M Y H:i') ?? '-',
            ])->toArray();

            $this->table(
                ['Party', 'Name', 'Status', 'Method', 'Completed'],
                $rows
            );
        }
    }

    /**
     * Reset to "setup" — before any signing started.
     */
    private function resetToSetup(SignatureTemplate $template): void
    {
        $this->line('Resetting to setup...');

        // Delete all signatures
        $sigCount = Signature::where('signature_template_id', $template->id)->delete();
        $this->line("  Deleted {$sigCount} signatures");

        // Delete all signing requests
        $reqCount = $template->requests()->delete();
        $this->line("  Deleted {$reqCount} signing requests");

        // Delete flattened images from storage
        $this->deleteFlattened($template);

        // Delete signed PDF
        $this->deleteSignedPdf($template);

        // Reset template state
        $template->update([
            'status' => SignatureTemplate::STATUS_DRAFT,
            'flattened_pages_json' => null,
            'signed_pdf_path' => null,
            'signed_pdf_client_path' => null,
            'completed_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'rejected_by' => null,
        ]);

        $this->line('  Template status set to: draft');
    }

    /**
     * Reset to "agent-signed" — after agent signed but before tenant.
     */
    private function resetToAgentSigned(SignatureTemplate $template): void
    {
        $this->line('Resetting to agent-signed...');

        // Delete non-agent signatures
        $nonAgentMarkerIds = $template->markers()
            ->where('assigned_party', '!=', 'agent')
            ->pluck('id');

        $sigCount = Signature::where('signature_template_id', $template->id)
            ->whereIn('signature_marker_id', $nonAgentMarkerIds)
            ->delete();
        $this->line("  Deleted {$sigCount} non-agent signatures");

        // Delete non-agent signing requests
        $reqCount = $template->requests()
            ->where('party_role', '!=', 'agent')
            ->delete();
        $this->line("  Deleted {$reqCount} non-agent signing requests");

        // Reset remaining agent requests
        $template->requests()
            ->where('party_role', 'agent')
            ->update([
                'reminder_count' => 0,
                'reminder_sent_at' => null,
            ]);

        // Delete flattened images and re-flatten with agent entries only
        $this->deleteFlattened($template);
        $this->deleteSignedPdf($template);

        $template->update([
            'flattened_pages_json' => null,
            'signed_pdf_path' => null,
            'signed_pdf_client_path' => null,
            'completed_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'rejected_by' => null,
            'status' => SignatureTemplate::STATUS_AWAITING_TENANT,
        ]);

        // Re-flatten with agent's entries
        $this->reflatten($template, ['agent']);

        $this->line('  Template status set to: awaiting_tenant');
    }

    /**
     * Reset to "tenant-signed" — after tenant signed but before landlord.
     */
    private function resetToTenantSigned(SignatureTemplate $template): void
    {
        $this->line('Resetting to tenant-signed...');

        // Delete landlord signatures
        $landlordMarkerIds = $template->markers()
            ->where('assigned_party', 'landlord')
            ->pluck('id');

        $sigCount = Signature::where('signature_template_id', $template->id)
            ->whereIn('signature_marker_id', $landlordMarkerIds)
            ->delete();
        $this->line("  Deleted {$sigCount} landlord signatures");

        // Delete landlord signing requests
        $reqCount = $template->requests()
            ->where('party_role', 'landlord')
            ->delete();
        $this->line("  Deleted {$reqCount} landlord signing requests");

        // Reset reminder counts on remaining requests
        $template->requests()
            ->whereIn('party_role', ['agent', 'tenant'])
            ->update([
                'reminder_count' => 0,
                'reminder_sent_at' => null,
            ]);

        // Delete flattened images and re-flatten with agent + tenant entries
        $this->deleteFlattened($template);
        $this->deleteSignedPdf($template);

        $template->update([
            'flattened_pages_json' => null,
            'signed_pdf_path' => null,
            'signed_pdf_client_path' => null,
            'completed_at' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'rejected_by' => null,
            'status' => SignatureTemplate::STATUS_AWAITING_LANDLORD,
        ]);

        // Re-flatten with agent + tenant entries
        $this->reflatten($template, ['agent', 'tenant']);

        $this->line('  Template status set to: awaiting_landlord');
    }

    /**
     * Delete flattened page images from storage.
     */
    private function deleteFlattened(SignatureTemplate $template): void
    {
        $flattenedPages = $template->flattened_pages_json ?? [];
        $deleted = 0;
        foreach ($flattenedPages as $path) {
            if ($path && Storage::disk('local')->exists($path)) {
                Storage::disk('local')->delete($path);
                $deleted++;
            }
        }
        $this->line("  Deleted {$deleted} flattened page images");
    }

    /**
     * Delete signed PDF from storage.
     */
    private function deleteSignedPdf(SignatureTemplate $template): void
    {
        if ($template->signed_pdf_path && Storage::disk('local')->exists($template->signed_pdf_path)) {
            Storage::disk('local')->delete($template->signed_pdf_path);
            $this->line('  Deleted signed PDF');
        }
    }

    /**
     * Re-flatten page images with only the specified parties' signatures.
     */
    private function reflatten(SignatureTemplate $template, array $parties): void
    {
        $template->refresh();
        $flattener = app(DocumentFlattener::class);

        // First flatten document fields (text, dates, selections — not signatures)
        $flattener->flattenFields($template);
        $template->refresh();

        // Then flatten signatures for each specified party
        foreach ($parties as $party) {
            $markers = $template->markers()
                ->where('assigned_party', $party)
                ->with('signatures')
                ->get();

            foreach ($markers as $marker) {
                $sig = $marker->signatures->first();
                if ($sig) {
                    $template->refresh();
                    $flattener->flattenSignature($template, $marker, $sig);
                }
            }
        }

        $this->line('  Re-flattened with entries from: ' . implode(', ', $parties));
    }
}
