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

    /** @var list<string>|null */
    private ?array $fieldIdCache = null;

    /** @var array<string,list<string>>|null */
    private ?array $anchorMapCache = null;

    private ?\DOMDocument $domCache = null;
    private bool $domParsed = false;

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
        $dom = $this->dom();
        if ($dom === null) {
            return $this->fingerprintCache = $blocks;
        }

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

    /**
     * The field_ids whose fill-points actually appear in the rendered body (for the golden
     * harness's "expected bound fields populated, none dropped" assertion).
     *
     * @return list<string>
     */
    public function fieldIds(): array
    {
        if ($this->fieldIdCache !== null) {
            return $this->fieldIdCache;
        }

        $ids = [];
        $dom = $this->dom();
        if ($dom !== null) {
            foreach ($dom->getElementsByTagName('*') as $el) {
                if ($el instanceof DOMElement && $el->hasAttribute('data-field-id')) {
                    $ids[] = $el->getAttribute('data-field-id');
                }
            }
        }

        return $this->fieldIdCache = array_values(array_unique($ids));
    }

    /**
     * partyKey (instance) → the anchor_ids placed for that signer in the rendered body (for
     * the golden harness's "every present signer has a place to sign" assertion).
     *
     * @return array<string,list<string>>
     */
    public function anchorMap(): array
    {
        if ($this->anchorMapCache !== null) {
            return $this->anchorMapCache;
        }

        $map = [];
        $dom = $this->dom();
        if ($dom !== null) {
            foreach ($dom->getElementsByTagName('*') as $el) {
                if ($el instanceof DOMElement && $el->hasAttribute('data-anchor-party')) {
                    $party = $el->getAttribute('data-anchor-party');
                    $map[$party][] = $el->getAttribute('data-anchor-id');
                }
            }
        }

        return $this->anchorMapCache = $map;
    }

    private function dom(): ?DOMDocument
    {
        if ($this->domParsed) {
            return $this->domCache;
        }
        $this->domParsed = true;

        if (trim($this->html) === '') {
            return $this->domCache = null;
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

        return $this->domCache = $dom;
    }

    private static function normalizeText(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
