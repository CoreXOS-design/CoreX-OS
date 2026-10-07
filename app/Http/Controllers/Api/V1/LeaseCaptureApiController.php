<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Rentals\LeaseCaptureIncompleteException;
use App\Exceptions\Rentals\NoLeaseAgreementLinkedException;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\CoreX\LeaseCaptureRequest;
use App\Models\Docuperfect\Flow;
use App\Services\Rentals\LeaseCaptureService;
use App\Services\Rentals\LeaseSigningLauncher;
use App\Models\Lease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/leases.md §15.15 (Build L2) — the JSON mirror of the single capture screen, for Andre's mobile
 * app (rule #7). Same request class, same service: `intent` is lease_only | lease_and_sign | paper_copy,
 * `previous_lease_id` makes it a renewal, `capture_key` makes a double-submit return the same lease.
 *
 *   201 {lease, signing_status, redirect_url}
 *   422 {message, errors}                            — a field problem
 *   422 {message, missing:[{key,label,fix_url}]}     — "lease_and_sign" with required agreement details blank
 *   409 {code:"no_lease_agreement_linked", message}  — "lease_and_sign" with no usable lease agreement (R3)
 *
 * "lease_and_sign" (Build L3a) creates the lease and opens the agency's own lease agreement as a prepared e-sign
 * flow — `signing_status` is `prepared`, and `redirect_url` is Fill & review of that agreement, where the agent
 * checks it and signs first. Nothing is sent to anyone by this call.
 */
class LeaseCaptureApiController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function store(LeaseCaptureRequest $request): JsonResponse
    {
        $input = $request->validated();
        $input['signed_document'] = $request->file('signed_document');

        try {
            $lease = app(LeaseCaptureService::class)->capture($input, (string) $input['intent'], $request->user(), $request->previousLease());
        } catch (LeaseCaptureIncompleteException $e) {
            return response()->json([
                'message' => 'Some agreement details are needed for signing.',
                'missing' => array_map(fn (array $m) => $m + ['fix_url' => null], $e->missing),
            ], 422);
        } catch (NoLeaseAgreementLinkedException $e) {
            return response()->json(['code' => NoLeaseAgreementLinkedException::CODE, 'message' => $e->getMessage()], 409);
        } catch (ValidationException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        }

        $flow = $input['intent'] === LeaseCaptureService::INTENT_LEASE_AND_SIGN && $lease->signing_flow_id
            ? Flow::find($lease->signing_flow_id)
            : null;

        return response()->json([
            'lease' => $lease,
            'signing_status' => $lease->signing_status,
            'redirect_url' => $flow ? app(LeaseSigningLauncher::class)->landingUrl($flow) : route('corex.leases.show', $lease),
        ], 201);
    }

    /**
     * leases.md §15.15 (Build L3a) — where a lease's agreement stands: the status, the signers in signing order
     * with each one's progress, what is still missing before it can be prepared, and where the agent continues.
     * Same own/branch/agency scope guard as the lease screen (another agency's lease is a 404).
     */
    public function signing(Request $request, Lease $lease): JsonResponse
    {
        $this->guardRentalRecordScope($lease, 'leases', $lease->branch_id);

        $launcher = app(LeaseSigningLauncher::class);
        $user = $request->user();

        $missing = [];
        if (in_array($lease->signing_status, [Lease::SIGNING_NOT_SENT, Lease::SIGNING_DECLINED, Lease::SIGNING_VOIDED, Lease::SIGNING_EXPIRED], true)
            && $lease->status === Lease::STATUS_DRAFT) {
            $agreement = app(LeaseCaptureService::class)->agreementStateFor((int) $lease->agency_id)['agreements']->first();
            if ($agreement) {
                $missing = $launcher->missing($lease, $agreement, [], $user);
            }
        }

        $flow = $lease->signing_status === Lease::SIGNING_PREPARED && $lease->signing_flow_id ? Flow::find($lease->signing_flow_id) : null;

        return response()->json([
            'lease_id' => $lease->id,
            'signing_status' => $lease->signing_status,
            'signing_status_label' => $lease->signingStatusLabel(),
            'signers' => $launcher->signersSummary($lease),
            'missing' => $missing,
            'continue_url' => $flow && (int) $flow->user_id === (int) $user->id ? $launcher->landingUrl($flow) : null,
        ]);
    }
}
