<?php

namespace App\Services\RentalApplications;

use App\Models\Lease;
use App\Models\RentalApplication;
use App\Models\RentalApplicationChecklistItem;
use App\Models\RentalApplicationChecklistSection;
use App\Models\RentalChecklistTemplateItem;
use App\Models\RentalChecklistTemplateSection;
use Illuminate\Support\Facades\DB;

/**
 * AT-430 §3 — the checklist half of the review-screen right-hand panel.
 *
 * Three responsibilities, kept in one place because they share the same
 * template/snapshot data:
 *  - seedDefaultTemplateFor() — Sherry's paper checklist, shipped as every
 *    agency's starting template (idempotent, same AgencyCreated-listener
 *    convention as RentalApplicationDeclineReasonTemplate).
 *  - snapshotFor() — copies the agency's CURRENT template onto a new
 *    application at creation time (§3.2: "Applications take a snapshot of
 *    the template at creation, so editing the template later does not
 *    rewrite applications already in flight").
 *  - syncDerivedStates() / isCompleteFor() — the Lease-progress derived
 *    items (Part D) and the approve-gate check. isCompleteFor() is the
 *    ONLY thing cc4's approval controller needs to call to honour
 *    applications.require_checklist_complete — this lane does not touch
 *    the approval controller itself (ownership boundary, AT-430 tasking).
 */
class RentalApplicationChecklistService
{
    /**
     * AT-430 §3.4 — Sherry's paper checklist, verbatim. Each item entry is
     * [name, help_text, note_required, derived_key]. derived_key is set
     * ONLY on the three Lease-progress items Johan ruled are system-derived
     * (Part D) — every other item is a plain manual tick.
     */
    public static function defaultTemplateSeed(): array
    {
        return [
            'Application' => [
                ['Viewed', null, false, null],
                ['Application received', null, false, null],
                ['Occupants / cars / pets recorded', null, false, null],
            ],
            'Documents' => [
                ['ID', null, false, null],
                ['Proof of address', null, false, null],
                ['Payslip', null, false, null],
                ['Employment confirmed', null, false, null],
                ['Tax number', null, false, null],
                ['Bank statement', null, false, null],
            ],
            'Vetting' => [
                ['Reference obtained', null, false, null],
                ['TPN fee paid', null, false, null],
                ['TPN check done', null, false, null],
                ['TPN outcome recorded', null, true, null],
            ],
            'FICA' => [
                ['FICA sent', null, false, null],
                ['FICA received', null, false, null],
                ['FICA uploaded', null, false, null],
            ],
            'Lease terms' => [
                ['Rent agreed', null, false, null],
                ['Deposit agreed', null, false, null],
                ['Occupation date agreed', null, false, null],
            ],
            'Lease progress' => [
                ['Lease drafted', null, false, null],
                ['Sent to tenant', null, false, null],
                ['Signed by tenant', null, false, null],
                ['Sent to landlord', null, false, null],
                ['Signed by landlord', null, false, null],
                ['Deposit paid', 'Ticks itself off once CoreX can see the lease deposit has been paid.', false, RentalChecklistTemplateItem::DERIVED_KEYS[0]],
                ['First rent paid', 'Ticks itself off once CoreX can see the first rent has been paid.', false, RentalChecklistTemplateItem::DERIVED_KEYS[1]],
                ['Occupied', 'Ticks itself off once the lease start date has passed.', false, RentalChecklistTemplateItem::DERIVED_KEYS[2]],
                ['Body corporate rules signed', null, false, null],
            ],
        ];
    }

    /** Idempotent — no-ops if this agency already has any template section (seeded or hand-created). Same convention as RentalApplicationDeclineReasonTemplate::seedDefaultsFor(). */
    public static function seedDefaultTemplateFor(int $agencyId): void
    {
        if (RentalChecklistTemplateSection::withTrashed()->where('agency_id', $agencyId)->exists()) {
            return;
        }

        DB::transaction(function () use ($agencyId) {
            $sectionOrder = 0;
            foreach (self::defaultTemplateSeed() as $sectionName => $items) {
                $section = RentalChecklistTemplateSection::create([
                    'agency_id' => $agencyId,
                    'name' => $sectionName,
                    'sort_order' => $sectionOrder++,
                ]);

                $itemOrder = 0;
                foreach ($items as [$name, $helpText, $noteRequired, $derivedKey]) {
                    RentalChecklistTemplateItem::create([
                        'agency_id' => $agencyId,
                        'template_section_id' => $section->id,
                        'name' => $name,
                        'help_text' => $helpText,
                        'note_required' => $noteRequired,
                        'is_derived' => $derivedKey !== null,
                        'derived_key' => $derivedKey,
                        'sort_order' => $itemOrder++,
                    ]);
                }
            }
        });
    }

    /**
     * AT-430 §3.2 — copies the agency's current, non-archived template onto
     * an application. Seeds the default template first if this agency has
     * never configured one (covers an agency that existed before this
     * feature shipped and whose AgencyCreated listener therefore never
     * fired for it).
     *
     * Called from two places: RentalApplicationController's create flow
     * (a brand new row nobody else can reach yet — no locking needed) and
     * ensureSnapshotFor() below (an existing application, lockForUpdate()-
     * guarded by the caller). Either way, `checklist_snapshotted_at` is set
     * in the SAME transaction as the section/item inserts — including when
     * the template has zero non-archived sections and nothing gets copied
     * (§5) — so the marker and the rows it describes can never disagree,
     * and a later ensureSnapshotFor() call on this same application (e.g.
     * its first review-screen view, moments after creation) sees the
     * timestamp already set and never runs this a second time.
     */
    public static function snapshotFor(RentalApplication $application): void
    {
        $agencyId = (int) $application->agency_id;
        self::seedDefaultTemplateFor($agencyId);

        $templateSections = RentalChecklistTemplateSection::where('agency_id', $agencyId)
            ->with('items')
            ->orderBy('sort_order')->orderBy('id')
            ->get();

        DB::transaction(function () use ($application, $templateSections) {
            foreach ($templateSections as $templateSection) {
                $snapshotSection = RentalApplicationChecklistSection::create([
                    'agency_id' => $application->agency_id,
                    'rental_application_id' => $application->id,
                    'template_section_id' => $templateSection->id,
                    'name' => $templateSection->name,
                    'sort_order' => $templateSection->sort_order,
                    'description' => null,
                ]);

                foreach ($templateSection->items as $templateItem) {
                    RentalApplicationChecklistItem::create([
                        'agency_id' => $application->agency_id,
                        'application_section_id' => $snapshotSection->id,
                        'template_item_id' => $templateItem->id,
                        'name' => $templateItem->name,
                        'help_text' => $templateItem->help_text,
                        'note_required' => $templateItem->note_required,
                        'document_required' => $templateItem->document_required,
                        'is_derived' => $templateItem->is_derived,
                        'derived_key' => $templateItem->derived_key,
                        'state' => RentalApplicationChecklistItem::STATE_NOT_STARTED,
                        'sort_order' => $templateItem->sort_order,
                    ]);
                }
            }

            $application->forceFill(['checklist_snapshotted_at' => now()])->save();
        });
    }

    /**
     * AT-430 §3.2 amendment, 2026-09-25 — the spec only ever described
     * snapshotting at creation, so every application that already existed
     * when this feature shipped got no snapshot and no path was ever wired
     * to give it one: opening its review screen showed zero checklist
     * sections, indistinguishable in the UI from an agency that has
     * deliberately archived every one of its template sections (§5). This
     * is the lazy fallback both review-screen controllers now call before
     * reading checklistSections — RentalApplicationReviewController::show()
     * and RentalApplicationAuthorisationController::show() (the shared
     * Blade template renders identically for both viewer roles per §3.1,
     * so both call sites need it — missing the second one is the exact gap
     * that shipped once before on a different variable in this same pair
     * of controllers).
     *
     * Never overwrites — snapshotFor()'s whole point, unchanged: if this
     * application has already been snapshotted (rows exist OR the attempt
     * already ran and found nothing to copy), this is a no-op.
     *
     * Race safety: `rental_applications.checklist_snapshotted_at` is the
     * durable claim, checked inside a lockForUpdate() transaction — the
     * exact "lock the row, re-check inside the lock, act" pattern already
     * proven in this codebase for an analogous problem
     * (LeaseActivationService::activate()). Two tabs opening the same
     * application concurrently serialize on that row lock: whichever
     * request's transaction acquires it first calls snapshotFor(), which
     * sets the timestamp and (if the template had sections) the snapshot
     * rows together in ONE inner transaction (a nested transaction becomes
     * a savepoint — still atomic with this outer one); the second
     * request's lockForUpdate() then blocks until the first commits, sees
     * the timestamp already set, and returns without calling snapshotFor()
     * at all. Because the marker and the rows always commit or roll back
     * together (inside snapshotFor() itself, not here), there is no window
     * where a crash mid-request leaves the timestamp set with no snapshot
     * attempt having actually run.
     *
     * This timestamp is also the answer to "which empty state is this" —
     * see the migration's own docblock. A null timestamp means "never
     * attempted, still eligible for the lazy snapshot below." A non-null
     * timestamp with zero RentalApplicationChecklistSection rows means
     * "attempted, the agency's current template had nothing non-archived
     * to copy" — genuinely different facts that used to collapse into the
     * same zero-rows observation with nothing on the model to tell them
     * apart.
     */
    public static function ensureSnapshotFor(RentalApplication $application): void
    {
        DB::transaction(function () use ($application) {
            /** @var RentalApplication $locked */
            $locked = RentalApplication::whereKey($application->id)->lockForUpdate()->firstOrFail();

            if ($locked->checklist_snapshotted_at !== null) {
                return;
            }

            self::snapshotFor($locked);
        });
    }

    /**
     * AT-430 Part D, Johan's ruling — "Deposit paid, First rent paid and
     * Occupied are DERIVED from the lease and rendered READ-ONLY... a
     * hand-tick that disagrees with the lease is exactly the second source
     * of truth we are avoiding."
     *
     * Investigation finding (flagged in the build report, not silently
     * worked around): today `Lease` (app/Models/Lease.php) carries
     * `deposit_amount` — the AGREED figure — but no fact anywhere in CoreX
     * for whether a deposit or first rent was actually PAID.
     * .ai/specs/leases.md §3.3/§627 explicitly defers "deposit-held
     * tracking / trust-account reconciliation" as a future, unbuilt
     * feature. Treating `deposit_amount is set` as "paid" would invent a
     * false signal — exactly the second-source-of-truth risk this ruling
     * exists to prevent — so lease_deposit_paid and lease_first_rent_paid
     * resolve to NOT_STARTED with an honest explanatory note until that
     * future feature exists to derive from.
     *
     * lease_occupied has a genuine signal already on hand: the lease has
     * a start_date and a status. "Occupied" derives as true once the lease
     * has commenced (start_date has passed) and is not cancelled.
     */
    public static function deriveState(string $derivedKey, ?Lease $lease): array
    {
        return match ($derivedKey) {
            'lease_deposit_paid' => [
                'state' => RentalApplicationChecklistItem::STATE_NOT_STARTED,
                'note' => 'Not yet trackable — CoreX does not record deposit payment against a lease today.',
            ],
            'lease_first_rent_paid' => [
                'state' => RentalApplicationChecklistItem::STATE_NOT_STARTED,
                'note' => 'Not yet trackable — CoreX does not record rent payment against a lease today.',
            ],
            'lease_occupied' => $lease && $lease->status !== Lease::STATUS_CANCELLED
                && $lease->start_date !== null && $lease->start_date->isPast()
                ? ['state' => RentalApplicationChecklistItem::STATE_DONE, 'note' => 'Derived: lease start date ('.$lease->start_date->format('d M Y').') has passed.']
                : ['state' => RentalApplicationChecklistItem::STATE_NOT_STARTED, 'note' => $lease ? 'Derived: lease start date has not yet passed.' : 'Derived: no lease exists yet for this application.'],
            default => ['state' => RentalApplicationChecklistItem::STATE_NOT_STARTED, 'note' => null],
        };
    }

    /** Recomputes every derived item on this application against its current lease, if any. Called on review-screen load — see RentalApplicationReviewController::show(). */
    public static function syncDerivedStates(RentalApplication $application): void
    {
        $items = RentalApplicationChecklistItem::whereHas('section', function ($q) use ($application) {
            $q->where('rental_application_id', $application->id);
        })->where('is_derived', true)->get();

        if ($items->isEmpty()) {
            return;
        }

        $lease = Lease::where('rental_application_id', $application->id)
            ->orderByDesc('id')->first();

        foreach ($items as $item) {
            $derived = self::deriveState($item->derived_key, $lease);
            if ($item->state !== $derived['state'] || $item->note !== $derived['note']) {
                $item->update([
                    'state' => $derived['state'],
                    'note' => $derived['note'],
                    'set_at' => now(),
                ]);
            }
        }
    }

    /**
     * §3.6 gate. cc4's approval controller is the sole caller — this lane
     * builds the check, ownership of the approve action itself stays with
     * cc4 (AT-430 tasking boundary). "not_applicable" items never block.
     */
    public static function isCompleteFor(RentalApplication $application): bool
    {
        $sections = RentalApplicationChecklistSection::where('rental_application_id', $application->id)
            ->with('items')->get();

        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                if ($item->state === RentalApplicationChecklistItem::STATE_NOT_STARTED) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * AT-430, 2026-09-24 — added for cc4's approval-controller block message
     * ("complete these before approving: ..."). Uses the EXACT same
     * "not_started" definition as isCompleteFor() above (same query shape,
     * same exclusion of not_applicable/done items) so the two can never
     * disagree about what counts as outstanding — a block message that
     * named different items than the ones the gate actually blocked on
     * would be worse than no message at all.
     */
    public static function outstandingItemNames(RentalApplication $application): array
    {
        $sections = RentalApplicationChecklistSection::where('rental_application_id', $application->id)
            ->with('items')->orderBy('sort_order')->get();

        $names = [];
        foreach ($sections as $section) {
            foreach ($section->items as $item) {
                if ($item->state === RentalApplicationChecklistItem::STATE_NOT_STARTED) {
                    $names[] = $item->name;
                }
            }
        }

        return $names;
    }
}
