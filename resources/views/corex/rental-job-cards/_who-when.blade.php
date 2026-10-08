{{-- Who & when: the crew, the booking, Mark in progress. Receives $jobCard, $isOpen, $crews, $stage. --}}
            {{-- 3. WHO & WHEN, one line --}}
            <div class="rounded-md p-4 space-y-2" style="background: var(--surface); border: 1px solid var(--border);">
                <div class="text-sm flex flex-wrap gap-3">
                    @if($jobCard->crew)
                        <span><span style="color: var(--text-muted);">Crew:</span> {{ $jobCard->crew->name }}@if($jobCard->crew->trashed()) <span style="color: var(--text-muted);">(archived)</span>@endif</span>
                    @elseif($jobCard->assigned_user_id)
                        {{-- 2026-10-05 — legacy only: a card assigned before crews existed. Read-only — never re-selectable, never written to again. --}}
                        <span style="color: var(--text-muted);">Previously assigned: {{ $jobCard->assignedUser?->name ?? '—' }}</span>
                    @else
                        <span><span style="color: var(--text-muted);">Crew:</span> —</span>
                    @endif
                    <span><span style="color: var(--text-muted);">Scheduled:</span> {{ $jobCard->scheduled_at?->format('Y-m-d H:i') ?? '—' }}</span>
                    <span><span style="color: var(--text-muted);">Due:</span> {{ $jobCard->due_at?->format('Y-m-d H:i') ?? '—' }}</span>
                </div>
                @if($jobCard->crew && $jobCard->crew->members->isNotEmpty())
                    <div class="text-xs" style="color: var(--text-muted);">{{ $jobCard->crew->members->pluck('name')->implode(', ') }}</div>
                @endif
                @permission('rental_job_cards.create')
                @if($isOpen)
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.assign-crew', $jobCard) }}" class="flex gap-2">
                    @csrf
                    <select name="rental_crew_id" required class="flex-1 rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                        <option value="">Select crew…</option>
                        @foreach($crews as $c)
                            <option value="{{ $c->id }}" @selected($jobCard->rental_crew_id === $c->id)>{{ $c->name }}</option>
                        @endforeach
                    </select>
                    <button type="submit" class="corex-btn-outline text-xs">Assign</button>
                </form>
                @permission('rental_catalogue.manage')
                @feature('rental-crews')
                <a href="{{ route('corex.rental-crews.index') }}" class="text-xs underline" style="color: var(--text-muted);">Manage crews</a>
                @endfeature
                @endpermission
                @if($stage['can']['schedule'])
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.schedule', $jobCard) }}" class="space-y-2">
                    @csrf
                    <input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at', $jobCard->scheduleInputValue('scheduled_at')) }}" aria-label="Scheduled" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <input type="datetime-local" name="due_at" value="{{ old('due_at', $jobCard->scheduleInputValue('due_at')) }}" aria-label="Due" class="w-full rounded-md px-2 py-1.5 text-xs" style="border: 1px solid var(--border);">
                    <button type="submit" class="corex-btn-outline text-xs w-full">Set</button>
                </form>
                @else
                    <p class="text-xs" style="color: var(--text-muted);" data-schedule-locked>Booking a date opens once the job is approved{{ $stage['key'] === 'signed_off' ? '' : '' }}.</p>
                @endif
                @if($stage['can']['start'])
                <form data-keep-scroll method="POST" action="{{ route('corex.rental-job-cards.start', $jobCard) }}">
                    @csrf
                    <button type="submit" class="corex-btn-outline text-xs">Mark in progress</button>
                </form>
                @endif
                @endif
                @endpermission
            </div>

