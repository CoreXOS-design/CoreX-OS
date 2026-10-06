<?php

namespace App\Services\PlatformEsign\Agreement;

/**
 * Field markers inside the agreement wording (spec §11.4, §11.13). The editor may rewrite the words around a marker,
 * but a marker that drives the form (an input, tick, quantity, amount, signature, initials) can never be removed,
 * duplicated or invented from the wording screen — that would break the form or the fee table.
 */
class AgreementTokens
{
    public const PATTERN = '/\{\{(f|o|q|rate|amt|rr|sig|ini|ref|auto|ctl|co)(?::([a-z0-9_]+))?(?::([a-z0-9_]+))?\}\}/';

    /** Kinds whose count must stay exactly as in the version the draft was copied from. */
    public const GUARDED = ['f', 'o', 'q', 'amt', 'rr', 'sig', 'ini', 'ctl'];

    private const QUANTITY_LINES = ['team_seats', 'agency_t1', 'agency_t2', 'agency_t3', 'branches'];
    private const AMOUNT_LINES = ['team_seats', 'agency_base', 'agency_t1', 'agency_t2', 'agency_t3', 'branches', 'total'];

    /** @return string[] canonical tokens in order, e.g. "f:reg_no", "o:plan:team", "ref" */
    public static function extract(string $md): array
    {
        preg_match_all(self::PATTERN, $md, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => implode(':', array_filter([$x[1], $x[2] ?? '', $x[3] ?? ''], fn ($p) => $p !== '')), $m);
    }

    /**
     * Is every marker in this text well-formed and known?
     *
     * @param array<string,mixed> $rates keys of the draft's rate table (a {{rate:key}} must name one)
     * @return string[] human error messages
     */
    public static function validate(string $md, array $rates, string $where): array
    {
        $errors = [];
        $rest = preg_replace(self::PATTERN, '', $md);
        if (str_contains($rest, '{{') || str_contains($rest, '}}')) {
            $errors[] = $where . ': a field marker is damaged (a "{{" or "}}" is left over). Field markers look like {{f:reg_no}} and must not be edited — put the cursor beside them instead.';
        }
        if (preg_match('/<\s*\/?\s*(script|iframe|object|embed|style|link|meta|form|base|svg|math)\b|<[^>]*\son[a-z]+\s*=|javascript\s*:/i', $md)) {
            $errors[] = $where . ': scripts, forms and embedded content cannot be used in the agreement wording.';
        }
        $schema = AgreementFields::schema();
        foreach (array_unique(self::extract($md)) as $t) {
            $p = explode(':', $t);
            $ok = match ($p[0]) {
                'f' => isset($p[1]) && ($schema[$p[1]]['side'] ?? null) === 'r' && !in_array($p[1], ['sigA', 'sigM'], true),
                'rr' => isset($p[1]) && ($schema[$p[1]]['side'] ?? null) === 'rr' && $p[1] !== 'sigR',
                'o' => isset($p[1], $p[2]) && isset($schema[$p[1]]['options'][$p[2]]),
                'q' => isset($p[1]) && in_array($p[1], self::QUANTITY_LINES, true),
                'amt' => isset($p[1]) && in_array($p[1], self::AMOUNT_LINES, true),
                'rate' => isset($p[1]) && array_key_exists($p[1], array_merge(AgreementPricing::DEFAULT_RATES, $rates)) && !str_ends_with($p[1], '_max') && $p[1] !== 'quote_above_agents',
                'sig' => isset($p[1]) && in_array($p[1], ['agency', 'mandate', 'rr'], true),
                'ini' => isset($p[1]) && in_array($p[1], ['agency', 'rr'], true),
                'auto' => isset($p[1]) && in_array($p[1], ['day', 'monthyear'], true),
                'ctl' => ($p[1] ?? '') === 'agents',
                'co' => isset($p[1]) && in_array($p[1], ['address', 'name'], true),
                'ref' => count($p) === 1,
                default => false,
            };
            if (!$ok) {
                $errors[] = $where . ': the field marker {{' . $t . '}} is not one the agreement knows.';
            }
        }

        return $errors;
    }

    /**
     * Compare the guarded markers of an edited part with the part it was copied from.
     *
     * @return string[] human error messages
     */
    public static function compare(string $baselineMd, string $draftMd, string $where): array
    {
        $count = function (string $md): array {
            $c = [];
            foreach (self::extract($md) as $t) {
                if (in_array(explode(':', $t)[0], self::GUARDED, true)) {
                    $c[$t] = ($c[$t] ?? 0) + 1;
                }
            }

            return $c;
        };
        $a = $count($baselineMd);
        $b = $count($draftMd);
        $errors = [];
        foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $t) {
            $had = $a[$t] ?? 0;
            $has = $b[$t] ?? 0;
            if ($had === $has) {
                continue;
            }
            $name = self::describe($t);
            $errors[] = $where . ': ' . ($has < $had
                ? ($has === 0 ? 'the field ' . $name . ' was removed' : 'the field ' . $name . ' appears ' . $has . ' times instead of ' . $had)
                : ($had === 0 ? 'the field ' . $name . ' was added' : 'the field ' . $name . ' appears ' . $has . ' times instead of ' . $had))
                . '. Fields can be moved around in the wording but not removed, repeated or added here — that needs a developer.';
        }

        return $errors;
    }

    /** "Registered name" for f:registered_name, "tick: Plan — CoreX Team" for o:plan:team … */
    public static function describe(string $token): string
    {
        $p = explode(':', $token);
        $schema = AgreementFields::schema();

        return '“' . match ($p[0]) {
            'f', 'rr' => ($schema[$p[1] ?? '']['label'] ?? $token),
            'o' => ($schema[$p[1] ?? '']['label'] ?? $p[1]) . ' — ' . ($schema[$p[1] ?? '']['options'][$p[2] ?? ''] ?? $p[2] ?? ''),
            'q' => 'quantity ' . ($p[1] ?? ''),
            'amt' => 'amount ' . ($p[1] ?? ''),
            'sig' => 'signature (' . ($p[1] ?? '') . ')',
            'ini' => 'initials (' . ($p[1] ?? '') . ')',
            'ctl' => 'number-of-agents box',
            default => $token,
        } . '”';
    }
}
