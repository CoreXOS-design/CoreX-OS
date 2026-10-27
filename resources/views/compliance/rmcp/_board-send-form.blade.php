{{-- Send the board approval letter. Needs: $version, $board, $letter (may be null). --}}
@php $members = $board['members']; @endphp
<div class="mt-4">
    @if($members->isEmpty())
        <p class="text-xs" style="color: var(--text-primary);"><strong>Next:</strong> add your board members. They are the people who sign.</p>
        <a href="{{ route('admin.company-settings') }}#compliance" class="corex-btn-primary text-xs mt-2 inline-block">Add board members</a>
    @else
        <p class="text-xs" style="color: var(--text-primary);"><strong>Next:</strong> send the letter. These people will each get an email with a link to sign:</p>
        <ul class="mt-1 text-xs" style="color: var(--text-secondary);">
            @foreach($members as $m)<li>{{ $m->name }}, {{ $m->title }} ({{ $m->email }})</li>@endforeach
        </ul>
        <p class="text-xs mt-1" style="color: var(--text-muted);">Wrong list? <a href="{{ route('admin.company-settings') }}#compliance" class="underline">Change the board list</a> first.</p>
        <form method="POST" action="{{ route('compliance.rmcp.board.send', $version) }}" class="mt-3 grid grid-cols-1 md:grid-cols-3 gap-3 items-end">
            @csrf
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">The RMCP takes effect on</label>
                <input type="date" name="effective_from" required min="{{ now()->format('Y-m-d') }}" value="{{ old('effective_from', now()->format('Y-m-d')) }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                @error('effective_from')<p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="block text-xs font-semibold mb-1" style="color: var(--text-secondary);">Review it again by</label>
                <input type="date" name="next_review_due" value="{{ old('next_review_due', now()->addYear()->format('Y-m-d')) }}"
                       class="w-full rounded-md px-3 py-2 text-sm" style="background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);">
                @error('next_review_due')<p class="text-xs mt-1" style="color: var(--ds-crimson);">{{ $message }}</p>@enderror
            </div>
            <div><button type="submit" class="corex-btn-primary text-xs">Send for board signature</button></div>
        </form>
    @endif
</div>
