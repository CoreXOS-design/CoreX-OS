<?php

namespace App\Services\Docuperfect\Compiler\Support;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Contracts\DictionaryEntry;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * A concrete, framework-free {@see DataDictionaryResolver} backed by an in-memory map. It
 * holds whatever entries it is given — it is NOT the canonical CoreX SA dictionary (that
 * versioned seed is WS0's, cc2). This double lets the linter's golden fixtures and dev
 * tooling resolve bindings without a database, and gives WS0 a reference for the shape its
 * Eloquent-backed resolver must satisfy.
 *
 * Entries are keyed by (dictionaryVersion, ref), so the same ref can differ across
 * versions — exactly the versioning guarantee L2/L5 rely on.
 */
final class InMemoryDataDictionaryResolver implements DataDictionaryResolver
{
    /** @var array<int,array<string,DictionaryEntry>> version => [ref => entry] */
    private array $byVersion = [];

    /**
     * @param array<int,array<string,DictionaryEntry|array<string,mixed>>> $seed
     *        version => [ref => DictionaryEntry|array]
     */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $version => $entries) {
            foreach ($entries as $ref => $entry) {
                $this->put((int) $version, (string) $ref, $entry);
            }
        }
    }

    /**
     * Register/overwrite an entry at a version.
     *
     * @param DictionaryEntry|array<string,mixed> $entry
     */
    public function put(int $version, string $ref, DictionaryEntry|array $entry): self
    {
        if (is_array($entry)) {
            $entry = DictionaryEntry::fromArray(array_merge(['ref' => $ref], $entry));
        }
        $this->byVersion[$version][$ref] = $entry;

        return $this;
    }

    public function has(string $ref, int $dictionaryVersion): bool
    {
        return isset($this->byVersion[$dictionaryVersion][$ref]);
    }

    public function get(string $ref, int $dictionaryVersion): ?DictionaryEntry
    {
        return $this->byVersion[$dictionaryVersion][$ref] ?? null;
    }

    /**
     * Convenience: build a resolver at version $version from a flat [ref => spec] map.
     *
     * @param array<string,DictionaryEntry|array<string,mixed>> $entries
     */
    public static function atVersion(int $version, array $entries): self
    {
        return new self([$version => $entries]);
    }
}
