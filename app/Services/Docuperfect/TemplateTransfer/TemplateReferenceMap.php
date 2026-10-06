<?php

declare(strict_types=1);

namespace App\Services\Docuperfect\TemplateTransfer;

/**
 * Rewrites the environment-specific database ids buried inside a template's
 * JSON (named-field ids, field-group ids, stray agency/user ids) to and from
 * package-local tokens ("@nf1", "@fg1"), so a package carries no ids of the
 * environment it came from.
 *
 * Where the ids live (verified against the committed template captures):
 *   field_mappings[tag].namedFieldId / .fieldGroupId / .typeKey ("fg:<id>")
 *   editor_state.mappings[tag] (the same shape — and the one the seeders forgot)
 * The walk is deliberately generic over EVERY JSON column so an id in a place
 * nobody listed still gets rewritten rather than silently shipped.
 *
 * Spec: .ai/specs/esign-template-transfer.md §3.
 */
final class TemplateReferenceMap
{
    public const NAMED_FIELD_KEYS = ['namedFieldId', 'named_field_id'];
    public const FIELD_GROUP_KEYS = ['fieldGroupId', 'field_group_id'];

    /** Keys whose numeric value is an environment identity that must never travel. */
    private const STRAY_ID_PATTERN = '/^(agency|user|owner|branch|template|document|contact|property|deal|document_type)_?id$|^(created|updated|uploaded)_?by$/i';

    /**
     * Export direction: ids -> tokens. $nf / $fg turn a source id into a token
     * (or null when the row no longer exists). Stray ids are nulled and their
     * JSON path recorded in $removed.
     *
     * @param callable(int):?string $nf
     * @param callable(int):?string $fg
     * @param string[] $removed
     */
    public static function toTokens(mixed $node, string $path, callable $nf, callable $fg, array &$removed): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $out = [];
        foreach ($node as $key => $value) {
            $here = $path . '/' . $key;

            if (in_array($key, self::NAMED_FIELD_KEYS, true) && self::isId($value)) {
                $token = $nf((int) $value);
                $out[$key] = $token === null ? null : '@' . $token;
            } elseif (in_array($key, self::FIELD_GROUP_KEYS, true) && self::isId($value)) {
                $token = $fg((int) $value);
                $out[$key] = $token === null ? null : '@' . $token;
            } elseif ($key === 'typeKey' && is_string($value) && preg_match('/^fg:(\d+)$/', $value, $m)) {
                $token = $fg((int) $m[1]);
                $out[$key] = $token === null ? null : 'fg:@' . $token;
            } elseif (is_string($key) && preg_match(self::STRAY_ID_PATTERN, $key) && self::isId($value)) {
                $removed[] = ltrim($here, '/');
                $out[$key] = null;
            } else {
                $out[$key] = self::toTokens($value, $here, $nf, $fg, $removed);
            }
        }

        return $out;
    }

    /**
     * Import direction: tokens -> target ids.
     *
     * @param array<string,int> $nfMap  "nf1" => target named-field id
     * @param array<string,int> $fgMap  "fg1" => target field-group id
     */
    public static function fromTokens(mixed $node, array $nfMap, array $fgMap): mixed
    {
        if (! is_array($node)) {
            return $node;
        }

        $out = [];
        foreach ($node as $key => $value) {
            if (in_array($key, self::NAMED_FIELD_KEYS, true) && is_string($value) && str_starts_with($value, '@')) {
                $out[$key] = $nfMap[substr($value, 1)] ?? null;
            } elseif (in_array($key, self::FIELD_GROUP_KEYS, true) && is_string($value) && str_starts_with($value, '@')) {
                $out[$key] = $fgMap[substr($value, 1)] ?? null;
            } elseif ($key === 'typeKey' && is_string($value) && preg_match('/^fg:@(fg\d+)$/', $value, $m)) {
                $out[$key] = isset($fgMap[$m[1]]) ? 'fg:' . $fgMap[$m[1]] : null;
            } else {
                $out[$key] = self::fromTokens($value, $nfMap, $fgMap);
            }
        }

        return $out;
    }

    /**
     * Every token referenced anywhere in $node: ['nf' => [...keys], 'fg' => [...keys]].
     *
     * @return array{nf: string[], fg: string[]}
     */
    public static function referencedTokens(mixed $node): array
    {
        $nf = [];
        $fg = [];
        $walk = function (mixed $n) use (&$walk, &$nf, &$fg): void {
            if (! is_array($n)) {
                return;
            }
            foreach ($n as $key => $value) {
                if (in_array($key, self::NAMED_FIELD_KEYS, true) && is_string($value) && str_starts_with($value, '@')) {
                    $nf[substr($value, 1)] = true;
                } elseif (in_array($key, self::FIELD_GROUP_KEYS, true) && is_string($value) && str_starts_with($value, '@')) {
                    $fg[substr($value, 1)] = true;
                } elseif ($key === 'typeKey' && is_string($value) && preg_match('/^fg:@(fg\d+)$/', $value, $m)) {
                    $fg[$m[1]] = true;
                } else {
                    $walk($value);
                }
            }
        };
        $walk($node);

        return ['nf' => array_keys($nf), 'fg' => array_keys($fg)];
    }

    /** A real numeric database id (not null, not a token, not a label). */
    private static function isId(mixed $value): bool
    {
        return (is_int($value) && $value > 0) || (is_string($value) && ctype_digit($value) && (int) $value > 0);
    }
}
