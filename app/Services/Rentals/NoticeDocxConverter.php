<?php

namespace App\Services\Rentals;

use App\Services\Docuperfect\DocxParserService;

/**
 * .ai/specs/rental-notices.md §3 - an agency uploads its OWN notice document (Word, with {{placeholders}}) and it becomes the template body.
 * It uses the SAME converter the e-sign importer uses (Mammoth, via DocxParserService::generateHtmlWithMammoth) and none of the importer's
 * signature-field detection: a notice is not signed on screen. Only plain document markup is kept (no scripts, no remote content).
 */
class NoticeDocxConverter extends DocxParserService
{
    private const KEEP = '<p><br><strong><b><em><i><u><ul><ol><li><table><thead><tbody><tr><td><th><h1><h2><h3><h4><h5><h6><span><div><sub><sup>';

    /** @throws \RuntimeException when the document cannot be converted */
    public function toTemplateHtml(string $path): string
    {
        $html = $this->generateHtmlWithMammoth($path)['html'];
        $html = strip_tags($html, self::KEEP);
        // Mammoth puts no styling on, but strip any attribute that could carry script or a remote reference
        $html = (string) preg_replace('/\s(on\w+|style|class|id|href|src)\s*=\s*("[^"]*"|\'[^\']*\')/i', '', $html);

        return trim($html);
    }
}
