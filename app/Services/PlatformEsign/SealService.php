<?php

namespace App\Services\PlatformEsign;

use App\Models\PlatformEsign\Document;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Storage;

/** Builds the sealed PDF of a fully signed Platform E-Sign document: the contract, then the signing record. */
class SealService
{
    private const PAGE_W = 595.0;  // A4 in pt
    private const PAGE_H = 842.0;

    public function build(Document $doc): string
    {
        if ($doc->isWebdoc()) {
            // Web documents (Subscription Agreement) are typeset and sealed by their own builder — spec §11.6.
            return app(\App\Services\PlatformEsign\Agreement\AgreementService::class)->sealedPdf($doc);
        }
        $doc->loadMissing(['signers', 'events', 'agency', 'values']);
        // Never hand the renderer a drawn signature that fails the size limits (legacy rows): the typed name is shown instead. In-memory only.
        foreach ($doc->signers as $s) {
            if ($s->signature_image && !EsignService::signatureUsable($s->signature_image)) {
                $s->signature_image = null;
            }
        }

        $pages = [];
        if ($doc->isPdf()) {
            $values = $doc->values->keyBy(fn ($v) => $v->signer_id . ':' . $v->field_id);
            $signers = $doc->signers->keyBy('role_key');
            for ($i = 0; $i < $doc->page_count; $i++) {
                $abs = Storage::disk(EsignService::DISK)->path('platform-esign/documents/' . $doc->id . '/pages/p' . $i . '.png');
                $overlays = [];
                foreach ($doc->fields_json ?? [] as $f) {
                    if ($f['page_index'] !== $i || !($s = $signers[$f['role_key']] ?? null)) {
                        continue;
                    }
                    $overlays[] = [
                        'left' => $f['x'] / 100 * self::PAGE_W, 'top' => $f['y'] / 100 * self::PAGE_H,
                        'width' => $f['w'] / 100 * self::PAGE_W, 'height' => $f['h'] / 100 * self::PAGE_H,
                        'type' => $f['type'], 'signer' => $s,
                        'value' => $values[$s->id . ':' . $f['id']]->value ?? null,
                    ];
                }
                $pages[] = [
                    'image' => is_file($abs) ? 'data:image/png;base64,' . base64_encode(file_get_contents($abs)) : null,
                    'overlays' => $overlays,
                ];
            }
        }

        return Pdf::loadView('platform-esign.pdf.sealed', [
            'doc' => $doc, 'pages' => $pages, 'consent' => EsignService::CONSENT,
            'w' => self::PAGE_W, 'h' => self::PAGE_H,
        ])->setPaper('a4')->output();
    }

    public static function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];

        return mb_strtoupper(implode('', array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 3))));
    }
}
