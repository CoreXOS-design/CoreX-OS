<?php

namespace App\Services\AdStudio;

use Illuminate\Support\Facades\File;

/** AT-527 - finds the ad scripts in resources/training/ads (config ad-studio.scripts_dir). */
class AdScriptRepository
{
    /** @var array<string,AdScript>|null */
    private ?array $cache = null;

    public function __construct(private readonly ?string $dir = null)
    {
    }

    public function dir(): string
    {
        return $this->dir ?? (string) config('ad-studio.scripts_dir');
    }

    /** @return array<string,AdScript> keyed by feature key */
    public function all(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }
        $out = [];
        if (is_dir($this->dir())) {
            foreach (File::glob($this->dir() . '/*.php') as $path) {
                $data = require $path;
                if (is_array($data) && ($data['type'] ?? null) === 'ad' && ! empty($data['key'])) {
                    $out[(string) $data['key']] = new AdScript($data, $path);
                }
            }
        }
        ksort($out);

        return $this->cache = $out;
    }

    public function find(?string $key): ?AdScript
    {
        return $key ? ($this->all()[$key] ?? null) : null;
    }
}
