<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Models\Scopes\BranchScope;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Phase-2 branch-isolation mixin for models that live below an agency
 * partition and can optionally be further isolated by branch when the
 * agency's `split_branches_enabled` toggle is on.
 *
 * Usage: add `use BelongsToBranch` to any model whose table carries a
 * `branch_id` column and whose records should be branch-scoped under
 * Split Branches. Shared-scope models (document_templates, training
 * courses, kb_documents, announcements, commission plans — see spec §7)
 * must NOT use this trait.
 *
 * Behaviour:
 *   - Adds BranchScope globally. The scope is a no-op when Split is OFF
 *     for the agency or when the user holds `branches.view_all`.
 *   - Auto-fills `branch_id` on `creating` from the authenticated user's
 *     `effectiveBranchId()`. Only fills when left blank — explicit
 *     branch assignment (e.g. attaching a contact to a different branch)
 *     always wins.
 */
trait BelongsToBranch
{
    protected static function bootBelongsToBranch(): void
    {
        static::addGlobalScope(new BranchScope());

        static::creating(function ($model) {
            if (!empty($model->branch_id)) {
                return;
            }

            $user = Auth::user();
            if (!$user) {
                return;
            }

            $branchId = method_exists($user, 'effectiveBranchId')
                ? $user->effectiveBranchId()
                : ($user->branch_id ?? null);

            if ($branchId) {
                $model->branch_id = $branchId;
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * Escape hatch for legitimate cross-branch reads (console commands,
     * scheduled jobs, principal reporting aggregates). Request-code
     * callers should prefer granting `branches.view_all` to the role
     * that needs the bypass rather than reaching for this helper.
     */
    public function newQueryWithoutBranchScope()
    {
        return $this->newQuery()->withoutGlobalScope(BranchScope::class);
    }

    public static function queryWithoutBranchScope()
    {
        return (new static)->newQueryWithoutBranchScope();
    }
}
