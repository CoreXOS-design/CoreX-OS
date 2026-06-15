<?php

namespace App\Services\Presentations;

use App\Models\Presentation;
use App\Models\PresentationVersion;
use App\Support\Presentations\CompLabel;
use Illuminate\Support\Facades\Storage;

/**
 * Generates a printable HTML pack from a PresentationVersion snapshot (P18).
 *
 * No external PDF library is required. The output is a self-contained HTML
 * document that browsers can print to PDF (Ctrl+P → Save as PDF).
 *
 * Storage path: presentations/{presentationId}/versions/{versionId}.html
 */
class PresentationPdfService
{
    public const STORAGE_DISK = 'local';

    /**
     * Generate the pack HTML, persist it to storage, and return the stored path.
     *
     * @return string Storage path relative to the disk root.
     */
    public function generate(PresentationVersion $version): string
    {
        $html = $this->buildHtml($version);

        $path = $this->storagePath($version);

        Storage::disk(self::STORAGE_DISK)->put($path, $html);

        return $path;
    }

    /**
     * Return the canonical storage path for a version's pack file.
     */
    public function storagePath(PresentationVersion $version): string
    {
        return sprintf(
            'presentations/%d/versions/%d.html',
            $version->presentation_id,
            $version->id,
        );
    }

    /**
     * B2 — Executive Summary payload.
     *
     * Pure-data builder for the Exec Summary + five-bullet primary page,
     * per .ai/specs/seller-report-restructure.md. Resolves every {token}
     * in spec §3 from canonical $data keys (B0a + B0b post-rewire), runs
     * the §4c above_clause conditional, and computes a $sectionIndex map
     * cover+summary-offset = 2 (cover p.1, exec summary p.2, beats start
     * p.3). Beat-suppression rule per spec §7 recomputes downstream page
     * refs so a no-comps subject (Bullet 2 + Beat 2 suppressed) leaves
     * Bullets 3/4/5 pointing to the right pages.
     *
     * No HTML. Unit-testable in isolation — the render layer in
     * buildHtml() consumes this payload.
     *
     * @param  Presentation         $presentation
     * @param  PresentationVersion  $version
     * @param  array<string, mixed> $data  Output of AnalysisDataService::compile
     * @return array{
     *   tone_text: ?string,
     *   bullets: array<int, array{key: string, suppressed: bool, ref: string, html: string}>,
     *   section_index: array<string, int>,
     *   tokens: array<string, mixed>,
     *   well_priced: bool,
     * }
     */
    public function buildSummaryPayload(
        \App\Models\Presentation $presentation,
        \App\Models\PresentationVersion $version,
        array $data,
    ): array {
        // ── Canonical data sources (post B0a + B0b) ─────────────────────
        $subject       = $data['subject_property']   ?? [];
        $cma           = $data['cma_valuation']      ?? [];
        $cmaComputed   = $data['cma_computed']       ?? [];
        $holdingCost   = $data['holding_cost']       ?? [];
        $compStock     = $data['competitor_stock']   ?? [];
        $comparable    = $data['comparable_sales']   ?? [];

        $askingPrice   = isset($subject['asking_price'])
            ? (int) $subject['asking_price']
            : ($presentation->asking_price_inc !== null ? (int) $presentation->asking_price_inc : null);
        $cmaMiddle     = $cma['cma_middle'] !== null ? (int) $cma['cma_middle'] : null;
        $cmaUpper      = $cma['cma_upper']  !== null ? (int) $cma['cma_upper']  : null;
        $monthlyTotal  = $holdingCost['monthly_total'] !== null ? (int) round((float) $holdingCost['monthly_total']) : 0;
        $competingCount = (int) ($compStock['competing_count'] ?? 0);

        // ── §3 Bullet 1 tokens — subject identity ───────────────────────
        $beds       = $subject['bedrooms']     ?? null;
        $baths      = $presentation->bathrooms ?? null;
        $extent     = $subject['extent_m2']    ?? null;
        $rawType    = $subject['property_type'] ?? null;
        $type       = $rawType ? \Illuminate\Support\Str::humanType($rawType) : 'property';
        $suburb     = $subject['suburb'] ?? $presentation->suburb ?? '';
        $address    = $subject['address'] ?? $presentation->property_address ?? '';

        // ── §3 Bullet 2 tokens — cleaned, size-matched sold pool (§4a) ──
        // Bind to cma_computed.pool_stats min/max (post recency + IQR
        // cleaning) so the seller sees the spread of REAL sales after
        // outliers fall out, not the raw vicinity range.
        $poolStats   = $cmaComputed['pool_stats'] ?? [];
        $soldLow     = $poolStats['min']      ?? null;
        $soldHigh    = $poolStats['max']      ?? null;
        $soldCount   = (int) ($poolStats['n_after_outlier_cut'] ?? $poolStats['n_total'] ?? 0);

        // Closest-match comp — smallest |Δsize| then most recent. Survives
        // the same cleaning the pool went through (we read off
        // comparable_sales which is the unsupressed pool; the cleaning
        // only excludes from the percentile maths, not from the table).
        [$matchDescriptor, $matchPrice] = $this->resolveClosestComp($comparable, $extent);

        // ── §3 Bullet 3 tokens — competing stock ────────────────────────
        $visible      = $compStock['visible'] ?? [];
        $visiblePrices = array_values(array_filter(
            array_map(static fn (array $r) => isset($r['price']) ? (int) $r['price'] : 0, $visible),
            static fn (int $p) => $p > 0,
        ));
        $compLow      = $visiblePrices === [] ? null : min($visiblePrices);
        $compHigh     = $visiblePrices === [] ? null : max($visiblePrices);
        $domValues    = array_values(array_filter(
            array_map(static fn (array $r) => isset($r['days_on_market']) ? (int) $r['days_on_market'] : null, $visible),
            static fn (?int $v) => $v !== null && $v > 0,
        ));
        $longestDom   = $domValues === [] ? null : max($domValues);

        // ── §3 Bullet 4 tokens — recommendation = the EVALUATED VALUE (the
        // comp-median, R2,520,000 in the Grindewald case). PRES-CMA-SELLER-
        // VOICE: the above-clause is anchored on the evaluated value (middle),
        // NOT the upper band — pre-fix asking ≤ upper read an over-market
        // asking as "priced right in the band", contradicting the recommended
        // figure on the same line. "Well-priced" now means asking ≤ the
        // evaluated value (genuinely fine); above it, the market's voice says
        // so plainly.
        $recommendedPrice = $cmaMiddle;
        $aboveClause      = $this->buildAboveClause($askingPrice, $cmaMiddle, $soldHigh, $compHigh, $competingCount);
        $wellPriced       = $aboveClause['well_priced'];

        // ── §3 Bullet 5 — waiting cost from canonical (B0a) ─────────────
        $holdingMonthly = $monthlyTotal;

        // ── §5 sectionIndex — fixed beat order, cover+summary offset = 2 ──
        // Beats 1-5 occupy sequential page positions when all present.
        // Beat-suppression rule per spec §7: a beat with no data still
        // renders the beat HEADER as a placeholder so navigation stays
        // intact — but its bullet is suppressed AND the index recomputes
        // to skip it so downstream refs stay correct.
        $beatOrder = ['your_property', 'sold', 'competition', 'recommendation', 'waiting'];
        $beatPresent = [
            'your_property'  => true,                                   // always renders (subject identity)
            'sold'           => $soldCount > 0 && $soldLow !== null,    // §7: no comps → Beat 2 suppressed
            'competition'    => $competingCount > 0,                    // §7: empty visible → Beat 3 verdict suppressed
            'recommendation' => $recommendedPrice !== null,             // §7: no CMA middle → Beat 4 suppressed
            'waiting'        => $holdingMonthly > 0,                    // §7: zero monthly_total → Beat 5 suppressed
        ];

        // B2-followup-2 — per-beat page sizes calibrated to the post-
        // reorder physical layout. Beats are no longer uniform 1-page
        // blocks: Beat 2 (Market Overview + Recent Sales table + sale-
        // trend chart + Spatial map) spans roughly 3 pages, Beat 3
        // (Active Competition + Scored Competitor cards) spans roughly
        // 2 pages. Beats 1, 4, 5 each fit on one page in the common
        // case. These are approximations — Chromium pagination depends
        // on exact content size — but they're MUCH closer to physical
        // truth than the previous uniform 1-page-per-beat assumption.
        // Suppressed beats (per beatPresent above) consume zero pages,
        // so a no-comps subject's Beat 3/4/5 refs shift up correctly.
        $beatSize = [
            'your_property'  => 1,
            'sold'           => 3,
            'competition'    => 2,
            'recommendation' => 1,
            'waiting'        => 1,
        ];
        $sectionIndex = [];
        $pageCursor   = 3; // cover=1, exec summary=2, beats start at 3
        foreach ($beatOrder as $beat) {
            $sectionIndex[$beat] = $pageCursor;
            if ($beatPresent[$beat]) {
                $pageCursor += $beatSize[$beat] ?? 1;
            }
            // Suppressed beats consume zero pages — the next beat lands
            // on the same page the suppressed one would have started on.
        }

        // ── Bullets — locked copy from spec §3, token substitution + ref ──
        $bullets = [];

        // Bullet 1 — locked: identity. Always renders.
        $bullets[] = [
            'key'        => 'your_property',
            'suppressed' => false,
            'ref'        => 'p.' . $sectionIndex['your_property'],
            'html'       => $this->bulletPropertyHtml($beds, $baths, $extent, $type, $suburb, $address),
        ];

        // Bullet 2 — sold band. Suppressed when no cleaned pool.
        $bullets[] = [
            'key'        => 'sold',
            'suppressed' => !$beatPresent['sold'],
            'ref'        => 'p.' . $sectionIndex['sold'],
            'html'       => $this->bulletSoldHtml($soldLow, $soldHigh, $matchDescriptor, $matchPrice),
        ];

        // Bullet 3 — competing stock. Suppressed when empty visible.
        $bullets[] = [
            'key'        => 'competition',
            'suppressed' => !$beatPresent['competition'],
            'ref'        => 'p.' . $sectionIndex['competition'],
            'html'       => $this->bulletCompetingHtml($competingCount, $compLow, $compHigh, $longestDom),
        ];

        // Bullet 4 — recommendation + conditional above-clause.
        $bullets[] = [
            'key'        => 'recommendation',
            'suppressed' => !$beatPresent['recommendation'],
            'ref'        => 'p.' . $sectionIndex['recommendation'],
            'html'       => $this->bulletRecommendationHtml($recommendedPrice, $askingPrice, $aboveClause),
        ];

        // Bullet 5 — waiting cost. Softens when well-priced (no pressure).
        $bullets[] = [
            'key'        => 'waiting',
            'suppressed' => !$beatPresent['waiting'],
            'ref'        => 'p.' . $sectionIndex['waiting'],
            'html'       => $this->bulletWaitingHtml($holdingMonthly, $wellPriced),
        ];

        // ── AI tone prose — figure-free per spec §2. Pass through
        // verbatim; the AI prompt enforces the no-numbers contract.
        // Legacy versions with hard figures in their stored text still
        // render — the bullets carry the load and the prose reads as
        // narrative context.
        $toneText = $version->ai_summary_text ? (string) $version->ai_summary_text : null;

        return [
            'tone_text'     => $toneText,
            'bullets'       => $bullets,
            'section_index' => $sectionIndex,
            'tokens'        => [
                'beds'             => $beds,
                'baths'            => $baths,
                'extent_m2'        => $extent,
                'type'             => $type,
                'suburb'           => $suburb,
                'address'          => $address,
                'sold_low'         => $soldLow,
                'sold_high'        => $soldHigh,
                'sold_count'       => $soldCount,
                'match_descriptor' => $matchDescriptor,
                'match_price'      => $matchPrice,
                'competing_count'  => $competingCount,
                'comp_low'         => $compLow,
                'comp_high'        => $compHigh,
                'longest_dom'      => $longestDom,
                'recommended_price'=> $recommendedPrice,
                'asking_price'     => $askingPrice,
                'above_clause'     => $aboveClause['text'],
                'holding_monthly'  => $holdingMonthly,
            ],
            'well_priced'   => $wellPriced,
        ];
    }

    /**
     * Spec §4a — closest comp by smallest |Δsize| then most recent.
     * Reads from comparable_sales.vicinity.rows (B0a-era shape; rows
     * carry address / sale_price / sale_date / extent_m2). Returns
     * [descriptor, price] or [null, null] when no usable comp exists.
     */
    private function resolveClosestComp(array $comparable, ?int $subjectExtent): array
    {
        $rows = $comparable['vicinity']['rows'] ?? [];
        if ($rows === []) return [null, null];

        $candidates = array_values(array_filter($rows, static function (array $r): bool {
            return !empty($r['sale_price']) && (int) $r['sale_price'] > 0;
        }));
        if ($candidates === []) return [null, null];

        usort($candidates, static function (array $a, array $b) use ($subjectExtent): int {
            $aDiff = $subjectExtent !== null && !empty($a['extent_m2'])
                ? abs((int) $a['extent_m2'] - $subjectExtent)
                : PHP_INT_MAX;
            $bDiff = $subjectExtent !== null && !empty($b['extent_m2'])
                ? abs((int) $b['extent_m2'] - $subjectExtent)
                : PHP_INT_MAX;
            if ($aDiff !== $bDiff) return $aDiff <=> $bDiff;
            // tiebreak: most recent date wins
            return strcmp((string) ($b['sale_date'] ?? ''), (string) ($a['sale_date'] ?? ''));
        });

        $best = $candidates[0];
        $extent = !empty($best['extent_m2']) ? (int) $best['extent_m2'] : null;
        $descriptor = $extent !== null
            ? "a {$extent} m² home, " . ($extent === $subjectExtent ? 'the same size as yours' : 'closest in size to yours')
            : 'the closest match nearby';
        return [$descriptor, (int) $best['sale_price']];
    }

    /**
     * Spec §4c — conditional above_clause. Tells the truth for every
     * pricing state including well-priced and under-priced; NEVER
     * asserts a falsehood. Returns a small struct so the bullet
     * renderer can flip to the well-priced branch entirely.
     *
     * @return array{text: string, well_priced: bool, above_competition: bool, above_sold: bool}
     */
    private function buildAboveClause(?int $askingPrice, ?int $evaluatedValue, ?int $soldHigh, ?int $compHigh, int $competingCount): array
    {
        // Well-priced: asking ≤ the EVALUATED VALUE (the comp-median).
        // PRES-CMA-SELLER-VOICE — anchored on the evaluated value, NOT the
        // upper band, so an above-market asking is never reassured as "in the
        // band". Spec §4c — softens the close only when genuinely well-placed.
        if ($askingPrice === null || $evaluatedValue === null) {
            return ['text' => '', 'well_priced' => false, 'above_competition' => false, 'above_sold' => false];
        }
        if ($askingPrice <= $evaluatedValue) {
            return [
                'text'              => '',
                'well_priced'       => true,
                'above_competition' => false,
                'above_sold'        => false,
            ];
        }

        $aboveCompetition = $compHigh !== null && $askingPrice > $compHigh && $competingCount > 0;
        $aboveSold        = $soldHigh !== null && $askingPrice > $soldHigh;

        $parts = [];
        if ($aboveCompetition) {
            $parts[] = sprintf(
                'all %d %s you\'re competing with',
                $competingCount,
                $competingCount === 1 ? 'home' : 'homes',
            );
        }
        if ($aboveSold) {
            $parts[] = 'everything similar that\'s sold';
        }

        if ($parts === []) {
            // asking > cmaUpper but no competitor / sold ceiling to assert
            // against — keep the truth narrow and just say "the
            // recommended band".
            return [
                'text'              => 'the recommended band',
                'well_priced'       => false,
                'above_competition' => false,
                'above_sold'        => false,
            ];
        }

        return [
            'text'              => implode(' and ', $parts),
            'well_priced'       => false,
            'above_competition' => $aboveCompetition,
            'above_sold'        => $aboveSold,
        ];
    }

    // ── Bullet HTML builders — copy frozen by spec §3 ───────────────────

    private function bulletPropertyHtml(?int $beds, ?int $baths, ?int $extent, string $type, string $suburb, string $address): string
    {
        $esc = static fn (?string $s) => htmlspecialchars((string) ($s ?? ''), ENT_QUOTES, 'UTF-8');
        $parts = [];
        if ($beds   !== null && $beds   > 0) $parts[] = $beds   . '-bed';
        if ($baths  !== null && $baths  > 0) $parts[] = $baths  . '-bath';
        if ($extent !== null && $extent > 0) $parts[] = number_format($extent) . ' m²';
        $descriptor = $parts === [] ? '' : '<strong>' . implode(', ', $parts) . '</strong> ';
        $where = $suburb !== '' ? ' in ' . $esc($suburb) : '';
        return $descriptor . $esc($type) . ($address !== '' ? ' — ' . $esc($address) : '') . $where . '.';
    }

    private function bulletSoldHtml(?int $low, ?int $high, ?string $matchDescriptor, ?int $matchPrice): string
    {
        if ($low === null || $high === null) {
            return 'No comparable sales nearby have completed yet — your value reads from the live market only.';
        }
        $core = sprintf(
            'Homes like yours sold for between <strong>%s</strong> and <strong>%s</strong>.',
            $this->zarFormat($low),
            $this->zarFormat($high),
        );
        if ($matchDescriptor !== null && $matchPrice !== null) {
            $core .= sprintf(
                ' The closest match — %s — sold for <strong>%s</strong>.',
                htmlspecialchars($matchDescriptor, ENT_QUOTES, 'UTF-8'),
                $this->zarFormat($matchPrice),
            );
        }
        return $core;
    }

    private function bulletCompetingHtml(int $count, ?int $low, ?int $high, ?int $longestDom): string
    {
        if ($count === 0) {
            return 'There are no scored competitors at your price level right now — you set the pace.';
        }
        $core = sprintf(
            'There are <strong>%d similar %s</strong> for sale near you right now',
            $count,
            $count === 1 ? 'home' : 'homes',
        );
        if ($low !== null && $high !== null) {
            $core .= sprintf(', from <strong>%s to %s</strong>', $this->zarFormat($low), $this->zarFormat($high));
        }
        if ($longestDom !== null && $longestDom > 0) {
            $core .= sprintf(
                ' — and one nearby has sat unsold for <strong>%d days</strong>',
                $longestDom,
            );
        }
        return $core . '.';
    }

    private function bulletRecommendationHtml(?int $recommended, ?int $asking, array $aboveClause): string
    {
        if ($recommended === null) {
            return 'A recommended price requires a CMA — once your comps and condition land, we can pin a specific number.';
        }
        $core = sprintf(
            'To <strong>sell</strong> — not just to list — your home fits best at <strong>%s</strong>.',
            $this->zarFormat($recommended),
        );
        if ($asking === null) {
            return $core;
        }
        // PRES-CMA-SELLER-VOICE — let the market deliver the message, never the
        // agent judging. Well-priced (asking ≤ evaluated value) gets a fair,
        // un-pressured line; above the evaluated value states plainly that the
        // asking is over what homes like this have actually sold for, and
        // points back to the evaluated value as where buyers act.
        if ($aboveClause['well_priced']) {
            return $core . sprintf(
                ' At <strong>%s</strong> you\'re priced at or below what homes like yours have sold for — right where buyers are active.',
                $this->zarFormat($asking),
            );
        }
        return $core . sprintf(
            ' At <strong>%s</strong> you\'re asking more than homes like yours have actually sold for — they\'ve been selling around <strong>%s</strong>. Pricing closer to that is what brings buyers.',
            $this->zarFormat($asking),
            $this->zarFormat($recommended),
        );
    }

    private function bulletWaitingHtml(int $monthly, bool $wellPriced): string
    {
        if ($monthly <= 0) {
            return 'Once your holding costs are captured we can show you what each month of waiting costs.';
        }
        $core = sprintf(
            'Every month unsold costs about <strong>%s</strong>.',
            $this->zarFormat($monthly),
        );
        // Spec §4c — softer close for well-priced subjects (no pressure).
        if ($wellPriced) {
            return $core . ' At today\'s pricing that\'s a working figure, not a warning.';
        }
        return $core . ' Pricing it right today usually means <strong>the same money — or more — in your pocket, sooner.</strong>';
    }

    /**
     * Local helper — matches the rest of the file's $zar formatter so the
     * Exec Summary numbers print identically to every other tile / table.
     */
    private function zarFormat(?int $val): string
    {
        if ($val === null || $val === 0) return '—';
        return 'R ' . number_format($val, 0, '.', ' ');
    }

    /**
     * Build the full HTML document from the presentation + analysis data.
     */
    public function buildHtml(PresentationVersion $version): string
    {
        // Load the presentation with all relations
        $presentation = Presentation::with([
            'fields', 'soldComps', 'activeListings', 'links', 'articles',
        ])->findOrFail($version->presentation_id);

        // Get the agent who created this presentation
        $agent = \App\Models\User::find($presentation->created_by_user_id);
        $agentName = $agent->name ?? 'Agent';
        $agentEmail = $agent->email ?? '';
        $agentPhone = $agent->cell ?? $agent->phone ?? '';
        $agentDesignation = $agent->designation ?? 'Property Practitioner';
        $agentPhotoPath = null;
        if ($agent && $agent->agent_photo_path && file_exists(storage_path('app/public/' . $agent->agent_photo_path))) {
            $agentPhotoPath = 'data:image/' . pathinfo($agent->agent_photo_path, PATHINFO_EXTENSION) . ';base64,'
                . base64_encode(file_get_contents(storage_path('app/public/' . $agent->agent_photo_path)));
        }

        $logoBase64 = null;
        $agency = $agent ? ($agent->agency ?? \App\Models\Agency::first()) : \App\Models\Agency::first();
        if ($agency && $agency->logo_path) {
            $logoFile = storage_path('app/public/' . $agency->logo_path);
            if (file_exists($logoFile)) {
                $ext = pathinfo($logoFile, PATHINFO_EXTENSION);
                $mime = in_array($ext, ['jpg', 'jpeg']) ? 'image/jpeg' : 'image/' . $ext;
                $logoBase64 = 'data:' . $mime . ';base64,' . base64_encode(file_get_contents($logoFile));
            }
        }

        // Subject Property card — hero image + static map data URIs.
        // Hero: first entry from Property::allImages() (the flatten over
        // dawn/noon/dusk/gallery/images_json). External URLs are skipped
        // — PDF must be self-contained (Puppeteer waitUntil:'load').
        // Map: PresentationStaticMapService with empty comp/competition
        // arrays renders a subject-only pin. Falls back to null when no
        // Maps key configured OR no GPS — view emits a placeholder.
        $_resolvePropertyImage = function (?string $raw): ?string {
            if (!$raw) return null;
            $raw = explode('?', $raw)[0];
            if (str_starts_with($raw, 'http://') || str_starts_with($raw, 'https://')) {
                return null;
            }
            $rel = ltrim($raw, '/');
            if (str_starts_with($rel, 'storage/')) $rel = substr($rel, strlen('storage/'));
            $abs = storage_path('app/public/' . $rel);
            if (!is_file($abs)) return null;
            $bytes = @file_get_contents($abs);
            if ($bytes === false || $bytes === '') return null;
            $ext = strtolower(pathinfo($abs, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'jpg', 'jpeg' => 'image/jpeg',
                'png'         => 'image/png',
                'gif'         => 'image/gif',
                'webp'        => 'image/webp',
                default       => 'image/' . $ext,
            };
            return 'data:' . $mime . ';base64,' . base64_encode($bytes);
        };
        $_subjectHeroDataUri = null;
        if ($presentation->property) {
            $_images = $presentation->property->allImages();
            if (!empty($_images)) {
                $_subjectHeroDataUri = $_resolvePropertyImage((string) ($_images[0] ?? ''));
            }
        }
        $_subjectMapDataUri = null;
        $_subjPropLat = $presentation->property?->latitude;
        $_subjPropLng = $presentation->property?->longitude;
        if ($_subjPropLat !== null && $_subjPropLng !== null) {
            try {
                // AT-22 §3 — renderBase64() now returns ['data_uri'=>?, 'legend'=>[]].
                // The subject hero map only needs the image, so unwrap data_uri.
                $_subjectMapResult = (new \App\Services\Presentations\Pdf\PresentationStaticMapService())
                    ->renderBase64(
                        ['lat' => (float) $_subjPropLat, 'lng' => (float) $_subjPropLng,
                         'title' => (string) ($presentation->property_address ?? '')],
                        [],
                        [],
                        640,
                        360,
                    );
                $_subjectMapDataUri = $_subjectMapResult['data_uri'] ?? null;
            } catch (\Throwable) {
                $_subjectMapDataUri = null;
            }
        }

        // Compile analysis data from AnalysisDataService (real extracted data).
        // Build 3 — pass the LATEST PUBLISHED version so the condition snapshot
        // travels with the PDF. If there's no published version yet (rare; the
        // public/show flow uses the same path before publish), the live
        // resolution falls back to property condition.
        $latestPublished = $presentation->versions()
            ->where('review_status', \App\Models\PresentationVersion::REVIEW_PUBLISHED)
            ->orderByDesc('published_at')
            ->first();
        $data = (new AnalysisDataService())->compile($presentation, $latestPublished);

        // Build 4 — section toggles. Each PAGE block is wrapped with
        // a $sectionEnabled('key') guard below; floor sections always
        // return true so the report never renders without a cover or
        // subject facts. Reading off the version (not a fresh lookup)
        // is intentional — published versions honour their snapshot.
        $sectionEnabled = function (string $key) use ($version): bool {
            return $version->isSectionEnabled($key);
        };

        // B2 — Executive Summary payload. Resolves spec §3 tokens once,
        // up front, so the §1 heredoc just iterates bullets. Sectional
        // index is pre-computed (cover=p.1, exec summary=p.2, beats
        // start p.3) so bullets carry → p.{N} refs that line up with
        // the printed beat numbers.
        $summary = $this->buildSummaryPayload($presentation, $version, $data);

        $subject     = $data['subject_property']   ?? [];
        $suburb      = $data['suburb_overview']     ?? [];
        $comps       = $data['comparable_sales']    ?? [];
        $cma         = $data['cma_valuation']       ?? [];
        $competition = $data['active_competition']  ?? [];
        $stock       = $data['stock_absorption']    ?? [];
        $inflow      = $data['inflow_absorption']   ?? [];
        $propcon     = $data['propcon_insights']    ?? [];
        $holding     = $data['holding_cost']        ?? [];
        $insights    = $data['key_insights']        ?? [];

        // B2-followup-2 — section-number offset for the appendix sections
        // (Inflow / PropCon / Holding / Pricing Strategy / Scenarios).
        // Hoisted here from its former mid-heredoc location (was at the
        // old §7 PropCon close, between PropCon and Holding Cost) so it
        // AT-22 R2 item 3 — section numbers are no longer computed here. Every
        // section header emits a __SECNO__ placeholder and buildHtml() does a
        // single document-order sweep at the end, numbering sections in reading
        // order. The old $sectionAfterInflow offset machinery is gone.

        $compiledAt = $version->compiled_at?->format('d F Y') ?? now()->format('d F Y');

        // ── Formatting helpers ──────────────────────────────────────────────
        $zar = function (?int $val): string {
            if ($val === null || $val === 0) return '—';
            return 'R ' . number_format($val, 0, '.', ' ');
        };
        $zarFloat = function (?float $val): string {
            if ($val === null || $val == 0) return '—';
            return 'R ' . number_format((int) round($val), 0, '.', ' ');
        };
        $pct = function (?float $val): string {
            if ($val === null) return '—';
            $sign = $val > 0 ? '+' : '';
            return $sign . number_format($val, 1) . '%';
        };
        $esc = function (?string $val): string {
            return htmlspecialchars((string) ($val ?? ''), ENT_QUOTES, 'UTF-8');
        };

        // ── Build data for each page ────────────────────────────────────────
        $address     = $esc($subject['address'] ?? $presentation->property_address ?? '');
        $suburbName  = $esc($subject['suburb'] ?? $presentation->suburb ?? '');
        $sellerName  = $esc($presentation->seller_name ?? '');
        $propType    = $esc(\Illuminate\Support\Str::humanType($presentation->property_type ?? ''));
        // Build 7 — sectional check reads title_type (keystone single
        // source of truth). The compile()'s is_sectional flag also now
        // reads title_type, so the two sources are consistent.
        $isSectional = ($presentation->property?->title_type === 'sectional_title')
            || ($data['is_sectional'] ?? false);
        $sizeLabel   = $isSectional ? 'Unit m²' : 'Erf m²';
        $bedrooms    = $presentation->bedrooms;
        $bathrooms   = null; // not on model currently
        // Build 7 — sectional subjects don't have an erf size, they have
        // a floor area. Read the right column per title_type so the
        // "Subject Property Context" table is honest.
        if ($isSectional) {
            $erfSize     = $presentation->floor_area_m2 ?? $subject['extent_m2'] ?? null;
            $sizeRowLbl  = 'Floor Area';
        } else {
            $erfSize     = $subject['extent_m2'] ?? $presentation->erf_size_m2;
            $sizeRowLbl  = 'Erf Extent';
        }
        $askingPrice = $subject['asking_price'] ?? $presentation->asking_price_inc;

        // CMA values — the RECOMMENDED band. These are NOT the imported CMA
        // Info PDF figures (that band lives in cma_valuation.cma_info_benchmark
        // and is never rendered on the seller PDF). PRES-CMA-REALFIX: they are
        // the RAW comparable-sales quartiles (pool_stats p25/median/p75) with
        // NO condition factor applied — condition is already embodied in the
        // comp selection / agent setup, so re-scaling here double-counts it.
        // The band is therefore the comp distribution itself.
        $cmaLower  = $cma['cma_lower'] ?? null;
        $cmaMiddle = $cma['cma_middle'] ?? null;
        $cmaUpper  = $cma['cma_upper'] ?? null;
        $askVsCmaPct = $cma['asking_vs_cma_pct'] ?? null;

        // PRES-CMA-REALFIX — "Why This Range?" shows the comparable-sales
        // quartiles directly. Since the band is now the raw distribution,
        // cma_lower/middle/upper EQUAL these pool_stats quartiles; we read
        // pool_stats so the evidence rows label the percentiles explicitly and
        // fall back to the band values if pool_stats is somehow absent (then
        // the band itself is null and the card is suppressed). The condition
        // adjustment is no longer applied to the band, so no "adjusted +N%"
        // row is printed — condition_applied is always false now.
        $cmaComputed = $data['cma_computed'] ?? [];
        $poolStats   = $cmaComputed['pool_stats'] ?? [];
        $compP25     = isset($poolStats['p25'])    && $poolStats['p25']    !== null ? (int) $poolStats['p25']    : null;
        $compMedian  = isset($poolStats['median']) && $poolStats['median'] !== null ? (int) $poolStats['median'] : null;
        $compP75     = isset($poolStats['p75'])    && $poolStats['p75']    !== null ? (int) $poolStats['p75']    : null;

        // Suburb overview
        $suburbMedian    = $suburb['median_price'] ?? null;
        $suburbSales     = $suburb['sales_count'] ?? null;
        $suburbYear      = $suburb['latest_year'] ?? date('Y');
        $suburbLow       = $suburb['low_range'] ?? null;
        $suburbHigh      = $suburb['high_range'] ?? null;
        $suburbMax       = $suburb['max_price'] ?? null;

        // Competition
        $activeCount   = $competition['count'] ?? 0;
        $avgAskPrice   = $competition['avg_asking_price'] ?? null;

        // Holding cost
        $monthlyTotal  = $holding['monthly_total'] ?? 0;
        $projected6m   = $holding['projected_6m'] ?? 0;
        $projected12m  = $holding['projected_12m'] ?? 0;
        $breakdown     = $holding['breakdown'] ?? [];

        // Comparable sales
        $vicinitySales = $comps['vicinity']['rows'] ?? [];
        $vicAvgPrice   = $comps['vicinity']['avg_price'] ?? null;
        $vicAvgPpm2    = $comps['vicinity']['avg_price_per_m2'] ?? null;
        $cmaComps      = $comps['cma_comps']['rows'] ?? [];
        $streetSales   = $comps['street_sales']['rows'] ?? [];

        // Active listings
        $activeRows    = $competition['rows'] ?? [];

        // Stock absorption from AnalysisDataService (uses portal search total_count)
        $totalActiveStock  = $stock['total_active_stock'] ?? $activeCount;
        $absorptionRate    = $stock['monthly_sales'] ?? null;
        $monthsOfSupply    = $stock['months_of_supply'] ?? null;
        $yearsOfSupply     = $stock['years_of_supply'] ?? null;
        $absorptionLabel   = $stock['absorption_label'] ?? null;
        $absorptionColor   = $stock['absorption_color'] ?? null;

        // Price position & brackets — Build 8 canonical denominator.
        // Every seller-facing competition COUNT or rank/percentile/position
        // VERDICT reads from competitor_stock (the scored Active Competition
        // set the agent curates on the review screen). The legacy
        // price_position / price_brackets — derived from
        // active_competition.rows — are retained for back-compat but no
        // longer drive any seller-facing surface; the canonical
        // alternatives shadow them with the same shape so existing
        // templates swap by reading a different key.
        //
        // Fallback: legacy versions whose snapshot pre-dates Build 8
        // have no competitor_stock data. When the canonical block has
        // has_data=false, the templates fall through to the legacy
        // price_position so seller-facing screens never go blank.
        $competitorStockCanonical = $data['competitor_stock'] ?? [];
        $competingCount           = (int) ($competitorStockCanonical['competing_count'] ?? 0);
        $pricePositionCanonical   = $competitorStockCanonical['price_position_canonical'] ?? ['has_data' => false];
        $priceBracketsCanonical   = $competitorStockCanonical['price_brackets_canonical'] ?? ['has_data' => false, 'brackets' => []];

        $pricePositionLegacy = $data['price_position'] ?? [];
        $priceBracketsLegacy = $data['price_brackets'] ?? [];
        // Seller-facing tiles read the canonical first, fall back to
        // legacy if the canonical block is absent (pre-Build-8 snapshots).
        $pricePosition = !empty($pricePositionCanonical['has_data']) ? $pricePositionCanonical : $pricePositionLegacy;
        $priceBrackets = !empty($priceBracketsCanonical['has_data']) ? $priceBracketsCanonical : $priceBracketsLegacy;

        // Links for references
        $p24Links = $presentation->links->where('type', 'property24')->values();

        ob_start();
        ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Market Analysis — <?= $address ?></title>
<style>
/* ── RESET & BASE ────────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

:root {
    --brand: #0b2a4a;
    --brand-light: #1a4a73;
    --brand-accent: #4f46e5;
    --text: #1e293b;
    --text-muted: #64748b;
    --text-light: #94a3b8;
    --bg: #ffffff;
    --bg-alt: #f8fafc;
    --border: #e2e8f0;
    --border-light: #f1f5f9;
    --success: #059669;
    --success-bg: #ecfdf5;
    --warning: #d97706;
    --warning-bg: #fffbeb;
    --danger: #dc2626;
    --danger-bg: #fef2f2;
}

@page {
    size: A4 portrait;
    margin: 15mm 18mm 20mm 18mm;
}

body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
    font-size: 11px;
    line-height: 1.55;
    color: var(--text);
    background: var(--bg);
    -webkit-print-color-adjust: exact;
    print-color-adjust: exact;
}

/* ── PAGE BREAK HELPERS ──────────────────────────────────────────────── */
.page-break { page-break-before: always; }
.avoid-break { page-break-inside: avoid; }
/* AT-22 item 4 — the closing CTA + footer travel together and carry NO
   trailing margin, so the document's last box never spills a hair past the
   page content-box and triggers chromium to emit an empty final page. */
.report-tail { page-break-inside: avoid; }
.report-tail > :last-child { margin-bottom: 0; }

/* ── TYPOGRAPHY ──────────────────────────────────────────────────────── */
h1 { font-size: 28px; font-weight: 800; letter-spacing: -0.02em; }
h2 { font-size: 18px; font-weight: 700; letter-spacing: -0.01em; }
h3 { font-size: 14px; font-weight: 600; }

.section-header {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 18px;
    padding-bottom: 10px;
    border-bottom: 2px solid var(--brand);
    page-break-inside: avoid;
    page-break-after: avoid;
}
/* B2 — beat eyebrow above section headers so the seller can match
   bullets-to-beats by number. The eyebrow renders ABOVE the section
   number circle, page-break-after:avoid so it never orphans from its
   section. */
.beat-eyebrow {
    display: inline-block;
    padding: 3px 10px;
    margin-bottom: 6px;
    background: var(--brand);
    color: #fff;
    border-radius: 3px;
    font-size: 9.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    page-break-after: avoid;
}
.section-header h2 { color: var(--brand); }
.section-number {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 28px;
    height: 28px;
    border-radius: 50%;
    background: var(--brand);
    color: #fff;
    font-size: 13px;
    font-weight: 700;
    flex-shrink: 0;
}

/* ── SUBJECT PROPERTY CARD ───────────────────────────────────────────── */
.subject-card {
    margin-top: 18px;
    border: 1px solid var(--brand);
    border-radius: 6px;
    background: var(--bg);
    page-break-inside: avoid;
    overflow: hidden;
}
.subject-card-header {
    padding: 12px 18px;
    background: var(--brand);
    color: #fff;
    display: flex;
    align-items: center;
    gap: 10px;
}
.subject-card-header .accent { width: 4px; height: 18px; background: #00d4aa; flex-shrink: 0; }
.subject-card-header h3 {
    margin: 0;
    font-size: 13px;
    font-weight: 700;
    color: #fff;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.subject-card-header .subject-card-sub {
    font-size: 11px;
    color: rgba(255,255,255,0.85);
    margin-left: auto;
    font-weight: 500;
    text-align: right;
}
.subject-card-body {
    display: flex;
    gap: 18px;
    padding: 16px 18px;
}
.subject-card-photo {
    flex: 0 0 52%;
    height: 240px;
    background: var(--bg-alt);
    border-radius: 4px;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.subject-card-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
.subject-card-photo-placeholder { font-size: 11px; color: var(--text-muted); text-align: center; padding: 14px; }
.subject-card-facts {
    flex: 1;
    display: grid;
    grid-template-columns: auto 1fr;
    gap: 7px 16px;
    align-content: center;
}
.subject-card-facts .fact-label {
    font-size: 9.5px;
    color: var(--text-muted);
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    white-space: nowrap;
}
.subject-card-facts .fact-value { font-size: 12px; color: var(--text); font-weight: 600; }
.subject-card-facts .fact-value.price {
    font-size: 16px;
    color: var(--brand);
    font-weight: 800;
    letter-spacing: -0.01em;
}
.subject-card-map { padding: 0 18px 16px; }
.subject-card-map img {
    width: 100%;
    height: auto;
    max-height: 320px;
    object-fit: cover;
    border-radius: 4px;
    border: 1px solid var(--border);
    display: block;
}
.subject-card-map-placeholder {
    padding: 28px 16px;
    text-align: center;
    font-size: 11px;
    color: var(--text-muted);
    background: var(--bg-alt);
    border-radius: 4px;
    border: 1px dashed var(--border);
}

/* ── TABLES ──────────────────────────────────────────────────────────── */
table {
    width: 100%;
    border-collapse: collapse;
    margin: 10px 0;
    font-size: 10.5px;
}
th {
    background: var(--brand);
    color: #fff;
    text-align: left;
    padding: 7px 10px;
    font-size: 9.5px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.05em;
}
td {
    padding: 6px 10px;
    border-bottom: 1px solid var(--border-light);
    vertical-align: top;
}
tr:nth-child(even) td { background: var(--bg-alt); }
.table-summary td {
    background: var(--brand) !important;
    color: #fff;
    font-weight: 700;
    font-size: 11px;
}
td.num, th.num { text-align: right; }

/* ── METRIC CARDS ────────────────────────────────────────────────────── */
.metric-grid {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 12px;
    margin: 14px 0;
}
.metric-card {
    background: var(--bg-alt);
    border: 1px solid var(--border);
    border-radius: 8px;
    padding: 14px 16px;
    text-align: center;
}
.metric-card .label {
    font-size: 9px;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: var(--text-muted);
    font-weight: 600;
    margin-bottom: 6px;
}
.metric-card .value {
    font-size: 20px;
    font-weight: 800;
    color: var(--brand);
    letter-spacing: -0.02em;
}
.metric-card .sub {
    font-size: 9px;
    color: var(--text-light);
    margin-top: 3px;
}
.metric-card.highlight {
    background: var(--brand);
    border-color: var(--brand);
}
.metric-card.highlight .label { color: rgba(255,255,255,0.7); }
.metric-card.highlight .value { color: #fff; }
.metric-card.highlight .sub { color: rgba(255,255,255,0.6); }

.metric-card.danger { border-color: var(--danger); }
.metric-card.danger .value { color: var(--danger); }
.metric-card.warning { border-color: var(--warning); }
.metric-card.warning .value { color: var(--warning); }
.metric-card.success { border-color: var(--success); }
.metric-card.success .value { color: var(--success); }

/* ── VALUATION BAR ───────────────────────────────────────────────────── */
.val-bar-container { margin: 16px 0; position: relative; }
.val-bar {
    display: flex;
    height: 40px;
    border-radius: 6px;
    overflow: hidden;
    margin-bottom: 6px;
}
.val-bar .segment {
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 11px;
    font-weight: 700;
    color: #fff;
}
.val-bar .seg-lower { background: #64748b; flex: 1; }
.val-bar .seg-middle { background: var(--brand); flex: 1; }
.val-bar .seg-upper { background: var(--brand-light); flex: 1; }
.val-bar-labels {
    display: flex;
    justify-content: space-between;
    font-size: 10px;
    color: var(--text-muted);
    font-weight: 600;
}

/* ── CALLOUT ─────────────────────────────────────────────────────────── */
.callout {
    padding: 12px 16px;
    border-radius: 6px;
    border-left: 4px solid;
    margin: 12px 0;
    font-size: 11px;
    line-height: 1.5;
}
.callout-info { background: #eff6ff; border-color: #3b82f6; color: #1e40af; }
.callout-warning { background: var(--warning-bg); border-color: var(--warning); color: #92400e; }
.callout-danger { background: var(--danger-bg); border-color: var(--danger); color: #991b1b; }
.callout-success { background: var(--success-bg); border-color: var(--success); color: #065f46; }

/* ── SECTION INTRO (Build 8 — seller-first interpretive callouts) ───── */
/* Sits directly under each section header. Calm, plain-language voice
   that tells the seller WHAT they're looking at and WHY it matters.
   page-break-inside:avoid + the existing .section-header
   page-break-after:avoid means the header + intro travel as one. */
.section-intro {
    padding: 14px 18px;
    margin: 0 0 16px 0;
    background: var(--bg-alt);
    border: 1px solid var(--border);
    border-left: 4px solid var(--brand);
    border-radius: 4px;
    font-size: 11.5px;
    line-height: 1.65;
    color: var(--text);
    page-break-inside: avoid;
}
.section-intro strong { color: var(--brand); font-weight: 700; }

/* ── COVER PAGE ──────────────────────────────────────────────────────── */
.cover {
    display: flex;
    flex-direction: column;
    justify-content: center;
    min-height: 90vh;
    text-align: left;
    padding: 40px 0;
}
.cover-brand {
    font-size: 13px;
    font-weight: 700;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    letter-spacing: 0.12em;
    color: var(--brand);
    margin-bottom: 28px;
}
.cover-bar {
    width: 80px;
    height: 4px;
    background: var(--brand);
    border-radius: 2px;
    margin: 20px 0 24px;
}
.cover h1 {
    font-size: 32px;
    color: var(--brand);
    margin-bottom: 8px;
    line-height: 1.15;
}
.cover-address {
    font-size: 22px;
    font-weight: 700;
    color: var(--text);
    margin-bottom: 6px;
}
.cover-details {
    font-size: 13px;
    color: var(--text-muted);
    margin-bottom: 32px;
    line-height: 1.7;
}
.cover-meta {
    margin-top: auto;
    padding-top: 20px;
}
.cover-agent-row {
    display: flex;
    align-items: flex-end;
    justify-content: space-between;
    border-top: 2px solid var(--brand);
    padding-top: 20px;
}
.cover-agent-info {
    flex: 1;
}
.cover-agent-info .agent-name {
    font-size: 18px;
    font-weight: 700;
    color: var(--brand);
    margin-bottom: 4px;
}
.cover-agent-info .agent-company {
    font-size: 12px;
    color: var(--text-muted);
    margin-bottom: 2px;
}
.cover-agent-info .agent-contact {
    font-size: 12px;
    color: var(--text-muted);
    line-height: 1.7;
}
.cover-agent-photo {
    width: 140px;
    height: 170px;
    object-fit: cover;
    border-radius: 8px;
    border: 3px solid var(--brand);
    margin-left: 24px;
    flex-shrink: 0;
}

/* ── FOOTER ──────────────────────────────────────────────────────────── */
@media print {
    .page-footer { display: none; }
    @page { @bottom-center { content: counter(page); } }
}
.page-footer {
    position: fixed;
    bottom: 0;
    left: 0;
    right: 0;
    text-align: center;
    font-size: 8.5px;
    color: var(--text-light);
    padding: 8px 18mm;
    border-top: 1px solid var(--border-light);
}

/* ── COMPARISON INDICATOR ────────────────────────────────────────────── */
.cmp-badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 700;
}
.cmp-danger { background: var(--danger-bg); color: var(--danger); }
.cmp-warning { background: var(--warning-bg); color: var(--warning); }
.cmp-success { background: var(--success-bg); color: var(--success); }

/* ── LINKS ───────────────────────────────────────────────────────────── */
a { color: var(--brand-accent); text-decoration: none; }
a:hover { text-decoration: underline; }

/* ── GRID ────────────────────────────────────────────────────────────── */
.two-col { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

/* ── HOLDING COST TIMELINE ───────────────────────────────────────────── */
.timeline-row { display: flex; align-items: center; gap: 8px; margin-bottom: 6px; }
.timeline-month {
    width: 24px;
    height: 24px;
    border-radius: 50%;
    background: var(--bg-alt);
    border: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 9px;
    font-weight: 700;
    color: var(--text-muted);
    flex-shrink: 0;
}
.timeline-bar {
    height: 10px;
    background: var(--danger);
    border-radius: 3px;
    opacity: 0.7;
}
.timeline-amount {
    font-size: 10px;
    font-weight: 600;
    color: var(--text);
    white-space: nowrap;
}

/* ── CHART: PRICE POSITION NUMBER LINE ───────────────────────────────── */
.price-line { position: relative; height: 50px; margin: 16px 0 30px; }
.price-line-track { position: absolute; top: 18px; left: 0; right: 0; height: 14px; border-radius: 7px; overflow: hidden; }
.price-line-zone { position: absolute; top: 0; height: 100%; }
.price-line-marker {
    position: absolute; top: 0; transform: translateX(-50%);
    display: flex; flex-direction: column; align-items: center;
}
.price-line-marker .dot {
    width: 12px; height: 12px; border-radius: 50%; border: 2px solid #fff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.3); z-index: 2;
}
.price-line-marker .marker-label {
    font-size: 8px; font-weight: 700; white-space: nowrap; margin-top: 2px;
    padding: 1px 4px; border-radius: 3px; background: #fff;
}
.price-line-marker .marker-value {
    font-size: 7.5px; color: var(--text-muted); white-space: nowrap;
}

/* ── CHART: ABSORPTION GAUGE ──────────────────────────────────────────── */
.gauge-container { position: relative; width: 240px; margin: 12px auto; }
.gauge-bar { height: 22px; border-radius: 11px; overflow: hidden; display: flex; }
.gauge-seg { height: 100%; }
.gauge-pointer {
    position: absolute; top: -4px; transform: translateX(-50%);
    width: 4px; height: 30px; background: var(--brand); border-radius: 2px;
    box-shadow: 0 0 4px rgba(0,0,0,0.3);
}
.gauge-labels { display: flex; justify-content: space-between; font-size: 8px; color: var(--text-muted); margin-top: 4px; }

/* ── CHART: SALE PRICE TIMELINE ──────────────────────────────────────── */
.sale-timeline { position: relative; height: 140px; margin: 14px 0; border-left: 1px solid var(--border); border-bottom: 1px solid var(--border); }
.sale-timeline-dot {
    position: absolute; width: 8px; height: 8px; border-radius: 50%;
    background: var(--brand); border: 1.5px solid #fff;
    box-shadow: 0 1px 2px rgba(0,0,0,0.2); transform: translate(-50%, 50%);
}
.sale-timeline-line { position: absolute; left: 0; right: 0; border-top: 2px dashed var(--danger); opacity: 0.5; }
.sale-timeline-axis { display: flex; justify-content: space-between; font-size: 8px; color: var(--text-muted); margin-top: 4px; padding-left: 1px; }
.sale-timeline-yaxis { position: absolute; left: -2px; font-size: 7.5px; color: var(--text-light); transform: translateY(50%); text-align: right; }

/* ── CHART: VERTICAL BAR CHART ───────────────────────────────────────── */
.bar-chart { display: flex; align-items: flex-end; gap: 3px; height: 100px; margin: 12px 0; padding: 0 4px; }
.bar-col {
    flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: flex-end;
    min-width: 20px;
}
.bar-col .bar {
    width: 100%; border-radius: 3px 3px 0 0; min-height: 2px;
    transition: height 0.2s;
}
.bar-col .bar-label { font-size: 11px; color: var(--text); font-weight: 600; margin-top: 6px; text-align: center; white-space: nowrap; line-height: 1.2; }
.bar-col .bar-sub   { font-size: 9px; color: var(--text-muted); margin-top: 2px; text-align: center; white-space: nowrap; }
.bar-col .bar-count { font-size: 12px; font-weight: 800; color: var(--brand); margin-bottom: 4px; }
.bar-chart-wrap { position: relative; }
.bar-chart-wrap .median-line {
    position: absolute;
    top: 0;
    width: 0;
    border-left: 1.5px dashed #dc2626;
    pointer-events: none;
}
.bar-chart-wrap .median-line-label {
    position: absolute;
    top: -4px;
    transform: translateX(-50%);
    font-size: 10px;
    font-weight: 700;
    color: #dc2626;
    background: var(--bg);
    padding: 0 4px;
    white-space: nowrap;
}

/* ── CHART: HORIZONTAL BRACKET BARS ──────────────────────────────────── */
.hbar-row { display: flex; align-items: center; gap: 6px; margin-bottom: 4px; }
.hbar-label { width: 100px; text-align: right; font-size: 9px; color: var(--text-muted); flex-shrink: 0; }
.hbar-track { flex: 1; background: #f1f5f9; border-radius: 999px; height: 18px; overflow: hidden; position: relative; }
.hbar-fill { height: 100%; border-radius: 999px; display: flex; align-items: center; padding: 0 6px; }
.hbar-fill span { font-size: 8px; color: #fff; font-weight: 700; }
.hbar-count { width: 28px; text-align: right; font-size: 10px; font-weight: 600; flex-shrink: 0; }
</style>
</head>
<body>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 1 — COVER
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="cover">
    <?php if ($logoBase64): ?>
    <div class="cover-brand"><img src="<?= $logoBase64 ?>" alt="Home Finders Coastal" style="max-height:120px;width:auto;"></div>
    <?php else: ?>
    <div class="cover-brand">Home Finders Coastal</div>
    <?php endif ?>
    <h1>Market Analysis<br>&amp; Pricing Strategy</h1>
    <div style="height:24px"></div>
    <div class="cover-address"><?= $address ?></div>
    <div class="cover-details">
        <?= $suburbName ?>
        <?php if ($erfSize): ?>&nbsp;&middot;&nbsp;<?= number_format((int) $erfSize) ?> m²<?php endif ?>
        <?php if ($propType): ?>&nbsp;&middot;&nbsp;<?= $propType ?><?php endif ?>
        <?php if ($bedrooms): ?>&nbsp;&middot;&nbsp;<?= $bedrooms ?> Bedroom<?= $bedrooms > 1 ? 's' : '' ?><?php endif ?>
    </div>
    <?php if ($sellerName): ?>
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:4px;">Prepared for <strong style="color:var(--text)"><?= $sellerName ?></strong></p>
    <?php endif ?>
    <div class="cover-meta">
        <div class="cover-agent-row">
            <div class="cover-agent-info">
                <div class="agent-name"><?= $esc($agentName) ?></div>
                <div class="agent-company">Home Finders Coastal — Shelly Beach, KZN South Coast</div>
                <div class="agent-contact">
                    <?php if ($agentEmail): ?><?= $esc($agentEmail) ?><br><?php endif ?>
                    <?php if (!empty($agentPhone)): ?><?= $esc($agentPhone) ?><br><?php endif ?>
                    <?= $esc($agentDesignation) ?><br>
                    <?= $compiledAt ?>
                </div>
            </div>
            <?php if ($agentPhotoPath): ?>
            <img class="cover-agent-photo" src="<?= $agentPhotoPath ?>" alt="<?= $esc($agentName) ?>">
            <?php endif ?>
        </div>
    </div>
    <div style="position:absolute;bottom:40px;left:0;right:0;text-align:center;font-size:9px;color:#888;">Registered with the PPRA</div>
</div>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 2 — EXECUTIVE SUMMARY (B2-followup: moved BEFORE Subject card)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php // B2 — Executive Summary primary page (spec
      // .ai/specs/seller-report-restructure.md).
      // Pure prose + five token-templated bullets. The CMA tiles +
      // price-position chart + recommended-band callout that used to
      // live here have moved to Beat 4 (Pricing Strategy section)
      // where they belong as proof, not summary. The bullets each
      // carry their canonical figures + a → p.{N} cross-reference
      // computed in buildSummaryPayload(). ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Executive Summary</h2>
</div>

<?php // AI tone prose — figure-free per spec §2. Frozen on the
      // version snapshot. If a legacy version has hard figures in its
      // stored text, the bullets below carry the load and the prose
      // reads as warm context. ?>
<?php if (!empty($summary['tone_text'])): ?>
    <div style="font-size:12px;line-height:1.65;color:var(--text-primary);margin-bottom:18px;white-space:pre-wrap;"><?= e($summary['tone_text']) ?></div>
<?php endif ?>

<?php // Five bullets — locked copy from spec §3, tokens resolved in
      // buildSummaryPayload. Suppressed bullets are skipped per spec
      // §7 degraded-state matrix; the sectionIndex page refs already
      // account for the recompute. ?>
<ol style="list-style:none;padding:0;margin:0;counter-reset:execbullet;">
    <?php foreach ($summary['bullets'] as $b): ?>
        <?php if (!empty($b['suppressed'])) continue; ?>
        <li style="display:flex;gap:14px;align-items:flex-start;padding:14px 0;border-bottom:1px solid var(--border);counter-increment:execbullet;">
            <div style="flex-shrink:0;width:28px;height:28px;border-radius:50%;background:var(--brand);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:13px;">
                <?= array_search($b, $summary['bullets'], true) + 1 ?>
            </div>
            <div style="flex:1;font-size:12.5px;line-height:1.65;color:var(--text);">
                <?= $b['html'] ?>
                <span style="display:inline-block;margin-left:6px;padding:2px 8px;border-radius:3px;background:var(--bg-alt);font-size:10.5px;font-weight:600;color:var(--text-muted);">→ <?= $b['ref'] ?></span>
            </div>
        </li>
    <?php endforeach ?>
</ol>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 3 — BEAT 1 · YOUR PROPERTY (B2-followup: Subject card moved
      // AFTER Exec Summary so the Bullet 1 → p.3 ref lines up physically).
      // Layout: hero image beside a clean fact grid, subject location map
      // below. Default fact set is intentionally trim (address / suburb /
      // type / beds / baths / garages / extent / asking) — agents will tune
      // the visible fields later. The card is page-break-inside:avoid via
      // .subject-card so it never splits across a page boundary.
      // POPIA: no owner field is rendered.
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="page-break"></div>
<div class="beat-eyebrow">Section <?= $summary['section_index']['your_property'] ?? 3 ?> · Beat 1 — Your Property</div>
<div class="subject-card">
    <div class="subject-card-header">
        <div class="accent"></div>
        <h3>Subject Property</h3>
        <?php if (!empty($subject['address'])): ?>
            <span class="subject-card-sub"><?= $esc($subject['address']) ?></span>
        <?php endif ?>
    </div>
    <div class="subject-card-body">
        <div class="subject-card-photo">
            <?php if ($_subjectHeroDataUri): ?>
                <img src="<?= $_subjectHeroDataUri ?>" alt="Subject property">
            <?php else: ?>
                <div class="subject-card-photo-placeholder">No primary photo on the property record yet.</div>
            <?php endif ?>
        </div>
        <div class="subject-card-facts">
            <?php
                $_facts = [];
                $_addFact = function (string $label, $value, bool $isPrice = false) use (&$_facts) {
                    if ($value === null || $value === '' || $value === '—') return;
                    $_facts[] = ['label' => $label, 'value' => $value, 'price' => $isPrice];
                };
                $_addFact('Address', $subject['address'] ?? $presentation->property_address ?? null);
                $_addFact('Suburb',  $subject['suburb']  ?? $presentation->suburb ?? null);
                $_typeRaw = $presentation->property?->property_type ?? $presentation->property_type ?? null;
                if ($_typeRaw) {
                    $_addFact('Type', \Illuminate\Support\Str::humanType($_typeRaw));
                }
                $_beds    = $presentation->property?->beds    ?? $presentation->bedrooms ?? null;
                $_baths   = $presentation->property?->baths   ?? null;
                $_garages = $presentation->property?->garages ?? null;
                if ($_beds    !== null && $_beds    !== '') $_addFact('Beds',    (int) $_beds);
                if ($_baths   !== null && $_baths   !== '') $_addFact('Baths',   (int) $_baths);
                if ($_garages !== null && $_garages !== '') $_addFact('Garages', (int) $_garages);
                if (!empty($subject['extent_m2'])) {
                    $_addFact($isSectional ? 'Floor area' : 'Extent',
                        number_format((int) $subject['extent_m2']) . ' m²');
                }
                if (!empty($askingPrice)) {
                    $_addFact('Asking price',
                        'R ' . number_format((int) $askingPrice, 0, '.', ' '),
                        true);
                }
            ?>
            <?php if (empty($_facts)): ?>
                <div style="grid-column:1/-1;font-size:11px;color:var(--text-muted);">Property record carries no field data yet.</div>
            <?php else: foreach ($_facts as $_f): ?>
                <div class="fact-label"><?= $esc($_f['label']) ?></div>
                <div class="fact-value<?= $_f['price'] ? ' price' : '' ?>"><?= $esc((string) $_f['value']) ?></div>
            <?php endforeach; endif ?>
        </div>
    </div>
    <div class="subject-card-map">
        <?php if ($_subjectMapDataUri): ?>
            <img src="<?= $_subjectMapDataUri ?>" alt="Subject location map">
        <?php elseif ($_subjPropLat !== null && $_subjPropLng !== null): ?>
            <div class="subject-card-map-placeholder">
                Subject GPS on file (<?= number_format((float) $_subjPropLat, 5) ?>, <?= number_format((float) $_subjPropLng, 5) ?>).
                <?php /* AT-22 R2 item 5 — distinguish the two failure modes: a
                         missing key vs a key that is set but whose Google Cloud
                         project has not enabled the Maps Static API (HTTP 403). */ ?>
                <?php if (empty(config('services.google.static_maps_api_key'))): ?>
                    Static-location map unavailable — the Google Static Maps key is not configured.
                <?php else: ?>
                    Static-location map unavailable — the Google Static Maps key is configured, but the map did not render. Check that the <strong>Maps Static API</strong> is enabled on the key's Google Cloud project.
                <?php endif ?>
            </div>
        <?php else: ?>
            <div class="subject-card-map-placeholder">
                Subject location not geocoded yet. Capture GPS on the property record to render the map here.
            </div>
        <?php endif ?>
    </div>
</div>

<?php // ══════════════════════════════════════════════════════════════════════
      // BEAT 2 — What's Happened Around You. Spec §1 order:
      //   1. Recent Sales (primary content — what actually transacted)
      //   2. Sale-price trend chart (inside Recent Sales block)
      //   3. Market Overview context strip (the broader backdrop)
      //   4. Spatial map (where the sold homes sit around the subject)
      // Banner is unconditional. The §2 Market Overview block is
      // captured into $beat2MarketOverviewHtml via ob_start() and
      // emitted AFTER §3 Recent Sales (call site below the
      // recent_sales endif) — B2-followup-3 internal reorder.
      // ══════════════════════════════════════════════════════════════════════ ?>
<div class="beat-eyebrow">Section <?= $summary['section_index']['sold'] ?? 4 ?> · Beat 2 — What's Happened Around You</div>
<?php ob_start(); ?>
<?php if ($sectionEnabled('market_overview')): ?>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Market Overview — <?= $suburbName ?></h2>
</div>

<div class="section-intro avoid-break">
    This is the broader picture for <strong><?= $suburbName ?></strong> — how many homes sold
    recently and at what prices. It sets the backdrop: a market with steady sales and
    rising prices gives you more room; a slower one calls for sharper positioning. Your
    property's specific value comes from the comparable sales on the next pages, but this
    is the climate it's selling into.
</div>

<div class="avoid-break">
<h3 style="margin-bottom:8px;">Suburb Price Summary (<?= $esc((string) $suburbYear) ?>)</h3>
<?php
    // AT-22 R2 item 1 — the suburb summary binds from an uploaded suburb-stats
    // report (parsed into suburb.latest_* fields). When NO report is attached
    // the figures are genuinely absent — show an honest empty-state rather than
    // a misleading row of zeros. We do NOT synthesise these from the comp pool:
    // Market Overview is the broader suburb picture, not the comparable set.
    $hasSuburbData = ($suburbSales !== null && (int) $suburbSales > 0)
        || ($suburbMedian !== null && (int) $suburbMedian > 0);
?>
<?php if ($hasSuburbData): ?>
<table>
    <thead>
        <tr>
            <th>Metric</th>
            <th class="num">Value</th>
        </tr>
    </thead>
    <tbody>
        <tr><td>Total Residential Sales</td><td class="num"><strong><?= (int) $suburbSales ?></strong></td></tr>
        <tr><td>Typical Sale Price</td><td class="num"><strong><?= $zar($suburbMedian) ?></strong></td></tr>
        <tr><td>Low Range</td><td class="num"><?= $zar($suburbLow) ?></td></tr>
        <tr><td>High Range</td><td class="num"><?= $zar($suburbHigh) ?></td></tr>
        <tr><td>Maximum Sale Price</td><td class="num"><?= $zar($suburbMax) ?></td></tr>
    </tbody>
</table>
<?php else: ?>
<?php
    // AT-22 R3 — the suburb price summary is pulled automatically from the
    // shared Market Intelligence data by suburb. This empty-state shows ONLY
    // when the MIC genuinely holds no data for this suburb yet — the fix is to
    // import a suburb report into Market Intelligence (reusable across every
    // presentation in the suburb), NOT to upload one to this presentation.
    $_micCreateUrl = \Illuminate\Support\Facades\Route::has('market-intelligence.reports.create')
        ? route('market-intelligence.reports.create')
        : null;
?>
<div style="padding:14px 16px; border:1px dashed var(--border, #cbd5e1); border-radius:6px; color:#64748b; font-size:13px; background:#f8fafc;">
    No market data for <strong><?= $suburbName !== '' ? $suburbName : 'this suburb' ?></strong> yet.
    Import a suburb report via <strong>Market Intelligence</strong><?php if ($_micCreateUrl): ?> (<a href="<?= $esc($_micCreateUrl) ?>" style="color:#2563eb;">import a report</a>)<?php endif ?>
    and it will populate here automatically — and across every presentation in this suburb.
</div>
<?php endif ?>
</div>

<?php if ($askingPrice && $suburbMedian && $suburbMedian > 0): ?>
<?php $askVsMedianPct = round(($askingPrice - $suburbMedian) / $suburbMedian * 100, 1); ?>
<div class="callout <?= $askVsMedianPct > 50 ? 'callout-danger' : ($askVsMedianPct > 20 ? 'callout-warning' : 'callout-info') ?>" style="margin-top:14px;">
    <strong>Your asking price of <?= $zar($askingPrice) ?> is <?= $pct($askVsMedianPct) ?> <?= $askVsMedianPct > 0 ? 'above' : 'below' ?> the typical sale price in the suburb.</strong>
    <?php if ($askVsMedianPct > 50): ?>
    Homes priced well above the typical sale price for the suburb usually take longer to sell.
    <?php endif ?>
</div>
<?php endif ?>

<?php // CHART 2: Absorption Rate Gauge ?>
<?php if ($monthsOfSupply !== null): ?>
<?php
    $gaugeMax = 24; // cap gauge at 24 months
    $gaugeVal = min($monthsOfSupply, $gaugeMax);
    $gaugePct = round($gaugeVal / $gaugeMax * 100);
?>
<div class="avoid-break" style="margin-top:16px;">
    <p style="font-size:9px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:8px;">How Quickly Homes Are Selling</p>
    <div class="gauge-container" style="width:100%;">
        <div class="gauge-bar">
            <div class="gauge-seg" style="width:12.5%;background:#059669;"></div>
            <div class="gauge-seg" style="width:12.5%;background:#16a34a;"></div>
            <div class="gauge-seg" style="width:12.5%;background:#d97706;"></div>
            <div class="gauge-seg" style="width:12.5%;background:#ea580c;"></div>
            <div class="gauge-seg" style="width:50%;background:#dc2626;"></div>
        </div>
        <div class="gauge-pointer" style="left:<?= $gaugePct ?>%;"></div>
        <div class="gauge-labels">
            <span>0</span><span>3 mo</span><span>6 mo</span><span>12 mo</span><span>24+ mo</span>
        </div>
        <div style="text-align:center;margin-top:8px;">
            <span style="font-size:16px;font-weight:800;color:var(--brand);"><?= number_format($monthsOfSupply, 1) ?></span>
            <span style="font-size:10px;color:var(--text-muted);"> months' worth of stock on hand</span>
        </div>
    </div>
</div>
<?php endif ?>

<?php // Subject property context ?>
<?php if ($subject['erf'] || $subject['municipal_value'] || $subject['indexed_value']): ?>
<div class="avoid-break" style="margin-top:18px;">
<h3 style="margin-bottom:8px;">Subject Property Context</h3>
<table>
    <thead><tr><th>Detail</th><th class="num">Value</th></tr></thead>
    <tbody>
        <?php if ($subject['erf']): ?><tr><td>Erf Number</td><td class="num"><?= $esc($subject['erf']) ?></td></tr><?php endif ?>
        <?php if ($erfSize): ?><tr><td><?= $esc($sizeRowLbl) ?></td><td class="num"><?= number_format((int) $erfSize) ?> m²</td></tr><?php endif ?>
        <?php if ($subject['purchase_date']): ?><tr><td>Purchase Date</td><td class="num"><?= $esc($subject['purchase_date']) ?></td></tr><?php endif ?>
        <?php if ($subject['purchase_price']): ?><tr><td>Purchase Price</td><td class="num"><?= $zar($subject['purchase_price']) ?></td></tr><?php endif ?>
        <?php if ($subject['indexed_value']): ?><tr><td>Indexed Value</td><td class="num"><?= $zar($subject['indexed_value']) ?></td></tr><?php endif ?>
        <?php if ($subject['cagr']): ?><tr><td>CAGR</td><td class="num"><?= number_format($subject['cagr'], 2) ?>%</td></tr><?php endif ?>
        <?php if ($subject['municipal_value']): ?><tr><td>Municipal Evaluation<?php if ($subject['municipal_year']): ?> (<?= $esc($subject['municipal_year']) ?>)<?php endif ?></td><td class="num"><?= $zar($subject['municipal_value']) ?></td></tr><?php endif ?>
    </tbody>
</table>
</div>
<?php endif ?>

<?php endif // /market_overview ?>
<?php $beat2MarketOverviewHtml = ob_get_clean(); ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // PAGE 4 — RECENT SALES NEAR YOUR PROPERTY  (Build 4 toggleable)
      // Emits FIRST inside Beat 2 per spec §1 order — Market Overview
      // context strip ($beat2MarketOverviewHtml) emits after this block.
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php if ($sectionEnabled('recent_sales')): ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Recent Sales Near Your Property</h2>
</div>

<?php
    // Combine vicinity + street sales, dedup, exclude subject property, sort by date desc
    $allSales = array_merge($vicinitySales, $streetSales);

    // 1. Exclude subject property (case-insensitive address match)
    $subjectAddr = strtolower(trim($address ?? ''));
    if ($subjectAddr !== '' && $subjectAddr !== '—') {
        $allSales = array_filter($allSales, function ($sale) use ($subjectAddr) {
            $saleAddr = strtolower(trim($sale['address'] ?? ''));
            return $saleAddr === '' || !str_contains($saleAddr, $subjectAddr);
        });
    }

    // 2. Dedup: same address + sale_date + sale_price = same row (keep most data-rich)
    $seen = [];
    $dedupSales = [];
    foreach ($allSales as $sale) {
        $addr = strtolower(trim($sale['address'] ?? ''));
        $dedupKey = $addr . '|' . ($sale['sale_date'] ?? '') . '|' . (int) ($sale['sale_price'] ?? 0);
        if ($addr !== '' && isset($seen[$dedupKey])) {
            continue; // skip duplicate
        }
        if ($addr !== '') {
            $seen[$dedupKey] = true;
        }
        $dedupSales[] = $sale;
    }
    $allSales = $dedupSales;

    // 3. Sort by date desc, take top 15
    usort($allSales, function ($a, $b) {
        return strcmp($b['sale_date'] ?? '', $a['sale_date'] ?? '');
    });
    $topSales = array_slice($allSales, 0, 15);
?>

<?php if (!empty($topSales)): ?>
<div class="section-intro avoid-break">
    These are the actual homes near you that have sold — real transactions, not asking
    prices. They're the single most reliable guide to what a buyer will pay for a property
    like yours, because they show what buyers have <strong>already paid</strong>. The closer
    a sale is in size, location and timing, the more it tells us about your value.
</div>
<p style="font-size:10px;color:var(--text-muted);margin:0 0 10px 0;">
    The <?= count($topSales) ?> most recent sales within the vicinity of your property, sorted by date (most recent first).
</p>

<table>
    <thead>
        <tr>
            <th>Address</th>
            <th>Dist.</th>
            <th class="num"><?= $esc($sizeLabel) ?></th>
            <th>Sale Date</th>
            <th class="num">Sale Price</th>
            <th class="num">Per m²</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($topSales as $sale): ?>
        <tr>
            <td><?= $esc($sale['address'] ?? '—') ?></td>
            <td><?= $sale['distance_m'] ? $sale['distance_m'] . 'm' : '—' ?></td>
            <td class="num"><?= $sale['extent_m2'] ? number_format((int) $sale['extent_m2']) : '—' ?></td>
            <td><?= $esc($sale['sale_date'] ?? '—') ?></td>
            <td class="num"><?= $zar($sale['sale_price'] ?? null) ?></td>
            <td class="num"><?= $sale['price_per_m2'] ? 'R ' . number_format((int) $sale['price_per_m2']) : '—' ?></td>
        </tr>
        <?php endforeach ?>
    </tbody>
    <?php if ($vicAvgPrice || $vicAvgPpm2): ?>
    <tfoot>
        <tr class="table-summary">
            <td colspan="4"><strong>Average</strong></td>
            <td class="num"><?= $zar($vicAvgPrice) ?></td>
            <td class="num"><?= $vicAvgPpm2 ? 'R ' . number_format($vicAvgPpm2) : '—' ?></td>
        </tr>
    </tfoot>
    <?php endif ?>
</table>

<?php if ($vicAvgPrice && $askingPrice && $vicAvgPrice > 0): ?>
<?php $askVsVicPct = round(($askingPrice - $vicAvgPrice) / $vicAvgPrice * 100, 1); ?>
<div class="callout <?= $askVsVicPct > 30 ? 'callout-danger' : ($askVsVicPct > 10 ? 'callout-warning' : 'callout-info') ?>" style="margin-top:12px;">
    The average vicinity sale price is <strong><?= $zar($vicAvgPrice) ?></strong> (R <?= $vicAvgPpm2 ? number_format($vicAvgPpm2) . '/m²' : '—' ?>).
    Your asking price is <strong><?= $pct($askVsVicPct) ?></strong> <?= $askVsVicPct > 0 ? 'above' : 'below' ?> this average.
</div>
<?php endif ?>

<?php // CHART 3: Sale Prices Over Time — Build 8 rebuild as a real
      // time-series. Pre-fix this was a dot cloud with two date labels
      // and no trend; now an SVG plot with a least-squares regression
      // trend line, monthly x-axis ticks, and the asking-price dashed
      // reference. Reads as "where is the market heading" at a glance.
?>
<?php
    $chartSales = array_filter($topSales, fn($s) => !empty($s['sale_date']) && !empty($s['sale_price']) && $s['sale_price'] > 0);
    if (count($chartSales) >= 3):
        usort($chartSales, fn($a, $b) => strcmp($a['sale_date'], $b['sale_date']));
        $cPrices = array_column($chartSales, 'sale_price');
        $cMinP = (int) (min($cPrices) * 0.92);
        $cMaxP = (int) (max(max($cPrices), $askingPrice ?? 0) * 1.05);
        $cRangeP = max(1, $cMaxP - $cMinP);
        $cDates = array_column($chartSales, 'sale_date');
        $cMinD = strtotime(min($cDates));
        $cMaxD = strtotime(max($cDates));
        $cRangeD = max(1, $cMaxD - $cMinD);

        // Least-squares regression over (date_unix, sale_price). Computed
        // on the raw unix timestamps; the slope is in ZAR per second,
        // which we convert to a per-month figure for the human label.
        $stN = count($chartSales);
        $stSumX = 0.0; $stSumY = 0.0; $stSumXY = 0.0; $stSumX2 = 0.0;
        foreach ($chartSales as $cs) {
            $tx = (float) strtotime($cs['sale_date']);
            $ty = (float) $cs['sale_price'];
            $stSumX += $tx; $stSumY += $ty;
            $stSumXY += $tx * $ty;
            $stSumX2 += $tx * $tx;
        }
        $stDenom = ($stN * $stSumX2 - $stSumX * $stSumX);
        $stSlope = $stDenom !== 0.0 ? ($stN * $stSumXY - $stSumX * $stSumY) / $stDenom : 0.0;
        $stIntercept = ($stSumY - $stSlope * $stSumX) / $stN;
        $stTrendStart = $stSlope * (float) $cMinD + $stIntercept;
        $stTrendEnd   = $stSlope * (float) $cMaxD + $stIntercept;
        // Clamp trend endpoints into the plotted band so the line stays
        // on-canvas when the regression overshoots min/max.
        $stTrendStart = max($cMinP, min($cMaxP, $stTrendStart));
        $stTrendEnd   = max($cMinP, min($cMaxP, $stTrendEnd));
        // Monthly change as % of the starting price — what the seller cares
        // about. Falls back to flat when the date range is < 1 month or
        // the start price is zero.
        $stRangeMonths = max(0.001, ($cMaxD - $cMinD) / (30.4 * 86400));
        $stStartPrice  = max(1.0, $stSlope * (float) $cMinD + $stIntercept);
        $stMonthlyPct  = $stStartPrice > 0
            ? (($stSlope * 30.4 * 86400) / $stStartPrice) * 100
            : 0.0;

        // SVG canvas geometry. Tight padL reserves room for the
        // R-formatted Y-axis ticks (rendered right-aligned).
        $stW = 620; $stH = 200;
        $stPadL = 64; $stPadR = 16; $stPadT = 12; $stPadB = 32;
        $stPlotW = $stW - $stPadL - $stPadR;
        $stPlotH = $stH - $stPadT - $stPadB;

        $stPx = function ($t) use ($cMinD, $cRangeD, $stPadL, $stPlotW) {
            return $stPadL + (($t - $cMinD) / $cRangeD) * $stPlotW;
        };
        $stPy = function ($p) use ($cMinP, $cRangeP, $stPadT, $stPlotH) {
            return $stPadT + (1 - (($p - $cMinP) / $cRangeP)) * $stPlotH;
        };

        // Monthly tick marks. We anchor to the first of each month inside
        // the date range; when the range spans more than ~12 months, we
        // thin to every Nth tick so the axis stays readable.
        $stTicks = [];
        $stCursor = strtotime(date('Y-m-01', $cMinD));
        if ($stCursor < $cMinD) $stCursor = strtotime('+1 month', $stCursor);
        $stGuard = 0;
        while ($stCursor <= $cMaxD && $stGuard < 240) {
            $stTicks[] = $stCursor;
            $stCursor = strtotime('+1 month', $stCursor);
            $stGuard++;
        }
        $stTickEvery = max(1, (int) ceil(count($stTicks) / 12));

        $stTrendLabel = abs($stMonthlyPct) < 0.05
            ? 'Trend: flat'
            : sprintf('Trend: %s%.1f%%/mo', $stMonthlyPct >= 0 ? '+' : '', $stMonthlyPct);
?>
<div class="avoid-break" style="margin-top:16px;">
    <p style="font-size:10.5px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Sale Prices Over Time</p>
    <svg viewBox="0 0 <?= $stW ?> <?= $stH ?>" style="width:100%;max-width:<?= $stW ?>px;height:auto;background:var(--bg);border:1px solid var(--border);border-radius:4px;">
        <?php // Plot frame ?>
        <line x1="<?= $stPadL ?>" y1="<?= $stPadT ?>" x2="<?= $stPadL ?>" y2="<?= $stH - $stPadB ?>" stroke="#cbd5e1" stroke-width="0.75"/>
        <line x1="<?= $stPadL ?>" y1="<?= $stH - $stPadB ?>" x2="<?= $stW - $stPadR ?>" y2="<?= $stH - $stPadB ?>" stroke="#cbd5e1" stroke-width="0.75"/>

        <?php // Y-axis labels — min, midpoint, max. ?>
        <?php $stMidP = (int) (($cMinP + $cMaxP) / 2); ?>
        <text x="<?= $stPadL - 6 ?>" y="<?= $stPadT + 4 ?>" text-anchor="end" font-size="10" fill="#64748b"><?= $zar($cMaxP) ?></text>
        <text x="<?= $stPadL - 6 ?>" y="<?= $stPadT + $stPlotH / 2 + 3 ?>" text-anchor="end" font-size="10" fill="#94a3b8"><?= $zar($stMidP) ?></text>
        <text x="<?= $stPadL - 6 ?>" y="<?= $stH - $stPadB + 3 ?>" text-anchor="end" font-size="10" fill="#64748b"><?= $zar($cMinP) ?></text>
        <line x1="<?= $stPadL ?>" y1="<?= round($stPadT + $stPlotH / 2, 1) ?>" x2="<?= $stW - $stPadR ?>" y2="<?= round($stPadT + $stPlotH / 2, 1) ?>" stroke="#e2e8f0" stroke-width="0.5" stroke-dasharray="2,3"/>

        <?php // Asking-price reference (dashed). Render in-band only — out-of-band asking
              // would just sit on the frame edge and confuse the reader. ?>
        <?php if ($askingPrice && $askingPrice >= $cMinP && $askingPrice <= $cMaxP): ?>
        <?php $stAskY = round($stPy((float) $askingPrice), 1); ?>
        <line x1="<?= $stPadL ?>" y1="<?= $stAskY ?>" x2="<?= $stW - $stPadR ?>" y2="<?= $stAskY ?>" stroke="#dc2626" stroke-width="1" stroke-dasharray="4,3" opacity="0.7"/>
        <text x="<?= $stW - $stPadR - 4 ?>" y="<?= $stAskY - 4 ?>" text-anchor="end" font-size="10" fill="#dc2626" font-weight="700">Asking <?= $zar((int) $askingPrice) ?></text>
        <?php endif ?>

        <?php // Trend line (least-squares regression). ?>
        <line x1="<?= round($stPx($cMinD), 1) ?>" y1="<?= round($stPy($stTrendStart), 1) ?>" x2="<?= round($stPx($cMaxD), 1) ?>" y2="<?= round($stPy($stTrendEnd), 1) ?>" stroke="#0b2a4a" stroke-width="1.75" opacity="0.85"/>

        <?php // Monthly x-axis ticks. ?>
        <?php foreach ($stTicks as $stI => $stTk):
            if ($stI % $stTickEvery !== 0) continue;
            $stTickX = round($stPx($stTk), 1);
        ?>
        <line x1="<?= $stTickX ?>" y1="<?= $stH - $stPadB ?>" x2="<?= $stTickX ?>" y2="<?= $stH - $stPadB + 4 ?>" stroke="#94a3b8" stroke-width="0.75"/>
        <text x="<?= $stTickX ?>" y="<?= $stH - $stPadB + 16 ?>" text-anchor="middle" font-size="9.5" fill="#64748b"><?= date('M y', $stTk) ?></text>
        <?php endforeach ?>

        <?php // Data points. ?>
        <?php foreach ($chartSales as $cs):
            $stDx = round($stPx(strtotime($cs['sale_date'])), 1);
            $stDy = round($stPy((float) $cs['sale_price']), 1);
        ?>
        <circle cx="<?= $stDx ?>" cy="<?= $stDy ?>" r="3.5" fill="#0b2a4a" stroke="#fff" stroke-width="1.25"/>
        <?php endforeach ?>

        <?php // Trend tag — bottom-left of the plot area. ?>
        <text x="<?= $stPadL + 6 ?>" y="<?= $stPadT + 14 ?>" font-size="10" font-weight="700" fill="#0b2a4a"><?= $esc($stTrendLabel) ?></text>
    </svg>
</div>
<?php endif ?>

<?php else: ?>
<div class="callout callout-info">No vicinity sales data available for this property.</div>
<?php endif ?>
<?php endif // /recent_sales ?>

<?php // Beat 2 internal order — Market Overview context strip emits
      // AFTER Recent Sales per spec §1 (B2-followup-3 reorder via
      // captured $beat2MarketOverviewHtml). ?>
<?= $beat2MarketOverviewHtml ?? '' ?>

<?php // Phase 3g V2 Part D4/D5 — Spatial View SVG. Only renders when the
      // subject property has resolved GPS + at least one comp with GPS.
      // Build 4 — also gated by section toggle. ?>
<?php if ($sectionEnabled('spatial_view')): ?>
<?php
    $_propertyForMap = $presentation->property_id ? \App\Models\Property::withoutGlobalScopes()->find($presentation->property_id) : null;
    $_subjLat = $_propertyForMap?->latitude;
    $_subjLng = $_propertyForMap?->longitude;
    $_svgComps = [];
    if ($_subjLat !== null && $_subjLng !== null) {
        foreach ($presentation->soldComps as $_sc) {
            $_raw = is_string($_sc->raw_row_json) ? (json_decode($_sc->raw_row_json, true) ?: []) : ((array) $_sc->raw_row_json ?: []);
            $_lat = $_raw['latitude'] ?? null;
            $_lng = $_raw['longitude'] ?? null;
            $_compRowId = $_raw['mic_comp_row_id'] ?? null;
            $_schemeName = $_raw['scheme_name'] ?? null;
            if (($_lat === null || $_lng === null) && $_compRowId) {
                $_gps = \Illuminate\Support\Facades\DB::table('market_report_comp_rows')
                    ->where('id', $_compRowId)
                    ->first(['latitude', 'longitude', 'scheme_name']);
                if ($_gps) {
                    if ($_gps->latitude !== null && $_gps->longitude !== null) {
                        $_lat = (float) $_gps->latitude; $_lng = (float) $_gps->longitude;
                    }
                    $_schemeName = $_schemeName ?: $_gps->scheme_name;
                }
            }
            // Scheme-name fallback — inherit from any matching subject report.
            if (($_lat === null || $_lng === null) && $_schemeName) {
                $_mr = \Illuminate\Support\Facades\DB::table('market_reports')
                    ->whereRaw('LOWER(subject_scheme_name) = ?', [mb_strtolower($_schemeName)])
                    ->whereNotNull('subject_latitude')->whereNotNull('subject_longitude')
                    ->orderByDesc('id')
                    ->first(['subject_latitude', 'subject_longitude']);
                if ($_mr) { $_lat = (float) $_mr->subject_latitude; $_lng = (float) $_mr->subject_longitude; }
            }
            if ($_lat === null || $_lng === null) continue;
            $_svgComps[] = [
                'lat'       => (float) $_lat,
                'lng'       => (float) $_lng,
                // Never-blank label — same chain as the review screen
                // / PDF table render. Sectional comps without street
                // address resolve to "Scheme, Section N" instead of
                // empty tooltips.
                'title'     => CompLabel::build($_raw, $_sc->suburb ?? null, $_sc->id ?? null),
                'layer'     => 'sold_comps',
                'price'     => $_sc->sold_price_inc ? (int) $_sc->sold_price_inc : null,
                'sale_date' => $_sc->sold_date ? $_sc->sold_date->toDateString() : null,
            ];
        }
        // AT-27 fix 1 — the separate MIC-fed 'active_listings' map layer is
        // REMOVED. It plotted presentation_active_listings (market_report_comp_
        // rows), which is NOT type-gated and NOT sold-excluded, so it leaked
        // sold + wrong-type "Residence" rows (the J/K Claverhouse/Topanga pins)
        // that every other surface had already discarded. Active competition is
        // now ONE set: the unified, type-gated, sold-excluded competitor_stock
        // layer plotted just below.

        // CMA-map — Active Competition layer (Build's new orange diamond
        // on the review screen). Reads competitor_stock.visible from the
        // compiled data so the PDF shows EXACTLY what the agent ticked.
        // No fake fallback pins — rows without lat/lng silently skip
        // and the caption surfaces the count.
        $_competitorVisible = $data['competitor_stock']['visible'] ?? [];
        foreach ($_competitorVisible as $_cv) {
            $_lat = $_cv['latitude']  ?? null;
            $_lng = $_cv['longitude'] ?? null;
            if ($_lat === null || $_lng === null) continue;
            $_svgComps[] = [
                'lat'       => (float) $_lat,
                'lng'       => (float) $_lng,
                'title'     => $_cv['address'] ?? ('Listing #' . ($_cv['listing_id'] ?? '')),
                'layer'     => 'competitor_stock',
                'price'     => isset($_cv['price']) ? (int) $_cv['price'] : null,
                'sale_date' => null,
            ];
        }
    }

    // PDF map provider selection. Build 8 — flipped the default so that
    // a real Google Static Map is rendered whenever a Maps Static key is
    // configured. Reads better for the seller (an actual map of their
    // area beats the polar abstraction) and the renderer already exists.
    // The radial SVG stays as the no-key FALLBACK so an agency without
    // a Maps key still gets a usable spatial view. The agencies column
    // (presentations_map_provider) becomes vestigial here — keep it on
    // the model in case a future build wants per-agency opt-out, but
    // the key presence is the live selector.
    $_mapsKeyConfigured = (string) config('services.google.static_maps_api_key', '') !== '';
    $_mapProvider = $_mapsKeyConfigured ? 'static_image' : 'svg_radial';
    $_staticMapDataUri = null;
    if ($_mapProvider === 'static_image' && $_subjLat !== null && $_subjLng !== null) {
        $_subjForStatic = ['lat' => (float) $_subjLat, 'lng' => (float) $_subjLng, 'title' => $address];
        // Re-bucket _svgComps into sold-comp + competition lists for the
        // static service, which needs them separately to colour-tag.
        $_staticSold = [];
        $_staticComp = [];
        foreach ($_svgComps as $_pt) {
            if (($_pt['layer'] ?? '') === 'competitor_stock') {
                $_staticComp[] = [
                    'latitude' => $_pt['lat'], 'longitude' => $_pt['lng'],
                    'title' => $_pt['title'] ?? null, 'price' => $_pt['price'] ?? null,
                ];
            } else {
                $_staticSold[] = [
                    'lat' => $_pt['lat'], 'lng' => $_pt['lng'],
                    'title_type' => $_pt['title_type'] ?? null,
                    'title' => $_pt['title'] ?? null, 'price' => $_pt['price'] ?? null,
                    'sale_date' => $_pt['sale_date'] ?? null, 'layer' => $_pt['layer'] ?? 'sold_comps',
                ];
            }
        }
        // AT-22 §3 — renderBase64() returns ['data_uri'=>?, 'legend'=>[...]].
        $_staticResult = (new \App\Services\Presentations\Pdf\PresentationStaticMapService())
            ->renderBase64($_subjForStatic, $_staticSold, $_staticComp, 640, 480);
        $_staticMapDataUri = $_staticResult['data_uri'] ?? null;
        $_mapLegend = $_staticResult['legend'] ?? [];
    }
    // AT-22 §3 — shared map-legend renderer. The map face carries numbered
    // pins only (no overprinting address labels); this legend keys each
    // number → address/price/date/distance/layer below the map. Engine is
    // Chromium (HTML table renders cleanly). Used by both map paths.
    $_mapLegend = $_mapLegend ?? [];
    $_renderMapLegend = function (array $legend): string {
        if (empty($legend)) { return ''; }
        ob_start();
        ?>
<?php /* AT-22 R2 item 4 — the legend can run long (subject + comps +
         competition). Repeat the header on each page (table-header-group)
         and keep each row intact (page-break-inside:avoid) so it flows
         cleanly across a page boundary instead of splitting mid-row. */ ?>
<table style="width:100%;border-collapse:collapse;margin-top:10px;font-size:10px;">
    <thead style="display:table-header-group;">
        <tr style="border-bottom:1px solid #e2e8f0;color:#64748b;text-align:left;">
            <th style="padding:3px 6px;width:28px;">#</th>
            <th style="padding:3px 6px;">Address</th>
            <th style="padding:3px 6px;text-align:right;">Price</th>
            <th style="padding:3px 6px;">Date</th>
            <th style="padding:3px 6px;text-align:right;">Distance</th>
            <th style="padding:3px 6px;">Layer</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($legend as $_lg): ?>
        <tr style="border-bottom:1px solid #f1f5f9;page-break-inside:avoid;">
            <td style="padding:3px 6px;">
                <span style="display:inline-block;width:16px;height:16px;line-height:16px;border-radius:50%;text-align:center;color:#fff;font-weight:700;background:<?= htmlspecialchars((string) ($_lg['colour'] ?? '#64748b'), ENT_QUOTES) ?>;"><?= htmlspecialchars((string) ($_lg['label_glyph'] ?? $_lg['index'] ?? ''), ENT_QUOTES) ?></span>
            </td>
            <td style="padding:3px 6px;"><?= htmlspecialchars((string) ($_lg['title'] ?? ''), ENT_QUOTES) ?></td>
            <td style="padding:3px 6px;text-align:right;"><?= isset($_lg['price']) && $_lg['price'] !== null ? 'R ' . number_format((int) $_lg['price'], 0, '.', ' ') : '—' ?></td>
            <td style="padding:3px 6px;"><?= !empty($_lg['sale_date']) ? \Carbon\Carbon::parse($_lg['sale_date'])->format('M Y') : '—' ?></td>
            <td style="padding:3px 6px;text-align:right;"><?= isset($_lg['distance_m']) && $_lg['distance_m'] !== null ? ((int) $_lg['distance_m'] >= 1000 ? number_format($_lg['distance_m'] / 1000, 1) . ' km' : (int) $_lg['distance_m'] . ' m') : '—' ?></td>
            <td style="padding:3px 6px;"><?= htmlspecialchars(ucwords(str_replace('_', ' ', (string) ($_lg['layer'] ?? ''))), ENT_QUOTES) ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
        <?php
        return (string) ob_get_clean();
    };
?>
<?php if ($_subjLat !== null && $_subjLng !== null && $_staticMapDataUri !== null): ?>
<?php // Static-image path — Google Static Maps PNG, embedded base64.
      // Caption shows plotted/unplotted counts honestly. ?>
<div style="margin-top:18px;">
    <h3 style="margin-bottom:8px;">CMA Map — Subject + Sold Comps + Active Competition</h3>
    <img src="<?= $_staticMapDataUri ?>" alt="CMA map" style="width:100%;max-width:640px;height:auto;border:1px solid #e2e8f0;border-radius:6px;">
    <p style="font-size:10px;color:#64748b;margin-top:4px;">
        Numbered pins keyed to the legend below ·
        Sold comps: <?= count($_svgComps) - (isset($_staticComp) ? count($_staticComp) : 0) ?> plotted ·
        Active competition: <?= isset($_staticComp) ? count($_staticComp) : 0 ?> plotted ·
        Subject = S pin.
    </p>
    <?= $_renderMapLegend($_mapLegend) ?>
</div>
<?php elseif ($_subjLat !== null && $_subjLng !== null && !empty($_svgComps)): ?>
<?php // Radial SVG path — default + fallback. SpatialViewSvgRenderer now
      // includes the competitor_stock layer (amber, matches the review
      // map's orange diamond palette). ?>
<div style="margin-top:18px;">
    <h3 style="margin-bottom:8px;">Spatial View — Subject + Comps + Competition</h3>
    <?php
        // AT-22 §3 — render() now returns ['svg'=>…, 'legend'=>[…]]. The
        // map face has numbered pins only; the legend (below) carries the
        // detail. No more overprinting address labels on the map.
        $_svgResult = (new \App\Services\Presentations\Pdf\SpatialViewSvgRenderer())->render(
            ['lat' => (float) $_subjLat, 'lng' => (float) $_subjLng, 'title' => $address],
            $_svgComps,
            540, 360,
        );
        $_mapLegend = $_svgResult['legend'] ?? [];
    ?>
    <?= $_svgResult['svg'] ?? '' ?>
    <p style="font-size:10px;color:#64748b;margin-top:4px;">
        Numbered pins keyed to the legend below · Subject at centre ·
        <?= count($_svgComps) ?> data point<?= count($_svgComps) === 1 ? '' : 's' ?> within view ·
        Distances Haversine-corrected · Compass: north up
    </p>
    <?= $_renderMapLegend($_mapLegend) ?>
</div>
<?php elseif ($_subjLat === null || $_subjLng === null): ?>
<?php // Empty state — subject property has no resolved GPS. Surface this
      // in the PDF so the agent sees the spatial view is missing because
      // of stale geocoding, not because no comps were found. ?>
<div class="callout callout-info" style="margin-top:14px;">
    Spatial View unavailable — subject property has no resolved GPS pin.
    Open the property and use the Map strip to set the pin, then regenerate this PDF.
</div>
<?php endif ?>

<?php endif // /spatial_view ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // BEAT 4 (part 1) — Comparative Market Analysis. B2-followup-2:
      // captured into $beat4CmaHtml via ob_start so the call-site emit
      // can re-order beats (Beat 3 must render before Beat 4).
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php ob_start(); ?>
<?php if ($sectionEnabled('cma_analysis')): ?>
<div class="page-break"></div>
<div class="beat-eyebrow">Section <?= $summary['section_index']['recommendation'] ?? 6 ?> · Beat 4 — Where You Should Be</div>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Comparative Market Analysis</h2>
</div>

<div class="section-intro avoid-break">
    Bringing the sales together, this is where your property's <strong>value range</strong>
    comes from. The spread shows the realistic band — most homes like yours sold within it.
    Pricing inside this range puts you where buyers are actively transacting; pricing above
    it asks buyers to pay more than the market has recently supported.
</div>

<?php if ($cmaLower || $cmaMiddle || $cmaUpper): ?>
<h3 style="margin-bottom:10px;color:var(--brand);">CMA Valuation Range</h3>

<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Lower Range</div>
        <div class="value"><?= $zar($cmaLower) ?></div>
    </div>
    <div class="metric-card highlight">
        <div class="label">CMA Valuation</div>
        <div class="value"><?= $zar($cmaMiddle) ?></div>
    </div>
    <div class="metric-card">
        <div class="label">Upper Range</div>
        <div class="value"><?= $zar($cmaUpper) ?></div>
    </div>
</div>

<?php if ($subject['municipal_value']): ?>
<p style="font-size:11px;color:var(--text-muted);margin:8px 0;">
    Municipal Valuation<?php if ($subject['municipal_year']): ?> (<?= $esc($subject['municipal_year']) ?>)<?php endif ?>:
    <strong><?= $zar($subject['municipal_value']) ?></strong>
</p>
<?php endif ?>

<?php // Vicinity ranges if different from CMA ?>
<?php if ($cma['vicinity_lower'] || $cma['vicinity_middle'] || $cma['vicinity_upper']): ?>
<div class="avoid-break" style="margin-top:14px;">
<h3 style="margin-bottom:8px;">Vicinity Sales Range</h3>
<table>
    <thead><tr><th>Measure</th><th class="num">Value</th></tr></thead>
    <tbody>
        <tr><td>Lower Range</td><td class="num"><?= $zar($cma['vicinity_lower']) ?></td></tr>
        <tr><td>Middle Range</td><td class="num"><?= $zar($cma['vicinity_middle']) ?></td></tr>
        <tr><td>Upper Range</td><td class="num"><?= $zar($cma['vicinity_upper']) ?></td></tr>
        <?php if ($cma['vicinity_ppm2']): ?>
        <tr><td>Average per m²</td><td class="num">R <?= number_format($cma['vicinity_ppm2']) ?></td></tr>
        <?php endif ?>
    </tbody>
</table>
</div>
<?php endif ?>
<?php endif ?>

<?php // CMA Comps table ?>
<?php if (!empty($cmaComps)): ?>
<div class="avoid-break" style="margin-top:18px;">
<h3 style="margin-bottom:8px;">CMA Comparable Properties (<?= count($cmaComps) ?>)</h3>
<table>
    <thead>
        <tr>
            <th>Address</th>
            <th>Dist.</th>
            <th class="num"><?= $esc($sizeLabel) ?></th>
            <th>Sale Date</th>
            <th class="num">Sale Price</th>
            <th class="num">Per m²</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($cmaComps as $comp): ?>
        <tr>
            <td><?= $esc($comp['address'] ?? '—') ?></td>
            <td><?= $comp['distance_m'] ? $comp['distance_m'] . 'm' : '—' ?></td>
            <td class="num"><?= $comp['extent_m2'] ? number_format((int) $comp['extent_m2']) : '—' ?></td>
            <td><?= $esc($comp['sale_date'] ?? '—') ?></td>
            <td class="num"><?= $zar($comp['sale_price'] ?? null) ?></td>
            <td class="num"><?= isset($comp['price_per_m2']) && $comp['price_per_m2'] ? 'R ' . number_format((int) $comp['price_per_m2']) : '—' ?></td>
        </tr>
        <?php endforeach ?>
    </tbody>
    <?php
        $cmaPrices = array_filter(array_column($cmaComps, 'sale_price'), fn($v) => $v > 0);
        $cmaPpm2   = array_filter(array_column($cmaComps, 'price_per_m2'), fn($v) => $v > 0);
        $cmaAvgP   = count($cmaPrices) > 0 ? (int) round(array_sum($cmaPrices) / count($cmaPrices)) : null;
        $cmaAvgPpm2 = count($cmaPpm2) > 0 ? (int) round(array_sum($cmaPpm2) / count($cmaPpm2)) : null;
    ?>
    <?php if ($cmaAvgP): ?>
    <tfoot>
        <tr class="table-summary">
            <td colspan="4"><strong>Average</strong></td>
            <td class="num"><?= $zar($cmaAvgP) ?></td>
            <td class="num"><?= $cmaAvgPpm2 ? 'R ' . number_format($cmaAvgPpm2) : '—' ?></td>
        </tr>
    </tfoot>
    <?php endif ?>
</table>
</div>
<?php endif ?>

<?php // CHART 4: Comp Price Distribution histogram — Build 8 legibility
      // pass. Bigger labels, explicit bucket ranges ("R 1.5M–R 1.7M"
      // rather than "R1500k"), counts above each bar, and a red dashed
      // median line over the grid. Asking-price bucket stays highlighted
      // in blue. Math unchanged — the bucket-size heuristic continues to
      // round to the nearest R50k that keeps the chart around 6 bars.
?>
<?php
    $allCompPrices = array_merge(
        array_filter(array_column($vicinitySales, 'sale_price'), fn($v) => $v > 0),
        array_filter(array_column($cmaComps, 'sale_price'), fn($v) => $v > 0)
    );
    if (count($allCompPrices) >= 3):
        $cpMin = min($allCompPrices);
        $cpMax = max($allCompPrices);
        $cpRange = $cpMax - $cpMin;
        $cpBktSize = max(50000, (int) (ceil($cpRange / 6 / 50000) * 50000));
        $cpStart = (int) (floor($cpMin / $cpBktSize) * $cpBktSize);
        $cpBuckets = [];
        foreach ($allCompPrices as $cp) {
            $idx = (int) floor(($cp - $cpStart) / $cpBktSize);
            $cpBuckets[$idx] = ($cpBuckets[$idx] ?? 0) + 1;
        }
        $cpMaxBkt = max(1, max($cpBuckets));
        $askBktIdx = ($askingPrice && $askingPrice > 0) ? (int) floor(($askingPrice - $cpStart) / $cpBktSize) : null;
        $cpNumBkts = max(array_keys($cpBuckets)) + 1;

        // Median of the comparable sale prices. Used to render a red
        // dashed reference line over the histogram so the seller sees
        // the middle of the comp range — independent of the highlighted
        // asking-price bucket.
        $cpSorted = $allCompPrices;
        sort($cpSorted);
        $cpN = count($cpSorted);
        $cpMedian = $cpN % 2 === 1
            ? $cpSorted[(int) ($cpN / 2)]
            : (int) (($cpSorted[$cpN / 2 - 1] + $cpSorted[$cpN / 2]) / 2);
        // Position the median line as a percentage of the bar-chart width.
        // Each bar occupies (100 / $cpNumBkts)% of the grid; the median's
        // fractional bucket position gives a precise vertical line.
        $cpMedianFrac = $cpBktSize > 0
            ? ($cpMedian - $cpStart) / ($cpBktSize * $cpNumBkts)
            : 0;
        $cpMedianPct  = max(0, min(100, $cpMedianFrac * 100));

        // Human-readable price formatter for axis labels. Keeps small
        // figures in "k" and rolls into "M" with one decimal once we
        // cross R1m — matches how SA estate agents quote prices.
        $cpFmt = function (int $p): string {
            if ($p >= 1_000_000) {
                $m = $p / 1_000_000;
                $s = rtrim(rtrim(number_format($m, 1, '.', ''), '0'), '.');
                return 'R ' . $s . 'M';
            }
            return 'R ' . number_format($p / 1000, 0) . 'k';
        };
?>
<div class="avoid-break" style="margin-top:18px;">
    <p style="font-size:10.5px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:6px;">Comparable Sales Price Distribution (<?= count($allCompPrices) ?> sales)</p>
    <div class="bar-chart-wrap">
        <div class="bar-chart" style="height:140px;align-items:flex-end;">
            <?php for ($bi = 0; $bi < $cpNumBkts; $bi++):
                $bCount = $cpBuckets[$bi] ?? 0;
                $bPct = round($bCount / $cpMaxBkt * 100);
                $bLow  = $cpStart + $bi * $cpBktSize;
                $bHigh = $bLow + $cpBktSize;
                $isAsk = $bi === $askBktIdx;
            ?>
            <div class="bar-col">
                <div class="bar-count" style="<?= $bCount === 0 ? 'opacity:0;' : '' ?>"><?= $bCount ?></div>
                <div class="bar" style="height:<?= max(2, $bPct) ?>%;background:<?= $isAsk ? '#2563eb' : 'var(--brand)' ?>;<?= $isAsk ? 'box-shadow:0 0 0 2px #2563eb33;' : '' ?>"></div>
                <div class="bar-label"><?= $cpFmt($bLow) ?>–<?= $cpFmt($bHigh) ?></div>
            </div>
            <?php endfor ?>
        </div>
        <?php // Median reference line — runs over the bar grid only.
              // The label sits just above the bars so it doesn't collide
              // with the bucket range labels below. ?>
        <div class="median-line" style="left:<?= number_format($cpMedianPct, 2) ?>%;height:108px;"></div>
        <div class="median-line-label" style="left:<?= number_format($cpMedianPct, 2) ?>%;">Typical price <?= $cpFmt((int) $cpMedian) ?></div>
    </div>
    <p style="font-size:10px;text-align:center;color:var(--text-muted);margin-top:6px;">
        <?php if ($askBktIdx !== null): ?>
            <span style="color:#2563eb;font-weight:700;">■</span> Your asking-price bracket
            &nbsp;·&nbsp;
        <?php endif ?>
        <span style="color:#dc2626;font-weight:700;">┊</span> Typical sold price across <?= count($allCompPrices) ?> comparable sales (<?= $cpFmt((int) $cpMedian) ?>)
    </p>
</div>
<?php endif ?>

<?php endif // /cma_analysis ?>
<?php $beat4CmaHtml = ob_get_clean(); ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // BEAT 3 — What's On The Market Now (Active Competition).
      // B2-followup-2: captured into $beat3Html so the call-site emit
      // can render it BEFORE Beat 4 (CMA) per spec §1 beat order.
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php ob_start(); ?>
<?php if ($sectionEnabled('active_competition')): ?>
<div class="page-break"></div>
<div class="beat-eyebrow">Section <?= $summary['section_index']['competition'] ?? 5 ?> · Beat 3 — What's On The Market Now</div>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Active Competition</h2>
</div>

<div class="section-intro avoid-break">
    These are the homes a buyer will see alongside yours, right now, today. A buyer
    comparing options will weigh your price, size and condition against these. Where you
    sit in this set directly shapes how quickly you attract serious interest — being the
    <strong>best-value option</strong> in the group is what moves a property.
</div>
<?php
    // AT-22 R2 item 2 — unify the Active Competition table with the page-8
    // cards, the spatial pins (21–30) and the headline count: ALL read the
    // scored competitor stock (competitor_stock.visible, from prospecting_
    // listings). The table previously read the stale active_competition.rows
    // (legacy MIC/portal-capture pipeline), contradicting the rest of the
    // report. Average is computed from the SAME visible set.
    $compVisible = $data['competitor_stock']['visible'] ?? [];
    $compPrices  = array_values(array_filter(array_map(fn ($c) => (int) ($c['price'] ?? 0), $compVisible), fn ($p) => $p > 0));
    $compAvg     = count($compPrices) ? (int) round(array_sum($compPrices) / count($compPrices)) : null;
?>
<p style="font-size:10.5px;color:var(--text-muted);margin:0 0 12px 0;">
    Your home competes against
    <strong><?= $competingCount ?> active listing<?= $competingCount !== 1 ? 's' : '' ?></strong>
    scored on price, suburb, type, and bedrooms.<?php if ($compAvg): ?> Average asking price across the table below: <strong><?= $zar($compAvg) ?></strong>.<?php endif ?>
</p>

<?php if (!empty($compVisible)): ?>
<p style="font-size:9.5px;color:var(--text-muted);text-transform:uppercase;letter-spacing:0.04em;font-weight:600;margin:14px 0 4px 0;">
    Active listings on the market now
</p>
<table>
    <thead>
        <tr>
            <th>Address</th>
            <th>Type</th>
            <th class="num"><?= $esc($sizeLabel) ?></th>
            <th>Listed</th>
            <th class="num">Asking Price</th>
            <th class="num">DOM</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($compVisible as $c): ?>
        <tr style="page-break-inside:avoid;">
            <td><?= $esc($c['address'] ?? ('Listing #' . ($c['listing_id'] ?? '—'))) ?></td>
            <td><?= $esc($c['property_type'] ?? '—') ?></td>
            <td class="num"><?php
                $cErf = !empty($c['erf_size_m2']) ? (int) $c['erf_size_m2'] : (!empty($c['property_size_m2']) ? (int) $c['property_size_m2'] : null);
                echo $cErf ? number_format($cErf) : '—';
            ?></td>
            <td><?= !empty($c['listed_date']) ? $esc($c['listed_date']) : (!empty($c['captured_at']) ? $esc(\Carbon\Carbon::parse($c['captured_at'])->format('Y-m-d')) : '—') ?></td>
            <td class="num"><?= $zar($c['price'] ?? null) ?></td>
            <td class="num"><?= $c['days_on_market'] ?? '—' ?></td>
        </tr>
        <?php endforeach ?>
    </tbody>
    <?php if ($compAvg): ?>
    <tfoot>
        <tr class="table-summary">
            <td colspan="4"><strong>Average</strong></td>
            <td class="num"><?= $zar($compAvg) ?></td>
            <td class="num"></td>
        </tr>
    </tfoot>
    <?php endif ?>
</table>
<?php else: ?>
<div class="callout callout-info">No scored competitor stock for this property yet. Competitors are matched from prospecting listings on price, suburb, type and bedrooms.</div>
<?php endif ?>

<?php // CHART 5: Competition Price Bracket Bars ?>
<?php if (!empty($priceBrackets['brackets']) && count($priceBrackets['brackets']) >= 2): ?>
<div class="avoid-break" style="margin-top:18px;">
    <p style="font-size:9px;text-transform:uppercase;letter-spacing:0.05em;color:var(--text-muted);font-weight:600;margin-bottom:8px;">Your Competition at Each Price Level</p>
    <?php foreach ($priceBrackets['brackets'] as $bkt): ?>
    <div class="hbar-row">
        <div class="hbar-label"><?= 'R' . number_format($bkt['lower'] / 1000, 0) . 'k–R' . number_format($bkt['upper'] / 1000, 0) . 'k' ?></div>
        <div class="hbar-track">
            <div class="hbar-fill" style="width:<?= max(4, $bkt['bar_pct']) ?>%;background:<?= $bkt['contains_asking'] ? '#2563eb' : 'var(--brand)' ?>;">
                <?php if ($bkt['count'] > 0): ?><span><?= $bkt['count'] ?></span><?php endif ?>
            </div>
        </div>
        <div class="hbar-count" style="<?= $bkt['contains_asking'] ? 'color:#2563eb;' : '' ?>"><?= $bkt['count'] ?></div>
    </div>
    <?php endforeach ?>
    <?php if ($askingPrice): ?>
    <p style="font-size:8px;text-align:center;color:#2563eb;font-weight:600;margin-top:4px;">Blue bar = your asking price bracket</p>
    <?php endif ?>
</div>
<?php endif ?>

<?php // P24 Links ?>
<?php if ($p24Links->isNotEmpty()): ?>
<div class="avoid-break" style="margin-top:14px;">
<h3 style="margin-bottom:6px;font-size:11px;color:var(--text-muted);">Property24 Sources</h3>
<ul style="font-size:10px;list-style:none;padding:0;">
    <?php foreach ($p24Links as $link): ?>
    <li style="margin-bottom:4px;">
        <a href="<?= $esc($link->url) ?>" target="_blank"><?= $esc($link->url) ?></a>
    </li>
    <?php endforeach ?>
</ul>
</div>
<?php endif ?>

<?php if ($monthsOfSupply !== null): ?>
<?php
    $compAbsClass = match($absorptionColor) {
        'green' => 'callout-success', 'amber' => 'callout-warning',
        'orange' => 'callout-warning', 'red' => 'callout-danger',
        default => 'callout-info',
    };
?>
<div class="callout <?= $compAbsClass ?>" style="margin-top:14px;">
    <strong>How fast this suburb is selling:</strong>
    About <?= (int) ($stock['annual_sales'] ?? 0) ?> homes change hands each year
    (roughly <?= number_format($absorptionRate ?? 0, 1) ?> a month).
    At that pace, today's listings would clear in about
    <strong><?= number_format($monthsOfSupply, 1) ?> months</strong>
    — <?= $absorptionLabel ?>
</div>
<?php endif ?>

<?php // Price Position callout ?>
<?php if (!empty($pricePosition['has_data'])): ?>
<?php
    $posCalloutClass = match($pricePosition['position_color'] ?? '') {
        'green' => 'callout-success', 'amber' => 'callout-warning',
        'orange' => 'callout-warning', 'red' => 'callout-danger',
        default => 'callout-info',
    };
?>
<div class="callout <?= $posCalloutClass ?>" style="margin-top:10px;">
    <strong>Where you sit against the active competition:</strong>
    Of the <?= $pricePosition['total_listings'] ?> homes a buyer can compare yours to right now,
    <?= $pricePosition['listings_more_expensive'] ?> are priced higher than yours
    and <?= $pricePosition['listings_cheaper'] ?> priced lower.
    <?= $pricePosition['position_label'] ?>.
</div>
<?php endif ?>

<?php // Price Bracket Distribution ?>
<?php if (!empty($priceBrackets['has_data']) && !empty($priceBrackets['brackets'])): ?>
<div class="avoid-break" style="margin-top:14px;">
<h3 style="margin-bottom:8px;font-size:11px;color:var(--text-muted);">Price Distribution (<?= $priceBrackets['total_priced'] ?> listings)</h3>
<?php foreach ($priceBrackets['brackets'] as $bracket): ?>
<div style="display:flex;align-items:center;gap:6px;margin-bottom:3px;<?= $bracket['contains_asking'] ? 'background:#eef2ff;border:1px solid #c7d2fe;border-radius:4px;padding:3px 6px;margin-left:-6px;margin-right:-6px;' : '' ?>">
    <div style="width:140px;text-align:right;font-size:9.5px;color:var(--text-muted);flex-shrink:0;font-family:monospace;"><?= $bracket['label'] ?></div>
    <div style="flex:1;background:#f3f4f6;border-radius:999px;height:14px;overflow:hidden;">
        <?php if ($bracket['bar_pct'] > 0): ?>
        <div style="width:<?= max($bracket['bar_pct'], 4) ?>%;height:100%;background:<?= $bracket['contains_asking'] ? 'var(--brand-accent)' : '#94a3b8' ?>;border-radius:999px;"></div>
        <?php endif ?>
    </div>
    <div style="width:24px;text-align:right;font-size:10px;font-weight:600;color:var(--text);"><?= $bracket['count'] ?></div>
    <?php if ($bracket['contains_asking']): ?>
    <div style="font-size:9px;color:var(--brand-accent);font-weight:600;flex-shrink:0;width:50px;">Your price</div>
    <?php else: ?>
    <div style="width:50px;"></div>
    <?php endif ?>
</div>
<?php endforeach ?>
</div>
<?php endif ?>

<?php
// ── Competitor Stock — scored Active Competition, Core Matches engine.
//    Renders ONLY the ticked competitors (included_competitor_ids_json
//    on the version, or all when null = first paint). Each card shows
//    match % + tier + per-card link to the source portal (P24/PP).
//    HFC-owned stock gets a DOM/views badge from the PropCon join.
$competitorStock = $data['competitor_stock'] ?? ['visible' => []];
$visibleCompetitors = $competitorStock['visible'] ?? [];
if (!empty($visibleCompetitors)):
?>
<h3 style="margin-top:18px;margin-bottom:8px;">Scored Competitor Stock (<?= count($visibleCompetitors) ?>)</h3>
<p style="margin:0 0 10px 0;font-size:11px;color:#64748b;">
    Active listings the property competes against, scored by Core Matches.
    Match % reflects proximity by price, suburb, type, and bedrooms.
</p>

<!-- Photo-card grid — same visual as the review-screen Active Competition
     cards. DomPDF renders local file paths natively for the thumbnails
     (no remote fetch). Cards without a cached thumbnail render the
     placeholder icon — matches the CAPTURED PROPERTIES card behaviour. -->
<table style="width:100%;border-collapse:separate;border-spacing:8px;">
<?php
$visibleCount = count($visibleCompetitors);
$columns      = 2;
for ($rowStart = 0; $rowStart < $visibleCount; $rowStart += $columns):
?>
    <tr>
    <?php for ($col = 0; $col < $columns; $col++):
        $idx = $rowStart + $col;
        if (!isset($visibleCompetitors[$idx])):
            ?><td style="width:50%;"></td><?php
            continue;
        endif;
        $c = $visibleCompetitors[$idx];
        $tierBg = match ($c['tier'] ?? '') {
            'perfect'     => '#ecfdf5',
            'strong'      => '#eff6ff',
            'approximate' => '#fefce8',
            default       => '#f8fafc',
        };
        $tierColor = match ($c['tier'] ?? '') {
            'perfect'     => '#10b981',
            'strong'      => '#0ea5e9',
            'approximate' => '#a16207',
            default       => '#475569',
        };
        $tierLabel = ucfirst((string) ($c['tier'] ?? 'Match'));
        $title     = $c['address'] ?? ('Listing #' . $c['listing_id']);
        $thumbAbs  = $c['thumbnail_abs_path'] ?? null;
        $stats     = [];
        if (!empty($c['bedrooms']))         $stats[] = (int) $c['bedrooms']  . ' bed';
        if (!empty($c['bathrooms']))        $stats[] = (int) $c['bathrooms'] . ' bath';
        if (!empty($c['garages']))          $stats[] = (int) $c['garages']   . ' garage';
        if (!empty($c['erf_size_m2']))      $stats[] = (int) $c['erf_size_m2']      . ' m² erf';
        if (!empty($c['property_size_m2'])) $stats[] = (int) $c['property_size_m2'] . ' m² floor';
    ?>
        <td style="width:50%;vertical-align:top;padding:0;">
            <div class="avoid-break" style="border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;background:#fff;">
                <!-- Image / placeholder + price banner + match% top-right -->
                <div style="position:relative;height:96px;background:#f1f5f9;overflow:hidden;">
                    <?php if ($thumbAbs): ?>
                        <img src="<?= $esc($thumbAbs) ?>" style="width:100%;height:100%;object-fit:cover;display:block;" />
                    <?php else: ?>
                        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#cbd5e1;font-size:11px;">No photo</div>
                    <?php endif; ?>
                    <span style="position:absolute;top:6px;right:6px;padding:2px 8px;border-radius:10px;font-size:10px;font-weight:700;background:#ffffff;color:<?= $tierColor ?>;">
                        <?= (int) ($c['score'] ?? 0) ?>%
                    </span>
                    <?php if (!empty($c['price'])): ?>
                        <div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.55);color:#fff;padding:4px 8px;">
                            <span style="font-weight:700;font-size:12px;"><?= $zar($c['price']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
                <!-- Body -->
                <div style="padding:6px 8px;">
                    <div style="font-size:11px;font-weight:600;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;"><?= $esc($title) ?></div>
                    <?php if (!empty($c['suburb']) && $c['suburb'] !== $title): ?>
                        <div style="font-size:10px;color:#94a3b8;"><?= $esc($c['suburb']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($stats)): ?>
                        <div style="font-size:10px;color:#64748b;margin-top:2px;"><?= $esc(implode(' · ', $stats)) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($c['agent_name']) || !empty($c['agency_name'])): ?>
                        <div style="font-size:9px;color:#94a3b8;margin-top:2px;"><?= $esc($c['agent_name'] ?? $c['agency_name']) ?></div>
                    <?php endif; ?>
                    <!-- Footer: ref + tier badge + HFC/DOM/views -->
                    <div style="border-top:1px solid #f1f5f9;margin-top:4px;padding-top:4px;font-size:9px;color:#94a3b8;">
                        <?php if (!empty($c['portal_ref'])): ?><span style="font-family:monospace;"><?= $esc($c['portal_ref']) ?></span> · <?php endif; ?>
                        <span style="background:<?= $tierBg ?>;color:<?= $tierColor ?>;padding:1px 5px;border-radius:6px;font-weight:600;"><?= $esc($tierLabel) ?></span>
                        <?php if (!empty($c['is_hfc_owned'])): ?>
                            · <span style="color:#10b981;font-weight:600;">HFC</span>
                            <?php if (isset($c['days_on_market']) && $c['days_on_market'] !== null): ?> · <?= (int) $c['days_on_market'] ?>d<?php endif; ?>
                            <?php if (isset($c['views']) && $c['views'] !== null): ?> · <?= number_format((int) $c['views']) ?> views<?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </td>
    <?php endfor; ?>
    </tr>
<?php endfor; ?>
</table>
<?php endif; ?>

<?php endif // /active_competition ?>
<?php $beat3Html = ob_get_clean(); ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // APPENDIX — Inflow / Absorption. B2-followup-2: captured into
      // $appendixInflowHtml so it renders AFTER Beat 5 Holding Cost in
      // the call-site emit (so Beat 5's bullet → p.7 ref stays correct).
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php ob_start(); ?>
<?php if ($sectionEnabled('inflow_absorption')): ?>
<?php if (!empty($inflow['has_data'])): ?>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>New Listing Inflow &amp; Absorption</h2>
</div>

<div class="section-intro avoid-break">
    This shows how fast new competing homes are coming to market versus how fast they're
    selling. When more arrive than sell, buyers gain choice and time — which works against
    an overpriced listing. <strong>Pricing right early</strong>, before more stock arrives,
    protects your position.
</div>

<?php // Period cards: 7d / 30d / 90d ?>
<div class="metric-grid">
    <div class="metric-card">
        <div class="label">Last 7 Days</div>
        <div class="value"><?= (int) $inflow['count_7d'] ?></div>
        <div class="sub">new listings</div>
    </div>
    <div class="metric-card">
        <div class="label">Last 30 Days</div>
        <div class="value"><?= (int) $inflow['count_30d'] ?></div>
        <div class="sub">new listings</div>
    </div>
    <div class="metric-card highlight">
        <div class="label">Last 90 Days</div>
        <div class="value"><?= (int) $inflow['count_90d'] ?></div>
        <div class="sub">new listings</div>
    </div>
</div>

<?php // Inflow rate callout ?>
<?php if ($inflow['new_listing_rate'] > 0): ?>
<div class="callout callout-info" style="margin-top:14px;">
    <strong>Inflow Rate:</strong> <?= $inflow['new_listing_rate'] ?> new similar listings per month
    (<?= number_format($inflow['new_listing_rate'] * 12, 0) ?>/year).
    Based on <?= (int) $inflow['count_90d'] ?> matching listings over the past 90 days.
    <?php if (!empty($inflow['target_suburbs'])): ?>
    <br><span style="font-size:10px;color:var(--text-light);">
        Matching: <?= $esc(implode(', ', $inflow['target_suburbs'])) ?>
        <?php if (!empty($inflow['target_types'])): ?> &middot; <?= $esc(implode('/', $inflow['target_types'])) ?><?php endif ?>
        <?php if (!empty($inflow['price_range'])): ?> &middot; R <?= number_format($inflow['price_range']['low']) ?> – R <?= number_format($inflow['price_range']['high']) ?><?php endif ?>
    </span>
    <?php endif ?>
</div>
<?php endif ?>

<?php // Adjusted absorption & selling probability ?>
<?php if ($inflow['net_absorption'] !== null): ?>
<?php
    $inflowTrendColor = match($inflow['stock_trend'] ?? '') {
        'growing'   => 'danger',
        'depleting' => 'success',
        default     => 'warning',
    };
    $inflowTrendLabel = match($inflow['stock_trend'] ?? '') {
        'growing'   => 'Stock Growing',
        'depleting' => 'Stock Depleting',
        default     => 'Stock Stable',
    };
    $inflowCalloutClass = match($inflow['stock_trend'] ?? '') {
        'growing'   => 'callout-danger',
        'depleting' => 'callout-success',
        default     => 'callout-warning',
    };
?>
<div class="avoid-break" style="margin-top:18px;">
<div class="two-col">
    <?php // Left: Standard vs Adjusted absorption ?>
    <div>
        <h3 style="margin-bottom:10px;">Adjusted Absorption</h3>
        <table>
            <thead><tr><th>Metric</th><th class="num">Value</th></tr></thead>
            <tbody>
                <tr>
                    <td>Standard supply</td>
                    <td class="num">
                        <?= (int) $inflow['active_listings'] ?> listings &divide; <?= $inflow['monthly_sales'] ?>/mo
                        <?php if ($monthsOfSupply !== null): ?>= <?= number_format($monthsOfSupply, 1) ?> months<?php endif ?>
                    </td>
                </tr>
                <tr>
                    <td>Net absorption</td>
                    <td class="num" style="color:var(--<?= $inflowTrendColor ?>);">
                        <?= $inflow['monthly_sales'] ?> sold &minus; <?= $inflow['new_listing_rate'] ?> new
                        = <?= $inflow['net_absorption'] > 0 ? '+' : '' ?><?= $inflow['net_absorption'] ?>/mo
                    </td>
                </tr>
                <tr>
                    <td>Stock trend</td>
                    <td class="num"><span class="cmp-badge cmp-<?= $inflowTrendColor ?>"><?= $inflowTrendLabel ?></span></td>
                </tr>
                <?php if ($inflow['adjusted_months_supply'] !== null): ?>
                <tr style="font-weight:700;">
                    <td>Adjusted supply</td>
                    <td class="num" style="color:var(--<?= $inflowTrendColor ?>);"><?= $inflow['adjusted_months_supply'] ?> months</td>
                </tr>
                <?php endif ?>
                <?php if ($inflow['pool_after_3_months'] !== null): ?>
                <tr>
                    <td>Pool after 3 months</td>
                    <td class="num">~<?= $inflow['pool_after_3_months'] ?> properties</td>
                </tr>
                <?php endif ?>
            </tbody>
        </table>
    </div>

    <?php // Right: Selling probability ?>
    <div>
        <h3 style="margin-bottom:10px;">Selling Probability</h3>
        <?php if ($inflow['monthly_probability'] !== null): ?>
        <div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px;">
                <span style="color:var(--text-muted);">Monthly chance</span>
                <span style="font-weight:700;"><?= $inflow['monthly_probability'] ?>%</span>
            </div>
            <div style="background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;">
                <div style="width:<?= min($inflow['monthly_probability'], 100) ?>%;height:100%;background:var(--brand-light);border-radius:999px;"></div>
            </div>
        </div>
        <?php endif ?>
        <?php if ($inflow['prob_3_months'] !== null): ?>
        <div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px;">
                <span style="color:var(--text-muted);">3-month chance</span>
                <span style="font-weight:700;"><?= $inflow['prob_3_months'] ?>%</span>
            </div>
            <div style="background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;">
                <div style="width:<?= min($inflow['prob_3_months'], 100) ?>%;height:100%;background:var(--brand);border-radius:999px;"></div>
            </div>
        </div>
        <?php endif ?>
        <?php if ($inflow['adjusted_prob_3_months'] !== null && $inflow['adjusted_prob_3_months'] != $inflow['prob_3_months']): ?>
        <div style="margin-bottom:10px;">
            <div style="display:flex;justify-content:space-between;font-size:10px;margin-bottom:3px;">
                <span style="color:var(--text-muted);">Adjusted 3-month <span style="font-size:8px;color:var(--text-light);">(with inflow)</span></span>
                <span style="font-weight:700;color:var(--<?= $inflow['adjusted_prob_3_months'] < ($inflow['prob_3_months'] ?? 0) ? 'danger' : 'success' ?>);"><?= $inflow['adjusted_prob_3_months'] ?>%</span>
            </div>
            <div style="background:#e2e8f0;border-radius:999px;height:10px;overflow:hidden;">
                <div style="width:<?= min($inflow['adjusted_prob_3_months'], 100) ?>%;height:100%;background:<?= $inflow['adjusted_prob_3_months'] < ($inflow['prob_3_months'] ?? 0) ? 'var(--danger)' : 'var(--success)' ?>;border-radius:999px;"></div>
            </div>
        </div>
        <?php endif ?>
    </div>
</div>
</div>
<?php endif ?>

<?php // Narrative insight ?>
<?php if (!empty($inflow['narrative'])): ?>
<div class="callout <?= $inflowCalloutClass ?? 'callout-info' ?>" style="margin-top:14px;">
    <strong>Key Insight:</strong> <?= $esc($inflow['narrative']) ?>
</div>
<?php endif ?>

<p style="font-size:8.5px;color:var(--text-light);margin-top:10px;">
    Source: P24 alert email imports (<?= number_format($inflow['total_p24_listings'] ?? 0) ?> total listings in database)
</p>
<?php endif // end inflow has_data — no-data placeholder removed; the
      // section is silently absent when no P24 data, so the downstream
      // PropCon section becomes "6" naturally without a duplicate header.
?>
<?php endif // /inflow_absorption ?>
<?php $appendixInflowHtml = ob_get_clean(); ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // APPENDIX — PropCon Listing Performance. B2-followup-2: captured
      // into $appendixPropconHtml. Conditional render preserved.
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php ob_start(); ?>
<?php if (!empty($propcon['has_data'])): ?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Listing Performance &mdash; Similar Properties</h2>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-bottom:14px;">
    How similar properties currently on the market are performing &mdash; portal views, buyer matches, and time on market.
    <?php if (!empty($propcon['criteria'])): ?>
    <br><strong>Matching:</strong> <?= $esc($propcon['criteria']) ?>
    &middot; <?= (int) $propcon['similar_count'] ?> similar <?= $propcon['similar_count'] === 1 ? 'listing' : 'listings' ?>
    <?php endif ?>
</p>

<!-- Benchmark stat cards -->
<div class="metric-grid" style="grid-template-columns: repeat(4, 1fr);">
    <div class="metric-card">
        <div class="label">Avg Views</div>
        <div class="value"><?= $propcon['avg_views'] !== null ? number_format($propcon['avg_views']) : '—' ?></div>
        <?php if ($propcon['min_views'] !== null && $propcon['max_views'] !== null): ?>
        <div class="sub"><?= number_format($propcon['min_views']) ?> – <?= number_format($propcon['max_views']) ?></div>
        <?php endif ?>
    </div>
    <div class="metric-card">
        <div class="label">Avg Buyer Matches</div>
        <div class="value"><?= $propcon['avg_matches'] !== null ? number_format($propcon['avg_matches']) : '—' ?></div>
        <?php if ($propcon['min_matches'] !== null && $propcon['max_matches'] !== null): ?>
        <div class="sub"><?= $propcon['min_matches'] ?> – <?= $propcon['max_matches'] ?></div>
        <?php endif ?>
    </div>
    <div class="metric-card">
        <div class="label">Avg Days on Market</div>
        <div class="value"><?= $propcon['avg_days_on_market'] !== null ? $propcon['avg_days_on_market'] : '—' ?></div>
        <?php if ($propcon['min_days'] !== null && $propcon['max_days'] !== null): ?>
        <div class="sub"><?= $propcon['min_days'] ?> – <?= $propcon['max_days'] ?> days</div>
        <?php endif ?>
    </div>
    <div class="metric-card highlight">
        <div class="label">Avg Views/Day</div>
        <div class="value"><?= $propcon['avg_views_per_day'] !== null ? $propcon['avg_views_per_day'] : '—' ?></div>
    </div>
</div>

<!-- Similar listings table -->
<?php if (!empty($propcon['listings'])): ?>
<div class="avoid-break" style="margin-top:16px;">
    <h3 style="margin-bottom:8px;">Similar Active Listings</h3>
    <table>
        <thead>
            <tr>
                <th style="text-align:left;">Address</th>
                <th style="text-align:left;">Type</th>
                <th class="num">Price</th>
                <th class="num">Beds</th>
                <th class="num">Views</th>
                <th class="num">Matches</th>
                <th class="num">Days</th>
                <th class="num">Views/Day</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($propcon['listings'] as $pcRow): ?>
            <tr<?= !empty($pcRow['is_subject']) ? ' style="background:var(--bg-alt);font-weight:600;"' : '' ?>>
                <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?= $esc($pcRow['address'] ?? '—') ?>
                    <?php if (!empty($pcRow['is_subject'])): ?>
                    <span style="font-size:8px;background:var(--brand-light);color:var(--brand);padding:1px 5px;border-radius:3px;margin-left:4px;">YOUR LISTING</span>
                    <?php endif ?>
                </td>
                <td><?= $esc($pcRow['type'] ?? '—') ?></td>
                <td class="num"><?= $pcRow['price'] ? $zar($pcRow['price']) : '—' ?></td>
                <td class="num"><?= $pcRow['beds'] ?? '—' ?></td>
                <td class="num"><?= $pcRow['views'] !== null ? number_format($pcRow['views']) : '—' ?></td>
                <td class="num"><?= $pcRow['matches'] ?? '—' ?></td>
                <td class="num"><?= $pcRow['days_on_market'] ?? '—' ?></td>
                <td class="num"><?= $pcRow['views_per_day'] ?? '—' ?></td>
            </tr>
            <?php endforeach ?>
        </tbody>
    </table>
</div>
<?php endif ?>

<!-- Subject property highlight -->
<?php if (!empty($propcon['subject_found']) && !empty($propcon['subject_stats'])): ?>
<?php $ss = $propcon['subject_stats']; ?>
<div class="callout callout-info" style="margin-top:14px;">
    <strong>Your Listing Performance:</strong>
    <?= $ss['views'] !== null ? number_format($ss['views']) . ' views' : '' ?>
    <?= $ss['matches'] !== null ? ' · ' . $ss['matches'] . ' matches' : '' ?>
    <?= $ss['days_on_market'] !== null ? ' · ' . $ss['days_on_market'] . ' days on market' : '' ?>
    <?= $ss['views_per_day'] !== null ? ' · ' . $ss['views_per_day'] . ' views/day' : '' ?>
    <?php if ($ss['rank_views']): ?>
    <br>Ranked <?= $esc($ss['rank_views']) ?> by views<?= $ss['rank_matches'] ? ', ' . $esc($ss['rank_matches']) . ' by matches' : '' ?> among similar listings.
    <?php endif ?>
</div>
<?php endif ?>

<!-- Market signal narrative -->
<?php if (!empty($propcon['market_signal_text'])): ?>
<?php
    $pcSignalClass = match($propcon['market_signal'] ?? '') {
        'price_issue'      => 'callout-danger',
        'visibility_issue' => 'callout-warning',
        'healthy'          => 'callout-success',
        'new_listing'      => 'callout-info',
        default            => 'callout-info',
    };
?>
<div class="callout <?= $pcSignalClass ?>" style="margin-top:14px;">
    <strong>Market Signal:</strong> <?= $esc($propcon['market_signal_text']) ?>
</div>
<?php endif ?>

<p style="font-size:8.5px;color:var(--text-light);margin-top:10px;">
    Source: PropCon agency data &middot; <?= number_format($propcon['total_propcon_listings'] ?? 0) ?> listings in database &middot; Updated weekly
</p>
<?php endif // end propcon section ?>
<?php $appendixPropconHtml = ob_get_clean(); ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // BEAT 5 — What Waiting Costs (Holding Cost Analysis). B2-followup-2:
      // captured into $beat5Html so it renders BEFORE the appendix
      // (Inflow/PropCon/Pricing Scenarios) in the call-site emit.
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php ob_start(); ?>
<?php if ($sectionEnabled('holding_cost')): ?>
<div class="beat-eyebrow">Section <?= $summary['section_index']['waiting'] ?? 7 ?> · Beat 5 — What Waiting Costs</div>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Holding Cost Analysis</h2>
</div>

<div class="section-intro avoid-break">
    Every month your home is on the market carries a real, ongoing cost — bond, rates,
    levies, and the opportunity cost of capital tied up. At
    <strong><?= $zarFloat($monthlyTotal) ?>/month</strong>, that's
    <strong><?= $zarFloat($projected12m) ?></strong> over a year. This is why a realistic
    price that sells in weeks often nets you <strong>more</strong> than a higher price
    that sits for months.
</div>

<?php if ($monthlyTotal > 0): ?>
<?php /* AT-22 R2 item 4 — keep the holding-cost grid whole. The two tables
         are short; protecting the grid container (not just the children) stops
         Chromium splitting the grid track across pages 10–11. */ ?>
<div class="two-col" style="page-break-inside:avoid;">
    <div class="avoid-break">
        <h3 style="margin-bottom:8px;">Monthly Breakdown</h3>
        <table>
            <thead><tr><th>Expense</th><th class="num">Monthly (ZAR)</th></tr></thead>
            <tbody>
                <?php foreach ($breakdown as $label => $amount): ?>
                <?php if ($amount > 0): ?>
                <tr>
                    <td><?= $esc($label) ?></td>
                    <td class="num"><?= $zarFloat($amount) ?></td>
                </tr>
                <?php endif ?>
                <?php endforeach ?>
            </tbody>
            <tfoot>
                <tr class="table-summary">
                    <td><strong>Monthly Total</strong></td>
                    <td class="num"><?= $zarFloat($monthlyTotal) ?></td>
                </tr>
            </tfoot>
        </table>
    </div>

    <div class="avoid-break">
        <h3 style="margin-bottom:8px;">Cumulative Cost</h3>
        <table>
            <thead><tr><th>Period</th><th class="num">Total Cost (ZAR)</th></tr></thead>
            <tbody>
                <?php for ($m = 1; $m <= 12; $m++): ?>
                <tr<?= in_array($m, [6, 12]) ? ' style="font-weight:700"' : '' ?>>
                    <td>Month <?= $m ?></td>
                    <td class="num"><?= $zarFloat($monthlyTotal * $m) ?></td>
                </tr>
                <?php endfor ?>
            </tbody>
        </table>
    </div>
</div>

<div class="metric-grid" style="grid-template-columns: 1fr 1fr 1fr; margin-top:16px;">
    <div class="metric-card warning">
        <div class="label">At 3 Months</div>
        <div class="value"><?= $zarFloat($holding['projected_3m'] ?? $monthlyTotal * 3) ?></div>
    </div>
    <div class="metric-card danger">
        <div class="label">At 6 Months</div>
        <div class="value"><?= $zarFloat($projected6m) ?></div>
    </div>
    <div class="metric-card danger">
        <div class="label">At 12 Months</div>
        <div class="value"><?= $zarFloat($projected12m) ?></div>
    </div>
</div>

<div class="callout callout-danger" style="margin-top:14px;">
    <strong>The cost of waiting:</strong>
    If this property remains on the market for 12 months, the total holding cost
    will be <strong><?= $zarFloat($projected12m) ?></strong>.
    <?php if ($projected12m && $askingPrice && $askingPrice > 0): ?>
    That's <strong><?= number_format($projected12m / $askingPrice * 100, 1) ?>%</strong> of the asking price.
    <?php endif ?>
</div>

<?php else: ?>
<div class="callout callout-info">
    No holding cost data has been entered. Add monthly expenses on the presentation page to populate this section.
</div>
<?php endif ?>

<?php endif // /holding_cost ?>
<?php $beat5Html = ob_get_clean(); ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // BEAT 4 (part 2) — Pricing Strategy & Recommendation. B2-followup-2:
      // captured into $beat4StrategyHtml. The call-site emits Beat 4 as
      // $beat4CmaHtml + $beat4StrategyHtml — grouping CMA + Strategy
      // contiguously on the recommendation page per spec.
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php ob_start(); ?>
<?php if ($sectionEnabled('pricing_strategy')): ?>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Pricing Strategy &amp; Recommendation</h2>
</div>

<div class="section-intro avoid-break">
    This brings everything together into a recommendation. The range reflects what the
    evidence supports — recent sales, the valuation band, current competition, and the
    cost of waiting. The <strong>final decision is yours</strong>; our role is to make
    sure it's an informed one.
</div>

<?php if ($cmaLower && $cmaUpper): ?>
<div class="avoid-break" style="margin-bottom:18px;">
<h3 style="margin-bottom:10px;color:var(--brand);">Recommended Price Band</h3>
<div class="metric-grid" style="grid-template-columns: 1fr 1fr;">
    <div class="metric-card highlight">
        <div class="label">Recommended Range</div>
        <?php /* AT-22 §5 — range is the comparable-sales band (lower→upper of
                 the cleaned comp pool). Both bounds are evidence-backed from
                 actual sales; asking is a reference only.
                 PRES-CMA-REALFIX — band is the RAW comp distribution (no
                 condition factor). PRES-CMA-SELLER-VOICE — upper bound now
                 reflects genuine comps (lone high outliers removed by the IQR
                 upper fence) and the seller-facing label drops the P25/P75
                 jargon entirely. */ ?>
        <div class="value" style="font-size:17px;"><?= $zar($cmaLower) ?> — <?= $zar($cmaUpper) ?></div>
        <div class="sub">The range homes like yours have actually sold for</div>
    </div>
    <?php if ($askingPrice): ?>
    <div class="metric-card <?= $askVsCmaPct !== null && $askVsCmaPct > 10 ? 'danger' : ($askVsCmaPct !== null && $askVsCmaPct > 5 ? 'warning' : 'success') ?>">
        <div class="label">Current Asking Price</div>
        <div class="value"><?= $zar($askingPrice) ?></div>
        <div class="sub"><?php if ($askVsCmaPct !== null): ?><?= $pct($askVsCmaPct) ?> vs evaluated value<?php endif ?></div>
    </div>
    <?php endif ?>
</div>
</div>
<?php endif ?>

<div class="avoid-break" style="margin-bottom:18px;">
<h3 style="margin-bottom:10px;">Why This Range?</h3>
<table>
    <thead><tr><th>Evidence Source</th><th class="num">Indicated Value</th><th>Status</th></tr></thead>
    <tbody>
        <?php /* PRES-CMA-REALFIX — the comp-distribution rows show the
                 P25 / median / P75 of the comparable-sales set, sourced from
                 cma_computed.pool_stats. The recommended band
                 (cma_lower/middle/upper) IS this raw distribution — no
                 condition factor is applied — so the evidence rows and the
                 recommended range are the same figures by construction. Fall
                 back to the band value only if pool_stats is somehow absent —
                 but then the band is itself null and this whole card is
                 suppressed. */ ?>
        <?php $rawP25 = $compP25 ?? $cmaLower; $rawMed = $compMedian ?? $cmaMiddle; $rawP75 = $compP75 ?? $cmaUpper; ?>
        <?php if ($rawP25): ?>
        <tr>
            <td>Lower end of recent comparable sales</td>
            <td class="num"><?= $zar($rawP25) ?></td>
            <td><span class="cmp-badge cmp-success">Comp evidence</span></td>
        </tr>
        <?php endif ?>
        <?php if ($rawMed): ?>
        <tr>
            <td>What homes like yours typically sold for</td>
            <td class="num"><?= $zar($rawMed) ?></td>
            <td><span class="cmp-badge cmp-success">Comp evidence</span></td>
        </tr>
        <?php endif ?>
        <?php if ($rawP75): ?>
        <tr>
            <td>Upper end of recent comparable sales</td>
            <td class="num"><?= $zar($rawP75) ?></td>
            <td><span class="cmp-badge cmp-success">Comp evidence</span></td>
        </tr>
        <?php endif ?>
        <?php if ($vicAvgPrice): ?>
        <tr>
            <td>Vicinity Sales Average</td>
            <td class="num"><?= $zar($vicAvgPrice) ?></td>
            <td><span class="cmp-badge cmp-success">Supporting</span></td>
        </tr>
        <?php endif ?>
        <?php if ($suburbMedian): ?>
        <tr>
            <td>Typical suburb price (<?= $esc((string) $suburbYear) ?>)</td>
            <td class="num"><?= $zar($suburbMedian) ?></td>
            <td><span class="cmp-badge cmp-success">Context</span></td>
        </tr>
        <?php endif ?>
        <?php if ($subject['municipal_value']): ?>
        <tr>
            <td>Municipal Valuation</td>
            <td class="num"><?= $zar($subject['municipal_value']) ?></td>
            <td><span class="cmp-badge cmp-warning">Reference</span></td>
        </tr>
        <?php endif ?>
        <?php if ($subject['indexed_value']): ?>
        <tr>
            <td>Indexed Value (CAGR <?= $subject['cagr'] ? number_format($subject['cagr'], 2) . '%' : '—' ?>)</td>
            <td class="num"><?= $zar($subject['indexed_value']) ?></td>
            <td><span class="cmp-badge cmp-warning">Reference</span></td>
        </tr>
        <?php endif ?>
    </tbody>
</table>
</div>

<?php // Key Insights from comparisons ?>
<?php if (!empty($insights['comparisons'])): ?>
<div class="avoid-break" style="margin-bottom:18px;">
<h3 style="margin-bottom:8px;">How Your Asking Price Compares</h3>
<table>
    <thead><tr><th>Comparison</th><th class="num">Benchmark</th><th class="num">Asking</th><th class="num">Difference</th><th>Status</th></tr></thead>
    <tbody>
        <?php foreach ($insights['comparisons'] as $cmp): ?>
        <tr>
            <td><?= $esc($cmp['label']) ?></td>
            <td class="num"><?= $zar($cmp['benchmark']) ?></td>
            <td class="num"><?= $zar($cmp['asking']) ?></td>
            <td class="num"><?= $pct($cmp['pct_difference']) ?></td>
            <td><span class="cmp-badge cmp-<?= $cmp['status'] ?>"><?= ucfirst($cmp['status']) ?></span></td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>

<?php // Source Reports ?>
<?php $allLinks = $presentation->links; ?>
<?php if ($allLinks->isNotEmpty()): ?>
<div class="avoid-break" style="margin-top:18px;">
<h3 style="margin-bottom:6px;">Source Reports &amp; References</h3>
<table>
    <thead><tr><th>Type</th><th>URL</th></tr></thead>
    <tbody>
        <?php foreach ($allLinks as $link): ?>
        <tr>
            <td><span class="cmp-badge" style="background:#eef2ff;color:var(--brand-accent)"><?= $esc(ucfirst(str_replace('_', ' ', $link->type))) ?></span></td>
            <td><a href="<?= $esc($link->url) ?>" target="_blank"><?= $esc($link->url) ?></a></td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>
</div>
<?php endif ?>
<?php endif // /pricing_strategy ?>
<?php $beat4StrategyHtml = ob_get_clean(); ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // APPENDIX — Pricing Scenarios (conditional — only if simulator
      // saved with include_in_pdf). B2-followup-2: captured into
      // $appendixScenariosHtml so it renders AFTER Beat 5.
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php ob_start(); ?>
<?php
    $simConfig = $presentation->simulator_config_json;
    if ($simConfig && !empty($simConfig['include_in_pdf']) && !empty($simConfig['scenarios'])):
        $simScenarios = $simConfig['scenarios'];
        $simCfg       = $simConfig['config'] ?? [];
        $simNarrative = $simConfig['narrative'] ?? '';
?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">__SECNO__</span>
    <h2>Pricing Scenarios</h2>
</div>

<div class="avoid-break" style="margin-bottom:14px;">
<p style="font-size:11px;color:var(--text-muted);margin-bottom:12px;">
    Commission: <?= number_format($simCfg['commission_pct'] ?? 7.5, 1) ?>% (excl. VAT)
    &middot; Transfer Cost: <?= number_format($simCfg['transfer_cost_pct'] ?? 4, 1) ?>%
    &middot; Monthly Holding Cost: <?= $zar((int)($simCfg['monthly_holding_cost'] ?? 0)) ?>
</p>

<table>
    <thead>
        <tr>
            <th>Scenario</th>
            <th class="num">Price</th>
            <th class="num">Competing</th>
            <th class="num">Est. Months</th>
            <th class="num">Holding Cost</th>
            <th class="num">Commission</th>
            <th class="num">Net Proceeds</th>
            <th class="num">vs Asking</th>
            <th>Probability</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($simScenarios as $sc): ?>
        <tr>
            <td><?= $esc($sc['label'] ?? '') ?></td>
            <td class="num"><?= $zar($sc['price'] ?? 0) ?></td>
            <td class="num"><?= $sc['competing_count'] ?? '—' ?></td>
            <td class="num"><?= $sc['est_months'] ?? '—' ?></td>
            <td class="num"><?= $zar($sc['holding_cost_total'] ?? 0) ?></td>
            <td class="num"><?= $zar($sc['commission'] ?? 0) ?></td>
            <td class="num" style="font-weight:700;"><?= $zar($sc['net_proceeds'] ?? 0) ?></td>
            <td class="num">
                <?php if (isset($sc['vs_asking_net'])): ?>
                    <?= ($sc['vs_asking_net'] >= 0 ? '+' : '') . $zar($sc['vs_asking_net']) ?>
                <?php else: ?>—<?php endif ?>
            </td>
            <td>
                <?php
                    $probLabel = $sc['probability'] ?? '';
                    $probStyle = match($probLabel) {
                        'Very Likely' => 'background:#d1fae5;color:#059669',
                        'Likely'      => 'background:#dcfce7;color:#16a34a',
                        'Possible'    => 'background:#fef3c7;color:#d97706',
                        'Unlikely'    => 'background:#fed7aa;color:#ea580c',
                        default       => 'background:#fecaca;color:#dc2626',
                    };
                ?>
                <span class="cmp-badge" style="<?= $probStyle ?>"><?= $esc($probLabel) ?></span>
            </td>
        </tr>
        <?php endforeach ?>
    </tbody>
</table>
</div>

<?php // Bar chart (CSS only) ?>
<div class="avoid-break" style="margin-bottom:18px;">
<h3 style="margin-bottom:10px;">Net Proceeds Comparison</h3>
<?php
    $maxNetPdf = max(1, max(array_map(fn($s) => max((int)($s['net_proceeds'] ?? 0), 0), $simScenarios)));
    $barColorMap = [
        'Very Likely' => '#059669', 'Likely' => '#16a34a',
        'Possible'    => '#d97706', 'Unlikely' => '#ea580c', 'Very Unlikely' => '#dc2626',
    ];
?>
<?php foreach ($simScenarios as $sc): ?>
<?php
    $netVal = max((int)($sc['net_proceeds'] ?? 0), 0);
    $barW   = max(2, round($netVal / $maxNetPdf * 100));
    $barC   = $barColorMap[$sc['probability'] ?? ''] ?? '#dc2626';
?>
<div style="display:flex;align-items:center;gap:6px;margin-bottom:4px;">
    <div style="width:100px;text-align:right;font-size:10px;color:var(--text-muted);flex-shrink:0;"><?= $esc($sc['label'] ?? '') ?></div>
    <div style="flex:1;background:#f3f4f6;border-radius:999px;height:20px;overflow:hidden;">
        <div style="width:<?= $barW ?>%;height:100%;background:<?= $barC ?>;border-radius:999px;display:flex;align-items:center;padding:0 6px;">
            <span style="font-size:9px;color:#fff;font-weight:600;white-space:nowrap;"><?= $zar($sc['net_proceeds'] ?? 0) ?></span>
        </div>
    </div>
</div>
<?php endforeach ?>
</div>

<?php if ($simNarrative): ?>
<div class="avoid-break" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:18px;">
    <h3 style="font-size:11px;text-transform:uppercase;letter-spacing:0.05em;color:var(--brand);margin-bottom:6px;">Key Insight</h3>
    <p style="font-size:12px;color:var(--text);line-height:1.6;"><?= $esc($simNarrative) ?></p>
</div>
<?php endif ?>

<?php endif // end simulator page ?>
<?php $appendixScenariosHtml = ob_get_clean(); ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // B2-followup-2 — BEAT REPLAY in spec order. The Cover, Exec
      // Summary, Beat 1 (Subject card), and Beat 2 (Market Overview +
      // Recent Sales + Spatial) already rendered above in physical
      // order. Now emit the captured buffers in the locked beat order:
      //   Beat 3 — Active Competition
      //   Beat 4 — CMA tiles + Pricing Strategy (recommendation)
      //   Beat 5 — Holding Cost (the close)
      //   Appendix — Inflow + PropCon + Pricing Scenarios
      // The bullets on the Exec Summary page → p.N refs were computed
      // by buildSummaryPayload assuming this order; emit-time order
      // matches by construction.
      // ══════════════════════════════════════════════════════════════════════ ?>
<?= $beat3Html ?>
<?= $beat4CmaHtml ?>
<?= $beat4StrategyHtml ?>
<?= $beat5Html ?>
<?= $appendixInflowHtml ?>
<?= $appendixPropconHtml ?>
<?= $appendixScenariosHtml ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // SELLER PRICING CONFIRMATION (conditional — only if seller live capture exists)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php
    $sellerCapture = $presentation->seller_live_capture_json;
    if ($sellerCapture && !empty($sellerCapture['price'])):
        $capPrice = (int) $sellerCapture['price'];
        $capProb  = $sellerCapture['probability'] ?? '';
        $capNet   = (int) ($sellerCapture['net_proceeds'] ?? 0);
        $capProbStyle = match(true) {
            str_contains(strtolower($capProb), 'very likely') => 'background:#d1fae5;color:#059669',
            str_contains(strtolower($capProb), 'likely')      => 'background:#dcfce7;color:#16a34a',
            str_contains(strtolower($capProb), 'possible')    => 'background:#fef3c7;color:#d97706',
            str_contains(strtolower($capProb), 'unlikely')    => 'background:#fed7aa;color:#ea580c',
            default                                           => 'background:#fecaca;color:#dc2626',
        };
?>
<div class="section-header">
    <span class="section-number">&bull;</span>
    <h2>Seller Pricing Confirmation</h2>
</div>

<div class="avoid-break" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;padding:20px;margin-bottom:18px;">
    <p style="font-size:11px;color:var(--text-muted);margin-bottom:16px;text-transform:uppercase;letter-spacing:0.05em;">
        Price point confirmed during listing appointment
    </p>
    <table>
        <tbody>
            <tr>
                <td style="font-weight:600;width:180px;">Confirmed Price</td>
                <td style="font-size:16px;font-weight:700;"><?= $zar($capPrice) ?></td>
            </tr>
            <tr>
                <td style="font-weight:600;">Probability of Sale</td>
                <td><span class="cmp-badge" style="<?= $capProbStyle ?>"><?= $esc($capProb) ?></span></td>
            </tr>
            <tr>
                <td style="font-weight:600;">Estimated Net Proceeds</td>
                <td style="font-weight:700;"><?= $zar($capNet) ?></td>
            </tr>
        </tbody>
    </table>
</div>
<?php endif // end seller live capture ?>

<?php // ══════════════════════════════════════════════════════════════════════
      // MARKET NEWS & ARTICLES (conditional — only if articles attached)
      // ══════════════════════════════════════════════════════════════════════ ?>
<?php
    $pdfArticles = $presentation->articles;
    if ($pdfArticles->isNotEmpty()):
?>
<div class="page-break"></div>
<div class="section-header">
    <span class="section-number">&bull;</span>
    <h2>Market Context</h2>
</div>

<p style="font-size:11px;color:var(--text-muted);margin-bottom:16px;">
    Relevant market news and commentary supporting this analysis.
</p>

<?php foreach ($pdfArticles as $pdfArticle):
    $artTags   = $pdfArticle->tags_json ?? [];
    $artTitle  = $esc($artTags['title'] ?? '');
    $artSource = $esc($artTags['source'] ?? 'Unknown source');
    $artDate   = '';
    if (!empty($artTags['published_at'])) {
        try { $artDate = (new \DateTimeImmutable($artTags['published_at']))->format('d M Y'); } catch (\Throwable) {}
    }
    $artSummary = $pdfArticle->ai_summary_text ?? $pdfArticle->snapshot_text ?? '';
    $artUrl     = $pdfArticle->url ?? '';
?>
<div class="avoid-break" style="background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;padding:14px 16px;margin-bottom:12px;">
    <?php if ($artTitle): ?>
    <h3 style="font-size:12px;font-weight:700;color:var(--brand);margin-bottom:4px;line-height:1.4;"><?= $artTitle ?></h3>
    <?php endif ?>
    <p style="font-size:9px;color:var(--text-light);margin-bottom:8px;">
        <?= $artSource ?><?= $artDate ? ' &middot; ' . $artDate : '' ?>
    </p>
    <?php if ($artSummary): ?>
    <p style="font-size:11px;color:var(--text);line-height:1.6;margin-bottom:6px;">
        <?= $esc(mb_substr($artSummary, 0, 500)) ?>
    </p>
    <?php endif ?>
    <?php if ($artUrl): ?>
    <p style="font-size:8.5px;color:var(--text-light);word-break:break-all;">
        <a href="<?= $esc($artUrl) ?>" style="color:var(--brand-light);text-decoration:none;"><?= $esc($artUrl) ?></a>
    </p>
    <?php endif ?>
</div>
<?php endforeach ?>

<?php endif // end articles ?>

<?php // AT-22 item 4 — closing CTA + footer wrapped as one no-break tail block
      // with trimmed top margins so they never spill onto an empty final page. ?>
<div class="report-tail">
<div style="margin-top:18px;padding:20px;background:var(--bg-alt);border:1px solid var(--border);border-radius:8px;text-align:center;">
    <p style="font-size:13px;font-weight:700;color:var(--brand);margin-bottom:6px;">
        Ready to discuss your pricing strategy?
    </p>
    <p style="font-size:12px;color:var(--text-muted);">
        <strong><?= $esc($agentName) ?></strong> &middot; Home Finders Coastal<br>
        <?php if ($agentEmail): ?><?= $esc($agentEmail) ?><br><?php endif ?>
        Shelly Beach, KZN South Coast
    </p>
</div>

<div style="margin-top:16px;text-align:center;font-size:8.5px;color:var(--text-light);border-top:1px solid var(--border-light);padding-top:12px;">
    Prepared by <?= $esc($agentName) ?> &middot; Home Finders Coastal &middot; <?= $compiledAt ?>
    &middot; Version #<?= $version->id ?>
    <br>
    This report is based on publicly available data and independent CMA valuation.
    All values are in South African Rand (ZAR). Data sources include CMA Info and Property24.
</div>
</div>

</body>
</html>
<?php
        $html = (string) ob_get_clean();

        // AT-22 R2 item 3 — assign section numbers in READING order. Each
        // section header carries a __SECNO__ placeholder; the buffer-and-
        // replay architecture echoes sections in reading order, so a single
        // document-order sweep numbers them 1..N sequentially. This replaces
        // the old per-section hardcoded literals (frozen to SOURCE order),
        // which came out scrambled (1, 3, 2, 5, 4, …). Bullet markers use the
        // same CSS class but render "•", not __SECNO__, so they're untouched.
        $secNo = 0;
        $html = preg_replace_callback('/__SECNO__/', static function () use (&$secNo) {
            return (string) (++$secNo);
        }, $html);

        return $html;
    }
}
