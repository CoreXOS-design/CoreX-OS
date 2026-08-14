{{--
    Shared period selector for the Agency Performance & ROI report family
    (company/branch/agent pages) — 2026-08-14.

    Root cause of the "Custom" bug this replaces: the <select> auto-submitted
    on every change, including "Custom" — but the start/end <input type="date">
    fields were gated behind a server-side @if($preset === 'custom'), which
    was only ever true on a response that ALREADY had valid dates. The very
    first "Custom" selection submitted with no start/end, PeriodResolver threw
    "a custom period requires both a start and an end date", and the
    controller's catch block reset $preset back to 'this_month' before
    rendering — so the date inputs could never appear through the UI at all
    (only reachable by hand-editing the URL). branch.blade.php and
    agent.blade.php didn't even have the date-input markup — no Custom
    support whatsoever on those two pages.

    Fix: "Custom" no longer auto-submits — Alpine reveals the date inputs
    client-side instead, so the user fills them in before ever hitting the
    server. required on both inputs gives native browser validation as a
    fallback. <template x-if> (not x-show) so a bookmarked URL missing one
    of start/end never round-trips a half-empty request.

    2026-08-14 follow-up: required alone made "Apply" fail SILENTLY when a
    native <input type="date"> is left partially typed — the segmented
    widget's .value stays "" until every segment (day/month/year) is
    complete, so an incomplete field trips the browser's native validation
    and blocks the click with no page reload and only an easy-to-miss
    tooltip. From the user's side that reads as "clicking Apply does
    nothing." Fix: mirror both inputs into Alpine state (start/end) so the
    button is visibly disabled and a plain-language hint shows until both
    dates are actually complete — the failure is now seen, not silent.

    Expects: $preset (string), $presets (array).
    Optional: $formAction — the branch/agent pages need next requests to
    keep hitting their own drill-down route, not the company report; omit it
    on the company report page to GET the current URL as usual.
--}}
<form method="GET" @if(isset($formAction)) action="{{ $formAction }}" @endif
      class="flex items-end gap-2 flex-wrap"
      x-data="{ preset: {{ Js::from($preset) }}, start: {{ Js::from((string) request('start')) }}, end: {{ Js::from((string) request('end')) }} }">
    <label class="text-[11px]" style="color:var(--text-muted);">
        Period
        <select name="period" x-model="preset"
                @change="if ($event.target.value !== 'custom') $event.target.form.submit()"
                class="block mt-1 text-xs rounded px-2 py-1"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            @foreach($presets as $p)
                <option value="{{ $p }}">{{ ucfirst(str_replace('_', ' ', $p)) }}</option>
            @endforeach
        </select>
    </label>
    <template x-if="preset === 'custom'">
        <div class="flex items-end gap-2 flex-wrap">
            <label class="text-[11px]" style="color:var(--text-muted);">Start
                <input type="date" name="start" x-model="start" required
                       class="block mt-1 text-xs rounded px-2 py-1" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </label>
            <label class="text-[11px]" style="color:var(--text-muted);">End
                <input type="date" name="end" x-model="end" required
                       class="block mt-1 text-xs rounded px-2 py-1" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            </label>
            <button type="submit" :disabled="!start || !end"
                    :style="(!start || !end) ? 'background:var(--surface-2); color:var(--text-muted); cursor:not-allowed; border:1px solid var(--border);' : 'background:var(--brand); color:#fff;'"
                    class="text-xs px-3 py-1 rounded">Apply</button>
            <span x-show="!start || !end" class="text-[11px]" style="color:var(--text-muted);">Pick both a start and end date</span>
        </div>
    </template>
</form>
