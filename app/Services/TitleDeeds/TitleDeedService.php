<?php

namespace App\Services\TitleDeeds;

use App\Exceptions\TitleDeedException;
use App\Models\Contact;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Property;
use App\Models\TitleDeeds\TitleDeed;
use App\Models\TitleDeeds\TitleDeedAuditLog;
use App\Models\TitleDeeds\TitleDeedOwner;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/title-deed-register.md §6/§7 — create, edit, archive, restore, scan. Status NEVER changes here: it moves only
 * through the notice / handover services. Everything that writes more than one row runs in one transaction.
 */
class TitleDeedService
{
    /**
     * @param array{property_id:int, owner_ids:array<int,int>, agent_id?:?int, branch_id?:?int, attorney_firm?:?string,
     *              received_at:string, storage_location?:?string, notes?:?string} $data
     */
    public function create(User $user, array $data): TitleDeed
    {
        $agencyId = $user->effectiveAgencyId();
        if (! $agencyId) {
            throw ValidationException::withMessages(['property_id' => 'Switch into an agency first - a title deed has to belong to one.']);
        }

        $property = $this->resolveProperty($user, (int) ($data['property_id'] ?? 0));
        $owners = $this->resolveOwners($data['owner_ids'] ?? []);
        [$branchId, $agentId] = $this->resolveBranchAndAgent($user, $property, $data['branch_id'] ?? null, $data['agent_id'] ?? null);

        return DB::transaction(function () use ($user, $agencyId, $property, $owners, $branchId, $agentId, $data) {
            $deed = TitleDeed::create([
                'agency_id' => $agencyId,
                'branch_id' => $branchId,
                'agent_id' => $agentId,
                'created_by' => $user->id,
                'property_id' => $property->id,
                'property_address' => mb_substr((string) $property->buildDisplayAddress(), 0, 255),
                'attorney_firm' => $this->clean($data['attorney_firm'] ?? null, 255),
                'received_at' => $data['received_at'],
                'storage_location' => $this->clean($data['storage_location'] ?? null, 255),
                'notes' => $this->clean($data['notes'] ?? null, 5000),
                'status' => TitleDeed::STATUS_RECEIVED,
            ]);

            $this->syncOwners($deed, $owners);

            TitleDeedAuditLog::record($deed, TitleDeedAuditLog::EVENT_CREATED,
                'Title deed logged for ' . $deed->property_display . ' (' . $owners->count() . ' owner' . ($owners->count() === 1 ? '' : 's') . ').',
                null, ['owners' => $owners->pluck('name')->all(), 'received_at' => (string) $data['received_at']], $user);

            return $deed;
        });
    }

    /** Edit. A collected deed accepts notes ONLY (the rest is the record of what happened). */
    public function update(User $user, TitleDeed $deed, array $data): TitleDeed
    {
        if ($deed->trashed()) {
            throw new TitleDeedException('This title deed is archived. Restore it before editing.');
        }

        if ($deed->isCollected()) {
            return DB::transaction(function () use ($user, $deed, $data) {
                $locked = TitleDeed::query()->lockForUpdate()->findOrFail($deed->id);
                $before = ['notes' => $locked->notes];
                $locked->update(['notes' => $this->clean($data['notes'] ?? null, 5000)]);
                TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_EDITED, 'Notes edited on a collected deed.', $before, ['notes' => $locked->notes], $user);

                return $locked;
            });
        }

        $property = $this->resolveProperty($user, (int) ($data['property_id'] ?? 0), $deed);
        $owners = $this->resolveOwners($data['owner_ids'] ?? [], $deed);
        [$branchId, $agentId] = $this->resolveBranchAndAgent($user, $property, $data['branch_id'] ?? $deed->branch_id, $data['agent_id'] ?? $deed->agent_id);

        return DB::transaction(function () use ($user, $deed, $property, $owners, $branchId, $agentId, $data) {
            $locked = TitleDeed::query()->lockForUpdate()->findOrFail($deed->id);
            if ($locked->isCollected()) {
                throw new TitleDeedException('This title deed was collected a moment ago, so it can no longer be changed (notes only).');
            }

            $before = [
                'property_id' => $locked->property_id, 'agent_id' => $locked->agent_id, 'branch_id' => $locked->branch_id,
                'attorney_firm' => $locked->attorney_firm, 'received_at' => $locked->received_at?->toDateString(),
                'storage_location' => $locked->storage_location, 'notes' => $locked->notes,
                'owners' => $locked->owners()->get()->map->display_name->all(),
            ];

            $locked->update([
                'branch_id' => $branchId,
                'agent_id' => $agentId,
                'property_id' => $property->id,
                'property_address' => mb_substr((string) $property->buildDisplayAddress(), 0, 255),
                'attorney_firm' => $this->clean($data['attorney_firm'] ?? null, 255),
                'received_at' => $data['received_at'],
                'storage_location' => $this->clean($data['storage_location'] ?? null, 255),
                'notes' => $this->clean($data['notes'] ?? null, 5000),
            ]);
            $this->syncOwners($locked, $owners);

            $after = [
                'property_id' => $locked->property_id, 'agent_id' => $locked->agent_id, 'branch_id' => $locked->branch_id,
                'attorney_firm' => $locked->attorney_firm, 'received_at' => $locked->received_at?->toDateString(),
                'storage_location' => $locked->storage_location, 'notes' => $locked->notes,
                'owners' => $owners->pluck('name')->all(),
            ];
            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_EDITED, 'Record edited.', $before, $after, $user);

            return $locked;
        });
    }

    public function archive(User $user, TitleDeed $deed): void
    {
        DB::transaction(function () use ($user, $deed) {
            $locked = TitleDeed::query()->lockForUpdate()->find($deed->id);
            if (! $locked) {
                return;   // already archived by someone else: absorb
            }
            $locked->delete();
            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_ARCHIVED, 'Archived.', null, null, $user);
        });
    }

    public function restore(User $user, TitleDeed $deed): void
    {
        DB::transaction(function () use ($user, $deed) {
            $locked = TitleDeed::onlyTrashed()->lockForUpdate()->find($deed->id);
            if (! $locked) {
                return;   // not archived: absorb
            }
            $locked->restore();
            TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_RESTORED, 'Restored from the archive.', null, null, $user);
        });
    }

    /** File (or replace) the optional scan. Private disk; the old scan's Document is archived, never hard-deleted. */
    public function attachScan(User $user, TitleDeed $deed, UploadedFile $file): Document
    {
        if ($deed->trashed()) {
            throw new TitleDeedException('This title deed is archived. Restore it before adding a scan.');
        }
        if ($deed->isCollected()) {
            throw new TitleDeedException('This title deed has been collected, so its record can no longer change (notes only).', TitleDeedException::ALREADY_COLLECTED);
        }

        $path = $file->store("title-deeds/{$deed->id}/scan", 'local');
        if (! $path) {
            throw new TitleDeedException('The scan could not be saved. Please try again.');
        }

        try {
            return DB::transaction(function () use ($user, $deed, $file, $path) {
                $locked = TitleDeed::query()->lockForUpdate()->findOrFail($deed->id);
                if ($locked->isCollected()) {
                    throw new TitleDeedException('This title deed was collected a moment ago, so its record can no longer change (notes only).', TitleDeedException::ALREADY_COLLECTED);
                }
                $typeId = DocumentType::query()->where('slug', 'title_deed_copy')->value('id');

                $doc = Document::create([
                    'agency_id' => $locked->agency_id,
                    'branch_id' => $locked->branch_id,
                    'original_name' => $file->getClientOriginalName() ?: 'title-deed-copy',
                    'storage_path' => $path,
                    'disk' => 'local',
                    'mime_type' => $file->getMimeType(),
                    'size' => $file->getSize(),
                    'document_type_id' => $typeId,
                    'source_type' => 'title_deed',
                    'source_id' => $locked->id,
                    'uploaded_by' => $user->id,
                ]);
                $doc->properties()->syncWithoutDetaching([$locked->property_id]);
                $contactIds = $locked->owners()->pluck('contact_id')->all();
                if ($contactIds) {
                    $doc->contacts()->syncWithoutDetaching($contactIds);
                }

                $old = $locked->scan_document_id;
                $locked->update(['scan_document_id' => $doc->id]);
                if ($old && ($oldDoc = Document::query()->find($old))) {
                    $oldDoc->delete();
                }

                TitleDeedAuditLog::record($locked, TitleDeedAuditLog::EVENT_SCAN_UPLOADED, 'Scan uploaded (' . $doc->original_name . ').', null, ['document_id' => $doc->id], $user);

                return $doc;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);   // no orphan file when the row write rolled back
            throw $e;
        }
    }

    /* ── helpers ── */

    private function resolveProperty(User $user, int $propertyId, ?TitleDeed $existing = null): Property
    {
        $property = $propertyId ? Property::query()->visibleTo($user)->find($propertyId) : null;

        // An edit that keeps the deed's current (maybe since-archived) property must not be forced to change it.
        if (! $property && $existing && $propertyId === (int) $existing->property_id) {
            $property = Property::withTrashed()->withoutGlobalScopes()->where('agency_id', $existing->agency_id)->find($propertyId);
        }

        if (! $property) {
            throw ValidationException::withMessages(['property_id' => 'Pick the property from the list - that property could not be found.']);
        }

        return $property;
    }

    /**
     * Resolve the posted owner ids to [id, name] pairs. A NEW owner must be a contact the user can see; an owner already on
     * the deed stays resolvable even if the contact has since been archived or sits outside this user's contact visibility
     * (the saved name is kept), so an untouched edit never fails because of a colleague's contact.
     *
     * @return \Illuminate\Support\Collection<int,array{id:int,name:string}>
     */
    private function resolveOwners(array $ids, ?TitleDeed $existing = null)
    {
        $ids = collect($ids)->map(fn ($i) => (int) $i)->filter()->unique()->values();
        if ($ids->isEmpty()) {
            throw ValidationException::withMessages(['owner_ids' => 'Add at least one owner - pick them from your contacts.']);
        }

        $visible = Contact::query()->whereIn('id', $ids)->get()->keyBy('id');
        $current = $existing
            ? TitleDeedOwner::withoutGlobalScopes()->where('title_deed_id', $existing->id)->whereNull('deleted_at')->get()->keyBy('contact_id')
            : collect();

        $out = collect();
        foreach ($ids as $id) {
            if ($visible->has($id)) {
                $out->push(['id' => $id, 'name' => $this->nameOf($visible[$id])]);
            } elseif ($current->has($id)) {
                $out->push(['id' => $id, 'name' => $current[$id]->name_snapshot ?: 'Contact #' . $id]);
            } else {
                throw ValidationException::withMessages(['owner_ids' => 'One of the owners could not be found (it may have been archived). Please pick again.']);
            }
        }

        return $out;
    }

    /** @return array{0:int,1:int} [branch_id, agent_id] */
    private function resolveBranchAndAgent(User $user, Property $property, mixed $branchId, mixed $agentId): array
    {
        $scope = PermissionService::getDataScope($user, 'title_deeds');

        $branch = (int) ($branchId ?: 0) ?: (int) ($property->branch_id ?: 0) ?: (int) ($user->effectiveBranchId() ?: 0);
        if ($scope !== 'all' && $user->effectiveBranchId()) {
            $branch = (int) $user->effectiveBranchId();   // a branch-scoped user files into their own branch only
        }
        if (! $branch) {
            throw ValidationException::withMessages(['branch_id' => 'This deed has no branch to sit under - pick one.']);
        }

        $agent = (int) ($agentId ?: 0) ?: (int) ($property->agent_id ?: 0) ?: (int) $user->id;
        $agentOk = User::query()->where('id', $agent)->where('is_active', 1)->exists();
        if (! $agentOk) {
            throw ValidationException::withMessages(['agent_id' => 'Pick the responsible agent from the list.']);
        }

        return [$branch, $agent];
    }

    /** Sync the owner rows. Soft-deleted rows are RESTORED, not duplicated (the unique key does not know about soft deletes). */
    private function syncOwners(TitleDeed $deed, $owners): void
    {
        $existing = TitleDeedOwner::withoutGlobalScopes()->withTrashed()->where('title_deed_id', $deed->id)->get()->keyBy('contact_id');
        $keep = [];

        foreach ($owners as $owner) {
            $keep[] = $owner['id'];
            if ($row = $existing->get($owner['id'])) {
                if ($row->trashed()) {
                    $row->restore();
                }
                if ($row->name_snapshot !== $owner['name']) {
                    $row->update(['name_snapshot' => $owner['name']]);
                }
            } else {
                TitleDeedOwner::create([
                    'agency_id' => $deed->agency_id,
                    'title_deed_id' => $deed->id,
                    'contact_id' => $owner['id'],
                    'name_snapshot' => $owner['name'],
                ]);
            }
        }

        foreach ($existing as $contactId => $row) {
            if (! in_array((int) $contactId, $keep, true) && ! $row->trashed()) {
                $row->delete();
            }
        }
    }

    private function nameOf(Contact $c): string
    {
        $name = trim(($c->first_name ?? '') . ' ' . ($c->last_name ?? ''));

        return $name !== '' ? mb_substr($name, 0, 255) : 'Contact #' . $c->id;
    }

    private function clean(?string $value, int $max): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
