<?php

namespace App\Services\Rentals;

use App\Models\RentalCatalogueItem;
use App\Models\RentalJobCard;
use App\Models\RentalJobCardLine;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * .ai/specs/rental-work-orders.md §17.43 — the ONE implementation of the crew's line handlers (add / change / remove / undo).
 *
 * `CrewJobLinkController` (the per-job link) and `CrewPageController` (the crew home page) used to carry a full copy each. They now
 * only resolve their token into a `CrewViewContext`, call this, and decide how to answer (a redirect with a banner for a plain form
 * post, JSON for the no-reload row). Request shape, validation messages, the fields read, the retry key and the audit wording live here
 * once, so a crew change cannot land in one place and miss the other.
 *
 * The rules themselves (parts carry cost, labour carries hours only, nothing about selling or markup) are CrewJobService's and are
 * untouched. Plain values only: the row returned is CrewPricingBlock::rowFor() - a cost, never a selling price.
 */
final class CrewLineEntryService
{
    /** The fields a line is built from. `is_extra` is read on add only (a line cannot change its price-request membership later). */
    private const ADD_FIELDS = ['type', 'description', 'rental_catalogue_item_id', 'quantity', 'unit', 'unit_cost', 'note', 'is_extra'];
    private const EDIT_FIELDS = ['type', 'description', 'rental_catalogue_item_id', 'quantity', 'unit', 'unit_cost', 'note'];

    /** How long a retry key remembers the line it created: long enough for a phone to find signal again, short enough to be forgotten. */
    private const KEY_TTL_MINUTES = 60;

    public function __construct(private readonly CrewJobService $jobs)
    {
    }

    /** The validation that is about the REQUEST's shape; what the numbers and words mean is CrewJobService's (it speaks plain language). */
    public static function rules(): array
    {
        return [
            'photos' => ['nullable', 'array', 'max:' . CrewJobService::MAX_LINE_PHOTOS],
            'photos.*' => ['file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            // AT-459 — the receipt for a part: from the camera ("Take photo") or the phone's files, one or the other.
            'receipt_camera' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'receipt_file' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,heic,heif', 'max:51200'],
            'client_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9-]{8,64}$/'],
        ];
    }

    public static function messages(): array
    {
        return [
            'photos.max' => 'You can add up to ' . CrewJobService::MAX_LINE_PHOTOS . ' photos to one line.',
            'photos.*.mimes' => 'Photos must be JPG, PNG, WEBP or HEIC.',
            'photos.*.max' => 'A photo is too large — each one can be up to 50 MB.',
            'receipt_camera.mimes' => 'The receipt must be a JPG, PNG, WEBP or HEIC photo.',
            'receipt_file.mimes' => 'The receipt must be a JPG, PNG, WEBP or HEIC photo.',
            'receipt_camera.max' => 'The receipt photo is too large — it can be up to 50 MB.',
            'receipt_file.max' => 'The receipt photo is too large — it can be up to 50 MB.',
            'client_key.regex' => 'That did not save - please try again.',
        ];
    }

    /**
     * @return array{line: RentalJobCardLine, row: array<string, mixed>, draft_count: int, message: string, audit: string, replayed: bool}
     *
     * @throws \Illuminate\Validation\ValidationException the request's shape is wrong
     * @throws \InvalidArgumentException the crew can fix it (the message is plain language)
     * @throws \LogicException the card is closed, or the line was already sent to the office
     */
    public function add(RentalJobCard $card, Request $request, CrewViewContext $ctx): array
    {
        $request->validate(self::rules(), self::messages());

        $key = $request->input('client_key');
        $jobCards = app(RentalJobCardService::class);

        $create = function () use ($card, $request, $ctx, $key, $jobCards): array {
            // a retried tap, or a phone replaying a queued add hours later: the first try got through, only the answer was lost - hand back
            // that line, never a second one. The key is stored on the line itself (no expiry, survives a deploy).
            $existing = $jobCards->lineForClientKey($card, $key);
            if ($existing && $this->isCrewLine($existing)) {
                return $this->result($card, $existing, 'Added to your list.', '', true);
            }
            $storeKey = $existing ? null : ($key ?: null); // a key that belongs to an office line is never reused (the pair is unique)

            $duplicate = $this->duplicateOnTheCard($card, $request->only(self::ADD_FIELDS));
            $note = '';
            if ($duplicate) {
                // JavaScript on: ask first. A plain form post cannot be asked, so it saves and says so in the banner.
                if ($this->canBeAsked($request) && ! $request->boolean('add_anyway')) {
                    throw new CrewLineLooksDuplicate($duplicate);
                }
                $note = ' Note: the office already has "' . $duplicate->description . '" on the job card.';
            }

            $line = $this->jobs->addLine(
                $card,
                $request->only(self::ADD_FIELDS) + ['client_key' => $storeKey],
                array_values($request->file('photos', [])),
                $ctx,
                $this->receiptFrom($request),
            );

            return $this->result(
                $card,
                $line,
                'Added to your list. Nothing has gone to the office yet - press "Send to office" when your list is ready.' . $note,
                'Added a ' . $line->type . ' line (draft): ' . $line->description . ($duplicate ? ' (the office already had it)' : ''),
            );
        };

        // two taps at once with the same key queue here, so the second finds the first one's line
        return $key ? Cache::lock("crew-line-key:{$card->id}:{$key}:lock", 15)->block(10, $create) : $create();
    }

    /** Only the crew's own lines are handed back for a key (an office line can never be returned to a public link, whatever key is sent). */
    private function isCrewLine(RentalJobCardLine $line): bool
    {
        return in_array($line->origin, [RentalJobCardLine::ORIGIN_CREW_PRICING, RentalJobCardLine::ORIGIN_CREW_EXTRA], true);
    }

    private function canBeAsked(Request $request): bool
    {
        return $request->expectsJson() || $request->ajax();
    }

    /**
     * §17.43 / audit C-E3 - a PART the office has already accepted onto this card: the same catalogue item, or (free-typed, no item) the
     * same words. A crew line has no task of its own, so the match is on the card, whichever task the office filed it under. Labour is
     * never flagged: another call-out or more hours is normal. Drafts and pending crew lines are not "the office already has it".
     */
    private function duplicateOnTheCard(RentalJobCard $card, array $data): ?RentalJobCardLine
    {
        $itemId = (int) ($data['rental_catalogue_item_id'] ?? 0);
        $item = $itemId > 0
            ? RentalCatalogueItem::withoutGlobalScopes()->where('agency_id', $card->agency_id)->find($itemId)
            : null;
        $type = $item?->kind() ?? (string) ($data['type'] ?? RentalCatalogueItem::TYPE_PART);
        if ($type !== RentalCatalogueItem::TYPE_PART) {
            return null;
        }

        $query = RentalJobCardLine::withoutGlobalScopes()
            ->where('rental_job_card_id', $card->id)->whereNull('deleted_at')
            ->where('office_status', RentalJobCardLine::OFFICE_ACCEPTED)
            ->where('type', RentalCatalogueItem::TYPE_PART);

        if ($item) {
            $query->where('rental_catalogue_item_id', $item->id);
        } else {
            $words = mb_strtolower(trim((string) ($data['description'] ?? '')));
            if ($words === '') {
                return null;
            }
            $query->whereNull('rental_catalogue_item_id')->whereRaw('LOWER(TRIM(description)) = ?', [$words]);
        }

        return $query->orderBy('id')->first();
    }

    /** @return array{line: RentalJobCardLine, row: array<string, mixed>, draft_count: int, message: string, audit: string, replayed: bool} */
    public function update(RentalJobCard $card, int $lineId, Request $request, CrewViewContext $ctx): array
    {
        $request->validate(self::rules(), self::messages());
        // Scoped to THIS card by the query itself — a line id of another card is a 404.
        $line = RentalJobCardLine::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->whereKey($lineId)->firstOrFail();

        $this->jobs->editDraft(
            $card,
            $line,
            $request->only(self::EDIT_FIELDS),
            array_values($request->file('photos', [])),
            $ctx,
            $this->receiptFrom($request),
        );

        return $this->result($card, $line->refresh(), 'Line changed.', 'Changed a draft line: ' . $line->description);
    }

    /** @return array{line: RentalJobCardLine, row: array<string, mixed>, draft_count: int, message: string, audit: string, replayed: bool} */
    public function archive(RentalJobCard $card, int $lineId, CrewViewContext $ctx): array
    {
        $line = RentalJobCardLine::withoutGlobalScopes()->where('rental_job_card_id', $card->id)->whereKey($lineId)->firstOrFail();

        $this->jobs->archiveDraft($card, $line, $ctx);

        return $this->result($card, $line, 'Line removed.', 'Removed a draft line: ' . $line->description);
    }

    /** @return array{line: RentalJobCardLine, row: array<string, mixed>, draft_count: int, message: string, audit: string, replayed: bool} */
    public function restore(RentalJobCard $card, int $lineId, CrewViewContext $ctx): array
    {
        $line = $this->jobs->restoreDraft($card, $lineId, $ctx);

        return $this->result($card, $line, 'Line put back on your list.', 'Put a removed draft line back: ' . $line->description);
    }

    /** How many lines are on the crew's list, not yet sent - drives the "Send to office (n)" bar. */
    public static function draftCount(RentalJobCard $card): int
    {
        return RentalJobCardLine::withoutGlobalScopes()
            ->where('rental_job_card_id', $card->id)->whereNull('deleted_at')
            ->whereIn('origin', [RentalJobCardLine::ORIGIN_CREW_PRICING, RentalJobCardLine::ORIGIN_CREW_EXTRA])
            ->where('office_status', RentalJobCardLine::OFFICE_CREW_DRAFT)->count();
    }

    private function receiptFrom(Request $request): ?\Illuminate\Http\UploadedFile
    {
        return $request->file('receipt_camera') ?? $request->file('receipt_file');
    }

    /** @return array{line: RentalJobCardLine, row: array<string, mixed>, draft_count: int, message: string, audit: string, replayed: bool} */
    private function result(RentalJobCard $card, RentalJobCardLine $line, string $message, string $audit, bool $replayed = false): array
    {
        return [
            'line' => $line,
            'row' => CrewPricingBlock::rowFor($line),
            'draft_count' => self::draftCount($card),
            'message' => $message,
            'audit' => $audit,
            'replayed' => $replayed,
        ];
    }
}
