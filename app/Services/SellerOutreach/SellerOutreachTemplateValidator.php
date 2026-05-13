<?php

declare(strict_types=1);

namespace App\Services\SellerOutreach;

use App\Support\SellerOutreach\TemplateValidationResult;

/**
 * Validates template bodies (and email subjects) before they are saved.
 *
 * Hard rules from spec S4:
 *  - body MUST contain '{tracking_link}'
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
        'agency_name',
        'buyer_count', 'matching_buyer_count',
        'tracking_link',
    ];

    public function validate(string $channel, ?string $subject, string $body): TemplateValidationResult
    {
        $errors = [];

        if ($channel === 'email' && empty(trim((string) $subject))) {
            $errors['subject_required'] = 'Email templates must have a subject.';
        }

        if (!str_contains($body, '{tracking_link}')) {
            $errors['tracking_link_missing'] = 'Body must contain the {tracking_link} merge field (mandatory for click tracking).';
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
