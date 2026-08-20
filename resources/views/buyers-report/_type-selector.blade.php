{{--
    Buyers Report type filter (Johan, 2026-08-20): "no ways to say buyer /
    leads - and I take it all tenants excluded here?" They are not — see
    BuyersReportService::TYPES for what the data actually supports.

    Plain GET select, matching the period selector's own simplicity — auto-
    submits on change, preserving every other current query param via hidden
    inputs so switching type never resets the period/compare/scope choice.
    Expects: $type (?string), $types (array key=>label).
--}}
<form method="GET" class="flex items-end gap-2">
    @foreach(request()->except(['type', 'page']) as $key => $value)
        @if(is_array($value))
            @foreach($value as $v)
                <input type="hidden" name="{{ $key }}[]" value="{{ $v }}">
            @endforeach
        @else
            <input type="hidden" name="{{ $key }}" value="{{ $value }}">
        @endif
    @endforeach
    <label class="text-[11px]" style="color:var(--text-muted);">
        Type
        <select name="type" onchange="this.form.submit()"
                class="block mt-1 text-xs rounded px-2 py-1"
                style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-primary);">
            <option value="" @selected($type === null)>All</option>
            @foreach($types as $key => $label)
                <option value="{{ $key }}" @selected($type === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>
</form>
