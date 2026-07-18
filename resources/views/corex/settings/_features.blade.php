{{-- Settings → Features — per-agency feature registry (spec: corex-feature-registry.md §6.4).
     Grouped by category. MODULE features are toggles (post their feature key, saved to
     agency_features via FeatureSettingsController). The six switchboard-origin keys are
     read-only here (their home is their own settings section + onboarding). Core is Always on.
     §6.1: every toggle carries a hidden "0" companion so unchecked still saves false. --}}
@php
    $features = app(\App\Services\Features\AgencyFeatureService::class);
    $grouped  = $features->groupedForDisplay();
    $catalogue = $features->catalogue();
    $labelOf = fn ($k) => $catalogue[$k]['label'] ?? $k;
@endphp

<div class="space-y-6">
    <div>
        <h2 class="text-lg font-bold" style="color:var(--text-primary);">Features</h2>
        <p class="text-sm mt-1" style="color:var(--text-muted);">
            Turn whole modules on or off for your agency. Turning one off hides it for everyone and
            skips it in onboarding — it never deletes anything, and you can turn it back on any time.
            Access still depends on each person's role: a feature being on doesn't grant a permission.
        </p>
    </div>

    @if (session('success'))
        <div class="rounded-md px-3 py-2 text-sm"
             style="background:color-mix(in srgb, var(--brand-icon,#0ea5e9) 12%, transparent); color:var(--brand-default,#0b2a4a);">
            {{ session('success') }}
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.features.update') }}" class="space-y-6">
        @csrf

        @foreach ($grouped as $category => $rows)
            <div class="rounded-md" style="border:1px solid var(--border,#e5e7eb);">
                <div class="px-4 py-2 text-xs font-semibold uppercase tracking-wider"
                     style="background:var(--surface-2,#f8fafc); color:var(--text-muted,#64748b); border-bottom:1px solid var(--border,#e5e7eb);">
                    {{ $category }}
                </div>
                <div class="divide-y" style="border-color:var(--border,#e5e7eb);">
                    @foreach ($rows as $row)
                        <div class="flex items-start justify-between gap-4 px-4 py-3">
                            <div class="min-w-0">
                                <div class="text-sm font-semibold" style="color:var(--text-primary,#0f172a);">
                                    {{ $row['label'] }}
                                    @if ($row['kind'] === 'core')
                                        <span class="ml-2 text-[11px] font-medium px-1.5 py-0.5 rounded"
                                              style="background:var(--surface-2,#f1f5f9); color:var(--text-muted,#64748b);">Always on</span>
                                    @elseif ($row['kind'] === 'switchboard')
                                        <span class="ml-2 text-[11px] font-medium px-1.5 py-0.5 rounded"
                                              style="background:var(--surface-2,#f1f5f9); color:var(--text-muted,#64748b);">Managed in its own settings</span>
                                    @endif
                                </div>
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
                                        Turn on <strong>{{ $labelOf($row['blocked_by']) }}</strong> first — this depends on it.
                                    </p>
                                @endif
                            </div>

                            <div class="flex-shrink-0 mt-1">
                                @if ($row['kind'] === 'module')
                                    {{-- Live toggle — posts the feature key. --}}
                                    <label class="relative inline-flex items-center {{ $row['blocked_by'] ? 'opacity-40 cursor-not-allowed' : 'cursor-pointer' }}">
                                        <input type="hidden" name="{{ $row['key'] }}" value="0">
                                        <input type="checkbox" name="{{ $row['key'] }}" value="1" class="sr-only peer"
                                               @checked($row['enabled']) @disabled((bool) $row['blocked_by'])>
                                        <span class="w-11 h-6 rounded-full transition-colors bg-slate-300 peer-checked:bg-[var(--brand-button,#0ea5e9)]"></span>
                                        <span class="absolute left-0.5 top-0.5 w-5 h-5 rounded-full bg-white shadow transition-transform peer-checked:translate-x-5"></span>
                                    </label>
                                @else
                                    {{-- Core / switchboard — read-only status chip. --}}
                                    <span class="text-xs font-semibold px-2 py-1 rounded"
                                          style="background:color-mix(in srgb, {{ $row['enabled'] ? 'var(--brand-icon,#0ea5e9)' : 'var(--text-muted,#94a3b8)' }} 14%, transparent); color:{{ $row['enabled'] ? 'var(--brand-default,#0b2a4a)' : 'var(--text-muted,#64748b)' }};">
                                        {{ $row['enabled'] ? 'On' : 'Off' }}
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <div class="flex items-center justify-end pt-2">
            <button type="submit" class="rounded-md px-5 py-2.5 text-sm font-semibold text-white"
                    style="background:var(--brand-button,#0ea5e9);">
                Save features
            </button>
        </div>
    </form>
</div>
