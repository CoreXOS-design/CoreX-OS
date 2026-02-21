<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\Parsers\CmaParserV1;
use App\Services\Presentations\Evidence\Parsers\SalesReportParserV1;
use App\Services\Presentations\Evidence\Parsers\SuburbStockParserV1;
use App\Services\Presentations\Evidence\Parsers\UnknownParser;
use App\Services\Presentations\Evidence\UploadExtractionService;
use PHPUnit\Framework\TestCase;

class UploadExtractionServiceTest extends TestCase
{
    private UploadExtractionService $service;

    protected function setUp(): void
    {
        $this->service = new UploadExtractionService();
    }

    // ── Doc-type routing ──────────────────────────────────────────────────────

    public function test_vicinity_filename_routes_to_sales_report(): void
    {
        $this->assertSame(SalesReportParserV1::DOC_TYPE, $this->service->detectDocType('vicinity_cape_town_2024.pdf'));
    }

    public function test_sales_filename_routes_to_sales_report(): void
    {
        $this->assertSame(SalesReportParserV1::DOC_TYPE, $this->service->detectDocType('Sales Report Q1.pdf'));
    }

    public function test_suburb_filename_routes_to_suburb_stock(): void
    {
        $this->assertSame(SuburbStockParserV1::DOC_TYPE, $this->service->detectDocType('suburb_stock_jan.pdf'));
    }

    public function test_stock_filename_routes_to_suburb_stock(): void
    {
        $this->assertSame(SuburbStockParserV1::DOC_TYPE, $this->service->detectDocType('Active Stock Report.pdf'));
    }

    public function test_cma_filename_routes_to_cma(): void
    {
        $this->assertSame(CmaParserV1::DOC_TYPE, $this->service->detectDocType('CMA_Greenpoint.pdf'));
    }

    public function test_valuation_filename_routes_to_cma(): void
    {
        $this->assertSame(CmaParserV1::DOC_TYPE, $this->service->detectDocType('Valuation Report 2024.pdf'));
    }

    public function test_unrecognised_filename_routes_to_unknown(): void
    {
        $this->assertSame(UnknownParser::DOC_TYPE, $this->service->detectDocType('document.pdf'));
    }

    public function test_routing_is_case_insensitive(): void
    {
        $this->assertSame(SalesReportParserV1::DOC_TYPE, $this->service->detectDocType('VICINITY_REPORT.PDF'));
        $this->assertSame(SuburbStockParserV1::DOC_TYPE, $this->service->detectDocType('SUBURB_STOCK.PDF'));
        $this->assertSame(CmaParserV1::DOC_TYPE, $this->service->detectDocType('CMA.PDF'));
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_same_filename_always_routes_to_same_type(): void
    {
        $result1 = $this->service->detectDocType('sales_report.pdf');
        $result2 = $this->service->detectDocType('sales_report.pdf');

        $this->assertSame($result1, $result2);
    }
}
