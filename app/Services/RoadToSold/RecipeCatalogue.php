<?php

namespace App\Services\RoadToSold;

use App\Events\Contracts\DomainEvent;
use App\Models\RoadToSold\RtsStep;
use App\Models\RoadToSold\RtsTask;

/**
 * AT-454 phase 1 - the fixed catalogues behind the recipe (road-to-sold.md section 4, 5.5, 6.3, 10.5). Pure data, no database.
 *
 *  - STEPS: the 13 steps, in order, with NEUTRAL labels (an agency renames them). Keys are STORED - never rename.
 *  - EVENTS: the domain events that really fire and so may complete a "core" task. A task cannot be bound to anything else
 *    (spec 10.5: "an event with no raise site is not selectable"). Each entry says which of the event's own facts a task may
 *    narrow on (e.g. a filed document of type "mandate") and who the work is credited to.
 *  - DEFAULT: the neutral recipe a new agency starts from. No agency's wording, weights or areas (non-negotiable 9).
 */
final class RecipeCatalogue
{
    /** key => [group, neutral label], in step order */
    public const STEPS = [
        'find'               => [RtsStep::GROUP_GET_STOCK,  'Find'],
        'find_owner'         => [RtsStep::GROUP_GET_STOCK,  'Find the owner'],
        'contact_outcome'    => [RtsStep::GROUP_GET_STOCK,  'Contact and outcome'],
        'evaluation_booked'  => [RtsStep::GROUP_GET_STOCK,  'Evaluation'],
        'presentation'       => [RtsStep::GROUP_GET_STOCK,  'Presentation'],
        'mandate'            => [RtsStep::GROUP_GET_STOCK,  'Mandate'],
        'listing_live'       => [RtsStep::GROUP_GET_STOCK,  'Listing live'],
        'marketing'          => [RtsStep::GROUP_SELL_STOCK, 'Marketing'],
        'buyers'             => [RtsStep::GROUP_SELL_STOCK, 'Buyers'],
        'viewings'           => [RtsStep::GROUP_SELL_STOCK, 'Viewings'],
        'otp'                => [RtsStep::GROUP_SELL_STOCK, 'Offer to purchase'],
        'deal'               => [RtsStep::GROUP_SELL_STOCK, 'Deal'],
        'aftercare'          => [RtsStep::GROUP_SELL_STOCK, 'Aftercare'],
    ];

    public const GROUP_LABELS = [
        RtsStep::GROUP_GET_STOCK  => 'Get stock',
        RtsStep::GROUP_SELL_STOCK => 'Sell stock',
    ];

    /**
     * The events that may complete a core task (every one has a raise site today - road-to-sold.md section 6.3 and the P2 build log).
     *
     *   label   what the agent would call it
     *   credit  the event's own properties (dotted into a model) holding the agent the work counts for, first non-empty wins;
     *           empty = the person who did it (an assistant's work counts for their agent)
     *   filters fact => label: what a task may narrow on. The facts are the event's public scalar properties and its context().
     *
     * @var array<string,array{label:string,credit:string[],recorded_by:string[],filters:array<string,string>}>
     */
    public const EVENTS = [
        \App\Events\Prospecting\OwnerLinkedToTrackedProperty::class => [
            'label' => 'The owner of a tracked property is found', 'credit' => [], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\RoadToSold\RoadToSoldOutcomeRecorded::class => [
            'label' => 'A call outcome is recorded', 'credit' => ['userId'], 'recorded_by' => ['recordedByUserId'],
            'filters' => ['kind' => 'The kind of outcome (attempt or conversation)', 'outcomeKey' => 'The outcome picked'],
        ],
        \App\Events\Evaluation\EvaluationBooked::class => [
            'label' => 'An evaluation is booked', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Evaluation\EvaluationCompleted::class => [
            'label' => 'An evaluation is done', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Presentation\PresentationPresented::class => [
            'label' => 'A presentation reaches the seller', 'credit' => [], 'recorded_by' => [],
            'filters' => ['channel' => 'How it was sent'],
        ],
        \App\Events\Document\DocumentFiledToProperty::class => [
            'label' => 'A document is filed on a property', 'credit' => [], 'recorded_by' => [],
            'filters' => ['documentTypeSlug' => 'The document type (for example mandate or otp)'],
        ],
        \App\Events\Property\PropertyWentLiveOnPortal::class => [
            'label' => 'A listing goes live on a portal', 'credit' => ['actorUserId', 'property.agent_id'], 'recorded_by' => [],
            'filters' => ['portal' => 'Which portal'],
        ],
        \App\Events\Marketing\MarketingPostPublished::class => [
            'label' => 'A marketing post is published', 'credit' => [], 'recorded_by' => [],
            'filters' => ['platform' => 'Which platform'],
        ],
        // Deal events credit EVERY agent on the deal (listing and selling side), never the person who clicked (road-to-sold.md 22.8):
        // 'agents' => 'deal' tells TaskCompletionService to read the deal's agents; the clicker is only recorded_by.
        \App\Events\Deal\DealCreated::class => [
            'label' => 'A deal is opened', 'credit' => [], 'recorded_by' => [], 'filters' => [], 'agents' => 'deal',
        ],
        \App\Events\Deal\DealClosed::class => [
            'label' => 'A deal is closed', 'credit' => [], 'recorded_by' => [], 'agents' => 'deal',
            'filters' => ['outcome' => 'How it closed (won, lost or abandoned)'],
        ],
        \App\Events\Deal\DealStageAdvanced::class => [
            'label' => 'A deal moves to a later stage', 'credit' => [], 'recorded_by' => [], 'agents' => 'deal',
            'filters' => ['toStage' => 'The stage it moved to'],
        ],
        \App\Events\Deal\DealCommissionFinalised::class => [
            'label' => 'A deal\'s commission is paid', 'credit' => [], 'recorded_by' => [], 'filters' => [], 'agents' => 'deal',
        ],
        // Road to Sold v3 (22.8): the Sell Stock events that fire but could not be bound to a task until now.
        \App\Events\Presentation\PresentationOutcomeRecorded::class => [
            'label' => 'A presentation gets its outcome', 'credit' => [], 'recorded_by' => [],
            'filters' => ['outcome' => 'The outcome (for example won_mandate or lost_to_competitor)'],
        ],
        \App\Events\Mandate\MandateSigned::class => [
            'label' => 'A mandate is e-signed', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Marketing\SellerLinkCreated::class => [
            'label' => 'A seller live link is made', 'credit' => [], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Marketing\SellerLinkOpened::class => [
            'label' => 'A seller opens their live link', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Marketing\ViewingPackCreated::class => [
            'label' => 'A viewing pack is made', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Contact\ContactMatchCreated::class => [
            'label' => 'A Core Match is set up for a buyer', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Viewing\ViewingScheduled::class => [
            'label' => 'A viewing is scheduled', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Viewing\ViewingHeld::class => [
            'label' => 'A viewing is held and its feedback captured', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Docuperfect\OtpSigned::class => [
            'label' => 'An offer to purchase is e-signed', 'credit' => ['userId'], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Contact\ContactContactedByAgent::class => [
            'label' => 'An agent contacts a person', 'credit' => [], 'recorded_by' => [],
            'filters' => ['channel' => 'How (contacted_action, message, shared_link or appointment_feedback)'],
        ],
        \App\Events\Prospecting\ClaimCreated::class => [
            'label' => 'A Market Intelligence claim is taken', 'credit' => [], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\Prospecting\ClaimFeedbackRecorded::class => [
            'label' => 'Feedback is recorded on a claim', 'credit' => [], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\SellerOutreach\PitchSent::class => [
            'label' => 'A seller pitch is sent', 'credit' => [], 'recorded_by' => [], 'filters' => [],
        ],
        \App\Events\SellerOutreach\OutreachOutcomeUpdated::class => [
            'label' => 'The outcome of a pitch is logged', 'credit' => [], 'recorded_by' => [], 'filters' => [],
        ],
    ];

    /**
     * Where an agent goes to do the work that completes a core task (phase 2: the "do this next" button). event class => route name.
     * Only routes that exist are ever linked (screenUrl()).
     */
    public const SCREENS = [
        \App\Events\Prospecting\OwnerLinkedToTrackedProperty::class => 'corex.deeds-capture.index',
        \App\Events\RoadToSold\RoadToSoldOutcomeRecorded::class     => 'road-to-sold.index',
        \App\Events\Evaluation\EvaluationBooked::class              => 'command-center.calendar',
        \App\Events\Evaluation\EvaluationCompleted::class           => 'command-center.calendar',
        \App\Events\Presentation\PresentationPresented::class       => 'presentations.index',
        \App\Events\Document\DocumentFiledToProperty::class         => 'corex.properties.index',
        \App\Events\Property\PropertyWentLiveOnPortal::class        => 'corex.properties.index',
        \App\Events\Marketing\MarketingPostPublished::class         => 'corex.properties.index',
        \App\Events\Deal\DealCreated::class                         => 'deals-dr2.index',
        \App\Events\Deal\DealClosed::class                          => 'deals-dr2.index',
        \App\Events\Deal\DealStageAdvanced::class                   => 'deals-dr2.index',
        \App\Events\Deal\DealCommissionFinalised::class             => 'deals-dr2.index',
        \App\Events\Presentation\PresentationOutcomeRecorded::class => 'presentations.index',
        \App\Events\Mandate\MandateSigned::class                    => 'corex.properties.index',
        \App\Events\Viewing\ViewingScheduled::class                 => 'command-center.calendar',
        \App\Events\Viewing\ViewingHeld::class                      => 'command-center.calendar',
        \App\Events\Contact\ContactMatchCreated::class              => 'corex.core-matches.index',
    ];

    public static function screenUrl(?string $eventClass): ?string
    {
        $route = $eventClass ? (self::SCREENS[$eventClass] ?? null) : null;

        return $route && \Illuminate\Support\Facades\Route::has($route) ? route($route) : null;
    }

    /**
     * The neutral recipe a new agency starts from (spec 5.5). Level 1 holds a small set; the rest are in the task list ready to be
     * placed in a level, and levels 2 and 3 are empty shells. `level1` = [target per period, points] or null (not in Level 1).
     *
     * @return array<int,array{key:string,step:string,label:string,event:string,filter:array<string,string>,unit:string,level1:?array{0:int,1:float}}>
     */
    public static function defaultTasks(): array
    {
        return [
            ['key' => 'owner-found',        'step' => 'find_owner',        'label' => 'Find the owner of a property',        'event' => \App\Events\Prospecting\OwnerLinkedToTrackedProperty::class, 'filter' => [], 'unit' => 'week',  'level1' => null],
            ['key' => 'call-outcome',       'step' => 'contact_outcome',   'label' => 'Record a result for each call',       'event' => \App\Events\RoadToSold\RoadToSoldOutcomeRecorded::class,     'filter' => [], 'unit' => 'day',   'level1' => [10, 1.0]],
            ['key' => 'evaluation-booked',  'step' => 'evaluation_booked', 'label' => 'Book an evaluation',                  'event' => \App\Events\Evaluation\EvaluationBooked::class,              'filter' => [], 'unit' => 'week',  'level1' => [1, 5.0]],
            ['key' => 'evaluation-done',    'step' => 'evaluation_booked', 'label' => 'Do an evaluation',                    'event' => \App\Events\Evaluation\EvaluationCompleted::class,           'filter' => [], 'unit' => 'week',  'level1' => null],
            ['key' => 'presentation-given', 'step' => 'presentation',      'label' => 'Present to a seller',                 'event' => \App\Events\Presentation\PresentationPresented::class,       'filter' => [], 'unit' => 'month', 'level1' => null],
            ['key' => 'mandate-filed',      'step' => 'mandate',           'label' => 'File a signed mandate',               'event' => \App\Events\Document\DocumentFiledToProperty::class,         'filter' => ['documentTypeSlug' => 'mandate'], 'unit' => 'month', 'level1' => [1, 10.0]],
            ['key' => 'listing-live',       'step' => 'listing_live',      'label' => 'Take a listing live',                 'event' => \App\Events\Property\PropertyWentLiveOnPortal::class,        'filter' => [], 'unit' => 'month', 'level1' => null],
            ['key' => 'post-published',     'step' => 'marketing',         'label' => 'Publish a marketing post',            'event' => \App\Events\Marketing\MarketingPostPublished::class,         'filter' => [], 'unit' => 'week',  'level1' => null],
            ['key' => 'otp-filed',          'step' => 'otp',               'label' => 'File a signed offer to purchase',     'event' => \App\Events\Document\DocumentFiledToProperty::class,         'filter' => ['documentTypeSlug' => 'otp'], 'unit' => 'month', 'level1' => null],
            ['key' => 'deal-opened',        'step' => 'deal',              'label' => 'Open a deal',                         'event' => \App\Events\Deal\DealCreated::class,                         'filter' => [], 'unit' => 'month', 'level1' => null],
            ['key' => 'deal-won',           'step' => 'deal',              'label' => 'Close a deal',                        'event' => \App\Events\Deal\DealClosed::class,                          'filter' => ['outcome' => 'won'], 'unit' => 'month', 'level1' => null],
        ];
    }

    /** The three neutral levels: number => [name, description]. Level 1 is seeded with tasks; 2 and 3 are shells for the agency to fill. */
    public const DEFAULT_LEVELS = [
        1 => ['Level 1', 'Where every new agent starts'],
        2 => ['Level 2', null],
        3 => ['Level 3', null],
    ];

    public static function eventLabel(?string $class): string
    {
        return $class && isset(self::EVENTS[$class]) ? self::EVENTS[$class]['label'] : 'Credited by CoreX (not tied to one event)';
    }

    /** @return array<string,string> class => label, for a picker */
    public static function eventChoices(): array
    {
        $out = [];
        foreach (self::EVENTS as $class => $meta) {
            $out[$class] = $meta['label'];
        }
        asort($out);

        return $out;
    }

    /** The event's own facts a filter may be checked against: its public scalar properties and its context(). @return array<string,scalar|null> */
    public static function facts(DomainEvent $event): array
    {
        $facts = [];
        foreach (self::scalarProperties($event) as $name => $value) {
            $facts[$name] = $value;
        }
        foreach ($event->context() as $name => $value) {
            if (is_scalar($value) || $value === null) {
                $facts[(string) $name] = $value;
            }
        }

        return $facts;
    }

    /** Does the event satisfy the task's filter? An empty filter always does; every named fact must equal (case-insensitive, trimmed). */
    public static function matches(DomainEvent $event, ?array $filter): bool
    {
        if (! $filter) {
            return true;
        }
        $facts = self::facts($event);
        foreach ($filter as $fact => $wanted) {
            if (! array_key_exists($fact, $facts)) {
                return false;
            }
            if (mb_strtolower(trim((string) $facts[$fact])) !== mb_strtolower(trim((string) $wanted))) {
                return false;
            }
        }

        return true;
    }

    /** First non-empty integer among the named properties of the event (dotted = into a model). */
    public static function firstId(DomainEvent $event, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = $event;
            foreach (explode('.', $path) as $segment) {
                $value = is_object($value) && (isset($value->{$segment}) || property_exists($value, $segment)) ? ($value->{$segment} ?? null) : null;
                if ($value === null) {
                    break;
                }
            }
            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    /** @return array<string,scalar|null> */
    private static function scalarProperties(object $event): array
    {
        $out = [];
        foreach ((new \ReflectionObject($event))->getProperties(\ReflectionProperty::IS_PUBLIC) as $property) {
            $name = $property->getName();
            if (in_array($name, ['eventId', 'occurredAt', 'traceId', 'socket'], true) || ! $property->isInitialized($event)) {
                continue;
            }
            $value = $property->getValue($event);
            if (is_scalar($value) || $value === null) {
                $out[$name] = $value;
            }
        }

        return $out;
    }

    /** Which fact names a task of this event may filter on (empty = none). @return array<string,string> */
    public static function filtersFor(?string $class): array
    {
        return $class && isset(self::EVENTS[$class]) ? self::EVENTS[$class]['filters'] : [];
    }

    /** True when the class is an event a core task may be bound to. */
    public static function isSelectable(?string $class): bool
    {
        return $class !== null && isset(self::EVENTS[$class]);
    }

    public static function typeLabel(string $type): string
    {
        return $type === RtsTask::TYPE_CORE ? 'Automatic' : 'Captured';
    }
}
