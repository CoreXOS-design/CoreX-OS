<?php

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Services\Presentations\CmaCoverageService;
use App\Services\Presentations\PresentationGeneratorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Presentations V2 Phase 1 + 2 — one-button generator entry point + coverage.
 *
 * POST /corex/properties/{property}/generate-presentation
 * GET  /corex/properties/{property}/presentation-coverage
 *
 * Spec: .ai/specs/presentations.md §3.1 + Phase 2
 */
class PresentationGeneratorController extends Controller
{
    public function __construct(
        private PresentationGeneratorService $generator,
        private CmaCoverageService $coverage,
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

        // Keystone — property_type is mandatory. The classifier needs it
        // to derive title_type (which drives comp-filter discipline);
        // without it the presentation would silently mis-classify or be
        // forced to lean on the agency category fallback, which is too
        // coarse for mixed-stock agencies. Reject early with a clear
        // user-facing message rather than letting the generator either
        // fail mysteriously or produce a wrong report.
        if (trim((string) ($property->property_type ?? '')) === '') {
            return $this->reject(
                $request,
                'No property type selected — please select a property type to continue.',
                422,
            );
        }

        $validated = $request->validate([
            'asking_price'  => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            // Phase 3b — per-presentation scope override.
            'comp_scope'    => ['nullable', 'in:radius_all,suburb_only'],
            'comp_radius_m' => ['nullable', 'integer', 'min:50', 'max:5000'],
        ]);

        $startedAt = microtime(true);

        try {
            $options = [];
            if (array_key_exists('asking_price', $validated)) {
                $options['asking_price'] = $validated['asking_price'];
            }
            if (!empty($validated['comp_scope'])) {
                $options['comp_scope'] = $validated['comp_scope'];
            }
            if (!empty($validated['comp_radius_m'])) {
                $options['comp_radius_m'] = (int) $validated['comp_radius_m'];
            }

            $version = $this->generator->generateForProperty(
                propertyId:  $property->id,
                agentUserId: $user->id,
                agencyId:    (int) $property->agency_id,
                options:     $options,
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

        // Build 2 — version now lands on the review screen first, not the
        // public/show. The button on the property page opens the
        // returned review_url in a NEW TAB so the property page never
        // navigates away. Spec: presentation-data-lineage.md §3-B.
        $reviewUrl = route('presentations.review.show', $version->id);
        $payload = [
            'presentation_id'     => $version->presentation_id,
            'version_id'          => $version->id,
            'generation_time_ms'  => $elapsedMs,
            'review_url'          => $reviewUrl,
            // Backwards-compat alias — older client code still reads
            // redirect_url. New code should prefer review_url.
            'redirect_url'        => $reviewUrl,
            'review_status'       => $version->review_status,
        ];

        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json($payload, 201);
        }

        return redirect()->route('presentations.review.show', $version->id)
            ->with('success', 'Presentation ready for review (' . $elapsedMs . ' ms).');
    }

    private function reject(Request $request, string $message, int $status): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() || $request->ajax() || $request->wantsJson()) {
            return response()->json(['error' => $message], $status);
        }
        return back()->with('error', $message);
    }

    /**
     * GET /corex/properties/{property}/presentation-coverage
     *
     * Phase 2 — returns the coverage state JSON so the property show page
     * can render a badge above the Generate Presentation button without
     * blocking initial page render.
     */
    public function coverage(Request $request, Property $property): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        if (!$user->hasPermission('access_presentations')) {
            return response()->json(['error' => 'You do not have permission to view presentation coverage.'], 403);
        }
        if ((int) $property->agency_id !== (int) $user->effectiveAgencyId()) {
            return response()->json(['error' => 'Property is outside your agency scope.'], 403);
        }

        $result = $this->coverage->scoreForProperty($property);

        return response()->json($result);
    }
}
