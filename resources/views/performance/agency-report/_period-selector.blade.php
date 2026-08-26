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

    2026-08-14 follow-up #2: the enabled Apply button used var(--brand),
    which is never defined anywhere in corex.css (only --brand-button,
    --brand-default, --brand-sidebar, --brand-icon exist). With --brand
    undefined, `background` fell through to whatever ambient surface color
    cascaded in, while color:#fff stayed hardcoded — invisible white-on-
    light-grey in light mode, only legible in dark mode by coincidence.
    Fix: use var(--brand-button, #0ea5e9), the same variable + fallback
    every other primary action button in the app uses (see
    .corex-btn-primary in corex.css), which is a proper solid blue with
    real contrast against white text in both themes.

    Expects: $preset (string), $presets (array).
    Optional: $formAction — the branch/agent pages need next requests to
    keep hitting their own drill-down route, not the company report; omit it
    on the company report page to GET the current URL as usual.
    Optional: $compareMode (string, default 'off'), $compareModes (array) —
    2026-08-19 (Johan, period-comparison) — the "Compare to" selector. Off by
    default on branch/agent pages if they don't pass these (index.blade.php
    always does). Composes with the Period selector above rather than being
    four separate named preset pairs: "Previous period" alone already reads
    as "this month vs last month" / "this quarter vs last quarter" / "this
    year vs last year" depending on whichever Period is selected — see
    .ai/specs/at366-period-comparison.md §2.
--}}
<form method="GET" @if(isset($formAction)) action="{{ $formAction }}" @endif
      class="flex items-end gap-2 flex-wrap"
      x-data="{
          preset: {{ Js::from($preset) }},
          start: {{ Js::from((string) request('start')) }},
          end: {{ Js::from((string) request('end')) }},
          compare: {{ Js::from($compareMode ?? 'off') }},
          compareStart: {{ Js::from((string) request('compare_start')) }},
          compareEnd: {{ Js::from((string) request('compare_end')) }},
          readyToSubmit() {
              if (this.preset === 'custom' && (!this.start || !this.end)) return false;
              if (this.compare === 'custom' && (!this.compareStart || !this.compareEnd)) return false;
              return true;
          },
          maybeAutoSubmit(el) { if (this.readyToSubmit()) el.form.submit(); },
      }">
    <label class="text-[11px]" style="color:var(--text-muted);">
        Period
        <select name="period" x-model="preset"
                @change="maybeAutoSubmit($event.target)"
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
        </div>
    </template>
    @if(isset($compareModes))
        <label class="text-[11px]" style="color:var(--text-muted);">
            Compare to
            <select name="compare" x-model="compare"
                    @change="maybeAutoSubmit($event.target)"
                    class="block mt-1 text-xs rounded px-2 py-1"
                    style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                @foreach($compareModes as $c)
                    <option value="{{ $c }}">{{ $c === 'off' ? 'Off' : ($c === 'same_last_year' ? 'Same period last year' : ucfirst(str_replace('_', ' ', $c))) }}</option>
                @endforeach
            </select>
        </label>
        <template x-if="compare === 'custom'">
            <div class="flex items-end gap-2 flex-wrap">
                <label class="text-[11px]" style="color:var(--text-muted);">Compare start
                    <input type="date" name="compare_start" x-model="compareStart" required
                           class="block mt-1 text-xs rounded px-2 py-1" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </label>
                <label class="text-[11px]" style="color:var(--text-muted);">Compare end
                    <input type="date" name="compare_end" x-model="compareEnd" required
                           class="block mt-1 text-xs rounded px-2 py-1" style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
                </label>
            </div>
        </template>
    @endif
    <template x-if="preset === 'custom' || compare === 'custom'">
        <div class="flex items-end gap-2 flex-wrap">
            <button type="submit" :disabled="!readyToSubmit()"
                    :style="!readyToSubmit() ? 'background:var(--surface-2); color:var(--text-muted); cursor:not-allowed; border:1px solid var(--border);' : 'background:var(--brand-button, #0ea5e9); color:#fff;'"
                    class="text-xs px-3 py-1 rounded">Apply</button>
            <span x-show="!readyToSubmit()" class="text-[11px]" style="color:var(--text-muted);">Pick both dates for every custom range in use</span>
        </div>
    </template>
</form>
