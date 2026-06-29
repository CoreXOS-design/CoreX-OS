<?php

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Hides rows whose linked property has been soft-deleted (or hard-removed).
 *
 * Command Center tasks and events are derived from / attached to a property
 * (document-expectation tasks, "needs attention" prompts, viewings). When the
 * property is soft-deleted it "no longer exists" to the agent, so its work
 * items must stop surfacing on Today / Tasks / Calendar / reminders — otherwise
 * the agent drowns in overdue noise for properties that are gone.
 *
 * Rows with a NULL property_id (standalone tasks/events) are unaffected.
 *
 * Self-healing and symmetric: this is a read-time guard, not a cascade. The row
 * is never destroyed — restore the property and the item reappears
 * automatically. No backfill is needed for already-orphaned rows; they vanish
 * the moment this scope is registered.
 *
 * Mirrors the existing global-scope isolation pattern on these models
 * (AgencyScope, BranchScope). Escape hatch for recovery / trashed-property
 * detail contexts: ->withoutGlobalScope(LivePropertyScope::class).
 */
class LivePropertyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $table = $model->getTable();

        $builder->where(function (Builder $q) use ($table) {
            $q->whereNull("{$table}.property_id")
              ->orWhereExists(function ($sub) use ($table) {
                  $sub->selectRaw('1')
                      ->from('properties')
                      ->whereColumn('properties.id', "{$table}.property_id")
                      ->whereNull('properties.deleted_at');
              });
        });
    }
}
