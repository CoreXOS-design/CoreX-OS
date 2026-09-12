<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationDocumentMark;
use App\Models\RentalApplicationHighlighter;
use App\Services\RentalApplications\RentalApplicationDocumentHighlightService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * AT-392, 2026-09-08 — shared between RentalApplicationReviewController
 * (the agent) and RentalApplicationAuthorisationController (the
 * authoriser). Johan: "the auth should be able to write on the docs as
 * well making notes etc." — reusing the review screen's own document
 * viewer per his instruction, not a third implementation. Extracted here
 * rather than left duplicated in both controllers specifically because
 * the save-completeness guard below is the exact protection the RA-06
 * critical fix built (a save must account for every page or it's
 * refused, never a silent partial wipe) — two independent copies of
 * that logic is precisely the kind of thing that quietly drifts out of
 * sync when one gets updated later and the other doesn't.
 *
 * Each consuming controller supplies its OWN access guard
 * (guardDocumentMarkAccess()) — the agent's own/branch/agency rule and
 * the authoriser's RO/CO tier rule are genuinely different checks; only
 * the mark-handling logic itself (validation, the completeness gate,
 * the save, the response shape) is shared.
 *
 * 2026-09-08 — Johan approved mark ownership + the six-colour category
 * scheme: a user may edit their own marks and never another's, every mark
 * knows which screen (role) drew it, and a save-time version check makes a
 * genuine collision visible rather than silent. Each consuming controller
 * also supplies markAuthorRole() — 'agent' or 'authoriser' — so newly
 * created marks are stamped with the CURRENT caller's role, never a
 * client-supplied one.
 */
trait HandlesRentalApplicationDocumentMarks
{
    abstract protected function guardDocumentMarkAccess(RentalApplication $rentalApplication, Document $document): void;

    /** 'agent' for the review screen, 'authoriser' for the authorisation screen — stamped onto every NEW mark this controller's save creates. */
    abstract protected function markAuthorRole(): string;

    /**
     * Capture-ledger rework, 2026-09-11 — both consuming controllers
     * (RentalApplicationReviewController, RentalApplicationAuthorisationController)
     * already provide this via AuthorizesRentalApplicationAccess. Declared
     * here explicitly (matching this trait's own existing style for its
     * other two dependencies above) since captureEntryUpdate()/Delete()
     * below act on the RENTAL APPLICATION directly, not a specific
     * document — an unanchored entry has none to guard through.
     */
    abstract protected function guardRentalApplication(RentalApplication $rentalApplication): void;

    /**
     * Progressive load, 2026-09-08 — page 1 fast, total page count, and
     * every currently-saved mark for the document (never split per page —
     * see RentalApplicationDocumentHighlightService::firstPagePreview()).
     */
    public function highlightFirstPage(RentalApplication $rentalApplication, Document $document, RentalApplicationDocumentHighlightService $highlights)
    {
        $this->guardDocumentMarkAccess($rentalApplication, $document);

        try {
            return response()->json($highlights->firstPagePreview($document));
        } catch (\Throwable $e) {
            \Log::error('Rental application document first-page preview failed', ['document' => $document->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'This document could not be opened for highlighting.'], 422);
        }
    }

    /** Progressive load — the remaining pages behind highlightFirstPage() above. */
    public function highlightRemainingPages(RentalApplication $rentalApplication, Document $document, RentalApplicationDocumentHighlightService $highlights)
    {
        $this->guardDocumentMarkAccess($rentalApplication, $document);

        try {
            return response()->json($highlights->remainingPagePreviews($document));
        } catch (\Throwable $e) {
            \Log::error('Rental application document remaining-pages preview failed', ['document' => $document->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'The rest of this document could not be loaded.'], 422);
        }
    }

    /**
     * Apply the current mark set. Every mark must declare a valid type
     * and the fields that type requires (RA-04), and the payload must
     * name EVERY page of the document or the whole save is refused
     * (the critical partial-save fix) — never a merge, never a partial
     * acceptance, so a stale tab or a crafted request can never silently
     * wipe marks it doesn't know about, from either role.
     */
    public function applyHighlight(Request $request, RentalApplication $rentalApplication, Document $document, RentalApplicationDocumentHighlightService $highlights)
    {
        $this->guardDocumentMarkAccess($rentalApplication, $document);

        $validated = $request->validate([
            'marks' => ['nullable', 'array', function (string $attribute, $value, \Closure $fail) {
                foreach ((array) $value as $page => $marksOnPage) {
                    if (! is_array($marksOnPage)) {
                        $fail("Page {$page}'s marks must be a list.");
                        continue;
                    }
                    foreach ($marksOnPage as $i => $mark) {
                        if (! is_array($mark)) {
                            $fail("Mark {$page}.{$i} must be an object.");
                            continue;
                        }
                        $type = $mark['type'] ?? null;
                        if ($type === 'note') {
                            if (! isset($mark['text']) || trim((string) $mark['text']) === '') {
                                $fail("Mark {$page}.{$i} is a note but has no text.");
                            }
                        } elseif ($type === 'highlight') {
                            if (! isset($mark['points']) || ! is_array($mark['points']) || count($mark['points']) < 2) {
                                $fail("Mark {$page}.{$i} is a highlight but has fewer than 2 points.");
                            }
                        } else {
                            $fail("Mark {$page}.{$i} has a missing or unrecognised type — expected 'highlight' or 'note'.");
                        }
                    }
                }
            }],
            'marks.*' => ['array'],
            'marks.*.*' => ['array'],
            'base_version' => ['nullable', 'integer', 'min:0'],
        ]);

        $totalPages = $highlights->totalPageCount($document);
        $providedPages = array_map('intval', array_keys((array) ($validated['marks'] ?? [])));
        sort($providedPages);
        if ($providedPages !== range(0, $totalPages - 1)) {
            return response()->json([
                'error' => 'This document hasn\'t fully finished loading yet — wait for every page to load, then try saving again.',
            ], 422);
        }

        try {
            $highlight = $highlights->applyMarks(
                $document,
                (int) $rentalApplication->agency_id,
                $request->user()->id,
                (string) $request->user()->name,
                $this->markAuthorRole(),
                (array) ($validated['marks'] ?? []),
                array_key_exists('base_version', $validated) ? (int) $validated['base_version'] : null,
            );
        } catch (\App\Exceptions\RentalApplicationMarkVersionConflictException $e) {
            return response()->json([
                'error' => 'Someone else\'s changes were saved to this document since you opened it. Reload the document, then reapply your marks.',
                'reason' => 'version_conflict',
                'current_version' => $e->currentVersion,
            ], 409);
        } catch (\App\Exceptions\RentalApplicationMarkOwnershipException $e) {
            return response()->json([
                'error' => 'One of these marks was created by the agent, or belongs to a different user, and can\'t be changed or removed here. Reload the document to see the current marks.',
                'reason' => 'ownership_conflict',
            ], 422);
        } catch (\Throwable $e) {
            \Log::error('Rental application document highlight apply failed', ['document' => $document->id, 'error' => $e->getMessage()]);

            return response()->json(['error' => 'Could not save highlights on this document. Please try again.'], 422);
        }

        // AT-401 — marks_json is frozen (no longer written); the true count
        // now lives in rental_application_document_marks.
        return response()->json([
            'ok' => true,
            'has_highlights' => $highlight->highlighted_file_path !== null,
            'mark_count' => \App\Models\RentalApplicationDocumentMark::where('document_id', $document->id)->count(),
            'marks_version' => $highlight->marks_version,
            'saved_at' => $highlight->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Capture-ledger rework, 2026-09-11 — Johan: "the highlighter mark IS
     * the ledger line." A drag with a capture pen active creates the mark
     * AND its ledger fields in ONE immediate save (not deferred to the
     * existing bulk "Save" button above, which replaces a whole document's
     * mark set and is built for freehand strokes, not a single committed
     * entry) — the capture chip's Enter calls this the instant the agent
     * confirms it. Points/width arrive already converted to RASTER px by
     * the client, the same convention applyHighlight() above already uses.
     *
     * Deliberately bypasses RentalApplicationDocumentHighlightService::
     * applyMarks() — that method's own job (regenerating the flattened/
     * burned download image, bumping marks_version) is real but not worth
     * paying on every single keystroke-speed capture save; the burned
     * artifact catches up the next time the existing bulk Save runs (its
     * own persistMarks() echoes this mark back unchanged and includes it
     * in the burn). Known, accepted side effect: a document whose ONLY
     * marks are capture entries won't show "Marked up" (that badge reads
     * the burned-file column) until a plain highlight/note is also drawn
     * and saved the old way — flagged in the build report, not silently
     * left undocumented.
     */
    public function captureEntryCreate(Request $request, RentalApplication $rentalApplication, Document $document, RentalApplicationDocumentHighlightService $highlights)
    {
        $this->guardDocumentMarkAccess($rentalApplication, $document);

        $validHighlighterIds = RentalApplicationHighlighter::pickerFor((int) $rentalApplication->agency_id, $this->markAuthorRole())
            ->pluck('id')->all();

        $validated = $request->validate([
            'mark_uid' => ['required', 'string', 'max:64'],
            'page' => ['required', 'integer', 'min:0'],
            'points' => ['required', 'array', 'min:2'],
            'points.*.x' => ['required', 'numeric'],
            'points.*.y' => ['required', 'numeric'],
            // 2026-09-12 — [4, 120] matches the exact bound
            // RentalApplicationDocumentHighlightService::normalizeNewMark()
            // already enforces for every other stroke's width; a bare 0-1
            // fraction (this endpoint's own real, found-and-fixed bug: the
            // client sent points/width unconverted for every capture-chip
            // entry ever confirmed) can never satisfy a >=4 floor. Kept
            // here rather than shared as a constant per this task's scope
            // lock — normalizeNewMark() itself is untouched.
            'width' => ['required', 'numeric', 'min:4', 'max:120'],
            'highlighter_id' => ['required', 'integer', Rule::in($validHighlighterIds)],
            'entry_type' => ['required', Rule::in(RentalApplicationDocumentMark::LEDGER_ENTRY_TYPES)],
            'entry_date' => ['nullable', 'date'],
            'entry_description' => ['nullable', 'string', 'max:255'],
            'entry_amount' => ['required', 'numeric'],
        ]);

        // Belt-and-braces alongside the width floor above: a point can
        // never legitimately land outside this page's own real raster
        // pixel size. Ground truth is read directly off the cached PNG
        // (pageDimensions()) — not trusted from the request — so a bad
        // client can never talk its way past this by simply also lying
        // about a page/document boundary.
        $dimensions = $highlights->pageDimensions($document, $validated['page']);
        if ($dimensions !== null) {
            foreach ($validated['points'] as $point) {
                if ($point['x'] < 0 || $point['x'] > $dimensions['width'] || $point['y'] < 0 || $point['y'] > $dimensions['height']) {
                    return response()->json(['error' => 'This mark falls outside the page — it could not be saved.'], 422);
                }
            }
        }

        if (RentalApplicationDocumentMark::where('document_id', $document->id)->where('mark_uid', $validated['mark_uid'])->exists()) {
            return response()->json(['error' => 'This mark has already been saved.'], 409);
        }

        $mark = RentalApplicationDocumentMark::create([
            'agency_id' => $rentalApplication->agency_id,
            'document_id' => $document->id,
            'rental_application_id' => $rentalApplication->id,
            'mark_uid' => $validated['mark_uid'],
            'type' => 'highlight',
            'page' => $validated['page'],
            'points' => $validated['points'],
            'width' => $validated['width'],
            'highlighter_id' => $validated['highlighter_id'],
            'author_user_id' => $request->user()->id,
            'author_name' => (string) $request->user()->name,
            'author_role' => $this->markAuthorRole(),
            'source' => 'human',
            'entry_type' => $validated['entry_type'],
            'entry_date' => $validated['entry_date'] ?? null,
            'entry_description' => $validated['entry_description'] ?? null,
            'entry_amount' => $validated['entry_amount'],
        ]);

        return response()->json(['ok' => true, 'entry' => $mark->toMarkArray()]);
    }

    /**
     * Stage 3, 2026-09-11 — "Add line manually," for a figure with nothing
     * to highlight. Same record as captureEntryCreate() above, but
     * UNANCHORED (document_id/page/type/points/width/highlighter_id all
     * null) — the schema was built for exactly this case (see the Stage 1
     * migration's own docblock). No $document route parameter at all,
     * since a manual entry never had one; guarded on the RENTAL
     * APPLICATION directly, the same way captureEntryUpdate/Delete already
     * are, not a document.
     */
    public function captureEntryCreateManual(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $validated = $request->validate([
            'mark_uid' => ['required', 'string', 'max:64'],
            'entry_type' => ['required', Rule::in(RentalApplicationDocumentMark::LEDGER_ENTRY_TYPES)],
            'entry_date' => ['nullable', 'date'],
            'entry_description' => ['nullable', 'string', 'max:255'],
            'entry_amount' => ['required', 'numeric'],
        ]);

        if (RentalApplicationDocumentMark::where('rental_application_id', $rentalApplication->id)->where('mark_uid', $validated['mark_uid'])->exists()) {
            return response()->json(['error' => 'This entry has already been saved.'], 409);
        }

        $mark = RentalApplicationDocumentMark::create([
            'agency_id' => $rentalApplication->agency_id,
            'rental_application_id' => $rentalApplication->id,
            'mark_uid' => $validated['mark_uid'],
            'author_user_id' => $request->user()->id,
            'author_name' => (string) $request->user()->name,
            'author_role' => $this->markAuthorRole(),
            'source' => 'human',
            'entry_type' => $validated['entry_type'],
            'entry_date' => $validated['entry_date'] ?? null,
            'entry_description' => $validated['entry_description'] ?? null,
            'entry_amount' => $validated['entry_amount'],
        ]);

        return response()->json(['ok' => true, 'entry' => $mark->toMarkArray()]);
    }

    /**
     * Ledger fields only — entry_date/entry_description/entry_amount.
     * Geometry (points/width/highlighter_id/page) is never editable here,
     * on purpose: the "no in-place edit of a drawn mark, only draw-new and
     * remove" evidence-integrity rule this table was built for (AT-401)
     * stays intact for what a mark visually IS on the document. What the
     * agent typed about it is a different fact and is allowed to change —
     * exactly as an ordinary ledger line always could.
     */
    public function captureEntryUpdate(Request $request, RentalApplication $rentalApplication, string $markUid)
    {
        $this->guardRentalApplication($rentalApplication);

        $mark = RentalApplicationDocumentMark::where('rental_application_id', $rentalApplication->id)
            ->where('mark_uid', $markUid)
            ->whereIn('entry_type', RentalApplicationDocumentMark::LEDGER_ENTRY_TYPES)
            ->firstOrFail();

        $this->guardCaptureEntryOwnership($mark, $request->user());

        $validated = $request->validate([
            'entry_date' => ['nullable', 'date'],
            'entry_description' => ['nullable', 'string', 'max:255'],
            'entry_amount' => ['required', 'numeric'],
        ]);

        $mark->update($validated);

        return response()->json(['ok' => true, 'entry' => $mark->toMarkArray()]);
    }

    /** Soft delete only (non-negotiable #1) — the mark AND its ledger line disappear together, since they are now the same row. */
    public function captureEntryDelete(Request $request, RentalApplication $rentalApplication, string $markUid)
    {
        $this->guardRentalApplication($rentalApplication);

        $mark = RentalApplicationDocumentMark::where('rental_application_id', $rentalApplication->id)
            ->where('mark_uid', $markUid)
            ->whereIn('entry_type', RentalApplicationDocumentMark::LEDGER_ENTRY_TYPES)
            ->firstOrFail();

        $this->guardCaptureEntryOwnership($mark, $request->user());

        $mark->delete();

        return response()->json(['ok' => true]);
    }

    /**
     * Same ownership rule persistMarks() already enforces for removing a
     * plain highlight/note (AT-401's own governance): an unattributed
     * (legacy/migrated) entry has nothing to protect; otherwise only the
     * entry's own author may change it, and an authoriser may never touch
     * an agent's entry regardless of user id.
     */
    private function guardCaptureEntryOwnership(RentalApplicationDocumentMark $mark, $user): void
    {
        if ($mark->author_role === null) {
            return;
        }

        $blockedByRole = $mark->author_role === 'agent' && $this->markAuthorRole() === 'authoriser';
        $blockedByUser = (int) $mark->author_user_id !== (int) $user->id;

        if ($blockedByRole || $blockedByUser) {
            abort(403, 'This entry was captured by someone else and can\'t be changed here.');
        }
    }
}
