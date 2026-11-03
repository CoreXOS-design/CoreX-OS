<?php

namespace App\Services\RoadToSold;

use App\Models\RoadToSold\RtsProspect;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Road to Sold v3 - the pop-up step of a prospect (road-to-sold.md section 22.9 / 22.10).
 *
 * One place knows which EXISTING CoreX screen a stage's next step opens, so the page never hard-codes a link and a stage can be
 * re-pointed in one line. A step is opened in the Road to Sold pop-up (an iframe of the real screen, embed mode, pre-filled with the
 * property / contact through the screen's own address). The route that serves it (road-to-sold.prospects.step) checks the prospect is the
 * viewer's own, so a screen is never reachable for someone else's prospect.
 */
final class ProspectStepService
{
    /** step key => the label on the button. */
    public const LABELS = [
        'find_owner' => 'Find owner',
        'pitch'      => 'Pitch',
        'evaluation' => 'Book appointment',
        'evaluation_feedback' => 'Appointment feedback',
        'presentation' => 'Presentation',
        // the pitch popup's steps 7-9 (road-to-sold.md 23.25): the screens are the existing ones, reached through the popup
        'results'    => 'Presentation results',
        'draft'      => 'Move to Draft',
        'active'     => 'Make active',
        'mandate'    => 'Mandate',
        'listing'    => 'Listing',
        'advertise'  => 'Advertise',
        'lead'       => 'Lead',
        'core_match' => 'Core Match',
        'viewing'    => 'Viewing',
        'viewing_pack' => 'Viewing pack',
        'offer'      => 'Offer',
        'deal'       => 'Deal',
        'property'   => 'Open property',
    ];

    /** stage => the step that moves the prospect on. */
    private const NEXT = [
        'found'                 => 'pitch',
        'contacted'             => 'evaluation',
        'appointment_set'       => 'evaluation_feedback',
        'presentation_prepared' => 'results',
        'evaluation_done'       => 'presentation',
        'presentation_given'    => 'presentation',
        'presentation_feedback' => 'mandate',
        'mandate_signed'        => 'listing',
        'draft'                 => 'active',
        'listing_live'          => 'advertise',
        'advertising'           => 'lead',
        'lead'                  => 'lead',
        'lead_contacted'        => 'core_match',
        'core_match'            => 'viewing',
        'viewing_scheduled'     => 'viewing_pack',
        'viewing_pack'          => 'viewing',
        'viewing_feedback'      => 'offer',
        'offer'                 => 'deal',
        'deal'                  => 'deal',
        'registered'            => 'property',
    ];

    /**
     * The primary step of a prospect row: Find owner when nobody owns the property yet (a pitch needs someone to pitch to), otherwise the
     * step of the stage. @param array<string,mixed> $row a ProspectListService row, plus owner_contact_id when known
     * @return array{key:string,label:string}
     */
    public function primary(array $row, bool $hasOwner): array
    {
        $key = (! $hasOwner && in_array($row['stage'] ?? 'found', ['found', 'claimed'], true)) ? 'find_owner' : (self::NEXT[$row['stage'] ?? 'found'] ?? 'property');
        if ($key === 'pitch' && ! $hasOwner) {
            $key = 'find_owner';
        }

        return ['key' => $key, 'label' => self::LABELS[$key]];
    }

    /** @param int[] $trackedIds @return array<int,int> tracked property id => owner contact id (only those with an owner) */
    public function ownersOf(array $trackedIds): array
    {
        if ($trackedIds === []) {
            return [];
        }

        return DB::table('tracked_properties')->whereIn('id', $trackedIds)->whereNotNull('owner_contact_id')->pluck('owner_contact_id', 'id')
            ->map(fn ($v) => (int) $v)->all();
    }

    /**
     * The address of the screen for one step, with the pop-up parameters. Null when the step has no screen for this prospect (the page then
     * shows the step as unavailable instead of a dead link).
     */
    public function urlFor(RtsProspect $p, string $step): ?string
    {
        $tp = (int) $p->tracked_property_id;
        $property = $p->property_id ? (int) $p->property_id : null;
        $owner = $this->ownersOf([$tp])[$tp] ?? null;
        $embed = ['embed' => 1, 'rts_prospect' => $p->id, 'rts_stage' => $p->stage];
        $r = fn (string $name, int|array $params = [], array $query = []) => Route::has($name) ? route($name, (array) $params, true) . '?' . http_build_query($embed + $query) : null;
        $propertyOrTracked = fn () => $property
            ? $r('corex.properties.show', $property)
            : $r('corex.tracked-properties.show', $tp);

        return match ($step) {
            // the pitch path (22.10, PitchPathService): owner known -> the composer on that contact and the property (made from the MIC address
            // first when there is none); nobody -> Find owner. One answer for both buttons, so a row never dead-ends.
            'find_owner', 'pitch' => $r('road-to-sold.prospects.pitch-path', $p->id),
            // v4 drop 4 (23.23): the calendar's own evaluation form, prefilled with the property and the owner, inside the pitch popup
            'evaluation' => $r('road-to-sold.prospects.evaluation-path', $p->id),
            // v4 (23.10): once it is booked, the next thing is saying how it went - the calendar's own feedback form (held / no-show / moved) on THAT appointment.
            // No live appointment to answer for (it was cancelled): the booking form instead, so the button never dead-ends.
            'evaluation_feedback' => ($eventId = app(BeltLinks::class)->latestEvaluationEvent((int) $p->id))
                ? $r('command-center.calendar', [], ['capture_feedback' => $eventId])
                : $r('road-to-sold.prospects.evaluation-path', $p->id),
            'viewing' => $r('command-center.calendar', [], $property ? ['property_id' => $property] : []),
            // v4 (23.8): the real generator, on this property - never the presentations list (presentations.create only redirects there)
            'presentation' => $r('road-to-sold.prospects.presentation-path', $p->id),
            // 23.25 steps 7-9: the existing feedback / Result form, and the property's draft and active gates, each one hop from the record
            'results' => $r('road-to-sold.prospects.results-path', $p->id),
            'draft', 'active' => $property ? $r('road-to-sold.prospects.property-step', [$p->id, $step]) : null,
            'mandate', 'offer' => $r('docuperfect.esign.create', [], $property ? ['property_id' => $property] : []),
            'listing', 'property' => $propertyOrTracked(),
            'advertise'    => $property ? $r('corex.properties.marketing.index', $property) : $r('tools.ad-manager'),
            'lead'         => $r('corex.portal-leads.index'),
            'core_match'   => $r('corex.core-matches.index'),
            'viewing_pack' => $r('corex.viewing-packs.index'),
            'deal'         => $r('deals-dr2.create', [], $property ? ['property_id' => $property] : []),
            default        => null,
        };
    }

    public function exists(string $step): bool
    {
        return isset(self::LABELS[$step]);
    }
}
