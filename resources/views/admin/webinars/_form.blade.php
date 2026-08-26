{{--
    Shared webinar form fields — included by create and edit.
    Spec: .ai/specs/webinar-registration.md §7.2

    One partial rather than two copies: the access window and reminder lead time are
    the whole policy of a webinar, and a create screen that drifted out of step with
    the edit screen would silently issue grants on rules nobody could see afterwards.

    $webinar is null on create.
--}}
@php($w = $webinar ?? null)

<div class="rounded-md p-5 space-y-4" style="background: var(--surface); border: 1px solid var(--border);">

    <div>
        <label for="title" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
            Title <span class="text-red-500">*</span>
        </label>
        <input id="title" name="title" type="text" required
               value="{{ old('title', $w->title ?? '') }}"
               placeholder="e.g. CoreX OS — a walkthrough for agency principals"
               class="w-full rounded-md px-3 py-2 text-sm"
               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        <p class="mt-1 text-xs" style="color: var(--text-muted);">
            Registrants see this in their confirmation email and in their calendar.
        </p>
        <x-input-error :messages="$errors->get('title')" class="mt-1" />
    </div>

    <div>
        <label for="slug" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
            Link name
        </label>
        <input id="slug" name="slug" type="text"
               value="{{ old('slug', $w->slug ?? '') }}"
               placeholder="leave blank to build it from the title"
               class="w-full rounded-md px-3 py-2 text-sm"
               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        <p class="mt-1 text-xs" style="color: var(--text-muted);">
            The short name that appears in the registration web address. If it's already taken
            we'll add a number so two webinars can never share a link.
        </p>
        <x-input-error :messages="$errors->get('slug')" class="mt-1" />
    </div>

    <div>
        <label for="description" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
            What you'll cover
        </label>
        <textarea id="description" name="description" rows="4"
                  class="w-full rounded-md px-3 py-2 text-sm"
                  style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);"
                  placeholder="Shown on the website's registration page and in the confirmation email.">{{ old('description', $w->description ?? '') }}</textarea>
        <x-input-error :messages="$errors->get('description')" class="mt-1" />
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="starts_at" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                Date and time <span class="text-red-500">*</span>
            </label>
            <input id="starts_at" name="starts_at" type="datetime-local" required
                   value="{{ old('starts_at', $w?->starts_at?->format('Y-m-d\TH:i')) }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                South African time. Registration closes automatically when the webinar starts.
            </p>
            <x-input-error :messages="$errors->get('starts_at')" class="mt-1" />
        </div>

        <div>
            <label for="duration_minutes" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                How long (minutes)
            </label>
            <input id="duration_minutes" name="duration_minutes" type="number" min="5" max="1440"
                   value="{{ old('duration_minutes', $w->duration_minutes ?? 60) }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                Used for the calendar invite we attach to the confirmation email.
            </p>
            <x-input-error :messages="$errors->get('duration_minutes')" class="mt-1" />
        </div>
    </div>

    <div>
        <label for="join_url" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
            Joining link
        </label>
        <input id="join_url" name="join_url" type="url"
               value="{{ old('join_url', $w->join_url ?? '') }}"
               placeholder="https://zoom.us/j/…"
               class="w-full rounded-md px-3 py-2 text-sm"
               style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
        <p class="mt-1 text-xs" style="color: var(--text-muted);">
            Zoom, Teams or Meet. Only people who register get this — it's never shown on the
            public page.
        </p>
        <x-input-error :messages="$errors->get('join_url')" class="mt-1" />
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="access_ends_days_after" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                Demo access ends this many days after <span class="text-red-500">*</span>
            </label>
            <input id="access_ends_days_after" name="access_ends_days_after" type="number" min="0" max="365" required
                   value="{{ old('access_ends_days_after', $w->access_ends_days_after ?? 3) }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                Everyone who registers loses their demo login at the end of that day — whether or
                not they ever used it. Enter 0 to end it on the day of the webinar.
            </p>
            <x-input-error :messages="$errors->get('access_ends_days_after')" class="mt-1" />
        </div>

        <div>
            <label for="reminder_hours_before" class="block text-xs font-medium mb-1" style="color: var(--text-secondary);">
                Send the reminder this many hours before <span class="text-red-500">*</span>
            </label>
            <input id="reminder_hours_before" name="reminder_hours_before" type="number" min="1" max="336" required
                   value="{{ old('reminder_hours_before', $w->reminder_hours_before ?? 24) }}"
                   class="w-full rounded-md px-3 py-2 text-sm"
                   style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
            <p class="mt-1 text-xs" style="color: var(--text-muted);">
                One reminder per person, with the joining link. It can't repeat the access code —
                for security that's only ever in their first email.
            </p>
            <x-input-error :messages="$errors->get('reminder_hours_before')" class="mt-1" />
        </div>
    </div>

</div>
