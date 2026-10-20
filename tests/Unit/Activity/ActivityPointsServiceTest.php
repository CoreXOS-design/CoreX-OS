<?php

declare(strict_types=1);

namespace Tests\Unit\Activity;

use App\Services\Activity\ActivityPointsService;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * agency-tracker-rehome.md §5.6 / step 1 — the one statement of the points rule:
 * `value` is a COUNT, points = count × the agency's weight. Nothing else multiplies in.
 */
final class ActivityPointsServiceTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int|float|string, 2: float}> */
    public static function cases(): array
    {
        return [
            'one event, weight 200 (calendar presentation)' => [1, 200, 200.0],
            'sixteen events, weight 50'                      => [16, 50, 800.0],
            'five events, weight 200'                        => [5, 200, 1000.0],
            'zero events'                                    => [0, 500, 0.0],
            'weight arrives as the decimal(10,2) string'     => [3, '12.50', 37.5],
            'weight zero'                                    => [4, 0, 0.0],
            'negative count is absorbed as zero'             => [-2, 50, 0.0],
            'non-numeric weight is absorbed as zero'         => [3, 'n/a', 0.0],
        ];
    }

    #[DataProvider('cases')]
    public function test_points_are_count_times_weight(int $count, int|float|string $weight, float $expected): void
    {
        $this->assertSame($expected, ActivityPointsService::pointsFor($count, $weight));
    }

    public function test_a_calendar_event_is_one_count_not_the_weight_again(): void
    {
        // The verified defect: value_per_event was seeded WITH the weight (200), so one
        // "Presentation" scored 200 × 200 = 40,000. As a count it is 1 × 200.
        $this->assertSame(200.0, ActivityPointsService::pointsFor(1, 200));
        $this->assertNotSame(40000.0, ActivityPointsService::pointsFor(1, 200));
    }

    public function test_sql_fragment_states_the_same_rule(): void
    {
        $this->assertSame('(GREATEST(e.`value`, 0) * d.`weight`)', ActivityPointsService::sqlPoints('e', 'd'));
        $this->assertSame(
            '(GREATEST(dae.`value`, 0) * ad.`weight`)',
            ActivityPointsService::sqlPoints('dae', 'ad')
        );
    }

    #[DataProvider('badAliases')]
    public function test_sql_fragment_refuses_anything_that_is_not_a_plain_alias(string $entry, string $def): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActivityPointsService::sqlPoints($entry, $def);
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function badAliases(): array
    {
        return [
            'injection in entry alias'  => ['e; DROP TABLE users', 'd'],
            'empty alias'               => ['', 'd'],
            'dot in definition alias'   => ['e', 'd.weight'],
            'leading digit'             => ['1e', 'd'],
        ];
    }
}
