<?php

namespace App\Services\RentalApplications;

use App\Http\Controllers\Docuperfect\SigningController;
use App\Models\RentalApplication;
use App\Models\RentalApplicationGeneration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * AT-392 — renders a RentalApplication to PDF for the "download, complete,
 * scan, return" route (spec §4a) and for the agent's own reference copy.
 *
 * Reuses the SAME Puppeteer HTML->PDF pipeline as e-sign
 * (SigningController::generatePdfFromHtml() / wrapHtmlForPdf()) rather than
 * inventing a second renderer — this is a static fill, not an e-sign
 * document, so it deliberately does NOT go anywhere near SignatureTemplate.
 *
 * Reopen/resubmit follow-up, 2026-09-09 — Johan/senior-engineer decision:
 * every render was previously a fresh ~9s headless-Chromium run, even for a
 * signed, submitted application whose content can never change again. Now
 * cached PER SEALED GENERATION (see RentalApplicationGeneration) on the
 * `data_volume` disk (config/filesystems.php — the mounted volume, never
 * root). This is deliberately NOT a cache with an invalidation strategy: a
 * sealed generation's snapshot is append-only and can never change, so once
 * a cache entry exists for (application id, generation), it is correct
 * forever — the only way it's ever removed is the application itself being
 * archived (see RentalApplicationController::destroy()).
 *
 * What is cacheable vs not, precisely: cacheable if and only if
 * $rentalApplication->isSubmitted() (submitted_at is set — the one-way,
 * never-cleared flag this whole module already treats as the source of
 * truth for "has this been signed") AND a RentalApplicationGeneration row
 * exists for its CURRENT generation. Those two together guarantee the
 * live row's own field values are byte-identical to what that generation
 * sealed — submit() writes the new field values, the new signatures, AND
 * the seal in one DB transaction (see RentalApplicationSigningController),
 * so there is no window where the live row and its current generation's
 * snapshot can disagree. Critically, this holds even while status is
 * 'reopened': reopen() never touches the applicant-answer fields, only
 * unlocks the public form — the live row still matches the last sealed
 * generation until the applicant actually resubmits, which is exactly the
 * moment current_generation bumps and a NEW generation is sealed. A
 * draft/sent/in_progress application, or the public "download, complete,
 * return" pre-submission link, is never submitted yet, so isSubmitted() is
 * false and this always regenerates fresh — never caches a moving target.
 */
class RentalApplicationPdfService
{
    public const CACHE_DISK = 'data_volume';

    public function __construct(private SigningController $signingController) {}

    /**
     * @return string Absolute path to a temp PDF file. Caller is responsible
     *                 for the file's lifetime (the controller streams it with
     *                 deleteFileAfterSend).
     */
    public function generate(RentalApplication $rentalApplication): string
    {
        $rentalApplication->loadMissing(['contact', 'property', 'signatures']);

        $sealedGeneration = $rentalApplication->isSubmitted()
            ? RentalApplicationGeneration::where('rental_application_id', $rentalApplication->id)
                ->where('generation', $rentalApplication->current_generation)
                ->first()
            : null;

        if ($sealedGeneration) {
            $cached = $this->tryServeFromCache($rentalApplication->id, $sealedGeneration->generation);
            if ($cached) {
                return $cached;
            }
        }

        $html = view('corex.rental-applications.pdf', [
            'application' => $rentalApplication,
            'agency' => $rentalApplication->agency,
            'branch' => $rentalApplication->branch,
        ])->render();

        // generatePdfFromHtml() wraps the shell (fonts, print CSS) itself
        // internally via wrapHtmlForPdf() — pass the raw body HTML, not
        // pre-wrapped, or the document shell doubles up.
        $path = $this->signingController->generatePdfFromHtml($html, $rentalApplication->id);

        if (! $path) {
            throw new \RuntimeException('Rental application PDF generation failed for id ' . $rentalApplication->id);
        }

        if ($sealedGeneration) {
            $this->tryStoreInCache($rentalApplication->id, $sealedGeneration->generation, $path);
        }

        return $path;
    }

    /**
     * AT-392 — Johan: "the rental application is not a pillar of corex but
     * the contact is, so we need to save the information on the contact so
     * its available at any point if anyone needs to look at it." Files the
     * generated pack as a real, persisted Document attached to the
     * contact via the same document_contacts pivot e-sign already uses
     * (SignatureService::linkFiledDocumentToContactsAndProperty()) —
     * not a new mechanism. Called at the outcome moments (approved/
     * declined), not on every render, so a contact's Drive/Rental
     * Applications history gains one dated snapshot per real decision.
     */
    public function fileAsDocument(RentalApplication $rentalApplication, string $label): ?\App\Models\Document
    {
        if (! $rentalApplication->contact_id) {
            return null;
        }

        try {
            $path = $this->generate($rentalApplication);
            $filename = $label . ' — ' . $rentalApplication->contact->full_name . ' — ' . now()->format('Y-m-d') . '.pdf';
            $storedPath = 'rental-applications/' . $rentalApplication->id . '/filed/' . \Illuminate\Support\Str::random(20) . '.pdf';

            Storage::disk('local')->put($storedPath, file_get_contents($path));

            $document = \App\Models\Document::withoutAgencyStamping(fn () => \App\Models\Document::create([
                'original_name' => $filename,
                'storage_path' => $storedPath,
                'disk' => 'local',
                'mime_type' => 'application/pdf',
                'size' => Storage::disk('local')->size($storedPath),
                'source_type' => 'rental_application',
                'source_id' => $rentalApplication->id,
                'agency_id' => $rentalApplication->agency_id,
                'branch_id' => $rentalApplication->branch_id,
            ]));

            $document->contacts()->syncWithoutDetaching([$rentalApplication->contact_id => ['party_role' => 'applicant']]);
            if ($rentalApplication->property_id) {
                $document->properties()->syncWithoutDetaching([$rentalApplication->property_id]);
            }

            return $document;
        } catch (\Throwable $e) {
            Log::warning('AT-392 rental application fileAsDocument failed', [
                'rental_application_id' => $rentalApplication->id,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    private function cachePath(int $applicationId, int $generation): string
    {
        return "rental-applications/{$applicationId}/generations/{$generation}.pdf";
    }

    /**
     * Cache-miss-must-be-invisible, the other direction: a READ failure
     * (corrupt cache entry, disk hiccup) must fall through to a fresh
     * render exactly like a genuine miss — never surface as an error to
     * whoever's waiting on their signed PDF.
     */
    private function tryServeFromCache(int $applicationId, int $generation): ?string
    {
        try {
            $disk = Storage::disk(self::CACHE_DISK);
            $path = $this->cachePath($applicationId, $generation);
            if (! $disk->exists($path)) {
                return null;
            }

            $tempDir = storage_path('app/temp');
            if (! is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
            $tempPath = $tempDir . '/doc_' . $applicationId . '_' . time() . '_' . bin2hex(random_bytes(6)) . '.pdf';
            file_put_contents($tempPath, $disk->get($path));

            return $tempPath;
        } catch (\Throwable $e) {
            Log::warning('Rental application PDF cache read failed — regenerating instead', [
                'rental_application_id' => $applicationId,
                'generation' => $generation,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Best-effort. A full disk, a permissions problem, anything — the
     * applicant/agent already has their freshly rendered PDF in hand by the
     * time this runs; a caching failure must never turn into "cannot open
     * your signed application."
     */
    private function tryStoreInCache(int $applicationId, int $generation, string $renderedPath): void
    {
        try {
            $disk = Storage::disk(self::CACHE_DISK);
            $disk->put($this->cachePath($applicationId, $generation), file_get_contents($renderedPath));
        } catch (\Throwable $e) {
            Log::warning('Rental application PDF cache write failed — serving uncached this time', [
                'rental_application_id' => $applicationId,
                'generation' => $generation,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * RentalApplicationController::destroy() (archive) calls this — a
     * cached generation PDF is a derived artefact, not the record of truth
     * (the sealed snapshot_json is, and is untouched by this), so removing
     * it when its application is archived is reclaiming disk, not deleting
     * evidence. Never throws — archiving the application itself must
     * succeed regardless of cache-cleanup outcome.
     */
    public function forgetCacheFor(RentalApplication $rentalApplication): void
    {
        try {
            $disk = Storage::disk(self::CACHE_DISK);
            $dir = "rental-applications/{$rentalApplication->id}/generations";
            if ($disk->exists($dir)) {
                $disk->deleteDirectory($dir);
            }
        } catch (\Throwable $e) {
            Log::warning('Rental application PDF cache cleanup failed on archive', [
                'rental_application_id' => $rentalApplication->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
