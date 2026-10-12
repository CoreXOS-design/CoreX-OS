<?php

namespace App\Services\TitleDeeds;

use App\Models\Branch;
use App\Models\TitleDeeds\TitleDeedStorageLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/title-deed-register.md §14.4 - the agency's own list of places title deeds are kept. Add, rename, re-order,
 * restrict to a branch, archive, restore. Soft delete only; always the acting user's own agency.
 */
class TitleDeedLocationService
{
    public function create(User $user, array $data): TitleDeedStorageLocation
    {
        $agencyId = $this->agencyId($user);
        $name = $this->cleanName($data['name'] ?? '');
        $branchId = $this->branchOrNull($agencyId, $data['branch_id'] ?? null);

        return DB::transaction(function () use ($agencyId, $name, $branchId, $data, $user) {
            $this->assertNameFree($agencyId, $name, null);
            $location = TitleDeedStorageLocation::create([
                'agency_id' => $agencyId,
                'name' => $name,
                'branch_id' => $branchId,
                'sort_order' => isset($data['sort_order']) && $data['sort_order'] !== '' ? max(0, min(9999, (int) $data['sort_order'])) : $this->nextSort($agencyId),
            ]);
            $this->log($user, 'added', $location);

            return $location;
        });
    }

    public function update(User $user, TitleDeedStorageLocation $location, array $data): TitleDeedStorageLocation
    {
        $agencyId = $this->agencyId($user);
        abort_unless((int) $location->agency_id === $agencyId, 404);

        $name = array_key_exists('name', $data) ? $this->cleanName($data['name']) : $location->name;
        $branchId = array_key_exists('branch_id', $data) ? $this->branchOrNull($agencyId, $data['branch_id']) : $location->branch_id;

        return DB::transaction(function () use ($location, $agencyId, $name, $branchId, $data, $user) {
            $locked = TitleDeedStorageLocation::withTrashed()->lockForUpdate()->findOrFail($location->id);
            if (! $locked->trashed()) {
                $this->assertNameFree($agencyId, $name, $locked->id);
            }
            $before = $locked->only(['name', 'sort_order', 'branch_id']);
            $locked->update([
                'name' => $name,
                'branch_id' => $branchId,
                'sort_order' => array_key_exists('sort_order', $data) && $data['sort_order'] !== null && $data['sort_order'] !== ''
                    ? max(0, min(9999, (int) $data['sort_order'])) : $locked->sort_order,
            ]);
            $this->log($user, 'changed', $locked, $before);

            return $locked;
        });
    }

    public function archive(User $user, TitleDeedStorageLocation $location): void
    {
        abort_unless((int) $location->agency_id === $this->agencyId($user), 404);
        DB::transaction(function () use ($user, $location) {
            $locked = TitleDeedStorageLocation::lockForUpdate()->find($location->id);
            if ($locked) {   // already archived: absorbed
                $locked->delete();
                $this->log($user, 'archived', $locked);
            }
        });
    }

    public function restore(User $user, TitleDeedStorageLocation $location): void
    {
        $agencyId = $this->agencyId($user);
        abort_unless((int) $location->agency_id === $agencyId, 404);
        DB::transaction(function () use ($user, $location, $agencyId) {
            $locked = TitleDeedStorageLocation::onlyTrashed()->lockForUpdate()->find($location->id);
            if (! $locked) {
                return;   // not archived: absorbed
            }
            $this->assertNameFree($agencyId, $locked->name, $locked->id, 'Another location is already called "' . $locked->name . '". Rename one of them first.');
            $locked->restore();
            $this->log($user, 'restored', $locked);
        });
    }

    /** Active locations a deed of $branchId may be kept in, in the agency's own order. */
    public function optionsFor(int $agencyId, ?int $branchId = null)
    {
        return TitleDeedStorageLocation::withoutGlobalScopes()
            ->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->usableForBranch($branchId)->ordered()->get();
    }

    private function agencyId(User $user): int
    {
        $id = (int) $user->effectiveAgencyId();
        if (! $id) {
            throw ValidationException::withMessages(['name' => 'Switch into an agency first.']);
        }

        return $id;
    }

    private function cleanName(mixed $name): string
    {
        $name = trim(preg_replace('/\s+/', ' ', (string) $name) ?? '');
        if (mb_strlen($name) < 2) {
            throw ValidationException::withMessages(['name' => 'Give the location a name (at least 2 characters).']);
        }
        if (mb_strlen($name) > 120) {
            throw ValidationException::withMessages(['name' => 'That name is too long (120 characters at most).']);
        }

        return $name;
    }

    private function branchOrNull(int $agencyId, mixed $branchId): ?int
    {
        if ($branchId === null || $branchId === '' || (int) $branchId === 0) {
            return null;
        }
        $ok = Branch::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')->whereKey((int) $branchId)->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['branch_id' => 'Pick one of your own branches, or leave it for every branch.']);
        }

        return (int) $branchId;
    }

    private function assertNameFree(int $agencyId, string $name, ?int $exceptId, ?string $message = null): void
    {
        $taken = TitleDeedStorageLocation::withoutGlobalScopes()->where('agency_id', $agencyId)->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => $message ?? 'There is already a location called "' . $name . '".']);
        }
    }

    private function nextSort(int $agencyId): int
    {
        return (int) TitleDeedStorageLocation::withoutGlobalScopes()->where('agency_id', $agencyId)->max('sort_order') + 10;
    }

    private function log(User $user, string $what, TitleDeedStorageLocation $location, array $before = []): void
    {
        Log::info('Title deed storage location ' . $what, [
            'agency_id' => $location->agency_id, 'user_id' => $user->id, 'location_id' => $location->id,
            'name' => $location->name, 'before' => $before,
        ]);
    }
}
