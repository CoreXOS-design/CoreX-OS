<?php

declare(strict_types=1);

namespace App\View\Components;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Request;
use Illuminate\View\Component;

/**
 * <x-period-selector> — the ONE month picker for screens that show a single
 * calendar month (agency-tracker-rehome.md §4.2.1, §14 step 1).
 *
 * It is a plain GET form: the chosen month goes back to the page as `?period=YYYY-MM`
 * and every other query-string value on the page is carried along unchanged.
 * Current month is the default whenever `?period=` is missing or not a real month,
 * so a hand-edited URL can never reach the page as garbage.
 *
 * Two shapes:
 *  - no `months`  → a native month input (any month).
 *  - `months` set → a dropdown of just those months (the "history": months that have
 *    figures), newest first, with the current month always offered.
 *
 * Different from performance/agency-report/_period-selector (presets + custom date
 * ranges for the Agency Performance report family): that one is for ranges, this one
 * is for "which month".
 */
class PeriodSelector extends Component
{
    public string $value;
    public string $current;
    public string $previousUrl;
    public ?string $nextUrl;
    /** @var array<int, string> YYYY-MM, newest first; empty = free month input */
    public array $months;
    /** @var array<string, string> flattened hidden inputs for the other query values */
    public array $carry;

    /**
     * @param array<int, string>|null $months  months that have figures (YYYY-MM)
     */
    public function __construct(
        public string $name = 'period',
        public ?string $action = null,
        public string $label = 'Month',
        ?string $selected = null,
        ?array $months = null,
    ) {
        $this->current = CarbonImmutable::now()->format('Y-m');
        $this->value = self::monthFrom($selected ?? Request::query($name));

        $this->months = [];
        if ($months !== null) {
            $valid = array_filter($months, static fn ($m) => self::isMonth($m));
            $valid[] = $this->current;
            $valid[] = $this->value;
            $valid = array_unique($valid);
            rsort($valid);
            $this->months = array_values($valid);
        }

        $this->carry = self::flatten(
            collect(Request::query())->except([$name, 'page'])->all()
        );

        $month = CarbonImmutable::createFromFormat('!Y-m', $this->value);
        $this->previousUrl = $this->urlFor($month->subMonthNoOverflow()->format('Y-m'));
        $this->nextUrl = $this->value < $this->current
            ? $this->urlFor($month->addMonthNoOverflow()->format('Y-m'))
            : null;
    }

    /** A real YYYY-MM, else the current month. Public so controllers resolve `?period=` the same way. */
    public static function monthFrom(mixed $raw): string
    {
        return self::isMonth($raw) ? $raw : CarbonImmutable::now()->format('Y-m');
    }

    public static function isMonth(mixed $raw): bool
    {
        return is_string($raw) && (bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $raw);
    }

    public function monthLabel(string $month): string
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month)->format('F Y');
    }

    private function urlFor(string $month): string
    {
        $query = array_merge(
            collect(Request::query())->except(['page'])->all(),
            [$this->name => $month]
        );
        $base = $this->action ?? Request::url();

        return $base . '?' . http_build_query($query);
    }

    /**
     * @param array<string, mixed> $values
     * @return array<string, string>
     */
    private static function flatten(array $values, string $prefix = ''): array
    {
        $out = [];
        foreach ($values as $key => $val) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';
            if (is_array($val)) {
                $out += self::flatten($val, $name);
            } elseif (is_scalar($val)) {
                $out[$name] = (string) $val;
            }
        }

        return $out;
    }

    public function render(): View
    {
        return view('components.period-selector');
    }
}
