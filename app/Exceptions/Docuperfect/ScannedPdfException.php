<?php

declare(strict_types=1);

namespace App\Exceptions\Docuperfect;

use RuntimeException;

/**
 * ES-6 — thrown when a PDF uploaded to the CDS import path is image-only /
 * scanned (no extractable text). Faithful OCR import of legal documents is a
 * documented deferral; the controller catches this to show the user actionable
 * guidance (supply a text-based PDF or the Word version) rather than producing
 * a low-fidelity template. Carries a user-safe message.
 */
class ScannedPdfException extends RuntimeException
{
}
