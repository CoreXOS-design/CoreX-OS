<?php

declare(strict_types=1);

namespace App\Services\MarketReports\Parsers;

use App\Domain\Presentation\TextExtractionService;
use App\Models\MarketReports\MarketReport;
use App\Models\MarketReports\MarketReportLearnedLayout;
use App\Services\MarketReports\CanonicalReadMapper;
use App\Services\MarketReports\DTOs\MarketReportParseResult;
use App\Services\MarketReports\DTOs\ParserConfidence;
use App\Services\MarketReports\LayoutFingerprint;
use App\Services\MarketReports\LearnedLayoutReader;
use App\Services\MarketReports\ReportReadSettings;

/**
 * Reads a report whose layout CoreX has already learned (a saved fingerprint + field
 * mapping, created after a successful AI read). Deterministic, no AI, no cost.
 * Registered BEFORE the generic fallback. Spec: mic-complete-spec §8.9.
 *
 * Extends the CMA Info base class only to reuse its pdftotext extraction; it has
 * nothing to do with CMA Info's own layouts. Pure like every parser: usage counters
 * are written by ParseMarketReportJob from rawJson['learned_layout_id'], not here.
 */
final class LearnedLayoutParser extends AbstractCmaInfoParser
{
    public const PARSER_VERSION = '1.0.0';

    public function __construct(
        TextExtractionService $textExtractor,
        private readonly ReportReadSettings $settings,
        private readonly LearnedLayoutReader $reader,
        private readonly CanonicalReadMapper $mapper,
    ) {
        parent::__construct($textExtractor);
    }

    public function getReportTypeKey(): string
    {
        return 'learned_layout';
    }

    public function getVersion(): string
    {
        return self::PARSER_VERSION;
    }

    /** The text this parser reads (and the learner maps against). */
    public function textFor(string $filePath): string
    {
        return $this->extractText($filePath);
    }

    public function canParse(string $filePath): ParserConfidence
    {
        $text = $this->extractText($filePath);
        if ($text === '') {
            return ParserConfidence::none('extractor returned empty text');
        }
        $best = $this->bestLayout($text);
        if ($best === null) {
            return ParserConfidence::none('no learned layout matches');
        }

        return new ParserConfidence(
            0.5 + 0.4 * $best['share'],
            ["learned layout #{$best['layout']->id} ({$best['layout']->name})", 'fingerprint match ' . round($best['share'] * 100) . '%'],
        );
    }

    public function parse(string $filePath, MarketReport $report): MarketReportParseResult
    {
        $text = $this->extractText($filePath);
        $best = $text === '' ? null : $this->bestLayout($text);
        if ($best === null) {
            return new MarketReportParseResult(rawJson: ['note' => 'No learned layout matches this report.']);
        }

        /** @var MarketReportLearnedLayout $layout */
        $layout = $best['layout'];
        $raw = $this->reader->read((array) $layout->mapping, $text);
        $sanitised = $this->mapper->sanitise($raw);

        $result = $this->mapper->toParseResult($sanitised['clean'], $report, [
            'read_by'           => 'learned_layout',
            'learned_layout_id' => $layout->id,
            'layout_name'       => $layout->name,
            'fingerprint_match' => round($best['share'], 3),
            'dropped'           => $sanitised['dropped'],
            'parser_version'    => self::PARSER_VERSION,
        ]);

        // The layout's own publisher name travels with the result for the queue/filters.
        return new MarketReportParseResult(
            dataPoints:         $result->dataPoints,
            extractedAddresses: $result->extractedAddresses,
            rawJson:            array_merge($result->rawJson, ['publisher' => $layout->publisher ?? ($result->rawJson['publisher'] ?? null)]),
            subjectMeta:        $result->subjectMeta,
            compRows:           $result->compRows,
            schemeOwners:       $result->schemeOwners,
        );
    }

    /** @return array{layout: MarketReportLearnedLayout, share: float}|null */
    private function bestLayout(string $text): ?array
    {
        $min = $this->settings->layoutMinMatch();
        $best = null;
        foreach (MarketReportLearnedLayout::query()->usable()->get() as $layout) {
            $share = LayoutFingerprint::presentShare((array) $layout->anchors, $text);
            if ($share >= $min && ($best === null || $share > $best['share'])) {
                $best = ['layout' => $layout, 'share' => $share];
            }
        }

        return $best;
    }
}
