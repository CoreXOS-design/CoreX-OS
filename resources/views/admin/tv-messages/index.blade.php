@extends('layouts.corex')

@section('content')
<div class="max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-6">

    <div style="background:#0b2a4a;" class="rounded-2xl px-6 py-4">
        <h2 class="text-xl font-bold text-white leading-tight">TV Messages (Admin)</h2>
        <div class="text-sm text-white/60">Create global messages (all TVs) or branch-specific messages.</div>
    </div>

    @if (session('status'))
        <div class="rounded-2xl border border-emerald-200 bg-emerald-50 text-emerald-900 px-4 py-3">{{ session('status') }}</div>
    @endif

    @if ($errors->any())
        <div class="rounded-2xl border border-rose-200 bg-rose-50 text-rose-900 px-4 py-3">{{ $errors->first() }}</div>
    @endif

    <div class="ds-status-card p-5">
        <h3 class="ds-section-header mb-3">Add TV Message</h3>

        <form method="POST" action="{{ route('admin.tv-messages.store') }}"
              class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">
            @csrf

            <div class="md:col-span-3">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Branch (blank = global)</label>
                <select name="branch_id"
                        class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                    <option value="">Global</option>
                    @foreach($branches as $b)
                        <option value="{{ $b->id }}">{{ $b->name }}</option>
                    @endforeach
                </select>
            </div>

            <div class="md:col-span-9">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Message</label>
                <input name="message" required
                       class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm"
                       placeholder="Motivational message, announcement, etc.">

                <div class="mt-2">
                    <div class="text-[11px] uppercase tracking-wider text-slate-500 dark:text-slate-400 mb-2">Insert values</div>
                    <div class="flex flex-wrap gap-2">
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
                                class="px-2 py-1 rounded-full border border-slate-200 dark:border-slate-800 bg-white/70 dark:bg-slate-900 text-slate-700 dark:text-slate-200 text-xs hover:bg-white dark:hover:bg-slate-800"
                                data-ph="{{ $ph }}" onclick="window.__tvInsertPh(this)">
                                {{ $ph }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="md:col-span-2">
                <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Show on</label>
                <select name="display_area" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                    <option value="both" selected>Hero + Ticker</option>
                    <option value="hero">Hero only</option>
                    <option value="ticker">Ticker only</option>
                </select>
            </div>

            <div class="md:col-span-2 flex items-center gap-2">
                <input type="hidden" name="is_enabled" value="0">
                <input type="checkbox" name="is_enabled" value="1" checked class="rounded border-slate-300 dark:border-slate-700">
                <span class="text-sm text-slate-700 dark:text-slate-200">Enabled</span>
            </div>

            <div class="md:col-span-2">
                <button class="corex-btn-primary text-sm w-full">Add</button>
            </div>

        </form>
    </div>

    <div class="rounded-2xl border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-950 overflow-hidden">

        <div class="px-4 py-3 border-b border-slate-200 dark:border-slate-800 flex justify-between items-center">
            <h3 class="ds-section-header">Existing Messages</h3>
            <div class="text-xs text-slate-500 dark:text-slate-400">{{ count($messages) }} total</div>
        </div>

        <div class="divide-y divide-slate-200 dark:divide-slate-800">

            @forelse($messages as $m)

                <div class="p-4 space-y-2">

                    <form method="POST"
                          action="{{ route('admin.tv-messages.update', $m->id) }}"
                          class="grid grid-cols-1 md:grid-cols-12 gap-3 items-end">

                        @csrf

                        <div class="md:col-span-3">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Branch</label>
                            <select name="branch_id"
                                    class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                                <option value="">Global</option>
                                @foreach($branches as $b)
                                    <option value="{{ $b->id }}"
                                        {{ $m->branch_id == $b->id ? 'selected' : '' }}>
                                        {{ $b->name }}
                                    </option>
                                @endforeach
                            </select>
                        </div>

                        <div class="md:col-span-6">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Message</label>
                            <input name="message"
                                   value="{{ $m->message }}"
                                   class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                        </div>

                        <div class="md:col-span-2">
                            <label class="block text-xs text-slate-600 dark:text-slate-300 mb-1">Show on</label>
                            <select name="display_area" class="w-full rounded-lg border border-slate-300 dark:border-slate-700 bg-white dark:bg-slate-900 text-slate-900 dark:text-slate-100 px-3 py-2 text-sm">
                                <option value="both" {{ (($m->display_area ?? 'both') === 'both') ? 'selected' : '' }}>Hero + Ticker</option>
                                <option value="hero" {{ (($m->display_area ?? 'both') === 'hero') ? 'selected' : '' }}>Hero only</option>
                                <option value="ticker" {{ (($m->display_area ?? 'both') === 'ticker') ? 'selected' : '' }}>Ticker only</option>
                            </select>
                        </div>

                        <div class="md:col-span-2 flex items-center gap-2">
                            <input type="hidden" name="is_enabled" value="0">
                            <input type="checkbox"
                                   name="is_enabled"
                                   value="1"
                                   {{ $m->is_enabled ? 'checked' : '' }}
                                   class="rounded border-slate-300 dark:border-slate-700">
                            <span class="text-sm text-slate-700 dark:text-slate-200">Enabled</span>
                        </div>

                        <div class="md:col-span-1">
                            <button class="corex-btn-primary text-sm">Save</button>
                        </div>

                    </form>

                    <div class="text-xs text-slate-500 dark:text-slate-400">
                        Created by:
                        {{ $m->creator->name ?? 'System' }}
                        ({{ $m->creator->email ?? '-' }})
                    </div>

                    <form method="POST"
                          action="{{ route('admin.tv-messages.delete', $m->id) }}"
                          onsubmit="return confirm('Delete message?');">
                        @csrf
                        <button class="text-xs text-rose-600 hover:underline">Delete</button>
                    </form>

                </div>

            @empty

                <div class="p-6 text-sm text-slate-500 dark:text-slate-400">
                    No TV messages yet.
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
