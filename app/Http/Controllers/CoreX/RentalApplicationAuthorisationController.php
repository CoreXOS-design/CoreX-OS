<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\HandlesRentalApplicationDocumentMarks;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAssessment;
use App\Models\RentalApplicationDocumentHighlight;
use App\Models\RentalApplicationDocumentValidityWindow;
use App\Models\RentalApplicationExpenseItem;
use App\Models\RentalApplicationIncomeItem;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplicationStatusHistory;
use App\Models\User;
use App\Services\RentalApplications\RentalApplicationAuditService;
use App\Services\RentalApplications\RentalApplicationMailer;
use App\Services\RentalApplications\RentalApplicationNotifier;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * AT-392 authoriser flow, 2026-09-08. Johan, verbatim: "auth goes through
 * and only the auth can accept / reject / ask for more information etc,"
 * and the two-tier design: "ro then co approval process? so admin or bm
 * acts like the co. selected agents act as ro... ro can approve / decline.
 * but then lets say the tenant speaks to admin and they decide they want to
 * override ro, then can approve / decline with reasons given... like an
 * admin override."
 *
 * A NEW controller, not an addition to RentalApplicationReviewController —
 * access here is gated on RO/CO tier membership (User::isRentalApplicationRO()
 * / isRentalApplicationCO()), a completely different check from the
 * ordinary rental_applications.view permission every agent already has.
 *
 * Tier rules, enforced server-side on every action below, not by hiding a
 * button:
 *   - RO or CO may make the FIRST decision on a pending application
 *     (approve/decline/request-more-info) — reason optional.
 *   - Once a decision exists (status is already approved/declined), only a
 *     CO may change it — that's an OVERRIDE, is_override=true on the audit
 *     row, reason REQUIRED. An RO attempting to override is refused (403).
 */
class RentalApplicationAuthorisationController extends Controller
{
    use HandlesRentalApplicationDocumentMarks;
    use \App\Http\Controllers\Concerns\FiltersRentalApplicationList;

    /** Mime types the browser can render natively — mirrors RentalApplicationReviewController exactly. */
    private const INLINE_VIEWABLE_MIME_PREFIXES = ['application/pdf', 'image/'];

    /**
     * Fulfils HandlesRentalApplicationDocumentMarks's guard requirement.
     * 2026-09-08 — Johan: "the auth should be able to write on the docs as
     * well making notes etc." Deliberately guardCanView(), not
     * guardCanDecide() — marking up a document is not itself a decision,
     * and an RO/CO who can see the application can mark it up regardless
     * of whether they're the one who'll end up deciding it.
     */
    protected function guardDocumentMarkAccess(RentalApplication $rentalApplication, Document $document): void
    {
        $this->guardCanView($rentalApplication);
        $this->guardDocumentBelongsToApplication($rentalApplication, $document);
    }

    /** Every mark this controller's save creates is stamped 'authoriser' — this is the authorisation screen. */
    protected function markAuthorRole(): string
    {
        return 'authoriser';
    }

    /**
     * Johan, 2026-09-09, verbatim ruling: "Self approve should only work for
     * the co of rentals or admin - rest agents and ro can not approve their
     * own." Applies to every decision (approve/decline/request-more-info) —
     * the point is an independent set of eyes on the file, and a self-
     * reviewer asking themselves for more information isn't independent
     * review either. "Administrator" isn't a separate concept here — the
     * plain users.role column, same check AgencySetupWizardController
     * already uses ('admin'); super_admin included too since it's strictly
     * the same tier one level up, not a different concept.
     */
    private function guardNotSelfApproving(RentalApplication $rentalApplication, User $user): void
    {
        if ((int) $rentalApplication->created_by_user_id !== (int) $user->id) {
            return;
        }

        abort_unless(
            $user->isRentalApplicationOverrideTier((int) $rentalApplication->agency_id),
            403,
            'You created this application, so it needs another authoriser.',
        );
    }

    /**
     * @return array{tier: string, is_override: bool}
     */
    private function guardCanDecide(RentalApplication $rentalApplication): array
    {
        /** @var User|null $user */
        $user = auth()->user();
        abort_unless($user !== null, 403);

        $isRO = $user->isRentalApplicationRO((int) $rentalApplication->agency_id);
        $isCO = $user->isRentalApplicationCO((int) $rentalApplication->agency_id);
        abort_unless($isRO || $isCO, 403, 'Only a configured Reviewer or Override user may act on this application.');

        $this->guardNotSelfApproving($rentalApplication, $user);

        $alreadyDecided = in_array($rentalApplication->status, ['approved', 'declined'], true);

        if ($alreadyDecided) {
            abort_unless($isCO, 403, 'This application already has a decision — only an Override (CO) user may change it.');

            return ['tier' => 'co', 'is_override' => true];
        }

        abort_unless($rentalApplication->isPendingAuthorisation(), 422, 'This application is not currently awaiting authorisation.');

        return ['tier' => $isCO ? 'co' : 'ro', 'is_override' => false];
    }

    private function guardCanView(RentalApplication $rentalApplication): void
    {
        $user = auth()->user();
        abort_unless($user !== null, 403);
        abort_unless(
            $user->isRentalApplicationRO((int) $rentalApplication->agency_id) || $user->isRentalApplicationCO((int) $rentalApplication->agency_id),
            403,
        );
    }

    /**
     * Everything an agency's RO/CO users currently have waiting on them —
     * the underlying BelongsToAgency global scope on RentalApplication still
     * means a user can only ever see their OWN agency's applications, cross-
     * agency data never reaches this query at all.
     *
     * Search/sort, 2026-09-09 (design-standard audit) — Johan: "search must
     * cover what an RO or CO would actually type: applicant name, property,
     * agent." Reuses FiltersRentalApplicationList — the exact same logic
     * index()/returned() already have — rather than a third hand-rolled
     * copy. Sortable: contact, property, agent, submitted. Default stays
     * submitted-oldest-first (unchanged from before this task): the point
     * of a decision queue is working the longest-waiting application first,
     * so this is the one screen where the shared trait's own default
     * (newest-first) would be the wrong choice — passed explicitly.
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($user->isRentalApplicationRO() || $user->isRentalApplicationCO(), 403,
            'You are not configured as a rental application Reviewer or Override user. Ask an admin to add you in Settings.');

        // 2026-09-09 (cc5 regression pass) — this was the one place the
        // "one trait, three screens" symmetry broke: hardcoded paginate(20),
        // ?per_page= silently ignored, no selector in the blade. Now
        // identical to index()/returned() — same options, same default —
        // not just the search/sort logic.
        $perPage = $this->resolvePerPage($request);

        $query = RentalApplication::whereNotNull('submitted_for_approval_at')
            ->where('rental_applications.status', 'under_assessment')
            ->with(['contact', 'property', 'createdBy']);

        $this->applySearchSortAndDateRange($query, $request, 'submitted_for_approval_at', 'submitted_for_approval_at', 'asc');

        $applications = $query->paginate($perPage)->withQueryString();

        return view('corex.rental-applications.authorisation.index', compact('applications', 'perPage'));
    }

    /**
     * The authoriser's read view. Deliberately reuses the SAME document +
     * highlight + assessment data shape RentalApplicationReviewController::
     * show() builds — Johan: "the authoriser must see the agent's
     * highlights on the documents — that is what persisting marks was for."
     */
    public function show(Request $request, RentalApplication $rentalApplication): View
    {
        $this->guardCanView($rentalApplication);

        $rentalApplication->load(['contact', 'property', 'signatures', 'documents.documentType', 'referencedDocuments.documentType']);

        $assessment = RentalApplicationAssessment::firstOrNew(
            ['rental_application_id' => $rentalApplication->id],
            ['agency_id' => $rentalApplication->agency_id],
        );

        $maxRentPercent = RentalApplicationQualifyingSetting::maxRentPercentFor((int) $rentalApplication->agency_id);
        $result = $assessment->exists ? $assessment->qualifyingResult($maxRentPercent) : null;

        // AT-392 "pull from contact" — the unified screen shows the
        // authoriser the same set of documents the agent sees, including
        // anything pulled from the contact's file history (not just what
        // this application owns).
        $allDocIds = $rentalApplication->documents->pluck('id')->merge($rentalApplication->referencedDocuments->pluck('id'));
        $highlightedByDocId = RentalApplicationDocumentHighlight::whereIn('document_id', $allDocIds)
            ->whereNotNull('highlighted_file_path')
            ->pluck('id', 'document_id');

        $agencyId = (int) $rentalApplication->agency_id;
        $documents = $rentalApplication->documents->map(function (Document $document) use ($highlightedByDocId, $agencyId) {
            return [
                'document' => $document,
                'inline_viewable' => $this->isInlineViewable($document->mime_type),
                'has_highlights' => $highlightedByDocId->has($document->id),
                'pulled_from_contact' => false,
                'staleness_warning' => RentalApplicationDocumentValidityWindow::stalenessWarning(
                    $document->created_at, $agencyId, 'rental_application', $document->document_type_id
                ),
            ];
        })->concat($rentalApplication->referencedDocuments->map(function (Document $document) use ($highlightedByDocId, $agencyId) {
            return [
                'document' => $document,
                'inline_viewable' => $this->isInlineViewable($document->mime_type),
                'has_highlights' => $highlightedByDocId->has($document->id),
                'pulled_from_contact' => true,
                'staleness_warning' => RentalApplicationDocumentValidityWindow::stalenessWarning(
                    $document->created_at, $agencyId, 'rental_application', $document->document_type_id
                ),
            ];
        }));

        $history = $rentalApplication->statusHistory()->with('changedBy')->latest('created_at')->get();

        // Conductor, 2026-09-08 (night run) — a busy application's audit
        // trail is unbounded by construction (every strike/add/replace/mark
        // writes a row); rendering ALL of it buried the Decision panel below
        // an ever-growing scroll and ran an uncapped query on every page
        // load. Capped at a sane ceiling; the view shows the newest 10 by
        // default with a "Show all" toggle for the rest of this page's load,
        // and tells the user honestly if even the cap was hit.
        $auditLogTotal = $rentalApplication->auditLog()->count();
        $auditLog = $rentalApplication->auditLog()->with('user')->latest('created_at')->limit(200)->get();

        $user = $request->user();
        $canOverride = $user->isRentalApplicationCO((int) $rentalApplication->agency_id);
        $alreadyDecided = in_array($rentalApplication->status, ['approved', 'declined'], true);

        // Johan, 2026-09-09 — self-approval block (see guardNotSelfApproving()
        // on the decision endpoints for the server-enforced version this
        // mirrors). Computed here, read-only, purely so the Decision panel
        // can say WHY the buttons are gone instead of leaving Johan to guess
        // — never the actual gate; guardNotSelfApproving() alone decides
        // what the server will accept.
        $selfCreated = (int) $rentalApplication->created_by_user_id === (int) $user->id;
        $blockedBySelfApproval = $selfCreated && ! $user->isRentalApplicationOverrideTier((int) $rentalApplication->agency_id);

        // Same shape the add/strike AJAX endpoints return (serializeItem()),
        // built once here so the initial page load and every subsequent
        // write agree on exactly what a row looks like — never a second,
        // simpler shape hand-rolled in the blade that could drift from it.
        $serializedIncomeItems = $assessment->exists
            ? $assessment->incomeItems->map(fn ($i) => $this->serializeItem($i, $user))->values()
            : collect();
        $serializedExpenseItems = $assessment->exists
            ? $assessment->expenseItems->map(fn ($i) => $this->serializeItem($i, $user))->values()
            : collect();

        // Highlighter collection expansion, 2026-09-09 — same reasoning as
        // RentalApplicationReviewController::show().
        $highlighters = \App\Models\RentalApplicationHighlighter::allFor((int) $rentalApplication->agency_id)
            ->map(fn ($h) => [
                'id' => $h->id, 'label' => $h->label, 'color' => $h->color,
                'role_scope' => $h->role_scope, 'archived' => $h->trashed(),
            ])->values();

        // Unified screen, 2026-09-09 — Johan: "did I not tell you the
        // reviewer screen is essentially the same screen as the agent
        // screen? same fucking problem I have been describing all along."
        // This route stays separate (guardCanView()'s RO/CO tier check is a
        // genuinely different question from guardRentalApplication()'s
        // ownership/branch/agency scope — collapsing the two guards would be
        // exactly the fragile conflation that's bitten this feature before),
        // but now renders the SAME view as RentalApplicationReviewController
        // ::show() rather than a second blade — $viewerRole is the only
        // thing telling it which role is looking. The authorisation queue's
        // links are unchanged; they still point here.
        $viewerRole = 'authoriser';

        return view('corex.rental-applications.review', compact(
            'rentalApplication', 'assessment', 'maxRentPercent', 'result', 'documents', 'history', 'auditLog', 'auditLogTotal', 'canOverride', 'alreadyDecided',
            'blockedBySelfApproval', 'serializedIncomeItems', 'serializedExpenseItems', 'highlighters', 'viewerRole'
        ));
    }

    public function approve(
        Request $request,
        RentalApplication $rentalApplication,
        RentalApplicationAuditService $audit,
        RentalApplicationNotifier $notifier,
    ) {
        $decision = $this->guardCanDecide($rentalApplication);

        // RA-02 (cc5 re-test, Round 8) — "the screen where an authoriser
        // APPROVES a tenant still rejects a comma in the rand amount."
        // Same sanitizer as every other money field on this feature: strip
        // thousand-separator commas, spaces, and a leading "R" prefix
        // before validation ever sees it.
        $request->merge(RentalApplication::sanitizeNumericInput(
            $request->only(['approved_rental_amount']),
            ['approved_rental_amount'],
        ));

        $validated = $request->validate([
            // Johan: "capture the approved amount... update agent rental
            // screen - tenant approved for x amount." Required — the whole
            // point of this outcome is that figure.
            'approved_rental_amount' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'reason' => $decision['is_override'] ? ['required', 'string', 'max:2000'] : ['nullable', 'string', 'max:2000'],
        ]);

        $fromStatus = $rentalApplication->status;
        $oldAmount = $rentalApplication->approved_rental_amount;

        $rentalApplication->status = 'approved';
        $rentalApplication->approved_rental_amount = $validated['approved_rental_amount'];
        $rentalApplication->save();

        RentalApplicationStatusHistory::record(
            $rentalApplication, $fromStatus, 'approved', $request->user(), $validated['reason'] ?? null,
        );

        $audit->log(
            $rentalApplication,
            eventCategory: 'authorisation',
            eventType: $decision['is_override'] ? 'approved_override' : 'approved',
            user: $request->user(),
            isOverride: $decision['is_override'],
            reason: $validated['reason'] ?? null,
            oldValues: ['status' => $fromStatus, 'approved_rental_amount' => $oldAmount],
            newValues: ['status' => 'approved', 'approved_rental_amount' => $validated['approved_rental_amount']],
            humanSummary: ($decision['is_override'] ? 'Overrode a prior decision to approve' : 'Approved')
                . " for R" . number_format((float) $validated['approved_rental_amount'], 2) . " ({$decision['tier']})",
        );

        // AT-392 — Johan changed the flow: approval no longer auto-emails
        // the applicant. "no, agent gets back and upon them being happy it
        // gets sent out." The agent decides the wishlist and sends —
        // see RentalApplicationAgentSendController::send(). notifyAgentOfDecision
        // is how the agent finds out approval happened at all; sendApproved()
        // no longer fires from here.
        $notifier->notifyAgentOfDecision($rentalApplication, 'approved', $validated['reason'] ?? null, $decision['is_override']);

        // AT-392 — keeps Contact::rental_application_status in sync
        // (App\Listeners\Contact\RecomputeRentalApplicationStatus).
        event(new \App\Events\RentalApplication\RentalApplicationApproved($rentalApplication, $request->user()?->id));

        return redirect()->route('corex.rental-applications.authorisation.index')
            ->with('success', 'Application approved.');
    }

    public function decline(
        Request $request,
        RentalApplication $rentalApplication,
        RentalApplicationAuditService $audit,
        RentalApplicationMailer $mailer,
        RentalApplicationNotifier $notifier,
        \App\Services\RentalApplications\RentalApplicationPdfService $pdfService,
    ) {
        $decision = $this->guardCanDecide($rentalApplication);

        // Johan, 2026-09-09, verbatim: "yes they should see it. the auth
        // needs to report back to the agent why the application has been
        // rejected." A decline with no reason tells the agent nothing — the
        // exact failure this closes. Required unconditionally now, not just
        // on override; Approve stays reason-optional on a first decision
        // (Johan: "an approval with an amount is self-explanatory").
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $fromStatus = $rentalApplication->status;
        $rentalApplication->status = 'declined';
        $rentalApplication->save();

        RentalApplicationStatusHistory::record(
            $rentalApplication, $fromStatus, 'declined', $request->user(), $validated['reason'] ?? null,
        );

        $audit->log(
            $rentalApplication,
            eventCategory: 'authorisation',
            eventType: $decision['is_override'] ? 'declined_override' : 'declined',
            user: $request->user(),
            isOverride: $decision['is_override'],
            reason: $validated['reason'] ?? null,
            oldValues: ['status' => $fromStatus],
            newValues: ['status' => 'declined'],
            humanSummary: ($decision['is_override'] ? 'Overrode a prior decision to decline' : 'Declined') . " ({$decision['tier']})",
        );

        $notifier->notifyAgentOfDecision($rentalApplication, 'declined', $validated['reason'] ?? null, $decision['is_override']);
        // Applicant-facing wording is EXPLICITLY unsettled beyond the agency's
        // own configured template — Johan: "still playing with this idea" on
        // any "how to improve" guidance. The template itself (subject/body,
        // agency-editable) is built and sent here; no extra content invented.
        $mailer->sendDecline($rentalApplication);

        // AT-392 — Johan: "the documents / application / approval gets
        // filed on the contact." Best-effort, same as the approval leg.
        $pdfService->fileAsDocument($rentalApplication, 'Declined Rental Application');

        // AT-392 — keeps Contact::rental_application_status in sync
        // (App\Listeners\Contact\RecomputeRentalApplicationStatus).
        event(new \App\Events\RentalApplication\RentalApplicationDeclined($rentalApplication, $request->user()?->id));

        return redirect()->route('corex.rental-applications.authorisation.index')
            ->with('success', 'Application declined.');
    }

    /**
     * The AUTHORISER's "request more information" — a separate thing from
     * the agent's own version (which goes to the applicant). This one goes
     * back to the AGENT — Johan confirmed: "my reading is it goes back to
     * the AGENT, who then decides whether they need to go back to the
     * applicant" and this was subsequently confirmed as correct. Clears
     * submitted_for_approval_at (same marker the agent's submit-for-approval
     * action sets) — the application returns to "agent working," not a new
     * status value.
     */
    public function requestMoreInfo(
        Request $request,
        RentalApplication $rentalApplication,
        RentalApplicationAuditService $audit,
        RentalApplicationNotifier $notifier,
    ) {
        // Not guardCanDecide() — this is only ever a FIRST-stage action (you
        // cannot "request more info" on an application that already has a
        // final decision; that's what override is for), so it always uses
        // the non-override gate directly.
        $user = auth()->user();
        abort_unless($user !== null, 403);
        abort_unless(
            $user->isRentalApplicationRO((int) $rentalApplication->agency_id) || $user->isRentalApplicationCO((int) $rentalApplication->agency_id),
            403,
        );
        $this->guardNotSelfApproving($rentalApplication, $user);
        abort_unless($rentalApplication->isPendingAuthorisation(), 422, 'This application is not currently awaiting authorisation.');

        // A blank request tells the agent nothing — required, same reasoning
        // as the agent's own request-more-info-from-applicant action.
        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ]);

        $rentalApplication->submitted_for_approval_at = null;
        $rentalApplication->save();

        RentalApplicationStatusHistory::record(
            $rentalApplication, $rentalApplication->status, $rentalApplication->status, $request->user(),
            'Authoriser requested more information: ' . $validated['reason'],
        );

        $audit->log(
            $rentalApplication,
            eventCategory: 'authorisation',
            eventType: 'more_info_requested',
            user: $request->user(),
            reason: $validated['reason'],
            humanSummary: 'Requested more information, returned to agent',
        );

        $notifier->notifyAgentOfDecision($rentalApplication, 'more_info_requested', $validated['reason']);

        return redirect()->route('corex.rental-applications.authorisation.index')
            ->with('success', 'Sent back to the agent for more information.');
    }

    /**
     * AT-392 authoriser assessment markup, 2026-09-08. Johan first: "so the
     * auth can verify working through the doc... add / edit / remove
     * (remove im thinking is just a strike out tick)." Then, confirmed
     * directly, superseding "edit": "auth can rather strike out and re-add
     * a value than edit a value. this way we have the evidence needed of
     * who did what." This is Johan's stated rule, not a decision awaiting
     * his review — his own reason (the evidence trail) is what drives every
     * choice below where he didn't spell out the detail.
     *
     * There is no EDIT verb. Two mutations only:
     *   STRIKE  — anyone with review/authorisation access, on ANY row,
     *             including the agent's own capture. Server-enforced with
     *             no ownership check at all — that's deliberate, not an
     *             oversight: disagreement is expressed by striking +
     *             adding, never by changing a figure in place, so there is
     *             nothing to protect a row's owner FROM here.
     *   ADD     — anyone with review/authorisation access, attributed to
     *             them via added_by_user_id. Optionally carries
     *             replaces_item_id — set when this add follows a strike in
     *             the same flow, so the struck row and its replacement stay
     *             linked ("this figure was replaced by that one, by this
     *             person, at this time") even after a reload, not just for
     *             the current page session.
     *
     * Editing another user's captured value is not a withheld permission —
     * there is no code path anywhere below that can do it. Nobody may ever
     * change a value someone else typed; the only way to correct it is to
     * strike it and add the correct one.
     */
    public function addIncomeItem(Request $request, RentalApplication $rentalApplication, RentalApplicationAuditService $audit)
    {
        return $this->addAssessmentItem($request, $rentalApplication, $audit, RentalApplicationIncomeItem::class, 'income');
    }

    public function addExpenseItem(Request $request, RentalApplication $rentalApplication, RentalApplicationAuditService $audit)
    {
        return $this->addAssessmentItem($request, $rentalApplication, $audit, RentalApplicationExpenseItem::class, 'expense');
    }

    private function addAssessmentItem(Request $request, RentalApplication $rentalApplication, RentalApplicationAuditService $audit, string $modelClass, string $kind)
    {
        $this->guardCanView($rentalApplication);

        $validated = $request->validate([
            'description' => ['nullable', 'string', 'max:255'],
            'amount' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            // "Dates on entries" (Johan, 2026-09-10) — same column, same
            // rule as the agent's own inline capture (see
            // RentalApplicationReviewController::saveAssessment()): an
            // authoriser-added or replacement line is the identical kind of
            // row on the identical table, so it gets the identical date
            // field rather than shipping half the column.
            'entry_date' => ['nullable', 'date', 'before_or_equal:today'],
            'replaces_item_id' => ['nullable', 'integer'],
        ]);

        $assessment = RentalApplicationAssessment::firstOrCreate(
            ['rental_application_id' => $rentalApplication->id],
            ['agency_id' => $rentalApplication->agency_id],
        );

        $replacesId = null;
        if (!empty($validated['replaces_item_id'])) {
            // Must genuinely be a struck row on THIS assessment — never a
            // free-floating id a crafted request could point anywhere.
            $struckRow = $modelClass::where('rental_application_assessment_id', $assessment->id)
                ->where('id', $validated['replaces_item_id'])
                ->whereNotNull('struck_out_at')
                ->first();
            $replacesId = $struckRow?->id;
        }

        $maxSort = $modelClass::where('rental_application_assessment_id', $assessment->id)->max('sort_order');

        $item = $modelClass::create([
            'agency_id' => $rentalApplication->agency_id,
            'rental_application_assessment_id' => $assessment->id,
            'description' => $validated['description'] ?? null,
            'amount' => $validated['amount'],
            'entry_date' => $validated['entry_date'] ?? null,
            'sort_order' => ($maxSort ?? -1) + 1,
            'added_by_user_id' => $request->user()->id,
            'replaces_item_id' => $replacesId,
        ]);

        $audit->log(
            $rentalApplication,
            eventCategory: 'authorisation',
            eventType: 'assessment_item_added',
            user: $request->user(),
            newValues: ['kind' => $kind, 'description' => $item->description, 'amount' => (string) $item->amount, 'replaces_item_id' => $replacesId],
            humanSummary: ($replacesId
                ? "Replaced a struck-out {$kind} line with: "
                : "Added a {$kind} line: ") . ($item->description ?: '(no description)') . ' — R' . number_format((float) $item->amount, 2),
        );

        return response()->json(['ok' => true, 'item' => $this->serializeItem($item, $request->user())]);
    }

    public function toggleStrikeIncomeItem(Request $request, RentalApplication $rentalApplication, RentalApplicationIncomeItem $item, RentalApplicationAuditService $audit)
    {
        return $this->toggleStrikeAssessmentItem($request, $rentalApplication, $item, $audit, 'income');
    }

    public function toggleStrikeExpenseItem(Request $request, RentalApplication $rentalApplication, RentalApplicationExpenseItem $item, RentalApplicationAuditService $audit)
    {
        return $this->toggleStrikeAssessmentItem($request, $rentalApplication, $item, $audit, 'expense');
    }

    /**
     * "Remove" — Johan, verbatim: "remove im thinking is just a strike out
     * tick - which leaves the amount there but removes it from the calcs...
     * it shows the authoriser disagreed with a specific line rather than
     * the figure quietly vanishing. It is an audit trail, not a display
     * choice." Never a delete, never SoftDeletes — struck_out_at/by stay on
     * the row, RentalApplicationAssessment::qualifyingResult() excludes a
     * struck line from the total while every view still renders it.
     * Toggle, not one-way — a reviewer can un-strike a line they struck in
     * error. Deliberately NO ownership guard here (see this method's own
     * class docblock above) — anyone with view access may strike ANY row.
     */
    private function toggleStrikeAssessmentItem(Request $request, RentalApplication $rentalApplication, $item, RentalApplicationAuditService $audit, string $kind)
    {
        $this->guardCanView($rentalApplication);
        $this->guardItemBelongsToApplication($rentalApplication, $item);

        $nowStriking = $item->struck_out_at === null;
        $item->struck_out_at = $nowStriking ? now() : null;
        $item->struck_out_by_user_id = $nowStriking ? $request->user()->id : null;
        $item->save();

        $audit->log(
            $rentalApplication,
            eventCategory: 'authorisation',
            eventType: $nowStriking ? 'assessment_item_struck' : 'assessment_item_unstruck',
            user: $request->user(),
            newValues: ['kind' => $kind, 'description' => $item->description, 'amount' => (string) $item->amount],
            humanSummary: ($nowStriking ? 'Struck out a ' : 'Restored a ') . "{$kind} line: " . ($item->description ?: '(no description)') . ' — R' . number_format((float) $item->amount, 2),
        );

        return response()->json(['ok' => true, 'item' => $this->serializeItem($item, $request->user())]);
    }

    private function guardItemBelongsToApplication(RentalApplication $rentalApplication, $item): void
    {
        abort_unless(
            (int) $item->assessment->rental_application_id === (int) $rentalApplication->id,
            404
        );
    }

    private function serializeItem($item, User $viewer): array
    {
        $replacedBy = $item->replacedBy;

        return [
            'id' => $item->id,
            'description' => $item->description,
            'amount' => (float) $item->amount,
            'entry_date' => $item->entry_date?->format('Y-m-d'),
            'struck_out' => $item->struck_out_at !== null,
            // "by this person, at this time" — Johan's own phrasing for
            // what the record must read as.
            'struck_out_by' => $item->struckOutBy?->name,
            'struck_out_at' => $item->struck_out_at?->format('d M Y H:i'),
            'added_by_authoriser' => $item->added_by_user_id !== null,
            'added_by' => $item->addedBy?->name,
            'added_at' => $item->created_at?->format('d M Y H:i'),
            'replaces_item_id' => $item->replaces_item_id,
            // The struck row's own view of "what replaced me" — enough to
            // render "→ replaced by R{amount}, by {who}, at {when}" right
            // under the struck line without a second request.
            'replaced_by_item_id' => $replacedBy?->id,
            'replaced_by_amount' => $replacedBy !== null ? (float) $replacedBy->amount : null,
            'replaced_by_description' => $replacedBy?->description,
            'replaced_by_user' => $replacedBy?->addedBy?->name,
            'replaced_by_at' => $replacedBy?->created_at?->format('d M Y H:i'),
        ];
    }

    /**
     * The authoriser's own document view — deliberately NOT
     * RentalApplicationReviewController::viewDocumentInline(), which is
     * gated by the AGENT's own/branch/agency guard
     * (AuthorizesRentalApplicationAccess). An authoriser's access model is
     * different — RO/CO tier membership for the agency, not owner/branch of
     * this specific record.
     */
    public function viewDocumentInline(RentalApplication $rentalApplication, Document $document)
    {
        $this->guardCanView($rentalApplication);
        $this->guardDocumentBelongsToApplication($rentalApplication, $document);

        if (! $this->isInlineViewable($document->mime_type)) {
            abort(404);
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

    /** Same substitution RentalApplicationReviewController::highlightedFile() does — the marked-up copy if one exists. */
    public function highlightedFile(RentalApplication $rentalApplication, Document $document)
    {
        $this->guardCanView($rentalApplication);
        $this->guardDocumentBelongsToApplication($rentalApplication, $document);

        $highlight = RentalApplicationDocumentHighlight::where('document_id', $document->id)->first();
        abort_if(! $highlight || ! $highlight->highlighted_file_path, 404);
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

    /** AT-392 "pull from contact" — see the identical guard in RentalApplicationReviewController for the full rationale. */
    private function guardDocumentBelongsToApplication(RentalApplication $rentalApplication, Document $document): void
    {
        $owned = $document->source_type === 'rental_application' && (int) $document->source_id === $rentalApplication->id;
        abort_unless($owned || $rentalApplication->referencedDocuments()->where('documents.id', $document->id)->exists(), 404);
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
