<?php

namespace App\Http\Controllers;

use App\Models\RentalApplication;
use App\Models\RentalApplicationSignature;
use App\Services\RentalApplications\RentalApplicationNotifier;
use App\Services\RentalApplications\RentalApplicationPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * AT-392 spec §4b — the public tokenised route, modelled on the existing
 * /sign/{token} mechanism (SigningController) but for a document the agent
 * never signs. Reused, not reinvented: same token shape, same 14-day expiry
 * convention, same no-identity-leak treatment of an expired/used link.
 */
class RentalApplicationSigningController extends Controller
{
    /**
     * A public, unauthenticated route has no agency context to scope to at
     * all — the token itself IS the identity here. Uses the model's own
     * sanctioned cross-tenant escape hatch (BelongsToAgency::
     * queryWithoutAgencyScope()), never a raw withoutGlobalScope() call in
     * request code (CLAUDE.md Non-negotiable #7).
     */
    private function findByToken(string $token): RentalApplication
    {
        return RentalApplication::queryWithoutAgencyScope()
            ->where('token', $token)
            ->with(['contact', 'property', 'signatures', 'agency', 'branch', 'documents'])
            ->firstOrFail();
    }

    public function show(string $token): View
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return view('rental-applications.public.unavailable', ['reason' => 'expired']);
        }

        if (in_array($application->status, ['returned', 'under_assessment', 'approved', 'declined', 'withdrawn'], true)) {
            return view('rental-applications.public.already-submitted', compact('application'));
        }

        return view('rental-applications.public.show', compact('application'));
    }

    /**
     * AT-392 spec §3 — the applicant signs twice here (declaration +
     * TPN consent) in one sitting. Every field optional; only the two
     * signature captures are required to submit online.
     */
    public function submit(Request $request, string $token)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        if (in_array($application->status, ['returned', 'under_assessment', 'approved', 'declined', 'withdrawn'], true)) {
            return redirect()->route('rental-applications.public.show', $token);
        }

        // BUILD_STANDARD §2 — every field is optional (nullable passes on
        // empty/absent), but a MALFORMED value must be rejected with a clear
        // message, never allowed through to crash the date/decimal cast on
        // save(). This is a public, unauthenticated endpoint — validation
        // here is the only thing standing between a tampered field and a 500.
        $validated = $request->validate(array_merge(RentalApplication::fieldValidationRules(), [
            'declaration_signature' => ['required', 'string'],
            'tpn_consent_signature' => ['required', 'string'],
        ]));

        $fields = collect($validated)->except(['declaration_signature', 'tpn_consent_signature'])->all();
        // Optional-and-empty must never error (BUILD_STANDARD §2) — a blank
        // string is stored as NULL, never coerced into breaking a date/decimal cast.
        $fields = array_map(fn ($v) => $v === '' ? null : $v, $fields);

        // Standing rule — transactions roll back clean: the record save and
        // both signature captures must land together or not at all. Without
        // this, a failure storing the SECOND signature (e.g. a disk write
        // error) would leave status='returned' with only one signature
        // saved — an inconsistent state with no way back to "still open."
        DB::transaction(function () use ($application, $fields, $validated, $request) {
            $application->fill($fields);
            $application->delivery_mode = 'online';
            $application->status = 'returned';
            $application->submitted_at = now();
            $application->save();

            $this->storeSignature($application, 'declaration', $validated['declaration_signature'], $request);
            $this->storeSignature($application, 'tpn_consent', $validated['tpn_consent_signature'], $request);
        });

        // Outside the transaction, deliberately: a notification failure must
        // never roll back the applicant's already-committed submission
        // (Johan, 2026-09-07 — "the agent must be notified", but the
        // applicant's data landing is the more important guarantee of the two).
        app(RentalApplicationNotifier::class)->notifyAgentOfReturn($application->fresh());

        return redirect()->route('rental-applications.public.show', $token)
            ->with('success', 'Thank you — your application has been submitted.');
    }

    /**
     * AT-392 spec §5 — reuses the SAME allowlist/size-cap contract as
     * SigningController::uploadSupportingDocuments() (pdf,jpg,jpeg,png,doc,docx,
     * 15MB/file, max 10 files), filed through the shared `documents` table.
     */
    public function uploadDocuments(Request $request, string $token)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        $request->validate([
            'supporting_files' => ['required', 'array', 'min:1', 'max:10'],
            'supporting_files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:15360'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
        ]);

        $filed = 0;
        foreach ($request->file('supporting_files') as $file) {
            $path = $file->store("rental-applications/{$application->id}/documents", 'local');

            // withoutAgencyStamping() — this route has no authenticated user in
            // the normal case, but an agent previewing their own sent link
            // while still logged in (possibly switched into a different agency
            // context) must never cause the document to be mis-tenanted; it
            // always files against the APPLICATION's own agency, explicitly.
            $document = \App\Models\Document::withoutAgencyStamping(fn () => \App\Models\Document::create([
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'disk' => 'local',
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'document_type_id' => $request->input('document_type_id'),
                'source_type' => 'rental_application',
                'source_id' => $application->id,
                'agency_id' => $application->agency_id,
                'branch_id' => $application->branch_id,
            ]));

            $document->contacts()->syncWithoutDetaching([$application->contact_id]);
            if ($application->property_id) {
                $document->properties()->syncWithoutDetaching([$application->property_id]);
            }

            $filed++;
        }

        if ($application->status === 'sent') {
            $application->status = 'in_progress';
            $application->save();
        }

        return redirect()->route('rental-applications.public.show', $token)
            ->with('success', $filed === 1
                ? 'Your document was uploaded.'
                : "Your {$filed} documents were uploaded.");
    }

    public function pdf(string $token)
    {
        $application = $this->findByToken($token);

        $path = app(RentalApplicationPdfService::class)->generate($application);

        return response()->download($path, 'Rental Application.pdf')->deleteFileAfterSend(true);
    }

    /**
     * Johan, 2026-09-07 — full CRUD for the applicant's OWN documents, not
     * just create. Every action here re-derives the application from the
     * TOKEN first, then checks the document's source_type/source_id match
     * THIS application before touching it — a document id is a globally
     * auto-incrementing key on the shared `documents` table, shared across
     * every agency and every application, so the id alone proves nothing.
     * A document belonging to a DIFFERENT application (or found via a
     * different/no token at all) 404s exactly like it doesn't exist —
     * never a 403 that would confirm the id is valid but off-limits.
     */
    private function scopedDocument(RentalApplication $application, int $documentId): \App\Models\Document
    {
        $document = \App\Models\Document::where('id', $documentId)
            ->where('source_type', 'rental_application')
            ->where('source_id', $application->id)
            ->first();

        abort_unless($document, 404);

        return $document;
    }

    public function viewDocument(string $token, int $document)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            abort(404);
        }

        $doc = $this->scopedDocument($application, $document);

        return $doc->downloadResponse();
    }

    /**
     * Archive only — Document already uses SoftDeletes, so delete() here is
     * never destructive (CLAUDE.md Non-negotiable #1: no hard deletes,
     * anywhere, no exceptions). The file itself is left on disk; only the
     * DB row (and therefore its visibility everywhere, including the agent
     * side) is archived.
     */
    public function removeDocument(string $token, int $document)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        $doc = $this->scopedDocument($application, $document);

        $doc->delete();

        return redirect()->route('rental-applications.public.show', $token)
            ->with('success', 'Document removed.');
    }

    /**
     * Replace = the old document is archived and a new one filed, together,
     * atomically — never a window where the old is gone and the new hasn't
     * landed yet, and never a hard delete of the old row.
     */
    public function replaceDocument(Request $request, string $token, int $document)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        $oldDoc = $this->scopedDocument($application, $document);

        $validated = $request->validate([
            'replacement_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:15360'],
        ]);

        DB::transaction(function () use ($request, $application, $oldDoc) {
            $file = $request->file('replacement_file');
            $path = $file->store("rental-applications/{$application->id}/documents", 'local');

            $newDoc = \App\Models\Document::withoutAgencyStamping(fn () => \App\Models\Document::create([
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'disk' => 'local',
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'document_type_id' => $oldDoc->document_type_id,
                'source_type' => 'rental_application',
                'source_id' => $application->id,
                'agency_id' => $application->agency_id,
                'branch_id' => $application->branch_id,
            ]));

            $newDoc->contacts()->syncWithoutDetaching([$application->contact_id]);
            if ($application->property_id) {
                $newDoc->properties()->syncWithoutDetaching([$application->property_id]);
            }

            $oldDoc->delete();
        });

        return redirect()->route('rental-applications.public.show', $token)
            ->with('success', 'Document replaced.');
    }

    private function storeSignature(RentalApplication $application, string $kind, string $dataUrl, Request $request): void
    {
        // Signature pad payload is a data: URI (image/png;base64,...) — same
        // capture shape the e-sign signing view already uses.
        if (! preg_match('/^data:image\/png;base64,(.+)$/', $dataUrl, $m)) {
            return;
        }

        $binary = base64_decode($m[1]);
        $path = "rental-applications/{$application->id}/signatures/" . $kind . '-' . Str::random(8) . '.png';
        Storage::disk('local')->put($path, $binary);

        RentalApplicationSignature::updateOrCreate(
            ['rental_application_id' => $application->id, 'kind' => $kind],
            [
                'signature_path' => $path,
                'signed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        );
    }
}
