<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Concerns\AuthorizesPropertyAccess;
use App\Http\Controllers\Controller;
use App\Models\CommandCenter\CommandTask;
use App\Models\OtherAgencyStockUnlock;
use App\Models\Property;
use App\Models\User;
use App\Notifications\OtherAgencyStockUnlockDecidedNotification;
use App\Notifications\OtherAgencyStockUnlockRequestedNotification;
use App\Services\Properties\OtherAgencyStockStatusGate;
use Illuminate\Http\Request;
use App\Services\CommandCenter\NotificationDispatcher;

/**
 * .ai/specs/other-agency-stock.md §8a — the request → approve/decline →
 * relock flow that temporarily unlocks an Other Agency Stock property's
 * imported advert content for editing. All state is append-only
 * (OtherAgencyStockUnlock); current state is always derived from the latest
 * row, never a separate mutable column.
 */
class OtherAgencyStockUnlockController extends Controller
{
    use AuthorizesPropertyAccess;

    public function request(Request $request, Property $property)
    {
        $this->authorizeProperty($property, forEdit: false);
        abort_unless($property->isOtherAgencyStock(), 404);

        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);

        $current = OtherAgencyStockUnlock::currentStateFor($property);
        if ($current['state'] !== 'locked') {
            return back()->with('error', 'This property is already ' . $current['state'] . ' — no need to request again.');
        }

        /** @var User $user */
        $user = auth()->user();

        $unlockRequest = OtherAgencyStockUnlock::create([
            'agency_id'             => $property->agency_id,
            'property_id'           => $property->id,
            'event_type'            => OtherAgencyStockUnlock::EVENT_REQUESTED,
            'requested_by_user_id'  => $user->id,
            'reason'                => $data['reason'] ?? null,
        ]);

        $authorisedUsers = OtherAgencyStockStatusGate::authorisedUsersFor((int) $property->agency_id);

        if ($authorisedUsers->isNotEmpty()) {
            // Through the notification gateway (preference, open hours, ledger) - one send per authorised user.
            foreach ($authorisedUsers as $recipient) {
                app(NotificationDispatcher::class)->send(
                    $recipient, 'other_agency_stock.unlock_requested', $unlockRequest,
                    new OtherAgencyStockUnlockRequestedNotification($unlockRequest, $property, $user),
                    ['threshold_hit_at' => now()],
                );
            }

            foreach ($authorisedUsers as $authorisedUser) {
                CommandTask::create([
                    'title'         => "{$user->name} wants to edit Other Agency Stock",
                    'description'   => $data['reason'] ?? null,
                    'task_type'     => 'other_agency_stock_unlock_request',
                    'status'        => CommandTask::STATUS_TODO,
                    'priority'      => 'normal',
                    'assigned_to'   => $authorisedUser->id,
                    'assigned_by'   => $user->id,
                    'property_id'   => $property->id,
                    'source_type'   => 'other_agency_stock_unlock',
                    'source_id'     => $unlockRequest->id,
                    'agency_id'     => $property->agency_id,
                    'branch_id'     => $property->branch_id,
                ]);
            }
        }

        return back()->with('success', 'Edit request sent to an authorised user.');
    }

    public function decide(Request $request, Property $property, OtherAgencyStockUnlock $unlock)
    {
        /** @var User $user */
        $user = auth()->user();
        abort_unless(OtherAgencyStockStatusGate::canAuthoriseAdvertEdit($user), 403);
        abort_unless((int) $unlock->property_id === (int) $property->id, 404);
        abort_unless($unlock->event_type === OtherAgencyStockUnlock::EVENT_REQUESTED, 404);

        $current = OtherAgencyStockUnlock::currentStateFor($property);
        if ($current['state'] !== 'pending' || (int) $current['row']->id !== (int) $unlock->id) {
            return back()->with('error', 'This request is no longer pending.');
        }

        $data = $request->validate([
            'approve' => ['required', 'boolean'],
        ]);

        $decision = OtherAgencyStockUnlock::create([
            'agency_id'          => $property->agency_id,
            'property_id'        => $property->id,
            'event_type'         => $data['approve'] ? OtherAgencyStockUnlock::EVENT_APPROVED : OtherAgencyStockUnlock::EVENT_DECLINED,
            'request_id'         => $unlock->id,
            'decided_by_user_id' => $user->id,
        ]);

        if ($unlock->requestedBy) {
            app(NotificationDispatcher::class)->send(
                $unlock->requestedBy, 'other_agency_stock.unlock_decided', $decision,
                new OtherAgencyStockUnlockDecidedNotification($decision, $property, $user),
                ['threshold_hit_at' => now()],
            );
        }

        CommandTask::where('source_type', 'other_agency_stock_unlock')
            ->where('source_id', $unlock->id)
            ->where('status', CommandTask::STATUS_TODO)
            ->get()
            ->each->update(['status' => CommandTask::STATUS_DONE, 'completed_at' => now()]);

        return back()->with('success', $data['approve'] ? 'Unlocked for the property agent.' : 'Request declined.');
    }

    public function relock(Request $request, Property $property)
    {
        /** @var User $user */
        $user = auth()->user();
        abort_unless(OtherAgencyStockStatusGate::canAuthoriseAdvertEdit($user), 403);
        abort_unless($property->isOtherAgencyStock(), 404);

        OtherAgencyStockUnlock::create([
            'agency_id'            => $property->agency_id,
            'property_id'          => $property->id,
            'event_type'           => OtherAgencyStockUnlock::EVENT_RELOCKED,
            'relocked_by_user_id'  => $user->id,
        ]);

        return back()->with('success', 'Locked.');
    }
}
