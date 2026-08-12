<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AgentSignatureService;
use App\Support\Impersonation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Reusable "place my signature" endpoints — consumed by BOTH e-sign and the CMA
 * certificate generator (and any future placer). All operate on the CURRENT
 * logged-in user's OWN signature; the service blocks impersonated sessions.
 */
final class AgentSignatureController extends Controller
{
    public function __construct(private readonly AgentSignatureService $svc)
    {
    }

    /** Is my signature ready, and am I in an impersonated session? */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'configured'    => $this->svc->isConfigured($request->user()),
            'impersonating' => Impersonation::actingAdminId() !== null,
        ]);
    }

    /** Verify the signing PIN and unlock this document/context for repeated placement. */
    public function unlock(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'pin'     => 'required|string|max:20',
            'context' => 'required|string|max:120',
        ]);

        $ok = $this->svc->verifyPinAndUnlock($request->user(), $validated['pin'], $validated['context']);

        return response()->json(
            ['ok' => $ok, 'error' => $ok ? null : 'Incorrect PIN, or your signature is not set up.'],
            $ok ? 200 : 422
        );
    }

    /** Return the decrypted signature/initial image — only if unlocked + not impersonating. */
    public function asset(Request $request, string $type): JsonResponse
    {
        abort_unless(in_array($type, ['signature', 'initial'], true), 404);

        $context = trim((string) $request->query('context', ''));
        abort_if($context === '', 422, 'Missing context.');

        // image() aborts 403 when locked or impersonating — never leaks the pixels.
        $data = $this->svc->image($request->user(), $type, $context);
        abort_if($data === null, 404, 'No saved signature.');

        return response()->json(['ok' => true, 'image' => $data]);
    }
}
