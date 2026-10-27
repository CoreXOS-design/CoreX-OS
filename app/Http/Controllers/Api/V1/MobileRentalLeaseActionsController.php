<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\MobileRentalLeaseController;
use App\Http\Controllers\Api\V1\Concerns\RunsWebAction;
use App\Http\Controllers\Controller;
use App\Http\Controllers\CoreX\LeaseBreachController;
use App\Http\Controllers\CoreX\LeaseController;
use App\Http\Controllers\CoreX\LeaseNoticeController;
use App\Http\Controllers\CoreX\LeaseRenewalController;
use App\Http\Controllers\CoreX\RentalNoticeController;
use App\Models\Lease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Mobile parity, leases and notices: the lease actions the web lease screen has and the phone did not. .ai/specs/at-508-mobile-parity-addendum.md §3.
 *
 * Every method runs the WEB controller's own action (see RunsWebAction): same validation, same permission and scope checks, same model
 * calls, same refusal wording. Nothing is decided here. The route carries the web's own permission key. A successful answer carries the
 * lease as the phone already knows it (GET /mobile/leases/{lease}) so the screen can redraw without a second call.
 */
class MobileRentalLeaseActionsController extends Controller
{
    use RunsWebAction;

    public function update(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'update');
    }

    public function agents(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'updateAgents');
    }

    public function noticeTerms(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'updateNoticeTerms');
    }

    public function confirmNoticeTerms(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'confirmNoticeTerms');
    }

    public function sendExpiryNotice(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'sendExpiryNotice');
    }

    public function markExpiryNoticeGiven(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'markExpiryNoticeGiven');
    }

    public function activate(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'activate');
    }

    public function cancel(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'cancel');
    }

    public function escalate(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'escalate');
    }

    public function archive(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'archive');
    }

    /** The lease is archived, so it is looked up with trashed (the route is ->withTrashed()). */
    public function restore(Request $request, int $lease): JsonResponse
    {
        $response = $this->runWebAction($request, LeaseController::class, 'restore', ['lease' => $lease]);

        return $response->getStatusCode() < 300 ? $this->withLease($request, Lease::query()->find($lease), $response) : $response;
    }

    public function runDateChecks(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'runDateChecks');
    }

    /** "Prepare for signing" / "prepare again" / "attach the signed copy": the agreement steps of the lease screen. */
    public function prepareForSigning(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'prepareForSigning');
    }

    public function prepareAgain(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'prepareAgain');
    }

    public function signedCopy(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseController::class, 'attachSignedCopy');
    }

    public function cancelRenewalDraft(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseRenewalController::class, 'cancelDraft');
    }

    /** A breach notice on this lease (the web "Breach" form). */
    public function storeBreach(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseBreachController::class, 'store');
    }

    /** The agent records a notice the tenant / landlord gave (the web "Tenant gave notice" / "Landlord gave notice" dialogs). */
    public function storeNoticeCase(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, LeaseNoticeController::class, 'store');
    }

    /** Send a notice to the tenant / landlord from a template (the web "Send notice" form). */
    public function storeNotice(Request $request, Lease $lease): JsonResponse
    {
        return $this->onLease($request, $lease, RentalNoticeController::class, 'store');
    }

    /** Accept a notice that was given: the web review screen's Accept (with a correcting date and a note). */
    public function acceptNotice(Request $request, int $notice): JsonResponse
    {
        return $this->runWebAction($request, LeaseNoticeController::class, 'accept', ['id' => $notice]);
    }

    private function onLease(Request $request, Lease $lease, string $controller, string $method): JsonResponse
    {
        $response = $this->runWebAction($request, $controller, $method, ['lease' => $lease]);

        return $response->getStatusCode() < 300 ? $this->withLease($request, $lease->fresh(), $response) : $response;
    }

    /** Add the lease, as the phone's lease file shows it, to a successful answer. */
    private function withLease(Request $request, ?Lease $lease, JsonResponse $response): JsonResponse
    {
        if ($lease === null || $lease->trashed()) {
            return $response;
        }
        try {
            $detail = app(MobileRentalLeaseController::class)->show($request, $lease)->getData(true);
        } catch (\Throwable) {
            return $response;
        }

        return response()->json((array) $response->getData(true) + ['lease' => $detail['lease'] ?? $detail], $response->getStatusCode());
    }
}
