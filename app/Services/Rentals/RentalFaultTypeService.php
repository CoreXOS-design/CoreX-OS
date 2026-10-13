<?php

namespace App\Services\Rentals;

use App\Models\Property;
use App\Models\RentalFaultType;

/**
 * .ai/specs/rentals-faults-work-orders.md §3.2 — "steps can pull property
 * data." A small, hardcoded allow-list of placeholder tokens, resolved by
 * simple string replacement — NOT a general template-merge engine, matching
 * BUILD_STANDARD §3's prevent-or-absorb discipline.
 *
 * Reused, not agent-screen-specific: this is the ONE place first-aid
 * content is rendered. The agent-facing picker (Slice 2,
 * RentalFaultTypeController::firstAid()) and the tenant-facing portal
 * (Slice 5, once ClientUser login lands, .ai/specs/rentals-faults-work-
 * orders.md §7.2) both call this exact method — never a second rendering
 * path for the tenant screen.
 *
 * Fallback discipline, tightened 2026-09-29 after Johan's QA1 review: a
 * missing location must never leave a raw {{token}} OR a grammatically
 * broken "is at: ." — the whole lead-in clause ("Your main water valve is
 * at: ") is swapped for a clean, standalone fallback sentence, not just
 * the bare token value.
 */
class RentalFaultTypeService
{
    /**
     * One entry per supported token. `leadInPattern` matches the token
     * together with its natural "is at: " lead-in (case-insensitive,
     * tolerant of missing/extra whitespace and the trailing period) so the
     * fallback reads as a complete sentence on its own. If an agency has
     * customised the wording and the lead-in no longer matches, `bareFallback`
     * is used as a defensive, always-safe substitute for the bare token —
     * never left unresolved.
     */
    private const TOKENS = [
        '{{main_water_valve_location}}' => [
            'property_field' => 'rental_main_water_valve_location',
            'lead_in_pattern' => '/your\s+main\s+water\s+valve\s+is\s+at:?\s*\{\{main_water_valve_location\}\}\.?/i',
            'fallback_sentence' => "Your agent hasn't recorded where the main water valve is — check near the meter/boundary.",
            'bare_fallback' => 'not recorded — check near the meter/boundary',
        ],
        '{{db_board_location}}' => [
            'property_field' => 'rental_db_board_location',
            'lead_in_pattern' => '/your\s+db\s+board\s+is\s+at:?\s*\{\{db_board_location\}\}\.?/i',
            'fallback_sentence' => "Your agent hasn't recorded where the DB board is — check near the meter/boundary.",
            'bare_fallback' => 'not recorded — check near the meter/boundary',
        ],
    ];

    /**
     * How the AGENT reads one of the tenant's ticked self-check steps. The steps are written to the tenant ("Your agent hasn't recorded where the main water valve is"); the agent
     * must never read that, nor an unfilled {{placeholder}}: a step whose place was never recorded reads "<Place> not recorded on this property" with the property field to set;
     * a step that carries the place reads "Main water valve is at: ...".
     *
     * @return array{text:string, missing:?string, field:?string}
     */
    public function agentWording(string $text): array
    {
        $labels = ['{{main_water_valve_location}}' => 'Main water valve location', '{{db_board_location}}' => 'DB board location'];
        foreach (self::TOKENS as $token => $config) {
            $label = $labels[$token] ?? 'Location';
            if (trim($text) === $config['fallback_sentence'] || str_contains($text, $token) || str_contains($text, $config['bare_fallback'])) {
                return ['text' => $label . ' not recorded on this property', 'missing' => $label, 'field' => $config['property_field']];
            }
        }
        $text = preg_replace('/\byour\s+(main\s+water\s+valve|db\s+board)\s+is\s+at:/i', '$1 is at:', $text);

        return ['text' => ucfirst((string) $text), 'missing' => null, 'field' => null];
    }

    public function renderFirstAidSteps(RentalFaultType $faultType, Property $property): string
    {
        $steps = (string) $faultType->first_aid_steps;

        foreach (self::TOKENS as $token => $config) {
            $value = $property->{$config['property_field']};

            if ($value) {
                $steps = str_replace($token, $value, $steps);
                continue;
            }

            // No value recorded — swap the whole "is at: {{token}}." clause
            // for a clean, standalone fallback sentence when the template
            // still uses the expected phrasing.
            $steps = preg_replace($config['lead_in_pattern'], $config['fallback_sentence'], $steps, 1, $count);

            // Defensive: an agency-customised template that doesn't match
            // the lead-in pattern must still never leak a raw {{token}}.
            if ($count === 0) {
                $steps = str_replace($token, $config['bare_fallback'], $steps);
            }
        }

        return $steps;
    }
}
