<?php

declare(strict_types=1);

namespace App\Services\Presentations;

use App\Models\Agency;
use App\Models\Presentation;
use App\Models\PresentationAiSummaryHistory;
use App\Models\PresentationAiVariant;
use App\Models\PresentationVersion;
use App\Models\User;
use App\Services\AI\AnthropicGateway;
use App\Services\AI\DTOs\NarrativeRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 3 — AI executive-summary generator + history + accept-into-version.
 *
 * Architecture:
 *   - Reuses the existing AnthropicGateway (retry, cache, budget, fallback).
 *     We don't build a parallel client.
 *   - gatherFacts() builds a structured dictionary from the presentation's
 *     compiled analytics — strictly what the AI is allowed to see.
 *   - buildPrompt() renders the facts dict as human-readable text (not JSON
 *     — easier for the model to use) and prepends the common system rules.
 *   - generate() calls AnthropicGateway, stamps a history row, returns the
 *     result without saving to the version (acceptForVersion does that).
 *   - acceptForVersion() locks the chosen history row into the
 *     PresentationVersion snapshot. Edit-vs-raw both preserved.
 *
 * Cache key strategy: every (presentation_id, variant_key, prompt_hash)
 * tuple gets its own cache slot via the gateway. Re-generating with same
 * variant + same facts returns the cached output cheaply.
 */
final class AiSummaryService
{
    /** System prefix shared by every variant (Phase 3 Part C1). */
    private const SYSTEM_PREFIX = <<<'TXT'
You are a senior real estate market analyst writing a presentation summary for a property seller.
You write in {agency_country} English with local property terminology.

HARD RULES:
- Use ONLY the facts provided in the FACTS section below. NEVER invent numbers, dates, addresses, names, or claims.
- If a fact is missing from FACTS, do not mention that topic.
- All currency in South African Rand. Use R format (R 1 250 000 — space separator, no decimals).
- All percentages rounded to 1 decimal.
- All dates in human-readable format (e.g. "May 2026" not "2026/05").
- Do not use the word "approximately" or "around" for numbers — be definitive with the data given.
- Do not start with "Dear" or any salutation. The summary is the first content the seller reads.
- 200-280 words. Do not exceed.
- Output plain text. No markdown headers or bullet points unless the variant prompt explicitly allows.
- Refer to the seller in second person ("your property", "you'll face") not third person.
- End with a clear next step or invitation to discuss.
TXT;

    private const FALLBACK_TEXT = '(AI summary unavailable — please regenerate or edit the static summary.)';

    public function __construct(
        private readonly AnthropicGateway $gateway = new AnthropicGateway(),
    ) {}

    /**
     * Collect every fact the AI is allowed to use. Returns a structured
     * array; the prompt builder renders it as text.
     */
    public function gatherFacts(Presentation $presentation, ?PresentationVersion $version = null): array
    {
        $version = $version ?? $presentation->versions()->latest('id')->first();
        $snapshot = $version?->getSnapshotArray() ?? [];
        $fields = $presentation->fields()->get()->keyBy('field_key');

        $property = $presentation->property;
        $agency = Agency::find($presentation->agency_id);
        $agent = $presentation->createdBy ?? null;

        // Pull the most-recent suburb stats (post-Phase 3e hydration writes
        // them into presentation_fields with suburb.* keys).
        $suburb = [
            'name'                   => $presentation->suburb,
            'year'                   => $fields->get('suburb.latest_year')?->final_value,
            'median_price'           => $this->numeric($fields->get('suburb.latest_median_price')?->final_value),
            'sales_count'            => $this->numeric($fields->get('suburb.latest_sales_count')?->final_value),
            'low_range'              => $this->numeric($fields->get('suburb.latest_low')?->final_value),
            'high_range'             => $this->numeric($fields->get('suburb.latest_high')?->final_value),
            'max_price'              => $this->numeric($fields->get('suburb.latest_max')?->final_value),
        ];

        $cma = [
            'lower'  => $this->numeric($fields->get('cma.lower_range')?->final_value),
            'middle' => $this->numeric($fields->get('cma.middle_range')?->final_value),
            'upper'  => $this->numeric($fields->get('cma.upper_range')?->final_value),
        ];

        $comps = $presentation->soldComps()->orderByDesc('sold_date')->limit(5)->get();
        $compsBlock = [
            'count'                    => $presentation->soldComps()->count(),
            'sample'                   => $comps->map(fn ($c) => [
                'address'    => is_string($c->raw_row_json) ? (json_decode($c->raw_row_json, true)['address'] ?? null) : null,
                'sale_price' => $c->sold_price_inc,
                'sale_date'  => optional($c->sold_date)->toDateString(),
                'extent_m2'  => $c->size_m2,
            ])->all(),
        ];
        if ($compsBlock['count'] > 0) {
            $allSold = $presentation->soldComps()->whereNotNull('sold_price_inc')->pluck('sold_price_inc');
            $sortedSold = $allSold->sort()->values();
            $count = $sortedSold->count();
            $compsBlock['median_sale_price']  = $count > 0 ? (int) $sortedSold[(int) floor($count / 2)] : null;
            $compsBlock['average_sale_price'] = $count > 0 ? (int) round($allSold->avg()) : null;
        }

        $active = $presentation->activeListings()->whereNotNull('list_price_inc')->get();
        $activeBlock = [
            'count'                  => $active->count(),
            'average_dom'            => null,
            'median_list_price'      => null,
            'sample'                 => $active->take(3)->map(fn ($a) => [
                'address'        => is_string($a->raw_row_json) ? (json_decode($a->raw_row_json, true)['address'] ?? null) : null,
                'list_price'     => $a->list_price_inc,
                'days_on_market' => is_string($a->raw_row_json) ? (json_decode($a->raw_row_json, true)['days_on_market'] ?? null) : null,
            ])->all(),
        ];
        if ($active->isNotEmpty()) {
            $prices = $active->pluck('list_price_inc')->sort()->values();
            $activeBlock['median_list_price'] = (int) ($prices[(int) floor($prices->count() / 2)] ?? 0);
        }

        // Stock absorption + holding cost come from the snapshot if compiled,
        // otherwise from presentation fields directly.
        $stock = $snapshot['analytics']['stock_absorption'] ?? [];
        $holdingCostMonthly = (int) ($presentation->monthly_rates ?? 0)
            + (int) ($presentation->monthly_levies ?? 0)
            + (int) ($presentation->monthly_insurance ?? 0)
            + (int) ($presentation->monthly_utilities ?? 0)
            + (int) ($presentation->monthly_opportunity_cost ?? 0)
            + (int) ($presentation->monthly_bond ?? 0);

        return [
            'property' => array_filter([
                'address'    => $presentation->property_address ?: $property?->address,
                'suburb'     => $presentation->suburb,
                'town'       => $property?->town,
                'type'       => $presentation->property_type,
                'bedrooms'   => $presentation->bedrooms,
                'bathrooms'  => $presentation->bathrooms,
                'extent_m2'  => $presentation->floor_area_m2 ?: $presentation->erf_size_m2,
            ], fn ($v) => $v !== null && $v !== ''),
            'asking' => [
                'price'  => $presentation->asking_price_inc !== null ? (int) $presentation->asking_price_inc : null,
                'source' => $presentation->asking_price_inc !== null ? 'agent-supplied' : null,
            ],
            'cma' => array_filter($cma, fn ($v) => $v !== null),
            'suburb' => array_filter($suburb, fn ($v) => $v !== null && $v !== ''),
            'comps' => array_filter($compsBlock, fn ($v) => $v !== null && $v !== [] && $v !== 0),
            'active_competition' => array_filter($activeBlock, fn ($v) => $v !== null && $v !== [] && $v !== 0),
            'absorption' => array_filter([
                'months_of_supply'         => $stock['months_of_supply']      ?? null,
                'sales_per_year'           => $stock['annual_sales']          ?? null,
                'monthly_sales'            => $stock['monthly_sales']         ?? null,
                'new_listings_per_month'   => $stock['new_listing_rate']      ?? null,
                'market_label'             => $stock['absorption_label']     ?? null,
            ], fn ($v) => $v !== null && $v !== ''),
            'holding_cost' => $holdingCostMonthly > 0 ? [
                'monthly_total'    => $holdingCostMonthly,
                'monthly_rates'    => (int) ($presentation->monthly_rates    ?? 0),
                'monthly_levy'     => (int) ($presentation->monthly_levies   ?? 0),
                'monthly_bond'     => (int) ($presentation->monthly_bond     ?? 0),
                'source_label'     => 'Estimated from agency defaults',
            ] : [],
            'pricing_scenarios' => $this->extractPricingScenarios($snapshot),
            'agent' => array_filter([
                'name'         => $agent?->name,
                'agency_name'  => $agency?->name,
                'agency_phone' => $agency?->phone,
                'agency_email' => $agency?->email,
            ], fn ($v) => $v !== null && $v !== ''),
            // Phase 3i — HFC sales we've made near the subject in the last 24mo.
            // Empty array (not null) when none; the prompt template skips empties.
            'hfc_neighbouring_sales' => $this->collectHfcNeighbouringSales($presentation),
        ];
    }

    /**
     * Phase 3i — HFC's own deals within 1km of the subject in the last 24 months.
     *
     * Pre-requisite: deals.property_id has been backfilled (Phase 3i) and the
     * neighbouring properties have GPS (Phase 3f geocoding cache). Returns
     * empty array when either condition isn't met for a given subject — the
     * prompt template silently omits the fact block.
     *
     * @return array{count:int, most_recent?:array{address:string,date:?string,price:?int,agent_name:?string}}
     */
    private function collectHfcNeighbouringSales(Presentation $presentation): array
    {
        $property = $presentation->property;
        if (!$property || $property->latitude === null || $property->longitude === null) {
            return [];
        }

        $cutoff = now()->subMonths(24)->toDateString();
        $lat = (float) $property->latitude;
        $lng = (float) $property->longitude;

        // Crude bbox prefilter — 1km ≈ 0.009° at this latitude. The Haversine
        // in PHP refines it. Skips SQL spatial functions to keep the query
        // portable across the dev/staging/prod MySQL versions.
        $deg = 0.01;
        $candidates = \App\Models\Deal::withoutGlobalScopes()
            // Qualify with the `deals` prefix: this query joins
            // `properties`, which ALSO has an `agency_id` column, so the
            // unqualified form is ambiguous (SQLSTATE[23000]). The
            // sale's agency is the semantically-correct filter (deal
            // ownership = closing agency, not asset listing-agency),
            // and matches the pre-withoutGlobalScopes() AgencyScope.
            ->where('deals.agency_id', $presentation->agency_id)
            ->whereNotNull('property_id')
            ->whereNotNull('sale_date')
            ->where('sale_date', '>=', $cutoff)
            ->join('properties', 'properties.id', '=', 'deals.property_id')
            ->whereBetween('properties.latitude', [$lat - $deg, $lat + $deg])
            ->whereBetween('properties.longitude', [$lng - $deg, $lng + $deg])
            ->where('deals.property_id', '!=', $property->id)
            ->select([
                'deals.id', 'deals.sale_date', 'deals.sale_price', 'deals.property_value',
                'properties.address as prop_address', 'properties.latitude', 'properties.longitude',
            ])
            ->orderByDesc('deals.sale_date')
            ->limit(20)
            ->get();

        $within = $candidates->filter(function ($row) use ($lat, $lng) {
            return $this->haversineKm($lat, $lng, (float) $row->latitude, (float) $row->longitude) <= 1.0;
        });

        if ($within->isEmpty()) {
            return [];
        }

        $most = $within->first();
        return [
            'count'       => $within->count(),
            'most_recent' => [
                'address'    => (string) $most->prop_address,
                'date'       => $most->sale_date ? (string) $most->sale_date : null,
                'price'      => $most->sale_price ? (int) $most->sale_price : ($most->property_value ? (int) round((float) $most->property_value) : null),
                'agent_name' => null,
            ],
        ];
    }

    private function haversineKm(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $R = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
        return 2 * $R * atan2(sqrt($a), sqrt(1 - $a));
    }

    /**
     * Render facts dictionary as human-readable text for the prompt.
     * NOT JSON — model uses prose facts more reliably for narrative output.
     */
    public function buildPrompt(string $variantKey, array $facts, ?string $countryName = 'South Africa'): string
    {
        $variant = PresentationAiVariant::where('key', $variantKey)->where('is_active', true)->firstOrFail();

        $system = str_replace('{agency_country}', $countryName, self::SYSTEM_PREFIX);
        $factsBlock = $this->renderFactsBlock($facts);

        $userPrompt = str_replace(
            ['{agency_country}', '{facts_block}'],
            [$countryName, $factsBlock],
            $variant->prompt_template,
        );

        return $system . "\n\n" . $userPrompt;
    }

    /**
     * Generate one summary. Stamps a history row regardless of success/fail.
     *
     * @return array{
     *   text: ?string,
     *   raw_text: ?string,
     *   variant_id: int,
     *   tokens_used: ?int,
     *   latency_ms: ?int,
     *   prompt_hash: string,
     *   model: ?string,
     *   history_id: int,
     *   from_cache: bool,
     *   from_fallback: bool,
     *   error: ?string,
     *   facts: array,
     * }
     */
    public function generate(
        Presentation $presentation,
        PresentationVersion $version,
        int $variantId,
        User $by,
        ?array $factsOverride = null,
    ): array {
        $variant = PresentationAiVariant::findOrFail($variantId);
        $facts = $factsOverride ?? $this->gatherFacts($presentation, $version);

        $system = str_replace('{agency_country}', 'South Africa', self::SYSTEM_PREFIX);
        $factsBlock = $this->renderFactsBlock($facts);
        $userPrompt = str_replace('{facts_block}', $factsBlock, $variant->prompt_template);

        $promptHash = hash('sha256', $system . "\n\n" . $userPrompt);
        $startedAt = microtime(true);

        $cacheKey = sprintf(
            'presentation_ai_summary:%d:%s:%s',
            $presentation->id,
            $variant->key,
            substr($promptHash, 0, 12),
        );

        $request = new NarrativeRequest(
            narrativeType:   'presentation_summary',
            cacheKey:        $cacheKey,
            modelAlias:      'quality',                  // Sonnet — narrative quality
            systemPrompt:    $system,
            userPrompt:      $userPrompt,
            inputData:       ['variant' => $variant->key, 'prompt_hash' => $promptHash],
            maxTokens:       $variant->max_tokens,
            temperature:     (float) $variant->temperature,
            cacheTtlMinutes: 60,                         // 1h cache; agent-driven regen sets forceRefresh=true
            agencyId:        (int) $presentation->agency_id,
            fallbackData:    ['text' => self::FALLBACK_TEXT],
            forceRefresh:    false,
            promptVersion:   $variant->key,
        );

        $response   = null;
        $errorMsg   = null;
        $latencyMs  = null;
        $tokensUsed = null;
        $model      = null;
        $rawText    = null;

        try {
            $response   = $this->gateway->generate($request);
            $latencyMs  = (int) round((microtime(true) - $startedAt) * 1000);
            $tokensUsed = ($response->inputTokens ?? 0) + ($response->outputTokens ?? 0);
            $model      = $response->model;
            $rawText    = trim((string) $response->outputText);
        } catch (\Throwable $e) {
            $latencyMs = (int) round((microtime(true) - $startedAt) * 1000);
            $errorMsg  = mb_substr($e->getMessage(), 0, 500);
            Log::warning('AiSummaryService::generate threw — recording history row', [
                'presentation_id' => $presentation->id,
                'variant'         => $variant->key,
                'err'             => $errorMsg,
            ]);
        }

        // Treat fallback text as a failure for our purposes — we don't want
        // to serve "AI summary unavailable" to a seller as if it were real.
        $isFallback = $response?->fromFallback ?? ($rawText === self::FALLBACK_TEXT);

        $history = PresentationAiSummaryHistory::create([
            'presentation_id'         => $presentation->id,
            'presentation_version_id' => $version->id,
            'ai_variant_id'           => $variant->id,
            'generated_text'          => $isFallback ? null : $rawText,
            'generated_at'            => now(),
            'generated_by_user_id'    => $by->id,
            'was_saved'               => false,
            'tokens_used'             => $tokensUsed,
            'latency_ms'              => $latencyMs,
            'failure_reason'          => $isFallback
                ? ($errorMsg ?: ($response?->errorMessage ?: 'fallback_emitted'))
                : null,
            'prompt_hash'             => $promptHash,
            'model'                   => $model,
            'created_at'              => now(),
        ]);

        $wordCount = $rawText ? str_word_count($rawText) : 0;
        if (!$isFallback && $wordCount > 350) {
            Log::warning('AiSummaryService: output exceeded soft word budget', [
                'history_id' => $history->id,
                'words'      => $wordCount,
            ]);
        }

        return [
            'text'          => $isFallback ? null : $rawText,
            'raw_text'      => $isFallback ? null : $rawText,
            'variant_id'    => $variant->id,
            'tokens_used'   => $tokensUsed,
            'latency_ms'    => $latencyMs,
            'prompt_hash'   => $promptHash,
            'model'         => $model,
            'history_id'    => $history->id,
            'from_cache'    => $response?->fromCache ?? false,
            'from_fallback' => $isFallback,
            'error'         => $isFallback ? ($errorMsg ?: ($response?->errorMessage ?: 'fallback emitted')) : null,
            'facts'         => $facts,
            'word_count'    => $wordCount,
        ];
    }

    /**
     * Auto-generate the default-tone (first active by sort_order)
     * Executive Summary for a freshly-created version + accept it
     * immediately. The "presentation is auto, agent overrides" model
     * means every version ships with a real summary by default;
     * the agent re-picks tone via the Exec Summary panel to override.
     *
     * NEVER throws. Any AI failure (budget cap, network, fallback,
     * variant missing) is silently logged and leaves `ai_summary_text`
     * null — the PDF readiness gate then blocks Compile/Download
     * until the agent regenerates. This is intentional: the caller
     * (PresentationGeneratorService) wraps an entire DB transaction
     * around generate, and a thrown AI error would roll back the
     * whole presentation. Soft-fail is the right behaviour.
     */
    public function generateDefaultAndAccept(Presentation $presentation, PresentationVersion $version, User $user): void
    {
        try {
            $defaultVariantId = PresentationAiVariant::where('is_active', true)
                ->orderBy('sort_order')
                ->value('id');
            if (!$defaultVariantId) {
                Log::warning('AiSummaryService::generateDefaultAndAccept — no active variant configured', [
                    'presentation_id' => $presentation->id,
                    'version_id'      => $version->id,
                ]);
                return;
            }

            $result = $this->generate($presentation, $version, $defaultVariantId, $user);

            // Only accept when the gateway returned real text (not the
            // fallback "(AI summary unavailable…)" placeholder). Fallback
            // means the AI was unreachable or budget-capped; leaving
            // ai_summary_text null lets the readiness gate block the PDF
            // until the agent regenerates from the panel.
            if (!$result['from_fallback'] && !empty($result['text'])) {
                $this->acceptForVersion($version, $result['history_id']);
            }
        } catch (\Throwable $e) {
            Log::warning('AiSummaryService::generateDefaultAndAccept threw — leaving summary null', [
                'presentation_id' => $presentation->id,
                'version_id'      => $version->id,
                'error'           => mb_substr($e->getMessage(), 0, 300),
            ]);
        }
    }

    /**
     * Lock the chosen history row's text into the version snapshot. If
     * $editedText differs from history.generated_text, flag the edit.
     */
    public function acceptForVersion(PresentationVersion $version, int $historyId, ?string $editedText = null): void
    {
        $history = PresentationAiSummaryHistory::with('variant')->findOrFail($historyId);
        if ($history->presentation_id !== $version->presentation_id) {
            throw new \InvalidArgumentException('History row does not belong to this presentation.');
        }

        $finalText = trim($editedText ?? (string) $history->generated_text);
        if ($finalText === '') {
            throw new \InvalidArgumentException('Final summary text cannot be empty.');
        }

        $edited = $editedText !== null && trim((string) $editedText) !== trim((string) $history->generated_text);

        DB::transaction(function () use ($version, $history, $finalText, $edited) {
            $version->forceFill([
                'ai_variant_id'              => $history->ai_variant_id,
                'ai_summary_text'            => $finalText,
                'ai_summary_raw_text'        => $history->generated_text,
                'ai_summary_edited_by_agent' => $edited,
                'ai_summary_generated_at'    => $history->generated_at,
                'ai_summary_edited_at'       => $edited ? now() : null,
                'ai_summary_model'           => $history->model,
                'ai_summary_prompt_hash'     => $history->prompt_hash,
            ])->save();

            // Mark this history row as accepted, others for this version
            // remain (their was_saved=false stamp is preserved).
            $history->forceFill([
                'was_saved'               => true,
                'presentation_version_id' => $version->id,
            ])->save();
        });
    }

    // ── Internals ───────────────────────────────────────────────────────────

    /**
     * Render facts dict into a human-readable block. Skips empty sections.
     */
    private function renderFactsBlock(array $facts): string
    {
        $out = [];

        $p = $facts['property'] ?? [];
        if (!empty($p['address'])) {
            $bits = [];
            $bits[] = $p['address'];
            if (!empty($p['type'])) $bits[] = $p['type'];
            $bedBath = [];
            if (!empty($p['bedrooms']))  $bedBath[] = $p['bedrooms']  . ' bed';
            if (!empty($p['bathrooms'])) $bedBath[] = $p['bathrooms'] . ' bath';
            if ($bedBath) $bits[] = implode(' / ', $bedBath);
            if (!empty($p['extent_m2'])) $bits[] = $p['extent_m2'] . ' m²';
            $out[] = 'Property: ' . implode(', ', $bits);
        }

        if (!empty($facts['asking']['price'])) {
            $out[] = 'Asking Price: ' . $this->zar($facts['asking']['price']);
        }

        $c = $facts['cma'] ?? [];
        if (!empty($c['lower']) || !empty($c['upper'])) {
            // ?? null on each bound — the outer || passes when ONLY ONE of
            // lower/upper is set, and the array_filter at L154 strips the
            // missing one. zar() already handles null (returns 'R 0').
            $out[] = 'CMA Evaluation Range: ' . $this->zar($c['lower'] ?? null) . ' to ' . $this->zar($c['upper'] ?? null)
                . (!empty($c['middle']) ? ' (middle ' . $this->zar($c['middle']) . ')' : '');
        }

        $s = $facts['suburb'] ?? [];
        if (!empty($s['name'])) {
            $out[] = '';
            // Guard every $s[...] read — gatherFacts wraps the suburb dict
            // in array_filter at L155, which STRIPS null-valued keys, so any
            // missing field (e.g. no suburb.latest_year hydrated) throws
            // "Undefined array key" under strict error handling. Same class
            // of bug fixed in two other places below (CMA, agent).
            $out[] = 'Suburb (' . $s['name'] . (!empty($s['year']) ? ' ' . $s['year'] : '') . '):';
            if (!empty($s['sales_count']))  $out[] = '  - ' . $s['sales_count'] . ' residential sales';
            if (!empty($s['median_price'])) $out[] = '  - Median sale price ' . $this->zar($s['median_price']);
            if (!empty($s['low_range']) && !empty($s['high_range'])) {
                $out[] = '  - Range ' . $this->zar($s['low_range']) . ' to ' . $this->zar($s['high_range']);
            }
            if (!empty($s['max_price'])) {
                $out[] = '  - Highest sale ' . $this->zar($s['max_price']);
            }
        }

        $cm = $facts['comps'] ?? [];
        if (!empty($cm['count'])) {
            $out[] = '';
            $out[] = 'Comparable Sales (' . $cm['count'] . ' in the area in the last 12 months):';
            if (!empty($cm['median_sale_price']))  $out[] = '  - Median sale ' . $this->zar($cm['median_sale_price']);
            if (!empty($cm['average_sale_price'])) $out[] = '  - Average sale ' . $this->zar($cm['average_sale_price']);
            foreach (($cm['sample'] ?? []) as $sample) {
                if (empty($sample['sale_price'])) continue;
                $when = !empty($sample['sale_date']) ? \Carbon\Carbon::parse($sample['sale_date'])->format('M Y') : '';
                $out[] = '  - ' . ($sample['address'] ?? 'Comparable')
                    . ' — ' . $this->zar($sample['sale_price'])
                    . ($when ? ' (' . $when . ')' : '')
                    . (!empty($sample['extent_m2']) ? ', ' . $sample['extent_m2'] . ' m²' : '');
            }
        }

        $a = $facts['active_competition'] ?? [];
        if (!empty($a['count'])) {
            $out[] = '';
            $out[] = 'Active Competition: ' . $a['count'] . ' competing listing' . ($a['count'] === 1 ? '' : 's');
            if (!empty($a['median_list_price'])) $out[] = '  - Median asking ' . $this->zar($a['median_list_price']);
            foreach (($a['sample'] ?? []) as $sample) {
                if (empty($sample['list_price'])) continue;
                $dom = !empty($sample['days_on_market']) ? ', ' . $sample['days_on_market'] . ' days on market' : '';
                $out[] = '  - ' . ($sample['address'] ?? 'Listing') . ' — ' . $this->zar($sample['list_price']) . $dom;
            }
        }

        $ab = $facts['absorption'] ?? [];
        if (!empty($ab['months_of_supply']) || !empty($ab['market_label'])) {
            $out[] = '';
            $line = 'Market Absorption:';
            if (!empty($ab['months_of_supply']))       $line .= ' ' . number_format((float) $ab['months_of_supply'], 1) . ' months of supply';
            if (!empty($ab['monthly_sales']))           $line .= ', ' . number_format((float) $ab['monthly_sales'], 1) . ' sales/month';
            if (!empty($ab['market_label']))            $line .= ', ' . $ab['market_label'];
            $out[] = $line;
            if (!empty($ab['new_listings_per_month'])) {
                $out[] = '  - New listings entering: ' . number_format((float) $ab['new_listings_per_month'], 1) . '/month';
            }
        }

        $hc = $facts['holding_cost'] ?? [];
        if (!empty($hc['monthly_total'])) {
            $parts = [];
            if (!empty($hc['monthly_bond']))  $parts[] = 'bond ' . $this->zar($hc['monthly_bond']);
            if (!empty($hc['monthly_rates'])) $parts[] = 'rates ' . $this->zar($hc['monthly_rates']);
            if (!empty($hc['monthly_levy']))  $parts[] = 'levy ' . $this->zar($hc['monthly_levy']);
            $out[] = '';
            $out[] = 'Holding Cost: ' . $this->zar($hc['monthly_total']) . '/month'
                . ($parts ? ' (' . implode(', ', $parts) . ')' : '')
                . ' — ' . ($hc['source_label'] ?? 'estimated');
        }

        $ps = $facts['pricing_scenarios'] ?? [];
        if (!empty($ps)) {
            $out[] = '';
            $out[] = 'Pricing Scenarios available:';
            foreach ($ps as $row) {
                $line = '  - ' . ($row['name'] ?? 'scenario') . ' ' . $this->zar($row['price'] ?? 0);
                if (!empty($row['net_proceeds']))    $line .= ' → net proceeds ' . $this->zar($row['net_proceeds']);
                if (!empty($row['months_to_sell'])) $line .= ', sell in ' . $row['months_to_sell'] . ' months';
                if (!empty($row['probability_label'])) $line .= ', ' . $row['probability_label'];
                $out[] = $line;
            }
        }

        // Phase 3i — HFC's own sales near the subject.
        $hfc = $facts['hfc_neighbouring_sales'] ?? [];
        if (!empty($hfc['count'])) {
            $out[] = '';
            $line = 'HFC has sold ' . (int) $hfc['count']
                . ' propert' . ((int) $hfc['count'] === 1 ? 'y' : 'ies')
                . ' within 1km of the subject in the last 24 months';
            $recent = $hfc['most_recent'] ?? null;
            if ($recent && !empty($recent['address'])) {
                $line .= '. Most recent: ' . $recent['address'];
                if (!empty($recent['price'])) {
                    $line .= ' at ' . $this->zar((int) $recent['price']);
                }
                if (!empty($recent['date'])) {
                    $line .= ' in ' . \Carbon\Carbon::parse($recent['date'])->format('M Y');
                }
                $line .= '.';
            }
            $out[] = $line;
            $out[] = '  (You may reference this as a market-familiarity proof point.)';
        }

        $g = $facts['agent'] ?? [];
        if (!empty($g['name']) || !empty($g['agency_name'])) {
            $out[] = '';
            // !empty guard on agency_name — outer || passes when ONLY name
            // is set, and array_filter at L173-178 strips a missing
            // agency_name key entirely. Same bug class as the suburb year +
            // CMA bounds above.
            $out[] = 'Agent: ' . ($g['name'] ?? 'Agent') . (!empty($g['agency_name']) ? ' at ' . $g['agency_name'] : '');
        }

        return implode("\n", $out);
    }

    private function zar(int|float|null $value): string
    {
        if ($value === null || $value <= 0) return 'R 0';
        return 'R ' . number_format((int) $value, 0, '.', ' ');
    }

    private function numeric(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        $clean = preg_replace('/[^\d.]/', '', (string) $value);
        if ($clean === '' || $clean === '.') return null;
        return (int) round((float) $clean);
    }

    /**
     * Extract up to 4 pricing scenarios from the snapshot, in a stable order.
     * Snapshot format: $snapshot['pricing_scenarios'][] = { name, price, net_proceeds, ... }
     * (Phase 2 schema). Returns simplified entries with only fields the AI needs.
     */
    private function extractPricingScenarios(array $snapshot): array
    {
        $raw = $snapshot['pricing_scenarios'] ?? [];
        if (!is_array($raw) || empty($raw)) return [];
        $out = [];
        foreach (array_slice($raw, 0, 4) as $s) {
            if (empty($s['name']) && empty($s['price'])) continue;
            $out[] = array_filter([
                'name'              => $s['name']             ?? null,
                'price'             => $s['price']            ?? null,
                'net_proceeds'      => $s['net_proceeds']     ?? null,
                'months_to_sell'    => $s['est_months']       ?? ($s['months_to_sell'] ?? null),
                'probability_label' => $s['probability']      ?? ($s['probability_label'] ?? null),
            ], fn ($v) => $v !== null && $v !== '');
        }
        return $out;
    }
}
