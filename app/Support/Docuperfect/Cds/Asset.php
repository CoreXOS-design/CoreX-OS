<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

/**
 * CDS v2 — a pinned document asset (spec §2 assets), e.g. letterhead or an embedded image.
 *
 * An asset is pinned by `hash` and re-resolvable by `ref`, so a compiled template never
 * bakes a stale agency letterhead (obsoletes LetterheadRefresher's serve-time swap): the
 * asset is a structural reference, resolved fresh at render, verified against the hash.
 */
final class Asset
{
    /**
     * @param string $kind e.g. "letterhead", "image", "logo"
     */
    public function __construct(
        public readonly string $key,
        public readonly string $kind,
        public readonly string $ref,
        public readonly ?string $hash = null,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) $data['key'],
            (string) $data['kind'],
            (string) $data['ref'],
            isset($data['hash']) ? (string) $data['hash'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'kind' => $this->kind,
            'ref' => $this->ref,
            'hash' => $this->hash,
        ];
    }
}
