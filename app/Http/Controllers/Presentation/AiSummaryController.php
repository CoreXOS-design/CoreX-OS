<?php

declare(strict_types=1);

namespace App\Http\Controllers\Presentation;

use App\Http\Controllers\Controller;
use App\Models\Presentation;
use App\Models\PresentationAiSummaryHistory;
use App\Models\PresentationAiVariant;
use App\Services\Presentations\AiSummaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 3 — agent-side AI summary controller.
 *
 *   POST /presentations/{p}/ai-summary/generate  → calls AiSummaryService::generate
 *   POST /presentations/{p}/ai-summary/accept    → locks chosen history row into latest version
 *
 * All endpoints agency-scoped via guard.
 */
final class AiSummaryController extends Controller
{
    public function __construct(private readonly AiSummaryService $svc = new AiSummaryService()) {}

    /** POST /presentations/{presentation}/ai-summary/generate */
    public function generate(Request $request, Presentation $presentation): JsonResponse
    {
        $this->guardAgency($request, $presentation);

        // Bug-class guard: if the presentation_ai_variants table is empty (the
        // seeder was never run, was wiped, or every variant got deactivated),
        // every downstream step fails opaquely — the dropdown renders empty,
        // the JS posts variant_id=NaN, validation/findOrFail explodes, and the
        // user sees "Generation failed: unknown". Catch the system-state
        // problem here so the message names the actual cause (an admin task,
        // not a transient generation failure).
        if (PresentationAiVariant::where('is_active', true)->count() === 0) {
            return response()->json([
                'error' => 'No summary variants configured. Contact admin.',
            ], 422);
        }

        $data = $request->validate(['variant_id' => 'required|integer|exists:presentation_ai_variants,id']);

        $version = $presentation->versions()->latest('id')->first();
        if (!$version) {
            return response()->json(['error' => 'Presentation has no compiled version yet. Run analysis first.'], 422);
        }

        $variant = PresentationAiVariant::findOrFail($data['variant_id']);
        try {
            $result = $this->svc->generate($presentation, $version, $variant->id, $request->user());
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok'           => !$result['from_fallback'],
            'history_id'   => $result['history_id'],
            'text'         => $result['text'],
            'word_count'   => $result['word_count'],
            'tokens_used'  => $result['tokens_used'],
            'latency_ms'   => $result['latency_ms'],
            'model'        => $result['model'],
            'from_cache'   => $result['from_cache'],
            'from_fallback'=> $result['from_fallback'],
            'error'        => $result['error'],
            'variant_id'   => $result['variant_id'],
            'variant_key'  => $variant->key,
        ]);
    }

    /** POST /presentations/{presentation}/ai-summary/accept */
    public function accept(Request $request, Presentation $presentation): JsonResponse
    {
        $this->guardAgency($request, $presentation);
        $data = $request->validate([
            'history_id'   => 'required|integer|exists:presentation_ai_summary_history,id',
            'edited_text'  => 'nullable|string|min:50|max:5000',
        ]);

        $version = $presentation->versions()->latest('id')->first();
        if (!$version) {
            return response()->json(['error' => 'No compiled version to accept into.'], 422);
        }

        $history = PresentationAiSummaryHistory::findOrFail($data['history_id']);
        if ($history->presentation_id !== $presentation->id) {
            abort(403, 'History row does not belong to this presentation.');
        }

        try {
            $this->svc->acceptForVersion($version, $history->id, $data['edited_text'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        $version->refresh();
        return response()->json([
            'ok'                     => true,
            'ai_summary_text'        => $version->ai_summary_text,
            'edited_by_agent'        => $version->ai_summary_edited_by_agent,
        ]);
    }

    private function guardAgency(Request $request, Presentation $presentation): void
    {
        $effective = $request->user()?->effectiveAgencyId();
        if (!$effective || (int) $presentation->agency_id !== (int) $effective) {
            abort(403, 'Cross-agency access denied.');
        }
    }
}
