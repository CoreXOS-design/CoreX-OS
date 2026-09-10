<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesRentalApplicationAccess;
use App\Http\Controllers\Concerns\HandlesRentalApplicationDocumentMarks;
use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactMatch;
use App\Models\Document;
use App\Models\RentalApplication;
use App\Models\RentalApplicationAssessment;
use App\Models\RentalApplicationDocumentHighlight;
use App\Models\RentalApplicationDocumentValidityWindow;
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
     *
     * Lock, added 2026-09-10 (Johan, QA1 item 2 follow-up) — reproduced on
     * a real, fully-approved application: this endpoint would silently
     * accept a property swap after the outcome email had already gone out
     * naming a different one, and — more dangerously — while an authoriser
     * was actively deciding against the property's rent (status
     * under_assessment). Server-side gate, not a hidden button: a request
     * straight at this route on a locked application gets a 403 same as a
     * button click would, per RentalApplicationQualifyingSetting's agency-
     * configurable default (locked from submission onward; an agency can
     * turn it off and get the old, unrestricted behaviour back).
     */
    /**
     * AT-392 cross-tenant fix, 2026-09-10 (cc1's live find) — `property_id`
     * used to be validated with `exists:properties,id`, a RAW query against
     * the table that never goes through Property's AgencyScope. Existence
     * and permission are different questions; this endpoint was only ever
     * asking the first. An ordinary agent POSTed a real property_id
     * belonging to a different agency straight at this route (bypassing the
     * search picker entirely — the picker being scoped was never the
     * protection) and it saved with a 302, no error. Fixed by resolving the
     * id through Property::findLinkableForRentalApplication() — the model,
     * with its scopes (agency AND whatever branch/own the agency has
     * configured for `properties` in Role Manager) — so an id that exists
     * but isn't this user's to link resolves to null exactly like an id
     * that doesn't exist at all, and never reaches save().
     */
    public function linkProperty(Request $request, RentalApplication $rentalApplication, \App\Services\RentalApplications\RentalApplicationAuditService $audit)
    {
        $this->guardRentalApplication($rentalApplication);

        $request->validate([
            'property_id' => ['nullable', 'integer'],
        ]);

        $oldPropertyId = $rentalApplication->property_id;
        $requestedPropertyId = $request->filled('property_id') ? (int) $request->input('property_id') : null;

        $newProperty = \App\Models\Property::findLinkableForRentalApplication($requestedPropertyId, $request->user());

        if ($requestedPropertyId !== null && $newProperty === null) {
            $audit->log(
                $rentalApplication,
                eventCategory: 'property_link',
                eventType: 'link_refused',
                user: $request->user(),
                newValues: ['requested_property_id' => $requestedPropertyId],
                humanSummary: "Refused: property #{$requestedPropertyId} isn't visible to this user (wrong agency, branch, or book).",
            );

            abort(403, "You don't have access to that property, so it can't be linked to this application. Search for it above rather than entering an id directly — if it should be visible and isn't, ask an admin to check Role Manager's Rental History / Properties access for your role.");
        }

        $newPropertyId = $newProperty?->id;

        if ($oldPropertyId === $newPropertyId) {
            return back();
        }

        abort_if(
            RentalApplicationQualifyingSetting::isPropertyLinkLockedFor($rentalApplication),
            403,
            "The linked property can't be changed once the application has been submitted for authorisation — this keeps the record consistent with any decision or outcome already based on it. Ask an admin to review this in Rental Application Settings if this needs to change.",
        );

        $oldProperty = $oldPropertyId ? \App\Models\Property::find($oldPropertyId) : null;

        \Illuminate\Support\Facades\DB::transaction(function () use ($rentalApplication, $newPropertyId, $oldPropertyId, $oldProperty, $newProperty, $audit, $request) {
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
        });

        return back()->with('success', $newProperty ? 'Property linked.' : 'Property link cleared.');
    }

    public function show(Request $request, RentalApplication $rentalApplication): View
    {
        $this->guardRentalApplication($rentalApplication);
        $rentalApplication->load(['contact', 'property', 'signatures', 'documents.documentType', 'referencedDocuments.documentType', 'generations']);

        $assessment = RentalApplicationAssessment::firstOrNew(
            ['rental_application_id' => $rentalApplication->id],
            ['agency_id' => $rentalApplication->agency_id],
        );
        $assessment->setRelation('incomeItems', $assessment->exists ? $assessment->incomeItems : collect());
        $assessment->setRelation('expenseItems', $assessment->exists ? $assessment->expenseItems : collect());

        $maxRentPercent = RentalApplicationQualifyingSetting::maxRentPercentFor((int) $rentalApplication->agency_id);
        $result = $assessment->exists ? $assessment->qualifyingResult($maxRentPercent) : null;

        $allDocIds = $rentalApplication->documents->pluck('id')->merge($rentalApplication->referencedDocuments->pluck('id'));
        $highlightedByDocId = RentalApplicationDocumentHighlight::whereIn('document_id', $allDocIds)
            ->whereNotNull('highlighted_file_path')
            ->pluck('id', 'document_id');

        // AT-392 — Johan: "a stale document warns naming the purpose it
        // fails and by how long, in plain language." This screen's purpose
        // is always 'rental_application', regardless of where a referenced
        // document was originally filed — that's what it's being used FOR
        // here, not where it came from.
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
            // AT-392 "pull from contact" — filed elsewhere (source_type/
            // source_id untouched), only REFERENCED here. Never eligible for
            // Split (that would archive a document another context owns)
            // and never counted toward THIS application's unsplit-completeness
            // gate — its typing/splitting was already this application's
            // business at its original home, not here.
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
        $propertyLinkLocked = RentalApplicationQualifyingSetting::isPropertyLinkLockedFor($rentalApplication);
        $auditLogTotal = $rentalApplication->auditLog()->count();
        $auditLog = $rentalApplication->auditLog()->with('user')->latest('created_at')->limit(200)->get();

        // AT-392 — the agent's wishlist step (approved, ready-to-send state
        // only, but harmless/unused otherwise). Same option data
        // ContactMatchController::edit() supplies so _match-form.blade.php
        // renders identically wherever it's reused.
        $existingWishlist = $rentalApplication->contact
            ? ContactMatch::withoutGlobalScopes()
                ->where('contact_id', $rentalApplication->contact_id)
                ->where('listing_type', 'rental')
                ->where('status', ContactMatch::STATUS_ACTIVE)
                ->whereNull('deleted_at')
                ->orderByDesc('is_primary')
                ->orderByDesc('updated_at')
                ->first()
            : null;
        $matchCategories = \App\Models\PropertySettingItem::group('category')->get();
        $matchTypes = \App\Models\PropertySettingItem::group('property_type')->where('active', true)->get();
        $featureOptions = array_merge(\App\Http\Controllers\CoreX\ContactMatchController::FEATURE_OPTIONS, \App\Http\Controllers\CoreX\ContactMatchController::POOL_TYPE_OPTIONS);

        // AT-392 — real rental stock only, not the full sale+rental type
        // list. Johan: "unless the agency actually lets commercial,
        // investigate before deciding" — investigation found real
        // commercial/vacant-land rental stock on file, so this filters to
        // whatever types this agency genuinely has under listing_type
        // rental TODAY, never a hardcoded residential-only guess.
        $rentalPropertyTypeNames = \App\Models\Property::query()
            ->where('agency_id', $rentalApplication->agency_id)
            ->where('listing_type', 'rental')
            ->whereNotNull('property_type')
            ->distinct()
            ->pluck('property_type')
            ->all();

        // Prefill for a FIRST-time wishlist only — an existing one shows
        // what the agent already set, never silently overwritten with the
        // application's figures on every open.
        $wishlistPrefill = $existingWishlist ? [] : [
            'price_max' => $rentalApplication->approved_rental_amount !== null
                ? (int) $rentalApplication->approved_rental_amount
                : null,
            'move_in_date' => $rentalApplication->occupation_date?->format('Y-m-d'),
            'rental_term_months' => $rentalApplication->rental_term_months,
        ];

        // AT-392 "pull from contact" — Johan: "the agent can attach
        // documents ALREADY ON FILE against the contact to a new
        // application, without the applicant re-sending them." Anything
        // already owned or referenced here is excluded from the picker —
        // no point offering what's already on the application.
        $attachedDocIds = $allDocIds->all();
        $pickableContactDocuments = $rentalApplication->contact
            ? $rentalApplication->contact->documents()
                ->whereNotIn('documents.id', $attachedDocIds)
                ->with('documentType')
                ->latest('documents.created_at')
                ->get()
            : collect();
        // "Document age shows wherever an agent picks OR reviews a
        // document" — the picker is exactly a "picks" moment, so it gets
        // the same staleness check as the main list, keyed by id rather
        // than folded into the row shape (the Blade iterates plain Document
        // objects here, unlike $documents above).
        $pickableStaleness = $pickableContactDocuments->mapWithKeys(fn (Document $d) => [
            $d->id => RentalApplicationDocumentValidityWindow::stalenessWarning($d->created_at, $agencyId, 'rental_application', $d->document_type_id),
        ]);

        return view('corex.rental-applications.review', compact(
            'rentalApplication', 'assessment', 'maxRentPercent', 'result', 'documents', 'moreInfoRequestedNote', 'declineInfo', 'highlighters',
            'viewerRole', 'propertyLinkLocked', 'auditLog', 'auditLogTotal', 'existingWishlist', 'matchCategories', 'matchTypes', 'featureOptions',
            'rentalPropertyTypeNames', 'wishlistPrefill', 'pickableContactDocuments', 'pickableStaleness'
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

    /**
     * AT-392 — the agent's own send action. Johan: "when the agent is
     * happy, THEY send. One email, containing the approval, the amount,
     * and the matched properties." Idempotent guard (applicant_notified_at
     * already set → 422, not a silent second send) — this is a one-shot
     * action, not a resend button.
     */
    public function send(Request $request, RentalApplication $rentalApplication, RentalApplicationMailer $mailer, RentalApplicationAuditService $audit, \App\Services\RentalApplications\RentalApplicationPropertyMatcher $matcher, \App\Services\RentalApplications\RentalApplicationPdfService $pdfService)
    {
        $this->guardRentalApplication($rentalApplication);

        if ($rentalApplication->status !== 'approved') {
            return redirect()->route('corex.rental-applications.review', $rentalApplication)
                ->with('error', 'This application is not approved yet.');
        }
        if ($rentalApplication->applicant_notified_at) {
            return redirect()->route('corex.rental-applications.review', $rentalApplication)
                ->with('error', 'The applicant has already been sent this approval.');
        }

        $properties = $matcher->forApproval($rentalApplication);

        $sent = $mailer->sendApproved($rentalApplication, $properties);

        $rentalApplication->applicant_notified_at = now();
        $rentalApplication->save();

        // AT-392 — Johan: "the documents / application / approval gets
        // filed on the contact... available at any point if anyone needs
        // to look at it." Best-effort (fileAsDocument() catches its own
        // failures) — a filing failure must never undo an already-sent
        // approval.
        $pdfService->fileAsDocument($rentalApplication, 'Approved Rental Application');

        $audit->log(
            $rentalApplication,
            eventCategory: 'agent',
            eventType: 'approval_sent',
            user: $request->user(),
            newValues: ['applicant_notified_at' => $rentalApplication->applicant_notified_at->toIso8601String()],
            metadata: ['matched_property_count' => $properties->count(), 'mail_sent' => $sent],
            humanSummary: 'Sent the approval to the applicant, with ' . $properties->count() . ' matched propert' . ($properties->count() === 1 ? 'y' : 'ies') . '.',
        );

        return redirect()->route('corex.rental-applications.review', $rentalApplication)
            ->with('success', 'Approval sent to the applicant.');
    }
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

        // AT-392 — keeps Contact::rental_application_status in sync
        // (App\Listeners\Contact\RecomputeRentalApplicationStatus).
        event(new \App\Events\RentalApplication\RentalApplicationReopened($rentalApplication, $isOverrideReopen, $request->user()->id));

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

        // AT-392 — Johan: "the gate is on SUBMIT FOR AUTHORISATION, not on
        // attaching... cannot hand the authoriser an unsorted blob." An
        // agent can attach freely and start working immediately; this is
        // the one point that refuses to hand off an untyped PDF. Same test
        // as the review screen's "Split & File" trigger visibility.
        $unsplitCount = $rentalApplication->documents()
            ->whereNull('document_type_id')
            ->where('mime_type', 'application/pdf')
            ->count();
        if ($unsplitCount > 0) {
            return response()->json([
                'error' => $unsplitCount === 1
                    ? 'One supporting document hasn\'t been sorted into document types yet — split it before submitting for authorisation.'
                    : "{$unsplitCount} supporting documents haven't been sorted into document types yet — split them before submitting for authorisation.",
                'reason' => 'unsplit_documents',
                'unsplit_count' => $unsplitCount,
            ], 422);
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
     * AT-392 "pull from contact" — download for a REFERENCED document only.
     * Deliberately a separate route/method from
     * RentalApplicationController::downloadDocument() rather than editing
     * that file — it's owned by another lane and actively being edited
     * concurrently (see this file's own class docblock). An owned document
     * keeps using the original route untouched; only a pulled-from-contact
     * document needs this one, since the original route's guard checks
     * source_type/source_id ownership only.
     */
    public function downloadReferencedDocument(RentalApplication $rentalApplication, Document $document)
    {
        $this->guardRentalApplication($rentalApplication);
        $this->guardDocumentBelongsToApplication($rentalApplication, $document);

        return $document->downloadResponse();
    }

    /**
     * AT-392 "pull from contact" — Johan: "the agent can attach documents
     * ALREADY ON FILE against the contact to a new application, without the
     * applicant re-sending them." Attaches via the rental_application_document
     * pivot only — the document's own source_type/source_id (its filing
     * home) is never touched, so it stays correctly filed wherever it
     * originally landed while also becoming visible/usable here.
     */
    public function attachExistingDocument(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $validated = $request->validate([
            'document_id' => ['required', 'integer'],
        ]);

        $document = Document::findOrFail($validated['document_id']);

        // Must genuinely belong to this application's own contact — this is
        // an attach action, not a way to pull an arbitrary document by ID.
        abort_unless(
            $rentalApplication->contact_id && $document->contacts()->where('contact_id', $rentalApplication->contact_id)->exists(),
            403,
            'That document is not on file for this application\'s contact.'
        );

        // Already owned outright — nothing to do, and re-referencing your
        // own owned document would be a confusing no-op state.
        if ($document->source_type === 'rental_application' && (int) $document->source_id === $rentalApplication->id) {
            return response()->json(['error' => 'That document is already on this application.'], 422);
        }

        $rentalApplication->referencedDocuments()->syncWithoutDetaching([
            $document->id => ['attached_by' => $request->user()->id],
        ]);

        return response()->json([
            'ok' => true,
            'document' => [
                'id' => $document->id,
                'original_name' => $document->original_name,
                'document_type' => $document->documentType?->label,
            ],
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
            // "Dates on entries" (Johan, 2026-09-10) — the date a captured
            // deposit/debit actually happened. Can't be in the future (it's
            // a line off an already-issued bank statement); no lower bound
            // — old statements are a normal, legitimate capture.
            'income_items.*.entry_date' => ['nullable', 'date', 'before_or_equal:today'],
            'expense_items' => ['nullable', 'array'],
            'expense_items.*.id' => ['nullable', 'integer'],
            'expense_items.*.description' => ['nullable', 'string', 'max:255'],
            'expense_items.*.amount' => ['nullable', 'numeric', 'min:0', 'max:99999999.99'],
            'expense_items.*.entry_date' => ['nullable', 'date', 'before_or_equal:today'],
            'notes' => ['nullable', 'string', 'max:5000'],
            // Round 11 — Johan: "we have to ask the nr of months the bank
            // statement is for." A bank statement's captured lines are a
            // lump sum over this many months, not a monthly figure.
            //
            // "Dates on entries" (2026-09-10) — Johan: "months covered
            // becomes a date range... the month count is calculated from
            // them, not typed." statement_months is no longer accepted from
            // the client at all — the form doesn't send it, and if it did
            // it would be ignored; the derived value's own ceiling (36
            // months, same as the old field's max) is enforced further
            // below, against the actual dates, not a client-supplied number.
            // Required-together: a lone from/to date means the agent picked
            // one side and hasn't finished — never a valid state to save
            // silently as a partial range (BUILD_STANDARD §2, "wrong
            // order"/asymmetric input must be rejected clearly, not guessed).
            'statement_period_from' => ['nullable', 'date', 'required_with:statement_period_to'],
            'statement_period_to' => ['nullable', 'date', 'required_with:statement_period_from', 'after_or_equal:statement_period_from'],
            // Round 16 — Johan: "unpaid transactions on bank statement...
            // this is a dangerous app." A single flag, not a list of
            // amounts — individual declined lines are marked on the
            // document itself via the highlighter.
            'has_unpaid_transactions' => ['nullable', 'boolean'],
            'expected_generation' => ['nullable', 'integer', 'min:1'],
        ], [
            'statement_period_from.required_with' => 'Enter both a from and to date for the statement period, or leave both blank.',
            'statement_period_to.required_with' => 'Enter both a from and to date for the statement period, or leave both blank.',
            'statement_period_to.after_or_equal' => 'The statement period\'s "to" date must be on or after its "from" date.',
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

        // "Dates on entries" (Johan, 2026-09-10) — statement_months is now
        // DERIVED from the date range, never submitted directly by the
        // form. Calculated here, not trusted from the request, so a
        // crafted/stale statement_months value in the payload can never
        // disagree with the dates actually being saved alongside it.
        $statementFrom = ($validated['statement_period_from'] ?? '') === '' ? null : $validated['statement_period_from'];
        $statementTo = ($validated['statement_period_to'] ?? '') === '' ? null : $validated['statement_period_to'];
        $derivedStatementMonths = RentalApplicationAssessment::calculateStatementMonths($statementFrom, $statementTo);

        // Same ceiling the old typed-number field enforced (min:1/max:36) —
        // a date range that works out to more than that is almost certainly
        // the wrong dates, not a real 3+ year bank statement, and must be
        // rejected clearly rather than silently accepted or truncated.
        if ($derivedStatementMonths !== null && $derivedStatementMonths > 36) {
            return response()->json([
                'error' => 'That date range covers ' . $derivedStatementMonths . ' months — check the dates. A statement period is expected to be 36 months or fewer.',
            ], 422);
        }

        $assessmentAttributes = [
            'agency_id' => $rentalApplication->agency_id,
            'notes' => ($validated['notes'] ?? '') === '' ? null : ($validated['notes'] ?? null),
            'statement_period_from' => $statementFrom,
            'statement_period_to' => $statementTo,
            'has_unpaid_transactions' => $request->boolean('has_unpaid_transactions'),
            'updated_by_user_id' => $request->user()->id,
        ];

        // Only overwrite statement_months when a full date range was
        // actually submitted on THIS save. Johan: "existing records keep
        // whatever month count they hold — nothing recalculates
        // retrospectively without a date range to derive it from." A save
        // with no dates yet (a fresh assessment, or an existing one nobody
        // has re-opened to pick dates on) must leave whatever figure is
        // already stored untouched, not silently zero it.
        if ($derivedStatementMonths !== null) {
            $assessmentAttributes['statement_months'] = $derivedStatementMonths;
        }

        $assessment = RentalApplicationAssessment::updateOrCreate(
            ['rental_application_id' => $rentalApplication->id],
            $assessmentAttributes,
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
            'income_items' => $assessment->incomeItems->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'amount' => $i->amount, 'entry_date' => $i->entry_date?->format('Y-m-d')])->values(),
            'expense_items' => $assessment->expenseItems->map(fn ($i) => ['id' => $i->id, 'description' => $i->description, 'amount' => $i->amount, 'entry_date' => $i->entry_date?->format('Y-m-d')])->values(),
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
                'entry_date' => ($item['entry_date'] ?? '') === '' ? null : $item['entry_date'],
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

    /**
     * AT-392 "pull from contact" — a document can belong here two ways: it
     * OWNS this application (source_type/source_id), or it's REFERENCED via
     * the rental_application_document pivot (attached from the contact's
     * file history, filed elsewhere). Either way it's legitimately viewable
     * here; only Split/archive actions care about the distinction.
     */
    private function guardDocumentBelongsToApplication(RentalApplication $rentalApplication, Document $document): void
    {
        $owned = $document->source_type === 'rental_application' && (int) $document->source_id === $rentalApplication->id;
        abort_unless($owned || $rentalApplication->referencedDocuments()->where('documents.id', $document->id)->exists(), 404);
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

    /**
     * AT-392 — the agent's wishlist step, reusing the SAME Core Matches
     * form and drawer pattern as the Buyer Pipeline detail page
     * (command-center/buyers/detail.blade.php:580-615), not a second
     * editor. Johan: "modal showing same as core matches on contact - we
     * have this already."
     *
     * Deliberately its own validation here rather than reusing
     * BuyerDetailController's private validateWishlistPayload() —
     * duplicated, not shared, because that method is private and this
     * redirects somewhere different (back to the review screen, not the
     * buyer-detail page). Flagged rather than silently left drifting: if
     * the Core Matches field set changes, both copies need updating.
     */
    public function addWishlist(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $contact = $rentalApplication->contact;
        abort_unless($contact, 422, 'This application has no linked contact.');

        $validated = $this->validateWishlistPayload($request, $rentalApplication);
        $fields = $this->extractWishlistMatchFields($validated);
        // This drawer creates a RENTAL wishlist only, regardless of what
        // the shared form's hidden listing_type field submits (its own
        // default is 'sale', built for the Buyer Pipeline) — forced last,
        // after the merge, so nothing upstream can override it.
        $fields['listing_type'] = 'rental';

        $contact->matches()->create(array_merge([
            'agency_id'          => $contact->agency_id,
            'created_by_user_id' => $request->user()->id,
            'status'             => ContactMatch::STATUS_ACTIVE,
        ], $fields));

        return redirect()
            ->route('corex.rental-applications.review', $rentalApplication)
            ->with('success', 'Wishlist added.');
    }

    public function updateWishlist(Request $request, RentalApplication $rentalApplication, ContactMatch $match)
    {
        $this->guardRentalApplication($rentalApplication);
        abort_if($match->contact_id !== $rentalApplication->contact_id, 403);

        $validated = $this->validateWishlistPayload($request, $rentalApplication);
        $fields = $this->extractWishlistMatchFields($validated);
        $fields['listing_type'] = 'rental';
        $match->update($fields);

        return redirect()
            ->route('corex.rental-applications.review', $rentalApplication)
            ->with('success', 'Wishlist updated.');
    }

    /**
     * Same field set/rules as _match-form.blade.php's other caller
     * (BuyerDetailController), plus two rental-only additions
     * (move_in_date, rental_term_months) and the hard approved-amount cap
     * — this controller's own copy, since the shared form is otherwise
     * agnostic to any linked application.
     */
    private function validateWishlistPayload(Request $request, RentalApplication $rentalApplication): array
    {
        $validator = \Illuminate\Support\Facades\Validator::make($request->all(), [
            'listing_type'              => 'nullable|in:sale,rental',
            'category'                  => 'nullable|string|max:100',
            'property_types'            => 'nullable|array',
            'property_types.*'          => 'string|max:100',
            'p24_suburb_ids'            => 'nullable|array',
            'p24_suburb_ids.*'          => 'integer|exists:p24_suburbs,id',
            'price_min'                 => 'nullable|integer|min:0',
            'price_max'                 => 'nullable|integer|min:0',
            'move_in_date'              => 'nullable|date',
            'rental_term_months'        => 'nullable|integer|min:1|max:60',
            'beds_min'                  => 'nullable|integer|min:0|max:20',
            'bedrooms_max'              => 'nullable|integer|min:0|max:20',
            'baths_min'                 => 'nullable|integer|min:0|max:20',
            'garages_min'               => 'nullable|integer|min:0|max:20',
            'parking_min'               => 'nullable|integer|min:0|max:20',
            'floor_size_min'            => 'nullable|integer|min:0',
            'floor_size_max'            => 'nullable|integer|min:0',
            'erf_size_min'              => 'nullable|integer|min:0',
            'erf_size_max'              => 'nullable|integer|min:0',
            'must_have_features'        => 'nullable|array',
            'must_have_features.*'      => 'string|max:60',
            'nice_to_have_features'     => 'nullable|array',
            'nice_to_have_features.*'   => 'string|max:60',
            'deal_breakers'             => 'nullable|array',
            'deal_breakers.*'           => 'string|max:60',
            'notes'                     => 'nullable|string|max:500',
            'is_primary'                => 'nullable|boolean',
            'name'                      => 'nullable|string|max:120',
            'criteria_groups_present'   => 'sometimes',
        ]);

        $validator->after(function ($v) use ($rentalApplication) {
            $bedsMin = $v->getData()['beds_min'] ?? null;
            $bedsMax = $v->getData()['bedrooms_max'] ?? null;
            if ($bedsMin !== null && $bedsMax !== null && (int) $bedsMax < (int) $bedsMin) {
                $v->errors()->add('bedrooms_max', 'Maximum bedrooms cannot be less than minimum bedrooms.');
            }

            // AT-392, Johan verbatim: "if a tenant is approved for 10k we do
            // not show them anything higher than 10k. done." Rejected here,
            // named, in plain language, BEFORE the record is touched — the
            // model-level clamp (ContactMatch::enforceRentalApprovedAmountCap())
            // is the universal backstop for every OTHER entry point; this is
            // the agent's own clear feedback on the intended screen.
            $priceMax = $v->getData()['price_max'] ?? null;
            $approvedAmount = $rentalApplication->approved_rental_amount;
            if ($priceMax !== null && $priceMax !== '' && $approvedAmount !== null && (float) $priceMax > (float) $approvedAmount) {
                $v->errors()->add('price_max', 'The maximum rent cannot be set above the R' . number_format((float) $approvedAmount, 2)
                    . ' this tenant is approved for — showing them anything higher risks telling them they qualify for a property they do not. '
                    . 'If you believe the approved amount is wrong, that needs to change through the authorisation decision itself, not here.');
            }

            $conflicts = ContactMatch::conflictingFeatureTokens(
                $v->getData()['must_have_features'] ?? [],
                $v->getData()['nice_to_have_features'] ?? [],
                $v->getData()['deal_breakers'] ?? [],
            );
            if ($conflicts) {
                $v->errors()->add('must_have_features', 'Each feature can be in only one category (Must-have, Nice, or Deal-breaker). In two: ' . implode(', ', $conflicts) . '.');
            }
        });

        $data = $validator->validate();

        if ($request->has('criteria_groups_present')) {
            foreach (['property_types', 'p24_suburb_ids', 'must_have_features', 'nice_to_have_features', 'deal_breakers'] as $group) {
                if (!isset($data[$group])) {
                    $data[$group] = [];
                }
            }
        }

        return $data;
    }

    private function extractWishlistMatchFields(array $validated): array
    {
        if (isset($validated['property_types']) && !empty($validated['property_types'])) {
            $validated['property_type'] = $validated['property_types'][0] ?? null;
        }

        return $validated;
    }
}
