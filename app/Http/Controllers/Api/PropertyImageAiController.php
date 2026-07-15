<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertyImageAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class PropertyImageAiController extends Controller
{
    /**
     * GET /api/mobile/properties/{property}/ai-suggestions
     *
     * Returns AI suggestion state for all analysed images on this property.
     * Used by mobile to poll while AnalysePropertyImageJob runs.
     */
    public function suggestions(Request $request, Property $property): JsonResponse
    {
        $this->guard($request, $property);

        $rows = PropertyImageAnalysis::query()
            ->where('property_id', $property->id)
            ->orderBy('id', 'desc')
            ->get();

        $featureBuckets = [];
        $spaceBuckets   = [];
        foreach ($rows as $row) {
            // NB: the mobile client reads each source by its EXACT camelCase
            // keys (`analysisId`, `imagePath`). Keep them camelCase — a
            // snake_case key reads as `undefined` and breaks the source-photo
            // mapping (and, depending on the parser, the whole suggestion).
            foreach ((array) $row->detected_features as $f) {
                $token = (string) ($f['token'] ?? '');
                if ($token === '') continue;
                $featureBuckets[$token][] = [
                    'analysisId' => $row->id,
                    'imagePath'  => $row->image_path,
                    'confidence' => (float) ($f['confidence'] ?? 0),
                ];
            }
            foreach ((array) $row->detected_spaces as $s) {
                $token = (string) ($s['token'] ?? '');
                if ($token === '') continue;
                $spaceBuckets[$token][] = [
                    'analysisId' => $row->id,
                    'imagePath'  => $row->image_path,
                    'confidence' => (float) ($s['confidence'] ?? 0),
                ];
            }
        }

        // Aggregate: max-confidence per token + list of source images
        $features = [];
        foreach ($featureBuckets as $token => $hits) {
            $top = max(array_column($hits, 'confidence'));
            $features[] = [
                'token'      => $token,
                'confidence' => round($top, 2),
                'sources'    => $hits,
            ];
        }
        $spaces = [];
        foreach ($spaceBuckets as $token => $hits) {
            $top = max(array_column($hits, 'confidence'));
            $spaces[] = [
                'token'      => $token,
                'confidence' => round($top, 2),
                'count'      => count($hits),
                'sources'    => $hits,
            ];
        }

        return response()->json([
            'property_id'  => $property->id,
            'total'        => $rows->count(),
            'queued'       => $rows->where('status', 'queued')->count(),
            'processing'   => $rows->where('status', 'processing')->count(),
            'complete'     => $rows->where('status', 'complete')->count(),
            'failed'       => $rows->where('status', 'failed')->count(),
            'features'     => $features,
            'spaces'       => $spaces,
            'features_meta_current' => $property->features_json_meta ?? new \stdClass(),
        ]);
    }

    /**
     * POST /api/mobile/properties/{property}/features/merge-ai
     *
     * Agent has reviewed the AI suggestions and confirmed which features to keep.
     * Merges the confirmed set into features_json and records audit meta.
     *
     * Payload: { confirmed: ["pool", "garden"], rejected: ["sea_view"] }
     */
    public function mergeFeatures(Request $request, Property $property): JsonResponse
    {
        $this->guard($request, $property);

        $data = $request->validate([
            'confirmed' => 'array',
            'confirmed.*' => 'string|max:64',
            'rejected'  => 'array',
            'rejected.*'  => 'string|max:64',
        ]);

        $confirmed = array_values(array_unique($data['confirmed'] ?? []));
        $rejected  = array_values(array_unique($data['rejected'] ?? []));

        // Pull current AI confidence per token so we can store it in meta
        $rows = PropertyImageAnalysis::query()
            ->where('property_id', $property->id)
            ->where('status', 'complete')
            ->get();
        $confidenceByToken = [];
        foreach ($rows as $row) {
            foreach ((array) $row->detected_features as $f) {
                $t = (string) ($f['token'] ?? '');
                $c = (float)  ($f['confidence'] ?? 0);
                if ($t !== '' && (!isset($confidenceByToken[$t]) || $c > $confidenceByToken[$t])) {
                    $confidenceByToken[$t] = $c;
                }
            }
        }

        $features = $property->features_json ?? [];
        $meta     = $property->features_json_meta ?? [];

        foreach ($confirmed as $token) {
            if (! in_array($token, $features, true)) $features[] = $token;
            $meta[$token] = [
                'source'              => 'ai',
                'confidence'          => $confidenceByToken[$token] ?? null,
                'confirmed_by_user_id' => $request->user()->id,
                'confirmed_at'        => now()->toIso8601String(),
            ];
        }

        foreach ($rejected as $token) {
            $features = array_values(array_filter($features, fn ($v) => $v !== $token));
            unset($meta[$token]);
        }

        $property->features_json      = array_values(array_unique($features));
        $property->features_json_meta = $meta;
        $property->saveQuietly();

        return response()->json([
            'message'            => 'Features merged.',
            'features_json'      => $property->features_json,
            'features_json_meta' => $property->features_json_meta,
        ]);
    }

    private function guard(Request $request, Property $property): void
    {
        $user = $request->user();
        if (! $user || ! $user->hasPermission('use_property_image_ai')) {
            abort(403, 'Permission denied.');
        }
        if ($user->agency_id && $property->agency_id !== $user->agency_id) {
            abort(404);
        }
    }
}
