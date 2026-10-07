<?php

namespace App\Http\Controllers;

use App\Exceptions\RentalInspectionSigningLinkException as LinkException;
use App\Models\RentalInspectionSetting;
use App\Models\RentalInspectionSignature;
use App\Models\RentalInspectionSigningLink;
use App\Services\Rentals\RentalInspectionReportPdfService;
use App\Services\Rentals\RentalInspectionSigningLinkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/rental-inspections.md §46 — the public page a party reaches from their personal signing link (email,
 * WhatsApp, or the QR code on the agent's phone). No CoreX session: the token is the authority, exactly like
 * RentalInspectionPublicController, and it is a top-level controller for the same reason.
 *
 * It shows the SAME read-only report as the general public link (RentalInspectionPublicController::reportData() is the
 * one builder) plus a "Sign" step at the end. A link shows ONLY its own inspection and writes only through
 * RentalInspectionSigningLinkService::submit().
 *
 * Every dead link — wrong, revoked, expired, or for an archived / cancelled inspection — is the one uniform
 * "this link isn't available" page (§44a), so a stranger cannot tell which.
 */
class RentalInspectionSigningController extends Controller
{
    public function __construct(
        private RentalInspectionSigningLinkService $links,
        private RentalInspectionPublicController $report,
    ) {}

    public function show(Request $request, string $token): \Symfony\Component\HttpFoundation\Response
    {
        $link = RentalInspectionSigningLink::findLiveByToken($token);
        if (! $link) {
            return $this->report->privateHeaders(response()->view('rental-inspections.public.unavailable'));
        }

        // An agent previewing a link while logged in is not "the party opened it".
        if (! auth()->check()) {
            $this->links->recordOpen($link);
        }

        $inspection = $link->inspection;
        $data = $this->report->reportData(
            $inspection,
            fn (RentalInspectionSignature $signature, string $kind) => route('rental-inspections.sign.signature-file', ['token' => $link->token, 'signature' => $signature->id, 'kind' => $kind]),
        );
        $data['signing'] = $this->signingContext($link, 'link');

        return $this->report->privateHeaders(response()->view('rental-inspections.public.show', $data));
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $link = RentalInspectionSigningLink::findLiveByToken($token);
        if (! $link) {
            return response()->json(['message' => 'This link is no longer available.'], 404);
        }

        $input = $this->validatedInput($request);

        try {
            $this->links->submit($link, $input, [
                'via' => RentalInspectionSignature::SIGNED_VIA_LINK,
                'ip' => $request->ip(),
                'user_agent' => $request->userAgent(),
            ]);
        } catch (LinkException $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason], $e->reason === LinkException::INVALID ? 422 : 409);
        }

        return response()->json(['ok' => true, 'outcome' => $input['action'] === 'sign' ? 'signed' : 'declined'], 201);
    }

    /** Token-authorised fetch of a signature image / wet-ink scan under THIS link's own inspection only. */
    public function signatureFile(Request $request, string $token, int $signature, string $kind)
    {
        abort_unless(in_array($kind, ['signature', 'wet-ink'], true), 404);

        $link = RentalInspectionSigningLink::findLiveByToken($token);
        abort_if(! $link, 404);

        $row = RentalInspectionSignature::withoutGlobalScopes()
            ->where('id', $signature)
            ->where('rental_inspection_id', $link->rental_inspection_id)
            ->whereNull('superseded_at')
            ->first();
        abort_if(! $row, 404);

        return $row->fileResponse($kind);
    }

    /** The report as a PDF the party can keep — the same document the completion copies carry. */
    public function pdf(Request $request, string $token)
    {
        $link = RentalInspectionSigningLink::findLiveByToken($token);
        abort_if(! $link, 404);

        $service = app(RentalInspectionReportPdfService::class);
        try {
            $bytes = $service->generate($link->inspection)->output();
        } catch (\Throwable $e) {
            \Log::error('Signing-link PDF download failed', ['link_id' => $link->id, 'error' => $e->getMessage()]);
            abort(503, 'The report could not be prepared just now — please try again in a minute.');
        }

        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $service->filenameFor($link->inspection) . '"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ]);
    }

    /**
     * What the "Sign" section of the page needs. `mode` is 'link' (the party's own phone) or 'device' (the agent's
     * logged-in device — CoreX\RentalInspectionSigningLinkController builds that one with its own submit URL).
     *
     * @return array<string, mixed>
     */
    public function signingContext(RentalInspectionSigningLink $link, string $mode, ?string $submitUrl = null): array
    {
        $inspection = $link->inspection;
        $check = $this->links->signability($link);
        $party = $this->links->parties($inspection)->first(
            fn (array $p) => $p['role'] === $link->party_role && ($link->party_role === 'agent' || (int) $p['contact_id'] === (int) $link->party_contact_id)
        );
        $signature = $link->signature_id
            ? RentalInspectionSignature::withoutGlobalScopes()->find($link->signature_id)
            : null;

        return [
            'mode' => $mode,
            'link' => $link,
            'role' => $link->party_role,
            'party_name' => $party['name'] ?? '',
            'can_sign' => $check['can_sign'],
            'message' => $check['message'],
            'reason' => $check['reason'],
            'submit_url' => $submitUrl ?? route('rental-inspections.sign.submit', $link->token),
            'pdf_url' => route('rental-inspections.sign.pdf', $link->token),
            'presets' => collect(RentalInspectionSetting::refusalReasonPresetsFor($inspection->agency_id))->values()->all(),
            'outcome' => $link->outcome,
            'outcome_at' => $link->outcome_at,
            'signature' => $signature,
            'completed' => $inspection->status === \App\Models\RentalInspection::STATUS_COMPLETED,
            'resign_notice' => $party ? $this->links->mustSignAgain($inspection, $party) : false,
            'agent_open_url' => $link->party_role === 'agent'
                ? route('corex.properties.show', ['property' => $inspection->property_id, 'tab' => 'inspections'])
                : null,
            'expires_at' => $link->expires_at,
        ];
    }

    /** @return array{action:string, typed_name:string, signature_image:?string, read_confirmed:mixed, comment:?string, reason_preset:?string, reason_note:?string} */
    public static function validatedInputFrom(Request $request): array
    {
        $v = $request->validate([
            'action' => ['required', 'string', 'in:sign,decline'],
            'typed_name' => ['required', 'string', 'min:2', 'max:191'],
            // ~1.4M base64 chars ≈ the 1MB decoded cap storeCanvasImage() enforces (audit M5).
            'signature_image' => ['nullable', 'string', 'max:1450000'],
            'read_confirmed' => ['nullable'],
            'comment' => ['nullable', 'string', 'max:2000'],
            'reason_preset' => ['nullable', 'string', 'max:60'],
            'reason_note' => ['nullable', 'string', 'max:2000'],
        ]);

        return [
            'action' => $v['action'],
            'typed_name' => $v['typed_name'],
            'signature_image' => $v['signature_image'] ?? null,
            'read_confirmed' => $v['read_confirmed'] ?? false,
            'comment' => $v['comment'] ?? null,
            'reason_preset' => $v['reason_preset'] ?? null,
            'reason_note' => $v['reason_note'] ?? null,
        ];
    }

    private function validatedInput(Request $request): array
    {
        return self::validatedInputFrom($request);
    }
}
