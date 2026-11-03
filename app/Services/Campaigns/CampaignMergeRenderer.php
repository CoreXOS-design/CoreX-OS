<?php

namespace App\Services\Campaigns;

use App\Models\Campaigns\CampaignLead;

/** AT-534 — merge fields in a step's subject and body (.ai/specs/campaigns.md §6.5). */
class CampaignMergeRenderer
{
    public const FIELDS = ['first_name', 'last_name', 'company', 'town'];

    /** Every {token} in $text that is not a merge field. @return list<string> */
    public function unknownTokens(string $text): array
    {
        preg_match_all('/\{([^{}\s]{1,40})\}/', $text, $m);

        return array_values(array_unique(array_filter($m[1], fn ($t) => ! in_array($t, self::FIELDS, true))));
    }

    public function render(string $text, CampaignLead $lead): string
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values['{' . $field . '}'] = trim((string) $lead->{$field});
        }

        return strtr($text, $values);
    }

    /** One-line subject: merge, then collapse whitespace (an empty field must not leave a double space or a line break). */
    public function renderSubject(string $subject, CampaignLead $lead): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $this->render($subject, $lead)));
    }
}
