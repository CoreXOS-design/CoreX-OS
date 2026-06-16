<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Support\SellerOutreach\TemplateValidationResult;

/**
 * Validates template bodies (and email subjects) before they are saved.
 *
 * Hard rules from spec S4:
 *  - body MUST contain '{tracking_link}'
 *  - body MUST contain '{opt_out_link}' (AT-49 — per-send self-service opt-out;
 *    mandatory on EVERY outreach template, independent of the tracking-link flag)
 *  - body MUST contain 'STOP' (opt-out clause keyword)
 *  - if channel is email, subject MUST be non-empty
 *
 * Soft rules (warnings; do not block save):
 *  - unknown merge fields surfaced via unknownMergeFields().
 */
final class SellerOutreachTemplateValidator
{
    public const KNOWN_MERGE_FIELDS = [
        'seller_name', 'property_address', 'property_suburb', 'property_town',
        'property_type', 'property_beds',
        'agent_name', 'agent_phone',
        'agency_name', 'agency_ppra_no', 'agency_contact',
        // AT-48 — company FFC, sending-agent FFC, branch-then-company tel.
        'agency_ffc', 'agent_ffc', 'branch_or_company_tel',
        'buyer_count', 'matching_buyer_count',
        'tracking_link',
        // AT-49 — per-send self-service opt-out link (mandatory on every template).
        'opt_out_link',
    ];

    /**
     * @param bool $includeTrackingLink When true (the default), the body MUST
     *   contain {tracking_link}. Consent-request templates that carry no
     *   live-demand link pass false. The opt-out (STOP) clause and the email
     *   subject rule are mandatory regardless of this flag.
     */
    public function validate(string $channel, ?string $subject, string $body, bool $includeTrackingLink = true): TemplateValidationResult
    {
        $errors = [];

        if ($channel === 'email' && empty(trim((string) $subject))) {
            $errors['subject_required'] = 'Email templates must have a subject.';
        }

        if ($includeTrackingLink && !str_contains($body, '{tracking_link}')) {
            $errors['tracking_link_missing'] = 'Body must contain the {tracking_link} merge field (mandatory for click tracking) — or turn off "Include tracking link" for this template.';
        }

        // AT-49 — the per-send opt-out link is mandatory on every outreach
        // template regardless of the tracking-link flag (POPIA: every marketing
        // message must carry a one-tap opt-out).
        if (!str_contains($body, '{opt_out_link}')) {
            $errors['opt_out_link_missing'] = 'Body must contain the {opt_out_link} merge field (mandatory one-tap opt-out on every marketing message).';
        }

        if (!preg_match('/\bSTOP\b/i', $body)) {
            $errors['opt_out_missing'] = 'Body must contain an opt-out instruction (the word "STOP" in an opt-out sentence).';
        }

        return new TemplateValidationResult($errors);
    }

    /** @return string[] Unknown merge fields used in the body (warnings only). */
    public function unknownMergeFields(string $body): array
    {
        preg_match_all('/\{([a-z_]+)\}/i', $body, $matches);
        $used = array_unique($matches[1] ?? []);
        return array_values(array_diff($used, self::KNOWN_MERGE_FIELDS));
    }
}
