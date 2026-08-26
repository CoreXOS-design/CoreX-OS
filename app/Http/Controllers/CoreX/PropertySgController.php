<?php

declare(strict_types=1);

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\Property;
use App\Models\PropertySgDocument;
use App\Services\SG\SgSearchService;
use App\Support\SG\SgQueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Phase 3j E3 — agent-facing endpoints for SG documents on a property.
 *
 * Routes:
 *   POST  /corex/properties/{property}/sg/search                         → search
 *   POST  /corex/properties/{property}/sg/documents/{sgDoc}/save         → save one
 *   POST  /corex/properties/{property}/sg/save-all                       → save all unsaved
 *   GET   /corex/properties/{property}/sg/documents/{sgDoc}/download     → stream TIF
 *
 * All endpoints enforce agency scoping: the property must belong to the
 * authenticated user's effective agency.
 */
final class PropertySgController extends Controller
{
    public function __construct(
        private readonly SgSearchService $svc,
        private readonly SgQueryBuilder $builder,
    ) {}

    /** POST /corex/properties/{property}/sg/search */
    public function search(Request $request, Property $property): JsonResponse
    {
        $this->guardAgency($request, $property);

        // Allow the agent to override / fill in missing fields. Otherwise
        // default from the property record via the query builder.
        $build = $this->builder->buildFromProperty($property);
        $query = array_merge($build['defaults'], $request->only([
            'province', 'rural_urban', 'town', 'parcel_number', 'portion', 'farm_name',
        ]));

        $missing = [];
        foreach (['province', 'town', 'parcel_number', 'portion'] as $required) {
            if (empty($query[$required]) && $required !== 'portion') {
                $missing[] = $required;
            }
        }
        if (!empty($missing)) {
            return response()->json([
                'ok'      => false,
                'error'   => 'Missing required fields: ' . implode(', ', $missing),
                'missing' => $missing,
            ], 422);
        }
        $query['portion'] = $query['portion'] ?: '0';

        // Optional: persist agent overrides back to the property as the new
        // search defaults — see request flag `save_defaults`.
        if ($request->boolean('save_defaults')) {
            $property->forceFill([
                'sg_province'      => $query['province'] ?? $property->sg_province,
                'sg_rural_urban'   => $query['rural_urban'] ?? $property->sg_rural_urban,
                'sg_farm_name'     => $query['farm_name'] ?? $property->sg_farm_name,
            ])->save();
        }

        $result = $this->svc->search($query);

        // Persist documents to the property (idempotent via unique constraint).
        $savedRows = [];
        if ($result->ok()) {
            // A corrected re-search (wrong erf typed, then fixed) must not leave
            // the previous, mistaken search's rows sitting in the property's
            // document list forever — clear anything not already saved before
            // recording this search's results. Saved documents are an agent's
            // deliberate choice and are never touched here.
            PropertySgDocument::where('property_id', $property->id)
                ->where('is_saved', false)
                ->get()
                ->each->delete();

            foreach ($result->documents as $doc) {
                // withTrashed(): the same document number can legitimately
                // resurface after the clear-stale step above soft-deleted it
                // (e.g. an agent corrects a search back to a parcel they'd
                // already tried) — the DB's unique key doesn't exempt
                // soft-deleted rows, so a plain updateOrCreate() would throw
                // a duplicate-key error instead of restoring the row.
                $row = PropertySgDocument::withTrashed()->updateOrCreate(
                    [
                        'property_id'        => $property->id,
                        'sg_document_number' => $doc['sg_document_number'],
                        'sg_page_number'     => $doc['sg_page_number'],
                    ],
                    [
                        'agency_id'     => $property->agency_id,
                        'sg_doc_type'   => $doc['sg_doc_type'],
                        'sg_source_url' => $doc['sg_source_url'],
                    ]
                );
                // deleted_at isn't mass-assignable, so restoring it needs the
                // dedicated SoftDeletes method rather than passing it through
                // the attributes array above.
                if ($row->trashed()) {
                    $row->restore();
                }
                $savedRows[] = $row->fresh()->toArray();
            }

            $property->forceFill(['sg_last_searched_at' => now()])->save();
        }

        return response()->json([
            'ok'           => $result->ok(),
            'from_cache'   => $result->fromCache,
            'fetched_at'   => $result->fetchedAt?->format(DATE_ATOM),
            'parse_error'  => $result->parseError,
            'error'        => $result->errorMessage,
            'documents'    => $savedRows,
            'resolved_query' => $result->resolvedQuery,
        ], $result->ok() ? 200 : ($result->parseError ? 502 : 503));
    }

    /** POST /corex/properties/{property}/sg/documents/{sgDoc}/save */
    public function saveDocument(Request $request, Property $property, PropertySgDocument $sgDoc): JsonResponse
    {
        $this->guardAgency($request, $property);
        $this->guardOwnership($sgDoc, $property);

        try {
            $saved = $this->svc->fetchAndSaveTif($sgDoc, $request->user());
        } catch (\Throwable $e) {
            return response()->json([
                'ok'    => false,
                'error' => 'TIF download failed: ' . $e->getMessage(),
            ], 502);
        }

        return response()->json([
            'ok'       => true,
            'document' => $saved->toArray(),
        ]);
    }

    /** POST /corex/properties/{property}/sg/save-all */
    public function saveAll(Request $request, Property $property): JsonResponse
    {
        $this->guardAgency($request, $property);

        $docs = PropertySgDocument::where('property_id', $property->id)
            ->where('is_saved', false)
            ->get();

        $results = ['saved' => 0, 'failed' => 0];
        foreach ($docs as $doc) {
            try {
                $this->svc->fetchAndSaveTif($doc, $request->user());
                $results['saved']++;
            } catch (\Throwable $e) {
                $results['failed']++;
            }
        }
        return response()->json(['ok' => true, 'results' => $results]);
    }

    /** GET /corex/properties/{property}/sg/documents/{sgDoc}/download */
    public function download(Request $request, Property $property, PropertySgDocument $sgDoc): StreamedResponse|Response
    {
        $this->guardAgency($request, $property);
        $this->guardOwnership($sgDoc, $property);

        if (!$sgDoc->is_saved || !$sgDoc->storage_path) {
            abort(404, 'Document not saved to drive yet.');
        }
        $disk = Storage::disk($this->svc->disk());
        if (!$disk->exists($sgDoc->storage_path)) {
            abort(404, 'File missing on storage.');
        }

        $filename = sprintf(
            'SG_%s_p%d.tif',
            preg_replace('/[^A-Za-z0-9_\-]/', '_', $sgDoc->sg_document_number),
            $sgDoc->sg_page_number,
        );
        return $disk->download($sgDoc->storage_path, $filename, [
            'Content-Type' => 'image/tiff',
        ]);
    }

    // ── Guards ──────────────────────────────────────────────────────────────

    private function guardAgency(Request $request, Property $property): void
    {
        $effective = $request->user()?->effectiveAgencyId();
        if (!$effective || (int) $property->agency_id !== (int) $effective) {
            abort(403, 'Cross-agency access denied.');
        }
    }

    private function guardOwnership(PropertySgDocument $sgDoc, Property $property): void
    {
        if ((int) $sgDoc->property_id !== (int) $property->id) {
            abort(404, 'Document not for this property.');
        }
    }
}
