{{--
    Settings → Prospecting Setup → Buyer Match Tiers

    Inputs: $tier — associative array (from ProspectingConfigurationService::buyerMatchTiers
                    or BuyerMatchTier::defaultsFor) with keys:
                      strong_min_score / mid_min_score / weak_min_score
                      strong_label     / mid_label    / weak_label
                      show_weak_in_badge
--}}

@php
    $tier = $tier ?? \App\Models\Prospecting\BuyerMatchTier::defaultsFor(0);
@endphp

<div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
        Buyer Match Quality Thresholds
    </h3>
    <p class="text-sm" style="color: var(--text-secondary);">
        Define what counts as a <strong>strong</strong>, <strong>mid</strong>, and <strong>weak</strong>
        buyer match for your agency. Higher cutoffs make the strong tier more selective.
    </p>
    <p class="text-xs mt-2" style="color: var(--text-muted);">
        Scores range 0–100. Buyers below the weak floor are excluded entirely.
        Strong tier should be reserved for buyers genuinely likely to move on a pitch.
    </p>
</div>

<form method="POST" action="{{ route('settings.prospecting.buyer-match-tiers.update') }}">
    @csrf
    @method('PUT')

    <div class="space-y-3">
        {{-- Strong tier --}}
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex items-center gap-2 mb-3">
                <span class="text-xl">🟢</span>
                <input type="text" name="strong_label"
                       value="{{ old('strong_label', $tier['strong_label']) }}"
                       maxlength="30" required
                       class="text-base font-semibold bg-transparent border-0 p-0 outline-none focus:ring-0"
                       style="color: var(--ds-green, #10b981);">
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm" style="color: var(--text-secondary);">Minimum score:</label>
                <input type="number" name="strong_min_score" min="0" max="100" required
                       value="{{ old('strong_min_score', $tier['strong_min_score']) }}"
                       class="w-20 px-2 py-1 text-sm rounded"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <span class="text-xs" style="color: var(--text-muted);">and above</span>
            </div>
        </div>

        {{-- Mid tier --}}
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex items-center gap-2 mb-3">
                <span class="text-xl">🟡</span>
                <input type="text" name="mid_label"
                       value="{{ old('mid_label', $tier['mid_label']) }}"
                       maxlength="30" required
                       class="text-base font-semibold bg-transparent border-0 p-0 outline-none focus:ring-0"
                       style="color: var(--ds-amber, #f59e0b);">
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm" style="color: var(--text-secondary);">Minimum score:</label>
                <input type="number" name="mid_min_score" min="0" max="100" required
                       value="{{ old('mid_min_score', $tier['mid_min_score']) }}"
                       class="w-20 px-2 py-1 text-sm rounded"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <span class="text-xs" style="color: var(--text-muted);">to just below the strong cutoff</span>
            </div>
        </div>

        {{-- Weak tier --}}
        <div class="rounded-md p-4" style="background: var(--surface-2); border: 1px solid var(--border);">
            <div class="flex items-center gap-2 mb-3">
                <span class="text-xl">⚪</span>
                <input type="text" name="weak_label"
                       value="{{ old('weak_label', $tier['weak_label']) }}"
                       maxlength="30" required
                       class="text-base font-semibold bg-transparent border-0 p-0 outline-none focus:ring-0"
                       style="color: var(--text-muted);">
            </div>
            <div class="flex items-center gap-2 mb-3">
                <label class="text-sm" style="color: var(--text-secondary);">Minimum score:</label>
                <input type="number" name="weak_min_score" min="0" max="100" required
                       value="{{ old('weak_min_score', $tier['weak_min_score']) }}"
                       class="w-20 px-2 py-1 text-sm rounded"
                       style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);">
                <span class="text-xs" style="color: var(--text-muted);">to just below the mid cutoff. Scores under this floor are excluded entirely.</span>
            </div>
            <label class="flex items-start gap-2 text-sm" style="color: var(--text-secondary);">
                <input type="checkbox" name="show_weak_in_badge" value="1"
                       @checked(old('show_weak_in_badge', $tier['show_weak_in_badge']))
                       class="mt-0.5">
                <span>
                    Show weak tier count in the prospecting row badge
                    <span class="block text-xs" style="color: var(--text-muted);">
                        Uncheck to hide ⚪ from the at-a-glance badge. Weak buyers still appear in the side-panel drill-down.
                    </span>
                </span>
            </label>
        </div>
    </div>

    <div class="mt-4 flex items-center gap-2">
        <button type="submit"
                class="px-4 py-2 text-sm font-medium rounded"
                style="background: var(--brand-button); color: #fff;">
            Save Thresholds
        </button>
        <span class="text-xs" style="color: var(--text-muted);">
            Changes take effect immediately on the next Market Intelligence page load.
        </span>
    </div>
</form>
