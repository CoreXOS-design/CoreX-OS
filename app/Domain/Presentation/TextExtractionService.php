<?php

namespace App\Domain\Presentation;

class TextExtractionService
{
    /**
     * Extract plain text from a stored file.
     *
     * Supports PDF via the pdftotext CLI utility (poppler-utils).
     * If the utility is not available or extraction fails, returns an empty
     * string — the caller is responsible for marking extraction_status = failed.
     *
     * Never throws. Never calls AI. Deterministic only.
     *
     * @param  string $absolutePath  Full filesystem path to the file.
     * @param  string $mimeType      MIME type reported by the upload.
     * @return string                Raw extracted text, or '' on failure.
     */
    public function extractText(string $absolutePath, string $mimeType): string
    {
        if (!$this->isPdf($mimeType)) {
            // Other file types not yet supported — return empty gracefully.
            return '';
        }

        return $this->extractPdfText($absolutePath);
    }

    private function isPdf(string $mimeType): bool
    {
        return stripos($mimeType, 'pdf') !== false;
    }

    private function extractPdfText(string $absolutePath): string
    {
        // pdftotext must be installed (poppler-utils on Linux/macOS, Xpdf on Windows).
        // On Windows this is typically not available; extraction_status will be 'failed'.
        if (!$this->commandExists('pdftotext')) {
            return '';
        }

        try {
            $escaped = escapeshellarg($absolutePath);
            // '-' as output means stdout
            $output = shell_exec("pdftotext {$escaped} -");
            return is_string($output) ? trim($output) : '';
        } catch (\Throwable $e) {
            return '';
        }
    }

    private function commandExists(string $command): bool
    {
        $result = shell_exec(PHP_OS_FAMILY === 'Windows'
            ? "where {$command} 2>NUL"
            : "command -v {$command} 2>/dev/null");
        return !empty($result);
    }
}
