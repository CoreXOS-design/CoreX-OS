<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Rentals\LeaseCaptureIncompleteException;
use App\Exceptions\Rentals\NoLeaseAgreementLinkedException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CoreX\LeaseCaptureRequest;
use App\Services\Rentals\LeaseCaptureService;
use Illuminate\Http\JsonResponse;
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
 * In Build L2 "lease_and_sign" creates the lease and checks the agreement details but does not open a signing
 * document (that is Build L3a) — `signing_status` stays `not_sent` until it does.
 */
class LeaseCaptureApiController extends Controller
{
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

        return response()->json([
            'lease' => $lease,
            'signing_status' => $lease->signing_status,
            'redirect_url' => route('corex.leases.show', $lease),
        ], 201);
    }
}
