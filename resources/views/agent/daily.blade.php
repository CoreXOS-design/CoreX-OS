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
              <div class="text-sm font-semibold" style="color:var(--text-primary)">Week</div>
              <div style="color:var(--text-secondary)">
                {{ isset($weekStart) ? $weekStart->format('D j M Y') : '' }} – {{ isset($weekEnd) ? $weekEnd->format('D j M Y') : '' }}
              </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
              <a class="inline-flex items-center rounded-lg border px-3 py-2 text-sm font-semibold"
                 style="border-color:var(--border); background:var(--surface); color:var(--text-primary)"
                 href="{{ route('agent.daily', array_merge($qs, ['date' => $prev])) }}">← Prev</a>

              <form method="GET" action="{{ route('agent.daily') }}" class="flex items-center gap-2">
                @foreach($qs as $k => $v)
                  @if($k !== 'date') <input type="hidden" name="{{ $k }}" value="{{ $v }}"> @endif
                @endforeach
                <input type="date" name="date" class="rounded-lg border px-3 py-2 text-sm" style="border-color:var(--border); background:var(--surface); color:var(--text-primary)" value="{{ $selected }}">
                <button class="rounded-lg px-4 py-2 text-sm font-semibold" style="background:var(--brand-button); color:#fff" type="submit">Go</button>
              </form>

              <a class="inline-flex items-center rounded-lg border px-3 py-2 text-sm font-semibold"
                 style="border-color:var(--border); background:var(--surface); color:var(--text-primary)"
                 href="{{ route('agent.daily', array_merge($qs, ['date' => $next])) }}">Next →</a>
            </div>
          </div>

          <div class="mt-4">
            <div class="flex gap-2 overflow-x-auto pb-2">
              @foreach(($weekDays ?? []) as $d)
                @php $date = $d['date']; $p = ($pointsByDay[$date] ?? null); @endphp
                <a href="{{ route('agent.daily', array_merge($qs, ['date' => $date])) }}"
                   class="min-w-[120px] rounded-xl border px-3 py-2 text-left transition"
                   style="{{ $d['is_selected'] ? 'border-color:var(--brand-button); background:var(--brand-button); color:#fff' : 'border-color:var(--border); background:var(--surface); color:var(--text-primary)' }}">
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
                <div class="text-sm font-semibold" style="color:var(--text-primary)">Capture</div>
                <div class="text-xs" style="color:var(--text-secondary)">Fast entry for {{ $selected }}</div>
              </div>
              <div class="text-xs" style="color:var(--text-muted)">Tip: use Tab / Shift+Tab</div>
            </div>

            <form method="POST" action="{{ route('admin.targets.daily.save') }}" class="mt-4">
              @csrf
              <input type="hidden" name="activity_date" value="{{ $selected }}">

              <div class="divide-y rounded-xl border" style="border-color:var(--border); background:var(--surface)">
                @foreach($dailyCols as $col)
                  @php
                    $key = (string)($col['key'] ?? '');
                    $label = (string)($col['label'] ?? $key);
                    $w = (float)($col['points_weight'] ?? 1);
                    $existing = $row && $key ? ($row->{$key} ?? 0) : 0;
                  @endphp

                  <div class="flex items-center justify-between gap-4 px-4 py-3" style="border-color:var(--border)">
                    <div class="min-w-0">
                      <div class="font-semibold truncate" style="color:var(--text-primary)">{{ $label }}</div>
                      <div class="text-xs" style="color:var(--text-muted)">Weight: {{ $w }}</div>
                    </div>

                    <div class="flex items-center gap-3">
                      <input
                        type="number"
                        inputmode="numeric"
                        min="0"
                        name="daily[{{ $key }}]"
                        value="{{ old('daily.'.$key, $existing) }}"
                        class="w-28 rounded-lg border px-3 py-2 text-right text-sm"
                        style="border-color:var(--border); background:var(--surface); color:var(--text-primary)"
                      >
                    </div>
                  </div>
                @endforeach
              </div>

              <div class="mt-4 flex justify-end">
                <button type="submit" class="rounded-lg px-6 py-3 text-sm font-semibold" style="background:var(--brand-button); color:#fff">
                  Save
                </button>
              </div>
            </form>
          </div>
        @endif

    </div>
</x-app-layout>

{{-- AGENT_DAILY_UI_PATCH:WEEK_UI_END --}}
