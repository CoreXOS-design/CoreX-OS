<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A DATETIME column that holds UTC wall-clock time, whatever the app timezone is.
 *
 * Eloquent's stock `datetime` cast reads a stored string in the APP timezone (Africa/Johannesburg
 * here, UTC+2) and writes the Carbon's own wall-clock — so a value written as UTC would be read two
 * hours late. This cast reads and writes the column as UTC explicitly and hands back a Carbon in UTC;
 * callers convert for display with ->setTimezone(...). Used by property_marketing_posts.scheduled_for
 * (AT-456 Part D, "stored UTC, shown in the agency timezone").
 */
final class UtcDateTime implements CastsAttributes
{
    private const FORMAT = 'Y-m-d H:i:s';

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::createFromFormat(self::FORMAT, substr((string) $value, 0, 19), 'UTC');
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof CarbonInterface) {
            return $value->copy()->utc()->format(self::FORMAT);
        }

        // A bare string is taken to already be UTC.
        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->format(self::FORMAT);
    }
}
