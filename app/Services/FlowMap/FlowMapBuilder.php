<?php

declare(strict_types=1);

namespace App\Services\FlowMap;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Builds the Flow Map view model:
 *   curated backbone (config/flow-map.php)
 *   + live domain-event catalogue (reflected from app/Events/**)
 *   filtered by what the given user is actually allowed to see.
 *
 * Read-only. Owns no entity, emits no events, touches no pillar data.
 * Spec: .ai/specs/flows-map.md
 */
class FlowMapBuilder
{
    /**
     * @return array{categories:array,nodes:array}
     */
    public function build(User $user): array
    {
        $config = config('flow-map');
        $catalogue = $this->eventCatalogue();

        $visible = [];
        foreach ($config['nodes'] as $node) {
            if (! $this->userCanSee($user, $node)) {
                continue;
            }
            $visible[$node['key']] = $this->decorate($node, $catalogue);
        }

        // Drop edges that point at a node the user cannot see, so the guide
        // stays coherent (no links into nowhere).
        foreach ($visible as $key => $node) {
            $visible[$key]['next'] = array_values(array_filter(
                $node['next'] ?? [],
                fn ($target) => isset($visible[$target])
            ));
        }

        // Keep only categories that still have at least one visible node.
        $usedCategories = collect($visible)->pluck('category')->unique()->all();
        $categories = array_values(array_filter(
            $config['categories'],
            fn ($c) => in_array($c['key'], $usedCategories, true)
        ));

        return [
            'categories' => $categories,
            'nodes'      => array_values($visible),
        ];
    }

    /**
     * A node is visible when:
     *  - its declared permission (if any) is held by the user, AND
     *  - if it has a route, that route is registered.
     * This mirrors exactly how the sidebar gates links, so a user never
     * sees a node for a section they cannot open.
     */
    private function userCanSee(User $user, array $node): bool
    {
        if (! empty($node['permission']) && ! $user->hasPermission($node['permission'])) {
            return false;
        }

        if (! empty($node['route']) && ! Route::has($node['route'])) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a node's clickable URL + attach the live "Triggers" badge:
     * the curated event short-names that actually exist in app/Events,
     * each with its one-line docblock summary.
     */
    private function decorate(array $node, array $catalogue): array
    {
        $node['url'] = ! empty($node['route']) && Route::has($node['route'])
            ? route($node['route'])
            : null;

        $triggers = [];
        foreach ($node['emits'] ?? [] as $shortName) {
            if (isset($catalogue[$shortName])) {
                $triggers[] = [
                    'name'    => Str::headline($shortName),
                    'summary' => $catalogue[$shortName],
                ];
            }
        }
        $node['triggers'] = $triggers;

        return $node;
    }

    /**
     * Reflect app/Events/**\/*.php into [ShortClassName => summary].
     * Summary is the first sentence of the class docblock. Cached 1h —
     * adding an event class + cache:clear surfaces it with no code edit.
     * Degrades to an empty catalogue (no triggers badged) on any failure.
     */
    public function eventCatalogue(): array
    {
        return Cache::remember('flow_map.event_catalogue', 3600, function () {
            $base = app_path('Events');
            if (! is_dir($base)) {
                return [];
            }

            $catalogue = [];
            $rii = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS)
            );

            foreach ($rii as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $short = $file->getBasename('.php');
                // Skip base/contract/smoke scaffolding.
                if (in_array($short, ['AbstractDomainEvent'], true)
                    || str_starts_with($file->getPathname(), $base . DIRECTORY_SEPARATOR . 'Contracts')
                    || str_contains($file->getPathname(), DIRECTORY_SEPARATOR . '_Smoke' . DIRECTORY_SEPARATOR)) {
                    continue;
                }
                $catalogue[$short] = $this->firstDocblockSentence($file->getPathname());
            }

            ksort($catalogue);

            return $catalogue;
        });
    }

    private function firstDocblockSentence(string $path): string
    {
        $contents = @file_get_contents($path);
        if ($contents === false
            || ! preg_match('/\/\*\*(.*?)\*\//s', $contents, $m)) {
            return '';
        }

        $text = preg_replace('/^\s*\*\s?/m', '', trim($m[1]));
        $text = trim(preg_replace('/\s+/', ' ', $text));

        if ($text === '') {
            return '';
        }

        // First sentence only.
        $end = strpos($text, '. ');

        return $end === false ? $text : substr($text, 0, $end + 1);
    }
}
