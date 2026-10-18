<?php

namespace App\Http\Controllers\CoreX;

use App\Exceptions\RentalInspectionNotRecordableException;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Models\RentalInspection;
use App\Models\RentalInspectionAttendance;
use App\Services\Rentals\RentalInspectionAttendanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-inspections.md §45.5 (Build I-3) — recording who attended an inspection and the
 * invitations that were given off the system. A thin call into RentalInspectionAttendanceService (every
 * rule lives there, shared with any future mobile endpoint). Child of a bound {rentalInspection}: the
 * route binding already applies own/branch/agency, and every action re-checks it with
 * guardRentalRecordScope() — an attendance row is never reachable by a bare id.
 *
 * Permissions: rental_inspections.record_attendance for every write (route middleware); CORRECTING or
 * withdrawing a record someone ELSE made additionally needs rental_inspections.resolve_discrepancy.
 */
class RentalInspectionAttendanceController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __construct(private readonly RentalInspectionAttendanceService $attendance)
    {
    }

    /** GET /corex/rental-inspections/{rentalInspection}/attendance — the board (parties, outcomes, invitation lines). */
    public function show(RentalInspection $rentalInspection): JsonResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        return response()->json($this->payload($rentalInspection));
    }

    /** POST …/attendance — record, or correct, one party's (or one extra attendee's) attendance. */
    public function store(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'party_role' => ['required', 'string', 'in:tenant,landlord,agent,other'],
            'party_contact_id' => ['nullable', 'integer'],
            'party_user_id' => ['nullable', 'integer'],
            'outcome' => ['required', 'string', 'in:attended,did_not_attend'],
            'attended_as' => ['nullable', 'string', 'in:' . implode(',', RentalInspectionAttendance::attendedAsKeys())],
            'attendee_name' => ['nullable', 'string', 'max:191'],
            'arrived_at' => ['nullable', 'string', 'max:8'],
            'note' => ['nullable', 'string', 'max:1000'],
            'client_idempotency_key' => ['nullable', 'string', 'max:64'],
        ]);

        // Correcting someone else's record needs the resolve power; writing a first record does not.
        $existing = $this->attendance->liveRecordFor(
            $rentalInspection, $validated['party_role'], $validated['party_contact_id'] ?? null, $validated['party_user_id'] ?? null,
        );
        if ($existing && (int) $existing->recorded_by_user_id !== (int) $request->user()->id
            && ! $request->user()->hasPermission('rental_inspections.resolve_discrepancy')) {
            return response()->json(['message' => 'This was recorded by someone else. Only a manager can correct it.'], 403);
        }

        try {
            $row = $this->attendance->record($rentalInspection, $validated, $request->user());
        } catch (RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->payload($rentalInspection) + ['recorded_id' => $row->id], 201);
    }

    /** POST …/attendance/{attendance}/withdraw — withdraw a record without replacing it (kept on file, marked withdrawn). */
    public function withdraw(Request $request, RentalInspection $rentalInspection, RentalInspectionAttendance $attendance): JsonResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);
        abort_unless((int) $attendance->rental_inspection_id === (int) $rentalInspection->id, 404);

        if ((int) $attendance->recorded_by_user_id !== (int) $request->user()->id
            && ! $request->user()->hasPermission('rental_inspections.resolve_discrepancy')) {
            return response()->json(['message' => 'This was recorded by someone else. Only a manager can withdraw it.'], 403);
        }

        try {
            $this->attendance->withdraw($attendance);
        } catch (RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($this->payload($rentalInspection));
    }

    /** POST …/attendance/invitations — an invitation given OFF the system, recorded with the real time it was given. */
    public function storeInvitation(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->guardRentalRecordScope($rentalInspection, 'rental_inspections', $rentalInspection->property?->branch_id);

        $validated = $request->validate([
            'party_role' => ['required', 'string', 'in:tenant,landlord,agent'],
            'party_contact_id' => ['nullable', 'integer'],
            'party_user_id' => ['nullable', 'integer'],
            'method' => ['required', 'string', 'max:200'],
            'occurred_at' => ['nullable', 'string', 'max:40'],
        ]);

        try {
            $this->attendance->recordInvitation($rentalInspection, $validated, $request->user());
        } catch (RentalInspectionNotRecordableException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->payload($rentalInspection), 201);
    }

    /** @return array<string, mixed> */
    private function payload(RentalInspection $inspection): array
    {
        return ['attendance_board' => $this->attendance->board($inspection)];
    }
}
