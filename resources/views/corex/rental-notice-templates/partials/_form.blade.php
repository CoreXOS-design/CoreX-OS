@php($template = $template ?? null)
<div>
    <label class="prop-label">Name</label>
    <input type="text" name="name" value="{{ old('name', $template?->name) }}" required class="prop-input">
</div>
<div>
    <label class="prop-label">Notice type</label>
    <select name="notice_type" required class="prop-input">
        @foreach(\App\Models\RentalNoticeTemplate::TYPES as $t)
            <option value="{{ $t }}" @selected(old('notice_type', $template?->notice_type ?? request('notice_type')) === $t)>{{ \App\Models\RentalNoticeTemplate::labelFor($t) }}</option>
        @endforeach
    </select>
    {{-- which screens offer which type, so a template is never "missing" without saying why --}}
    <p class="text-xs mt-1" style="color: var(--text-muted);" data-qa="template-type-help">
        <strong>Breach notice (payment)</strong> is offered on the bulk payment sheet and on a lease's Breach notice screen. <strong>Breach notice (ad hoc)</strong> is offered on a lease's Breach notice screen (you give the clause and the reason there). <strong>Notice to vacate</strong> is offered on a breach once its remedy date is reached.
    </p>
</div>
<div data-qa="template-upload">
    <label class="prop-label">Upload your document (Word, .docx)</label>
    <p class="text-xs mb-1" style="color: var(--text-muted);">Your own breach notice / notice to vacate. Write the placeholders in the document as @{{tenant_name}}, @{{amount}} and so on (listed below). It is converted to the text below, which you can still edit.
        @if($template?->source_file_name) Made from: <strong>{{ $template->source_file_name }}</strong>. @endif</p>
    <input type="file" name="document" accept=".docx" class="text-xs">
    @error('document')<p class="text-xs mt-1" style="color: var(--ds-red, #b91c1c);">{{ $message }}</p>@enderror
</div>
<div>
    <label class="prop-label">Body (HTML) - or type it here instead of uploading</label>
    {{-- AT-445 regression (2026-10-05, 6b14f9e4c, same commit/bug class as
         the Property Rental tab fix) — {{ '}}' }} contains a literal "}}"
         INSIDE the quoted string meant to escape it, so Blade's own {{ }}
         echo matcher (non-greedy, stops at the first "}}" it finds) closed
         early on the string's own closing brace-pair, leaving the rest as
         broken literal text in every placeholder example on this line.
         @{{ }} is Blade's own built-in escape for "output this literally,
         don't evaluate it" — no quoted-string workaround needed at all. --}}
    <p class="text-xs mb-1" style="color: var(--text-muted);">Use @{{token}} placeholders — e.g. @{{tenant_name}}, @{{arrears_amount}}, @{{vacate_by_date}}, @{{breach_description}}. The ones listed below are filled in from the lease and the notice automatically; any other is typed by the agent when sending.</p>
    <details class="text-xs mb-2" style="color: var(--text-muted);"><summary class="cursor-pointer">Placeholders filled in automatically</summary>
        <ul class="list-disc list-inside">@foreach(\App\Services\Rentals\RentalNoticeService::PLACEHOLDERS as $k => $label)<li><code>&#123;&#123;{{ $k }}&#125;&#125;</code> - {{ $label }}</li>@endforeach</ul>
    </details>
    <textarea name="body_html" rows="12" class="prop-input" style="font-family: monospace;">{{ old('body_html', $template?->body_html) }}</textarea>
</div>
<div data-qa="template-default-clause">
    <label class="prop-label">Lease clauses quoted by default (optional)</label>
    <p class="text-xs mb-1" style="color: var(--text-muted);">The clause block your notice quotes, as it appears in your lease (one clause per paragraph). The notice prints it where the template says @{{clause}}; the agent can edit it for each breach notice. Leave blank if the template spells the clauses out itself.</p>
    <textarea name="default_clause" rows="6" maxlength="5000" class="prop-input">{{ old('default_clause', $template?->default_clause) }}</textarea>
</div>
<div data-qa="template-remedy-days">
    <label class="prop-label">Days to put the breach right (breach notices)</label>
    <p class="text-xs mb-1" style="color: var(--text-muted);">The remedy period this breach notice gives, in days. Leave blank to use your agency's default (Settings &rarr; Leases). The remedy-by date on the notice is the day it is sent plus this many days.</p>
    <input type="number" name="remedy_days" value="{{ old('remedy_days', $template?->remedy_days) }}" min="1" max="365" class="prop-input" style="max-width: 8rem;">
    @error('remedy_days')<p class="text-xs mt-1" style="color: var(--ds-red, #b91c1c);">{{ $message }}</p>@enderror
</div>
<label class="flex items-center gap-2 text-sm">
    <input type="hidden" name="is_active" value="0">
    <input type="checkbox" name="is_active" value="1" @checked(old('is_active', $template?->is_active ?? true))>
    Active (selectable when sending a notice)
</label>
