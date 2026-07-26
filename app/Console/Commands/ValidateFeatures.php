<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Validate the feature registry config is well-formed.
 *
 * Spec: corex-feature-registry.md §6.5. Run in the focused test and (optionally)
 * on deploy. Asserts: required keys present, every depends_on parent exists, no
 * dependency cycles, no duplicate keys, defaults/core are booleans.
 */
class ValidateFeatures extends Command
{
    protected $signature = 'corex:features:validate';

    protected $description = 'Validate config/corex-features.php (shape, dependencies, no cycles)';

    /** Required keys on every registry entry. */
    private const REQUIRED = [
        'label', 'category', 'explain', 'affects', 'default', 'core',
        'depends_on', 'nav_permission', 'settings_section', 'route_prefixes',
    ];

    public function handle(): int
    {
        $registry = config('corex-features', []);
        $errors = [];

        if (empty($registry)) {
            $this->error('config/corex-features.php is empty or missing.');
            return self::FAILURE;
        }

        foreach ($registry as $key => $def) {
            if (!is_string($key) || $key === '') {
                $errors[] = "Invalid feature key: " . var_export($key, true);
                continue;
            }
            if (!is_array($def)) {
                $errors[] = "[$key] entry is not an array.";
                continue;
            }
            foreach (self::REQUIRED as $req) {
                if (!array_key_exists($req, $def)) {
                    $errors[] = "[$key] missing required '$req'.";
                }
            }
            if (array_key_exists('default', $def) && !is_bool($def['default'])) {
                $errors[] = "[$key] 'default' must be a boolean.";
            }
            if (array_key_exists('core', $def) && !is_bool($def['core'])) {
                $errors[] = "[$key] 'core' must be a boolean.";
            }
            if (!empty($def['affects']) && !empty($def['label'])
                && strtolower(trim($def['affects'])) === strtolower('Whether ' . $def['label'] . ' is enabled.')) {
                $errors[] = "[$key] 'affects' is a tautology — describe a concrete, observable consequence.";
            }
            foreach (($def['depends_on'] ?? []) as $parent) {
                if (!array_key_exists($parent, $registry)) {
                    $errors[] = "[$key] depends_on unknown feature '$parent'.";
                }
            }
        }

        // Cycle detection (DFS).
        $cycle = $this->findCycle($registry);
        if ($cycle !== null) {
            $errors[] = 'Dependency cycle: ' . implode(' -> ', $cycle);
        }

        if ($errors) {
            foreach ($errors as $e) {
                $this->error($e);
            }
            $this->error(count($errors) . ' problem(s) found in the feature registry.');
            return self::FAILURE;
        }

        $this->info('Feature registry OK — ' . count($registry) . ' features, no cycles.');
        return self::SUCCESS;
    }

    /** @return list<string>|null the cycle path, or null if acyclic */
    private function findCycle(array $registry): ?array
    {
        $state = []; // key => 0 unvisited, 1 in-stack, 2 done

        $visit = function (string $key, array $path) use (&$visit, &$state, $registry): ?array {
            if (($state[$key] ?? 0) === 1) {
                return array_merge($path, [$key]); // back-edge → cycle
            }
            if (($state[$key] ?? 0) === 2) {
                return null;
            }
            $state[$key] = 1;
            foreach (($registry[$key]['depends_on'] ?? []) as $parent) {
                if (!array_key_exists($parent, $registry)) {
                    continue; // reported separately
                }
                $found = $visit($parent, array_merge($path, [$key]));
                if ($found !== null) {
                    return $found;
                }
            }
            $state[$key] = 2;
            return null;
        };

        foreach (array_keys($registry) as $key) {
            $found = $visit($key, []);
            if ($found !== null) {
                return $found;
            }
        }
        return null;
    }
}
