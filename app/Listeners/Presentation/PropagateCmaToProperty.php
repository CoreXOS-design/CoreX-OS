<?php

declare(strict_types=1);

namespace App\Listeners\Presentation;

use App\Events\Presentation\PresentationFieldsExtracted;
use App\Services\Presentation\PropertyCmaPropagationService;
use App\Services\Prospecting\TrackedPropertyMatchOrCreateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * On PresentationFieldsExtracted, do TWO things:
 *   1. Back-propagate CMA fields to the linked Property (legacy single-tenant path).
 *      This stays — it enriches an existing Property when the presentation has a
 *      direct listing_id or a property_presentation_snapshots pivot row.
 *   2. Feed the same facts into the Tracked Property universe via the central
 *      match-or-create hub. This always runs, even when no Property is linked —
 *      so CMAs done on properties HFC has never mandated still land somewhere.
 *
 * Failure-isolated: either propagation path can fail without affecting the other
 * or the original presentation save.
 */
final class PropagateCmaToProperty
{
    /**
     * Field keys consumed from presentation_fields. Mirrors DocumentExtractor's
     * output schema — see app/Support/Presentation/DocumentExtractor.php.
     */
    private const RELEVANT_FIELD_KEYS = [
        'subject.address',
        'subject.suburb',
        'subject.erf',
        'subject.extent_m2',
        'subject.gps',
        'subject.purchase_price',
        'subject.purchase_date',
        'subject.title_deed',
        'municipal.total_value',
        'municipal.valuation_year',
    ];

    public function __construct(
        private readonly PropertyCmaPropagationService $propertyService,
        private readonly TrackedPropertyMatchOrCreateService $trackedService,
    ) {}

    public function handle(PresentationFieldsExtracted $event): void
    {
        // Each propagation path is independently try/catch'd so one failing does
        // not block the other. The original presentation save is never affected.
        try {
            $result = $this->propertyService->propagateFromPresentation(
                $event->presentationId,
                allowAddressMatch: true,
            );

            if (($result['status'] ?? null) === 'updated') {
                Log::info('CMA fields propagated to Property', $result);
            }
        } catch (\Throwable $e) {
            Log::warning('CMA → Property propagation failed', [
                'presentation_id' => $event->presentationId,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $this->propagateToTrackedProperty($event);
        } catch (\Throwable $e) {
            Log::warning('CMA → TrackedProperty propagation failed', [
                'presentation_id' => $event->presentationId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Universal Match-or-Create: every CMA presentation contributes a row to the
     * Tracked Property universe regardless of whether HFC has a mandate. Closes
     * the orphaned-CMA loop identified in the market-intelligence discovery audit.
     */
    private function propagateToTrackedProperty(PresentationFieldsExtracted $event): void
    {
        $presentation = DB::table('presentations')
            ->where('id', $event->presentationId)
            ->whereNull('deleted_at')
            ->first();
        if (!$presentation) return;

        $agencyId = $event->agencyId ?? $this->resolveAgencyId($presentation);
        if ($agencyId === null) return;

        // Use the canonical resolved value: final_value > override_value > extracted_value.
        // Column is final_value — NOT field_value (the spec's draft had this wrong; same bug as
        // the CMA back-propagation build earlier today caught).
        $rows = DB::table('presentation_fields')
            ->where('presentation_id', $event->presentationId)
            ->whereNull('deleted_at')
            ->whereIn('field_key', self::RELEVANT_FIELD_KEYS)
            ->select('field_key', 'final_value', 'override_value', 'extracted_value')
            ->get();

        $fields = [];
        foreach ($rows as $r) {
            $value = $r->final_value ?? $r->override_value ?? $r->extracted_value;
            if ($value !== null && $value !== '') {
                $fields[$r->field_key] = $value;
            }
        }

        if (empty($fields)) return;

        $facts = $this->fieldsToFacts($fields);

        // Without any locating fact, matchOrCreate would create a useless ghost record.
        // Bail rather than pollute the universe with addressless rows.
        if (empty($facts['suburb']) && empty($facts['street_name']) && empty($facts['erf_number'])
            && empty($facts['cma_gps_lat'])) {
            return;
        }

        $this->trackedService->matchOrCreate(
            agencyId: $agencyId,
            facts: $facts,
            source: [
                'type' => 'cmainfo',
                'ref'  => "pres_{$event->presentationId}",
                'payload' => [
                    'presentation_id' => $event->presentationId,
                    'extracted_at'    => now()->toIso8601String(),
                ],
            ],
            actorUserId: $event->actorUserId,
        );
    }

    /**
     * Map presentation_fields rows to TrackedProperty canonical facts.
     * Parses GPS, strips currency formatting, strips "Erf "/"Stand " prefixes.
     */
    private function fieldsToFacts(array $fields): array
    {
        $facts = [];

        if (!empty($fields['subject.address'])) {
            $facts['address'] = (string) $fields['subject.address'];
            // The match-or-create service runs token-overlap fallback on $facts['address'],
            // so passing the raw free-text address is enough. Parsing street_number/name
            // is best-effort and the service tolerates nulls.
            if (preg_match('/^(\d+\w*)\s+(.+)$/', trim((string) $fields['subject.address']), $m)) {
                $facts['street_number'] = $m[1];
                $facts['street_name']   = $m[2];
            }
        }

        if (!empty($fields['subject.suburb'])) {
            $facts['suburb'] = (string) $fields['subject.suburb'];
        }

        if (!empty($fields['subject.erf'])) {
            $erf = preg_replace('/^(erf|stand)\s+/i', '', trim((string) $fields['subject.erf']));
            if ($erf !== '') $facts['erf_number'] = $erf;
        }

        if (!empty($fields['subject.title_deed'])) {
            $td = trim((string) $fields['subject.title_deed']);
            if ($td !== '') $facts['title_deed_number'] = $td;
        }

        if (!empty($fields['subject.extent_m2'])) {
            $extent = (float) preg_replace('/[^\d.]/', '', (string) $fields['subject.extent_m2']);
            if ($extent > 0) $facts['erf_size_m2'] = $extent;
        }

        if (!empty($fields['subject.gps'])) {
            $gps = $this->parseGps((string) $fields['subject.gps']);
            if ($gps) {
                $facts['cma_gps_lat'] = $gps['lat'];
                $facts['cma_gps_lng'] = $gps['lng'];
            }
        }

        if (!empty($fields['municipal.total_value'])) {
            $val = (float) preg_replace('/[^\d.]/', '', (string) $fields['municipal.total_value']);
            if ($val > 0) $facts['municipal_valuation'] = $val;
        }

        if (!empty($fields['municipal.valuation_year'])) {
            $year = (int) preg_replace('/\D/', '', (string) $fields['municipal.valuation_year']);
            if ($year >= 2000 && $year <= ((int) date('Y') + 1)) {
                $facts['municipal_valuation_year'] = $year;
            }
        }

        if (!empty($fields['subject.purchase_price'])) {
            $price = (float) preg_replace('/[^\d.]/', '', (string) $fields['subject.purchase_price']);
            if ($price > 0) $facts['last_known_sold_price'] = $price;
        }

        if (!empty($fields['subject.purchase_date'])) {
            try {
                $facts['last_known_sold_date'] = Carbon::parse((string) $fields['subject.purchase_date'])->toDateString();
            } catch (\Throwable) {
                // ignore unparseable date — field is dropped
            }
        }

        return $facts;
    }

    /**
     * SA-format GPS parser. Tolerates "30.265405°E 30.980583°S" AND the pdftotext
     * encoding casualty form "30.265405??E 30.980583??S" — both appear in HFC's data.
     */
    private function parseGps(string $raw): ?array
    {
        $raw = trim($raw);
        if ($raw === '') return null;
        $sep = '(?:°|\?\?|\s)*';
        if (preg_match('/([\d.]+)\s*' . $sep . '\s*([EW])\s+([\d.]+)\s*' . $sep . '\s*([NS])/i', $raw, $m)) {
            $lng = (float) $m[1] * (strtoupper($m[2]) === 'W' ? -1 : 1);
            $lat = (float) $m[3] * (strtoupper($m[4]) === 'S' ? -1 : 1);
            return ['lat' => $lat, 'lng' => $lng];
        }
        return null;
    }

    private function resolveAgencyId(object $presentation): ?int
    {
        if (!empty($presentation->agency_id)) return (int) $presentation->agency_id;
        if (!empty($presentation->branch_id)) {
            $branch = DB::table('branches')->where('id', $presentation->branch_id)->first(['agency_id']);
            if ($branch && $branch->agency_id) return (int) $branch->agency_id;
        }
        return null;
    }
}
