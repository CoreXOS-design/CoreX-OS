<?php

namespace App\Models\Scopes;

use App\Support\PlatformEsignMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * AT-447 — keeps CoreX's own contract templates (is_platform = 1) out of every
 * customer-facing template query, and (in Platform E-Sign mode) shows ONLY them.
 *
 * Unauthenticated contexts (a public signing link, console, queue jobs) are left
 * alone: they reach a template through a token / job payload, never by browsing.
 */
class PlatformTemplateScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (!Auth::hasUser() && !Auth::check()) {
            return;
        }

        $builder->where($model->getTable() . '.is_platform', PlatformEsignMode::active() ? 1 : 0);
    }
}
