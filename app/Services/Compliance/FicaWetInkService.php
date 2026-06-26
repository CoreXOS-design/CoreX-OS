<?php

namespace App\Services\Compliance;

use App\Events\Fica\FicaSubmitted;
use App\Models\Contact;
use App\Models\FicaDocument;
use App\Models\FicaSubmission;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * AT-105 — single source of truth for creating a wet-ink FICA verification.
 *
 * Extracted from FicaController::storeWetInk so BOTH the manual wet-ink intake
 * form AND the PDF Splitter's FICA auto-kickoff create submissions through the
 * exact same path (one creator, no fork). The controller still owns request
 * validation + the redirect; this service owns persistence + the domain event.
 *
 * Document slots mirror the wet-ink intake exactly: 'fica_form', 'id_copy',
 * 'proof_of_address', and 'supporting'. Files land on the public disk under
 * fica/wet-ink/{submission_id}/ — identical to the manual flow.
 */
class FicaWetInkService
{
    /**
     * Create the submission row (no documents). Caller wraps this + the
     * addDocument* calls in a DB transaction, then calls fireSubmitted().
     *
     * @param array{
     *   entity_type?: string,
     *   wet_ink_received_date?: string,
     *   status?: string,
     *   source?: string,
     *   actor_user_id?: int|null
     * } $opts
     */
    public function create(Contact $contact, int $agencyId, array $opts = []): FicaSubmission
    {
        $entityType   = $opts['entity_type'] ?? 'natural';
        $receivedDate = $opts['wet_ink_received_date'] ?? now()->toDateString();
        $status       = $opts['status'] ?? 'submitted';
        $actorUserId  = $opts['actor_user_id'] ?? Auth::id();

        $intake = [
            'method'        => 'wet_ink',
            'received_date' => $receivedDate,
            'received_by'   => Auth::user()->name ?? 'System',
        ];
        if (! empty($opts['source'])) {
            $intake['source'] = $opts['source'];
        }

        return FicaSubmission::create([
            'contact_id'            => $contact->id,
            'agency_id'             => $agencyId,
            'requested_by'          => $actorUserId,
            'status'                => $status,
            'intake_type'           => 'wet_ink',
            'entity_type'           => $entityType,
            'wet_ink_received_date' => $receivedDate,
            'wet_ink_confirmed_by'  => $actorUserId,
            'signed_at'             => $receivedDate,
            'form_data'             => [
                'personal' => [
                    'first_name' => $contact->first_name,
                    'last_name'  => $contact->last_name,
                    'id_number'  => $contact->id_number ?? null,
                    'email'      => $contact->email ?? null,
                    'phone'      => $contact->phone ?? null,
                ],
                'entity' => ['type' => $entityType],
                'intake' => $intake,
            ],
        ]);
    }

    /**
     * Attach a freshly-uploaded file (manual intake form path).
     */
    public function addUploadedDocument(FicaSubmission $submission, UploadedFile $file, string $slot): FicaDocument
    {
        $path = $file->store("fica/wet-ink/{$submission->id}", 'public');

        return FicaDocument::create([
            'fica_submission_id' => $submission->id,
            'document_type'      => $slot,
            'file_path'          => $path,
            'file_name'          => $file->getClientOriginalName(),
            'file_size'          => $file->getSize(),
            'mime_type'          => $file->getMimeType(),
            'status'             => 'uploaded',
            'uploaded_at'        => now(),
            'uploaded_by'        => Auth::id(),
        ]);
    }

    /**
     * Attach a file already on disk (PDF Splitter path — a split output PDF).
     * Copies the bytes into the FICA store so the FICA record owns its own
     * authoritative copy, independent of the splitter's temp/Drive copies.
     */
    public function addStoredDocument(FicaSubmission $submission, string $absPath, string $originalName, string $slot): ?FicaDocument
    {
        if (! is_file($absPath)) {
            return null;
        }

        $stream = @fopen($absPath, 'rb');
        if (! $stream) {
            return null;
        }

        $relPath = "fica/wet-ink/{$submission->id}/" . \Illuminate\Support\Str::random(8) . '_' . basename($originalName);
        Storage::disk('public')->put($relPath, $stream);
        if (is_resource($stream)) {
            @fclose($stream);
        }

        return FicaDocument::create([
            'fica_submission_id' => $submission->id,
            'document_type'      => $slot,
            'file_path'          => $relPath,
            'file_name'          => $originalName,
            'file_size'          => @filesize($absPath) ?: null,
            'mime_type'          => 'application/pdf',
            'status'             => 'uploaded',
            'uploaded_at'        => now(),
            'uploaded_by'        => Auth::id(),
        ]);
    }

    /**
     * Fire the cross-pillar domain event. Called AFTER the create+attach
     * transaction commits — mirrors FicaController::storeWetInk ordering.
     */
    public function fireSubmitted(FicaSubmission $submission, Contact $contact, ?int $actorUserId = null): void
    {
        event(new FicaSubmitted(
            contact: $contact,
            package: $submission,
            actorUserId: $actorUserId ?? Auth::id(),
        ));
    }
}
