<?php

namespace App\Services\TrainingVideos\Auto;

use Illuminate\Support\Facades\File;

/** AT-525 - finds the screen scripts in resources/training/scripts (config training-videos.auto.scripts_dir). */
class TrainingScriptRepository
{
    /** @var array<string,TrainingScript>|null */
    private ?array $cache = null;

    public function __construct(private readonly ?string $dir = null)
    {
    }

    public function dir(): string
    {
        return $this->dir ?? (string) config('training-videos.auto.scripts_dir');
    }

    /** @return array<string,TrainingScript> keyed by screen key */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        if (is_dir($this->dir())) {
            foreach (File::glob($this->dir() . '/*.php') as $path) {
                $data = require $path;
                if (is_array($data) && ! empty($data['key'])) {
                    $out[(string) $data['key']] = new TrainingScript($data, $path);
                }
            }
        }
        ksort($out);

        return $this->cache = $out;
    }

    public function find(?string $key): ?TrainingScript
    {
        return $key ? ($this->all()[$key] ?? null) : null;
    }

    public function forRoute(?string $routeName): ?TrainingScript
    {
        if (! $routeName) {
            return null;
        }
        foreach ($this->all() as $s) {
            if ($s->route() === $routeName) {
                return $s;
            }
        }

        return null;
    }

    /** The script of a screen reached by an unnamed alias route (e.g. /corex/market-intelligence/work, which is the named "/" route's twin): matched on its path. */
    public function forPath(?string $path): ?TrainingScript
    {
        $path = '/' . trim((string) $path, '/');
        foreach ($this->all() as $s) {
            if ($s->url() !== '' && '/' . trim($s->url(), '/') === $path) {
                return $s;
            }
        }

        return null;
    }
}
