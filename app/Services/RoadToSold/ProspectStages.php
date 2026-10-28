<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsStep;

/**
 * Road to Sold v3 - the 18 stages of a prospect (.ai/specs/road-to-sold.md section 22.2). Pure data, no database.
 * Keys are STORED - never rename. Labels are neutral wording; each stage belongs to one recipe step so the manager's
 * on-track bar for a stage is the recipe step's.
 *
 *   label    what the page shows
 *   step     the recipe step (RecipeCatalogue::STEPS key) the stage counts toward
 *   group    get_stock | sell_stock
 *   next     the next action a prospect gets on entering the stage (null = none); the date is set by whoever knows it
 */
final class ProspectStages
{
    public const FOUND = 'found';
    public const CONTACTED = 'contacted';
    public const APPOINTMENT_SET = 'appointment_set';
    public const EVALUATION_DONE = 'evaluation_done';
    public const PRESENTATION_GIVEN = 'presentation_given';
    public const PRESENTATION_FEEDBACK = 'presentation_feedback';
    public const MANDATE_SIGNED = 'mandate_signed';
    public const LISTING_LIVE = 'listing_live';
    public const ADVERTISING = 'advertising';
    public const LEAD = 'lead';
    public const LEAD_CONTACTED = 'lead_contacted';
    public const CORE_MATCH = 'core_match';
    public const VIEWING_SCHEDULED = 'viewing_scheduled';
    public const VIEWING_PACK = 'viewing_pack';
    public const VIEWING_FEEDBACK = 'viewing_feedback';
    public const OFFER = 'offer';
    public const DEAL = 'deal';
    public const REGISTERED = 'registered';

    /** @var array<string,array{label:string,step:string,group:string,next:?string}> in pipeline order */
    public const ALL = [
        self::FOUND                 => ['label' => 'Found',                       'step' => 'find',              'group' => RtsStep::GROUP_GET_STOCK,  'next' => 'Make first contact'],
        self::CONTACTED             => ['label' => 'Contacted',                   'step' => 'contact_outcome',   'group' => RtsStep::GROUP_GET_STOCK,  'next' => 'Follow up with the seller'],
        self::APPOINTMENT_SET       => ['label' => 'Appointment set',             'step' => 'evaluation_booked', 'group' => RtsStep::GROUP_GET_STOCK,  'next' => 'Do the evaluation'],
        self::EVALUATION_DONE       => ['label' => 'Evaluation done',             'step' => 'evaluation_booked', 'group' => RtsStep::GROUP_GET_STOCK,  'next' => 'Give the presentation'],
        self::PRESENTATION_GIVEN    => ['label' => 'Presentation given',          'step' => 'presentation',      'group' => RtsStep::GROUP_GET_STOCK,  'next' => 'Get the presentation feedback'],
        self::PRESENTATION_FEEDBACK => ['label' => 'Presentation feedback',       'step' => 'presentation',      'group' => RtsStep::GROUP_GET_STOCK,  'next' => 'Get the mandate signed'],
        self::MANDATE_SIGNED        => ['label' => 'Mandate signed',              'step' => 'mandate',           'group' => RtsStep::GROUP_GET_STOCK,  'next' => 'Take the listing live'],
        self::LISTING_LIVE          => ['label' => 'Listing live',                'step' => 'listing_live',      'group' => RtsStep::GROUP_GET_STOCK,  'next' => 'Advertise the listing'],
        self::ADVERTISING           => ['label' => 'Advertising / social shares', 'step' => 'marketing',         'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Follow up leads'],
        self::LEAD                  => ['label' => 'Lead',                        'step' => 'buyers',            'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Contact the lead'],
        self::LEAD_CONTACTED        => ['label' => 'Lead contacted',              'step' => 'buyers',            'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Set up a Core Match'],
        self::CORE_MATCH            => ['label' => 'Core Match set up',           'step' => 'buyers',            'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Schedule a viewing'],
        self::VIEWING_SCHEDULED     => ['label' => 'Viewing scheduled',           'step' => 'viewings',          'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Send the viewing pack'],
        self::VIEWING_PACK          => ['label' => 'Viewing pack sent',           'step' => 'viewings',          'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Capture the viewing feedback'],
        self::VIEWING_FEEDBACK      => ['label' => 'Viewing feedback',            'step' => 'viewings',          'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Follow up the buyer'],
        self::OFFER                 => ['label' => 'Offer',                       'step' => 'otp',               'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Open the deal'],
        self::DEAL                  => ['label' => 'Deal',                        'step' => 'deal',              'group' => RtsStep::GROUP_SELL_STOCK, 'next' => 'Take the deal to registration'],
        self::REGISTERED            => ['label' => 'Registered',                  'step' => 'deal',              'group' => RtsStep::GROUP_SELL_STOCK, 'next' => null],
    ];

    /**
     * The production line (road-to-sold.md 22.24): five phases, 18 stations. Phase key => label and its stations in order. The two-way
     * `group` above (recipe step groups) is unchanged.
     *
     * @var array<string,array{label:string,stages:string[]}>
     */
    public const PHASES = [
        'get_stock' => ['label' => 'Get stock',       'stages' => [self::FOUND, self::CONTACTED, self::APPOINTMENT_SET, self::EVALUATION_DONE]],
        'win'       => ['label' => 'Win the mandate', 'stages' => [self::PRESENTATION_GIVEN, self::PRESENTATION_FEEDBACK, self::MANDATE_SIGNED]],
        'market'    => ['label' => 'Market it',       'stages' => [self::LISTING_LIVE, self::ADVERTISING]],
        'sell'      => ['label' => 'Sell it',         'stages' => [self::LEAD, self::LEAD_CONTACTED, self::CORE_MATCH, self::VIEWING_SCHEDULED, self::VIEWING_PACK, self::VIEWING_FEEDBACK, self::OFFER]],
        'close'     => ['label' => 'Close it',        'stages' => [self::DEAL, self::REGISTERED]],
    ];

    /** Names for a narrow screen; the full label is always in the hover. */
    public const SHORT = [
        self::FOUND => 'Found', self::CONTACTED => 'Contacted', self::APPOINTMENT_SET => 'Appt set', self::EVALUATION_DONE => 'Eval done',
        self::PRESENTATION_GIVEN => 'Presented', self::PRESENTATION_FEEDBACK => 'Feedback', self::MANDATE_SIGNED => 'Mandate',
        self::LISTING_LIVE => 'Live', self::ADVERTISING => 'Advertising',
        self::LEAD => 'Lead', self::LEAD_CONTACTED => 'Lead called', self::CORE_MATCH => 'Core Match', self::VIEWING_SCHEDULED => 'Viewing set',
        self::VIEWING_PACK => 'Pack sent', self::VIEWING_FEEDBACK => 'Viewing fb', self::OFFER => 'Offer',
        self::DEAL => 'Deal', self::REGISTERED => 'Registered',
    ];

    public static function short(string $key): string
    {
        return self::SHORT[$key] ?? self::label($key);
    }

    /** The phase key a station belongs to (null for an unknown key). */
    public static function phaseOf(string $key): ?string
    {
        foreach (self::PHASES as $phase => $meta) {
            if (in_array($key, $meta['stages'], true)) {
                return $phase;
            }
        }

        return null;
    }

    /** @return string[] phase keys in order */
    public static function phaseKeys(): array
    {
        return array_keys(self::PHASES);
    }

    /** Position of a phase in the line (0 = Get stock); -1 for an unknown key. */
    public static function phaseIndex(?string $phase): int
    {
        $i = array_search($phase, self::phaseKeys(), true);

        return $i === false ? -1 : $i;
    }

    /** @return string[] keys in pipeline order */
    public static function keys(): array
    {
        return array_keys(self::ALL);
    }

    public static function exists(string $key): bool
    {
        return isset(self::ALL[$key]);
    }

    public static function label(string $key): string
    {
        return self::ALL[$key]['label'] ?? ucfirst(str_replace('_', ' ', $key));
    }

    /** Position in the pipeline (1 = Found); 0 for an unknown key. */
    public static function order(string $key): int
    {
        $i = array_search($key, self::keys(), true);

        return $i === false ? 0 : $i + 1;
    }

    public static function defaultNext(string $key): ?string
    {
        return self::ALL[$key]['next'] ?? null;
    }

    /** @return string[] the stage keys of one group, in order */
    public static function inGroup(string $group): array
    {
        return array_keys(array_filter(self::ALL, fn ($s) => $s['group'] === $group));
    }

    /** @return string[] the stage keys that belong to a recipe step */
    public static function forStep(string $step): array
    {
        return array_keys(array_filter(self::ALL, fn ($s) => $s['step'] === $step));
    }
}
