<?php

/**
 * Contact-details Phase 1 — the default selectable list of country dialing
 * prefixes for a contact's phone numbers. ZA is first (the agency's home
 * country) and is the default selection on every new number.
 *
 * Static/global for now — every agency sees the same list. If a future phase
 * needs per-agency customisation, wrap this array the same way ContactSource
 * wraps its seeded defaults; don't duplicate the data.
 */
return [
    'default' => 'ZA',
    'countries' => [
        ['iso' => 'ZA', 'name' => 'South Africa', 'dial_code' => '+27'],
        ['iso' => 'US', 'name' => 'United States', 'dial_code' => '+1'],
        ['iso' => 'CA', 'name' => 'Canada', 'dial_code' => '+1'],
        ['iso' => 'GB', 'name' => 'United Kingdom', 'dial_code' => '+44'],
        ['iso' => 'AU', 'name' => 'Australia', 'dial_code' => '+61'],
        ['iso' => 'NZ', 'name' => 'New Zealand', 'dial_code' => '+64'],
        ['iso' => 'IE', 'name' => 'Ireland', 'dial_code' => '+353'],
        ['iso' => 'DE', 'name' => 'Germany', 'dial_code' => '+49'],
        ['iso' => 'FR', 'name' => 'France', 'dial_code' => '+33'],
        ['iso' => 'NL', 'name' => 'Netherlands', 'dial_code' => '+31'],
        ['iso' => 'BE', 'name' => 'Belgium', 'dial_code' => '+32'],
        ['iso' => 'CH', 'name' => 'Switzerland', 'dial_code' => '+41'],
        ['iso' => 'PT', 'name' => 'Portugal', 'dial_code' => '+351'],
        ['iso' => 'ES', 'name' => 'Spain', 'dial_code' => '+34'],
        ['iso' => 'IT', 'name' => 'Italy', 'dial_code' => '+39'],
        ['iso' => 'AE', 'name' => 'United Arab Emirates', 'dial_code' => '+971'],
        ['iso' => 'SA', 'name' => 'Saudi Arabia', 'dial_code' => '+966'],
        ['iso' => 'IL', 'name' => 'Israel', 'dial_code' => '+972'],
        ['iso' => 'IN', 'name' => 'India', 'dial_code' => '+91'],
        ['iso' => 'CN', 'name' => 'China', 'dial_code' => '+86'],
        ['iso' => 'JP', 'name' => 'Japan', 'dial_code' => '+81'],
        ['iso' => 'SG', 'name' => 'Singapore', 'dial_code' => '+65'],
        ['iso' => 'HK', 'name' => 'Hong Kong', 'dial_code' => '+852'],
        ['iso' => 'BR', 'name' => 'Brazil', 'dial_code' => '+55'],
        ['iso' => 'MZ', 'name' => 'Mozambique', 'dial_code' => '+258'],
        ['iso' => 'ZW', 'name' => 'Zimbabwe', 'dial_code' => '+263'],
        ['iso' => 'ZM', 'name' => 'Zambia', 'dial_code' => '+260'],
        ['iso' => 'BW', 'name' => 'Botswana', 'dial_code' => '+267'],
        ['iso' => 'NA', 'name' => 'Namibia', 'dial_code' => '+264'],
        ['iso' => 'KE', 'name' => 'Kenya', 'dial_code' => '+254'],
        ['iso' => 'NG', 'name' => 'Nigeria', 'dial_code' => '+234'],
        ['iso' => 'GH', 'name' => 'Ghana', 'dial_code' => '+233'],
        ['iso' => 'EG', 'name' => 'Egypt', 'dial_code' => '+20'],
        ['iso' => 'MU', 'name' => 'Mauritius', 'dial_code' => '+230'],
    ],
];
