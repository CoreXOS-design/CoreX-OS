<?php

namespace App\Services\Campaigns;

use App\Models\DevSetting;

/**
 * AT-534 — the owner-editable Campaigns defaults (.ai/specs/campaigns.md §4). Platform settings in DevSetting (Article Library
 * precedent), falling back to config/campaigns.php. A stored value that fails its own rule is ignored, never trusted.
 */
class CampaignSettings
{
    public const KEY_DAILY_LIMIT = 'campaigns.default_daily_limit';
    public const KEY_SEND_DAYS = 'campaigns.default_send_days';
    public const KEY_SEND_FROM = 'campaigns.default_send_from';
    public const KEY_SEND_TO = 'campaigns.default_send_to';
    public const KEY_FOOTER = 'campaigns.opt_out_footer';
    public const KEY_MIN_GAP = 'campaigns.min_day_gap';

    /** @return array{daily_limit:int, send_days:list<int>, send_from:string, send_to:string, opt_out_footer:string, min_day_gap:int} */
    public function all(): array
    {
        $d = config('campaigns.defaults');

        $limit = (int) DevSetting::get(self::KEY_DAILY_LIMIT, (string) $d['daily_limit']);
        $days = json_decode((string) DevSetting::get(self::KEY_SEND_DAYS, json_encode($d['send_days'])), true);
        $days = is_array($days) ? array_values(array_unique(array_filter(array_map('intval', $days), fn ($x) => $x >= 1 && $x <= 7))) : [];
        $from = (string) DevSetting::get(self::KEY_SEND_FROM, $d['send_from']);
        $to = (string) DevSetting::get(self::KEY_SEND_TO, $d['send_to']);
        $footer = trim((string) DevSetting::get(self::KEY_FOOTER, $d['opt_out_footer']));
        $gap = (int) DevSetting::get(self::KEY_MIN_GAP, (string) $d['min_day_gap']);

        $validTime = fn (string $t) => (bool) preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t);
        if (! $validTime($from) || ! $validTime($to) || $from >= $to) {
            [$from, $to] = [$d['send_from'], $d['send_to']];
        }

        return [
            'daily_limit' => ($limit >= 1 && $limit <= config('campaigns.max_daily_limit')) ? $limit : $d['daily_limit'],
            'send_days' => $days !== [] ? $days : $d['send_days'],
            'send_from' => $from,
            'send_to' => $to,
            'opt_out_footer' => $footer !== '' ? $footer : $d['opt_out_footer'],
            'min_day_gap' => ($gap >= 1 && $gap <= 30) ? $gap : $d['min_day_gap'],
        ];
    }

    public function save(array $data): void
    {
        $days = array_values(array_unique(array_map('intval', $data['send_days'])));
        sort($days);

        DevSetting::set(self::KEY_DAILY_LIMIT, (string) (int) $data['daily_limit']);
        DevSetting::set(self::KEY_SEND_DAYS, json_encode($days));
        DevSetting::set(self::KEY_SEND_FROM, $data['send_from']);
        DevSetting::set(self::KEY_SEND_TO, $data['send_to']);
        DevSetting::set(self::KEY_FOOTER, trim((string) $data['opt_out_footer']));
        DevSetting::set(self::KEY_MIN_GAP, (string) (int) $data['min_day_gap']);
    }
}
