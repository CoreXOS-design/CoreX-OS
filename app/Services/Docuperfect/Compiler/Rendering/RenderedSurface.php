<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\Compiler\Rendering;

use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use DOMDocument;
use DOMElement;

/**
 * AT-177 / WS2 — the output of one render of a compiled document (spec §6).
 *
 * Carries the rendered `html` for a mode + party combination AND its STRUCTURAL FINGERPRINT
 * — the ordered, presentation-independent semantic content (block ids/types, normalized
 * text/bound values, per-anchor party+kind) extracted by parsing the produced HTML.
 *
 * The fingerprint is the unit of L6 render-parity: web and print are compared on their
 * fingerprints, so a divergence in EITHER renderer (a dropped block, a missing anchor, a
 * changed value) is caught — the check is real, not a tautology over the source CDS.
 */
final class RenderedSurface
{
    /** @var list<array{block_id:string,type:string,text:string,anchors:list<array{party:string,kind:string}>}>|null */
    private ?array $fingerprintCache = null;

    /**
     * @param list<string> $activePartyKeys
     */
    public function __construct(
        public readonly string $html,
        public readonly DeliveryMode $mode,
        public readonly array $activePartyKeys,
        public readonly ?string $viewerPartyKey = null,
    ) {
    }

    /**
     * The presentation-independent structural fingerprint, parsed FROM the rendered HTML.
     *
     * @return list<array{block_id:string,type:string,text:string,anchors:list<array{party:string,kind:string}>}>
     */
    public function fingerprint(): array
    {
        if ($this->fingerprintCache !== null) {
            return $this->fingerprintCache;
        }

        $blocks = [];
        if (trim($this->html) === '') {
            return $this->fingerprintCache = $blocks;
        }

        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        // Wrap so a fragment parses; force UTF-8.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"><div id="__root__">' . $this->html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        foreach ($dom->getElementsByTagName('*') as $el) {
            if (! $el instanceof DOMElement || ! $el->hasAttribute('data-block-id')) {
                continue;
            }

            $anchors = [];
            foreach ($el->getElementsByTagName('*') as $descendant) {
                if ($descendant instanceof DOMElement && $descendant->hasAttribute('data-anchor-party')) {
                    $anchors[] = [
                        'party' => $descendant->getAttribute('data-anchor-party'),
                        'kind' => $descendant->getAttribute('data-anchor-kind'),
                    ];
                }
            }

            $blocks[] = [
                'block_id' => $el->getAttribute('data-block-id'),
                'type' => $el->getAttribute('data-block-type'),
                'text' => self::normalizeText($el->textContent),
                'anchors' => $anchors,
            ];
        }

        return $this->fingerprintCache = $blocks;
    }

    public function fingerprintHash(): string
    {
        return hash('sha256', (string) json_encode(
            $this->fingerprint(),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ));
    }

    private static function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
