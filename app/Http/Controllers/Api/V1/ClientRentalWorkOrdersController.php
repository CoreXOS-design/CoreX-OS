<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ResolvesPortalContact;
use App\Http\Controllers\Controller;
use App\Models\RentalWorkCompletionRound;
use App\Services\Rentals\RentalCompletionService;
use App\Services\Rentals\RentalPortalScopeService;
use App\Services\Rentals\RentalWorkOrderClientViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-work-orders.md §17.3.5 / §17.10.4 — the portal's work-order endpoints under /api/v1/client/rentals/*:
 * the tenant's work-order list, the tenant's answer to "is this finished?" (confirm, or "not complete" with a note and
 * photos), and the landlord's single work order. Every record is fetched through RentalPortalScopeService — never a
 * bare Model::find(): a work order or round that belongs to another tenant, lease, property or agency is a 404.
 * What each audience sees is decided by RentalWorkOrderClientViewService (tenant: never a price; landlord: the
 * owner-facing amount only).
 *
 * The same answer rules as the response link and the office's "Record tenant's answer" — all three call
 * RentalCompletionService::respond().
 */
class ClientRentalWorkOrdersController extends Controller
{
    use ResolvesPortalContact;

    public function __construct(
        private readonly RentalPortalScopeService $scope,
        private readonly RentalWorkOrderClientViewService $view,
        private readonly RentalCompletionService $completion,
    ) {
    }

    /** GET /api/v1/client/rentals/work-orders — the tenant's work orders, newest first. */
    public function tenantIndex(Request $request): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        return response()->json([
            'work_orders' => $this->scope->tenantWorkOrders($contact)
                ->map(fn ($w) => $this->view->payload($w, RentalWorkOrderClientViewService::AUDIENCE_TENANT))->values(),
        ]);
    }

    /**
     * POST /api/v1/client/rentals/work-orders/{workOrder}/completion-response — body `fixed` (boolean), `note`,
     * `photos[]`. A "not complete" answer needs a note of at least five characters and takes up to ten photos.
     */
    public function completionResponse(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->tenantWorkOrder($contact, $workOrder);
        $round = $order ? $this->scope->tenantCompletionRound($contact, $workOrder) : null;
        if (! $order || ! $round) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        $data = $request->validate([
            'fixed' => ['required', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'photos' => ['nullable', 'array', 'max:' . RentalCompletionService::MAX_DISPUTE_PHOTOS],
            'photos.*' => ['file', 'image', 'max:15360'],
        ]);

        return $this->answer($order, $round, (bool) $data['fixed'], $data['note'] ?? null, $request->file('photos', []), $contact, $request);
    }

    /** GET /api/v1/client/rentals/landlord/work-orders/{workOrder} — one work order on the landlord's own property. */
    public function landlordShow(Request $request, int $workOrder): JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->landlordWorkOrder($contact, $workOrder);
        if (! $order) {
            return response()->json(['message' => 'Work order not found.'], 404);
        }

        return response()->json(['work_order' => $this->view->payload($order, RentalWorkOrderClientViewService::AUDIENCE_LANDLORD)]);
    }

    /**
     * GET /api/v1/client/rentals/landlord/work-orders/{workOrder}/invoices/{invoice}/file — one supplier invoice, but ONLY when
     * the work order is on the caller's own property (the owner scope — a tenant's contact resolves to nothing) AND the agent has
     * ticked "share with owner" AND it is not archived. Anything else is the same 404 as a record that does not exist.
     * Every view / download leaves a portal audit row, like the portal's rental documents (§17.31).
     */
    public function landlordInvoiceFile(Request $request, int $workOrder, int $invoice): \Symfony\Component\HttpFoundation\Response|JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->landlordWorkOrder($contact, $workOrder);
        $service = app(\App\Services\Rentals\RentalWorkOrderInvoiceService::class);
        $row = $order ? $service->sharedInvoice($order, $invoice) : null;
        $response = $row ? $service->response($row, $request->boolean('download')) : null;
        if (! $response) {
            return response()->json(['message' => 'Invoice not found.'], 404);
        }

        app(\App\Services\ClientAuthService::class)->log($request->user(), (int) $contact->agency_id, $contact->id,
            $request->boolean('download') ? 'document_downloaded' : 'document_viewed', $request, [
                'document_id' => $row->id, 'kind' => 'work_order_invoice', 'role' => 'landlord', 'rental_work_order_id' => $order->id,
            ]);

        return $response;
    }

    /** P2 - the selected quote's document for the owner who is asked to approve it (owner scope; refused when a fee makes the document's total differ from what the owner pays). */
    public function landlordQuoteFile(Request $request, int $workOrder): \Symfony\Component\HttpFoundation\Response|JsonResponse
    {
        $contact = $this->resolvePortalContact($request);
        if ($contact instanceof JsonResponse) {
            return $contact;
        }

        $order = $this->scope->landlordWorkOrder($contact, $workOrder);
        $quote = $order ? app(\App\Services\Rentals\RentalWorkOrderClientViewService::class)->ownerVisibleQuote($order) : null;
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        if (! $quote || ! $quote->document_storage_path || ! $quote->ownerMayOpenDocument() || ! $disk->exists($quote->document_storage_path)) {
            return response()->json(['message' => 'Quote document not found.'], 404);
        }

        app(\App\Services\ClientAuthService::class)->log($request->user(), (int) $contact->agency_id, $contact->id, 'document_viewed', $request, [
            'document_id' => $quote->id, 'kind' => 'work_order_quote', 'role' => 'landlord', 'rental_work_order_id' => $order->id,
        ]);

        $mime = $disk->mimeType($quote->document_storage_path) ?: 'application/octet-stream';
        $inline = in_array(strtolower($mime), ['application/pdf', 'image/jpeg', 'image/png', 'image/webp'], true);
        $headers = ['Content-Type' => $mime, 'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0'];
        $ext = pathinfo($quote->document_storage_path, PATHINFO_EXTENSION);
        $name = 'Quote' . ($ext ? ".{$ext}" : '');

        return $inline ? $disk->response($quote->document_storage_path, $name, $headers, 'inline') : $disk->download($quote->document_storage_path, $name, $headers);
    }

    /**
     * Shared by the new endpoint and the old `…/confirm` alias (ClientTenantRentalsController): one place that turns
     * the service's refusals into plain 422 messages.
     *
     * @param array<int, mixed> $photos
     */
    public function answer($order, RentalWorkCompletionRound $round, bool $fixed, ?string $note, array $photos, $contact, Request $request): JsonResponse
    {
        try {
            $this->completion->respond($round, $fixed, $note, $photos, [
                'contact' => $contact,
                'via' => RentalWorkCompletionRound::RESPONDED_PORTAL,
                'ip' => $request->ip(),
            ]);
        } catch (\InvalidArgumentException|\LogicException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['work_order' => $this->view->payload($order->fresh(), RentalWorkOrderClientViewService::AUDIENCE_TENANT)]);
    }
}
