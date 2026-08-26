<?php

declare(strict_types=1);

namespace App\Services\Docuperfect;

use App\Exceptions\Docuperfect\WebPackSlotException;
use App\Models\Docuperfect\Template;
use App\Models\Docuperfect\WebPack;
use App\Models\Docuperfect\WebPackItem;
use Illuminate\Support\Collection;

/**
 * HD-2 / P1-2a — resolves a web pack + the agent's slot picks into the exact, ordered set of
 * documents a ceremony will send. This is the SERVER-SIDE AUTHORITY on that question.
 *
 * `web_pack_items` has carried `slot_type` / `slot_group` / `slot_label` since the admin pack
 * builder shipped, and the wizard has a slot picker in JS that reads them. What was missing was
 * anyone on the server reading them: `ESignWizardController::store()` took the client's
 * `resolved_template_ids` and fed each one straight to `Template::find()`. That trusted the
 * browser with three things it must never be trusted with:
 *
 *   1. WHICH DOCUMENTS ARE IN THE PACK. Nothing checked that a posted id was an item of this
 *      pack at all — any template id in the database was accepted and sent.
 *   2. WHETHER THE REQUIRED ONES ARE THERE. A required slot could simply be omitted from the
 *      post, and the ceremony would go out without the document the pack exists to send.
 *   3. WHETHER THEY MAY BE E-SIGNED AT ALL. The single-template path hard-blocks alienation
 *      documents (ECTA §13(1)); the pack path never did. A sale agreement inside a web pack was
 *      one click from being e-signed — and a sale e-signed is VOID, not flagged.
 *
 * The slot vocabulary (as written by the admin builder, WebPackController):
 *
 *   required    always sent. The agent cannot drop it.
 *   selectable  belongs to a `slot_group`; EXACTLY ONE member of each group is sent. This is how
 *               a pack says "a mandate, and it is either the Sole or the Open one" — sending both
 *               is not a lesser bug than sending neither, it is a contradictory instruction.
 *   optional    sent only if the agent asked for it.
 */
final class WebPackSlotResolver
{
    public const SLOT_REQUIRED   = 'required';
    public const SLOT_SELECTABLE = 'selectable';
    public const SLOT_OPTIONAL   = 'optional';

    /**
     * The documents this pack sends, in pack order, for this selection.
     *
     * @param  int[]|null  $selectedTemplateIds  What the agent picked. Null/empty is legitimate:
     *                                           a pack of nothing but required slots needs no
     *                                           picking, and that is every pack built before the
     *                                           slot builder existed (slot_type defaults to
     *                                           'required', so they resolve exactly as before).
     * @return Collection<int,Template>
     *
     * @throws WebPackSlotException
     */
    public function resolve(WebPack $pack, ?array $selectedTemplateIds = null): Collection
    {
        /** @var Collection<int,WebPackItem> $items */
        $items = $pack->items()
            ->with('template')
            ->orderBy('sort_order')
            ->get()
            // A template soft-deleted out from under the pack leaves an item pointing at nothing.
            // Skip it rather than 500 — the pack is still sendable without it (BUILD_STANDARD §4).
            ->filter(fn (WebPackItem $item) => $item->template !== null)
            ->values();

        if ($items->isEmpty()) {
            throw new WebPackSlotException('This pack has no documents in it. Add at least one before sending.');
        }

        $selected = collect($selectedTemplateIds ?? [])
            ->map(fn ($id) => (int) $id)
            ->filter()
            ->unique();

        // (1) Every pick must be a document IN THIS PACK. The pack is the whole vocabulary of the
        // choice; anything else is a posted id we have no business sending.
        $inPack = $items->pluck('template_id')->map(fn ($id) => (int) $id)->unique();
        if ($selected->diff($inPack)->isNotEmpty()) {
            throw new WebPackSlotException(
                'That selection includes a document that is not part of this pack. Reopen the pack and choose again.'
            );
        }

        $resolved = collect();

        foreach ($items as $item) {
            $type = $item->slot_type ?: self::SLOT_REQUIRED;

            // Selectable items are decided per GROUP, below — not one at a time.
            if ($type === self::SLOT_SELECTABLE) {
                continue;
            }

            // (2) A required document is sent whether or not the client remembered to ask for it.
            if ($type === self::SLOT_REQUIRED || $selected->contains((int) $item->template_id)) {
                $resolved->push($item->template);
            }
        }

        foreach ($this->selectableGroups($items) as $group) {
            $resolved->push($this->resolveGroup($group, $selected));
        }

        // Keep pack order — the group members were resolved out of sequence above.
        $order = $items->pluck('template_id')->map(fn ($id) => (int) $id)->values()->all();
        $resolved = $resolved
            ->unique('id')
            ->sortBy(fn (Template $t) => array_search((int) $t->id, $order, true))
            ->values();

        if ($resolved->isEmpty()) {
            throw new WebPackSlotException('No documents were selected to send. Choose at least one.');
        }

        // (3) Eligibility is re-run on the RESOLVED set, not on the pack. That is the whole point:
        // which documents a pack sends depends on the variant chosen, so a pack that is
        // e-signable with one selection can be illegal with another. The question is only ever
        // answerable about the documents actually going out.
        foreach ($resolved as $template) {
            $this->assertEsignable($template);
        }

        return $resolved;
    }

    /**
     * Selectable items keyed by slot_group, in the pack's own order.
     *
     * @param  Collection<int,WebPackItem>  $items
     * @return Collection<int,Collection<int,WebPackItem>>
     */
    private function selectableGroups(Collection $items): Collection
    {
        return $items
            ->filter(fn (WebPackItem $item) => ($item->slot_type ?: self::SLOT_REQUIRED) === self::SLOT_SELECTABLE)
            // The builder defaults a selectable item's group to 1; an item that somehow lost its
            // group is treated as group 1 rather than becoming its own silent one-member group.
            ->groupBy(fn (WebPackItem $item) => (int) ($item->slot_group ?: 1));
    }

    /**
     * Exactly one member of a selectable group is sent. Neither "none" nor "all" is a safe
     * fallback here — a mandate pack that quietly sends nothing has failed the agent, and one
     * that sends both the Sole AND the Open mandate has sent the seller a contradiction. So both
     * are refused, by name, with the label the pack builder gave the choice.
     *
     * @param  Collection<int,WebPackItem>  $group
     */
    private function resolveGroup(Collection $group, Collection $selected): Template
    {
        $label = $this->groupLabel($group);
        $picked = $group->filter(fn (WebPackItem $item) => $selected->contains((int) $item->template_id));

        if ($picked->isEmpty()) {
            throw new WebPackSlotException("Choose which {$label} to send — the pack offers a choice and none was made.");
        }

        if ($picked->count() > 1) {
            throw new WebPackSlotException("Only one {$label} can be sent. Choose a single one.");
        }

        return $picked->first()->template;
    }

    /** @param  Collection<int,WebPackItem>  $group */
    private function groupLabel(Collection $group): string
    {
        $labelled = $group->first(fn (WebPackItem $item) => filled($item->slot_label));

        return $labelled ? trim((string) $labelled->slot_label) : 'document';
    }

    /**
     * May this document be e-signed, right now, on its own merits?
     *
     * `Template::isEsignBlocked()` is the canonical legal predicate (there is no
     * EsignEligibilityService — the model owns this). The model's `booted()` guard already
     * refuses to PERSIST is_esign=true on an alienation document, so the two checks agree; this
     * is the send-time gate that the pack path never had.
     */
    private function assertEsignable(Template $template): void
    {
        if ($template->isEsignBlocked()) {
            throw WebPackSlotException::esignBlocked((string) $template->name);
        }

        if (! $template->is_esign) {
            throw new WebPackSlotException(
                "“{$template->name}” is not enabled for e-signing. Enable it on the template, or remove it from this pack."
            );
        }
    }
}
