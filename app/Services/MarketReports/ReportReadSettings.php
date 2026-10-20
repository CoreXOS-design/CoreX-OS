<?php

declare(strict_types=1);

namespace App\Services\MarketReports;

use App\Models\DevSetting;
use App\Models\PerformanceSetting;

/**
 * Every threshold of the report recogniser, in one place, each with a default
 * (nothing is a code constant). Spec: .ai/specs/mic-complete-spec.md §8.9.
 *
 *   Agency  : report_ai_read_enabled          (performance_settings, Setup Wizard)
 *   System  : report_ai_max_pages             (dev_settings)
 *             report_ai_min_confidence
 *             report_layout_min_match
 *             report_layout_selftest_min
 *             report_ai_monthly_cap        (AI reads per agency per month)
 *             report_ai_platform_daily_cap (AI reads per day across all agencies)
 */
final class ReportReadSettings
{
    public const KEY_AI_ENABLED     = 'report_ai_read_enabled';
    public const KEY_MAX_PAGES      = 'report_ai_max_pages';
    public const KEY_MIN_CONFIDENCE = 'report_ai_min_confidence';
    public const KEY_LAYOUT_MATCH   = 'report_layout_min_match';
    public const KEY_SELFTEST_MIN   = 'report_layout_selftest_min';
    public const KEY_MONTHLY_CAP    = 'report_ai_monthly_cap';
    public const KEY_PLATFORM_DAILY_CAP = 'report_ai_platform_daily_cap';

    public const DEFAULT_AI_ENABLED     = true;
    public const DEFAULT_MAX_PAGES      = 40;
    public const DEFAULT_MIN_CONFIDENCE = 0.50;
    public const DEFAULT_LAYOUT_MATCH   = 0.85;
    public const DEFAULT_SELFTEST_MIN   = 0.90;
    public const DEFAULT_MONTHLY_CAP    = 5;
    public const DEFAULT_PLATFORM_DAILY_CAP = 10;

    /** Bump to invalidate every cached AI read after the prompt changes. */
    public const PROMPT_VERSION = 'rr-v1';

    public function aiEnabledForAgency(?int $agencyId): bool
    {
        if ($agencyId === null) {
            return self::DEFAULT_AI_ENABLED;
        }
        $value = PerformanceSetting::get(self::KEY_AI_ENABLED, self::DEFAULT_AI_ENABLED ? 1 : 0, $agencyId);

        return in_array((string) $value, ['1', 'true', 'yes', 'on'], true);
    }

    public function maxPages(): int
    {
        return max(1, (int) DevSetting::get(self::KEY_MAX_PAGES, (string) self::DEFAULT_MAX_PAGES));
    }

    public function minConfidence(): float
    {
        return $this->clamp01((float) DevSetting::get(self::KEY_MIN_CONFIDENCE, (string) self::DEFAULT_MIN_CONFIDENCE));
    }

    /** AI reads one agency may use per calendar month (0 = none). Johan's ruling 20 Oct: default 5. */
    public function monthlyCap(): int
    {
        return max(0, (int) DevSetting::get(self::KEY_MONTHLY_CAP, (string) self::DEFAULT_MONTHLY_CAP));
    }

    /** AI reads across ALL agencies per day (0 = none). Johan's ruling 20 Oct: default 10. */
    public function platformDailyCap(): int
    {
        return max(0, (int) DevSetting::get(self::KEY_PLATFORM_DAILY_CAP, (string) self::DEFAULT_PLATFORM_DAILY_CAP));
    }

    public function layoutMinMatch(): float
    {
        return $this->clamp01((float) DevSetting::get(self::KEY_LAYOUT_MATCH, (string) self::DEFAULT_LAYOUT_MATCH));
    }

    public function selftestMin(): float
    {
        return $this->clamp01((float) DevSetting::get(self::KEY_SELFTEST_MIN, (string) self::DEFAULT_SELFTEST_MIN));
    }

    /** @return array<string, int|float> the system values as the settings card shows them */
    public function systemValues(): array
    {
        return [
            self::KEY_MAX_PAGES      => $this->maxPages(),
            self::KEY_MIN_CONFIDENCE => $this->minConfidence(),
            self::KEY_LAYOUT_MATCH   => $this->layoutMinMatch(),
            self::KEY_SELFTEST_MIN   => $this->selftestMin(),
            self::KEY_MONTHLY_CAP    => $this->monthlyCap(),
            self::KEY_PLATFORM_DAILY_CAP => $this->platformDailyCap(),
        ];
    }

    private function clamp01(float $v): float
    {
        return max(0.0, min(1.0, $v));
    }
}
