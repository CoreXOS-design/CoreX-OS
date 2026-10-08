<?php

namespace App\Services\Rentals;

use App\Models\Lease;
use App\Models\LeaseSetting;
use App\Models\RentalPortalSetting;
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
    public const TOKENS = ['notice_days', 'earliest_termination_date', 'earliest_notice_date', 'lease_end_date', 'early_cancellation_terms'];

    /**
     * @return array<int,array{key:string,question:string,answer:string}>
     */
    public function forLease(Lease $lease, string $audience): array
    {
        if (in_array($lease->status, [Lease::STATUS_CANCELLED, Lease::STATUS_EXPIRED, Lease::STATUS_DRAFT], true)) {
            return [];
        }

        $agencyId = (int) $lease->agency_id;
        $values = $this->values($lease, $agencyId);

        // No notice / cancellation term on THIS lease → no FAQ. (Agency standard notice days alone are not the lease's terms.)
        if (!$values['_lease_has_terms']) {
            return [];
        }

        $who = $audience === RentalPortalOverviewService::AUDIENCE_LANDLORD ? 'landlord' : 'tenant';
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
    public function values(Lease $lease, int $agencyId): array
    {
        // Read explicitly (agency and lease pinned, scope off): this runs for a portal person, not a staff user, so the staff agency scope must not decide.
        $terms = \App\Models\LeaseAgreementTerms::withoutGlobalScopes()
            ->whereNull('deleted_at')->where('agency_id', $agencyId)->where('lease_id', $lease->id)->first(); // null = no terms row (or a soft-deleted one)
        $extra = is_array($terms?->extra) ? $terms->extra : [];

        $earliest = $terms?->earliest_termination_date;

        $leaseNoticeDays = $this->positiveInt($extra['notice_period_days'] ?? null);
        $earlyTerms = $this->cleanText($extra['early_cancellation_terms'] ?? null);

        // The notice LENGTH: the lease's own when its agreement map carries it, otherwise the agency's standard — and only
        // if the agency has actually saved one (LeaseSetting falls back to 30 when nothing is saved; that is a default, not a term).
        $noticeDays = $leaseNoticeDays ?? $this->positiveInt(
            LeaseSetting::withoutGlobalScopes()->where('agency_id', $agencyId)->value('tenant_notice_period_days')
        );

        $earliestNotice = null;
        if ($earliest && $noticeDays) {
            $candidate = $earliest->copy()->startOfDay()->subDays($noticeDays);
            // A date already behind us is not "the earliest you can give notice" — it is simply "now". Say nothing rather than a past date.
            $earliestNotice = $candidate->gte(now()->startOfDay()) ? $candidate : null;
        }

        return [
            // Does the LEASE itself carry any notice / cancellation term? (the gate for showing any FAQ)
            '_lease_has_terms' => $earliest !== null || $leaseNoticeDays !== null || $earlyTerms !== null,
            'notice_days' => $noticeDays !== null ? (string) $noticeDays : null,
            'earliest_termination_date' => $earliest ? $this->day($earliest) : null,
            'earliest_notice_date' => $earliestNotice ? $this->day($earliestNotice) : null,
            'lease_end_date' => $lease->end_date && !$lease->is_month_to_month ? $this->day($lease->end_date) : null,
            'early_cancellation_terms' => $earlyTerms,
        ];
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
