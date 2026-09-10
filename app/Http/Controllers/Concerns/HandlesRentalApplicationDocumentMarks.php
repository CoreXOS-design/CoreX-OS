<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Document;
use App\Models\RentalApplication;
use App\Services\RentalApplications\RentalApplicationDocumentHighlightService;
use Illuminate\Http\Request;

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
}
