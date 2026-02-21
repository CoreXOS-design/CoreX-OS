<?php

namespace Tests\Unit\Presentations;

use App\Models\PresentationLink;
use App\Models\PresentationUpload;
use PHPUnit\Framework\TestCase;

/**
 * Tests that getVerifiedData() safely handles double-encoded JSON strings.
 *
 * When a model casts a column to 'array', Laravel auto-encodes on write
 * and auto-decodes on read. If the write path accidentally called
 * json_encode() first, the DB stores a double-encoded string like:
 *   "{\"key\":\"value\"}"
 * On read, Laravel decodes once → returns a plain string.
 * getVerifiedData() must handle this gracefully via safeArray().
 */
class DoubleEncodedJsonTest extends TestCase
{
    // ── PresentationLink ───────────────────────────────────────────────

    public function test_link_getVerifiedData_returns_array_from_normal_array(): void
    {
        $link = new PresentationLink();
        $link->extracted_json = ['asking_price' => 1500000, 'beds' => 3];

        $result = $link->getVerifiedData();

        $this->assertIsArray($result);
        $this->assertSame(1500000, $result['asking_price']);
        $this->assertSame(3, $result['beds']);
    }

    public function test_link_getVerifiedData_handles_single_encoded_string(): void
    {
        $link = new PresentationLink();
        // Simulate: DB has a JSON string that Laravel's cast decoded to a string
        // (shouldn't happen with proper cast, but safeArray handles it)
        $link->setRawAttributes([
            'extracted_json' => json_encode(['asking_price' => 1200000]),
            'override_json'  => null,
        ]);

        $result = $link->getVerifiedData();

        $this->assertIsArray($result);
        $this->assertSame(1200000, $result['asking_price']);
    }

    public function test_link_getVerifiedData_handles_double_encoded_string(): void
    {
        $link = new PresentationLink();
        // Simulate double-encoding: json_encode was called twice
        $inner = json_encode(['asking_price' => 950000, 'beds' => 2]);
        $link->setRawAttributes([
            'extracted_json' => json_encode($inner), // double-encoded
            'override_json'  => null,
        ]);

        $result = $link->getVerifiedData();

        $this->assertIsArray($result);
        $this->assertSame(950000, $result['asking_price']);
        $this->assertSame(2, $result['beds']);
    }

    public function test_link_getVerifiedData_returns_null_for_empty(): void
    {
        $link = new PresentationLink();
        $link->extracted_json = null;
        $link->override_json = null;

        $this->assertNull($link->getVerifiedData());
    }

    public function test_link_getVerifiedData_prefers_override(): void
    {
        $link = new PresentationLink();
        $link->extracted_json = ['asking_price' => 1000000];
        $link->override_json = ['asking_price' => 1100000];

        $result = $link->getVerifiedData();

        $this->assertSame(1100000, $result['asking_price']);
    }

    // ── PresentationUpload ─────────────────────────────────────────────

    public function test_upload_getVerifiedData_returns_array_from_normal_array(): void
    {
        $upload = new PresentationUpload();
        $upload->extraction_json = ['parser_version' => 'v1', 'parsed_counts' => ['sold_comps' => 5]];

        $result = $upload->getVerifiedData();

        $this->assertIsArray($result);
        $this->assertSame('v1', $result['parser_version']);
    }

    public function test_upload_getVerifiedData_handles_single_encoded_string(): void
    {
        $upload = new PresentationUpload();
        $upload->setRawAttributes([
            'extraction_json' => json_encode(['aggregates' => ['median_price' => 1500000]]),
            'override_json'   => null,
        ]);

        $result = $upload->getVerifiedData();

        $this->assertIsArray($result);
        $this->assertSame(1500000, $result['aggregates']['median_price']);
    }

    public function test_upload_getVerifiedData_handles_double_encoded_string(): void
    {
        $upload = new PresentationUpload();
        $inner = json_encode(['aggregates' => ['sold_count' => 23, 'median_price' => 1620000]]);
        $upload->setRawAttributes([
            'extraction_json' => json_encode($inner), // double-encoded
            'override_json'   => null,
        ]);

        $result = $upload->getVerifiedData();

        $this->assertIsArray($result);
        $this->assertSame(23, $result['aggregates']['sold_count']);
        $this->assertSame(1620000, $result['aggregates']['median_price']);
    }

    public function test_upload_getVerifiedData_returns_null_for_empty(): void
    {
        $upload = new PresentationUpload();
        $upload->extraction_json = null;
        $upload->override_json = null;

        $this->assertNull($upload->getVerifiedData());
    }

    public function test_upload_getVerifiedData_prefers_override(): void
    {
        $upload = new PresentationUpload();
        $upload->extraction_json = ['aggregates' => ['median_price' => 1500000]];
        $upload->override_json = ['aggregates' => ['median_price' => 1600000]];

        $result = $upload->getVerifiedData();

        $this->assertSame(1600000, $result['aggregates']['median_price']);
    }
}
