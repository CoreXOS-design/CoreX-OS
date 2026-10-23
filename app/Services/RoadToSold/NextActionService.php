<?php

namespace App\Services\RoadToSold;

/**
 * AT-454 v2 phase 2 - "do this next": the ONE most important thing for an agent now, with the control that does it
 * (road-to-sold.md section 9.4 advice and 10.1). Pure rules over figures already computed for the page - no queries, no writes:
 *
 *   1. things due today - an enquiry, a buyer or a mandate waiting (FeedService), the most time-critical first
 *   2. the day's call list - the next open property, when today's list still has some
 *   3. the unfinished task whose period ends soonest (ties: the larger gap)
 *   4. nothing left - everything due is met
 *
 * With nothing to do for someone other than the viewer (a manager looking at an agent) the card still names the thing, but carries
 * no button: only the agent, or their assistant, does the agent's work.
 */
final class NextActionService
{
    public const KIND_FOLLOW_UP = 'follow_up';
    public const KIND_CALL      = 'call';
    public const KIND_TASK      = 'task';
    public const KIND_DONE      = 'done';

    private const WHEN = ['hour' => 'this hour', 'day' => 'today', 'week' => 'this week', 'month' => 'this month'];

    /** The order of the three "waiting" feeds: an enquiry is the most time-critical. */
    private const FEED_ORDER = [
        'leads'    => 'Respond to',
        'buyers'   => 'Follow up',
        'mandates' => 'Review the mandate for',
    ];

    /**
     * @param array<string,mixed>      $progress  RecipeProgressService::forAgent()
     * @param array<string,array>      $feeds     FeedService blocks keyed leads|buyers|mandates (empty on another track)
     * @param array{open:int,first:?array} $list  open items left on today's list and the first of them
     * @return array{kind:string,title:string,detail:?string,action:?array<string,mixed>}
     */
    public function resolve(array $progress, array $feeds, array $list, bool $canAct): array
    {
        foreach (self::FEED_ORDER as $key => $verb) {
            $feed = $feeds[$key] ?? null;
            $first = $feed['items'][0] ?? null;
            if ($feed && (int) $feed['count'] > 0 && $first) {
                return [
                    'kind'   => self::KIND_FOLLOW_UP,
                    'title'  => $verb . ' ' . $first['title'],
                    'detail' => (int) $feed['count'] > 1 ? ((int) $feed['count'] - 1) . ' more waiting' : null,
                    'action' => $first['url'] ? ['type' => 'link', 'label' => 'Open', 'url' => $first['url']] : null,
                ];
            }
        }

        $firstRow = $list['first'] ?? null;
        if ((int) ($list['open'] ?? 0) > 0 && $firstRow) {
            return [
                'kind'   => self::KIND_CALL,
                'title'  => 'Call ' . $firstRow['address'],
                'detail' => (int) $list['open'] > 1 ? ((int) $list['open'] - 1) . ' more on today\'s list' : null,
                'action' => $canAct ? [
                    'type' => 'contact', 'label' => 'Contacted client',
                    'tp' => $firstRow['tracked_property_id'], 'item' => $firstRow['list_item_id'], 'address' => $firstRow['address'],
                ] : null,
            ];
        }

        // The call-result task is done through the day's list (rule 2), so it is never offered as a separate thing to go and do.
        $due = collect($progress['tasks'] ?? [])
            ->filter(fn ($t) => $t['target'] > 0 && $t['actual'] < $t['target'] && ($t['event_key'] ?? null) !== \App\Events\RoadToSold\RoadToSoldOutcomeRecorded::class)
            ->sortBy(fn ($t) => [$t['period_end']->getTimestamp(), -($t['target'] - $t['actual']), $t['task_id']])
            ->first();
        if ($due) {
            $left = $due['target'] - $due['actual'];
            $action = null;
            if ($canAct) {
                if ($due['is_custom']) {
                    $action = ['type' => 'capture', 'label' => 'Capture', 'task_id' => $due['task_id']];
                } elseif ($url = RecipeCatalogue::screenUrl($due['event_key'])) {
                    $action = ['type' => 'link', 'label' => 'Go', 'url' => $url];
                }
            }

            return [
                'kind'   => self::KIND_TASK,
                'title'  => $due['label'],
                'detail' => $left . ' to go ' . (self::WHEN[$due['unit_label']] ?? 'this ' . $due['unit_label']),
                'action' => $action,
            ];
        }

        return ['kind' => self::KIND_DONE, 'title' => 'Everything due is done', 'detail' => null, 'action' => null];
    }
}
