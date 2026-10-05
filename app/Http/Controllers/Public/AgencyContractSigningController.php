<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\Platform\PlatformContractAttachment;
use App\Services\Platform\PlatformContractService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Public, token-gated signing of a platform contract. Spec §6.3.
 *
 * An expired / voided / signed / declined / unknown link only ever shows a plain
 * status page — NEVER the document.
 */
class AgencyContractSigningController extends Controller
{
    public function __construct(private PlatformContractService $svc)
    {
    }

    private function noStore($response)
    {
        return $response->header('Cache-Control', 'no-store')->header('X-Robots-Tag', 'noindex, nofollow');
    }

    private function statusPage(string $state)
    {
        return $this->noStore(response()->view('public.agency-contract.status', ['state' => $state], $state === 'unknown' ? 404 : 200));
    }

    public function show(Request $request, string $token)
    {
        $env = strlen($token) === 48 ? $this->svc->resolve($token) : null;
        if (!$env) {
            return $this->statusPage('unknown');
        }
        if (!$env->isSignable()) {
            return $this->statusPage($env->status);
        }
        $this->svc->recordView($env, $request->ip());

        return $this->noStore(response()->view('public.agency-contract.sign', [
            'env' => $env->load(['agency', 'attachments']), 'consent' => PlatformContractService::CONSENT, 'token' => $token,
        ]));
    }

    public function sign(Request $request, string $token)
    {
        $data = $request->validate([
            'typed_name'     => 'required|string|max:255',
            'consent'        => 'accepted',
            'signature_data' => 'nullable|string|max:400000',
        ]);

        try {
            $this->svc->sign($token, trim($data['typed_name']), $data['signature_data'] ?? null, $request->ip(), $request->userAgent());
        } catch (\DomainException $e) {
            return $this->statusPage('unavailable');
        }

        return $this->statusPage('signed_now');
    }

    public function decline(Request $request, string $token)
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        try {
            $this->svc->decline($token, $data['reason'], $request->ip());
        } catch (\DomainException $e) {
            return $this->statusPage('unavailable');
        }

        return $this->statusPage('declined_now');
    }

    /** An attachment (e.g. debit order form) — only while the link is signable. */
    public function attachment(Request $request, string $token, int $attachment)
    {
        $env = strlen($token) === 48 ? $this->svc->resolve($token) : null;
        abort_unless($env && $env->isSignable(), 404);
        $att = PlatformContractAttachment::where('envelope_id', $env->id)->findOrFail($attachment);
        abort_unless(Storage::disk('local')->exists($att->stored_path), 404);

        return Storage::disk('local')->download($att->stored_path, $att->original_name);
    }
}
