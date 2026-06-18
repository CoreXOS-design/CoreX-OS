<?php

namespace App\Http\Controllers\Concerns;

/**
 * Shared allow-list for user document/file uploads.
 *
 * Why: an upload rule of just `file|max:N` accepts ANY type, including active
 * content (.php, .phtml, .svg, .html, .xhtml) that becomes a stored-XSS or RCE
 * vector when served back from the same origin. Every upload ingress must
 * constrain the type. Centralised here so the list is fixed in one place
 * ("fix the class, not the instance").
 */
trait ValidatesDocumentUploads
{
    /**
     * Extensions allowed for document-drive uploads (contacts & properties).
     * Laravel's `mimes:` rule validates against the file's real MIME type, so a
     * renamed `.php` masquerading as `.pdf` is still rejected.
     */
    protected array $allowedDocumentExtensions = [
        // Documents
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'odp',
        'txt', 'csv', 'rtf',
        // Images
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic', 'heif', 'bmp', 'tiff',
        // Archives
        'zip',
    ];

    /**
     * Build the validation rule string for an uploaded document field.
     *
     * @param  int  $maxKb  Max size in kilobytes.
     */
    protected function documentUploadRule(int $maxKb = 51200): string
    {
        return 'required|file|max:' . $maxKb . '|mimes:' . implode(',', $this->allowedDocumentExtensions);
    }
}
