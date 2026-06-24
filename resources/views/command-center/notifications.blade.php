@extends('layouts.corex')

@section('corex-content')
<div class="space-y-4" data-tour="cc-notifications-list">
    <div class="flex items-center justify-between" data-tour="cc-notifications-header">
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Notifications</h1>
        <div class="flex items-center gap-2">
            @include('layouts.partials.tour-header-launcher')
            @if($notifications->count() > 0)
            <form method="POST" action="{{ route('command-center.notifications.mark-all-read') }}">
                @csrf
                <button type="submit" data-tour="cc-notifications-mark-all" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface-2);color:var(--text-muted);border:1px solid var(--border);">Mark all read</button>
            </form>
            @endif
        </div>
    </div>

    @forelse($notifications as $n)
        @php
            $data = json_decode($n->data, true);
            $isUnread = !$n->read_at;
        @endphp
        <div class="rounded-md px-4 py-3 flex items-start gap-3" data-tour="cc-notifications-item" style="background:var(--surface);border:1px solid var(--border);{{ $isUnread ? 'border-left:3px solid var(--brand-button);' : '' }}">
            <div class="w-2 h-2 rounded-full flex-shrink-0 mt-1.5" style="{{ $isUnread ? 'background:var(--brand-button);' : 'background:var(--text-muted);opacity:0.3;' }}"></div>
            <div class="flex-1 min-w-0">
                @php
                    $title = $data['title'] ?? $data['message'] ?? null;
                    $body  = $data['body']  ?? null;
                    if (!$title && !$body) {
                        // Derive a readable label from the event key (e.g. "contact.birthday" → "Contact Birthday")
                        // rather than leaking the raw notification class name.
                        $title = !empty($data['event_key'])
                            ? \Illuminate\Support\Str::headline(str_replace('.', ' ', $data['event_key']))
                            : \Illuminate\Support\Str::headline(class_basename($n->type));
                    }
                @endphp
                @if($title)
                    <div class="text-sm font-medium" style="color:var(--text-primary);">{{ $title }}</div>
                @endif
                @if($body)
                    <div class="text-xs mt-0.5" style="color:var(--text-secondary);">{{ $body }}</div>
                @endif
                <div class="text-xs mt-0.5" style="color:var(--text-muted);">{{ \Carbon\Carbon::parse($n->created_at)->diffForHumans() }}</div>
            </div>
            @if($isUnread)
                <form method="POST" action="{{ route('command-center.notifications.mark-read', $n->id) }}" class="flex-shrink-0">
                    @csrf
                    <button type="submit" title="Mark as read" class="p-1 rounded transition-colors hover:opacity-70" style="color:var(--text-muted);">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m4.5 12.75 6 6 9-13.5"/></svg>
                    </button>
                </form>
            @endif
        </div>
    @empty
        <div class="rounded-md p-8 text-center" style="background:var(--surface);border:1px solid var(--border);">
            <p class="text-sm" style="color:var(--text-muted);">No notifications.</p>
        </div>
    @endforelse

    {{ $notifications->links() }}
</div>
@endsection
