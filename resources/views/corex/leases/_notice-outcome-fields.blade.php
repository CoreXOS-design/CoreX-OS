{{--
    .ai/specs/rental-renewals.md §19 — Johan's ruling 2026-10-05: the notice
    dialogs' three-way "what happens to the property" choice. The agent
    picks exactly one, every time — nothing pre-selected unless THIS exact
    dialog just failed validation and is reopening with what was entered
    ($isReopening, passed by the including dialog). `notice_outcome` is
    `required` on every radio in the group, so the browser itself blocks
    submit with none selected — the server re-validates the same rule
    (LeaseRenewalController::recordNotice()/changeNoticeOutcome()).

    Params:
      $isReopening             bool  — this dialog is reopening after ITS OWN failed submit
      $presetOutcome            ?string — pre-selected value for a "change later" dialog (not a fresh notice)
      $showAvailableFromOnPortals bool — default for the readvertise sub-option
--}}
@php
    $reopening = $isReopening ?? false;
    $currentOutcome = $reopening ? old('notice_outcome') : ($presetOutcome ?? null);
    $showPortalsDefault = $reopening
        ? old('show_available_from_on_portals') === '1'
        : ($showAvailableFromOnPortals ?? true);
@endphp
<div class="space-y-2" x-data="{ outcome: {{ \Illuminate\Support\Js::from($currentOutcome) }} }">
    <span class="text-xs font-medium block">What happens to the property? (required)</span>

    <label class="flex items-start gap-2 text-xs">
        <input type="radio" name="notice_outcome" value="readvertise" x-model="outcome" required class="mt-0.5">
        <span>Put back on the market, available from the day after move-out</span>
    </label>
    <div x-show="outcome === 'readvertise'" x-cloak class="ml-5">
        <label class="flex items-center gap-2 text-xs">
            <input type="hidden" name="show_available_from_on_portals" value="0">
            <input type="checkbox" name="show_available_from_on_portals" value="1" @checked($showPortalsDefault)>
            Show available-from date on portals
        </label>
    </div>

    <label class="flex items-start gap-2 text-xs">
        <input type="radio" name="notice_outcome" value="withdraw" x-model="outcome" required class="mt-0.5">
        <span>Withdraw the property (landlord is taking it back / property is lost)</span>
    </label>

    <label class="flex items-start gap-2 text-xs">
        <input type="radio" name="notice_outcome" value="leave" x-model="outcome" required class="mt-0.5">
        <span>Leave as is for now</span>
    </label>

    <x-input-error :messages="$reopening ? $errors->get('notice_outcome') : []" class="mt-1" />
</div>
