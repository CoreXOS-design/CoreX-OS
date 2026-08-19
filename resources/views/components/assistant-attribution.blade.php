{{-- AT-267 / AUDIT 2026-07-26 (F1) — the "added by {assistant}" tag.

     Renders a small line on a record's page naming the assistant who last CHANGED it, when the
     assigned agent has `show_attribution` on. Ownership routing files an assistant's work under
     the agent by design, so without this tag the agent has no way to tell, on the record itself,
     which of the changes on their book were their assistant's hand.

     Renders NOTHING at all when no assistant has changed the record, when the last change was the
     agent's own, or when the agent has switched the toggle off — so it is safe to drop onto any
     record page unconditionally.

     Usage:  <x-assistant-attribution type="property" :id="$property->id" />
             types match LogAssistantActivity::SUBJECTS — property | contact | deal
--}}
@props(['type' => null, 'id' => null])

@php
    $attr = \App\Models\AssistantActivityLog::attributionFor($type, $id);
@endphp

@if($attr)
    <div class="inline-flex items-center gap-1.5 text-xs rounded-md px-2 py-1"
         style="background:var(--surface-2); border:1px solid var(--border); color:var(--text-secondary);"
         title="Your assistant's work is filed as yours. This shows who actually made the change.">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5 flex-none" fill="none" viewBox="0 0 24 24"
             stroke-width="1.8" stroke="currentColor" style="color:var(--text-muted);">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 1 1-7.5 0 3.75 3.75 0 0 1 7.5 0ZM4.501 20.118a7.5 7.5 0 0 1 14.998 0A17.933 17.933 0 0 1 12 21.75c-2.676 0-5.216-.584-7.499-1.632Z" />
        </svg>
        <span>
            Last updated by <strong style="color:var(--text-primary);">{{ $attr['name'] }}</strong>
            <span style="color:var(--text-muted);">({{ $attr['title'] }})</span>
            @if($attr['at'])
                <span style="color:var(--text-muted);">· {{ $attr['at']->diffForHumans() }}</span>
            @endif
        </span>
    </div>
@endif
