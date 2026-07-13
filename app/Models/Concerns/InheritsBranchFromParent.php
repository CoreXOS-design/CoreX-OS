<?php

namespace App\Models\Concerns;

/**
 * Branch inheritance for CHILD records.
 *
 * `BelongsToBranch` auto-stamps `branch_id` from the ACTING user's branch. That is
 * correct for a record the user creates in their own right (a property, a contact),
 * but wrong for a child record whose parent may live in a different branch: a
 * principal in Margate adding a money line to a Port Shepstone deal would stamp the
 * line "Margate", and under Split Branches that line would then be invisible to the
 * Shepstone agents whose deal it belongs to.
 *
 * A child's branch is its PARENT's branch. Always. Use this trait alongside
 * `BelongsToBranch` — declare it AFTER it, so this listener registers second and
 * overrides the acting-user default:
 *
 *     use BelongsToBranch, InheritsBranchFromParent;
 *
 *     protected function branchParent(): array
 *     {
 *         return [\App\Models\Deal::class, 'deal_id'];
 *     }
 *
 * The parent is read WITHOUT global scopes on purpose: we are resolving where the
 * row belongs, not what the current viewer is allowed to see. Scoping the lookup
 * would make it return nothing exactly when the parent is in another branch — the
 * one case this trait exists to handle.
 */
trait InheritsBranchFromParent
{
    /** @return array{0: class-string, 1: string} [parent model, foreign key on this model] */
    abstract protected function branchParent(): array;

    protected static function bootInheritsBranchFromParent(): void
    {
        static::creating(function ($model) {
            [$parentClass, $foreignKey] = $model->branchParent();

            $parentId = $model->{$foreignKey} ?? null;
            if (!$parentId) {
                return;   // orphan child — fall back to whatever BelongsToBranch stamped
            }

            $branchId = $parentClass::withoutGlobalScopes()
                ->whereKey($parentId)
                ->value('branch_id');

            if ($branchId) {
                $model->branch_id = $branchId;
            }
        });
    }
}
