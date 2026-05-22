<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationDelivery;
use App\Services\Presentations\PresentationDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Phase 6 — agent-side send + deliveries-list controller.
 *
 * Three actions:
 *   POST /presentations/{p}/deliveries/preview  → JSON DeliveryBatch preview
 *   POST /presentations/{p}/deliveries/send     → JSON send-batch results
 *   GET  /presentations/{p}/deliveries          → HTML index page
 *   GET  /corex/deliveries/{delivery}/whatsapp-redirect → 302 to wa.me URL + record click-through
 */
final class PresentationDeliveryController extends Controller
{
    public function __construct(private readonly PresentationDeliveryService $svc = new PresentationDeliveryService()) {}

    /** POST /presentations/{presentation}/deliveries/preview */
    public function preview(Request $request, Presentation $presentation): JsonResponse
    {
        $this->guardAgency($request, $presentation);

        $data = $this->validatePayload($request);

        $batch = $this->svc->prepareDeliveryBatch($presentation, $data['recipients'], [
            'default_mode'       => $data['default_mode']    ?? 'full',
            'default_channel'    => $data['default_channel'] ?? 'email',
            'subject'            => $data['subject']         ?? null,
            'body'               => $data['body']            ?? null,
            'expires_at'         => $data['expires_at']      ?? null,
            'created_by_user_id' => (int) $request->user()->id,
        ]);

        return response()->json([
            'recipients' => $batch->recipients,
            'valid'      => $batch->isValid(),
            'errors'     => $batch->validationErrors(),
        ]);
    }

    /** POST /presentations/{presentation}/deliveries/send */
    public function send(Request $request, Presentation $presentation): JsonResponse
    {
        $this->guardAgency($request, $presentation);

        $data = $this->validatePayload($request);

        $batch = $this->svc->prepareDeliveryBatch($presentation, $data['recipients'], [
            'default_mode'       => $data['default_mode']    ?? 'full',
            'default_channel'    => $data['default_channel'] ?? 'email',
            'subject'            => $data['subject']         ?? null,
            'body'               => $data['body']            ?? null,
            'expires_at'         => $data['expires_at']      ?? null,
            'created_by_user_id' => (int) $request->user()->id,
        ]);

        if (!$batch->isValid()) {
            return response()->json([
                'ok'     => false,
                'errors' => $batch->validationErrors(),
            ], 422);
        }

        $results = $this->svc->sendBatch($batch, $request->user());
        return response()->json([
            'ok'      => true,
            'results' => $results,
            'summary' => $this->summarise($results),
        ]);
    }

    /** GET /presentations/{presentation}/deliveries */
    public function index(Request $request, Presentation $presentation): Response
    {
        $this->guardAgency($request, $presentation);

        $deliveries = PresentationDelivery::where('presentation_id', $presentation->id)
            ->with(['link:id,token,expires_at,revoked_at,view_count,first_viewed_at', 'contact:id,first_name,last_name', 'sender:id,name'])
            ->orderByDesc('id')
            ->paginate(50);

        return response()->view('presentations.deliveries.index', [
            'presentation' => $presentation,
            'deliveries'   => $deliveries,
        ]);
    }

    /**
     * GET /corex/deliveries/{delivery}/whatsapp-redirect
     *
     * Records the agent's click-through, then 302s to the actual wa.me URL.
     * Agency-scoped — only the sender (or another agent of the same agency)
     * can hit this. Public token-bearing recipients never use this.
     */
    public function whatsappRedirect(Request $request, PresentationDelivery $delivery): RedirectResponse
    {
        $this->guardAgency($request, $delivery->presentation);

        if (!$delivery->whatsapp_url) {
            abort(404, 'No WhatsApp URL for this delivery.');
        }
        $this->svc->recordWhatsappClickThrough($delivery);
        return redirect()->away($delivery->whatsapp_url);
    }

    // ── Internals ───────────────────────────────────────────────────────────

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'recipients'                 => 'required|array|min:1',
            'recipients.*.contact_id'    => 'nullable|integer|exists:contacts,id',
            'recipients.*.name'          => 'required|string|min:2|max:200',
            'recipients.*.first_name'    => 'sometimes|string|max:100',
            'recipients.*.email'         => 'nullable|email|max:200',
            'recipients.*.phone'         => 'nullable|string|max:30',
            'recipients.*.channel'       => 'sometimes|in:email,whatsapp,copy,sms',
            'recipients.*.mode'          => 'sometimes|in:full,teaser',
            'default_mode'               => 'sometimes|in:full,teaser',
            'default_channel'            => 'sometimes|in:email,whatsapp,copy,sms',
            'subject'                    => 'sometimes|nullable|string|max:300',
            'body'                       => 'sometimes|nullable|string|max:8000',
            'expires_at'                 => 'sometimes|nullable|date|after:today',
        ]);
    }

    private function guardAgency(Request $request, Presentation $presentation): void
    {
        $effective = $request->user()?->effectiveAgencyId();
        if (!$effective || (int) $presentation->agency_id !== (int) $effective) {
            abort(403, 'Cross-agency access denied.');
        }
    }

    /** @param array<int, array<string, mixed>> $results */
    private function summarise(array $results): array
    {
        $byStatus = [];
        $whatsappLinks = 0;
        foreach ($results as $r) {
            $byStatus[$r['status']] = ($byStatus[$r['status']] ?? 0) + 1;
            if (!empty($r['whatsapp_url'])) $whatsappLinks++;
        }
        return [
            'total'          => count($results),
            'by_status'      => $byStatus,
            'whatsapp_links' => $whatsappLinks,
        ];
    }
}
