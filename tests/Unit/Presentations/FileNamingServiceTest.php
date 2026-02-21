<?php

namespace Tests\Unit\Presentations;

use App\Services\Presentations\FileNamingService;
use PHPUnit\Framework\TestCase;

class FileNamingServiceTest extends TestCase
{
    private FileNamingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FileNamingService();
    }

    // ── Determinism ─────────────────────────────────────────────────────

    public function test_same_input_always_produces_same_output(): void
    {
        $content = 'Hello deterministic world';
        $a = $this->service->generate('Report.pdf', $content, 'cma_v1');
        $b = $this->service->generate('Report.pdf', $content, 'cma_v1');

        $this->assertSame($a, $b, 'Same inputs must produce identical filenames');
    }

    public function test_different_content_produces_different_hash(): void
    {
        $a = $this->service->generate('Report.pdf', 'content-A', 'cma_v1');
        $b = $this->service->generate('Report.pdf', 'content-B', 'cma_v1');

        $this->assertNotSame($a, $b, 'Different content must produce different filenames');
    }

    // ── Slug safety ─────────────────────────────────────────────────────

    public function test_output_is_slug_safe(): void
    {
        $slug = $this->service->generate(
            'CMA Report (2024) — Final!!.pdf',
            'some content',
            'cma_v1',
        );

        // Must not contain spaces, uppercase, or unsafe chars
        $this->assertMatchesRegularExpression(
            '/^[a-z0-9\-]+\.[a-z0-9]+$/',
            $slug,
            'Filename must be lowercase alphanumeric with hyphens and one dot before extension',
        );
    }

    public function test_special_characters_are_stripped(): void
    {
        $slug = $this->service->generate(
            'Müller & Söhne\'s Ré$ümé.xlsx',
            'content',
            'unknown',
        );

        $this->assertStringNotContainsString('&', $slug);
        $this->assertStringNotContainsString("'", $slug);
        $this->assertStringNotContainsString('$', $slug);
        $this->assertStringNotContainsString(' ', $slug);
        $this->assertStringEndsWith('.xlsx', $slug);
    }

    // ── Doc type prefix ────────────────────────────────────────────────

    public function test_doc_type_appears_as_prefix(): void
    {
        $slug = $this->service->generate('data.csv', 'abc', 'sales_report_v1');

        $this->assertStringStartsWith('sales-report-v1-', $slug);
    }

    // ── Extension handling ─────────────────────────────────────────────

    public function test_preserves_extension_lowercase(): void
    {
        $slug = $this->service->generate('MyFile.PDF', 'x', 'unknown');
        $this->assertStringEndsWith('.pdf', $slug);
    }

    public function test_missing_extension_defaults_to_bin(): void
    {
        $slug = $this->service->generate('noext', 'x', 'unknown');
        $this->assertStringEndsWith('.bin', $slug);
    }

    // ── Storage path ───────────────────────────────────────────────────

    public function test_storage_path_uses_presentation_id(): void
    {
        $fileSlug = $this->service->generate('doc.pdf', 'x', 'cma_v1');
        $path     = $this->service->storagePath(42, $fileSlug);

        $this->assertStringStartsWith('presentations/42/', $path);
        $this->assertStringEndsWith($fileSlug, $path);
    }

    // ── Content hash ───────────────────────────────────────────────────

    public function test_content_hash_is_sha256(): void
    {
        $content  = 'Hello deterministic world';
        $hash     = $this->service->contentHash($content);
        $expected = hash('sha256', $content);

        $this->assertSame($expected, $hash);
        $this->assertSame(64, strlen($hash), 'SHA-256 hex digest must be 64 chars');
    }

    public function test_content_hash_is_deterministic(): void
    {
        $content = 'identical content';
        $a = $this->service->contentHash($content);
        $b = $this->service->contentHash($content);

        $this->assertSame($a, $b);
    }

    // ── Edge cases ─────────────────────────────────────────────────────

    public function test_empty_filename_stem_defaults_to_upload(): void
    {
        $slug = $this->service->generate('.pdf', 'x', 'unknown');

        // The stem is empty, should fallback to 'upload'
        $this->assertStringContainsString('upload', $slug);
        $this->assertStringEndsWith('.pdf', $slug);
    }

    public function test_very_long_filename_is_truncated(): void
    {
        $longName = str_repeat('abcdefghij', 30) . '.pdf'; // 300 char stem
        $slug     = $this->service->generate($longName, 'x', 'unknown');

        // doc-type prefix + slug + hash + ext should be reasonable length
        $this->assertLessThan(200, strlen($slug), 'Slug must be under 200 chars');
    }

    // ── No drift: naming matches stored path ───────────────────────────

    public function test_storage_path_matches_naming_output(): void
    {
        $content  = 'file bytes here';
        $fileSlug = $this->service->generate('MyDoc.pdf', $content, 'suburb_stock_v1');
        $path     = $this->service->storagePath(7, $fileSlug);

        $this->assertSame("presentations/7/{$fileSlug}", $path);
    }
}
