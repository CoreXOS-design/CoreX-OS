<?php

declare(strict_types=1);

namespace App\Services\MarketIntelligence\Dom;

/** Days on market with how sure we are (mic-work-tab.md "Numbers say how sure they are", contract 11.1). */
final class DomResult
{
    public const LISTED = 'listed';
    public const APPROX = 'approx';
    public const MINIMUM = 'minimum';
    public const UNKNOWN = 'unknown';

    public function __construct(
        public readonly ?int $days,
        public readonly string $mode,
        public readonly string $label,
        public readonly ?string $source,
    ) {
    }

    public static function unknown(): self
    {
        return new self(null, self::UNKNOWN, 'not known', null);
    }

    /** @return array{days:?int,mode:string,label:string,source:?string} */
    public function toArray(): array
    {
        return ['days' => $this->days, 'mode' => $this->mode, 'label' => $this->label, 'source' => $this->source];
    }
}
