<?php

declare(strict_types=1);

namespace App\Models\MarketReports;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * AI-read counters. Spec: .ai/specs/mic-complete-spec.md §8.9 ("AI cost protection").
 *
 *  - per agency, per calendar MONTH (period 'YYYY-MM', Africa/Johannesburg)
 *  - platform-wide, per calendar DAY (agency_id 0, period 'YYYY-MM-DD')
 *
 * Pure counters, deliberately not BelongsToAgency: the check runs inside a queue job that
 * is not tied to a logged-in agency, and the rows hold no agency content.
 */
class MarketReportAiUsage extends Model
{
    public const PLATFORM = 0;

    protected $table = 'market_report_ai_usage';

    protected $fillable = ['agency_id', 'period_key', 'reads_count', 'cap_alert_sent_at', 'last_reset_at'];

    protected $casts = [
        'reads_count'       => 'integer',
        'cap_alert_sent_at' => 'datetime',
        'last_reset_at'     => 'datetime',
    ];

    public static function monthKey(): string
    {
        return now()->format('Y-m');
    }

    public static function dayKey(): string
    {
        return now()->toDateString();
    }

    public static function usedThisMonth(int $agencyId): int
    {
        return (int) self::query()->where('agency_id', $agencyId)->where('period_key', self::monthKey())->value('reads_count');
    }

    public static function usedToday(): int
    {
        return (int) self::query()->where('agency_id', self::PLATFORM)->where('period_key', self::dayKey())->value('reads_count');
    }

    /** Take one of the agency's slots for this month, atomically (row lock), or refuse. */
    public static function reserveAgencyMonth(int $agencyId, int $cap): bool
    {
        return self::reserve($agencyId, self::monthKey(), $cap);
    }

    /** Take one of the platform's slots for today, atomically, or refuse. */
    public static function reservePlatformDay(int $cap): bool
    {
        return self::reserve(self::PLATFORM, self::dayKey(), $cap);
    }

    /** Give both slots back (the AI was never actually called). */
    public static function releaseBoth(int $agencyId): void
    {
        self::release($agencyId, self::monthKey());
        self::release(self::PLATFORM, self::dayKey());
    }

    public static function releaseAgencyMonth(int $agencyId): void
    {
        self::release($agencyId, self::monthKey());
    }

    /** A CoreX layout approval gives the agency its reads back for this month. */
    public static function resetAgencyMonth(int $agencyId): void
    {
        self::query()
            ->where('agency_id', $agencyId)
            ->where('period_key', self::monthKey())
            ->update(['reads_count' => 0, 'last_reset_at' => now(), 'updated_at' => now()]);
    }

    /** True exactly once per day: the caller sends the single "AI read cap hit" alert. */
    public static function claimPlatformCapAlert(): bool
    {
        DB::table('market_report_ai_usage')->insertOrIgnore([
            'agency_id' => self::PLATFORM, 'period_key' => self::dayKey(), 'reads_count' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return self::query()
            ->where('agency_id', self::PLATFORM)
            ->where('period_key', self::dayKey())
            ->whereNull('cap_alert_sent_at')
            ->update(['cap_alert_sent_at' => now()]) === 1;
    }

    private static function reserve(int $agencyId, string $period, int $cap): bool
    {
        if ($cap <= 0) {
            return false;
        }

        return DB::transaction(function () use ($agencyId, $period, $cap): bool {
            // The unique key makes a racing first-insert harmless.
            DB::table('market_report_ai_usage')->insertOrIgnore([
                'agency_id' => $agencyId, 'period_key' => $period, 'reads_count' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = self::query()->where('agency_id', $agencyId)->where('period_key', $period)->lockForUpdate()->first();
            if ($row === null || $row->reads_count >= $cap) {
                return false;
            }
            $row->forceFill(['reads_count' => $row->reads_count + 1])->save();

            return true;
        });
    }

    private static function release(int $agencyId, string $period): void
    {
        self::query()
            ->where('agency_id', $agencyId)
            ->where('period_key', $period)
            ->where('reads_count', '>', 0)
            ->decrement('reads_count');
    }
}
