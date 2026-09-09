<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalApplicationAccess;
use App\Http\Controllers\Concerns\HandlesRentalApplicationDocumentMarks;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAssessment;
use App\Models\RentalApplicationDocumentHighlight;
use App\Models\RentalApplicationExpenseItem;
use App\Models\RentalApplicationIncomeItem;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationStatusHistory;
use App\Services\RentalApplications\RentalApplicationAuditService;
use App\Services\RentalApplications\RentalApplicationDocumentHighlightService;
use App\Services\RentalApplications\RentalApplicationMailer;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-392 Phase 2 — the agent review split-screen. Johan's own design-
 * conversation words: "application gets returned, agent open application -
 * sees application and supporting docs on left panel of screen... then have
 * a place on the right panel to input things like - income, salary / etc
 * etc... doing the calcs to the bottom to see if tenant qualifies." Explicit
 * scoping/CRUD standard (BUILD_STANDARD §1) applied identically to every
 * action here via AuthorizesRentalApplicationAccess — the same trait every
 * other rental-applications controller action already uses, so this screen
 * can never grant more access than show()/downloadDocument() already do.
 *
 * Deliberately a NEW controller, not an edit to RentalApplicationController
 * (owned by another lane, actively being edited concurrently) or show.blade.php
 * (owned by another lane, has a queued late-document badge). No shared file
 * touched except one appended route group and one appended settings-form
 * section.
 */
class RentalApplicationReviewController extends Controller
{
    use AuthorizesRentalApplicationAccess;
    use HandlesRentalApplicationDocumentMarks;

    /** Fulfils HandlesRentalApplicationDocumentMarks's guard requirement with the agent's own access rule. */
    protected function guardDocumentMarkAccess(RentalApplication $rentalApplication, Document $document): void
    {
        $this->guardRentalApplication($rentalApplication);
        $this->guardDocumentBelongsToApplication($rentalApplication, $document);
    }

    /** Every mark this controller's save creates is stamped 'agent' — this is the review screen. */
    protected function markAuthorRole(): string
    {
        return 'agent';
    }

    /** Mime types the browser can render natively — everything else gets a download-only fallback. */
    private const INLINE_VIEWABLE_MIME_PREFIXES = ['application/pdf', 'image/'];

    /**
     * AT-392, Johan (approved via the conductor, 2026-09-08): "property
     * linked should be an option then on review - look when the agent
     * creates the application they can link it, but if not linked and we
     * want to test against it then we need to allow the agent to link it
     * on this screen as well." The affordability check was testing the
     * applicant's self-reported CURRENT rent when no property was linked —
     * meaningless, since it answers "can they afford where they already
     * live." cc4's qualifyingResult() now reads the rent from the linked
     * property instead (App\Models\RentalApplicationAssessment) — this
     * action is the only way to set/clear that link from Review.
     *
     * Deliberately its OWN action, not a reopening of
     * RentalApplicationController::update() — that route is now hard-
     * blocked for a submitted application (today's read-only-view fix) and
     * stays blocked. This is a narrow, explicitly audited exception for
     * exactly one field, not a backdoor to the rest of the form.
     */
    public function linkProperty(Request $request, RentalApplication $rentalApplication, \App\Services\RentalApplications\RentalApplicationAuditService $audit)
    {
        $this->guardRentalApplication($rentalApplication);

        $validated = $request->validate([
            'property_id' => ['nullable', 'integer', 'exists:properties,id'],
        ]);

        $oldPropertyId = $rentalApplication->property_id;
        $newPropertyId = $validated['property_id'] ?? null;

        if ($oldPropertyId === $newPropertyId) {
            return back();
        }

        $oldProperty = $oldPropertyId ? \App\Models\Property::find($oldPropertyId) : null;
        $newProperty = $newPropertyId ? \App\Models\Property::find($newPropertyId) : null;

        $rentalApplication->property_id = $newPropertyId;
        $rentalApplication->save();

        $audit->log(
            $rentalApplication,
            eventCategory: 'property_link',
            eventType: $newPropertyId ? ($oldPropertyId ? 'changed' : 'linked') : 'cleared',
            user: $request->user(),
            oldValues: ['property_id' => $oldPropertyId, 'address' => $oldProperty?->buildDisplayAddress()],
            newValues: ['property_id' => $newPropertyId, 'address' => $newProperty?->buildDisplayAddress()],
            humanSummary: $newProperty
                ? "Linked property: {$newProperty->buildDisplayAddress()}"
                : 'Cleared the linked property',
        );

        return back()->with('success', $newProperty ? 'Property linked.' : 'Property link cleared.');
    }

    public function show(Request $request, RentalApplication $rentalApplication): View
    {
        $this->guardRentalApplication($rentalApplication);
        $rentalApplication->load(['contact', 'property', 'signatures', 'documents.documentType', 'generations']);

        $assessment = RentalApplicationAssessment::firstOrNew(
            ['rental_application_id' => $rentalApplication->id],
            ['agency_id' => $rentalApplication->agency_id],
        );
        $assessment->setRelation('incomeItems', $assessment->exists ? $assessment->incomeItems : collect());
        $assessment->setRelation('expenseItems', $assessment->exists ? $assessment->expenseItems : collect());

        $maxRentPercent = RentalApplicationQualifyingSetting::maxRentPercentFor((int) $rentalApplication->agency_id);
        $result = $assessment->exists ? $assessment->qualifyingResult($maxRentPercent) : null;

        $highlightedByDocId = RentalApplicationDocumentHighlight::whereIn('document_id', $rentalApplication->documents->pluck('id'))
            ->whereNotNull('highlighted_file_path')
            ->pluck('id', 'document_id');

        $documents = $rentalApplication->documents->map(function (Document $document) use ($highlightedByDocId) {
            return [
                'document' => $document,
                'inline_viewable' => $this->isInlineViewable($document->mime_type),
                'has_highlights' => $highlightedByDocId->has($document->id),
            ];
        });

        // Conductor, 2026-09-08 (night run) — "Request more information" on
        // the authoriser screen promises "Sends this back to the agent, not
        // the applicant," but this screen showed nothing: the only channel
        // was a best-effort email (silently caught and logged on failure,
        // never retried). The reason IS already durable, in
        // rental_application_status_history — surfacing whatever the LATEST
        // entry says, so the banner is exactly the current truth and clears
        // itself the moment the agent resubmits (submitForApproval() below
        // writes its own new history row, becoming the new latest).
        $latestHistory = $rentalApplication->statusHistory()->latest('created_at')->first();
        $moreInfoRequestedNote = ($latestHistory && str_starts_with((string) $latestHistory->note, 'Authoriser requested more information:'))
            ? trim(substr($latestHistory->note, strlen('Authoriser requested more information:')))
            : null;

        // Johan, 2026-09-09, verbatim: "yes they should see it. the auth
        // needs to report back to the agent why the application has been
        // rejected." Read as a requirement, not a visibility setting — the
        // reason existing somewhere in the audit trail for the agent to go
        // find isn't enough; this is the outcome itself, shown at the top
        // of the page. If status is currently 'declined', the LATEST
        // history row is guaranteed to be the one that set it there (any
        // later action would have moved status away from 'declined'), so
        // no separate query is needed — same $latestHistory as above.
        $declineInfo = ($rentalApplication->status === 'declined' && $latestHistory && $latestHistory->to_status === 'declined')
            ? ['reason' => $latestHistory->note]
            : null;

        // Highlighter collection expansion, 2026-09-09 — Johan: "an agency
        // can have 10 highlighters set up." EVERY highlighter for this
        // agency is sent, including archived ones (the legend, and
        // fillFor() for an existing mark, need to resolve colours
        // regardless of archived state) — the JS itself is what only ever
        // offers the CURRENT user's own active, role-visible ones on the
        // drawing toolbar (Johan: "do not show anyone six" — now "do not
        // show anyone the other role's, or the archived ones" — a
        // picker-UI rule, not a data-hiding one).
        $highlighters = \App\Models\RentalApplicationHighlighter::allFor((int) $rentalApplication->agency_id)
            ->map(fn ($h) => [
                'id' => $h->id, 'label' => $h->label, 'color' => $h->color,
                'role_scope' => $h->role_scope, 'archived' => $h->trashed(),
            ])->values();

        // Unified screen, 2026-09-09 — Johan: "did I not tell you the
        // reviewer screen is essentially the same screen as the agent
        // screen?" This controller and RentalApplicationAuthorisationController
        // now render the SAME view; $viewerRole is the only thing that tells
        // it which role is looking. Audit trail moved here too — visible to
        // both roles now, per Johan's ruling ("an agent seeing what happened
        // to their own submission is a feature not a leak") — same cap/toggle
        // as the authoriser screen already had, so a busy application can't
        // bury anything below it on either screen.
        $viewerRole = 'agent';
        $auditLogTotal = $rentalApplication->auditLog()->count();
        $auditLog = $rentalApplication->auditLog()->with('user')->latest('created_at')->limit(200)->get();

        return view('corex.rental-applications.review', compact(
            'rentalApplication', 'assessment', 'maxRentPercent', 'result', 'documents', 'moreInfoRequestedNote', 'declineInfo', 'highlighters',
            'viewerRole', 'auditLog', 'auditLogTotal'
        ))->with('isPendingAuthorisation', $rentalApplication->isPendingAuthorisation());
    }

    /**
     * AT-392 authoriser flow — the agent's own "request more information
     * from the applicant" action. Johan: "reuse the existing applicant flow
     * and token rather than inventing a second one" — the applicant's link
     * (rental-applications.public.show) already allows adding documents at
     * any status (cc4's add-after-submit build), so this only needs to
     * notify them and log the request; no status change, no new token.
     */
    public function requestMoreInfoFromApplicant(Request $request, RentalApplication $rentalApplication, RentalApplicationMailer $mailer)
    {
        $this->guardRentalApplication($rentalApplication);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        if (! $rentalApplication->token) {
            return response()->json(['error' => 'This application has no applicant link yet — send it first.'], 422);
        }

        $sent = $mailer->sendMoreInfoRequest($rentalApplication, $validated['note']);

        RentalApplicationStatusHistory::record(
            $rentalApplication,
            $rentalApplication->status,
            $rentalApplication->status,
            $request->user(),
            'Requested more information from applicant: ' . $validated['note'],
        );

        return response()->json([
            'ok' => true,
            'mail_sent' => $sent,
        ]);
    }

    /**
     * Reopen/resubmit, 2026-09-08 — Johan: "after a rental application comes
     * back to the agent, the agent must be able to send it BACK to the
     * applicant so the applicant can reopen it, edit what they entered, and
     * re-sign it." Only reachable from RentalApplication::REOPENABLE_STATUSES.
     * A required note (mirrors requestMoreInfoFromApplicant()'s own shape) —
     * both because the applicant-facing email needs something to say and
     * because a status change of this weight belongs in the audit trail
     * with a reason, same as every other agent judgement call on this
     * screen.
     *
     * 2026-09-09 — Johan: "co should be able to reopen [a declined
     * application]. maybe declined and more evidence given so can work
     * with it again?" Reopening from 'declined' is now allowed, but ONLY
     * for the rental-application override tier (a configured CO, or
     * admin/super_admin — see User::isRentalApplicationOverrideTier(),
     * the SAME check guardNotSelfApproving() already enforces elsewhere,
     * extracted rather than copied a third time). An ordinary agent
     * reopening from 'returned'/'under_assessment' is unchanged — the
     * existing guardRentalApplication() ownership check alone still
     * governs that, no new restriction there. This is a real 403 on the
     * action itself, not a hidden button — a button hidden from a
     * crafted request is not a gate.
     *
     * Nothing about the prior decision is touched: the decline reason and
     * its status-history row stay exactly as they were (this method only
     * ever appends a new row, never edits or deletes one — same for every
     * status transition it's always recorded), the authoriser's document
     * marks and the agent's captured income/expense items are untouched
     * (this method writes only to the application row itself), and
     * signatures continue to version rather than overwrite on the
     * applicant's next real resubmission (RentalApplicationSigningController
     * ::submit() creates a new numbered generation unconditionally — reopen
     * doesn't touch signatures at all, so there is nothing new to prove
     * there). Also now writes its own audit-log entry (see
     * RentalApplicationAuditService), naming who reopened it and, when
     * reopening from 'declined', that it required the override tier —
     * mirroring exactly how decline() already records itself, so "who did
     * what and when" reads the same way for both actions.
     *
     * Reuses the SAME token the applicant already has (never regenerates
     * it) — refreshing only its expiry, via the agency-configurable
     * reopen_link_expiry_days setting, defaulting to the same 14-day window
     * the original invite link uses. The applicant's answers are left
     * exactly as they are: nothing is cleared, which is what makes the
     * public form pre-fill automatically (Johan: "prefilled - its a
     * reopen, not new") — see RentalApplicationSigningController::show().
     */
    public function reopen(Request $request, RentalApplication $rentalApplication, RentalApplicationMailer $mailer, RentalApplicationAuditService $audit)
    {
        $this->guardRentalApplication($rentalApplication);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:2000'],
        ]);

        if (! in_array($rentalApplication->status, RentalApplication::REOPENABLE_STATUSES, true)) {
            return response()->json(['error' => 'This application can\'t be reopened from its current status.'], 422);
        }

        $fromStatus = $rentalApplication->status;
        $isOverrideReopen = $fromStatus === 'declined';

        if ($isOverrideReopen) {
            abort_unless(
                $request->user()->isRentalApplicationOverrideTier((int) $rentalApplication->agency_id),
                403,
                'Only the head of rentals (or an admin) may reopen a declined application.',
            );
        }

        if (! $rentalApplication->token) {
            return response()->json(['error' => 'This application has no applicant link yet — send it first.'], 422);
        }

        $expiryDays = RentalApplicationQualifyingSetting::reopenLinkExpiryDaysFor((int) $rentalApplication->agency_id);

        $rentalApplication->status = 'reopened';
        $rentalApplication->reopened_at = now();
        $rentalApplication->reopened_by_user_id = $request->user()->id;
        $rentalApplication->reopened_note = $validated['note'];
        $rentalApplication->token_expires_at = now()->addDays($expiryDays);
        $rentalApplication->save();

        RentalApplicationStatusHistory::record(
            $rentalApplication,
            $fromStatus,
            'reopened',
            $request->user(),
            'Reopened for the applicant: ' . $validated['note'],
        );

        $audit->log(
            $rentalApplication,
            eventCategory: 'reopen',
            eventType: 'reopened',
            user: $request->user(),
            isOverride: $isOverrideReopen,
            reason: $validated['note'],
            oldValues: ['status' => $fromStatus],
            newValues: ['status' => 'reopened'],
            humanSummary: $isOverrideReopen
                ? 'Reopened a declined application (override)'
                : 'Reopened for the applicant',
        );

        $sent = $mailer->sendReopened($rentalApplication, $validated['note']);

        return response()->json([
            'ok' => true,
            'mail_sent' => $sent,
            'status' => $rentalApplication->status,
            'token_expires_at' => $rentalApplication->token_expires_at->toIso8601String(),
        ]);
    }

    /**
     * Reopen/resubmit, 2026-09-08 — "a read-only signed view must be able
     * to show what was signed at each point" (Johan, non-negotiable). Reads
     * ONLY from the sealed, append-only RentalApplicationGeneration row —
     * never from the live rental_applications row, which may since have
     * moved on to a later generation. 404s (not 403) for a generation
     * number that doesn't exist on this application, same "don't confirm
     * what's valid" posture as scopedDocument() elsewhere in this module.
     */
    public function showGeneration(RentalApplication $rentalApplication, int $generation): View
    {
        $this->guardRentalApplication($rentalApplication);

        $sealed = \App\Models\RentalApplicationGeneration::where('rental_application_id', $rentalApplication->id)
            ->where('generation', $generation)
            ->first();
        abort_unless($sealed, 404);

        $signatures = $rentalApplication->signatures()->where('generation', $generation)->get();
        $latestGeneration = (int) \App\Models\RentalApplicationGeneration::where('rental_application_id', $rentalApplication->id)->max('generation');

        return view('corex.rental-applications.generation-show', [
            'rentalApplication' => $rentalApplication,
            'sealed' => $sealed,
            'signatures' => $signatures,
            'isLatest' => $generation === $latestGeneration,
            'latestGeneration' => $latestGeneration,
        ]);
    }

    /**
     * AT-392 authoriser flow — the agent hands the application to the
     * authoriser. Deliberately NOT a status change (see RentalApplication::
     * isPendingAuthorisation()) — status stays under_assessment, this
     * timestamp is the marker. Re-submittable any number of times (e.g.
     * after an authoriser asks for more info) — always just bumps the
     * timestamp and logs again.
     */
    public function submitForApproval(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        abort_unless(in_array($rentalApplication->status, RentalApplication::POST_RETURN_STATUSES, true), 422);

        // Reopen/resubmit, 2026-09-08 — never hand a stale generation to the
        // authoriser: if the applicant reopened-and-resubmitted since this
        // agent's screen loaded, refuse rather than submitting a decision
        // window against content that's since changed.
        $expectedGeneration = $request->input('expected_generation');
        try {
            $rentalApplication->assertGenerationMatches($expectedGeneration !== null ? (int) $expectedGeneration : null);
        } catch (\App\Exceptions\RentalApplicationGenerationConflictException $e) {
            return response()->json([
                'error' => 'This application changed since you opened it — reload to see the new version before submitting for approval.',
                'reason' => 'generation_conflict',
                'current_generation' => $e->currentGeneration,
            ], 409);
        }

        $rentalApplication->status = 'under_assessment';
        $rentalApplication->submitted_for_approval_at = now();
        $rentalApplication->save();

        RentalApplicationStatusHistory::record(
            $rentalApplication,
            $rentalApplication->status,
            $rentalApplication->status,
            $request->user(),
            'Submitted for authorisation.',
        );

        return response()->json([
            'ok' => true,
            'submitted_for_approval_at' => $rentalApplication->submitted_for_approval_at->toIso8601String(),
        ]);
    }

    /**
     * Autosave — called on every field blur/change from the right panel, not
     * a single final submit. "Nothing the agent types may ever be lost" —
     * this has bitten the feature three times today on other screens, so
     * this endpoint is deliberately fired on every change, not on navigate-
     * away. Every field is optional (BUILD_STANDARD §2) — a partial save is
     * a normal, expected state, not an error.
     */
    public function saveAssessment(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        // RA-02 (cc5 re-test, Round 8) — every numeric money field on this
        // feature. Round 9 (item 5) — monthly_income/other_monthly_income/
        // monthly_expenses became growable lists; the sanitizer still
        // applies to each item's own 'amount', not a top-level field.
        $incomeItemsInput = array_map(
            fn ($item) => RentalApplication::sanitizeNumericInput((array) $item, ['amount']),
            (array) $request->input('income_items', []),
        );
        $expenseItemsInput = array_map(
            fn ($item) => RentalApplication::sanitizeNumericInput((array) $item, ['amount']),
            (array) $request->input('expense_items', []),
        );
        $request->merge(['income_items' => $incomeItemsInput, 'expense_items' => $expenseItemsInput]);

        $validated = $request->validate([
            'income_items' => ['nullable', 'array'],
            'income_items.*.id' => ['nullable', 'integer'],
            'income_items.*.description' => ['nullable', 'string', 'max:255'],
            'income_items.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'expense_items' => ['nullable', 'array'],
            'expense_items.*.id' => ['nullable', 'integer'],
            'expense_items.*.description' => ['nullable', 'string', 'max:255'],
            'expense_items.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Round 11 — Johan: "we have to ask the nr of months the bank
            // statement is for." A bank statement's captured lines are a
            // lump sum over this many months, not a monthly figure.
            'statement_months' => ['nullable', 'integer', 'min:1', 'max:36'],
            // Round 16 — Johan: "unpaid transactions on bank statement...
            // this is a dangerous app." A single flag, not a list of
            // amounts — individual declined lines are marked on the
            // document itself via the highlighter.
            'has_unpaid_transactions' => ['nullable', 'boolean'],
            'expected_generation' => ['nullable', 'integer', 'min:1'],
        ]);

        // Reopen/resubmit, 2026-09-08 — Johan: "agent has review screen open,
        // applicant resubmits mid-review... reuse the exact 409-conflict
        // pattern you already built and shipped for document marks." The
        // review screen bootstraps `expected_generation` from the page it
        // rendered; a mismatch here means the applicant reopened-and-
        // resubmitted since then, and this autosave must not silently land
        // against content the agent hasn't actually seen.
        try {
            $rentalApplication->assertGenerationMatches(array_key_exists('expected_generation', $validated) ? $validated['expected_generation'] : null);
        } catch (\App\Exceptions\RentalApplicationGenerationConflictException $e) {
            return response()->json([
                'error' => 'This application changed since you opened it — reload to see the new version.',
                'reason' => 'generation_conflict',
                'current_generation' => $e->currentGeneration,
            ], 409);
        }

        // A row the agent never filled in (no description, no amount) is the
        // ever-present trailing "type here to add another" placeholder —
        // Johan: "empty trailing rows must not save as zero-value rows or
        // clutter the record." Filtered server-side too, not just by the
        // frontend, since this is the only thing standing between a crafted
        // request and a junk row.
        $isBlank = fn ($item) => empty($item['description'] ?? null) && (($item['amount'] ?? null) === null || $item['amount'] === '');

        $assessment = RentalApplicationAssessment::updateOrCreate(
            ['rental_application_id' => $rentalApplication->id],
            [
                'agency_id' => $rentalApplication->agency_id,
                'notes' => ($validated['notes'] ?? '') === '' ? null : ($validated['notes'] ?? null),
                'statement_months' => ($validated['statement_months'] ?? '') === '' ? null : ($validated['statement_months'] ?? null),
                'has_unpaid_transactions' => $request->boolean('has_unpaid_transactions'),
                'updated_by_user_id' => $request->user()->id,
            ],
        );

        $this->syncItems(
            $assessment,
            RentalApplicationIncomeItem::class,
            array_values(array_filter($validated['income_items'] ?? [], fn ($i) => ! $isBlank($i))),
        );
        $this->syncItems(
            $assessment,
            RentalApplicationExpenseItem::class,
            array_values(array_filter($validated['expense_items'] ?? [], fn ($i) => ! $isBlank($i))),
        );

        $maxRentPercent = RentalApplicationQualifyingSetting::maxRentPercentFor((int) $rentalApplication->agency_id);
        $assessment = $assessment->fresh(['incomeItems', 'expenseItems']);

        // Round 9 (item 5) — the client must learn each row's real id after
        // its first save, or the NEXT autosave would have no way to match
        // existing rows and would create duplicates instead of updating
        // them. Echoing the canonical saved list back is simpler and safer
        // than the client guessing its own ids.
        return response()->json([
            'ok' => true,
            'result' => $assessment->qualifyingResult($maxRentPercent),
            'income_items' => $assessment->incomeItems->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'amount' => $i->amount])->values(),
            'expense_items' => $assessment->expenseItems->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'amount' => $i->amount])->values(),
            'saved_at' => $assessment->updated_at?->toIso8601String(),
        ]);
    }

    /**
     * Replace an assessment's income/expense line items with $items,
     * matched by id where the client already has one (a row it typed into
     * on a previous autosave). Rows no longer present are SOFT-deleted
     * (non-negotiable #1 — an agent removing a line from a financial
     * record is a real, recoverable event, never a hard delete), never
     * blind delete-all-then-recreate — that would soft-delete and
     * immediately recreate every unchanged row on every keystroke,
     * turning the audit trail into noise.
     *
     * @param class-string<RentalApplicationIncomeItem>|class-string<RentalApplicationExpenseItem> $modelClass
     */
    private function syncItems(RentalApplicationAssessment $assessment, string $modelClass, array $items): void
    {
        // AT-392 authoriser strike-out, 2026-09-08 — this endpoint replaces
        // the agent's WHOLE list on every autosave, matched by id, deleting
        // whatever isn't present. That was always safe while every row was
        // agent-owned. It stops being safe the moment a row can belong to
        // someone else: an authoriser-added row the agent's own browser
        // session never loaded would look "no longer present" to this
        // method and get silently soft-deleted on the agent's very next
        // keystroke-triggered autosave — the exact class of silent data
        // loss this feature exists to prevent, just relocated. Two guards:
        // (1) never delete a row someone else added, present or not in the
        // agent's submitted list; (2) never let this bulk path update a row
        // this user doesn't own — same ownership rule the dedicated
        // authoriser endpoints enforce with a 403, applied here as a silent
        // skip instead, since one disallowed row must not fail the agent's
        // otherwise-legitimate autosave of their OWN rows.
        $currentUserId = auth()->id();
        $keptIds = [];
        foreach (array_values($items) as $sortOrder => $item) {
            $attributes = [
                'agency_id' => $assessment->agency_id,
                'rental_application_assessment_id' => $assessment->id,
                'description' => ($item['description'] ?? '') === '' ? null : $item['description'],
                'amount' => ($item['amount'] ?? '') === '' ? null : $item['amount'],
                'sort_order' => $sortOrder,
            ];

            $row = ! empty($item['id'])
                ? $modelClass::where('rental_application_assessment_id', $assessment->id)->find($item['id'])
                : null;

            if ($row) {
                $ownedByCurrentUser = $row->added_by_user_id === null
                    ? (int) $assessment->rentalApplication->created_by_user_id === (int) $currentUserId
                    : (int) $row->added_by_user_id === (int) $currentUserId;
                if ($ownedByCurrentUser) {
                    $row->update($attributes);
                }
                // Not owned: leave it exactly as it is. Still kept (below),
                // so this bulk save can never delete it either.
            } else {
                // added_by_user_id stays unset — a row created via this
                // endpoint (the agent's own review screen) is the agent's
                // original capture, same meaning "null" already had before
                // this column existed. Ownership for a null row resolves to
                // whoever the APPLICATION is attributed to
                // (created_by_user_id), not whichever user happened to be
                // logged in when this specific row was typed — own/branch/
                // agency scope can let more than one agent open the same
                // review screen, and "the agent's capture" means the
                // application's agent, not a session identity.
                $row = $modelClass::create($attributes);
            }

            $keptIds[] = $row->id;
        }

        // Rows added by someone OTHER than the current user are never
        // eligible for this cleanup, whether or not they appear in the
        // agent's submitted list — the agent's browser may simply never
        // have loaded them yet.
        $modelClass::where('rental_application_assessment_id', $assessment->id)
            ->whereNotIn('id', $keptIds ?: [0])
            ->where(function ($q) use ($currentUserId) {
                $q->whereNull('added_by_user_id')->orWhere('added_by_user_id', $currentUserId);
            })
            ->delete();
    }

    // highlightFirstPage(), highlightRemainingPages(), applyHighlight() —
    // 2026-09-08, moved to the shared HandlesRentalApplicationDocumentMarks
    // trait (see its docblock) once the authoriser screen also needed to
    // mark up documents — this controller's own copies would otherwise
    // have been the second, independently-maintainable implementation of
    // the exact partial-save protection the critical fix built.

    /**
     * Playback for "the next party" — mirrors ViewingPackController::
     * redactedFile(). Whoever opens this document next (including the agent
     * reopening it) is served the marked-up copy automatically once one
     * exists; 404 if none has been applied yet (the caller falls back to
     * viewDocumentInline for the plain original).
     */
    public function highlightedFile(RentalApplication $rentalApplication, Document $document)
    {
        $this->guardRentalApplication($rentalApplication);
        $this->guardDocumentBelongsToApplication($rentalApplication, $document);

        $highlight = RentalApplicationDocumentHighlight::where('document_id', $document->id)->first();
        abort_if(!$highlight || !$highlight->highlighted_file_path, 404);
        abort_unless(\Storage::disk('local')->exists($highlight->highlighted_file_path), 404);

        return response()->streamDownload(
            function () use ($highlight) {
                echo \Storage::disk('local')->get($highlight->highlighted_file_path);
            },
            $document->original_name,
            ['Content-Type' => 'application/pdf'],
            'inline',
        );
    }

    private function guardDocumentBelongsToApplication(RentalApplication $rentalApplication, Document $document): void
    {
        abort_unless(
            $document->source_type === 'rental_application' && (int) $document->source_id === $rentalApplication->id,
            404
        );
    }

    /**
     * Inline document view for the left panel — the same scope guard AND the
     * same source_type/source_id defense-in-depth check downloadDocument()
     * already uses, so this can never open a door that action doesn't. PDFs
     * and images stream inline (Content-Disposition: inline); everything
     * else (doc/docx — the allowlist also permits these, and a browser
     * cannot render them natively) redirects to the existing download route
     * rather than attempting a broken inline render.
     */
    public function viewDocumentInline(RentalApplication $rentalApplication, Document $document)
    {
        $this->guardRentalApplication($rentalApplication);
        $this->guardDocumentBelongsToApplication($rentalApplication, $document);

        if (!$this->isInlineViewable($document->mime_type)) {
            return redirect()->route('corex.rental-applications.documents.download', [
                $rentalApplication, $document,
            ]);
        }

        return response()->streamDownload(
            function () use ($document) {
                echo $document->decryptedContents();
            },
            $document->original_name,
            ['Content-Type' => $document->mime_type ?: 'application/octet-stream'],
            'inline',
        );
    }

    private function isInlineViewable(?string $mimeType): bool
    {
        $mimeType = $mimeType ?? '';
        foreach (self::INLINE_VIEWABLE_MIME_PREFIXES as $prefix) {
            if (str_starts_with($mimeType, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
