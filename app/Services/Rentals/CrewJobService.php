<?php

namespace App\Services\Rentals;

use App\Events\Rentals\RentalJobCardCrewCompleted;
use App\Events\Rentals\RentalJobCardCrewPhotosAdded;
use App\Models\Agency;
use App\Models\RentalJobCard;
use App\Events\Rentals\RentalCrewLinesSubmitted;
use App\Models\RentalCatalogueItem;
use App\Models\RentalJobCardCrewReport;
use App\Models\RentalJobCardLine;
use App\Models\RentalJobCardPriceRequest;
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
    /** §17.5.1 — up to three photos explain one crew line (an extra); they are stored against the LINE, not the job gallery. */
    public const MAX_LINE_PHOTOS = 3;
    /** AT-465 - the crew's "what did you find / what is needed" on a send. */
    public const MAX_FINDINGS_LENGTH = 2000;
    /** AT-465 - a second "Send to office" this soon after a send is a double-tap, not a new batch. */
    public const DOUBLE_SEND_WINDOW_SECONDS = 120;

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
            ->with(['lines' => fn ($q) => $q->withoutGlobalScope(AgencyScope::class)->accepted()])
            ->get();
        // §17.4.6 — "What to load" / Labour list the lines that are part of the job (ACCEPTED). The crew's own drafts and lines
        // awaiting the office are shown separately, in the Parts & labour panel (CrewPricingBlock), with their state.
        $generalLines = RentalJobCardLine::withoutGlobalScope(AgencyScope::class)->accepted()
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
            // §17.24 item 4 — a tenant's DISPUTE photo is shown only inside the dispute block (CrewDisputeBlock), never in the general gallery.
            ->whereNotIn('photo_type', [RentalWorkOrder::PHOTO_REPORTED, RentalWorkOrder::PHOTO_DISPUTE])
            // §17.5.1 — a photo that explains one crew line shows against that line (Parts & labour panel), not in the job gallery.
            ->whereNotIn('photo_type', [RentalJobCardLine::PHOTO_TYPE, RentalJobCardLine::RECEIPT_PHOTO_TYPE])
            ->orderByDesc('created_at')->orderByDesc('id')
            ->get()
            ->map(fn (RentalWorkOrderPhoto $p) => [
                'url' => $p->storage_path,
                'type' => $p->photo_type,
                'caption' => $p->caption,
                'at' => $p->created_at?->copy()->setTimezone($tz)->format('j M, H:i'),
            ])->all();

        $branding = Agency::publicBrandingFor($ctx->agencyId);

        $payload = [
            'agency' => ['name' => $branding['name'], 'logo_url' => $branding['logoUrl'] ? (\App\Support\PortalLogo::urlFor($ctx->agencyId) ?? $branding['logoUrl']) : null, 'color' => $branding['colors']['default']],
            'title' => $card->title,
            'work_order_id' => $card->rental_work_order_id,
            'status' => $card->status,
            'status_label' => ucfirst(str_replace('_', ' ', $card->status)),
            'is_open' => ! $card->isClosed() && ! $card->trashed(),
            // A plain yes/no — never the gate's own note (it can carry amounts the crew must not see). Decides whether the
            // page offers "Mark work completed": the server refuses it on an unauthorised job (RentalJobCard::recordCrewCompletion),
            // so the action is not offered until the job is authorised. A pure read (record: false), no side effects.
            'authorised' => app(RentalApprovalGateService::class)->authoriseCard($card, false)->authorised,
            // section 22.7 - the owner stopped the job: a plain yes/no (never the owner's reason)
            'on_hold' => app(\App\Services\Rentals\RentalOwnerHoldService::class)->activeFor($card) !== null,
            'address' => $address,
            'map_url' => JobSiteInfo::mapUrl($property, $address),
            // AT-459 — who lets the worker in, their number and the note; read from the work order (the card's own copy when it has none).
            'access' => $access = JobSiteInfo::accessContact($card->workOrder()->withoutGlobalScopes()->first(), $card),
            'access_notes' => $access['note'],
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
            'tenant' => $ctx->showTenantContact ? JobSiteInfo::tenants($card->lease_id) : null,
            'photos' => $photos,
            // AT-452 - the photos of the fault behind this job, with the office's marks and notes (they help the repair); [] when the job has no fault.
            'reported_photos' => app(RentalFaultPhotoAnnotationService::class)->forJobCard($card),
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

        // AT-465 - the NEXT bar at the top of the crew page: one sentence, at most one button (read from the payload above).
        $payload['next'] = CrewNextBar::for($payload);

        return $payload;
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
    public function markCompleted(RentalJobCard $card, string $fullName, bool $confirmed, CrewViewContext $ctx, array $report = []): void
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

        // spec section 22.13.2 - a job that came from a fault is not done until the crew say what was found and what caused it (same writer as the contractor link)
        $workOrder = $card->rental_work_order_id ? RentalWorkOrder::withoutGlobalScopes()->where('agency_id', $card->agency_id)->find($card->rental_work_order_id) : null;
        $who = app(RentalWhoPaysService::class);
        if ($workOrder && $who->isFaultJob($workOrder)) {
            $who->recordFindings($workOrder, $report['found_text'] ?? null, ! empty($report['cause_id']) ? (int) $report['cause_id'] : null, $report['cause_note'] ?? null, $fullName . ' (crew)');
        }

        DB::transaction(function () use ($card, $fullName, $ctx) {
            $card->recordCrewCompletion($fullName, $ctx->signOffVia(), $ctx->ip, $ctx->userAgent, null);
        });

        RentalJobCardCrewCompleted::dispatch($card, $fullName, $ctx->signOffVia());
        $this->touchToken($ctx);
    }

    // ───────────── §17.5 — the crew prices a job and adds extras (Build 1) ─────────────

    /**
     * Add ONE part or labour line from the crew's phone. It is a `crew_draft`: visible only to the crew, in no total, quote
     * or owner payload until the crew presses "Send to office" and the office accepts it (§17.5.3). The crew types the
     * ACTUAL COST (required when the agency captures money at all); there is no selling, markup or margin anywhere in this
     * call or its result. While the office has an open price request a line is a pricing line for it, unless the crew marks
     * it as an extra.
     *
     * @param array{type?: ?string, description?: ?string, rental_catalogue_item_id?: mixed, quantity?: mixed, unit?: ?string, unit_cost?: mixed, note?: ?string, is_extra?: mixed} $data
     * @param array<int, UploadedFile> $photos up to MAX_LINE_PHOTOS
     * @param UploadedFile|null $receipt AT-459 — the receipt for a PART line (what the crew paid); ignored for labour
     */
    public function addLine(RentalJobCard $card, array $data, array $photos, CrewViewContext $ctx, ?UploadedFile $receipt = null): RentalJobCardLine
    {
        $this->assertReachable($card, $ctx);
        $card->assertContentEditable();

        $clean = $this->cleanLineData($card, $data, $ctx);
        $this->assertPhotoCount(count($photos), 0);

        $request = $card->priceRequests()->withoutGlobalScopes()->where('status', RentalJobCardPriceRequest::STATUS_OPEN)->latest('id')->first();
        $isExtra = ! empty($data['is_extra']) || ! $request;

        $line = DB::transaction(function () use ($card, $clean, $request, $isExtra, $ctx) {
            return app(RentalJobCardService::class)->addLine($card, $clean + [
                'origin' => $isExtra ? RentalJobCardLine::ORIGIN_CREW_EXTRA : RentalJobCardLine::ORIGIN_CREW_PRICING,
                'office_status' => RentalJobCardLine::OFFICE_CREW_DRAFT,
                'crew_added_by_label' => mb_substr($ctx->actorLabel, 0, 191),
                'crew_added_at' => now(),
                'rental_job_card_price_request_id' => $isExtra ? null : $request?->id,
            ], null, null, false);
        });

        $this->storeLinePhotos($card, $line, $photos, $ctx);
        $this->storeReceipt($card, $line, $receipt, $ctx);
        $this->touchToken($ctx);

        return $line;
    }

    /** Change one of the crew's OWN drafts. Once sent to the office a line is no longer the crew's to change. */
    public function editDraft(RentalJobCard $card, RentalJobCardLine $line, array $data, array $photos, CrewViewContext $ctx, ?UploadedFile $receipt = null): RentalJobCardLine
    {
        $this->assertReachable($card, $ctx);
        $card->assertContentEditable();
        $this->assertOwnDraft($card, $line);

        $clean = $this->cleanLineData($card, $data, $ctx);
        $existingPhotos = RentalWorkOrderPhoto::withoutGlobalScopes()->where('rental_job_card_line_id', $line->id)->where('photo_type', RentalJobCardLine::PHOTO_TYPE)->count();
        $this->assertPhotoCount(count($photos), $existingPhotos);

        $pricing = app(RentalPricingService::class);
        $line->forceFill([
            'rental_catalogue_item_id' => $clean['rental_catalogue_item_id'] ?? null,
            'code' => $clean['code'] ?? null,
            'type' => $clean['type'],
            'description' => $clean['description'],
            'unit' => $clean['unit'] ?? null,
            'quantity' => $clean['quantity'],
            'unit_cost' => $clean['unit_cost'] ?? null,
            'crew_note' => $clean['crew_note'] ?? null,
        ]);
        $pricing->syncCostTotal($line);
        $line->save();

        $this->storeLinePhotos($card, $line, $photos, $ctx);
        $this->storeReceipt($card, $line, $receipt, $ctx);
        $this->touchToken($ctx);

        return $line->refresh();
    }

    /** Remove one of the crew's own drafts — a soft archive (nothing is ever hard-deleted). */
    public function archiveDraft(RentalJobCard $card, RentalJobCardLine $line, CrewViewContext $ctx): void
    {
        $this->assertReachable($card, $ctx);
        $card->assertContentEditable();
        $this->assertOwnDraft($card, $line);

        $line->delete();
        $this->touchToken($ctx);
    }

    /**
     * Undo a Remove: bring back a draft the crew archived (spec §17.42). Only the crew's own, still-draft line of THIS card; a line
     * that is not archived is returned as it is, so a double tap on Undo is harmless.
     */
    public function restoreDraft(RentalJobCard $card, int $lineId, CrewViewContext $ctx): RentalJobCardLine
    {
        $this->assertReachable($card, $ctx);
        $card->assertContentEditable();

        $line = RentalJobCardLine::withoutGlobalScopes()->withTrashed()->where('rental_job_card_id', $card->id)->whereKey($lineId)->firstOrFail();
        abort_unless(in_array($line->origin, [RentalJobCardLine::ORIGIN_CREW_PRICING, RentalJobCardLine::ORIGIN_CREW_EXTRA], true), 404);
        if ($line->office_status !== RentalJobCardLine::OFFICE_CREW_DRAFT) {
            throw new \LogicException('This line has already been sent to the office, so it can no longer be changed here.');
        }
        if ($line->trashed()) {
            $line->restore();
        }
        $this->touchToken($ctx);

        return $line->refresh();
    }

    /**
     * "Send to office": every one of this card's crew drafts becomes `awaiting_office`; an open price request that the crew
     * answered becomes `submitted` (with who/when/IP/device). ONE audit line, ONE domain event (→ one in-app note to the
     * property's agent) per send, never per line. Returns how many lines were sent.
     */
    public function sendToOffice(RentalJobCard $card, CrewViewContext $ctx, ?string $findings = null): int
    {
        $this->assertReachable($card, $ctx);
        $card->assertContentEditable();

        $findings = trim((string) $findings);
        if (mb_strlen($findings) > self::MAX_FINDINGS_LENGTH) {
            throw new \InvalidArgumentException('Keep what you found to ' . self::MAX_FINDINGS_LENGTH . ' characters or fewer.');
        }

        $sent = 0;
        $requestId = null;
        $duplicate = false;
        $reportId = null;
        DB::transaction(function () use ($card, $ctx, $findings, &$sent, &$requestId, &$duplicate, &$reportId) {
            // The card row is the lock: two taps of "Send to office" (or the same send from two tabs) queue here, so the second
            // one finds no drafts left rather than sending the batch twice.
            RentalJobCard::withoutGlobalScopes()->whereKey($card->id)->lockForUpdate()->first();

            $drafts = RentalJobCardLine::withoutGlobalScopes()
                ->where('rental_job_card_id', $card->id)->whereNull('deleted_at')
                ->whereIn('origin', [RentalJobCardLine::ORIGIN_CREW_PRICING, RentalJobCardLine::ORIGIN_CREW_EXTRA])
                ->where('office_status', RentalJobCardLine::OFFICE_CREW_DRAFT)
                ->lockForUpdate()->get();
            if ($drafts->isEmpty()) {
                // a double-tap: the batch the crew just sent is already with the office - say so instead of an error
                $recent = RentalJobCardCrewReport::withoutGlobalScopes()->where('rental_job_card_id', $card->id)
                    ->where('created_at', '>=', now()->subSeconds(self::DOUBLE_SEND_WINDOW_SECONDS))->exists();
                if ($recent) {
                    $duplicate = true;

                    return;
                }
                throw new \InvalidArgumentException('Add at least one part or labour line to your list before you send to the office.');
            }

            // What did you find / what is needed: required on the crew's FIRST send for this card, optional after.
            $firstSend = ! RentalJobCardCrewReport::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->exists();
            if ($firstSend && $findings === '') {
                throw new \InvalidArgumentException('Tell the office what you found and what is needed before you send.');
            }

            $report = RentalJobCardCrewReport::withoutGlobalScopes()->create([
                'agency_id' => $card->agency_id,
                'rental_job_card_id' => $card->id,
                'findings' => $findings !== '' ? $findings : null,
                'line_count' => $drafts->count(),
                'sent_label' => mb_substr($ctx->actorLabel, 0, 191),
                'sent_via' => mb_substr($ctx->via, 0, 20),
                'sent_ip' => $ctx->ip,
            ]);

            $reportId = $report->id;
            RentalJobCardLine::withoutGlobalScopes()->whereIn('id', $drafts->pluck('id'))
                ->update(['office_status' => RentalJobCardLine::OFFICE_AWAITING, 'rental_job_card_crew_report_id' => $report->id]);
            $sent = $drafts->count();

            $pricingRequestIds = $drafts->where('origin', RentalJobCardLine::ORIGIN_CREW_PRICING)->pluck('rental_job_card_price_request_id')->filter()->unique();
            if ($pricingRequestIds->isNotEmpty()) {
                $requestId = (int) $pricingRequestIds->first();
                RentalJobCardPriceRequest::withoutGlobalScopes()->whereKey($pricingRequestIds->all())
                    ->where('status', RentalJobCardPriceRequest::STATUS_OPEN)
                    ->update([
                        'status' => RentalJobCardPriceRequest::STATUS_SUBMITTED,
                        'submitted_at' => now(),
                        'submitted_label' => mb_substr($ctx->actorLabel, 0, 191),
                        'submitted_ip' => $ctx->ip,
                        'submitted_device' => $ctx->userAgent,
                    ]);
            }

            $note = "{$sent} line" . ($sent === 1 ? '' : 's') . " ({$ctx->actorLabel}" . ($ctx->ip ? ", IP {$ctx->ip}" : '') . ')'
                . ($findings !== '' ? ' - ' . \Illuminate\Support\Str::limit(preg_replace('/\s+/u', ' ', $findings), 200) : '');
            $card->logUpdate('crew_lines_sent', null, $note);
            if ($requestId) {
                $card->logUpdate('pricing_submitted', null, "Crew sent their prices ({$ctx->actorLabel}" . ($ctx->ip ? ", IP {$ctx->ip}" : '') . ')');
            }
        });

        if ($duplicate) {
            return 0;
        }

        RentalCrewLinesSubmitted::dispatch($card, $sent, $ctx->via, $requestId, null, $reportId);
        $this->touchToken($ctx);

        return $sent;
    }

    /** @return array<string, mixed> the line fields, validated in plain language (the crew sees these messages). */
    private function cleanLineData(RentalJobCard $card, array $data, CrewViewContext $ctx): array
    {
        $pricesOn = \App\Models\RentalWorkOrderSetting::capturePricesOnJobCardsFor($card->agency_id);

        $item = null;
        if (! empty($data['rental_catalogue_item_id'])) {
            $item = RentalCatalogueItem::withoutGlobalScopes()->where('agency_id', $ctx->agencyId)->where('is_active', true)
                ->with('catalogueUnit')->find((int) $data['rental_catalogue_item_id']);
            if (! $item) {
                throw new \InvalidArgumentException('That item is not on the list — pick another or type a description.');
            }
        }

        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '' && $item) {
            $description = (string) $item->description;
        }
        if ($description === '') {
            throw new \InvalidArgumentException('Say what the part or work is — pick an item or type a description.');
        }
        if (mb_strlen($description) > 255) {
            throw new \InvalidArgumentException('The description is too long (255 characters at most).');
        }

        // AT-459 — a picked catalogue item decides Part or Labour for the crew (the office may override an item's kind on its own
        // lines; a crew must not, or "Labour" could be turned into a "Part" just to type a price against it).
        $type = $item?->kind() ?? (string) ($data['type'] ?? RentalCatalogueItem::TYPE_PART);
        if (! in_array($type, [RentalCatalogueItem::TYPE_PART, RentalCatalogueItem::TYPE_LABOUR], true)) {
            throw new \InvalidArgumentException('Choose Part or Labour.');
        }
        $isLabour = $type === RentalCatalogueItem::TYPE_LABOUR;

        // Parts: how many (default 1). Labour: HOURS — there is no sensible default, so it must be typed.
        $quantityRaw = $data['quantity'] ?? null;
        if ($quantityRaw === '' || $quantityRaw === null) {
            if ($isLabour) {
                throw new \InvalidArgumentException('Enter the hours worked (a number above 0).');
            }
            $quantityRaw = 1;
        }
        if (! is_numeric($quantityRaw) || (float) $quantityRaw <= 0 || (float) $quantityRaw > 100000) {
            throw new \InvalidArgumentException($isLabour ? 'Enter the hours worked (a number above 0).' : 'Enter how many (a number above 0).');
        }

        // Labour is counted in the catalogue item's own unit (Hour / Day / Call-out); typed from scratch it is hours.
        $unit = $isLabour ? '' : trim((string) ($data['unit'] ?? ''));
        if ($unit === '' && $item) {
            $unit = (string) ($item->catalogueUnit?->name ?? '');
        }
        if ($unit === '' && $isLabour) {
            $unit = 'Hour';
        }
        if (mb_strlen($unit) > 30) {
            throw new \InvalidArgumentException('The unit is too long (30 characters at most).');
        }

        $unitCost = null;
        $costRaw = $data['unit_cost'] ?? null;
        $costTyped = $costRaw !== null && $costRaw !== '';
        if ($pricesOn) {
            if ($isLabour) {
                // The crew never prices labour. Cost = hours x the catalogue item's cost rate, worked out here (CrewJobService /
                // RentalPricingService::syncCostTotal) and never read from the request. No item, or an item with no cost on file:
                // the line arrives with a blank cost for the office to price.
                if ($costTyped) {
                    throw new \InvalidArgumentException('Labour is priced by the office — enter the hours only.');
                }
                // AT-459 NOTE for the catalogue pricing change (cc5): the labour rate AND the part prefill both read the catalogue cost
                // through RentalJobCardVatService::catalogueDefaultCostForLine() — when the new cost column lands, change it THERE.
                $unitCost = $item ? app(RentalJobCardVatService::class)->catalogueDefaultCostForLine($item, $card->agency ?? Agency::withoutGlobalScopes()->find($card->agency_id)) : null;
            } elseif ($costTyped) {
                if (! is_numeric($costRaw) || (float) $costRaw < 0 || (float) $costRaw > 99999999) {
                    throw new \InvalidArgumentException('Enter the cost as a number, 0 or more.');
                }
                $unitCost = round((float) $costRaw, 2);
            } elseif ($item) {
                // A part picked from the list: the cost on file stands unless the crew typed what they actually paid.
                $unitCost = app(RentalJobCardVatService::class)->catalogueDefaultCostForLine($item, $card->agency ?? Agency::withoutGlobalScopes()->find($card->agency_id));
            } else {
                throw new \InvalidArgumentException('Enter what it cost you (the actual cost).');
            }
        }

        $note = trim((string) ($data['note'] ?? ''));
        if (mb_strlen($note) > 1000) {
            throw new \InvalidArgumentException('The note is too long (1000 characters at most).');
        }

        return [
            'rental_catalogue_item_id' => $item?->id,
            'code' => $item?->code,
            'type' => $type,
            'description' => $description,
            'unit' => $unit !== '' ? $unit : null,
            'quantity' => round((float) $quantityRaw, 2),
            'unit_cost' => $unitCost,
            'crew_note' => $note !== '' ? $note : null,
        ];
    }

    private function assertPhotoCount(int $new, int $existing): void
    {
        if ($new + $existing > self::MAX_LINE_PHOTOS) {
            throw new \InvalidArgumentException('You can add up to ' . self::MAX_LINE_PHOTOS . ' photos to one line.');
        }
    }

    /** @param array<int, UploadedFile> $photos */
    private function storeLinePhotos(RentalJobCard $card, RentalJobCardLine $line, array $photos, CrewViewContext $ctx): void
    {
        $service = app(RentalJobCardService::class);
        foreach (array_values($photos) as $file) {
            $service->storePhoto($card, $file, RentalJobCardLine::PHOTO_TYPE, null, null, null, $ctx->photoVia(), false, $line->id);
        }
    }

    /**
     * AT-459 — the receipt for a PART line: what the crew paid, proved. Stored like a line photo (against the line, not in the job gallery)
     * but with its own photo_type so it never appears in the up-to-3 "Photos" count, the job gallery, or anything the owner or tenant sees.
     * A newer receipt supersedes an older one on screen (photo rows are immutable); labour has no receipt.
     */
    private function storeReceipt(RentalJobCard $card, RentalJobCardLine $line, ?UploadedFile $receipt, CrewViewContext $ctx): void
    {
        app(RentalJobCardService::class)->attachLineReceipt($card, $line, $receipt, null, $ctx->photoVia());
    }

    /** Only the crew's own, still-draft, live line of THIS card — anything else is the same 404/refusal a stranger would get. */
    private function assertOwnDraft(RentalJobCard $card, RentalJobCardLine $line): void
    {
        abort_unless((int) $line->rental_job_card_id === (int) $card->id && ! $line->trashed(), 404);
        abort_unless(in_array($line->origin, [RentalJobCardLine::ORIGIN_CREW_PRICING, RentalJobCardLine::ORIGIN_CREW_EXTRA], true), 404);
        if ($line->office_status !== RentalJobCardLine::OFFICE_CREW_DRAFT) {
            throw new \LogicException('This line has already been sent to the office, so it can no longer be changed here.');
        }
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
}
