<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

use App\Support\Docuperfect\Cds\Enums\DeliveryMode;
use App\Support\Docuperfect\Cds\Enums\LegalClass;

/**
 * CDS v2 — the Compiled Document Structure. The SOLE runtime truth (spec §2).
 *
 * A typed, addressable tree: declared parties, ordered typed blocks, pinned assets, the
 * enabled delivery modes, the legal class, and the dictionary version the bindings resolve
 * against. Everything the runtime needs is IN here — no merged HTML, no re-derivation, no
 * serve-time normalisation. The compiled tree already IS the normalised surface.
 *
 * This root value object is the shared contract WS1 (linter) and WS2 (renderer) both code
 * against. It is stored verbatim in `compiled_templates.structure`; {@see contentHash()}
 * is the immutability anchor a signing request pins (spec §5).
 */
final class Cds
{
    /**
     * @param list<DeliveryMode> $deliveryModes
     * @param list<Party>        $parties
     * @param list<Block>        $blocks
     * @param list<Asset>        $assets
     */
    public function __construct(
        public readonly string $family,
        public readonly int $dataDictionaryVersion,
        public readonly LegalClass $legalClass,
        public readonly array $deliveryModes,
        public readonly array $parties,
        public readonly array $blocks,
        public readonly array $assets = [],
        public readonly ?RenderParity $renderParity = null,
    ) {
    }

    public function hasDeliveryMode(DeliveryMode $mode): bool
    {
        return in_array($mode, $this->deliveryModes, true);
    }

    /** @return list<Party> */
    public function parties(): array
    {
        return $this->parties;
    }

    /** @return list<Block> */
    public function blocks(): array
    {
        return $this->blocks;
    }

    public function party(string $key): ?Party
    {
        foreach ($this->parties as $party) {
            if ($party->key === $key) {
                return $party;
            }
        }

        return null;
    }

    public function block(string $blockId): ?Block
    {
        foreach ($this->blocks as $block) {
            if ($block->blockId === $blockId) {
                return $block;
            }
        }

        return null;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['family'] ?? ''),
            (int) ($data['data_dictionary_version'] ?? 1),
            LegalClass::from((string) ($data['legal_class'] ?? LegalClass::General->value)),
            array_values(array_map(
                static fn ($m) => $m instanceof DeliveryMode ? $m : DeliveryMode::from((string) $m),
                $data['delivery_modes'] ?? [],
            )),
            array_values(array_map([Party::class, 'fromArray'], $data['parties'] ?? [])),
            array_values(array_map([Block::class, 'fromArray'], $data['blocks'] ?? [])),
            array_values(array_map([Asset::class, 'fromArray'], $data['assets'] ?? [])),
            RenderParity::fromArray($data['render_parity'] ?? null),
        );
    }

    /**
     * Full serialisation for storage in `compiled_templates.structure`.
     * Includes render_parity (proof metadata written after L6).
     */
    public function toArray(): array
    {
        return [
            'family' => $this->family,
            'data_dictionary_version' => $this->dataDictionaryVersion,
            'legal_class' => $this->legalClass->value,
            'delivery_modes' => array_map(static fn (DeliveryMode $m) => $m->value, $this->deliveryModes),
            'parties' => array_map(static fn (Party $p) => $p->toArray(), $this->parties),
            'blocks' => array_map(static fn (Block $b) => $b->toArray(), $this->blocks),
            'assets' => array_map(static fn (Asset $a) => $a->toArray(), $this->assets),
            'render_parity' => $this->renderParity?->toArray(),
        ];
    }

    /**
     * Deterministic content hash of the STRUCTURAL content (spec §5).
     *
     * Excludes `render_parity` (derived proof, written post-compile) and the assigned
     * `version` (a row-identity concern). Keys are recursively sorted so the hash is
     * independent of input key order — identical content always hashes identically.
     */
    public function contentHash(): string
    {
        $canonical = $this->toArray();
        unset($canonical['render_parity']);
        sort($canonical['delivery_modes']); // order-independent set

        self::deepKsort($canonical);

        return hash('sha256', (string) json_encode(
            $canonical,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        ));
    }

    private static function deepKsort(array &$array): void
    {
        ksort($array);
        foreach ($array as &$value) {
            if (is_array($value)) {
                self::deepKsort($value);
            }
        }
        unset($value);
    }
}
