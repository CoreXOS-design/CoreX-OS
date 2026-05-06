<?php

namespace App\Services\CommandCenter\Calendar;

use App\Contracts\CalendarSourceContract;
use Illuminate\Support\Collection;

/**
 * Holds registered calendar source services. Phase 1 sources register
 * themselves here via AppServiceProvider boot().
 */
class CalendarSourceRegistry
{
    /** @var array<class-string<CalendarSourceContract>> */
    private array $sources = [];

    public function register(string $sourceClass): void
    {
        if (!is_subclass_of($sourceClass, CalendarSourceContract::class)) {
            throw new \InvalidArgumentException(
                $sourceClass . ' must implement CalendarSourceContract'
            );
        }
        if (!in_array($sourceClass, $this->sources, true)) {
            $this->sources[] = $sourceClass;
        }
    }

    /** @return Collection<int, CalendarSourceContract> */
    public function all(): Collection
    {
        return collect($this->sources)->map(fn ($cls) => app($cls));
    }

    public function count(): int
    {
        return count($this->sources);
    }
}
