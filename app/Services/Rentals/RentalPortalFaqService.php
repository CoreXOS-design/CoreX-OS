<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\RentalPortalSetting;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * .ai/specs/rental-portal-access.md §22 — the portal Home FAQ ("Can I give notice?", "What happens if I give notice
 * before my lease expires?"), worked out from the signed lease's OWN terms.
 *
 * The rule that shapes everything here (Johan, 8 Oct 2026): where the lease has no such data, show NOTHING rather than a
 * guess. So:
 *  - the lease's own terms are `lease_agreement_terms.earliest_termination_date` (clause "earliest date notice may expire"),
 *    and, where the agency's own agreement map carries them, `extra.notice_period_days` and `extra.early_cancellation_terms`;
 *  - if the lease holds NONE of those, the lease gets no FAQ at all — the agency's standard notice period on its own is not a
 *    statement about this lease;
 *  - each answer is the AGENCY'S wording (RentalPortalSetting::FAQ_DEFAULTS unless edited) with the lease's values merged in;
 *    a [[piece]] whose {value} the lease does not have is dropped whole, and a question whose whole answer is empty is dropped.
 *
 * No legal wording lives in code: the sentences are settings with neutral defaults.
 */
class RentalPortalFaqService
{
    public const TOKENS = ['notice_period', 'notice_days', 'earliest_notice_date', 'notice_from', 'notice_by_date', 'earliest_termination_date', 'lease_end_date', 'early_cancellation_terms', 'early_cancellation_notice', 'early_cancellation_penalty'];

    /**
     * @return array<int,array{key:string,question:string,answer:string}>
     */
    public function forLease(Lease $lease, string $audience): array
    {
        if (in_array($lease->status, [Lease::STATUS_CANCELLED, Lease::STATUS_EXPIRED, Lease::STATUS_DRAFT], true)) {
            return [];
        }

        $agencyId = (int) $lease->agency_id;
        $who = $audience === RentalPortalOverviewService::AUDIENCE_LANDLORD ? 'landlord' : 'tenant';
        $values = $this->values($lease, $agencyId, $who);

        // No notice / cancellation term on THIS lease → no FAQ. (Agency standard notice days alone are not the lease's terms.)
        if (! $values['_lease_has_terms']) {
            return [];
        }

        $faqs = [];

        $noticeAnswer = $this->render(RentalPortalSetting::faqTextFor($agencyId, "faq_{$who}_notice_answer"), $values);
        if ($noticeAnswer !== '') {
            $faqs[] = [
                'key' => 'notice',
                'question' => RentalPortalSetting::faqTextFor($agencyId, "faq_{$who}_notice_question"),
                'answer' => $noticeAnswer,
            ];
        }

        // "Before the lease expires" needs something to say about leaving early: the earliest date or the lease's own terms.
        if ($values['earliest_termination_date'] !== null || $values['early_cancellation_terms'] !== null) {
            $earlyAnswer = $this->render(RentalPortalSetting::faqTextFor($agencyId, "faq_{$who}_early_answer"), $values);
            if ($earlyAnswer !== '') {
                $faqs[] = [
                    'key' => 'early',
                    'question' => RentalPortalSetting::faqTextFor($agencyId, "faq_{$who}_early_question"),
                    'answer' => $earlyAnswer,
                ];
            }
        }

        return $faqs;
    }

    /**
     * The values the lease can supply. Anything it cannot is null (never a default, never a guess).
     *
     * @return array<string,mixed>
     */
    public function values(Lease $lease, int $agencyId, string $who = 'tenant'): array
    {
        $notice = app(LeaseNoticeTermsService::class);
        $stored = $notice->forLease($lease); // includes earliest_termination_date
        $terms = $notice->termsOf($lease);
        $extra = is_array($terms?->extra) ? $terms->extra : [];

        $length = $stored['notice_period'];
        $unit = $stored['notice_period_unit'] ?? 'days';

        // Older route: the agency's own agreement map may declare the length / cancellation wording in `extra`.
        $extraDays = $this->positiveInt($extra['notice_period_days'] ?? null);
        if ($length === null && $extraDays !== null) {
            $length = $extraDays;
            $unit = 'days';
        }
        $extraEarly = $this->cleanText($extra['early_cancellation_terms'] ?? null);

        $earliestEnd = $stored['earliest_termination_date'] ? Carbon::parse($stored['earliest_termination_date']) : null;
        $today = now()->startOfDay();

        // The notice LENGTH: the lease's own; otherwise (a lease that holds other terms but no length) the agency's standard,
        // and only if the agency has actually saved one — the 30-day fallback is a default, not a term.
        if ($length === null) {
            $saved = $this->positiveInt(LeaseSetting::withoutGlobalScopes()->where('agency_id', $agencyId)->value('tenant_notice_period_days'));
            if ($saved !== null) {
                $length = $saved;
                $unit = LeaseSetting::tenantNoticePeriodUnitFor($agencyId);
            }
        }

        $workedLease = $notice->workedDates($lease, array_merge($stored, ['notice_period' => $length, 'notice_period_unit' => $unit]));
        $earliestNotice = $workedLease['earliest_notice'] ? Carbon::parse($workedLease['earliest_notice']) : null;
        $noticeBy = $workedLease['notice_by'] ? Carbon::parse($workedLease['notice_by']) : null;

        $noticeFrom = null;
        if ($earliestNotice) {
            $noticeFrom = $earliestNotice->lte($today) ? 'now' : 'from ' . $this->day($earliestNotice);
        }

        return [
            // Does the LEASE itself carry any notice / cancellation term? (the gate for showing any FAQ)
            '_lease_has_terms' => $notice->anyIn($stored) || $extraDays !== null || $extraEarly !== null,
            'notice_period' => $notice->periodText($length, $unit),
            'notice_days' => $length !== null && $unit === 'days' ? (string) $length : ($length !== null && $unit === 'weeks' ? (string) ($length * 7) : null),
            'earliest_termination_date' => $earliestEnd ? $this->day($earliestEnd) : null,
            'earliest_notice_date' => $earliestNotice ? $this->day($earliestNotice) : null,
            'notice_from' => $noticeFrom,
            // To leave exactly when a fixed term ends: the last day to give notice (only while that day is still ahead).
            'notice_by_date' => $noticeBy && $noticeBy->gte($today) ? $this->day($noticeBy) : null,
            'lease_end_date' => $lease->end_date && ! $lease->is_month_to_month ? $this->day($lease->end_date) : null,
            'early_cancellation_notice' => $notice->periodText($stored['early_cancellation_notice'], $stored['early_cancellation_notice_unit']),
            'early_cancellation_penalty' => $stored['early_cancellation_penalty'],
            'early_cancellation_terms' => $this->cancellationSentence($stored, $extraEarly, $agencyId, $who, $notice),
        ];
    }

    /**
     * The early-cancellation sentence, from the agency's own wording for "allowed" / "not allowed" (settings, not code) merged
     * with this lease's values; the older `extra.early_cancellation_terms` text when the lease holds no structured answer.
     *
     * @param array<string,mixed> $stored
     */
    private function cancellationSentence(array $stored, ?string $extraText, int $agencyId, string $who, LeaseNoticeTermsService $notice): ?string
    {
        $allowed = $stored['early_cancellation_allowed'] ?? null;
        if ($allowed === 'yes' || $allowed === 'no') {
            $template = RentalPortalSetting::faqTextFor($agencyId, "faq_{$who}_cancel_" . $allowed);
            $v = [
                'early_cancellation_notice' => $notice->periodText($stored['early_cancellation_notice'], $stored['early_cancellation_notice_unit']),
                'early_cancellation_penalty' => $stored['early_cancellation_penalty'],
            ];
            $sentence = $this->render($template, $v);

            return $sentence !== '' ? $sentence : null;
        }

        return $extraText;
    }

    /**
     * Merge the values into the agency's wording. [[piece]] is dropped whole when any {value} inside it is missing;
     * a {value} outside a piece that is missing is removed; an unknown {word} is removed. Never leaks a raw {token}.
     *
     * @param array<string,mixed> $values
     */
    public function render(string $template, array $values): string
    {
        $fill = function (string $text) use ($values): ?string {
            $missing = false;
            $out = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values, &$missing) {
                $v = $values[$m[1]] ?? null;
                if ($v === null || $v === '') {
                    $missing = true;

                    return '';
                }

                return (string) $v;
            }, $text);

            return $missing ? null : $out;
        };

        // 1. optional pieces
        $template = preg_replace_callback('/\[\[(.*?)\]\]/s', fn ($m) => $fill($m[1]) ?? '', $template);
        // 2. whatever is left outside a piece
        $template = preg_replace_callback('/\{([a-z_]+)\}/', function ($m) use ($values) {
            $v = $values[$m[1]] ?? null;

            return $v === null ? '' : (string) $v;
        }, $template);

        // tidy: doubled spaces, a space before punctuation, a lone comma/full stop left behind
        $template = preg_replace('/[ \t]+/', ' ', $template);
        $template = preg_replace('/\s+([.,;:])/', '$1', $template);
        $template = preg_replace('/([.,;:])\1+/', '$1', $template);

        return trim($template);
    }

    private function day(CarbonInterface $d): string
    {
        return $d->format('j M Y');
    }

    private function positiveInt(mixed $v): ?int
    {
        if ($v === null || $v === '' || !is_numeric($v)) {
            return null;
        }
        $n = (int) $v;

        return $n > 0 ? $n : null;
    }

    private function cleanText(mixed $v): ?string
    {
        if (!is_string($v)) {
            return null;
        }
        $v = trim($v);

        return $v === '' ? null : $v;
    }
}
