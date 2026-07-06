<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds\Pipeline;

/**
 * AT-177 / WS4-E — a block-addressed note from segmentation (spec §3 step 2).
 *
 * Segmentation is deterministic + human-in-the-loop: where it is UNSURE (an ambiguous
 * fill-point, a signature zone with no detectable party, a table it could not type), it emits
 * one of these so the Compile Studio (WS4-S) surfaces it to the operator for confirmation —
 * never a silent guess.
 */
final class SegmentationWarning
{
    public const INFO = 'info';
    public const WARN = 'warn';

    public function __construct(
        public readonly string $blockId,
        public readonly string $code,
        public readonly string $message,
        public readonly string $severity = self::WARN,
    ) {
    }

    public static function info(string $blockId, string $code, string $message): self
    {
        return new self($blockId, $code, $message, self::INFO);
    }

    public static function warn(string $blockId, string $code, string $message): self
    {
        return new self($blockId, $code, $message, self::WARN);
    }

    public function toArray(): array
    {
        return [
            'block_id' => $this->blockId,
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
        ];
    }
}
