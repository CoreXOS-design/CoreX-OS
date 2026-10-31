<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Pockets;

/**
 * One hot-pocket tile's answer (contract 11.1): a count, a one-line reason, the list filter a click applies, and an honest
 * "too little data" flag. Immutable; built only by TileService from STORED numbers.
 */
final class TileResult
{
    /**
     * @param  array<string, mixed>  $filter  query params the Work list applies, always ['tile' => <key>] (+ optional narrowing params)
     * @param  array<string, mixed>  $meta    tile-specific numbers (see spec 11.1)
     */
    public function __construct(
        public readonly int $tile,
        public readonly string $key,
        public readonly ?int $count,
        public readonly string $reason,
        public readonly array $filter,
        public readonly bool $tooLittleData = false,
        public readonly ?string $tooLittleMessage = null,
        public readonly array $meta = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tile' => $this->tile, 'key' => $this->key, 'count' => $this->count, 'reason' => $this->reason, 'filter' => $this->filter,
            'tooLittleData' => $this->tooLittleData, 'tooLittleMessage' => $this->tooLittleMessage, 'meta' => $this->meta,
        ];
    }
}
