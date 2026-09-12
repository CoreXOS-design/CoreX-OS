<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\Document;
use App\Models\Property;
use App\Models\RentalApplicationQualifyingSetting;
use App\Models\RentalApplication;
use App\Models\RentalApplicationStatusHistory;
use App\Services\RentalApplications\RentalApplicationMailer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * AT-392 — Rental Applications, Phase 1. Spec: .ai/specs/rental-applications.md
 *
 * Dedicated page, deliberately NOT the e-sign wizard: the agent never signs
 * this document, and every field is optional (contact is the only required
 * link). See the spec's "Why" section for the full reasoning.
 */
class RentalApplicationController extends Controller
{
    use \App\Http\Controllers\Concerns\AuthorizesRentalApplicationAccess;
    use \App\Http\Controllers\Concerns\FiltersRentalApplicationList;

    /**
     * AT-402 — Rental Application Control Centre. Johan, verbatim: "the way
     * fica works is a lot better than having 3 menus here... fica carries
     * all the work and you can click the tiles to select which you want to
     * work with... so it becomes more of a rental application control
     * centre than having 3 menus and you have to sit and click through it
     * to find where your application is at." Replaces the old three-screen
     * split (this index, returned(), and the separate Authorisation list)
     * with ONE tile-filtered list, copying FICA's proven pattern
     * (Compliance\FicaController::index()) rather than e-sign's — e-sign's
     * "tiles" are same-page scroll anchors with no real filter, no
     * pagination, no own/branch/agency tiers; FICA's are real ?tab=-style
     * links whose counts and list share one scoped base query.
     *
     * TILE COUNTS ARE A SCOPING SURFACE (Johan, explicit ruling): every
     * count below is computed from `clone $countBase`, which is itself
     * `clone $baseQuery` — the SAME scoped query the filtered list uses,
     * before either branches. A tile can never report a count the viewer
     * couldn't open, because count and list share one scoped ancestor.
     *
     * The scope TOGGLE (as opposed to the ceiling scopeVisibleTo() already
     * enforced) still defaults to the NARROWEST level ('own'), never the
     * user's ceiling — unchanged from the pre-402 behaviour. Clamped
     * server-side in RentalApplication::clampScope().
     */
    public function index(Request $request): View
    {
        $user = $request->user();
        $requestedScope = $request->get('scope', 'own');
        $maxScope = \App\Services\PermissionService::getDataScope($user, 'rental_applications');
        $canSeeBranch = in_array($maxScope, ['branch', 'all'], true);
        $canSeeAgency = $maxScope === 'all';

        $perPage = $this->resolvePerPage($request);

        // Legacy-link fallback: a bare `?status=` with no `?tile=` at all
        // (the exact shape of a bookmarked pre-402 index.blade.php filter
        // URL — that screen's URI never changes, so it never redirects)
        // resolves the ACTIVE tile from the same table returned()'s
        // redirect uses, so the highlighted tile matches the (already-
        // correctly-narrowed, via applySearchSortAndDateRange's own
        // `status` handling) list instead of falling back to a stale 'All'.
        if ($request->filled('tile')) {
            $tile = (string) $request->query('tile');
        } elseif ($request->filled('status') && array_key_exists($request->query('status'), self::TILE_FOR_LEGACY_STATUS)) {
            $tile = self::TILE_FOR_LEGACY_STATUS[$request->query('status')];
        } else {
            $tile = 'all';
        }
        if (!array_key_exists($tile, self::TILES)) {
            $tile = 'all';
        }

        // AT-402 — PERMISSION REGRESSION GUARD, not a UI nicety. Pre-402,
        // 'returned'/'under_assessment'/'approved'/'declined'/'reopened'
        // were reachable ONLY through the separate /returned route, gated
        // by rental_applications.view_returned via route middleware — a
        // role with plain rental_applications.view (this screen's own
        // gate) but WITHOUT view_returned could never see those statuses
        // at all. Merging the two screens must not silently widen that: a
        // user lacking view_returned gets those statuses excluded from the
        // BASE query itself (not just the tile buttons hidden), so 'All'
        // and search results can't leak them either, and requesting a
        // restricted tile by hand-edited URL returns an honestly-empty
        // list rather than a 403 — 'draft'/'sent'/'in_progress'/'withdrawn'
        // were always visible on the plain index() regardless of this
        // permission and stay visible here unchanged.
        $canViewReturned = $user->hasPermission('rental_applications.view_returned');
        if (!$canViewReturned && in_array($tile, self::VIEW_RETURNED_TILES, true)) {
            $tile = 'not_yet_submitted';
        }

        // Base query — own/branch/agency ceiling, identical mechanism FICA's
        // own $baseQuery uses. Every tile count AND the filtered list itself
        // both descend from this SAME scoped query, cloned before either
        // branches — see the class docblock above.
        $baseQuery = RentalApplication::visibleTo($user, $requestedScope);
        if (!$canViewReturned) {
            $baseQuery->whereNotIn('rental_applications.status', self::RETURNED_STATUSES);
        }

        $countBase = clone $baseQuery;
        $counts = [];
        foreach (self::TILES as $key => $def) {
            $tileQuery = clone $countBase;
            $this->applyTileFilter($tileQuery, $key);
            $counts[$key] = $tileQuery->count();
        }

        $query = (clone $baseQuery)->with(['contact', 'property', 'createdBy']);
        $this->applyTileFilter($query, $tile);
        $this->applySearchSortAndDateRange($query, $request, 'created_at', 'created_at');

        $applications = $query->paginate($perPage)->withQueryString();

        $archived = null;
        if ($request->boolean('archived')) {
            $archivedQuery = RentalApplication::visibleTo($user, $requestedScope)
                ->onlyTrashed()
                ->with(['contact', 'property', 'createdBy']);
            $this->applyTileFilter($archivedQuery, $tile);
            $archived = $archivedQuery
                ->orderByDesc('deleted_at')
                ->paginate($perPage, ['*'], 'archived_page')
                ->withQueryString();
        }

        // AT-402 — advertises the SEPARATE, structurally distinct
        // Authorisation decision queue (RentalApplicationAuthorisationController)
        // to RO/CO users only. This is NOT one of the shared tiles above:
        // that controller's own query uses the RO/CO's full granted ceiling
        // (not narrowed to $requestedScope — an authoriser's queue is
        // everything they're allowed to decide on, same as it was on the
        // dedicated screen), and its show/decide route carries the
        // self-approval block, the CO-override rule, and the status-history
        // audit trail — logic that stays in that one controller, not
        // reachable through this shared list. A plain agent gets no
        // equivalent count and this block never renders for them; their own
        // "sent for authorisation" applications remain visible read-only
        // through the ordinary 'sent_for_authorisation' TILE above, scoped
        // like every other tile on this screen.
        $isAuthoriser = $user->isRentalApplicationRO() || $user->isRentalApplicationCO();
        $authorisationQueueCount = 0;
        if ($isAuthoriser) {
            $authorisationQueueCount = RentalApplication::whereNotNull('submitted_for_approval_at')
                ->where('status', 'under_assessment')
                ->visibleTo($user)
                ->count();
        }

        return view('corex.rental-applications.index', compact(
            'applications', 'archived', 'canSeeBranch', 'canSeeAgency', 'perPage',
            'tile', 'counts', 'isAuthoriser', 'authorisationQueueCount', 'canViewReturned',
        ));
    }

    /**
     * AT-402 tile set. Derived from the real status enum (RentalApplication::
     * STATUSES) and the real live-data split Johan asked for, not the
     * shorthand he sketched it with:
     *   - draft/sent/in_progress grouped as "Not yet submitted" — nobody
     *     hunts these by state, they hunt by applicant name (Johan).
     *   - under_assessment is NOT one bucket. submitted_for_approval_at
     *     (set only by RentalApplicationReviewController when the agent
     *     submits to the authoriser) splits "with agent" from "with
     *     authoriser" — the exact distinction Johan called out as the
     *     precise question an agent wastes clicks on. Never collapse
     *     these back into one tile.
     *   - withdrawn/reopened kept as real, individually reachable tiles
     *     (Johan: "a real state is never unreachable... zero live rows
     *     today is not a reason to hide a state") but rendered with lower
     *     visual prominence in the view — see SECONDARY_TILES below.
     *   - 'all' always present so an agent who doesn't know the state can
     *     still search everything, exactly as FICA's own 'all' tab works.
     *
     * @var array<string,array{label:string,statuses:?array<int,string>,submitted_for_approval:?bool}>
     */
    public const TILES = [
        'all' => ['label' => 'All', 'statuses' => null, 'submitted_for_approval' => null],
        'not_yet_submitted' => ['label' => 'Not Yet Submitted', 'statuses' => ['draft', 'sent', 'in_progress'], 'submitted_for_approval' => null],
        'returned' => ['label' => 'Returned', 'statuses' => ['returned'], 'submitted_for_approval' => null],
        'under_assessment' => ['label' => 'Under Assessment', 'statuses' => ['under_assessment'], 'submitted_for_approval' => false],
        'sent_for_authorisation' => ['label' => 'Sent for Authorisation', 'statuses' => ['under_assessment'], 'submitted_for_approval' => true],
        'approved' => ['label' => 'Approved', 'statuses' => ['approved'], 'submitted_for_approval' => null],
        'declined' => ['label' => 'Declined', 'statuses' => ['declined'], 'submitted_for_approval' => null],
        'withdrawn' => ['label' => 'Withdrawn', 'statuses' => ['withdrawn'], 'submitted_for_approval' => null],
        'reopened' => ['label' => 'Reopened', 'statuses' => ['reopened'], 'submitted_for_approval' => null],
    ];

    /** Rendered as small, muted links below the main tile row — reachable, not prominent (Johan's ruling). */
    public const SECONDARY_TILES = ['withdrawn', 'reopened'];

    /**
     * The statuses formerly gated behind the dedicated /returned route's
     * rental_applications.view_returned permission. See the permission
     * regression guard in index() above — these must stay excluded from a
     * user lacking that permission, exactly as they were pre-402.
     */
    public const RETURNED_STATUSES = ['returned', 'reopened', 'under_assessment', 'approved', 'declined'];

    /** Tile keys that surface any of RETURNED_STATUSES — hidden/redirected away for a user lacking view_returned. */
    public const VIEW_RETURNED_TILES = ['returned', 'under_assessment', 'sent_for_authorisation', 'approved', 'declined', 'reopened'];

    /**
     * REGRESSION FIX (2026-09-11) — merging index()/returned() into one list
     * dropped the Review action entirely; every row got the old index()'s
     * single "Open" (read-only/edit-form) action, orphaning the review
     * screen (mark up documents, run the affordability assessment, submit
     * for authorisation) from the list for every application, including the
     * ones actively being worked. Johan, verbatim: "returned applications
     * has no review any more?"
     *
     * These are the statuses where an agent has real work to do on the
     * FILE — the old returned.blade.php showed Open AND Review together,
     * unconditionally, for exactly this status set (it also included
     * approved/declined/withdrawn, but those are terminal decisions with
     * nothing left to review — Johan's explicit instruction: "an approved
     * or declined application opening into a working review screen is its
     * own bug"). draft/sent never had a Review button either (nothing
     * uploaded yet to review) — Open there opens the editable capture form.
     * RentalApplicationReviewController::show() itself has no status guard
     * of its own, so this list is the only thing standing between an
     * approved/declined row and a working review screen — get this wrong
     * here and it's wrong everywhere.
     */
    public const REVIEWABLE_STATUSES = ['in_progress', 'returned', 'reopened', 'under_assessment'];

    private function applyTileFilter($query, string $tile): void
    {
        $def = self::TILES[$tile] ?? self::TILES['all'];
        if ($def['statuses'] !== null) {
            $query->whereIn('rental_applications.status', $def['statuses']);
        }
        if ($def['submitted_for_approval'] === true) {
            $query->whereNotNull('rental_applications.submitted_for_approval_at');
        } elseif ($def['submitted_for_approval'] === false) {
            $query->whereNull('rental_applications.submitted_for_approval_at');
        }
    }

    /**
     * Johan, 2026-09-07 — real-use bug: uploadDocuments() only ever advances
     * status sent -> in_progress (it never reaches 'returned', which requires
     * the full sign-both-declarations submit — see
     * RentalApplicationSigningController::submit()). This screen's status
     * filter excluded 'in_progress' entirely, so an applicant who uploaded a
     * real document without finishing the full signature flow was invisible
     * here — not because the document was broken (it was correctly filed,
     * linked, and rendered on show()), but because the APPLICATION never
     * surfaced on the one screen named for reviewing incoming applicant
     * activity. 'in_progress' also still shows on index() — deliberately
     * left there too rather than removed, so nothing an agent currently
     * relies on seeing disappears as a side effect of this fix.
     */
    /**
     * AT-402 — "Returned Applications" is retired as a screen but its route
     * NAME and URI both stay registered exactly as before (routes/web.php
     * is unchanged) so nothing bookmarked, emailed, or written into an
     * audit trail 404s. Redirects into the control centre with the
     * equivalent tile pre-selected — see TILE_FOR_LEGACY_STATUS below for
     * the exact old-status → new-tile mapping. Every other query param
     * (q, date_from/to, per_page, sort, direction, scope, archived) is
     * preserved verbatim, so a bookmarked filtered/sorted/paginated old URL
     * lands on the equivalent filtered/sorted/paginated new one, not just
     * the bare screen.
     */
    public function returned(Request $request): \Illuminate\Http\RedirectResponse
    {
        $params = $request->query();
        $oldStatus = $params['status'] ?? null;
        unset($params['status']);

        if ($oldStatus !== null && array_key_exists($oldStatus, self::TILE_FOR_LEGACY_STATUS)) {
            $params['tile'] = self::TILE_FOR_LEGACY_STATUS[$oldStatus];
            // under_assessment is now split into two tiles (with-agent vs
            // with-authoriser) — a bare old ?status=under_assessment can't
            // honestly map to just one of them without hiding half of what
            // it used to show, so it keeps its literal status filter (still
            // respected by applySearchSortAndDateRange()) under the 'all'
            // tile rather than picking one arbitrarily.
            if ($oldStatus === 'under_assessment') {
                $params['status'] = $oldStatus;
            }
        } else {
            // REGRESSION FIX (2026-09-11) — bare /returned (no ?status= at
            // all) was landing on 'all', not 'returned'. Reasoned at build
            // time that 'all' was the technically-honest superset of the
            // old screen's exact status union; in practice it meant an old
            // bookmark for "Returned Applications" landed on a mixed list
            // of EVERYTHING (drafts included) with no obvious connection to
            // what was bookmarked — indistinguishable from "the screen is
            // gone" (Johan/conductor, reproduced live). The screen's own
            // NAME is what a bookmark represents, not its exact old status
            // union — 'returned' is what "Returned Applications" means.
            $params['tile'] = 'returned';
        }

        return redirect()->route('corex.rental-applications.index', $params);
    }

    /**
     * Maps every old ?status= value either screen's own filter form/tab bar
     * could send (index.blade.php's <select>: draft/sent/in_progress/
     * withdrawn; returned.blade.php's tab bar: in_progress/returned/
     * reopened/under_assessment/approved/declined/withdrawn) onto the new
     * control centre's tile keys — used by returned()'s redirect above AND
     * by index() itself, so a bookmarked `/rental-applications?status=draft`
     * (which never needed a redirect — the URI is unchanged) still lands on
     * the right ACTIVE tile, not just a correctly-narrowed-but-mislabelled
     * list under a stale 'All' highlight.
     */
    private const TILE_FOR_LEGACY_STATUS = [
        'draft' => 'not_yet_submitted',
        'sent' => 'not_yet_submitted',
        'in_progress' => 'not_yet_submitted',
        'returned' => 'returned',
        'reopened' => 'reopened',
        'under_assessment' => 'all', // see returned() above — deliberately not split here
        'approved' => 'approved',
        'declined' => 'declined',
        'withdrawn' => 'withdrawn',
    ];

    /**
     * AT-392 — Johan, QA1: "no user action... may EVER discard typed
     * input." A failed store() (e.g. a stale contact/property id) redirects
     * back here with old() flashed — but the contact/property picker is
     * Alpine state seeded from nothing, so the agent's search-and-select
     * work was silently wiped even though old() had the ids all along.
     * Resolved server-side (through the same agency-scoped models, so a
     * stale/foreign id just resolves to null rather than leaking anything)
     * and handed to the view to seed the Alpine component's initial state.
     */
    public function create(): View
    {
        $oldContact = old('contact_id') ? Contact::find(old('contact_id')) : null;
        $oldProperty = old('property_id') ? Property::find(old('property_id')) : null;

        return view('corex.rental-applications.create', compact('oldContact', 'oldProperty'));
    }

    /**
     * Lightweight property picker for the create page — deliberately its
     * own endpoint under this feature's own permission, rather than
     * borrowing another feature's search route (e.g. the filing register's,
     * gated on a different permission an agent here may not hold).
     */
    public function searchProperties(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        // 2026-09-10 — applies the SAME scopeVisibleTo() the write paths now
        // enforce (Property::findLinkableForRentalApplication()), so the
        // picker never offers a property the agent couldn't actually link —
        // a branch/own-restricted agent used to see the whole agency's
        // rental stock here and only find out it wasn't linkable after
        // picking it and getting refused.
        $properties = Property::query()
            ->where('listing_type', 'rental')
            ->visibleTo($request->user())
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q) {
                $w->where('address', 'like', "%{$q}%")->orWhere('title', 'like', "%{$q}%");
            }))
            ->orderByDesc('id')
            ->limit(10)
            ->get(['id', 'address', 'title', 'suburb']);

        return response()->json($properties->map(fn (Property $p) => [
            'id' => $p->id,
            'label' => $p->title ?: trim($p->address . ', ' . $p->suburb, ', '),
        ]));
    }

    /**
     * AT-392 spec §1 — contact required, everything else optional. Nothing
     * here may block a send (BUILD_STANDARD §2, the input-space rule).
     *
     * property_id, 2026-09-10 — same cross-tenant class cc1 found on the
     * review screen's link-property endpoint (`exists:properties,id` is a
     * raw, unscoped query). contact_id never actually shared this bug —
     * `Contact::findOrFail()` three lines below already goes through the
     * model, so AgencyScope already 404d a cross-agency contact_id before
     * RentalApplication::create() was ever reached — but the validation
     * rule ITSELF was still the raw, bypassing `exists:contacts,id` shape,
     * safe only because of a second check happening to exist after it.
     * QA1 multi-tenancy sweep, 2026-09-12 — converted to ExistsInScope so
     * the validation layer is correct on its own, not safe by accident.
     *
     * property_id refusal, 2026-09-11 — Johan's own standing rule for this
     * whole feature: "no user action may EVER discard typed input." The
     * cross-tenant fix's first pass used abort(403) here, which is right
     * for link-property (an AJAX-style action on an ALREADY-SAVED
     * application — nothing typed is at risk) but wrong for this FORM: a
     * bare 403 is a dead-end page with no explanation and it discarded the
     * contact the agent had already picked, for the ordinary, non-malicious
     * case this was meant to also catch — a property genuinely deleted
     * between search and submit (the exact scenario
     * RentalApplicationInputPreservationTest's own docblock names). Fixed
     * to the same validation-redirect shape every other field on this form
     * already uses: back()->withInput()->withErrors(), contact selection
     * preserved, one plain-language message. Security posture UNCHANGED —
     * still refuses identically whether the id is genuinely nonexistent or
     * just not this user's, same as before; only the FAILURE RESPONSE
     * shape changed, not what's allowed through.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'contact_id' => ['required', 'integer', new \App\Rules\ExistsInScope(Contact::class)],
            'property_id' => ['nullable', 'integer'],
        ]);

        $contact = Contact::findOrFail($validated['contact_id']);

        $requestedPropertyId = $validated['property_id'] ?? null;
        $property = Property::findLinkableForRentalApplication($requestedPropertyId, $request->user());
        if ($requestedPropertyId !== null && $property === null) {
            \Illuminate\Support\Facades\Log::warning('AT-392 rental application create: refused cross-tenant/out-of-scope property_id', [
                'user_id' => $request->user()->id,
                'agency_id' => $request->user()->effectiveAgencyId(),
                'requested_property_id' => $requestedPropertyId,
            ]);

            return back()->withInput()->withErrors([
                'property_id' => "That property isn't available to link — it may have been removed, or you may not have access to it. Search for it again above.",
            ]);
        }

        // Johan, QA1 — "have not even sent anything, yet top left shows
        // sent?" status starts 'draft' (true starting state — nothing has
        // been sent yet) and the token/link are generated here, not inside
        // send(): the online/download link must exist immediately (so an
        // agent can copy and share it manually even for a contact with no
        // email — the spec's own "share the links below directly" route),
        // independent of whether the email-gated Send button is ever used.
        $application = RentalApplication::create(array_merge(
            $this->prefillFromContact($contact),
            [
                'contact_id' => $contact->id,
                'property_id' => $property?->id,
                'branch_id' => $request->user()->effectiveBranchId(),
                'created_by_user_id' => $request->user()->id,
                'status' => 'draft',
                'token' => $this->generateToken(),
                // QA1 design-standard audit, 2026-09-11 — was hardcoded
                // addDays(14) while the reopen link on this same model
                // already had a working agency-configurable equivalent
                // (RentalApplicationQualifyingSetting::reopenLinkExpiryDaysFor(),
                // same 14-day default) that this first link was simply
                // never wired to. Reusing that setting rather than adding
                // a second one, per instruction.
                'token_expires_at' => now()->addDays(
                    RentalApplicationQualifyingSetting::reopenLinkExpiryDaysFor($request->user()->effectiveAgencyId())
                ),
            ],
        ));

        return redirect()
            ->route('corex.rental-applications.show', $application)
            ->with('success', 'Rental application created. Review it, then send.');
    }

    /**
     * AT-392 — inline "create new contact" from the rental-application
     * create picker (2026-09-12, greenlit by Johan). A walk-in enquiry not
     * yet in Contacts is the most ordinary rental scenario there is; before
     * this the agent had to abandon the form, create the contact
     * separately, then come back. Minimum-viable fields only — this is
     * deliberately NOT the full contact form in a modal.
     *
     * Reuses the actual canonical machinery rather than re-implementing it:
     * - Duplicate check: the SAME `ContactDuplicateService` (and therefore
     *   the SAME agency-configurable mode — no hardcoded threshold added
     *   here) `ContactController::store()` already uses. Returns 422 with
     *   the match list so the agent links the existing person instead of
     *   minting a second record; `auto_link` mode returns the existing
     *   contact directly, same as store().
     * - Type assignment: NONE at creation, on purpose (corrected
     *   2026-09-12 — see .ai/specs/rental-applications.md, "Inline
     *   create-contact type correction"). The first cut of this wrongly
     *   assigned "Lessee" (id 10, the CANONICAL e-sign-wizard parent) —
     *   a genuinely different database row from "Tenant" (id 11, the type
     *   `AddTenantTypeOnRentalApproval` actually adds, and the one every
     *   report/filter in this module is keyed on). Picking an existing
     *   contact via the normal search box also assigns no type at
     *   creation — type only ever arrives via `AddTenantTypeOnRentalApproval`
     *   on APPROVAL, for every contact regardless of entry door. Stamping
     *   a type here — even the correct one — would make an inline-created
     *   contact diverge from that rule (e.g. a DECLINED applicant would
     *   wrongly carry a rental type forever, since Johan's add-never-strip
     *   rule means nothing ever removes it). Leaving this path
     *   type-less at creation is what makes it behave identically, from
     *   day one through approval, to a contact picked via the pre-existing
     *   search box — not a gap, the correct behaviour.
     * - Identifiers: `ContactIdentifierService::syncIdentifiers()` — the
     *   same child-row writer every other contact-creation path uses.
     *
     * Never touches `ContactController.php` or any contact Blade template.
     */
    public function quickCreateContact(Request $request)
    {
        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'phone'      => 'nullable|string|max:30',
            'email'      => 'nullable|email|max:150',
            'bypass_duplicate_check' => 'nullable|boolean',
        ]);

        $phone = trim((string) ($data['phone'] ?? ''));
        $email = trim((string) ($data['email'] ?? ''));
        if ($phone === '' && $email === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'phone' => 'Add a phone number or an email address so this contact can be reached.',
            ]);
        }

        $user = $request->user();
        $agencyId = (int) ($user->effectiveAgencyId() ?: 0);
        $duplicateService = app(\App\Services\ContactDuplicateService::class);

        if (empty($data['bypass_duplicate_check'])) {
            $duplicates = $duplicateService->findDuplicatesForIdentifiers(
                $phone !== '' ? [$phone] : [],
                $email !== '' ? [$email] : [],
                null,
                $agencyId,
            );

            if ($duplicates->isNotEmpty()) {
                $mode = $duplicateService->resolveMode($agencyId);
                $match = $duplicateService->identifyMatch([
                    'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'phone' => $phone ?: null, 'email' => $email ?: null,
                ], $duplicates->first(), $agencyId);

                if ($mode === 'auto_link') {
                    $existing = $duplicates->first();
                    if (Contact::whereKey($existing->id)->exists()) {
                        $duplicateService->logAttempt($agencyId, $user->id, $mode, $match['field'], $match['value'], $existing->id, $data, 'auto_linked');
                        return response()->json([
                            'linked_existing' => true,
                            'contact' => [
                                'id' => $existing->id,
                                'first_name' => $existing->first_name,
                                'last_name' => $existing->last_name,
                            ],
                        ]);
                    }
                }

                $viewableIds = Contact::whereIn('id', $duplicates->pluck('id'))->pluck('id')->all();
                return response()->json([
                    'duplicates' => $duplicates->map(function (Contact $c) use ($mode, $viewableIds) {
                        $canView = in_array($c->id, $viewableIds, true);
                        $hide = $mode === 'hard_block_request' || !$canView;
                        return [
                            'id' => $c->id,
                            'name' => $c->full_name,
                            'phone' => $hide ? null : $c->phone,
                            'email' => $hide ? null : $c->email,
                            'can_view' => $canView,
                        ];
                    }),
                    'mode' => $mode,
                ], 422);
            }
        }

        $contact = DB::transaction(function () use ($data, $phone, $email, $user, $agencyId) {
            $contact = Contact::create([
                'contact_kind' => Contact::TYPE_NATURAL_PERSON,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'agency_id' => $agencyId,
                'branch_id' => $user->effectiveBranchId(),
                'created_by_user_id' => $user->id,
            ]);

            app(\App\Services\Contacts\ContactIdentifierService::class)->syncIdentifiers(
                $contact,
                $phone !== '' ? [['value' => $phone, 'label' => null, 'is_primary' => true]] : [],
                $email !== '' ? [['value' => $email, 'label' => null, 'is_primary' => true]] : [],
            );

            // No type assigned here, deliberately — see the method docblock.
            // AddTenantTypeOnRentalApproval adds "Tenant" on approval, the
            // same as it already does for a contact picked via search.

            return $contact;
        });

        return response()->json([
            'contact' => [
                'id' => $contact->id,
                'first_name' => $contact->first_name,
                'last_name' => $contact->last_name,
            ],
        ], 201);
    }

    /**
     * AT-392, Johan (asked three times, verbatim): "opening a rental
     * application should not be able to edit... open / view should show
     * the application the applicant sent in. nothing more. no edits,
     * nothing... A signed document a third party can alter afterwards is
     * worthless as evidence."
     *
     * Once the applicant has actually submitted (any status in
     * POST_RETURN_STATUSES), View renders a read-only page built around
     * the SAME PDF this module already generates for download
     * (RentalApplicationPdfService — reused via pdfInline(), never a
     * second rendering) instead of the editable field form. Before
     * submission (draft/sent/in_progress) nothing changes — the agent is
     * still building/sending it and there is nothing signed yet to
     * protect.
     */
    public function show(Request $request, RentalApplication $rentalApplication): View
    {
        $this->guardRentalApplication($rentalApplication);
        $rentalApplication->load(['contact', 'property', 'signatures', 'documents.documentType', 'documents.uploader', 'statusHistory.changedBy']);

        // Reopen/resubmit, 2026-09-08 — AGENT_EDIT_LOCKED_STATUSES (not
        // POST_RETURN_STATUSES), so an agent viewing this page while the
        // application is 'reopened' still gets the read-only view, never an
        // editable-looking form that would only 403 on submit (update()
        // itself already blocks 'reopened' — this keeps what's SHOWN
        // consistent with what's actually ALLOWED).
        if (in_array($rentalApplication->status, RentalApplication::AGENT_EDIT_LOCKED_STATUSES, true)) {
            return view('corex.rental-applications.view-readonly', compact('rentalApplication'));
        }

        return view('corex.rental-applications.show', compact('rentalApplication'));
    }

    /**
     * The same generated PDF as pdf() (RentalApplicationPdfService — one
     * rendering, never a second one that could drift), served inline for
     * the read-only View screen's embedded viewer instead of forcing a
     * download. Never a hand-editable page: this is a flat PDF byte
     * stream with no form, no inputs, nothing to submit.
     */
    public function pdfInline(RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $service = app(\App\Services\RentalApplications\RentalApplicationPdfService::class);
        $path = $service->generate($rentalApplication);

        return response()->file($path, [
            'Content-Disposition' => 'inline; filename="Rental Application - ' . ($rentalApplication->full_name ?: $rentalApplication->contact->full_name) . '.pdf"',
        ])->deleteFileAfterSend(true);
    }

    /**
     * AT-392 — Johan, independent testing (cc5), RA-05: "Tab 1 saves a
     * field. Tab 2, opened earlier and unaware, then saves a different
     * field and SILENTLY BLANKS Tab 1's genuine save." Same family as
     * every other input-loss defect on this feature — work an agent
     * genuinely did disappears with no warning.
     *
     * No existing "hidden updated_at + compare on submit" trait exists
     * anywhere in CoreX (checked before building this). The closest
     * precedent is `Property::galleryFingerprint()` +
     * `PropertyController::reorderImages()` — a content-fingerprint
     * hidden in the request, hard-blocked with a 409-style refusal on
     * mismatch rather than silently merged or just warned-after-the-fact.
     * Mirrors that shape here with `updated_at` (simpler than a field
     * hash, and correctly reflects "changed since I opened this" for a
     * plain Blade form) — a hidden `expected_updated_at` seeded when the
     * page loads, compared against the record's actual current
     * `updated_at` before ANY write happens. On mismatch: the save is
     * refused outright (matching the CoreX precedent's hard-block, not a
     * softer warn-then-save), with `old()` preserving everything the
     * SECOND tab typed so reloading and redoing the edit costs nothing.
     */
    public function update(Request $request, RentalApplication $rentalApplication, \App\Services\RentalApplications\RentalApplicationAuditService $audit)
    {
        $this->guardRentalApplication($rentalApplication);

        // AT-392, Johan (asked three times): "no edits, nothing... A signed
        // document a third party can alter afterwards is worthless as
        // evidence." Enforced HERE, not just by the View screen no longer
        // rendering a form — a hand-crafted PUT against this route must be
        // refused too, regardless of what any page shows. Once the
        // applicant has submitted, this action is permanently closed; there
        // is no override, no permission that reopens it.
        // Reopen/resubmit, 2026-09-08 — AGENT_EDIT_LOCKED_STATUSES (not
        // POST_RETURN_STATUSES) so this also blocks while 'reopened': the
        // applicant is the one editing these fields right now.
        abort_if(
            in_array($rentalApplication->status, RentalApplication::AGENT_EDIT_LOCKED_STATUSES, true),
            403,
            'This application was submitted and signed by the applicant — its answers can no longer be edited.'
        );

        $expectedUpdatedAt = $request->input('expected_updated_at');
        if ($expectedUpdatedAt !== null && $expectedUpdatedAt !== ''
            && (int) $expectedUpdatedAt !== $rentalApplication->updated_at?->timestamp) {
            return back()->withInput()->with('error',
                'Someone else saved changes to this application after you opened it — your changes were NOT saved, to avoid silently overwriting theirs. Reload the page to see the latest version, then redo your edit.'
            );
        }

        $request->merge(RentalApplication::sanitizeNumericInput($request->only(RentalApplication::NUMERIC_FIELDS)));

        $validated = $request->validate(array_merge(RentalApplication::fieldValidationRules(), [
            'property_id' => ['nullable', 'integer'],
        ]));

        // 2026-09-10 — same cross-tenant class cc1 found on the review
        // screen (exists:properties,id is a raw, unscoped query). Only
        // re-resolve when the field was actually sent — same "absent means
        // keep the existing value" contract line 406 already had, so a form
        // post that never touches property_id can't be misread as "clear it."
        //
        // Refusal shape, 2026-09-11 — same fix as store(): a bare abort(403)
        // here discarded EVERY OTHER field on this full-form save, not just
        // the property — worse than store()'s version of the same mistake.
        // Redirect-with-input instead, same security guarantee (still
        // refused identically either way), just not a data-destroying
        // dead end for the ordinary "property deleted meanwhile" case.
        if ($request->has('property_id')) {
            $requestedPropertyId = $validated['property_id'] ?? null;
            $property = \App\Models\Property::findLinkableForRentalApplication($requestedPropertyId, $request->user());
            if ($requestedPropertyId !== null && $property === null) {
                $audit->log(
                    $rentalApplication,
                    eventCategory: 'property_link',
                    eventType: 'link_refused',
                    user: $request->user(),
                    newValues: ['requested_property_id' => $requestedPropertyId],
                    humanSummary: "Refused: property #{$requestedPropertyId} isn't visible to this user (wrong agency, branch, or book).",
                );

                return back()->withInput()->withErrors([
                    'property_id' => "That property isn't available to link — it may have been removed, or you may not have access to it. Search for it again above.",
                ]);
            }
            $validated['property_id'] = $property?->id;
        }

        $fields = collect($validated)->except(['property_id'])->all();
        $fields = array_map(fn ($v) => $v === '' ? null : $v, $fields);
        $fields = RentalApplication::normalizeStillLiving($fields);

        DB::transaction(function () use ($rentalApplication, $validated, $fields) {
            $rentalApplication->update(array_merge(
                ['property_id' => $validated['property_id'] ?? $rentalApplication->property_id],
                $fields,
            ));

            $this->backfillContactEmail($rentalApplication);
        });

        return redirect()
            ->route('corex.rental-applications.show', $rentalApplication)
            ->with('success', 'Saved.');
    }

    /**
     * Johan, QA1 — "once we have an email for a contact we update the
     * contact." Fill-only, never overwrite: if the contact already has a
     * DIFFERENT email on file, that's real CRM data an agent entered
     * deliberately elsewhere — a document-edit screen must never silently
     * rewrite it. Only fires when the contact's own email is genuinely
     * empty. Uses Contact::auditedQuietUpdate() (the sanctioned "meaningful
     * quiet write" path, AT-321-C) so this shows up in the contact's own
     * audit trail rather than looking like it appeared from nowhere.
     * Agency scoping: $rentalApplication->contact is already resolved
     * through the model relationship, which is itself agency-scoped via
     * Contact's global AgencyScope — this can never reach a contact
     * outside the application's own agency.
     */
    private function backfillContactEmail(RentalApplication $rentalApplication): void
    {
        $email = $rentalApplication->email;
        $contact = $rentalApplication->contact;

        if (! $email || ! $contact || $contact->email) {
            return;
        }

        $contact->auditedQuietUpdate(
            ['email' => $email],
            eventType: 'contact_updated',
            summary: 'Email filled in from a rental application (contact had none on file).',
        );
    }

    /**
     * AT-392 — Johan, QA1: "on returned applications theres statuses at the
     * top, but theres no way to mark application status to what it is?"
     * Only the agent's own judgement calls are settable by hand
     * (RentalApplication::AGENT_SETTABLE_STATUSES) — draft/sent/in_progress/
     * returned are system-recorded facts and stay off this endpoint's
     * allow-list entirely, so there is no way to fake them even with a
     * crafted request. Only reachable once the application has actually
     * been returned (POST_RETURN_STATUSES) — assessing something the
     * applicant hasn't submitted yet makes no sense. Every change is
     * recorded via RentalApplicationStatusHistory::record() — who, when,
     * from what to what — inside the same transaction as the status write.
     */
    public function updateStatus(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $validated = $request->validate([
            'status' => ['required', Rule::in(RentalApplication::AGENT_SETTABLE_STATUSES)],
            'note' => ['nullable', 'string', 'max:1000'],
            'expected_generation' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! in_array($rentalApplication->status, RentalApplication::POST_RETURN_STATUSES, true)) {
            return back()->withInput()->with('error', "This application hasn't been submitted yet — there's nothing to assess.");
        }

        // Reopen/resubmit, 2026-09-08 — the applicant may have reopened-and-
        // resubmitted since this agent's screen last loaded. Same 409-style
        // guard as saveAssessment() below, expressed as a redirect-with-
        // error since this action isn't an AJAX endpoint.
        try {
            $rentalApplication->assertGenerationMatches($request->input('expected_generation') !== null ? (int) $request->input('expected_generation') : null);
        } catch (\App\Exceptions\RentalApplicationGenerationConflictException $e) {
            return back()->withInput()->with('error', 'This application changed since you opened it (the applicant resubmitted) — reload to see the new version before making a decision.');
        }

        $from = $rentalApplication->status;
        $to = $validated['status'];

        if ($from === $to) {
            return back()->with('success', 'Status unchanged.');
        }

        DB::transaction(function () use ($rentalApplication, $from, $to, $validated) {
            $rentalApplication->update(['status' => $to]);

            RentalApplicationStatusHistory::record(
                $rentalApplication,
                $from,
                $to,
                auth()->user(),
                $validated['note'] ?? null,
            );
        });

        return back()->with('success', 'Status updated to ' . str_replace('_', ' ', $to) . '.');
    }

    /**
     * AT-392 spec §4 — one send, two return routes, applicant's choice.
     * Token/link generation now happens in store() for anything created
     * through the normal flow — but this action must not assume that: a
     * legacy record (created before this fix) or any other creation path
     * can still reach here with no token. Self-healing it here, exactly
     * as this method always did, is cheap and removes a real failure mode
     * — found the hard way: with the fallback removed, sending for a
     * token-less record threw building the public link inside the mail
     * content, silently swallowed by sendInvite()'s own catch block, so
     * "could not send" was reported with no clue why.
     *
     * Johan, QA1 — "no email present and resets the form" / "status says
     * sent on something never sent": Send is disabled client-side without
     * a saved email (show.blade.php), but a direct POST must be refused
     * the same way server-side — never trust a disabled attribute alone.
     * And status only ever becomes 'sent' when mail genuinely left
     * (`$mailSent === true`); a failed/refused send must never claim it.
     */
    public function send(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $recipientEmail = $rentalApplication->recipientEmail();

        if (! $recipientEmail) {
            return redirect()
                ->route('corex.rental-applications.show', $rentalApplication)
                ->with('error', 'Add an email address and save before sending.');
        }

        if (! $rentalApplication->token) {
            $rentalApplication->token = $this->generateToken();
            // Same agency-configurable setting as store() above — see that
            // comment for why this is no longer hardcoded.
            $rentalApplication->token_expires_at = now()->addDays(
                RentalApplicationQualifyingSetting::reopenLinkExpiryDaysFor($rentalApplication->agency_id)
            );
            $rentalApplication->save();
        }

        $mailSent = app(RentalApplicationMailer::class)->sendInvite($rentalApplication);

        if (! $mailSent) {
            return redirect()
                ->route('corex.rental-applications.show', $rentalApplication)
                ->with('error', 'Could not send — please try again.');
        }

        // A resend of an application the applicant has already progressed
        // (in_progress/returned/etc.) must not regress its status back to
        // 'sent' — only the FIRST successful send moves it off 'draft'.
        if ($rentalApplication->status === 'draft') {
            $rentalApplication->status = 'sent';
            $rentalApplication->save();
        }

        // AT-392 — Johan, QA1: "once send is clicked redirect back to rental
        // application screen" — the list, not the single application's own
        // show page. Only the successful-send path moves; the two error
        // returns above stay on show() so the agent can see and fix the
        // problem (a missing email, a failed send) on the application that
        // has it, rather than losing that context on the list.
        return redirect()
            ->route('corex.rental-applications.index')
            ->with('success', 'Sent to ' . $recipientEmail . '. Both the download link and the online link are on the application page too, if you want to share them another way.');
    }

    public function pdf(RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $service = app(\App\Services\RentalApplications\RentalApplicationPdfService::class);
        $path = $service->generate($rentalApplication);

        return response()->download($path, 'Rental Application - ' . ($rentalApplication->full_name ?: $rentalApplication->contact->full_name) . '.pdf')
            ->deleteFileAfterSend(true);
    }

    /**
     * BUILD_STANDARD §1 — full CRUD is the floor. No hard deletes anywhere in
     * CoreX (STANDARDS.md) — RentalApplication already has SoftDeletes, this
     * is the archive action the index/show screens were missing.
     */
    public function destroy(Request $request, RentalApplication $rentalApplication, \App\Services\RentalApplications\RentalApplicationPdfService $pdfService)
    {
        $this->guardRentalApplication($rentalApplication);

        // Reopen/resubmit follow-up, 2026-09-09 — a cached generation PDF is
        // a derived artefact (the record of truth, snapshot_json, is
        // untouched by this), so it's reclaimed here rather than left
        // orphaned on the data volume for an application nobody can reach
        // again except through restore(). Never blocks the archive itself —
        // forgetCacheFor() swallows its own failures.
        $pdfService->forgetCacheFor($rentalApplication);

        $rentalApplication->delete();

        // AT-402 — now ONE screen, so "where do I land" is just "the tile
        // this application actually belongs to," derived from its own
        // status rather than a hidden return_to field naming a now-retired
        // second screen. Same reasoning as before (an archived returned/
        // approved/etc. application landing on a tile that never shows it
        // would look like Archive did nothing) — just one source of truth
        // instead of two routes to keep in sync.
        return redirect()
            ->route('corex.rental-applications.index', ['tile' => $this->tileForApplication($rentalApplication)])
            ->with('success', 'Rental application archived.');
    }

    /** AT-402 — the tile a given application's CURRENT status belongs to. Mirrors TILES's own split. */
    private function tileForApplication(RentalApplication $application): string
    {
        if (in_array($application->status, ['draft', 'sent', 'in_progress'], true)) {
            return 'not_yet_submitted';
        }
        if ($application->status === 'under_assessment') {
            return $application->submitted_for_approval_at ? 'sent_for_authorisation' : 'under_assessment';
        }

        return $application->status;
    }

    /**
     * Johan, 2026-09-07 — full CRUD includes "restore from archive," not
     * just archive. Route-model-binding on a soft-deleted row 404s by
     * default, so the {rentalApplication} parameter is bound explicitly
     * withTrashed() here — the only action in this controller that needs to.
     */
    public function restore(Request $request, int $rentalApplication)
    {
        $application = RentalApplication::withTrashed()->findOrFail($rentalApplication);
        $this->guardRentalApplication($application);

        $application->restore();

        // AT-402 — same reasoning as destroy() above: land on the tile the
        // restored application's own status actually belongs to.
        return redirect()
            ->route('corex.rental-applications.index', ['tile' => $this->tileForApplication($application), 'archived' => 1])
            ->with('success', 'Rental application restored.');
    }

    /**
     * Supporting-document download for the agent side (spec §5). Both
     * $rentalApplication and $document implicitly scope via their own
     * BelongsToAgency global scope (a cross-agency id 404s at route-model-
     * binding, before this method ever runs) — the explicit source_type/
     * source_id check below is defense-in-depth against a same-agency
     * agent guessing a document id that belongs to a DIFFERENT application.
     * The own/branch/agency guard below covers the finer-grained visibility
     * tier on top of that agency-level check.
     */
    public function downloadDocument(RentalApplication $rentalApplication, Document $document)
    {
        $this->guardRentalApplication($rentalApplication);

        abort_unless(
            $document->source_type === 'rental_application' && (int) $document->source_id === $rentalApplication->id,
            404
        );

        return $document->downloadResponse();
    }

    /**
     * AT-392 — Johan: "agent should in any case be able to add docs as
     * client can be in the office so agent scans docs to themselves, or
     * even receive via whatsapp etc." The applicant's own upload path is
     * NOT reused directly (that's token-based, unauthenticated, public) —
     * but the exact same Document model, storage convention, allowlist,
     * and soft-delete rule are, so this is not a second document path,
     * just a second AUTHENTICATED entry point onto the one path.
     *
     * `uploaded_by` (already an existing column/relation on Document,
     * `uploader()`) is the ONLY thing that needs setting here — the
     * applicant's own upload never sets it (no authenticated user in that
     * public context), so it's already the natural "who added this"
     * signal with no new column needed. Screens distinguish "From
     * applicant" (uploaded_by null) from "Added by {agent}" (uploaded_by
     * set) purely by checking whether it's null.
     *
     * Agency/branch scoping: Document::create() auto-stamps agency_id from
     * the authenticated acting user via its own BelongsToAgency trait (no
     * withoutAgencyStamping() needed here — that escape hatch is only for
     * the public, unauthenticated upload path).
     */
    public function uploadDocument(Request $request, RentalApplication $rentalApplication)
    {
        $this->guardRentalApplication($rentalApplication);

        $request->validate([
            'supporting_files' => ['required', 'array', 'min:1', 'max:10'],
            'supporting_files.*' => ['file', 'mimes:pdf,jpg,jpeg,png,doc,docx', 'max:15360'],
        ]);

        $filedDocuments = [];
        foreach ($request->file('supporting_files') as $file) {
            $path = $file->store("rental-applications/{$rentalApplication->id}/documents", 'local');

            $document = Document::create([
                'original_name' => $file->getClientOriginalName(),
                'storage_path' => $path,
                'disk' => 'local',
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'source_type' => 'rental_application',
                'source_id' => $rentalApplication->id,
                'branch_id' => $rentalApplication->branch_id,
                'uploaded_by' => $request->user()->id,
            ]);

            $document->contacts()->syncWithoutDetaching([$rentalApplication->contact_id]);
            if ($rentalApplication->property_id) {
                $document->properties()->syncWithoutDetaching([$rentalApplication->property_id]);
            }

            $filedDocuments[] = $document;
        }

        if ($request->wantsJson()) {
            return response()->json([
                'documents' => collect($filedDocuments)->map(fn ($d) => [
                    'id' => $d->id,
                    'name' => $d->original_name,
                    'view_url' => route('corex.rental-applications.documents.download', [$rentalApplication, $d]),
                ]),
            ]);
        }

        return back()->with('success', count($filedDocuments) === 1 ? 'Document added.' : count($filedDocuments) . ' documents added.');
    }

    /**
     * A token must be unique ACROSS every agency, not just the acting user's
     * own — two different agencies' applications must never collide. Uses the
     * model's own sanctioned cross-tenant escape hatch (BelongsToAgency::
     * queryWithoutAgencyScope()), never a raw withoutGlobalScope() call in
     * request code (CLAUDE.md Non-negotiable #7).
     */
    private function generateToken(): string
    {
        do {
            $token = Str::random(64);
        } while (RentalApplication::queryWithoutAgencyScope()->where('token', $token)->exists());

        return $token;
    }

    /**
     * AT-392 spec §1 — prefill wherever a V8 field maps to a real contacts
     * column (see the AT-332 investigation: marital_status, spouse_name,
     * spouse_id, citizenship and a distinct work number do NOT exist on
     * contacts — only these five genuinely map).
     */
    private function prefillFromContact(Contact $contact): array
    {
        return [
            'full_name' => $contact->full_name,
            'id_number' => $contact->id_number,
            'email' => $contact->email,
            'cell' => $contact->phone,
            'current_residential_address' => trim(implode(', ', array_filter([
                $contact->address, $contact->suburb, $contact->city,
            ]))),
        ];
    }

}
