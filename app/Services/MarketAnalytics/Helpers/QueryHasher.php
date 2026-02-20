<?php

namespace App\Services\MarketAnalytics\Helpers;

class QueryHasher
{
    /**
     * Produce a stable SHA-256 digest for a SQL query + bindings pair.
     *
     * Normalisation steps:
     *   1. Collapse whitespace in SQL to single spaces, lowercase.
     *   2. ksort bindings so key order doesn't affect hash.
     *   3. sha256 of JSON-encoded [sql, bindings].
     */
    public static function hash(string $sql, array $bindings = []): string
    {
        $normalised = preg_replace('/\s+/', ' ', trim(mb_strtolower($sql)));

        ksort($bindings);

        $payload = json_encode(
            ['sql' => $normalised, 'bindings' => $bindings],
            JSON_THROW_ON_ERROR
        );

        return hash('sha256', $payload);
    }
}
