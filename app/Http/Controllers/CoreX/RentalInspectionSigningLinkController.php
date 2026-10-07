<?php

namespace App\Http\Controllers\CoreX;

use App\Exceptions\RentalInspectionSigningLinkException as LinkException;
use App\Http\Controllers\Concerns\AuthorizesRentalRecordScope;
use App\Http\Controllers\Controller;
use App\Http\Controllers\RentalInspectionPublicController;
use App\Http\Controllers\RentalInspectionSigningController;
use App\Models\RentalInspection;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInspectionSigningLink;
use App\Services\Rentals\RentalInspectionSigningLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * .ai/specs/rental-inspections.md §46 — the agent's side of signing by link: see each party's link and its status,
 * send it (email, WhatsApp, copy, QR), revoke it, and let a party who is standing next to the agent sign on the
 * agent's own logged-in device.
 *
 * Every action is scoped exactly like the rest of the inspection (own / branch / agency via guardRentalRecordScope),
 * and a link must belong to the inspection in the URL. Managing links needs `rental_inspections.public_link` (it is the
 * same act as sharing the public report link); signing on the agent's device needs `rental_inspections.create` (the
 * same as recording a signature on the recording screen).
 */
class RentalInspectionSigningLinkController extends Controller
{
    use AuthorizesRentalRecordScope;

    public function __construct(private RentalInspectionSigningLinkService $links) {}

    /** GET /corex/rental-inspections/{inspection}/signing-links */
    public function index(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->guard($rentalInspection);

        return response()->json($this->links->panel($rentalInspection));
    }

    /**
     * POST …/signing-links — make sure this party has a live link and, when the agent says how they are sharing it
     * (WhatsApp, copy, QR, device), record that. Returns the link's URL and, for WhatsApp, the ready-made wa.me address.
     */
    public function issue(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->guard($rentalInspection);

        $v = $request->validate([
            'party_role' => ['required', 'string', 'in:tenant,landlord,agent'],
            'party_contact_id' => ['nullable', 'integer'],
            'channel' => ['nullable', 'string', 'in:whatsapp,copied,qr,device'],
        ]);

        try {
            $link = $this->links->issue($rentalInspection, $v['party_role'], $v['party_contact_id'] ?? null, $request->user());
            if (! empty($v['channel'])) {
                $this->links->recordShared($link, $v['channel'], null, $request->user());
            }
        } catch (LinkException $e) {
            return $this->refusal($e);
        }

        $party = $this->links->parties($rentalInspection)->first(
            fn (array $p) => $p['role'] === $link->party_role && ($link->party_role === 'agent' || (int) $p['contact_id'] === (int) $link->party_contact_id)
        );

        return response()->json([
            'url' => $link->url(),
            'link_id' => $link->id,
            'whatsapp_url' => $this->whatsappUrl($rentalInspection, $link, $party),
            'device_url' => route('corex.rental-inspections.signing-links.device', [$rentalInspection, $link]),
            'panel' => $this->links->panel($rentalInspection),
        ], 201);
    }

    /** POST …/signing-links/send-all — issue and email every tenant and the landlord who still has to sign. */
    public function sendAll(Request $request, RentalInspection $rentalInspection): JsonResponse
    {
        $this->guard($rentalInspection);

        $results = [];
        foreach ($this->links->parties($rentalInspection) as $party) {
            if ($party['role'] === RentalInspectionSigningLink::ROLE_AGENT) {
                continue;
            }
            try {
                $link = $this->links->issue($rentalInspection, $party['role'], $party['contact_id'], $request->user());
                $sent = $this->links->sendEmail($link, $request->user());
                $results[] = ['name' => $party['name'], 'role' => $party['role'], 'status' => $sent['status'], 'error' => $sent['error']];
            } catch (LinkException $e) {
                $results[] = ['name' => $party['name'], 'role' => $party['role'], 'status' => $e->reason === LinkException::ALREADY_RECORDED ? 'already_recorded' : 'failed', 'error' => $e->getMessage()];
            }
        }

        return response()->json(['results' => $results, 'panel' => $this->links->panel($rentalInspection)]);
    }

    /** POST …/signing-links/{link}/email — email (or re-email) that party's link. */
    public function email(Request $request, RentalInspection $rentalInspection, RentalInspectionSigningLink $link): JsonResponse
    {
        $this->guardLink($rentalInspection, $link);

        try {
            $result = $this->links->sendEmail($link, $request->user());
        } catch (LinkException $e) {
            return $this->refusal($e);
        }

        return response()->json(['result' => $result, 'panel' => $this->links->panel($rentalInspection)]);
    }

    /** POST …/signing-links/{link}/revoke — the link stops working at once. */
    public function revoke(Request $request, RentalInspection $rentalInspection, RentalInspectionSigningLink $link): JsonResponse
    {
        $this->guardLink($rentalInspection, $link);
        $this->links->revoke($link, $request->user());

        return response()->json(['panel' => $this->links->panel($rentalInspection)]);
    }

    /** GET …/signing-links/{link}/qr — the link as a QR code for the agent's phone screen (counts as shared). */
    public function qr(Request $request, RentalInspection $rentalInspection, RentalInspectionSigningLink $link): JsonResponse
    {
        $this->guardLink($rentalInspection, $link);
        if (! $link->isLive()) {
            return response()->json(['message' => 'This link is no longer live — issue a new one first.'], 409);
        }
        $this->links->recordShared($link, RentalInspectionSigningLink::CHANNEL_QR, null, $request->user());

        return response()->json(['url' => $link->url(), 'data_uri' => $this->links->qrDataUri($link)]);
    }

    /**
     * GET …/signing-links/{link}/device — "sign on this device": the agent hands over their own logged-in phone, the
     * party reads the report and signs. Same page as the party's own link, but the submit goes through the agent's
     * session so the record says it was signed on the agent's device, by which agent.
     */
    public function device(Request $request, RentalInspection $rentalInspection, RentalInspectionSigningLink $link, RentalInspectionSigningController $signing, RentalInspectionPublicController $report): \Symfony\Component\HttpFoundation\Response
    {
        $this->guardLink($rentalInspection, $link, 'create');
        $link->setRelation('inspection', $rentalInspection);

        if (! $link->isLive()) {
            return $report->privateHeaders(response()->view('rental-inspections.public.unavailable'));
        }

        $data = $report->reportData(
            $rentalInspection,
            fn (RentalInspectionSignature $signature, string $kind) => route('rental-inspections.sign.signature-file', ['token' => $link->token, 'signature' => $signature->id, 'kind' => $kind]),
        );
        $data['signing'] = $signing->signingContext($link, 'device', route('corex.rental-inspections.signing-links.device-submit', [$rentalInspection, $link]));
        $data['signing']['device_agent_name'] = $request->user()->name;
        $data['signing']['back_url'] = route('corex.properties.show', ['property' => $rentalInspection->property_id, 'tab' => 'inspections']);

        return $report->privateHeaders(response()->view('rental-inspections.public.show', $data));
    }

    /** POST …/signing-links/{link}/device — record a signature (or refusal) made on the agent's device. */
    public function deviceSubmit(Request $request, RentalInspection $rentalInspection, RentalInspectionSigningLink $link): JsonResponse
    {
        $this->guardLink($rentalInspection, $link, 'create');

        $input = RentalInspectionSigningController::validatedInputFrom($request);

        try {
            $this->links->submit($link, $input, [
                'via' => RentalInspectionSignature::SIGNED_VIA_AGENT_DEVICE,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'device_user_id' => $request->user()->id,
            ]);
        } catch (LinkException $e) {
            return $this->refusal($e);
        }

        return response()->json(['ok' => true, 'outcome' => $input['action'] === 'sign' ? 'signed' : 'declined'], 201);
    }

    private function guard(RentalInspection $inspection): void
    {
        $this->guardRentalRecordScope($inspection, 'rental_inspections', $inspection->property?->branch_id);
    }

    /** @param 'public_link'|'create' $permission the key this action needs besides the route group's .view */
    private function guardLink(RentalInspection $inspection, RentalInspectionSigningLink $link, string $permission = 'public_link'): void
    {
        $this->guard($inspection);
        abort_unless((int) $link->rental_inspection_id === (int) $inspection->id, 404);
        abort_unless(auth()->user()?->hasPermission('rental_inspections.' . $permission), 403);
    }

    private function refusal(LinkException $e): JsonResponse
    {
        return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->reason === LinkException::INVALID ? 422 : 409);
    }

    private function whatsappUrl(RentalInspection $inspection, RentalInspectionSigningLink $link, ?array $party): string
    {
        $first = trim(explode(' ', (string) ($party['name'] ?? ''))[0] ?? '');
        $text = ($first !== '' ? "Hi {$first}, " : 'Hi, ')
            . 'here is the ' . strtolower(RentalInspection::typeName($inspection->type)) . ' report for '
            . ($inspection->property?->buildDisplayAddress() ?? 'the property')
            . '. You can read it and sign it here: ' . $link->url();

        return 'https://wa.me/' . ($party['whatsapp'] ?? '') . '?text=' . rawurlencode($text);
    }
}
