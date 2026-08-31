<?php

namespace App\Http\Controllers\CoreX;

use App\Http\Controllers\Controller;
use App\Models\MobilePhotoEvent;
use App\Models\Property;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * "Where did my photos go?" — the report.
 *
 * Answers the one question nginx logs cannot: how many photos did the agent
 * actually TAKE. The server only ever sees survivors, so a photo that dies on
 * the phone before the upload queue is invisible. This page puts the app's own
 * account (captured / queued / attempted / failed / dropped) next to the
 * server's own record of arrival, and subtracts.
 *
 * Spec: .ai/specs/mobile-photo-upload-telemetry.md
 */
class PhotoUploadDiagnosticsController extends Controller
{
    public function index(Request $request): View
    {
        $propertyId = (int) $request->query('property', 0);

        return view('corex.diagnostics.photo-uploads', [
            'propertyId' => $propertyId,
            'property'   => $propertyId > 0 ? Property::find($propertyId) : null,
            'shoots'     => $propertyId > 0 ? collect() : $this->recentShoots(),
            'photos'     => $propertyId > 0 ? $this->photoTimeline($propertyId) : collect(),
            'summary'    => $propertyId > 0 ? $this->summary($propertyId) : null,
        ]);
    }

    /**
     * Recent shoots, worst first — a shoot that lost photos is the whole point of
     * the page, so it should not be something you have to go hunting for.
     */
    private function recentShoots()
    {
        $rows = MobilePhotoEvent::query()
            ->select('property_id', DB::raw('DATE(created_at) as day'))
            ->selectRaw("COUNT(DISTINCT CASE WHEN phase = 'captured' THEN client_upload_id END) as captured")
            ->selectRaw("COUNT(DISTINCT CASE WHEN phase = 'queued'   THEN client_upload_id END) as queued")
            ->selectRaw("COUNT(DISTINCT CASE WHEN phase = 'received' THEN client_upload_id END) as received")
            ->selectRaw("MIN(created_at) as started_at, MAX(created_at) as ended_at")
            ->groupBy('property_id', 'day')
            ->orderByDesc('day')
            ->orderByDesc(DB::raw('MAX(created_at)'))
            ->limit(60)
            ->get();

        $properties = Property::whereIn('id', $rows->pluck('property_id')->unique())
            ->get(['id', 'street_number', 'street_name', 'suburb', 'title'])
            ->keyBy('id');

        return $rows->map(function ($r) use ($properties) {
            $r->property = $properties[$r->property_id] ?? null;
            $r->missing  = max(0, (int) $r->captured - (int) $r->received);

            return $r;
        });
    }

    /** Every phase every photo reached, one row per photo. */
    private function photoTimeline(int $propertyId)
    {
        $events = MobilePhotoEvent::where('property_id', $propertyId)
            ->orderBy('client_upload_id')
            ->get();

        return $events->groupBy('client_upload_id')->map(function ($group, $key) {
            $byPhase = $group->keyBy('phase');

            $captured = $byPhase['captured']->occurred_at ?? null;
            $received = $byPhase[MobilePhotoEvent::PHASE_RECEIVED]->created_at ?? null;

            // The index the app stamps into its idempotency key (…_17). Sorting on
            // it puts a shoot back in shutter order, which is how the agent
            // remembers it — and makes a gap obvious at a glance.
            $index = preg_match('/_(\d+)$/', (string) $key, $m) ? (int) $m[1] : null;

            $failed = $byPhase['upload_failed'] ?? null;

            return (object) [
                'client_upload_id' => $key,
                'index'            => $index,
                'captured_at'      => $captured,
                'queued_at'        => $byPhase['queued']->occurred_at ?? null,
                'attempted_at'     => $byPhase['upload_started']->occurred_at ?? null,
                'received_at'      => $received,
                'dropped'          => isset($byPhase['dropped']),
                'error'            => $failed->meta['error'] ?? null,
                'room_tag'         => $byPhase[MobilePhotoEvent::PHASE_RECEIVED]->meta['room_tag'] ?? null,
                'lag_seconds'      => ($captured && $received) ? $received->diffInSeconds($captured) : null,
                'status'           => $this->statusFor($byPhase),
            ];
        })->sortBy(fn ($p) => $p->index ?? PHP_INT_MAX)->values();
    }

    /**
     * The verdict for one photo. Order matters: arrival beats everything (a photo
     * that landed is fine no matter how ugly the road was), and "never queued" is
     * called out separately from "queued but never arrived" because they are two
     * completely different bugs in two different places.
     */
    private function statusFor($byPhase): string
    {
        if (isset($byPhase[MobilePhotoEvent::PHASE_RECEIVED])) {
            return 'landed';
        }
        if (isset($byPhase['dropped'])) {
            return 'dropped by app';
        }
        if (isset($byPhase['upload_failed'])) {
            return 'upload failed';
        }
        if (isset($byPhase['queued'])) {
            return 'queued, never arrived';
        }
        if (isset($byPhase['captured'])) {
            return 'never queued';
        }

        return 'unknown';
    }

    private function summary(int $propertyId): array
    {
        $distinct = fn (string $phase) => MobilePhotoEvent::where('property_id', $propertyId)
            ->where('phase', $phase)
            ->distinct()
            ->count('client_upload_id');

        $captured = $distinct('captured');
        $received = $distinct(MobilePhotoEvent::PHASE_RECEIVED);

        // A photo the agent deleted in review is NOT a lost photo. Since the app
        // began enqueuing at the shutter and draining without waiting for the
        // camera to close, `dropped` became a normal outcome — the agent shoots,
        // reviews, bins one. Counting those as "never arrived" would paint a
        // healthy shoot as broken and bury the real losses in noise, which is the
        // one thing this page exists not to do.
        $dropped = MobilePhotoEvent::where('property_id', $propertyId)
            ->where('phase', 'dropped')
            ->whereNotIn('client_upload_id', function ($q) use ($propertyId) {
                $q->select('client_upload_id')
                  ->from('mobile_photo_events')
                  ->where('property_id', $propertyId)
                  ->where('phase', MobilePhotoEvent::PHASE_RECEIVED);
            })
            ->distinct()
            ->count('client_upload_id');

        return [
            'captured' => $captured,
            'queued'   => $distinct('queued'),
            'received' => $received,
            'dropped'  => $dropped,
            'missing'  => max(0, $captured - $received - $dropped),
        ];
    }
}
