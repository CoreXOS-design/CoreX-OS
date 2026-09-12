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

        if ($application->status === 'draft') {
            return view('rental-applications.public.unavailable', ['reason' => 'not_sent']);
        }

        // Reopen/resubmit, 2026-09-08 — 'reopened' is deliberately NOT in
        // POST_RETURN_STATUSES (see RentalApplication::REOPENABLE_STATUSES'
        // docblock), so it falls through to the same editable form as
        // sent/in_progress. Every field renders pre-filled from the model's
        // OWN attributes ($application->full_name etc, unchanged since the
        // last submission) — Johan: "prefilled - its a reopen, not new."
        if (in_array($application->status, RentalApplication::POST_RETURN_STATUSES, true)) {
            return view('rental-applications.public.already-submitted', compact('application'));
        }

        return view('rental-applications.public.show', compact('application'));
    }

    /**
     * AT-392 spec §3 — the applicant signs twice here (declaration +
     * TPN consent) in one sitting. Every field optional; only the two
     * signature captures are required to submit online.
     */
    public function submit(Request $request, string $token, \App\Services\RentalApplications\RentalApplicationAuditService $audit)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        if ($application->status === 'draft') {
            return redirect()->route('rental-applications.public.show', $token);
        }

        // Reopen/resubmit — same POST_RETURN_STATUSES check as show() above;
        // 'reopened' is not in that list, so a reopened application can
        // reach submit() same as sent/in_progress can.
        if (in_array($application->status, RentalApplication::POST_RETURN_STATUSES, true)) {
            return redirect()->route('rental-applications.public.show', $token);
        }

        // RA-02 (cc5) — "a real person types 15,000... gets 'must be a
        // number'." Strip thousand separators/spaces/R-prefix on every
        // numeric field before validation, same as the agent-side fix.
        $request->merge(RentalApplication::sanitizeNumericInput($request->only(RentalApplication::NUMERIC_FIELDS)));

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
        $fields = RentalApplication::normalizeStillLiving($fields);

        // Standing rule — transactions roll back clean: the record save,
        // both signature captures, AND (reopen/resubmit, 2026-09-08) the
        // sealed generation snapshot must land together or not at all.
        //
        // Generation, 2026-09-08 — current_generation defaults to 1 at
        // creation (matches every pre-reopen signature row, already
        // backfilled to generation=1). The FIRST-EVER submit() must seal
        // under that same 1, not bump past it — signalled by submitted_at
        // still being null (it is set exactly once per RentalApplication::
        // isSubmitted()'s own docblock, on every submit including this one,
        // and never cleared). Every submit() AFTER the first (i.e. a
        // reopen-resubmit, where submitted_at is already set from the prior
        // round) bumps current_generation before sealing. Either way,
        // signatures are stamped with whatever current_generation ends up
        // being for THIS submission — so a resubmit can never collide with
        // (and therefore can never overwrite) the previous round's
        // signature row, which is the "signature landmine" this build was
        // required to fix.
        $isResubmit = $application->submitted_at !== null;

        DB::transaction(function () use ($application, $fields, $validated, $request, $audit, $isResubmit) {
            $fromStatus = $application->status;

            $application->fill($fields);
            $application->delivery_mode = 'online';
            $application->status = 'returned';
            $application->submitted_at = now();
            if ($isResubmit) {
                $application->current_generation = $application->current_generation + 1;
            }
            $application->save();

            $this->storeSignature($application, 'declaration', $validated['declaration_signature'], $request);
            $this->storeSignature($application, 'tpn_consent', $validated['tpn_consent_signature'], $request);

            \App\Models\RentalApplicationGeneration::seal($application, $request);

            // Defect fix, AT-392 (Johan via cc5's journey walk) — neither a
            // first submission nor a resubmit-after-reopen ever wrote to the
            // evidentiary trail; there was no way to tell an applicant had
            // ever responded. No User actor exists on this public,
            // unauthenticated route — both calls take null, same as any
            // other applicant-originated event on this feature.
            \App\Models\RentalApplicationStatusHistory::record(
                $application, $fromStatus, 'returned', null,
                $isResubmit ? 'Applicant resubmitted online.' : 'Applicant submitted online.',
            );
            $audit->log(
                $application,
                eventCategory: 'applicant',
                eventType: $isResubmit ? 'resubmitted' : 'submitted',
                oldValues: ['status' => $fromStatus],
                newValues: ['status' => 'returned'],
                humanSummary: $isResubmit
                    ? 'Applicant resubmitted online after a reopen.'
                    : 'Applicant submitted the application online.',
            );
        });

        // Outside the transaction, deliberately: a notification failure must
        // never roll back the applicant's already-committed submission
        // (Johan, 2026-09-07 — "the agent must be notified", but the
        // applicant's data landing is the more important guarantee of the two).
        $application = $application->fresh();
        app(RentalApplicationNotifier::class)->notifyAgentOfReturn($application);

        // AT-392 — keeps Contact::rental_application_status in sync
        // (App\Listeners\Contact\RecomputeRentalApplicationStatus).
        event(new \App\Events\RentalApplication\RentalApplicationSubmitted($application, $isResubmit));

        return redirect()->route('rental-applications.public.show', $token)
            ->with('success', 'Thank you — your application has been submitted.');
    }

    /**
     * AT-392 spec §5 — reuses the SAME allowlist/size-cap contract as
     * SigningController::uploadSupportingDocuments() (pdf,jpg,jpeg,png,doc,docx,
     * 15MB/file, max 10 files), filed through the shared `documents` table.
     */
    /**
     * Johan, QA1 — "I select docs and click submit... no docs arrive back
     * because i never clicked upload" / "I complete all the information...
     * attach a file, click upload and the screen refreshes, and all my
     * typed info is gone." Root cause of BOTH: this was a synchronous
     * form-POST-redirect action, so ANY use of it reloaded the whole page —
     * discarding whatever the applicant had typed into the main form but
     * not yet submitted (there is no separate "save" step on this public
     * page; everything lives only in the browser until Submit). Fixed by
     * making this endpoint respond with JSON when asked (the new
     * fetch-based upload in show.blade.php) so the file attaches with NO
     * navigation at all — the old synchronous form-POST path is left
     * intact for any caller that still wants it (e.g. a no-JS fallback),
     * unchanged in behaviour.
     */
    public function uploadDocuments(Request $request, string $token)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'This link has expired.'], 410);
            }

            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        if ($application->status === 'draft') {
            if ($request->wantsJson()) {
                return response()->json(['message' => "This application hasn't been sent to you yet."], 410);
            }

            return redirect()->route('rental-applications.public.show', $token);
        }

        $request->validate([
            'supporting_files' => ['required', 'array', 'min:1', 'max:10'],
            'supporting_files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:15360'],
            'document_type_id' => ['nullable', 'integer', 'exists:document_types,id'],
        ]);

        $filedDocuments = [];
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

            $filedDocuments[] = $document;
        }

        if ($application->status === 'sent') {
            $application->status = 'in_progress';
            $application->save();
        }

        $filed = count($filedDocuments);

        if ($request->wantsJson()) {
            return response()->json([
                'documents' => collect($filedDocuments)->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->original_name,
                    'view_url' => route('rental-applications.public.documents.view', [$token, $d->id]),
                ]),
            ]);
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
     * Johan, 2026-09-07 — "submitted docs are submitted. they can add, but
     * not replace or remove." Evidentiary rule: once an agent has received
     * the application, the applicant must not be able to quietly swap or
     * pull a document the agent has already seen. This is a correctness
     * rule, not a setting — no agency toggle, no threshold, checked the
     * same way for every agency. Shared by removeDocument() and
     * replaceDocument() so the two can never drift out of sync with each
     * other or with RentalApplication::isSubmitted().
     */
    private function assertDocumentsNotLocked(RentalApplication $application, string $token)
    {
        if (! $application->isSubmitted()) {
            return null;
        }

        return redirect()->route('rental-applications.public.show', $token)
            ->with('error', 'The documents you submitted with your application are locked and can\'t be changed. You can still add more below.');
    }

    /**
     * Archive only — Document already uses SoftDeletes, so delete() here is
     * never destructive (CLAUDE.md Non-negotiable #1: no hard deletes,
     * anywhere, no exceptions). The file itself is left on disk; only the
     * DB row (and therefore its visibility everywhere, including the agent
     * side) is archived.
     */
    public function removeDocument(Request $request, string $token, int $document)
    {
        $application = $this->findByToken($token);

        if ($application->token_expires_at && $application->token_expires_at->isPast()) {
            if ($request->wantsJson()) {
                return response()->json(['message' => 'This link has expired.'], 410);
            }

            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        $doc = $this->scopedDocument($application, $document);

        if ($locked = $this->assertDocumentsNotLocked($application, $token)) {
            if ($request->wantsJson()) {
                return response()->json(['message' => "The documents you submitted with your application are locked and can't be changed."], 423);
            }

            return $locked;
        }

        $doc->delete();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Document removed.']);
        }

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
            if ($request->wantsJson()) {
                return response()->json(['message' => 'This link has expired.'], 410);
            }

            return redirect()->route('rental-applications.public.show', $token)
                ->with('error', 'This link has expired.');
        }

        $oldDoc = $this->scopedDocument($application, $document);

        if ($locked = $this->assertDocumentsNotLocked($application, $token)) {
            if ($request->wantsJson()) {
                return response()->json(['message' => "The documents you submitted with your application are locked and can't be changed."], 423);
            }

            return $locked;
        }

        $validated = $request->validate([
            'replacement_file' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:15360'],
        ]);

        $newDoc = DB::transaction(function () use ($request, $application, $oldDoc) {
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

            return $newDoc;
        });

        if ($request->wantsJson()) {
            return response()->json([
                'document' => [
                    'id' => $newDoc->id,
                    'name' => $newDoc->original_name,
                    'view_url' => route('rental-applications.public.documents.view', [$token, $newDoc->id]),
                ],
                'replaced_id' => $oldDoc->id,
            ]);
        }

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

        // Reopen/resubmit, 2026-09-08 — 'generation' is now part of the key.
        // $application->current_generation has ALREADY been bumped (if this
        // is a resubmit) by the time this runs, inside the same transaction
        // — so this always creates a NEW row for a new round, never
        // overwrites the previous round's signature.
        //
        // QA1 multi-tenancy sweep, 2026-09-12 — agency_id is now part of the
        // match/create attributes, and the whole call is wrapped in
        // withoutAgencyStamping() so BelongsToAgency's creating() hook can
        // never override this already-correct, already-validated value with
        // whatever the calling context's own agency resolves to (this route
        // is normally unauthenticated, but an owner-role account testing a
        // link while switched into a different agency is exactly the edge
        // case that hook exists to guard against elsewhere in this sweep).
        RentalApplicationSignature::withoutAgencyStamping(fn () => RentalApplicationSignature::updateOrCreate(
            ['rental_application_id' => $application->id, 'agency_id' => $application->agency_id, 'kind' => $kind, 'generation' => $application->current_generation],
            [
                'signature_path' => $path,
                'signed_at' => now(),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]
        ));
    }
}
