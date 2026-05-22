<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Presentations\PresentationGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Presentations V2 Phase 1 — one-button generator entry point.
 *
 * POST /properties/{property}/generate-presentation
 *
 * Spec: .ai/specs/presentations.md §3.1
 */
class PresentationGeneratorController extends Controller
{
    public function __construct(
        private PresentationGeneratorService $generator,
    ) {}

    public function generate(Request $request, Property $property): JsonResponse|RedirectResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Permission + agency-scope gate
        if (!$user->hasPermission('create_presentations')) {
            return $this->reject($request, 'You do not have permission to generate presentations.', 403);
        }
        if ((int) $property->agency_id !== (int) $user->effectiveAgencyId()) {
            return $this->reject($request, 'Property is outside your agency scope.', 403);
        }

        $validated = $request->validate([
            'asking_price' => ['nullable', 'integer', 'min:0'],
        ]);

        $startedAt = microtime(true);

        try {
            $version = $this->generator->generateForProperty(
                propertyId:  $property->id,
                agentUserId: $user->id,
                agencyId:    (int) $property->agency_id,
                options:     array_key_exists('asking_price', $validated)
                    ? ['asking_price' => $validated['asking_price']]
                    : [],
            );
        } catch (\Throwable $e) {
            Log::error('PresentationGeneratorController: generation failed', [
                'property_id' => $property->id,
                'user_id'     => $user->id,
                'message'     => $e->getMessage(),
                'trace'       => $e->getTraceAsString(),
            ]);
            return $this->reject(
                $request,
                'Could not generate presentation: ' . $e->getMessage(),
                500,
            );
        }

        $elapsedMs = (int) round((microtime(true) - $startedAt) * 1000);

        $payload = [
            'presentation_id'     => $version->presentation_id,
            'version_id'          => $version->id,
            'generation_time_ms'  => $elapsedMs,
            'redirect_url'        => route('presentations.show', $version->presentation_id),
        ];

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json($payload, 201);
        }

        return redirect()->route('presentations.show', $version->presentation_id)
            ->with('success', 'Presentation generated in ' . $elapsedMs . ' ms.');
    }

    private function reject(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json(['error' => $message], $status);
        }
        return back()->with('error', $message);
    }
}
