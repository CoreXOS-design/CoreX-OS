<?php

namespace App\Services\Docuperfect\Compiler\Linter;

use App\Services\Docuperfect\Compiler\Contracts\DataDictionaryResolver;
use App\Services\Docuperfect\Compiler\Contracts\RenderParityVerifier;
use App\Services\Docuperfect\Compiler\Linter\Rules\AllFieldsBoundRule;
use App\Services\Docuperfect\Compiler\Linter\Rules\AnchorsPerRoleRule;
use App\Services\Docuperfect\Compiler\Linter\Rules\ConditionalCombinationsResolveRule;
use App\Services\Docuperfect\Compiler\Linter\Rules\LegalModeCoherenceRule;
use App\Services\Docuperfect\Compiler\Linter\Rules\NoOrphanMappingsRule;
use App\Services\Docuperfect\Compiler\Linter\Rules\RenderParityRule;
use App\Services\Docuperfect\Compiler\Linter\Rules\ValidationCoherenceRule;
use App\Support\Docuperfect\Cds\Cds;
use Throwable;

/**
 * E-Sign Document Compiler — WS1 (Linter gate engine).
 *
 * The compile-time LINTER gate (§4). A CDS "cannot be published unless every rule passes."
 * This engine runs the ordered rule set (L1..L7) as PURE functions over the canonical WS0
 * {@see Cds} DTO and returns one auditable {@see LintReport}.
 *
 * Publish gate: `$linter->lint(...)->publishable()`.
 *
 * Framework-free and stateless. It reaches the Data Dictionary only through the
 * {@see DataDictionaryResolver} contract (WS0 supplies the Eloquent impl) and the PDF/web
 * render only through the optional {@see RenderParityVerifier} (WS2 supplies it) — so the
 * two sibling lanes integrate by supplying those contracts, without this engine changing.
 *
 * ROBUSTNESS (BUILD_STANDARD §3 — absorb, never break):
 *  - a raw structure array that cannot even hydrate into the canonical {@see Cds} DTO
 *    yields a single blocking `structure_malformed` finding, never a 500 at the gate;
 *  - a rule that throws on an odd-but-hydratable CDS is caught and converted into a
 *    blocking finding rather than crashing the whole lint.
 */
final class CompiledTemplateLinter
{
    /** @var LintRule[] */
    private array $rules;

    /** @param LintRule[]|null $rules override the default L1..L7 set (order preserved) */
    public function __construct(?array $rules = null)
    {
        $this->rules = $rules ?? self::defaultRules();
    }

    /**
     * The canonical L1..L7 rule set, in gate order.
     *
     * @return LintRule[]
     */
    public static function defaultRules(): array
    {
        return [
            new AllFieldsBoundRule(),                 // L1
            new NoOrphanMappingsRule(),               // L2
            new AnchorsPerRoleRule(),                 // L3
            new ConditionalCombinationsResolveRule(), // L4
            new ValidationCoherenceRule(),            // L5
            new RenderParityRule(),                   // L6
            new LegalModeCoherenceRule(),             // L7
        ];
    }

    /**
     * Run the full gate over a CDS.
     *
     * @param Cds|array<string,mixed>  $cds        the canonical CDS DTO, or the stored
     *                                             `compiled_templates.structure` JSON array
     * @param DataDictionaryResolver   $dictionary resolves bindings against the pinned version
     * @param RenderParityVerifier|null $parity     WS2 renderer (L6); null ⇒ L6 reports PENDING
     * @param LinterContext|null        $context    advanced options (instance cap)
     */
    public function lint(
        Cds|array $cds,
        DataDictionaryResolver $dictionary,
        ?RenderParityVerifier $parity = null,
        ?LinterContext $context = null,
    ): LintReport {
        if (is_array($cds)) {
            try {
                $cds = Cds::fromArray($cds);
            } catch (Throwable $e) {
                return new LintReport([LintFinding::error(
                    'L0',
                    '',
                    'structure_malformed',
                    sprintf('The structure could not be parsed into a compiled document (%s). It cannot be published.', $e->getMessage()),
                    ['exception' => $e::class],
                )]);
            }
        }

        $ctx = $this->resolveContext($context, $parity);

        $findings = [];
        foreach ($this->rules as $rule) {
            try {
                $findings = array_merge($findings, $rule->evaluate($cds, $dictionary, $ctx));
            } catch (Throwable $e) {
                $findings[] = LintFinding::error(
                    $rule->code(),
                    '',
                    'rule_threw',
                    sprintf('Rule %s failed to evaluate the structure (%s). The structure is malformed and cannot be published.', $rule->code(), $e->getMessage()),
                    ['exception' => $e::class],
                );
            }
        }

        return new LintReport($findings);
    }

    /**
     * If both an explicit context (carrying its own verifier) and a positional $parity are
     * supplied, the positional $parity wins the common call shape `lint($cds,$dict,$verifier)`.
     */
    private function resolveContext(?LinterContext $context, ?RenderParityVerifier $parity): LinterContext
    {
        if ($context === null) {
            return new LinterContext(renderParityVerifier: $parity);
        }
        if ($parity !== null && $context->renderParityVerifier === null) {
            return new LinterContext(renderParityVerifier: $parity, maxInstancesPerParty: $context->maxInstancesPerParty);
        }

        return $context;
    }
}
