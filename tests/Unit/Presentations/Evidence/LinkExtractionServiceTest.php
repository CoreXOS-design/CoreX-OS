<?php

namespace Tests\Unit\Presentations\Evidence;

use App\Services\Presentations\Evidence\LinkExtractionService;
use PHPUnit\Framework\TestCase;

class LinkExtractionServiceTest extends TestCase
{
    private LinkExtractionService $service;

    protected function setUp(): void
    {
        $this->service = new LinkExtractionService();
    }

    public function test_service_version_constant_is_set(): void
    {
        $this->assertSame('link_extraction_v2', LinkExtractionService::SERVICE_VERSION);
    }
}
