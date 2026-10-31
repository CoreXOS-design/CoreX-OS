<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Row journal for the MIC data steps (.ai/specs/mic-work-tab.md 11.1, AT-519). One row per column changed by a backfill run
 * (run_id groups a run), so `--rollback=<run_id>` can put the old value back. Written with plain inserts by the services; an
 * internal audit table - never shown to users, never soft-deleted.
 */
class MicDataJournal extends Model
{
    public $timestamps = false;

    protected $table = 'mic_data_journals';

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];
}
