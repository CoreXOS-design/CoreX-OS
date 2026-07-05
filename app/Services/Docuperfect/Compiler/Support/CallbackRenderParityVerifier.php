<?php

namespace App\Services\Docuperfect\Compiler\Support;

use App\Services\Docuperfect\Compiler\Contracts\RenderParityVerifier;
use App\Services\Docuperfect\Compiler\Contracts\RenderParityResult;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * A test/dev {@see RenderParityVerifier} whose verdict is driven by a callback. Lets the
 * golden fixtures exercise L6 fully (both the all-parity-passes and the mismatch paths)
 * before the real WS2 renderer + Puppeteer engine exist. NOT a production verifier —
 * WS2 (cc1) ships that.
 *
 * @see \App\Services\Docuperfect\Compiler\Contracts\RenderParityVerifier
 */
final class CallbackRenderParityVerifier implements RenderParityVerifier
{
    /** @var callable(array<string,mixed>, string[]): RenderParityResult */
    private $callback;

    /** @param callable(array<string,mixed>, string[]): RenderParityResult $callback */
    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    /**
     * A verifier that always reports parity (every combination matches).
     */
    public static function alwaysMatches(): self
    {
        return new self(static fn (array $structure, array $keys): RenderParityResult => new RenderParityResult(
            matched: true,
            webHash: 'web-' . substr(hash('sha256', json_encode([$structure, $keys])), 0, 16),
            pdfHash: 'pdf-' . substr(hash('sha256', json_encode([$structure, $keys])), 0, 16),
        ));
    }

    /**
     * A verifier that reports a mismatch for a specific active-party combination (by the
     * set of keys), matching everything else. Useful to prove L6 fails block-addressed.
     *
     * @param string[] $mismatchOnKeys
     */
    public static function mismatchOn(array $mismatchOnKeys, string $reason = 'anchor placement differs'): self
    {
        sort($mismatchOnKeys);

        return new self(static function (array $structure, array $keys) use ($mismatchOnKeys, $reason): RenderParityResult {
            $sorted = $keys;
            sort($sorted);
            $matched = $sorted !== $mismatchOnKeys;

            return new RenderParityResult(
                matched: $matched,
                webHash: 'web-hash',
                pdfHash: 'pdf-hash',
                differences: $matched ? [] : [$reason],
            );
        });
    }

    public function verify(array $structure, array $activePartyKeys): RenderParityResult
    {
        return ($this->callback)($structure, $activePartyKeys);
    }
}
