<?php

namespace App\Services\Performance\Providers;

use App\Services\Performance\AgentActivityFilter;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AT-366-A reference provider — contacts created per agent (category 4).
 *
 * Ships in the foundation to prove the provider → rollup pipeline end-to-end.
 * AT-366-B adds the remaining categories alongside it in the registry.
 *
 * 2026-08 (stale attribution fix, Johan-confirmed) — attributed by the CURRENT
 * owner (agent_id), not the original creator (created_by_user_id). A contact
 * that changed hands now counts toward whoever is working it today, matching
 * BuyersAddedProvider (same 'contacts' table, same agent_id column) — the two
 * were inconsistent before this: buyers_added already followed the current
 * owner while contacts_created stayed stuck on the creator.
 */
class ContactsCreatedProvider extends AbstractCountMetricProvider
{
    public function key(): string
    {
        return 'contacts_created';
    }

    public function label(): string
    {
        return 'Contacts created';
    }

    protected function table(): string
    {
        return 'contacts';
    }

    protected function userColumn(): string
    {
        return 'agent_id';
    }

    protected function periodColumn(): string
    {
        return 'created_at';
    }

    protected function baseQuery(): Builder
    {
        // AT-366 correctness: exclude untouched bulk imports (count native or worked-since-import).
        return AgentActivityFilter::contacts(DB::table('contacts')->whereNull('deleted_at'));
    }
}
