<?php

namespace Tests\Unit;

use App\Services\Docuperfect\CdsParserService;
use App\Services\Docuperfect\DocxTooLargeException;
use Tests\TestCase;
use ZipArchive;

/**
 * Zip-bomb guard: a small .docx that declares a huge part (or far too many parts) is refused BEFORE it is inflated, with a
 * message the author can read. DB-free. The crafted archives are tiny on disk: a long run of one character deflates ~1000:1.
 */
class CdsParserZipLimitsTest extends TestCase
{
    private function docx(callable $fill): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zb') . '.docx';
        $zip = new ZipArchive();
        $zip->open($path, ZipArchive::CREATE);
        $fill($zip);
        $zip->close();

        return $path;
    }

    public function test_a_part_that_declares_more_than_the_cap_is_refused_before_it_is_inflated(): void
    {
        $path = $this->docx(function (ZipArchive $zip) {
            $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
            $zip->addFromString('word/styles.xml', str_repeat(' ', 26_000_000));
        });

        $this->assertLessThan(200_000, filesize($path), 'the crafted archive is small on disk');
        try {
            app(CdsParserService::class)->parse($path);
            $this->fail('an oversized part must be refused');
        } catch (DocxTooLargeException $e) {
            $this->assertStringContainsString('too large', $e->getMessage());
        } finally {
            @unlink($path);
        }
    }

    public function test_too_many_parts_are_refused(): void
    {
        $path = $this->docx(function (ZipArchive $zip) {
            $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
            for ($i = 0; $i < 5001; $i++) {
                $zip->addFromString("junk/{$i}.txt", 'x');
            }
        });

        try {
            $this->expectException(DocxTooLargeException::class);
            app(CdsParserService::class)->parse($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_a_normal_small_docx_still_parses(): void
    {
        $path = $this->docx(function (ZipArchive $zip) {
            $zip->addFromString('word/document.xml', '<?xml version="1.0"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello world</w:t></w:r></w:p></w:body></w:document>');
        });

        try {
            $cds = app(CdsParserService::class)->parse($path);
            $this->assertStringContainsString('Hello world', (string) $cds['original_text']);
        } finally {
            @unlink($path);
        }
    }
}
