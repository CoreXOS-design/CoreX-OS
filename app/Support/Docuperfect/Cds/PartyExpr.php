<?php

declare(strict_types=1);

namespace App\Support\Docuperfect\Cds;

use InvalidArgumentException;

/**
 * CDS v2 — a DECLARED party expression (spec §2 Block.visibility / Block.editability).
 *
 * Replaces today's request-time `data-viewer-editable` stamping and HTML role detection:
 * who sees / who may edit a block is COMPILED IN, not detected at serve time.
 *
 * A key in {@see $partyKeys} may be:
 *   - a specific instance  — "seller_1"
 *   - a role base          — "seller"  (matches every seller_1, seller_2 …)
 *
 * The renderer projects a block for a signer via {@see appliesTo()}; the linter (L4)
 * evaluates it across the enumerated party space to prove no combination strands a block.
 */
final class PartyExpr
{
    public const MODE_ALL = 'all';
    public const MODE_NONE = 'none';
    public const MODE_ONLY = 'only';
    public const MODE_EXCEPT = 'except';

    /**
     * @param list<string> $partyKeys
     */
    public function __construct(
        public readonly string $mode,
        public readonly array $partyKeys = [],
    ) {
        if (! in_array($mode, [self::MODE_ALL, self::MODE_NONE, self::MODE_ONLY, self::MODE_EXCEPT], true)) {
            throw new InvalidArgumentException("Unknown PartyExpr mode [{$mode}].");
        }
        if (($mode === self::MODE_ONLY || $mode === self::MODE_EXCEPT) && $partyKeys === []) {
            throw new InvalidArgumentException("PartyExpr mode [{$mode}] requires at least one party key.");
        }
    }

    public static function all(): self
    {
        return new self(self::MODE_ALL);
    }

    public static function none(): self
    {
        return new self(self::MODE_NONE);
    }

    /** @param list<string> $partyKeys */
    public static function only(array $partyKeys): self
    {
        return new self(self::MODE_ONLY, array_values($partyKeys));
    }

    /** @param list<string> $partyKeys */
    public static function except(array $partyKeys): self
    {
        return new self(self::MODE_EXCEPT, array_values($partyKeys));
    }

    /**
     * Does this expression apply to a concrete party instance (e.g. "seller_2")?
     * Matching is by exact key OR by role base (the instance's "_N" suffix stripped).
     */
    public function appliesTo(string $partyKey): bool
    {
        return match ($this->mode) {
            self::MODE_ALL => true,
            self::MODE_NONE => false,
            self::MODE_ONLY => $this->matchesAny($partyKey),
            self::MODE_EXCEPT => ! $this->matchesAny($partyKey),
        };
    }

    private function matchesAny(string $partyKey): bool
    {
        $base = self::roleBase($partyKey);

        foreach ($this->partyKeys as $declared) {
            if ($declared === $partyKey || $declared === $base) {
                return true;
            }
        }

        return false;
    }

    /** "seller_2" → "seller"; "agent" → "agent". */
    public static function roleBase(string $partyKey): string
    {
        return preg_replace('/_\d+$/', '', $partyKey) ?? $partyKey;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['mode'] ?? self::MODE_ALL),
            array_values(array_map('strval', $data['party_keys'] ?? [])),
        );
    }

    /** @return array{mode:string,party_keys:list<string>} */
    public function toArray(): array
    {
        return [
            'mode' => $this->mode,
            'party_keys' => $this->partyKeys,
        ];
    }
}
