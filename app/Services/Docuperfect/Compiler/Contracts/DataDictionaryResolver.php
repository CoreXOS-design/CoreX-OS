<?php

namespace App\Services\Docuperfect\Compiler\Contracts;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * The linter resolves every field binding against a *pinned* Data Dictionary version
 * (§2.1 / §12-decision-1: the dictionary is versioned so a later change can never
 * silently alter a published template's meaning). This interface is the seam between
 * the pure linter (WS1) and the Data Dictionary store (WS0).
 *
 * INTEGRATION (AT-177): consumer-owned interface (dependency inversion). WS0 (cc2)
 * ships the Eloquent-backed implementation over the versioned dictionary tables +
 * CoreX-standard SA seed; WS1 (cc3) ships {@see \App\Services\Docuperfect\Compiler\Support\InMemoryDataDictionaryResolver}
 * for golden fixtures and dev. The linter never touches Eloquent directly — it only
 * ever calls this contract.
 */
interface DataDictionaryResolver
{
    /**
     * Does entry $ref exist in dictionary version $dictionaryVersion?
     */
    public function has(string $ref, int $dictionaryVersion): bool;

    /**
     * Resolve entry $ref at $dictionaryVersion, or null if it does not exist.
     */
    public function get(string $ref, int $dictionaryVersion): ?DictionaryEntry;
}
