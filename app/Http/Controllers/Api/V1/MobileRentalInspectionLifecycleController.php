<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\RentalInspectionNotRecordableException;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CoreX\RentalInspectionController;
use App\Http\Controllers\CoreX\RentalInspectionRecordingController;
use App\Models\Lease;
use App\Models\Property;
use App\Models\RentalInspection;
use App\Models\RentalInspectionSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Mobile parity with the web inspection lifecycle: schedule, reschedule, cancel, archive, restore, start the next one in the chain, and
 * start the replacement of a sent report. .ai/specs/at-508-mobile-parity-addendum.md, rule source .ai/specs/rental-inspections.md
 * §43 (scheduling), §44/§57 (chain), §45.8 (archive), §47.5 (replace).
 *
 * NO RULE IS DECIDED HERE. Each action validates the same fields the web action validates and calls the SAME model method
 * (RentalInspection::schedule / reschedule / cancel / startNext / startReplacement, SoftDeletes delete / restore). The permission keys on the
 * routes are the web's own (.create / .reschedule / .cancel / .archive / .restore); .archive_completed is checked here, as on the web.
 * Not queueable: these are deliberate office-style actions that need a line, so there is no idempotency key; the model refuses a second
 * identical start/cancel itself.
 */
class MobileRentalInspectionLifecycleController extends Controller
{
    use AuthorizesRentalRecordScope;

    private const TYPES = [RentalInspection::TYPE_IN, RentalInspection::TYPE_OUT, RentalInspection::TYPE_INTERIM, RentalInspection::TYPE_AD_HOC];

    /** POST /mobile/rental-inspections/schedule — book a future inspection on a lease (the web "Schedule" button). */
    public function schedule(Request $request): JsonResponse
    {
        $user = $request->user();
        $v = $request->validate([
            'lease_id' => ['required', 'integer'],
            'type' => ['required', Rule::in(self::TYPES)],
            'scheduled_for' => ['required', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'scheduled_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'inspector_user_id' => ['nullable', 'integer'],
            'schedule_note' => ['nullable', 'string', 'max:1000'],
            'planned_date_id' => ['nullable', 'integer'],
        ]);

        // AgencyScope 404s another agency's lease; the same own/branch ceiling the web picker uses 403s the rest.
        $lease = Lease::query()->find($v['lease_id']);
        abort_if($lease === null, 404);
        abort_unless(Lease::query()->visibleTo($user, null)->whereKey($lease->id)->exists(), 403);
        $property = Property::query()->visibleTo($user)->find($lease->property_id);
        abort_if($property === null, 403);

        if (! empty($v['inspector_user_id'])) {
            $request->validate(['inspector_user_id' => [Rule::exists('users', 'id')->where('agency_id', $property->agency_id)]]);
        }

        try {
            $inspection = RentalInspection::schedule($property, $v['type'], $user, $v, $lease);
        } catch (\LogicException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cannot_schedule'], 409);
        }
        app(RentalInspectionController::class)->linkPlannedDate($request, $inspection);

        return response()->json(['duplicate' => false, 'notice_warning' => $this->noticeWarning($inspection, 'scheduled')]
            + $this->detail($request, $inspection), 201);
    }

    /** POST /mobile/rental-inspections/{inspection}/reschedule — move the date/time/inspector; the old values are kept as history. */
    public function reschedule(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->find($inspection);
        $v = $request->validate([
            'scheduled_for' => ['required', 'date'],
            'scheduled_time' => ['nullable', 'date_format:H:i'],
            'scheduled_duration_minutes' => ['nullable', 'integer', 'min:5', 'max:1440'],
            'inspector_user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('agency_id', $insp->agency_id)],
            'schedule_note' => ['nullable', 'string', 'max:1000'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $insp->reschedule($v, $request->user(), $v['reason'] ?? null);
        } catch (RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_recordable'], 409);
        }

        return response()->json(['notice_warning' => $this->noticeWarning($insp->fresh(), 'rescheduled')] + $this->detail($request, $insp->fresh()));
    }

    /** POST /mobile/rental-inspections/{inspection}/cancel — a reason is required (as on the web); the parties are told by the model. */
    public function cancel(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->find($inspection);
        $v = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']]);

        try {
            $insp->cancel($request->user(), $v['cancel_reason']);
        } catch (RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_recordable'], 409);
        }

        return response()->json($this->detail($request, $insp->fresh()));
    }

    /** DELETE /mobile/rental-inspections/{inspection} — archive (soft delete). A completed or signed one needs .archive_completed, as on the web. */
    public function archive(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->find($inspection);

        if ($insp->isEvidenceRecord() && ! $request->user()->hasPermission('rental_inspections.archive_completed')) {
            return response()->json([
                'message' => 'A completed or signed inspection is a record — only a manager can archive it.',
                'code' => 'needs_manager',
            ], 403);
        }

        $insp->forceFill(['archived_by_user_id' => $request->user()->id])->save();
        $insp->delete();

        return response()->json(['ok' => true, 'id' => $insp->id, 'archived' => true]);
    }

    /** POST /mobile/rental-inspections/{inspection}/restore — bring an archived inspection back (refused if something started after it). */
    public function restore(Request $request, int $inspection): JsonResponse
    {
        $insp = RentalInspection::withTrashed()->find($inspection);
        abort_if($insp === null, 404);
        $this->guardRentalRecordScope($insp, 'rental_inspections', $insp->property?->branch_id);

        try {
            $insp->restore();
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cannot_restore'], 409);
        }
        $insp->forceFill(['archived_by_user_id' => null])->save();

        return response()->json($this->detail($request, $insp->fresh()));
    }

    /** POST /mobile/rental-inspections/{inspection}/next — start the next inspection in the chain (same checks and "start anyway" ask as the web). */
    public function next(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->find($inspection);

        return app(RentalInspectionRecordingController::class)->next($request, $insp->property, $insp);
    }

    /** POST /mobile/rental-inspections/{inspection}/replace — "Start new inspection" for a report that was already sent (it can never be edited). */
    public function replace(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->find($inspection);

        try {
            $new = RentalInspection::startReplacement($insp, $request->user());
        } catch (\LogicException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'cannot_replace'], 409);
        }

        return response()->json($this->detail($request, $new->fresh()), 201);
    }

    /** POST /mobile/rental-inspections/{inspection}/public-link — mint (or return) the read-only report link. */
    public function generatePublicLink(Request $request, int $inspection): JsonResponse
    {
        $insp = $this->find($inspection);

        // as the web: no live link for a cancelled inspection (an archived one never gets here — find() answers 410)
        if ($insp->status === RentalInspection::STATUS_CANCELLED) {
            return response()->json(['message' => 'A public link cannot be created for a cancelled inspection.', 'code' => 'cancelled'], 409);
        }

        $insp->generatePublicLink();

        return response()->json(['url' => $insp->fresh()->publicShareUrl()], 201);
    }

    /** DELETE /mobile/rental-inspections/{inspection}/public-link — the link stops working at once. */
    public function revokePublicLink(Request $request, int $inspection): JsonResponse
    {
        $this->find($inspection)->revokePublicLink();

        return response()->json(['ok' => true]);
    }

    private function find(int $id): RentalInspection
    {
        $inspection = RentalInspection::withTrashed()->find($id);
        abort_if($inspection === null, 404);
        $this->guardRentalRecordScope($inspection, 'rental_inspections', $inspection->property?->branch_id);
        if ($inspection->trashed()) {
            abort(response()->json(['message' => 'This inspection was archived. Restore it first.', 'code' => 'archived'], 410));
        }

        return $inspection;
    }

    private function detail(Request $request, RentalInspection $inspection): array
    {
        return (array) app(MobileRentalInspectionController::class)->show($request, $inspection->id)->getData(true);
    }

    /** The web shows this as a yellow note and goes ahead anyway; the app shows it the same way. */
    private function noticeWarning(RentalInspection $inspection, string $verb): ?string
    {
        $days = RentalInspectionSetting::minimumNoticeDaysFor((int) $inspection->agency_id);
        if ($inspection->scheduledForDateTime()?->lt(now()->addDays($days))) {
            return "This is less than the agency's usual {$days}-day notice — {$verb} anyway, parties are still notified.";
        }

        return null;
    }
}
