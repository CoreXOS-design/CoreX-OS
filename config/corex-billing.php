<?php

declare(strict_types=1);

/**
 * CoreX agency billing — what an agency pays US.
 *
 * Spec: .ai/specs/agency-billing.md  (AT-11)
 *
 * Every rate CoreX charges lives HERE and nowhere else. A price change is a
 * config edit, never a code hunt. Nothing in app/ hardcodes a rand amount.
 *
 * TWO PRICE LISTS (spec §3 D7, Johan 2026-10-19). Every agency is pinned to
 * the price list it joined on (`agency_subscriptions.price_list`):
 *   - 'tiers_2026_07' — the Team / Agency plans below. Every agency that was
 *     already on CoreX on 19 October 2026 stays on it.
 *   - 'bands_2026_10' — one flat monthly fee per headcount band (`bands`).
 *     Every agency created from then on.
 * `price_list` names the list a NEW agency gets.
 *
 * The plans and bands are PRICE SHAPES, not feature sets — every agency gets
 * full access to everything (spec §3 D2). The plan (or band) is derived from
 * headcount, never hand-set.
 *
 * NOTE: no VAT anywhere, by decision (spec §3 D4). The number shown is the
 * number owed — VAT-inclusive once RR Technologies registers, with no increase.
 */
return [

    /*
    |---------------------------------------------------------------------------
    | The price list a newly created agency is put on
    |---------------------------------------------------------------------------
    */
    'price_list' => 'bands_2026_10',

    /*
    |---------------------------------------------------------------------------
    | Bands — one flat monthly fee for the band the headcount falls in
    |---------------------------------------------------------------------------
    | Price list 'bands_2026_10'. Bands are [from, to, amount] and MUST be
    | contiguous and ascending from 1. Above the last band the fee is on
    | quotation: the agency is billed only once a custom amount is set on the
    | System Developer → Agency Billing page.
    */
    'bands' => [
        'label' => 'CoreX',
        'bands' => [
            ['from' => 1,  'to' => 1,  'amount' => 450.00],
            ['from' => 2,  'to' => 2,  'amount' => 900.00],
            ['from' => 3,  'to' => 5,  'amount' => 1495.00],
            ['from' => 6,  'to' => 10, 'amount' => 2495.00],
            ['from' => 11, 'to' => 15, 'amount' => 3295.00],
            ['from' => 16, 'to' => 20, 'amount' => 3995.00],
            ['from' => 21, 'to' => 25, 'amount' => 4595.00],
            ['from' => 26, 'to' => 30, 'amount' => 5095.00],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | CoreX Team — flat, headcount × rate   (price list 'tiers_2026_07')
    |---------------------------------------------------------------------------
    | No base fee. No tiers. Applies while billable seats <= max_seats.
    */
    'team' => [
        'label'          => 'CoreX Team',
        'seat_rate'      => 450.00,
        'max_seats'      => 10,   // the 11th seat moves the agency to the Agency plan
    ],

    /*
    |---------------------------------------------------------------------------
    | CoreX Agency — base fee + GRADUATED seat tiers   (price list 'tiers_2026_07')
    |---------------------------------------------------------------------------
    | Graduated means each band is charged at its OWN rate — it is NOT a flat
    | rate that switches. 25 seats is billed as:
    |     10 × 295  +  10 × 250  +  5 × 195  =  R6 425   (+ R1 495 base = R7 920)
    |
    | Tiers are [from, to (null = unbounded), rate] and MUST be contiguous and
    | ascending. SubscriptionPricingService walks them in order.
    */
    'agency' => [
        'label'      => 'CoreX Agency',
        'base_fee'   => 1495.00,
        'seat_tiers' => [
            ['from' => 1,  'to' => 10,   'rate' => 295.00],
            ['from' => 11, 'to' => 20,   'rate' => 250.00],
            ['from' => 21, 'to' => null, 'rate' => 195.00],
        ],
    ],

    /*
    |---------------------------------------------------------------------------
    | Branches — plan-agnostic (spec §3 D2a, Johan 2026-07-14)
    |---------------------------------------------------------------------------
    | The first branch is included. Every branch beyond the first costs
    | `rate`/month — on BOTH plans, and on every band (Johan 2026-10-19). An 8-agent agency with 2 branches is on the
    | Team plan AND pays for the second branch. Charging R0 for a real second
    | branch is a revenue leak; the plans differ in price shape, not in access.
    */
    'branches' => [
        'included' => 1,
        'rate'     => 750.00,
    ],

    /*
    |---------------------------------------------------------------------------
    | Plan-change notification (spec §3 D3)
    |---------------------------------------------------------------------------
    | Who gets told when an agency auto-switches plan. Sent via the 'corex'
    | mailer (config/mail.php) so it delivers even where the default mailer is
    | 'log' (staging). Recipients live here, never hardcoded in a listener.
    */
    'notify' => [
        'plan_change_recipients' => [
            'andre@corexos.co.za',
            'johan@corexos.co.za',
        ],
        'mailer' => 'corex',
    ],

    /*
    |---------------------------------------------------------------------------
    | Seat release hold — the anti-churn lock (AT-278)
    |---------------------------------------------------------------------------
    | Spec: .ai/specs/agent-seat-release-lock.md
    |
    | The bill is computed on the LIVE seat count, so a seat freed today stops
    | being charged today. Honest — but it also means a seat could be freed and
    | retaken at will, so an agency could archive eight agents on the 28th and
    | restore them on the 1st.
    |
    | Releasing a seat (soft-delete OR deactivate) therefore starts a hold: that
    | PERSON cannot occupy a seat again for `lock_days`. The agency still stops
    | paying immediately — we never charge for people who are gone — but a
    | R450 dodge now costs a month of that agent's production instead.
    |
    | PLATFORM CONTROL, NOT AN AGENCY SETTING. This must never reach the Agency
    | Onboarding Setup Wizard (non-negotiable #10a): an agency that can set its
    | own anti-abuse window has no anti-abuse window. Recorded as a deliberate
    | wizard exemption in the spec §10, same as the seat rates above.
    |
    | Changing this value does NOT move existing holds — `reinstatable_at` is
    | stamped at release time, so a policy change never retroactively extends a
    | hold an agency is already sitting inside (spec D4).
    |
    | A CoreX System Owner can lift any hold immediately, with a mandatory
    | reason, on the record. Nobody inside the agency can — that asymmetry is
    | the design.
    */
    'seat_release' => [
        'lock_days' => 30,
    ],

];
