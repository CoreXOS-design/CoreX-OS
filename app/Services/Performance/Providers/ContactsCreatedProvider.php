<?php

namespace App\Services\Performance\Providers;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * AT-366-A reference provider — contacts created per agent (category 4).
 *
 * Ships in the foundation to prove the provider → rollup pipeline end-to-end.
 * AT-366-B adds the remaining categories alongside it in the registry.
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
        return 'created_by_user_id';
    }

    protected function periodColumn(): string
    {
        return 'created_at';
    }

    protected function baseQuery(): Builder
    {
        return DB::table('contacts')->whereNull('deleted_at');
    }
}
