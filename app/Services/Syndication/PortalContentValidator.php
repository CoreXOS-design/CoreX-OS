<?php

namespace App\Services\Syndication;

use App\Models\Property;

/**
 * Portal content rules — the checks the portals themselves run, applied BEFORE
 * we send (AT-221). Each rule is a small, declarative entry so the set is
 * extensible per portal and grows straight from real portal rejection reasons
 * (harvested from p24_syndication_logs), never hardcoded to one rule.
 *
 * SCOPE: content rules only — things in the agent's copy they can fix in
 * seconds (e.g. a phone number in the description). Portal LIFECYCLE reasons
 * (expiry passed, listing deactivated/blocked on the portal side) are NOT
 * content and are surfaced honestly at sync time instead (Layer 3), not
 * pre-validated here.
 *
 * Used by:
 *   - Layer 1 (capture): PropertyController validates on save.
 *   - Layer 2 (sync):     Property24SyndicationService validates before submit.
 */
class PortalContentValidator
{
    public const P24 = 'property24';
    public const PP  = 'private_property';

    public const PORTAL_LABELS = [
        self::P24 => 'Property24',
        self::PP  => 'Private Property',
    ];

    /**
     * The rule set. Each rule:
     *   portal   — which portal enforces it
     *   key      — stable identifier
     *   field    — the Property attribute the rule inspects
     *   pattern  — PCRE; a match is a violation
     *   message  — agent-facing; {portal} and {match} are interpolated
     *
     * Both P24 and Private Property forbid contact details in the free-text
     * description; the phone rule is confirmed live for P24 (the 2026-07-10
     * incident) and applied to PP by the same policy.
     *
     * @return array<int,array{portal:string,key:string,field:string,pattern:string,message:string}>
     */
    protected function rules(): array
    {
        // A South African phone number in free text: starts with +27 or 0, then a
        // dialling code and 7-8 more digits with optional separators. Prices/erf
        // sizes/years don't start with +27/0 so they aren't matched.
        $saPhone = '/(?:\+?27|0)[\s.\-]?\d{2}[\s.\-]?\d{3}[\s.\-]?\d{3,4}/';

        return [
            [
                'portal'  => self::P24,
                'key'     => 'phone_in_description',
                'field'   => 'description',
                'pattern' => $saPhone,
                'message' => '{portal} does not allow phone numbers in listing descriptions — please remove {match}.',
            ],
            [
                'portal'  => self::PP,
                'key'     => 'phone_in_description',
                'field'   => 'description',
                'pattern' => $saPhone,
                'message' => '{portal} does not allow phone numbers in listing descriptions — please remove {match}.',
            ],
        ];
    }

    /**
     * Content violations for a property against ONE portal.
     *
     * @return array<int,array{key:string,portal:string,message:string}>
     */
    public function violationsFor(Property $property, string $portal): array
    {
        $out = [];
        foreach ($this->rules() as $rule) {
            if ($rule['portal'] !== $portal) {
                continue;
            }
            $value = (string) ($property->{$rule['field']} ?? '');
            if ($value === '') {
                continue;
            }
            if (preg_match($rule['pattern'], $value, $m)) {
                $out[] = [
                    'key'      => $rule['key'],
                    'portal'   => $portal,
                    'match'    => trim($m[0]),
                    'template' => $rule['message'],
                    'message'  => strtr($rule['message'], [
                        '{portal}' => self::PORTAL_LABELS[$portal] ?? $portal,
                        '{match}'  => trim($m[0]),
                    ]),
                ];
            }
        }
        return $out;
    }

    /**
     * Capture-time violations (Layer 1): across every portal the property is (or
     * will be) syndicated to. De-duplicated by rule key so the agent sees one
     * clear message per problem rather than one per portal.
     *
     * @return array<int,string> agent-facing messages
     */
    public function captureViolations(Property $property): array
    {
        // Group by rule key so the agent gets ONE message per problem, naming
        // every portal that enforces it (e.g. "Property24 and Private Property").
        $byKey = [];
        foreach ($this->portalsFor($property) as $portal) {
            foreach ($this->violationsFor($property, $portal) as $v) {
                $byKey[$v['key']] ??= ['template' => $v['template'], 'match' => $v['match'], 'portals' => []];
                $byKey[$v['key']]['portals'][] = self::PORTAL_LABELS[$portal] ?? $portal;
            }
        }

        $messages = [];
        foreach ($byKey as $g) {
            $messages[] = strtr($g['template'], [
                '{portal}' => $this->joinNames($g['portals']),
                '{match}'  => $g['match'],
            ]);
        }
        return $messages;
    }

    /** "A", "A and B", "A, B and C". */
    private function joinNames(array $names): string
    {
        $names = array_values(array_unique($names));
        if (count($names) <= 1) {
            return $names[0] ?? '';
        }
        $last = array_pop($names);
        return implode(', ', $names) . ' and ' . $last;
    }

    /** Portals a property syndicates to (both are on by default for HFC stock). */
    protected function portalsFor(Property $property): array
    {
        return [self::P24, self::PP];
    }
}
