@extends('layouts.corex-app')

@section('corex-content')
<style>
    /* Page header inline status filter — sits on the branded header */
    .tv-header-filter {
        height: 2.25rem;
        padding: 0 0.625rem;
        border-radius: 6px;
        font-size: 0.8125rem;
        background: rgba(255,255,255,0.08);
        color: #fff;
        border: 1px solid rgba(255,255,255,0.25);
        cursor: pointer;
        transition: all 300ms ease;
    }
    .tv-header-filter:hover {
        background: rgba(255,255,255,0.14);
        border-color: rgba(255,255,255,0.45);
    }
    .tv-header-filter:focus {
        outline: none;
        border-color: rgba(255,255,255,0.7);
        box-shadow: 0 0 0 2px rgba(255,255,255,0.15);
    }

    /* Token-aware focus ring for inputs/selects on this page */
    .tv-input {
        transition: border-color 300ms ease, box-shadow 300ms ease;
    }
    .tv-input:focus {
        outline: none;
        border-color: var(--brand-button, #0ea5e9);
        box-shadow: 0 0 0 2px color-mix(in srgb, var(--brand-button, #0ea5e9) 20%, transparent);
    }

    /* Placeholder token pill — CSS hover instead of inline JS */
    .tv-ph-pill {
        padding: 0.25rem 0.5rem;
        border-radius: 6px;
        font-size: 0.75rem;
        background: var(--surface-2);
        color: var(--text-secondary);
        border: 1px solid var(--border);
        transition: all 300ms ease;
        cursor: pointer;
    }
    .tv-ph-pill:hover {
        background: var(--surface);
        color: var(--text-primary);
        border-color: var(--border-hover, var(--border));
    }

    /* Row text actions */
    .tv-action-link {
        font-size: 0.75rem;
        font-weight: 600;
        background: transparent;
        border: 0;
        cursor: pointer;
        transition: opacity 300ms ease;
    }
    .tv-action-link:hover { opacity: 0.7; }
    .tv-action-restore { color: var(--ds-green); }
    .tv-action-delete  { color: var(--ds-crimson); }
</style>

<div class="w-full space-y-6">

    {{-- Page Header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight tracking-tight">TV Messages</h1>
                <p class="text-sm text-white/60">Create global messages (all TVs) or branch-specific messages.</p>
            </div>
            <form method="GET" action="{{ route('admin.tv-messages') }}">
                <select name="status" onchange="this.form.submit()" class="tv-header-filter">
                    <option value="active" style="background: var(--surface); color: var(--text-primary);" {{ request('status', 'active') === 'active' ? 'selected' : '' }}>Active</option>
                    <option value="archived" style="background: var(--surface); color: var(--text-primary);" {{ request('status') === 'archived' ? 'selected' : '' }}>Archived</option>
                </select>
            </form>
        </div>
    </div>

    {{-- Flash Messages --}}
    @if (session('status'))
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-green);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
            <div class="flex-1"><strong>Saved.</strong> {{ session('status') }}</div>
        </div>
    @endif

    @if ($errors->any())
        <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent);
                    color: var(--text-primary);">
            <svg class="w-5 h-5 flex-shrink-0 mt-0.5" style="color: var(--ds-crimson);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M5.07 19h13.86c1.54 0 2.5-1.67 1.73-3L13.73 4a2 2 0 00-3.46 0L3.34 16c-.77 1.33.19 3 1.73 3z" />
            </svg>
            <div class="flex-1"><strong>Couldn’t save.</strong> {{ $errors->first() }}</div>
        </div>
    @endif

    {{-- Add Message Form --}}
    @if(!$showArchived)
    <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-lg font-semibold mb-4" style="color: var(--text-primary);">Add TV Message</h2>

        <form method="POST" action="{{ route('admin.tv-messages.store') }}" class="space-y-4">
            @csrf

            <div class="grid grid-cols-1 md:grid-cols-12 gap-4">
                <div class="md:col-span-3">
                    <label for="add_branch_id" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch (blank = global)</label>
                    <select id="add_branch_id" name="branch_id"
                            class="tv-input w-full rounded-md text-sm px-3 py-2"
                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="">Global</option>
                        @foreach($branches as $b)
                            <option value="{{ $b->id }}">{{ $b->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="md:col-span-9">
                    <label for="add_message" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Message</label>
                    <input id="add_message" name="message" required
                           class="tv-input w-full rounded-md text-sm px-3 py-2 placeholder:opacity-50"
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
                                <button type="button" class="tv-ph-pill" data-ph="{{ $ph }}" onclick="window.__tvInsertPh(this)">
                                    {{ $ph }}
                                </button>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-end">
                <div class="md:col-span-3">
                    <label for="add_display_area" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Show on</label>
                    <select id="add_display_area" name="display_area"
                            class="tv-input w-full rounded-md text-sm px-3 py-2"
                            style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                        <option value="both" selected>Hero + Ticker</option>
                        <option value="hero">Hero only</option>
                        <option value="ticker">Ticker only</option>
                    </select>
                </div>

                <div class="md:col-span-2 flex items-center gap-2">
                    <input type="hidden" name="is_enabled" value="0">
                    <input type="checkbox" id="add_is_enabled" name="is_enabled" value="1" checked
                           class="rounded" style="border-color: var(--border); accent-color: var(--brand-button, #0ea5e9);">
                    <label for="add_is_enabled" class="text-sm" style="color: var(--text-secondary);">Enabled</label>
                </div>

                <div class="md:col-span-2">
                    <button type="submit" class="w-full corex-btn-primary text-sm">Add Message</button>
                </div>
            </div>
        </form>
    </div>
    @endif

    {{-- Messages List --}}
    <div class="rounded-md overflow-hidden" style="border: 1px solid var(--border); background: var(--surface);">
        <div class="px-5 py-3 flex items-center justify-between" style="border-bottom: 1px solid var(--border);">
            <h2 class="text-lg font-semibold" style="color: var(--text-primary);">{{ $showArchived ? 'Archived Messages' : 'Existing Messages' }}</h2>
            <div class="text-xs" style="color: var(--text-muted);">{{ number_format(count($messages)) }} total</div>
        </div>

        <div>
            @forelse($messages as $m)
                <div class="px-5 py-4 space-y-3" style="border-bottom: 1px solid var(--border);">

                    @if($showArchived)
                        <div class="flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm" style="color: var(--text-primary);">{{ $m->message }}</div>
                                <div class="text-xs mt-1.5 flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                                    <span class="ds-badge ds-badge-warning">Archived</span>
                                    <span>{{ $m->branch?->name ?? 'Global' }}</span>
                                    <span>&middot;</span>
                                    <span>Created by: <span class="font-semibold" style="color: var(--text-secondary);">{{ $m->creator->name ?? 'System' }}</span></span>
                                </div>
                            </div>
                            <form method="POST" action="{{ route('admin.tv-messages.restore', $m->id) }}" class="inline shrink-0">
                                @csrf
                                <button type="submit" class="tv-action-link tv-action-restore">Restore</button>
                            </form>
                        </div>
                    @else
                        <form method="POST"
                              action="{{ route('admin.tv-messages.update', $m->id) }}"
                              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
                            @csrf

                            <div class="md:col-span-3">
                                <label for="branch_{{ $m->id }}" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Branch</label>
                                <select id="branch_{{ $m->id }}" name="branch_id"
                                        class="tv-input w-full rounded-md text-sm px-3 py-2"
                                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    <option value="">Global</option>
                                    @foreach($branches as $b)
                                        <option value="{{ $b->id }}" {{ $m->branch_id == $b->id ? 'selected' : '' }}>
                                            {{ $b->name }}
                                        </option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="md:col-span-5">
                                <label for="message_{{ $m->id }}" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Message</label>
                                <input id="message_{{ $m->id }}" name="message"
                                       value="{{ $m->message }}"
                                       class="tv-input w-full rounded-md text-sm px-3 py-2"
                                       style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                            </div>

                            <div class="md:col-span-2">
                                <label for="display_{{ $m->id }}" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">Show on</label>
                                <select id="display_{{ $m->id }}" name="display_area"
                                        class="tv-input w-full rounded-md text-sm px-3 py-2"
                                        style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                                    <option value="both" {{ (($m->display_area ?? 'both') === 'both') ? 'selected' : '' }}>Hero + Ticker</option>
                                    <option value="hero" {{ (($m->display_area ?? 'both') === 'hero') ? 'selected' : '' }}>Hero only</option>
                                    <option value="ticker" {{ (($m->display_area ?? 'both') === 'ticker') ? 'selected' : '' }}>Ticker only</option>
                                </select>
                            </div>

                            <div class="md:col-span-1 flex items-center gap-2">
                                <input type="hidden" name="is_enabled" value="0">
                                <input type="checkbox" id="enabled_{{ $m->id }}" name="is_enabled" value="1"
                                       {{ $m->is_enabled ? 'checked' : '' }}
                                       class="rounded" style="border-color: var(--border); accent-color: var(--brand-button, #0ea5e9);">
                                <label for="enabled_{{ $m->id }}" class="text-sm" style="color: var(--text-secondary);">On</label>
                            </div>

                            <div class="md:col-span-1">
                                <button type="submit" class="w-full corex-btn-primary text-sm">Save</button>
                            </div>
                        </form>

                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div class="text-xs flex flex-wrap items-center gap-2" style="color: var(--text-muted);">
                                <span>
                                    Created by: <span class="font-semibold" style="color: var(--text-secondary);">{{ $m->creator->name ?? 'System' }}</span>
                                    <span class="opacity-70">({{ $m->creator->email ?? '-' }})</span>
                                </span>

                                @if(is_null($m->branch_id))
                                    <span class="ds-badge ds-badge-info">Global</span>
                                @else
                                    <span class="ds-badge ds-badge-success">{{ $m->branch?->name ?? 'Branch' }}</span>
                                @endif
                            </div>

                            <form method="POST"
                                  action="{{ route('admin.tv-messages.delete', $m->id) }}"
                                  onsubmit="return confirm('Delete message?');">
                                @csrf
                                <button type="submit" class="tv-action-link tv-action-delete">Delete</button>
                            </form>
                        </div>
                    @endif

                </div>

            @empty
                <div class="py-12 px-6 text-center">
                    <div class="w-12 h-12 rounded-full mx-auto mb-4 flex items-center justify-center"
                         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 12%, transparent); color: var(--brand-icon, #0ea5e9);">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold mb-1" style="color: var(--text-primary);">
                        {{ $showArchived ? 'No archived messages' : 'No TV messages yet' }}
                    </h3>
                    <p class="text-sm mb-4" style="color: var(--text-muted);">
                        {{ $showArchived
                            ? 'Archived messages will appear here when you delete a message.'
                            : 'Use the form above to create your first global or branch-specific TV message.' }}
                    </p>
                    @if(!$showArchived)
                        <button type="button"
                                onclick="document.getElementById('add_message')?.focus(); document.getElementById('add_message')?.scrollIntoView({behavior:'smooth', block:'center'});"
                                class="corex-btn-primary text-sm">
                            Add First Message
                        </button>
                    @endif
                </div>
            @endforelse
        </div>
    </div>

</div>

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
