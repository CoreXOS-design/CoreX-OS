<?php

namespace App\Jobs;

use App\Models\Agency;
use App\Models\Communications\Communication;
use App\Models\Communications\CommunicationLink;
use App\Models\Compliance\AgencyComplianceProvision;
use App\Models\Compliance\AgencyTransformationNote;
use App\Models\Compliance\PpraInspectionPack;
use App\Models\Deal;
use App\Models\Document;
use App\Models\FicaDocument;
use App\Models\Lease;
use App\Models\Property;
use App\Models\User;
use App\Notifications\PpraPackReadyNotification;
use App\Services\CommandCenter\NotificationDispatcher;
use App\Services\Compliance\PpraInspectionPackChecklistService;
use App\Services\Compliance\PpraInspectionReportPdfService;
use App\Services\Compliance\PpraLetterheadSampleService;
use App\Services\Compliance\PpraMandateFileAggregationService;
use App\Services\Compliance\PpraRentalFileAggregationService;
use App\Services\Compliance\PpraSalesFileAggregationService;
use App\Services\Compliance\PractitionerFfcRosterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * PPRA Inspection Pack Phase J — .ai/specs/ppra-inspection-pack.md §6.9.
 * Queued (bundling real files is slow); the Inspection Report itself
 * (§6.2) stays synchronous elsewhere. Bundles the Report + every source
 * document from Phases A-I around the same manifests items k/l/m's own
 * aggregation services already produce for the Report's per-file index —
 * this job is the one place that actually WRITES a ZIP from them, per
 * those services' own docblocks ("this phase does not write one").
 *
 * QA note (spec's own): QA1 runs no queue worker. This job's first REAL
 * queued-run QA happens on Staging — a QA1-only sync-dispatch smoke test
 * proves the logic doesn't crash on real data, not that queued execution
 * itself works end to end.
 */
class GeneratePpraInspectionPackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Bundling real files + a puppeteer render can far exceed the worker's 60s default. */
    public int $timeout = 900;

    public int $tries = 1;

    public bool $failOnTimeout = true;

    public function __construct(private int $packId)
    {
    }

    /**
     * Called by the queue on timeout / kill / max-attempts — the catch block
     * in handle() only covers PHP exceptions, so without this a killed job
     * would leave the pack 'generating' forever and block regeneration.
     */
    public function failed(\Throwable $e): void
    {
        $pack = PpraInspectionPack::withoutGlobalScopes()->find($this->packId);
        if ($pack && $pack->status !== 'ready') {
            $pack->update(['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    public function handle(
        PpraInspectionReportPdfService $reportPdf,
        PpraInspectionPackChecklistService $checklist,
        PractitionerFfcRosterService $practitionerRoster,
        PpraLetterheadSampleService $letterheadPdf,
        PpraSalesFileAggregationService $salesAgg,
        PpraRentalFileAggregationService $rentalAgg,
        PpraMandateFileAggregationService $mandateAgg,
        NotificationDispatcher $notifier,
    ): void {
        $pack = PpraInspectionPack::find($this->packId);
        if (! $pack) {
            return; // deleted before the job ran — nothing to do
        }

        $pack->update(['status' => 'generating']);
        $pack->touch(); // restart the stale-pack clock now that the job is really running

        try {
            $agency = Agency::withoutGlobalScopes()->find($pack->agency_id);
            $user = User::find($pack->requested_by_user_id);
            abort_unless($agency && $user, 500, 'Pack references a missing agency or user.');

            $zipDir = storage_path('app/ppra-inspection-pack/' . $agency->id);
            if (! is_dir($zipDir)) {
                mkdir($zipDir, 0755, true);
            }
            $zipPath = $zipDir . '/pack-' . $pack->id . '-' . now()->format('Ymd-His') . '.zip';

            $zip = new ZipArchive();
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Could not create the pack ZIP file.');
            }

            // 1. The Inspection Report (§6.2's own renderer — same document
            // as the standalone preview, frozen here).
            $reportPdfPath = $reportPdf->generate($agency, $user);
            $zip->addFile($reportPdfPath, 'Inspection-Report.pdf');

            $rows = $checklist->checklistFor($agency);

            // 2. Vault documents a/b/d/h, and item e's trial balance (per
            // Johan's ruling — e stays a simple vault upload, no sampling).
            foreach (['a', 'b', 'd', 'e', 'h'] as $item) {
                $row = $rows->firstWhere('item', $item);
                if ($row && $row->document) {
                    $provision = AgencyComplianceProvision::find($row->document->provision_id);
                    if ($provision) {
                        $this->addStoredFile($zip, 'local', $provision->document_path, 'vault/' . $this->safeSegment((string) $row->document->name));
                    }
                }
            }

            // 3. Practitioner + principal FFC certificates (c/f).
            $roster = $practitionerRoster->rosterFor($agency->id);
            foreach ($roster as $agent) {
                $doc = $agent['ffc']['document'] ?? null;
                if ($doc) {
                    $ext = pathinfo($doc->file_path, PATHINFO_EXTENSION) ?: 'pdf';
                    $this->addStoredFile($zip, 'local', $doc->file_path, 'practitioners/' . $this->safeSegment((string) $agent['name']) . ' - FFC.' . $ext);
                }
            }

            // 4. Plain letterhead (g), generated fresh.
            $letterheadPath = $letterheadPdf->generate($agency);
            $zip->addFile($letterheadPath, 'letterhead.pdf');

            // 5. Transformation initiatives (i) — the uploaded document if
            // entry_type=document; a structured entry is already rendered
            // inside the Report itself, nothing extra to bundle for it.
            $transformation = AgencyTransformationNote::currentFor($agency->id);
            if ($transformation && $transformation->entry_type === 'document' && $transformation->document_path) {
                $ext = pathinfo($transformation->document_path, PATHINFO_EXTENSION) ?: 'pdf';
                $this->addStoredFile($zip, 'local', $transformation->document_path, 'transformation-initiatives.' . $ext);
            }

            // 6-8. Sampled deal/rental/listing file sets (k/l/m), reusing
            // the SAME aggregation manifests the Report's per-file index
            // already computed from.
            foreach (Deal::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $pack->agency_id)->whereIn('id', $pack->sample_deal_ids ?? [])->get() as $deal) {
                $this->bundleManifest($zip, $salesAgg->aggregate($deal));
            }
            foreach (Lease::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $pack->agency_id)->whereIn('id', $pack->sample_rental_ids ?? [])->get() as $lease) {
                $this->bundleManifest($zip, $rentalAgg->aggregate($lease));
            }
            foreach (Property::withoutGlobalScope(\App\Models\Scopes\AgencyScope::class)->where('agency_id', $pack->agency_id)->whereIn('id', $pack->sample_listing_ids ?? [])->get() as $property) {
                $this->bundleManifest($zip, $mandateAgg->aggregate($property));
            }

            if (! $zip->close() || ! is_file($zipPath)) {
                throw new \RuntimeException('Could not finalise the pack ZIP file.');
            }

            $pack->update([
                'status'          => 'ready',
                'zip_path'        => $zipPath,
                'report_pdf_path' => $reportPdfPath,
                'zip_size_bytes'  => filesize($zipPath),
                'gaps_summary'    => $rows->whereIn('status', ['amber', 'red'])->values()->toArray(),
                'generated_at'    => now(),
            ]);

            // threshold_hit_at is a REQUIRED dedup key (NotificationDispatcher
            // throws InvalidArgumentException without it — confirmed the hard
            // way, see this spec's own Phase J verification note). "Pack ready"
            // is a discrete one-off event, not a persistent condition, so per
            // the dispatcher's own documented guidance now() is the correct
            // value here (a fresh fact every time, not something to dedupe
            // against a stable threshold).
            $notifier->send($user, 'ppra_pack.generation_complete', $pack, new PpraPackReadyNotification($pack), [
                'threshold_hit_at' => now(),
            ]);

            $this->pruneOldPacks($pack);
        } catch (\Throwable $e) {
            $pack->update(['status' => 'failed', 'error_message' => $e->getMessage()]);
            Log::error('PPRA inspection pack generation failed', [
                'pack_id' => $pack->id,
                'agency_id' => $pack->agency_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** One aggregation manifest (k/l/m's own object shape) walked into the open ZIP. */
    private function bundleManifest(ZipArchive $zip, object $sample): void
    {
        foreach ($sample->sections as $section) {
            foreach ($section['files'] as $file) {
                $this->addManifestFileToZip($zip, $file);
            }
        }

        if (! empty($sample->missing)) {
            $zip->addFromString($this->safeEntry($sample->folder . '/00-missing.txt'), implode("\n", $sample->missing));
        }
    }

    private function addManifestFileToZip(ZipArchive $zip, object $file): void
    {
        $source = $file->source;

        if ($source instanceof Document) {
            if ($this->addStoredFile($zip, $source->disk ?? 'local', $source->storage_path, $file->dest_path)) {
                return;
            }
        } elseif ($source instanceof FicaDocument) {
            if ($this->addStoredFile($zip, 'local', $source->file_path, $file->dest_path)) {
                return;
            }
        } elseif ($source instanceof \Illuminate\Support\Collection && $source->first() instanceof CommunicationLink) {
            $zip->addFromString($this->safeEntry($file->dest_path), $this->communicationsLogText($source));
            return;
        }

        // Synthetic entries (pipeline/lease summaries, inspection/inventory
        // references, or a real file missing from disk) — never a silent
        // gap in the folder, per §9: write what we know as plain text.
        $zip->addFromString($this->safeEntry($file->dest_path), $file->note ?: ($file->label . ' — no file on disk.'));
    }

    private function addStoredFile(ZipArchive $zip, string $disk, ?string $storagePath, string $destPath): bool
    {
        if (! $storagePath) {
            return false;
        }

        $realPath = Storage::disk($disk)->path($storagePath);
        if (! is_file($realPath)) {
            return false;
        }

        $zip->addFile($realPath, $this->safeEntry($destPath));

        return true;
    }

    /** One path segment safe to use inside the archive (no separators, no traversal). */
    private function safeSegment(string $name): string
    {
        $name = str_replace(['/', '\\', "\0"], '-', $name);
        $name = trim(preg_replace('/[\x00-\x1F]/', '', $name) ?? '');

        return ($name === '' || $name === '.' || $name === '..') ? 'file' : $name;
    }

    /** A full archive entry name: every segment sanitised, '.'/'..'/empty segments dropped. */
    private function safeEntry(string $path): string
    {
        $parts = [];
        foreach (preg_split('#[/\\\\]+#', $path) ?: [] as $segment) {
            $segment = trim(preg_replace('/[\x00-\x1F]/', '', $segment) ?? '');
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            $parts[] = $segment;
        }

        return $parts ? implode('/', $parts) : 'file';
    }

    /**
     * Retention: generated ZIPs/reports are multi-MB and were never cleaned up.
     * After a successful run, delete this agency's pack files older than
     * 30 days (never the pack just built). Rows stay; the download route
     * already 404s when the file is gone.
     */
    private function pruneOldPacks(PpraInspectionPack $current): void
    {
        try {
            $dir = storage_path('app/ppra-inspection-pack/' . $current->agency_id);
            $keep = [$current->zip_path, $current->report_pdf_path];
            $cutoff = now()->subDays(30)->getTimestamp();
            foreach (glob($dir . '/*') ?: [] as $file) {
                if (is_file($file) && ! in_array($file, $keep, true) && filemtime($file) < $cutoff) {
                    @unlink($file);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('PPRA pack pruning failed', ['error' => $e->getMessage()]);
        }
    }

    private function communicationsLogText(\Illuminate\Support\Collection $links): string
    {
        $lines = [];
        foreach ($links as $link) {
            $comm = $link->communication;
            if (! $comm instanceof Communication) {
                continue;
            }
            $when = optional($comm->occurred_at)->format('Y-m-d H:i') ?? '(no date)';
            $lines[] = "[{$when}] {$comm->channel} {$comm->direction} — " . ($comm->subject ?: $comm->body_preview ?: '(no subject)');
        }

        return implode("\n", $lines) ?: 'No communications logged.';
    }
}
