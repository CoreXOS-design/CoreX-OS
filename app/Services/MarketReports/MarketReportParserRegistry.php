<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Services\MarketReports\Contracts\MarketReportParser;
use App\Services\MarketReports\Parsers\CmaInfoMarketAnalysisParser;
use App\Services\MarketReports\Parsers\CmaInfoMedianSalesAnalysisParser;
use App\Services\MarketReports\Parsers\CmaInfoPropertyValuationParser;
use App\Services\MarketReports\Parsers\CmaInfoSchemeOwnersListParser;
use App\Services\MarketReports\Parsers\CmaInfoSectionalTitleSalesParser;
use App\Services\MarketReports\Parsers\CmaInfoVicinitySaleParser;
use App\Services\MarketReports\Parsers\GenericFallbackParser;
use App\Services\MarketReports\Parsers\LearnedLayoutParser;

/**
 * Registry of every parser the upload pipeline considers. Used by:
 *   - MarketReportController::store() — auto-detect best parser
 *   - ParseMarketReportJob — resolve the parser by report_type_id
 *   - Settings/Report-Types dashboard — list known parsers
 *
 * Add a new parser by appending its class to ::V1_PARSERS. The phase-A2
 * seeder maps each parser_class onto a market_report_types row by FQCN, so
 * keep the FQCNs in sync there.
 *
 * Spec: .ai/specs/mic-complete-spec.md §8.2.
 */
final class MarketReportParserRegistry
{
    /** @var class-string<MarketReportParser>[] */
    public const V1_PARSERS = [
        CmaInfoMarketAnalysisParser::class,
        CmaInfoMedianSalesAnalysisParser::class,
        CmaInfoPropertyValuationParser::class,
        CmaInfoSectionalTitleSalesParser::class,
        CmaInfoVicinitySaleParser::class,
        CmaInfoSchemeOwnersListParser::class,
        // A publisher's layout CoreX learned from an earlier AI read (mic-complete-spec §8.9).
        // Before the generic fallback so a known layout never reaches the AI again.
        LearnedLayoutParser::class,
        // GenericFallbackParser ALWAYS last — its 0.1 floor anchors the
        // tiebreaker so the upload pipeline always gets at least one match.
        GenericFallbackParser::class,
    ];

    /**
     * @return MarketReportParser[]
     */
    public function all(): array
    {
        return array_map(fn (string $fqcn) => app($fqcn), self::V1_PARSERS);
    }

    /**
     * Returns the best-matching parser + its confidence for the given file.
     *
     * @return array{parser: MarketReportParser, confidence: \App\Services\MarketReports\DTOs\ParserConfidence}
     */
    public function detect(string $filePath): array
    {
        $best = null;
        $bestScore = -1.0;

        foreach ($this->all() as $parser) {
            $conf = $parser->canParse($filePath);
            if ($conf->score > $bestScore) {
                $best = $parser;
                $bestConf = $conf;
                $bestScore = $conf->score;
            }
        }

        return ['parser' => $best, 'confidence' => $bestConf];
    }

    /**
     * Look up a parser by its report-type key (cma_info_market_analysis,
     * cma_info_median_sales_analysis, …). Returns the GenericFallbackParser
     * when no match is registered.
     */
    public function resolveByKey(string $key): MarketReportParser
    {
        foreach ($this->all() as $parser) {
            if ($parser->getReportTypeKey() === $key) {
                return $parser;
            }
        }
        return app(GenericFallbackParser::class);
    }
}
