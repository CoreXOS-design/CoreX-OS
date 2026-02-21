<?php

namespace App\Services\Presentations;

use Illuminate\Support\Str;

/**
 * Deterministic, slug-safe file naming for presentation uploads.
 *
 * Contract:
 *  - Same file content + same original name → identical output every time.
 *  - Output is filesystem-safe (lowercase, ASCII, hyphens only).
 *  - Content hash suffix prevents collisions when two different files
 *    share the same slugified name.
 *  - The full slug is stored in DB (file_slug column) so the system
 *    of record is always the database, never the filesystem.
 *
 * Format: {doc_type}-{slugified_stem}-{hash8}.{ext}
 *
 * Examples:
 *   "CMA Report (2024).pdf"  → "cma-v1-cma-report-2024-a1b2c3d4.pdf"
 *   "Vicinity Sales.xlsx"    → "sales-report-v1-vicinity-sales-e5f6g7h8.xlsx"
 *   "Unknown Doc.docx"       → "unknown-unknown-doc-1a2b3c4d.docx"
 */
class FileNamingService
{
    /**
     * Generate a deterministic, slug-safe filename.
     *
     * @param  string $originalFilename  The user-provided original filename.
     * @param  string $fileContents      Raw binary content of the file (for hashing).
     * @param  string $docType           Detected doc type (e.g. 'cma_v1', 'sales_report_v1').
     * @return string  Slug-safe filename with extension.
     */
    public function generate(string $originalFilename, string $fileContents, string $docType = 'unknown'): string
    {
        $ext  = $this->extractExtension($originalFilename);
        $stem = $this->extractStem($originalFilename);
        $slug = Str::slug($stem, '-');

        // Guard against empty slug (e.g. filename was all special chars)
        if ($slug === '') {
            $slug = 'upload';
        }

        // Truncate slug to prevent absurdly long filenames (max 80 chars for stem)
        $slug = Str::limit($slug, 80, '');

        $hash8   = substr(hash('sha256', $fileContents), 0, 8);
        $typeSlug = Str::slug($docType, '-');

        return "{$typeSlug}-{$slug}-{$hash8}.{$ext}";
    }

    /**
     * Build the full storage path for a presentation upload.
     *
     * @param  int    $presentationId
     * @param  string $fileSlug  Output of generate().
     * @return string  Relative path like "presentations/5/cma-v1-report-a1b2c3d4.pdf"
     */
    public function storagePath(int $presentationId, string $fileSlug): string
    {
        return "presentations/{$presentationId}/{$fileSlug}";
    }

    /**
     * Compute the SHA-256 content hash for a file's raw bytes.
     */
    public function contentHash(string $fileContents): string
    {
        return hash('sha256', $fileContents);
    }

    /**
     * Extract the lowercased file extension (without dot).
     */
    private function extractExtension(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $ext !== '' ? $ext : 'bin';
    }

    /**
     * Extract the filename stem (without extension).
     */
    private function extractStem(string $filename): string
    {
        return pathinfo($filename, PATHINFO_FILENAME);
    }
}
