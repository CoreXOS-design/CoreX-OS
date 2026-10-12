<?php

namespace App\Http\Controllers\TitleDeeds;

use App\Http\Controllers\Controller;
use App\Models\Agency;
use App\Services\TitleDeeds\TitleDeedSigningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * .ai/specs/title-deed-register.md section 14.10 - the page a collector opens from their one-time signing link, on their own
 * phone. No login: the token is the credential (stored hashed, single use, expiring, tied to ONE deed and ONE named collector).
 * Every dead link is a plain page that does not say which kind of dead it is to a stranger beyond "not available / run out".
 */
class TitleDeedSignPublicController extends Controller
{
    public function __construct(private TitleDeedSigningService $signing) {}

    public function show(Request $request, string $token)
    {
        $r = $this->signing->resolve($token);

        if ($r['state'] === 'open') {
            $this->signing->recordOpen($r['link']);
        }

        return response()->view('title-deeds.public.sign', [
            'state' => $r['state'],
            'token' => $token,
            'collector' => $r['link']?->collector_name,
            'property' => $r['deed']?->property_display,
            'agency' => $r['deed'] ? (Agency::withoutGlobalScopes()->find($r['deed']->agency_id)?->name ?: 'the office') : null,
            'expires' => $r['link']?->expires_at?->format('H:i'),
        ])->withHeaders($this->privateHeaders());
    }

    public function submit(Request $request, string $token): JsonResponse
    {
        $data = $request->validate([
            'typed_name' => ['nullable', 'string', 'max:191'],
            'signature_image' => ['nullable', 'string', 'max:1450000'],
            'confirmed' => ['nullable'],
        ]);

        $result = $this->signing->sign(
            $token, (string) ($data['typed_name'] ?? ''), (string) ($data['signature_image'] ?? ''),
            filter_var($data['confirmed'] ?? false, FILTER_VALIDATE_BOOLEAN), $request->ip(), $request->userAgent()
        );

        $status = match ($result['state']) {
            'done' => 201,
            'error' => 422,
            'invalid' => 404,
            default => 409,
        };

        return response()->json($result, $status)->withHeaders($this->privateHeaders());
    }

    private function privateHeaders(): array
    {
        return ['Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer'];
    }
}
