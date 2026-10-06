<?php

namespace App\Services\Rentals;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Events\Rentals\RentalJobCardCrewPhotosAdded;
use App\Models\Agency;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardTask;
use App\Models\RentalWorkOrder;
use App\Models\RentalWorkOrderPhoto;
use App\Models\Scopes\AgencyScope;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * .ai/specs/rental-work-orders.md §14.27.6 item 4 / §14.28 — everything a crew
 * can see and do on ONE job card through a crew link, in one place so the
 * per-job link (Build 1) and the crew page (Build 2) share a single
 * implementation.
 *
 * Every method takes the card and a CrewViewContext, which is built only from
 * a resolved live token — so nothing here trusts anything in the request.
 * Each action refuses a closed (completed / cancelled) or archived card and
 * writes its own audit line (actor null; the note carries "via crew link —
 * {crew}"). A crew action NEVER closes a card: completion is the agent's
 * sign-off (RentalJobCardService::complete()).
 */
class CrewJobService
{
    public const MAX_PHOTOS_PER_SUBMIT = 10;
    public const PHOTO_TYPES = [RentalWorkOrder::PHOTO_IN_PROGRESS, RentalWorkOrder::PHOTO_COMPLETED];

    /**
     * The crew's view of a card (§14.27.5). Deliberately a flat array of
     * plain values — never a model — so nothing the crew must not see
     * (landlord, quote, approval, history, other cards, other crews) can
     * leak into the Blade partial by accident.
     */
    public function payload(RentalJobCard $card, CrewViewContext $ctx): array
    {
        $this->assertReachable($card, $ctx);

        $property = $card->property()->withoutGlobalScopes()->withTrashed()->first();
        $address = $property?->buildDisplayAddress() ?: '';
        $tz = $this->timezone($card);

        // Agency scope off (a public crew link has no staff user to resolve it) but SoftDeletes ON:
        // an archived task or line is never shown to the crew — same rule as the crew page list and the office card.
        $tasks = RentalJobCardTask::withoutGlobalScope(AgencyScope::class)
            ->where('rental_job_card_id', $card->id)
            ->orderBy('sort_order')->orderBy('id')
            ->with(['lines' => fn ($q) => $q->withoutGlobalScope(AgencyScope::class)])
            ->get();
        $generalLines = RentalJobCardLine::withoutGlobalScope(AgencyScope::class)
            ->where('rental_job_card_id', $card->id)->whereNull('rental_job_card_task_id')
            ->orderBy('sort_order')->orderBy('id')->get();

        $allLines = $tasks->flatMap(fn ($t) => $t->lines)->merge($generalLines);
        // §17.4.7 — "Crew works on actual costs, not selling." The crew payload reads the COST columns only
        // (`unit_cost` / `cost_total`) and never `unit_price` / `line_total` (selling), `markup_*`, margin or an owner
        // amount. A line with no cost recorded simply shows none (never a 0.00). Guarded permanently by
        // CrewPayloadNeverCarriesSellingTest.
        $lineRow = fn (RentalJobCardLine $l) => array_filter([
            'code' => $l->code,
            'description' => $l->description,
            'quantity' => rtrim(rtrim(number_format((float) $l->quantity, 2, '.', ''), '0'), '.'),
            'unit' => $l->unit,
            'unit_cost' => $ctx->showCosts && $l->unit_cost !== null ? number_format((float) $l->unit_cost, 2) : null,
            'cost_total' => $ctx->showCosts && $l->cost_total !== null ? number_format((float) $l->cost_total, 2) : null,
        ], fn ($v) => $v !== null && $v !== '');
        $costedLines = $allLines->filter(fn (RentalJobCardLine $l) => $l->cost_total !== null);

        $photos = RentalWorkOrderPhoto::withoutGlobalScopes()
            ->where('rental_job_card_id', $card->id)
            ->where('photo_type', '!=', RentalWorkOrder::PHOTO_REPORTED)
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (RentalWorkOrderPhoto $p) => [
                'url' => $p->storage_path,
                'type' => $p->photo_type,
                'caption' => $p->caption,
                'at' => $p->created_at?->copy()->setTimezone($tz)->format('j M, H:i'),
            ])->all();

        $branding = Agency::publicBrandingFor($ctx->agencyId);

        return [
            'agency' => ['name' => $branding['name'], 'logo_url' => $branding['logoUrl'], 'color' => $branding['colors']['default']],
            'title' => $card->title,
            'status' => $card->status,
            'status_label' => ucfirst(str_replace('_', ' ', $card->status)),
            'is_open' => ! $card->isClosed() && ! $card->trashed(),
            'address' => $address,
            'map_url' => $this->mapUrl($property, $address),
            'access_notes' => $card->access_notes,
            'scheduled_at' => $card->scheduled_at?->copy()->setTimezone($tz)->format('D j M Y, H:i'),
            'due_at' => $card->due_at?->copy()->setTimezone($tz)->format('D j M Y, H:i'),
            'crew_name' => $card->crew?->name,
            'tasks' => $tasks->values()->map(fn (RentalJobCardTask $t, int $i) => [
                'id' => $t->id,
                'number' => $i + 1,
                'description' => $t->description,
                'is_done' => (bool) $t->is_done,
            ])->all(),
            'materials' => $allLines->where('type', 'part')->values()->map($lineRow)->all(),
            'labour' => $allLines->where('type', 'labour')->values()->map($lineRow)->all(),
            'show_costs' => $ctx->showCosts,
            'cost_total' => $ctx->showCosts && $costedLines->isNotEmpty() ? number_format((float) $costedLines->sum('cost_total'), 2) : null,
            'tenant' => $ctx->showTenantContact ? $this->tenantContact($card) : null,
            'photos' => $photos,
            'crew_completed' => $card->worker_signed_off_at ? [
                'name' => $card->worker_sign_off_name,
                'at' => $card->worker_signed_off_at->copy()->setTimezone($tz)->format('j M Y, H:i'),
                'via' => $card->worker_sign_off_via,
            ] : null,
            // §17.21.1 — the three plug-in slots, one provider class per build, so the builds never edit the same
            // hunk of this method: pricing (Build 1), approval (Build 2), dispute (Build 3). Each returns [] until
            // its build lands, and renders through its own partial in rentals/crew-link/_job-body.blade.php.
            'blocks' => [
                'pricing' => CrewPricingBlock::for($card, $ctx),
                'approval' => CrewApprovalBlock::for($card, $ctx),
                'dispute' => CrewDisputeBlock::for($card, $ctx),
            ],
        ];
    }

    /** Tick or untick a task. */
    public function tick(RentalJobCard $card, RentalJobCardTask $task, CrewViewContext $ctx): void
    {
        $this->assertReachable($card, $ctx);
        $card->assertContentEditable();
        abort_unless((int) $task->rental_job_card_id === (int) $card->id && $task->trashed() === false, 404);

        $isDone = ! $task->is_done;
        $task->forceFill([
            'is_done' => $isDone,
            'done_by_user_id' => null,
            'done_at' => $isDone ? now() : null,
        ])->save();

        $card->logUpdate('task_ticked', null, ($isDone ? 'Ticked: ' : 'Unticked: ') . $task->description . " ({$ctx->actorLabel})");
        $this->touchToken($ctx);
    }

    /**
     * Add photos from the crew's phone. One audit line and one domain event
     * per submit (not per file). A file whose client key was already stored
     * for this card is skipped, so a flaky connection that re-sends cannot
     * double-post. Returns how many NEW photos were stored.
     *
     * @param array<int, UploadedFile> $files
     * @param array<int, string|null> $clientKeys per-file client UUIDs, same order as $files
     */
    public function addPhotos(RentalJobCard $card, array $files, string $type, ?string $caption, CrewViewContext $ctx, array $clientKeys = []): int
    {
        $this->assertReachable($card, $ctx);
        $card->assertContentEditable();
        if (! in_array($type, self::PHOTO_TYPES, true)) {
            throw new \InvalidArgumentException('Photo type must be "in progress" or "completed".');
        }
        if (count($files) > self::MAX_PHOTOS_PER_SUBMIT) {
            throw new \InvalidArgumentException('You can add up to ' . self::MAX_PHOTOS_PER_SUBMIT . ' photos at a time.');
        }

        $caption = $caption !== null ? mb_substr(trim($caption), 0, 255) : null;
        $caption = $caption === '' ? null : $caption;
        $service = app(RentalJobCardService::class);

        $stored = 0;
        foreach (array_values($files) as $i => $file) {
            $key = $clientKeys[$i] ?? null;
            if ($key && RentalWorkOrderPhoto::withoutGlobalScopes()
                ->where('rental_job_card_id', $card->id)->where('client_idempotency_key', $key)->exists()) {
                continue;
            }
            $service->storePhoto($card, $file, $type, null, $key ?: null, $caption, $ctx->photoVia(), false);
            $stored++;
        }

        if ($stored > 0) {
            $card->logUpdate(
                'crew_photos_added',
                null,
                $stored . ' ' . ($type === RentalWorkOrder::PHOTO_COMPLETED ? 'completed' : 'in-progress') . ' photo' . ($stored === 1 ? '' : 's') . " ({$ctx->actorLabel})",
            );
            RentalJobCardCrewPhotosAdded::dispatch($card, $stored, $type, $ctx->via);
        }
        $this->touchToken($ctx);

        return $stored;
    }

    /**
     * "Mark work completed": typed full name + a confirmation tick, stored
     * with timestamp, IP and device (§14.27.1 Q6). Records the worker
     * sign-off ONLY — the card stays open until the agent signs off and
     * completes it. No undo from the link: a second attempt is refused.
     */
    public function markCompleted(RentalJobCard $card, string $fullName, bool $confirmed, CrewViewContext $ctx): void
    {
        $this->assertReachable($card, $ctx);
        $card->assertContentEditable();

        $fullName = trim(preg_replace('/\s+/', ' ', $fullName) ?? '');
        if (mb_strlen($fullName) < 2 || mb_strlen($fullName) > 191) {
            throw new \InvalidArgumentException('Type your full name to sign.');
        }
        if (! $confirmed) {
            throw new \InvalidArgumentException('Tick the box to confirm the work is completed.');
        }
        if ($card->worker_signed_off_at) {
            throw new \LogicException('This job has already been marked completed.');
        }

        DB::transaction(function () use ($card, $fullName, $ctx) {
            $card->recordCrewCompletion($fullName, $ctx->signOffVia(), $ctx->ip, $ctx->userAgent, null);
        });

        RentalJobCardCrewCompleted::dispatch($card, $fullName, $ctx->signOffVia());
        $this->touchToken($ctx);
    }

    /** A closed (completed / cancelled), archived or other-agency card is never reachable from a crew link. */
    private function assertReachable(RentalJobCard $card, CrewViewContext $ctx): void
    {
        if ((int) $card->agency_id !== $ctx->agencyId) {
            abort(404);
        }
        if ($card->trashed()) {
            throw new \LogicException('This job card is closed.');
        }
    }

    private function touchToken(CrewViewContext $ctx): void
    {
        \App\Models\RentalSecureAccessToken::withoutGlobalScopes()->whereKey($ctx->tokenId)->update(['last_used_at' => now()]);
    }

    private function timezone(RentalJobCard $card): string
    {
        return $card->agency?->outreachTimezone() ?: (config('app.timezone') ?: 'Africa/Johannesburg');
    }

    private function mapUrl(?\App\Models\Property $property, string $address): ?string
    {
        if ($property && $property->latitude && $property->longitude) {
            return 'https://www.google.com/maps/search/?api=1&query=' . urlencode($property->latitude . ',' . $property->longitude);
        }

        return $address !== '' ? 'https://www.google.com/maps/search/?api=1&query=' . urlencode($address) : null;
    }

    /** @return array<int, array{name: string, phone: ?string}> primary tenant first */
    private function tenantContact(RentalJobCard $card): array
    {
        if (! $card->lease_id) {
            return [];
        }

        return \App\Models\LeaseTenant::query()
            ->where('lease_id', $card->lease_id)
            ->with('contact')
            ->orderByDesc('is_primary')->orderBy('id')
            ->get()
            ->map(fn ($t) => $t->contact ? ['name' => (string) $t->contact->full_name, 'phone' => $t->contact->phone] : null)
            ->filter()->values()->all();
    }
}
