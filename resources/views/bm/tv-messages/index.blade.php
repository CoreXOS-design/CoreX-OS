@extends('layouts.corex-app')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
@php
    $u = auth()->user();
    $bmBranchId = (int)($u?->effectiveBranchId() ?? ($u->branch_id ?? 0));
    $bmBranchName = '';
    if (!empty($branches) && $bmBranchId) {
        $found = $branches->firstWhere('id', $bmBranchId);
        $bmBranchName = $found?->name ?? '';
    }
@endphp

<div class="w-full space-y-6">

    {{-- Page Header (Pattern A — branded) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">TV Messages (Branch)</h1>
                <p class="text-sm text-white/60">
                    Add messages for your branch TV. Admin can add global messages visible on all TVs.
                </p>
            </div>
            @if($bmBranchId)
                <div class="inline-flex items-center gap-2 text-xs px-3 py-1 rounded-md" style="background: rgba(255,255,255,0.1); color: rgba(255,255,255,0.8);">
                    <span style="opacity: 0.7;">Branch:</span>
                    <span class="font-semibold">{{ $bmBranchName ?: ('#'.$bmBranchId) }}</span>
                </div>
            @endif
        </div>
    </div>

    {{-- Flash Messages --}}
    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--ds-green);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <div class="flex-1">{{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" style="color: var(--ds-crimson);">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 2a10 10 0 100 20 10 10 0 000-20z" />
            </svg>
            <div class="flex-1">{{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Add Message Form --}}
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Add TV Message</h2>

        <form method="POST" action="{{ route('bm.tv-messages.store') }}"
              class="space-y-4">
            @csrf
            <input type="hidden" name="branch_id" value="{{ $bmBranchId }}">

            <div>
                <label for="tv-message-input" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Message</label>
                <input id="tv-message-input" name="message" required
                       class="w-full rounded-md text-sm px-3 py-2 transition-all duration-300 placeholder:opacity-50"
                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                       placeholder="Motivational message, announcement, etc.">

                <div class="mt-3">
                    <div class="text-[0.6875rem] uppercase tracking-wider mb-2" style="color: var(--text-muted);">Insert values</div>
                    <div class="flex flex-wrap gap-1.5">
                        @php
                            $__tvPh = [
                                '{{branch_name}}','{{period}}',
                                '{{deals_target}}','{{deals_actual}}','{{deals_remaining}}',
                                '{{value_target}}','{{value_actual}}','{{value_remaining}}',
                                '{{points_target}}','{{points_actual}}','{{points_status}}',
                                '{{listings_active}}','{{listings_avg_dom}}','{{listings_stale}}','{{listings_expiring}}','{{listings_expired}}',
                            ];
                        @endphp
                        @foreach($__tvPh as $ph)
                            <button type="button"
                                class="tv-ph-chip px-2 py-1 rounded-md text-xs transition-all duration-300"
                                style="border: 1px solid var(--border); background: var(--surface-2); color: var(--text-secondary);"
                                data-ph="{{ $ph }}" onclick="window.__tvInsertPh(this)">
                                {{ $ph }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                <div class="md:col-span-3">
                    <label for="tv-display-area" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Show on</label>
                    <select id="tv-display-area" name="display_area"
                            class="w-full rounded-md text-sm px-3 py-2 transition-all duration-300"
                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="both" style="background: var(--surface); color: var(--text-primary);" selected>Hero + Ticker</option>
                        <option value="hero" style="background: var(--surface); color: var(--text-primary);">Hero only</option>
                        <option value="ticker" style="background: var(--surface); color: var(--text-primary);">Ticker only</option>
                    </select>
                </div>

                <div class="md:col-span-2 flex items-center gap-2">
                    <input type="hidden" name="is_enabled" value="0">
                    <input id="tv-is-enabled" type="checkbox" name="is_enabled" value="1" checked
                           class="rounded" style="border-color: var(--border); accent-color: var(--brand-button, #0ea5e9);">
                    <label for="tv-is-enabled" class="text-sm cursor-pointer" style="color: var(--text-secondary);">Enabled</label>
                </div>

                <div class="md:col-span-2">
                    <button class="w-full corex-btn-primary">Add Message</button>
                </div>
            </div>
        </form>
    </div>

    {{-- Branch Messages List --}}
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="px-5 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Your Branch Messages</h2>
            <div class="text-xs" style="color: var(--text-muted);">{{ number_format(count($messages)) }} message{{ count($messages) !== 1 ? 's' : '' }}</div>
        </div>

        <div>
            @forelse($messages as $m)
                <div class="px-5 py-4 space-y-3" style="border-bottom: 1px solid var(--border);">
                    <form method="POST"
                          action="{{ route('bm.tv-messages.update', $m->id) }}"
                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                        @csrf
                        <input type="hidden" name="branch_id" value="{{ $bmBranchId }}">

                        <div class="md:col-span-7">
                            <label for="tv-msg-{{ $m->id }}" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Message</label>
                            <input id="tv-msg-{{ $m->id }}" name="message"
                                   value="{{ $m->message }}"
                                   class="w-full rounded-md text-sm px-3 py-2 transition-all duration-300"
                                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        </div>

                        <div class="md:col-span-2">
                            <label for="tv-show-{{ $m->id }}" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Show on</label>
                            <select id="tv-show-{{ $m->id }}" name="display_area"
                                    class="w-full rounded-md text-sm px-3 py-2 transition-all duration-300"
                                    style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                <option value="both" style="background: var(--surface); color: var(--text-primary);" {{ (($m->display_area ?? 'both') === 'both') ? 'selected' : '' }}>Hero + Ticker</option>
                                <option value="hero" style="background: var(--surface); color: var(--text-primary);" {{ (($m->display_area ?? 'both') === 'hero') ? 'selected' : '' }}>Hero only</option>
                                <option value="ticker" style="background: var(--surface); color: var(--text-primary);" {{ (($m->display_area ?? 'both') === 'ticker') ? 'selected' : '' }}>Ticker only</option>
                            </select>
                        </div>

                        <div class="md:col-span-1 flex items-center gap-2">
                            <input type="hidden" name="is_enabled" value="0">
                            <input id="tv-on-{{ $m->id }}" type="checkbox"
                                   name="is_enabled"
                                   value="1"
                                   {{ $m->is_enabled ? 'checked' : '' }}
                                   class="rounded" style="border-color: var(--border); accent-color: var(--brand-button, #0ea5e9);">
                            <label for="tv-on-{{ $m->id }}" class="text-sm cursor-pointer" style="color: var(--text-secondary);">On</label>
                        </div>

                        <div class="md:col-span-2 flex items-center gap-2">
                            <button class="flex-1 corex-btn-primary">Save</button>
                        </div>
                    </form>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-xs flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                            <span>
                                Created by: <span class="font-semibold" style="color: var(--text-secondary);">{{ $m->creator->name ?? 'System' }}</span>
                                <span style="opacity: 0.7;">({{ $m->creator->email ?? '-' }})</span>
                            </span>

                            @if(is_null($m->branch_id))
                                <span class="ds-badge ds-badge-info">Global</span>
                            @else
                                <span class="ds-badge ds-badge-success">Branch</span>
                            @endif
                        </div>

                        <form method="POST"
                              action="{{ route('bm.tv-messages.delete', $m->id) }}"
                              onsubmit="return confirm('Delete message?');">
                            @csrf
                            <button class="tv-delete-btn text-xs font-semibold transition-colors duration-150" style="color: var(--ds-crimson);">
                                Delete
                            </button>
                        </form>
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 4v16M17 4v16M3 8h4m10 0h4M3 12h18M3 16h4m10 0h4M4 20h16a1 1 0 001-1V5a1 1 0 00-1-1H4a1 1 0 00-1 1v14a1 1 0 001 1z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No TV messages yet</h3>
                    <p class="text-sm mb-4" style="color: var(--text-muted);">Use the form above to add your first branch message.</p>
                    <button type="button" onclick="document.getElementById('tv-message-input').focus()" class="corex-btn-primary text-sm">Add your first message</button>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Global Messages (read-only) --}}
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="px-5 py-4 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">Global Messages (Admin)</h2>
            <div class="text-xs" style="color: var(--text-muted);">{{ number_format(count($globalMessages ?? [])) }} global</div>
        </div>

        <div>
            @forelse(($globalMessages ?? []) as $gm)
                <div class="px-5 py-4 space-y-3" style="border-bottom: 1px solid var(--border);">
                    <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                        <div class="md:col-span-7">
                            <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Message</div>
                            <div class="rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                {{ $gm->message }}
                            </div>

                            @if(!empty($gm->title))
                                <div class="mt-1.5 text-xs" style="color: var(--text-muted);">
                                    Title: <span class="font-semibold" style="color: var(--text-secondary);">{{ $gm->title }}</span>
                                </div>
                            @endif
                        </div>

                        <div class="md:col-span-3">
                            <div class="text-xs font-medium mb-1" style="color: var(--text-secondary);">Show on</div>
                            <div class="text-sm font-semibold" style="color: var(--text-primary);">
                                @php($da = $gm->display_area ?? 'both')
                                @if($da === 'hero')
                                    Hero only
                                @elseif($da === 'ticker')
                                    Ticker only
                                @else
                                    Hero + Ticker
                                @endif
                            </div>

                            <div class="mt-2 text-xs flex items-center gap-2" style="color: var(--text-muted);">
                                <span>Status:</span>
                                @php($on = (bool)($gm->is_enabled ?? false))
                                @if($on)
                                    <span class="ds-badge ds-badge-success">On</span>
                                @else
                                    <span class="ds-badge ds-badge-default">Off</span>
                                @endif
                            </div>

                            @if(!empty($gm->starts_at) || !empty($gm->ends_at))
                                <div class="mt-1.5 text-xs" style="color: var(--text-muted);">
                                    Window:
                                    <span class="font-semibold" style="color: var(--text-secondary);">
                                        {{ $gm->starts_at ? \Illuminate\Support\Carbon::parse($gm->starts_at)->format('Y-m-d') : '—' }}
                                        →
                                        {{ $gm->ends_at ? \Illuminate\Support\Carbon::parse($gm->ends_at)->format('Y-m-d') : '—' }}
                                    </span>
                                </div>
                            @endif
                        </div>

                        <div class="md:col-span-2">
                            <span class="ds-badge ds-badge-info">Global</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="text-xs flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                            <span>
                                Created by: <span class="font-semibold" style="color: var(--text-secondary);">{{ $gm->creator->name ?? 'System' }}</span>
                                <span style="opacity: 0.7;">({{ $gm->creator->email ?? '-' }})</span>
                            </span>
                        </div>
                        <div class="text-xs" style="color: var(--text-muted);">Read-only (managed by Admin)</div>
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 12l2-2m0 0l7-7 7 7M5 10v10a1 1 0 001 1h3m10-11l2 2m-2-2v10a1 1 0 01-1 1h-3m-6 0a1 1 0 001-1v-4a1 1 0 011-1h2a1 1 0 011 1v4a1 1 0 001 1m-6 0h6" />
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">No global messages</h3>
                    <p class="text-sm" style="color: var(--text-muted);">Admin can add messages visible across all branch TVs.</p>
                </div>
            @endforelse
        </div>
    </div>

</div>

<style>
    .tv-ph-chip:hover {
        background: var(--surface) !important;
        color: var(--text-primary) !important;
        border-color: var(--border-hover) !important;
    }
    .tv-delete-btn:hover {
        opacity: 0.7;
    }
    #tv-message-input:focus,
    [id^="tv-msg-"]:focus,
    #tv-display-area:focus,
    [id^="tv-show-"]:focus {
        border-color: var(--brand-button, #0ea5e9) !important;
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button, #0ea5e9) 20%, transparent) !important;
        outline: none;
    }
</style>

{{-- TV_INSERT_PH_JS_2026 --}}
<script>
(function(){
  function insertAtCursor(el, text) {
    if (!el) return;
    el.focus();

    if (typeof el.selectionStart === 'number' && typeof el.selectionEnd === 'number') {
      const start = el.selectionStart;
      const end = el.selectionEnd;
      const v = el.value || '';
      el.value = v.slice(0, start) + text + v.slice(end);

      const pos = start + text.length;
      el.selectionStart = el.selectionEnd = pos;
      return;
    }

    el.value = (el.value || '') + text;
  }

  window.__tvInsertPh = function(btn){
    try {
      const token = btn && btn.getAttribute('data-ph') ? btn.getAttribute('data-ph') : '';
      if (!token) return;

      const form = btn.closest('form');
      let input = form ? form.querySelector('input[name="message"]') : null;

      if (!input) input = document.querySelector('input[name="message"]');

      insertAtCursor(input, token);
    } catch (e) {
      console && console.warn && console.warn('tv placeholder insert failed', e);
    }
  };
})();
</script>

@endsection
