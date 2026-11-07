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
        // Single-agent agreements only (solo-agent-accounts.md section 4.2/5a): filled for a single-agent agency, blank - and so
        // refused with a clear message - for any other. monthly_fee comes from the same setting billing uses, so the contract and the bill agree.
        'agent_name', 'agent_ffc_number', 'monthly_fee',
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

        $solo = $this->soloValues($agency);

        return $solo + [
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

    /** @return array<string,string> agent_name / agent_ffc_number / monthly_fee for a single-agent agency; [] for any other. */
    private function soloValues(Agency $agency): array
    {
        if (! $agency->isSolo()) {
            return [];
        }
        $signup = \App\Models\SoloAgentSignup::withTrashed()->where('agency_id', $agency->id)->first();

        return [
            'agent_name'       => (string) ($signup?->user?->name ?? ''),
            'agent_ffc_number' => (string) ($signup?->ffc_number ?? ''),
            'monthly_fee'      => \App\Support\Money\Zar::formatWhole((float) config('solo-agent.monthly_fee_zar', 450)) . ' per month',
        ];
    }

    /** Anything written between double braces counts as a merge field — a typo or a different case must be caught, never shipped as literal text. */
    private const ANY = '/\{\{(.*?)\}\}/s';

    /** Fields a body uses (unique, in order) — EVERY {{ … }} in it, known or not, so unknown / mis-cased names can be refused. */
    public function used(string $body): array
    {
        preg_match_all(self::ANY, $body, $m);

        return array_values(array_unique(array_map('trim', $m[1])));
    }

    /** @throws \DomainException */
    public function render(string $body, array $values): string
    {
        $merged = preg_replace_callback(self::ANY, function ($m) use ($values) {
            $key = trim($m[1]);
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
