{{-- Onboarding capabilities step — AUTO-DERIVED module toggles (spec:
     corex-feature-registry.md §7). Renders every non-core, non-switchboard MODULE
     feature from the registry, grouped by category, as a toggle posting its feature
     key. Saved by FeatureSettingsController@update (agency_features). The six
     switchboard capability toggles are rendered separately by this step's `controls`
     (their own savers/stores). Adding a feature to config/corex-features.php surfaces
     it here automatically — Non-negotiable #10a made structural. --}}
@php
    $svc = app(\App\Services\Features\AgencyFeatureService::class);
    $grouped = $svc->groupedForDisplay($agency ?? null);
    $labelOf = fn ($k) => ($svc->catalogue()[$k]['label'] ?? $k);
@endphp

<div class="space-y-4">
    <div>
        <h2 class="text-sm font-bold" style="color:var(--text-primary,#0f172a);">The rest of your toolkit</h2>
        <p class="text-xs mt-1" style="color:var(--text-muted,#64748b);">
            Every module CoreX can run for you. Switch on what you'll use — each one you turn on is set
            up in the steps that follow (or from Settings later), and anything you leave off is simply
            hidden until you want it.
        </p>
    </div>

    @foreach ($grouped as $category => $rows)
        @php $modules = array_values(array_filter($rows, fn ($r) => $r['kind'] === 'module')); @endphp
        @if (count($modules))
            <div class="rounded-md" style="border:1px solid var(--border,#e5e7eb);">
                <div class="px-3 py-1.5 text-[11px] font-semibold uppercase tracking-wider"
                     style="background:var(--surface-2,#f8fafc); color:var(--text-muted,#64748b); border-bottom:1px solid var(--border,#e5e7eb);">
                    {{ $category }}
                </div>
                <div class="divide-y" style="border-color:var(--border,#e5e7eb);">
                    @foreach ($modules as $row)
                        <div class="flex items-start justify-between gap-3 px-3 py-2.5">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold" style="color:var(--text-primary,#0f172a);">{{ $row['label'] }}</div>
                                @if (!empty($row['explain']))
                                    <p class="text-xs mt-0.5" style="color:var(--text-muted,#64748b);">{{ $row['explain'] }}</p>
                                @endif
                                @if (!empty($row['affects']))
                                    <p class="text-[11px] mt-1" style="color:var(--text-muted,#94a3b8);">
                                        <span class="font-semibold">What this changes:</span> {{ $row['affects'] }}
                                    </p>
                                @endif
                                @if ($row['blocked_by'])
                                    <p class="text-[11px] mt-1" style="color:var(--ds-crimson,#e11d48);">
                                        Turn on <strong>{{ $labelOf($row['blocked_by']) }}</strong> first.
                                    </p>
                                @endif
                            </div>
                            <label class="relative inline-flex items-center flex-shrink-0 mt-1 {{ $row['blocked_by'] ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer' }}">
                                <input type="hidden" name="{{ $row['key'] }}" value="0">
                                <input type="checkbox" name="{{ $row['key'] }}" value="1" class="sr-only peer"
                                       @checked($row['enabled']) @disabled((bool) $row['blocked_by'])>
                                <span class="w-11 h-6 rounded-full transition-colors bg-slate-300 peer-checked:bg-[var(--brand-button,#0ea5e9)]"></span>
                                <span class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                            </label>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif
    @endforeach
</div>
