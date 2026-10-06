<?php

namespace App\Services\PlatformEsign;

use App\Models\Agency;
use App\Models\Platform\AgencyTimeline;
use App\Services\Platform\AgencyTimelineService;
use App\Services\Platform\PlainDocRenderer;

/**
 * Agency details a CoreX contract template can drop into its wording, written {{ like_this }}.
 * A field that is unknown OR has no value is a refusal naming the field — a contract must never carry a blank.
 */
class MergeFields
{
    public const FIELDS = [
        'agency_name', 'agency_trading_name', 'agency_reg_no', 'agency_vat_no', 'agency_address',
        'today', 'go_live_date', 'billing_start_date',
    ];

    public function __construct(private AgencyTimelineService $timelines)
    {
    }

    /** @return array<string,string> */
    public function values(?Agency $agency): array
    {
        if (!$agency) {
            return ['today' => now()->format('j F Y')];
        }
        $timeline = AgencyTimeline::where('agency_id', $agency->id)->first();
        $live = $timeline ? $this->timelines->goLive($timeline) : null;
        $liveDate = $live ? ($live['expected'] ?? $live['planned']) : null;

        return [
            'agency_name'         => (string) $agency->name,
            'agency_trading_name' => (string) ($agency->trading_name ?: $agency->name),
            'agency_reg_no'       => (string) $agency->reg_no,
            'agency_vat_no'       => (string) $agency->vat_no,
            'agency_address'      => (string) $agency->address,
            'today'               => now()->format('j F Y'),
            'go_live_date'        => $liveDate ? $liveDate->format('j F Y') : '',
            'billing_start_date'  => $liveDate ? $liveDate->copy()->addDay()->format('j F Y') : '',
        ];
    }

    /** Fields a body uses (unique, in order). */
    public function used(string $body): array
    {
        preg_match_all('/\{\{\s*([a-z_]+)\s*\}\}/', $body, $m);

        return array_values(array_unique($m[1]));
    }

    /** @throws \DomainException */
    public function render(string $body, array $values): string
    {
        $merged = preg_replace_callback('/\{\{\s*([a-z_]+)\s*\}\}/', function ($m) use ($values) {
            $key = $m[1];
            if (!in_array($key, self::FIELDS, true)) {
                throw new \DomainException("The template uses an unknown field {{{$key}}}.");
            }
            if (trim((string) ($values[$key] ?? '')) === '') {
                throw new \DomainException("The field {{{$key}}} has no value for this contract. Pick the agency it is for (and start its timeline for go-live dates), fill the missing detail on the agency, or remove the field from the template.");
            }

            return $values[$key];
        }, $body);

        return PlainDocRenderer::render($merged);
    }
}
