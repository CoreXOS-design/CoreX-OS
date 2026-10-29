<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * Keeps a model's DERIVED canonical advert reference(s) in step with the raw advert number it holds
 * (.ai/specs/property-identity.md §4). The raw column is never touched; the canonical column is derived
 * data, recomputed on every Eloquent save. A normaliser failure is logged and absorbed — it never blocks a save.
 *
 * The model implements canonicalRefValues(): array<string, ?string> — [canonical column => canonical value].
 * (Writers that use saveQuietly / the query builder skip this; `property-refs:normalise --apply` is the catch-all.)
 */
trait HasCanonicalPortalRef
{
    public static function bootHasCanonicalPortalRef(): void
    {
        static::saving(function ($model): void {
            try {
                foreach ($model->canonicalRefValues() as $column => $value) {
                    if ($model->getAttribute($column) !== $value) {
                        $model->setAttribute($column, $value);
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('canonical portal ref skipped', ['model' => static::class, 'id' => $model->getKey(), 'error' => $e->getMessage()]);
            }
        });
    }

    /** @return array<string, ?string> */
    abstract public function canonicalRefValues(): array;
}
