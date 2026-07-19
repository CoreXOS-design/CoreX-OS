<?php

namespace App\Models\Concerns;

use App\Models\User;
use App\Support\ActingFor;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * AT-267 §11 — auto-stamps `on_behalf_of_user_id` on an audit row from the acting user's
 * Assigned Agent, when that user is an assistant.
 *
 * Add to any audit model that carries an actor column, alongside its existing actor field.
 * A single `creating` chokepoint means every write path (controller, service, listener)
 * records the on-behalf-of fact without each writer having to remember to — and it only ever
 * SETS a blank value, so an explicit assignment (should one ever exist) always wins.
 *
 * Only covers Eloquent writes; audit tables written via raw DB::table()->insert() must set
 * the column at the insert site (there are two: domain_event_log, marketing_share_log).
 */
trait StampsOnBehalfOf
{
    protected static function bootStampsOnBehalfOf(): void
    {
        static::creating(function ($model) {
            if (empty($model->on_behalf_of_user_id)) {
                $model->on_behalf_of_user_id = ActingFor::onBehalfOfUserId();
            }
        });
    }

    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_user_id');
    }
}
