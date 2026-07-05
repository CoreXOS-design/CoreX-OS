<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Enums;

/**
 * CDS v2 — the three preserved delivery modes (March CDS decision, spec §1/§6.1).
 * All three render from the ONE canonical structure; no divergent code paths.
 */
enum DeliveryMode: string
{
    case WebEsign = 'web_esign';
    case PdfWetInk = 'pdf_wetink';
    case Download = 'download';
}
