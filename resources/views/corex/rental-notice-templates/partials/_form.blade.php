@php($template = $template ?? null)
<div>
    <label class="prop-label">Name</label>
    <input type="text" name="name" value="{{ old('name', $template?->name) }}" required class="prop-input">
</div>
<div>
    <label class="prop-label">Notice type</label>
    <select name="notice_type" required class="prop-input">
        @foreach(\App\Models\RentalNoticeTemplate::TYPES as $t)
            <option value="{{ $t }}" @selected(old('notice_type', $template?->notice_type) === $t)>{{ ucfirst(str_replace('_', ' ', $t)) }}</option>
        @endforeach
    </select>
</div>
<div>
    <label class="prop-label">Body (HTML)</label>
    {{-- AT-445 regression (2026-10-05, 6b14f9e4c, same commit/bug class as
         the Property Rental tab fix) — {{ '}}' }} contains a literal "}}"
         INSIDE the quoted string meant to escape it, so Blade's own {{ }}
         echo matcher (non-greedy, stops at the first "}}" it finds) closed
         early on the string's own closing brace-pair, leaving the rest as
         broken literal text in every placeholder example on this line.
         @{{ }} is Blade's own built-in escape for "output this literally,
         don't evaluate it" — no quoted-string workaround needed at all. --}}
    <p class="text-xs mb-1" style="color: var(--text-muted);">Use @{{token}} placeholders — e.g. @{{tenant_name}}, @{{arrears_amount}}, @{{vacate_by_date}}, @{{breach_description}}. The agent fills these in when sending.</p>
    <textarea name="body_html" rows="12" required class="prop-input" style="font-family: monospace;">{{ old('body_html', $template?->body_html) }}</textarea>
</div>
<label class="flex items-center gap-2 text-sm">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template?->is_active ?? true))>
    Active (selectable when sending a notice)
</label>
