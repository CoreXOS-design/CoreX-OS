{{-- .ai/specs/rental-renewals.md §4-§9 — shared term inputs, included once per send-path form on the renewal screen. --}}
<div class="grid grid-cols-2 gap-3">
    <div>
        <label class="text-xs font-medium">New rent (R)</label>
        <input type="number" name="rental_amount" step="0.01" min="0" required value="{{ $lease->rental_amount }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
    </div>
    <div>
        <label class="text-xs font-medium">Deposit (R)</label>
        <input type="number" name="deposit_amount" step="0.01" min="0" value="{{ $lease->deposit_amount }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
    </div>
    <div>
        <label class="text-xs font-medium">New start date</label>
        <input type="date" name="start_date" required value="{{ $lease->end_date ? $lease->end_date->copy()->addDay()->toDateString() : '' }}" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
    </div>
    <div>
        <label class="text-xs font-medium">New end date</label>
        <input type="date" name="end_date" class="w-full rounded-md px-3 py-2 text-sm mt-1" style="border: 1px solid var(--border);">
    </div>
</div>
