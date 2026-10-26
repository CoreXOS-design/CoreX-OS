<?php

namespace App\Services\RoadToSold;

use App\Models\DocumentLibraryItem;
use App\Models\RoadToSold\RtsTrainingPointer;
use App\Models\Scopes\AgencyScope;
use App\Models\Training\TrainingDoc;
use App\Models\TrainingCourse;
use App\Models\User;
use App\Support\Tours\TourRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;

/**
 * AT-454 v2 phase 5 - which training an agency points at for each funnel ratio (road-to-sold.md section 9.3). No skill-to-course mapping
 * existed anywhere, so this is it: one pointer per (agency, track, ratio) to a training course, a help document, a document-library item
 * or a guided tour. Only items of the agency's own (or the shared help documents) can be chosen; archive and restore only.
 */
class TrainingPointerService
{
    /** @return Collection<string,RtsTrainingPointer> ratio key => pointer (live ones; archived too when asked) */
    public function forAgency(int $agencyId, string $track, bool $withArchived = false): Collection
    {
        $q = RtsTrainingPointer::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $agencyId)->where('track', $track);

        return ($withArchived ? $q->withTrashed() : $q)->get()->keyBy('ratio_key');
    }

    /**
     * What can be chosen, by kind: id (or slug / key) => title.
     *
     * @return array<string,array<string,string>>
     */
    public function choices(User $viewer): array
    {
        $agencyId = (int) ($viewer->effectiveAgencyId() ?: 0);

        return [
            RtsTrainingPointer::KIND_COURSE => TrainingCourse::query()->withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agencyId)->where('is_published', true)->orderBy('title')->pluck('title', 'id')
                ->mapWithKeys(fn ($t, $id) => [(string) $id => (string) $t])->all(),
            RtsTrainingPointer::KIND_DOC => TrainingDoc::query()->withoutGlobalScope(AgencyScope::class)
                ->where(fn ($w) => $w->where('agency_id', $agencyId)->orWhereNull('agency_id'))->orderBy('title')->pluck('title', 'slug')->all(),
            RtsTrainingPointer::KIND_LIBRARY => DocumentLibraryItem::query()->withoutGlobalScope(AgencyScope::class)
                ->where('agency_id', $agencyId)->where('is_enabled', true)->orderBy('title')->get(['id', 'title', 'original_name'])
                ->mapWithKeys(fn ($i) => [(string) $i->id => (string) ($i->title ?: $i->original_name)])->all(),
            RtsTrainingPointer::KIND_TOUR => collect(TourRegistry::all())
                ->filter(fn ($t) => TourRegistry::visibleTo($t, $viewer) && TourRegistry::launchTarget($t) !== null)
                ->mapWithKeys(fn ($t) => [(string) $t['key'] => (string) ($t['title'] ?? $t['key'])])->sort()->all(),
        ];
    }

    public function save(User $by, string $track, string $ratioKey, string $kind, string $ref): RtsTrainingPointer
    {
        abort_unless($by->hasPermission('road_to_sold.manage_settings'), 403);
        $agencyId = (int) ($by->effectiveAgencyId() ?: 0);
        abort_if($agencyId <= 0, 403);
        if (! isset(FunnelService::RATIOS[$ratioKey])) {
            throw ValidationException::withMessages(['ratio_key' => 'Choose one of the funnel ratios.']);
        }
        if (! isset(RtsTrainingPointer::KINDS[$kind])) {
            throw ValidationException::withMessages(['kind' => 'Choose what kind of training this is.']);
        }
        $choices = $this->choices($by)[$kind] ?? [];
        if (! array_key_exists($ref, $choices)) {
            throw ValidationException::withMessages(['target' => 'That training is not available to your agency - choose one from the list.']);
        }
        $numeric = in_array($kind, [RtsTrainingPointer::KIND_COURSE, RtsTrainingPointer::KIND_LIBRARY], true);

        return DB::transaction(function () use ($by, $agencyId, $track, $ratioKey, $kind, $ref, $choices, $numeric) {
            $row = RtsTrainingPointer::query()->withoutGlobalScope(AgencyScope::class)->withTrashed()
                ->where('agency_id', $agencyId)->where('track', $track)->where('ratio_key', $ratioKey)->first();
            $values = [
                'kind' => $kind, 'target_id' => $numeric ? (int) $ref : null, 'target_key' => $numeric ? null : $ref,
                'label' => mb_substr($choices[$ref], 0, 200), 'set_by_user_id' => $by->id,
            ];
            if (! $row) {
                return RtsTrainingPointer::withoutAgencyStamping(fn () => RtsTrainingPointer::create($values + ['agency_id' => $agencyId, 'track' => $track, 'ratio_key' => $ratioKey]));
            }
            if ($row->trashed()) {
                $row->restore();
            }
            $row->forceFill($values)->save();

            return $row;
        });
    }

    public function archive(User $by, string $track, string $ratioKey): bool
    {
        abort_unless($by->hasPermission('road_to_sold.manage_settings'), 403);
        $row = RtsTrainingPointer::query()->withoutGlobalScope(AgencyScope::class)
            ->where('agency_id', (int) ($by->effectiveAgencyId() ?: 0))->where('track', $track)->where('ratio_key', $ratioKey)->first();

        return $row ? (bool) $row->delete() : false;
    }

    public function restore(User $by, string $track, string $ratioKey): bool
    {
        abort_unless($by->hasPermission('road_to_sold.manage_settings'), 403);
        $row = RtsTrainingPointer::query()->withoutGlobalScope(AgencyScope::class)->onlyTrashed()
            ->where('agency_id', (int) ($by->effectiveAgencyId() ?: 0))->where('track', $track)->where('ratio_key', $ratioKey)->first();

        return $row ? (bool) $row->restore() : false;
    }

    /**
     * Where the training is, for $for: ['label' => ..., 'url' => ...], or null when what was chosen no longer exists (or its screen is off).
     *
     * @return array{label:string,url:string}|null
     */
    public function resolve(RtsTrainingPointer $p): ?array
    {
        $url = match ($p->kind) {
            RtsTrainingPointer::KIND_COURSE  => $p->target_id && TrainingCourse::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $p->agency_id)->whereKey($p->target_id)->where('is_published', true)->exists() && Route::has('training.show')
                ? route('training.show', $p->target_id, false) : null,
            RtsTrainingPointer::KIND_DOC     => $p->target_key && Route::has('training-help.show') ? route('training-help.show', $p->target_key, false) : null,
            RtsTrainingPointer::KIND_LIBRARY => $p->target_id && DocumentLibraryItem::query()->withoutGlobalScope(AgencyScope::class)->where('agency_id', $p->agency_id)->whereKey($p->target_id)->exists() && Route::has('documents.library.view')
                ? route('documents.library.view', $p->target_id, false) : null,
            RtsTrainingPointer::KIND_TOUR    => ($t = TourRegistry::find($p->target_key)) ? (TourRegistry::launchTarget($t)['url'] ?? null) : null,
            default => null,
        };

        return $url ? ['label' => (string) $p->label, 'url' => $url] : null;
    }
}
