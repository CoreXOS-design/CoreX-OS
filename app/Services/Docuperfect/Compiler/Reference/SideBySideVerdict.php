<?php

namespace App\Services\Docuperfect\Compiler\Reference;

/**
 * E-Sign Document Compiler — WS5 (reference proofs).
 *
 * The side-by-side truth test for one reference template (§8.2): does the COMPILED render
 * reproduce the document's essential legal content and signature topology, and what
 * legitimate differences exist between the compiled artifact and the legacy runtime?
 * Immutable.
 *
 *  - $declaredSigners / $renderedSigners : the party role-bases the CDS declares vs the ones
 *    that actually got a signing surface in the compiled render (must match — no signer
 *    dropped, none invented).
 *  - $coveredPhrases / $missingPhrases   : the essential legal phrases found / absent in the
 *    compiled render body (content-drop detector).
 *  - $notedDifferences                   : legitimate differences from the legacy runtime,
 *    named (per the RoleBlockExpansionService precedent — a legacy bug the compiler fixes is
 *    documented as such, not treated as a compiler defect).
 */
final class SideBySideVerdict
{
    /**
     * @param string[] $declaredSigners
     * @param string[] $renderedSigners
     * @param string[] $coveredPhrases
     * @param string[] $missingPhrases
     * @param string[] $notedDifferences
     */
    public function __construct(
        public readonly array $declaredSigners,
        public readonly array $renderedSigners,
        public readonly array $coveredPhrases,
        public readonly array $missingPhrases,
        public readonly array $notedDifferences,
    ) {
    }

    public function signersMatch(): bool
    {
        $a = $this->declaredSigners;
        $b = $this->renderedSigners;
        sort($a);
        sort($b);

        return $a === $b;
    }

    public function contentReproduced(): bool
    {
        return $this->missingPhrases === [];
    }

    /** The side-by-side passes: topology matches and no essential content was dropped. */
    public function passed(): bool
    {
        return $this->signersMatch() && $this->contentReproduced();
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'signers_match' => $this->signersMatch(),
            'declared_signers' => $this->declaredSigners,
            'rendered_signers' => $this->renderedSigners,
            'content_reproduced' => $this->contentReproduced(),
            'covered_phrases' => $this->coveredPhrases,
            'missing_phrases' => $this->missingPhrases,
            'noted_differences' => $this->notedDifferences,
        ];
    }
}
