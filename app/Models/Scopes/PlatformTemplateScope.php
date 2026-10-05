<?php

namespace App\Models\Scopes;

use App\Support\PlatformEsignMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * AT-447 — keeps CoreX's own contract templates (is_platform = 1) out of every query except two:
 *   - Platform E-Sign mode (shows ONLY them), and
 *   - a signing link reached by token, and console / queue work (finalising a signed contract).
 * Everything else — logged-in users, API / bearer principals, anonymous web requests — gets
 * is_platform = 0. This is deliberately "closed by default": the previous version skipped the filter
 * whenever the default web guard had no user, which a stateless API principal would slip through.
 */
class PlatformTemplateScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (PlatformEsignMode::active()) {
            $builder->where($model->getTable() . '.is_platform', 1);

            return;
        }

        // External signing / download links are authorised by their token.
        if (PlatformEsignMode::onTokenRoute()) {
            return;
        }

        // Queue workers and artisan (e.g. sealing a signed contract) have no request. PHPUnit also runs
        // "in console", so tests are treated as real requests.
        if (app()->runningInConsole() && !app()->runningUnitTests()) {
            return;
        }

        $builder->where($model->getTable() . '.is_platform', 0);
    }
}
