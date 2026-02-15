{{-- AGENT_DAILY_UI_PATCH:WEEK_UI_START --}}
@php
  $qs = request()->query();
  $selected = $dailyDate ?? request('date', now()->toDateString());
  $prev = \Carbon\Carbon::parse($selected)->subWeek()->toDateString();
  $next = \Carbon\Carbon::parse($selected)->addWeek()->toDateString();
  $row = null;
  if (isset($dailyActivities) && method_exists($dailyActivities, 'get')) {
      $row = $dailyActivities->get(auth()->id());
  }
@endphp

<x-app-layout>
    <x-slot name="header">
        Daily Activity
    </x-slot>

    <div class="space-y-6">
        @if (session('status'))
            <div class="p-3 rounded bg-green-100 text-green-800">{{ session('status') }}</div>
        @endif

        @if($errors->any())
            <div class="p-3 rounded bg-red-100 text-red-800">{{ $errors->first() }}</div>
        @endif

        <div class="hfc-card p-4">
          <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div>
              <div class="text-sm font-semibold text-slate-800">Week</div>
              <div class="text-slate-600">
                {{ isset($weekStart) ? $weekStart->format('D j M Y') : '' }} – {{ isset($weekEnd) ? $weekEnd->format('D j M Y') : '' }}
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <a class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                 href="{{ route('agent.daily', array_merge($qs, ['date' => $prev])) }}">← Prev</a>

              <form method="GET" action="{{ route('agent.daily') }}" class="flex items-center gap-2">
                @foreach($qs as $k => $v)
                  @if($k !== 'date') <input type="hidden" name="{{ $k }}" value="{{ $v }}"> @endif
                @endforeach
                <input type="date" name="date" class="rounded-lg border border-slate-200 px-3 py-2 text-sm" value="{{ $selected }}">
                <button class="rounded-lg bg-slate-900 px-4 py-2 text-sm font-semibold text-white hover:bg-slate-800" type="submit">Go</button>
              </form>

              <a class="inline-flex items-center rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-800 hover:bg-slate-50"
                 href="{{ route('agent.daily', array_merge($qs, ['date' => $next])) }}">Next →</a>
            </div>
          </div>

          <div class="mt-4">
            <div class="flex gap-2 overflow-x-auto pb-2">
              @foreach(($weekDays ?? []) as $d)
                @php $date = $d['date']; $p = ($pointsByDay[$date] ?? null); @endphp
                <a href="{{ route('agent.daily', array_merge($qs, ['date' => $date])) }}"
                   class="min-w-[120px] rounded-xl border px-3 py-2 text-left transition
                          {{ $d['is_selected'] ? 'border-slate-900 bg-slate-900 text-white' : 'border-slate-200 bg-white text-slate-900 hover:bg-slate-50' }}">
                  <div class="text-xs opacity-90">{{ $d['label'] }}</div>
                  <div class="text-lg font-extrabold leading-tight">{{ $p === null ? '—' : number_format($p, 0) }}</div>
                  <div class="text-xs opacity-80">pts</div>
                </a>
              @endforeach
            </div>
          </div>
        </div>


        @if(isset($dailyCols) && is_array($dailyCols))
          <div class="hfc-card p-4">
            <div class="flex items-center justify-between gap-3">
              <div>
                <div class="text-sm font-semibold text-slate-900">Capture</div>
                <div class="text-xs text-slate-600">Fast entry for {{ $selected }}</div>
              </div>
              <div class="text-xs text-slate-500">Tip: use Tab / Shift+Tab</div>
            </div>

            <form method="POST" action="{{ route('admin.targets.daily.save') }}" class="mt-4">
              @csrf
              <input type="hidden" name="activity_date" value="{{ $selected }}">

              <div class="divide-y divide-slate-100 rounded-xl border border-slate-200 bg-white">
                @foreach($dailyCols as $col)
                  @php
                    $key = (string)($col['key'] ?? '');
                    $label = (string)($col['label'] ?? $key);
                    $w = (float)($col['points_weight'] ?? 1);
                    $existing = $row && $key ? ($row->{$key} ?? 0) : 0;
                  @endphp

                  <div class="flex items-center justify-between gap-4 px-4 py-3">
                    <div class="min-w-0">
                      <div class="font-semibold text-slate-900 truncate">{{ $label }}</div>
                      <div class="text-xs text-slate-500">Weight: {{ $w }}</div>
                    </div>

                    <div class="flex items-center gap-3">
                      <input
                        type="number"
                        inputmode="numeric"
                        min="0"
                        name="daily[{{ $key }}]"
                        value="{{ old('daily.'.$key, $existing) }}"
                        class="w-28 rounded-lg border border-slate-200 px-3 py-2 text-right text-sm"
                      >
                    </div>
                  </div>
                @endforeach
              </div>

              <div class="mt-4 flex justify-end">
                <button type="submit" class="rounded-lg bg-slate-900 px-6 py-3 text-sm font-semibold text-white hover:bg-slate-800">
                  Save
                </button>
              </div>
            </form>
          </div>
        @endif

    </div>
</x-app-layout>

{{-- AGENT_DAILY_UI_PATCH:WEEK_UI_END --}}
