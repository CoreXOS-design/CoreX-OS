<?php

namespace App\Services\AttorneyPortal;

use App\Models\AttorneyPortalAccess;
use App\Models\AttorneyPortalLog;
use Illuminate\Http\Request;

/** The one writer of the append-only attorney portal log (spec §8). */
class AttorneyPortalLogger
{
    /**
     * @param array{deal_id?:int|null, document_id?:int|null, meta?:array} $ctx
     */
    public static function record(AttorneyPortalAccess $access, string $event, array $ctx = [], ?Request $request = null): AttorneyPortalLog
    {
        $request ??= request();

        return AttorneyPortalLog::create([
            'agency_id'   => $access->agency_id,
            'access_id'   => $access->id,
            'event'       => $event,
            'deal_id'     => $ctx['deal_id'] ?? null,
            'document_id' => $ctx['document_id'] ?? null,
            'ip'          => $request?->ip(),
            'user_agent'  => $request ? substr((string) $request->userAgent(), 0, 500) : null,
            'meta'        => $ctx['meta'] ?? null,
        ]);
    }
}
