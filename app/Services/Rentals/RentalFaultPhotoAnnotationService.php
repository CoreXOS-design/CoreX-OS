<?php

namespace App\Services\Rentals;

use App\Models\RentalFaultPhotoMark;
use App\Models\RentalFaultPhotoNote;
use App\Models\RentalFaultReport;
use App\Models\RentalFaultReportPhoto;
use App\Models\RentalFaultReportUpdate;
use App\Models\RentalPortalSetting;
use App\Models\User;
use App\Services\Images\PortalImageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * .ai/specs/rentals-faults-work-orders.md §18 (AT-452) — the agent's marks and per-photo notes on a fault's photos.
 *
 * ONE place decides who may SEE them ({@see self::annotationsVisibleTo()}) and ONE place writes them (add / change / remove / restore a
 * mark, set / clear a note), each write logged to the fault's history (who, when). The photo file and its row are never touched — a mark
 * is data drawn as an overlay by the viewer. Every portal / contractor payload is built here so the rule cannot drift between screens.
 */
class RentalFaultPhotoAnnotationService
{
    public const AUDIENCE_STAFF = 'staff';
    public const AUDIENCE_OWNER = 'owner';
    public const AUDIENCE_TENANT = 'tenant';
    public const AUDIENCE_CONTRACTOR = 'contractor';

    /** A sensible ceiling so a runaway client cannot pile marks onto one photo. */
    public const MAX_MARKS_PER_PHOTO = 30;

    /**
     * May this audience see the agent's marks and notes on this fault's photos? (Which PHOTOS the audience sees at all is decided by the
     * caller's existing scope — the owner only gets the shared ones, the tenant only their own.)
     */
    public function annotationsVisibleTo(RentalFaultReport $fault, string $audience): bool
    {
        return match ($audience) {
            self::AUDIENCE_STAFF, self::AUDIENCE_CONTRACTOR => true,
            // The owner sees nothing of the agent's work until the agent has sent the fault to them.
            self::AUDIENCE_OWNER => $fault->sent_to_owner_at !== null,
            self::AUDIENCE_TENANT => RentalPortalSetting::tenantSeesAgentMarksFor((int) $fault->agency_id),
            default => false,
        };
    }

    /**
     * The viewer's photo list for a set of this fault's photos.
     *
     * @param  Collection<int,RentalFaultReportPhoto>  $photos
     * @return array<int,array<string,mixed>>
     */
    public function payload(RentalFaultReport $fault, Collection $photos, string $audience, ?array $sharedIds = null): array
    {
        $with = $this->annotationsVisibleTo($fault, $audience);
        $ids = $photos->pluck('id')->all();
        $marks = $with && $ids ? RentalFaultPhotoMark::withoutGlobalScopes()->where('agency_id', $fault->agency_id)->whereIn('rental_fault_report_photo_id', $ids)->whereNull('deleted_at')->orderBy('id')->get()->groupBy('rental_fault_report_photo_id') : collect();
        $notes = $with && $ids ? RentalFaultPhotoNote::withoutGlobalScopes()->where('agency_id', $fault->agency_id)->whereIn('rental_fault_report_photo_id', $ids)->whereNull('deleted_at')->get()->keyBy('rental_fault_report_photo_id') : collect();
        $names = $audience === self::AUDIENCE_STAFF
            ? User::withoutGlobalScopes()->whereIn('id', $marks->flatten(1)->pluck('created_by_user_id')->merge($notes->pluck('recorded_by_user_id'))->filter()->unique())->pluck('name', 'id')
            : collect();
        $images = app(PortalImageService::class);

        return $photos->values()->map(function (RentalFaultReportPhoto $p, int $i) use ($with, $marks, $notes, $names, $images, $audience, $sharedIds) {
            $urls = $images->urls($p->storage_path);
            $row = [
                'id' => $p->id,
                'url' => $urls['url'],
                'thumb_url' => $urls['thumb_url'],
                'alt' => 'Photo ' . ($i + 1),
                'marks' => [],
                'note' => null,
            ];
            if ($sharedIds !== null) {
                $row['shared'] = in_array($p->id, $sharedIds, true);
            }
            if (! $with) {
                return $row;
            }
            $row['marks'] = ($marks[$p->id] ?? collect())->map(fn (RentalFaultPhotoMark $m) => [
                'id' => $m->id,
                'shape' => $m->shape,
                'geometry' => $m->geometry,
                'colour' => $m->colour,
                'by' => $this->who($audience, $names[$m->created_by_user_id] ?? null),
                'at' => $m->created_at?->toIso8601String(),
            ])->values()->all();
            $n = $notes[$p->id] ?? null;
            $row['note'] = $n ? [
                'text' => $n->description,
                'by' => $this->who($audience, $names[$n->recorded_by_user_id] ?? null),
                'at' => $n->recorded_at?->toIso8601String(),
            ] : null;

            return $row;
        })->all();
    }

    /** The photos the TENANT may see on this fault: the ones that came in through the portal (no staff uploader) on a fault they can already see. */
    public function tenantPhotos(RentalFaultReport $fault): Collection
    {
        if ($fault->reported_by_type !== RentalFaultReport::REPORTED_BY_TENANT) {
            return collect();
        }

        return RentalFaultReportPhoto::withoutGlobalScopes()
            ->where('agency_id', $fault->agency_id)->where('rental_fault_report_id', $fault->id)
            ->whereNull('uploaded_by_user_id')->orderBy('id')->get();
    }

    /**
     * The work order's photos for the viewer. A `reported` photo is a copy of a fault photo (same stored picture), so its marks and notes
     * are the fault photo's: matched on the fault that raised the work order and the stored path. Every other photo is shown plain.
     *
     * @param  Collection<int,\App\Models\RentalWorkOrderPhoto>  $woPhotos
     * @return array<int,array<string,mixed>>
     */
    public function workOrderPayload(\App\Models\RentalWorkOrder $workOrder, Collection $woPhotos, string $audience): array
    {
        $fault = RentalFaultReport::withoutGlobalScopes()->where('agency_id', $workOrder->agency_id)->where('rental_work_order_id', $workOrder->id)->first();
        $faultPhotos = $fault
            ? RentalFaultReportPhoto::withoutGlobalScopes()->where('agency_id', $fault->agency_id)->where('rental_fault_report_id', $fault->id)->get()->keyBy('storage_path')
            : collect();
        $matched = $woPhotos->filter(fn ($p) => $p->photo_type === \App\Models\RentalWorkOrder::PHOTO_REPORTED && $faultPhotos->has($p->storage_path))
            ->map(fn ($p) => $faultPhotos[$p->storage_path])->unique('id')->values();
        $annotated = $fault && $matched->isNotEmpty() ? collect($this->payload($fault, $matched, $audience))->keyBy('id') : collect();
        $images = app(PortalImageService::class);

        return $woPhotos->values()->map(function ($p, int $i) use ($faultPhotos, $annotated, $images) {
            $urls = $images->urls($p->storage_path);
            $fp = $p->photo_type === \App\Models\RentalWorkOrder::PHOTO_REPORTED ? $faultPhotos->get($p->storage_path) : null;
            $a = $fp ? $annotated->get($fp->id) : null;

            return [
                'id' => $p->id, 'url' => $urls['url'], 'thumb_url' => $urls['thumb_url'],
                'alt' => ucfirst(str_replace('_', ' ', (string) $p->photo_type)) . ' photo ' . ($i + 1),
                'type' => $p->photo_type,
                'marks' => $a['marks'] ?? [], 'note' => $a['note'] ?? null,
            ];
        })->all();
    }

    /**
     * "Photos of the problem" on the contractor's private link AND the crew's job page: EVERY photo of the fault behind the work order (tenant's, owner's, the
     * office's - read-only, with the office's marks and notes), plus any photo the office put on the work order itself as a reported one.
     * Rental re-walk, 14 Oct 2026: a work order only started with the photos the agent ticked "Share" for the OWNER, so a fault whose
     * photos were never ticked - or that gained one after the work order was made - sent the contractor to a page with no pictures even
     * though the fault holds them. The owner's own view is untouched: it still shows only what the agent shared with them.
     *
     * @return array<int,array<string,mixed>>
     */
    public function contractorProblemPhotos(?\App\Models\RentalWorkOrder $workOrder, ?RentalFaultReport $fault = null): array
    {
        $reported = $workOrder
            ? $workOrder->photos()->withoutGlobalScopes()->where('photo_type', \App\Models\RentalWorkOrder::PHOTO_REPORTED)->orderBy('id')->get()
            : collect();
        $out = $workOrder ? $this->workOrderPayload($workOrder, $reported, self::AUDIENCE_CONTRACTOR) : [];

        $fault ??= $workOrder
            ? RentalFaultReport::withoutGlobalScopes()->where('agency_id', $workOrder->agency_id)->where('rental_work_order_id', $workOrder->id)->first()
            : null;
        if (! $fault) {
            return $out;
        }
        $have = $reported->pluck('storage_path')->all();
        $more = RentalFaultReportPhoto::withoutGlobalScopes()
            ->where('agency_id', $fault->agency_id)->where('rental_fault_report_id', $fault->id)
            ->whereNotIn('storage_path', $have ?: [''])->orderBy('id')->get();
        if ($more->isEmpty()) {
            return $out;
        }

        $n = count($out);
        foreach ($this->payload($fault, $more, self::AUDIENCE_CONTRACTOR) as $row) {
            $n++;
            $row['type'] = \App\Models\RentalWorkOrder::PHOTO_REPORTED;
            $row['alt'] = 'Reported photo ' . $n;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * What the crew is shown for a job card: every photo of the fault behind it (and any photo the office put on its work order as a reported one),
     * read-only, with the agent's marks and notes - they help the repair. Empty when the card is not tied to a fault.
     *
     * @return array<int,array<string,mixed>>
     */
    public function forJobCard(\App\Models\RentalJobCard $card): array
    {
        $fault = $card->rental_fault_report_id
            ? RentalFaultReport::withoutGlobalScopes()->where('agency_id', $card->agency_id)->find($card->rental_fault_report_id)
            : ($card->rental_work_order_id
                ? RentalFaultReport::withoutGlobalScopes()->where('agency_id', $card->agency_id)->where('rental_work_order_id', $card->rental_work_order_id)->first()
                : null);
        if (! $fault) {
            return [];
        }

        // The crew sees what the contractor sees: every photo of the fault (not just the ones ticked Share for the owner), read-only.
        $wo = $card->rental_work_order_id
            ? \App\Models\RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $card->agency_id)->find($card->rental_work_order_id)
            : null;

        return $this->contractorProblemPhotos($wo, $fault);
    }

    private function who(string $audience, ?string $name): string
    {
        return match ($audience) {
            self::AUDIENCE_STAFF => $name ?: 'Staff',
            self::AUDIENCE_CONTRACTOR => 'The office',
            default => 'Your agent',
        };
    }

    // ── writes (staff only; the controller has already checked permission + scope) ─────────────────────────────────────────

    public function addMark(RentalFaultReport $fault, RentalFaultReportPhoto $photo, array $input, User $by): RentalFaultPhotoMark
    {
        $this->assertPhotoBelongs($fault, $photo);
        [$shape, $geometry, $colour] = $this->validated($input);

        return DB::transaction(function () use ($fault, $photo, $shape, $geometry, $colour, $by) {
            $count = RentalFaultPhotoMark::withoutGlobalScopes()->where('rental_fault_report_photo_id', $photo->id)->whereNull('deleted_at')->lockForUpdate()->count();
            if ($count >= self::MAX_MARKS_PER_PHOTO) {
                throw ValidationException::withMessages(['shape' => 'That photo already has the most marks it can carry (' . self::MAX_MARKS_PER_PHOTO . ').']);
            }
            $mark = RentalFaultPhotoMark::create([
                'agency_id' => $fault->agency_id, 'rental_fault_report_id' => $fault->id, 'rental_fault_report_photo_id' => $photo->id,
                'shape' => $shape, 'geometry' => $geometry, 'colour' => $colour, 'created_by_user_id' => $by->id,
            ]);
            $fault->recordHistory(RentalFaultReportUpdate::TYPE_PHOTO_MARK_ADDED, $by, $this->label($fault, $photo) . ': ' . $shape . ' added.');

            return $mark;
        });
    }

    public function changeMark(RentalFaultReport $fault, RentalFaultReportPhoto $photo, RentalFaultPhotoMark $mark, array $input, User $by): RentalFaultPhotoMark
    {
        $this->assertMarkBelongs($fault, $photo, $mark);
        [$shape, $geometry, $colour] = $this->validated($input + ['shape' => $mark->shape]);
        $mark->forceFill(['shape' => $shape, 'geometry' => $geometry, 'colour' => $colour, 'updated_by_user_id' => $by->id])->save();
        $fault->recordHistory(RentalFaultReportUpdate::TYPE_PHOTO_MARK_CHANGED, $by, $this->label($fault, $photo) . ': ' . $shape . ' moved or changed.');

        return $mark;
    }

    public function removeMark(RentalFaultReport $fault, RentalFaultReportPhoto $photo, RentalFaultPhotoMark $mark, User $by): void
    {
        $this->assertMarkBelongs($fault, $photo, $mark);
        $mark->forceFill(['deleted_by_user_id' => $by->id])->save();
        $mark->delete();   // soft delete only
        $fault->recordHistory(RentalFaultReportUpdate::TYPE_PHOTO_MARK_REMOVED, $by, $this->label($fault, $photo) . ': ' . $mark->shape . ' removed.');
    }

    public function restoreMark(RentalFaultReport $fault, RentalFaultReportPhoto $photo, RentalFaultPhotoMark $mark, User $by): RentalFaultPhotoMark
    {
        $this->assertMarkBelongs($fault, $photo, $mark);
        $mark->restore();
        $mark->forceFill(['deleted_by_user_id' => null, 'updated_by_user_id' => $by->id])->save();
        $fault->recordHistory(RentalFaultReportUpdate::TYPE_PHOTO_MARK_RESTORED, $by, $this->label($fault, $photo) . ': ' . $mark->shape . ' put back.');

        return $mark;
    }

    public function setNote(RentalFaultReport $fault, RentalFaultReportPhoto $photo, string $text, User $by): RentalFaultPhotoNote
    {
        $this->assertPhotoBelongs($fault, $photo);
        $text = trim($text);
        if ($text === '') {
            throw ValidationException::withMessages(['description' => 'Write a short description, or remove the note.']);
        }
        if (mb_strlen($text) > RentalFaultPhotoNote::MAX_LENGTH) {
            throw ValidationException::withMessages(['description' => 'Keep the description to ' . RentalFaultPhotoNote::MAX_LENGTH . ' characters.']);
        }

        // One note per photo: setting it again edits the same row (and brings back one that was removed).
        $note = RentalFaultPhotoNote::withoutGlobalScopes()->withTrashed()->where('rental_fault_report_photo_id', $photo->id)->first() ?? new RentalFaultPhotoNote();
        if ($note->trashed()) {
            $note->restore();
        }
        $note->forceFill([
            'agency_id' => $fault->agency_id, 'rental_fault_report_id' => $fault->id, 'rental_fault_report_photo_id' => $photo->id,
            'description' => $text, 'recorded_by_user_id' => $by->id, 'recorded_at' => now(), 'deleted_by_user_id' => null,
        ])->save();
        $fault->recordHistory(RentalFaultReportUpdate::TYPE_PHOTO_NOTE_SET, $by, $this->label($fault, $photo) . ': note saved.');

        return $note;
    }

    public function clearNote(RentalFaultReport $fault, RentalFaultReportPhoto $photo, User $by): void
    {
        $this->assertPhotoBelongs($fault, $photo);
        $note = RentalFaultPhotoNote::withoutGlobalScopes()->where('rental_fault_report_photo_id', $photo->id)->whereNull('deleted_at')->first();
        if (! $note) {
            return;
        }
        $note->forceFill(['deleted_by_user_id' => $by->id])->save();
        $note->delete();
        $fault->recordHistory(RentalFaultReportUpdate::TYPE_PHOTO_NOTE_REMOVED, $by, $this->label($fault, $photo) . ': note removed.');
    }

    // ── internals ───────────────────────────────────────────────────────────────────────────────────────────────────────────

    private function assertPhotoBelongs(RentalFaultReport $fault, RentalFaultReportPhoto $photo): void
    {
        abort_unless((int) $photo->rental_fault_report_id === (int) $fault->id && (int) $photo->agency_id === (int) $fault->agency_id, 404);
    }

    private function assertMarkBelongs(RentalFaultReport $fault, RentalFaultReportPhoto $photo, RentalFaultPhotoMark $mark): void
    {
        $this->assertPhotoBelongs($fault, $photo);
        abort_unless((int) $mark->rental_fault_report_photo_id === (int) $photo->id && (int) $mark->agency_id === (int) $fault->agency_id, 404);
    }

    private function label(RentalFaultReport $fault, RentalFaultReportPhoto $photo): string
    {
        $pos = RentalFaultReportPhoto::withoutGlobalScopes()->where('rental_fault_report_id', $fault->id)->where('id', '<=', $photo->id)->count();

        return 'Photo ' . $pos;
    }

    /** @return array{0:string,1:array<string,float>,2:string} shape, geometry (normalised 0..1, 4 decimals), colour */
    private function validated(array $input): array
    {
        $shape = (string) ($input['shape'] ?? '');
        if (! in_array($shape, RentalFaultPhotoMark::SHAPES, true)) {
            throw ValidationException::withMessages(['shape' => 'Choose a circle or an arrow.']);
        }
        $raw = is_array($input['geometry'] ?? null) ? $input['geometry'] : [];
        $geometry = [];
        foreach (array_keys(RentalFaultPhotoMark::geometryKeys($shape)) as $key) {
            $v = $raw[$key] ?? null;
            if (! is_numeric($v) || (float) $v < 0 || (float) $v > 1) {
                throw ValidationException::withMessages(['geometry' => 'The mark is outside the photo.']);
            }
            $geometry[$key] = round((float) $v, 4);
        }
        if ($shape === RentalFaultPhotoMark::SHAPE_CIRCLE && $geometry['r'] < 0.005) {
            throw ValidationException::withMessages(['geometry' => 'Make the circle a little bigger.']);
        }
        if ($shape === RentalFaultPhotoMark::SHAPE_ARROW && hypot($geometry['x2'] - $geometry['x1'], $geometry['y2'] - $geometry['y1']) < 0.01) {
            throw ValidationException::withMessages(['geometry' => 'Make the arrow a little longer.']);
        }
        $colour = (string) ($input['colour'] ?? RentalFaultPhotoMark::DEFAULT_COLOUR);
        if (! in_array($colour, RentalFaultPhotoMark::COLOURS, true)) {
            $colour = RentalFaultPhotoMark::DEFAULT_COLOUR;
        }

        return [$shape, $geometry, $colour];
    }
}
