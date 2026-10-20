{{--
    The agency's own timezone (audit 2026-10-20, L7): an IANA name; blank = the system's own. Shared by Company Settings and the Settings > Agency form.
    Receives $agency and $surface (the CSS variable the host form uses for its inputs).
--}}
@php
    $tzCurrent = (string) old('timezone', $agency->timezone);
    $tzSystem = (string) config('app.timezone');
@endphp
<div>
    <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Timezone</label>
    <select name="timezone" class="w-full rounded-md px-3 py-2 text-sm"
            style="background:var({{ $surface ?? '--surface-2' }}); border:1px solid var(--border); color:var(--text-primary);" data-agency-timezone>
        <option value="" @selected($tzCurrent === '')>System default ({{ $tzSystem }})</option>
        @foreach(\DateTimeZone::listIdentifiers() as $tzName)
            <option value="{{ $tzName }}" @selected($tzCurrent === $tzName)>{{ $tzName }}</option>
        @endforeach
    </select>
    <p class="text-[11px] mt-1" style="color:var(--text-muted);">The timezone your staff, crews, owners and tenants see times in - appointment times on job cards, crew links and emails, and the outreach send window. Leave it on the system default unless your agency is in a different timezone.</p>
</div>
