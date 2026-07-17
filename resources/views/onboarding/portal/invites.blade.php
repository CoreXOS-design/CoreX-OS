@extends('layouts.onboarding-portal')

@section('portal-content')
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-10"
     x-data="portalInvites()">
    <div class="mb-6">
        <p class="text-xs text-muted mb-1">Step 2 of 2 · Invite your agents</p>
        <h1 class="text-2xl font-bold">Send agent invite links</h1>
        <p class="text-sm text-muted mt-1">
            Your properties are in. These are the agents imported for
            <span class="font-semibold">{{ $agency->name }}</span>. Everyone ticked below
            gets an email invite to set up their CoreX login. <span class="font-medium">Untick
            anyone you don't want to invite</span> — you can always invite them later.
        </p>
    </div>

    @php
        $invitable = $agents->filter(fn ($a) => !$a->is_active && filled($a->email));
        $active    = $agents->filter(fn ($a) => $a->is_active);
        $noEmail   = $agents->filter(fn ($a) => !$a->is_active && blank($a->email));
    @endphp

    <form method="POST" action="{{ route('onboarding.portal.invites.send', $portal->urlKey()) }}">
        @csrf

        <div class="rounded-md bg-surface border border-subtle/30 shadow-sm overflow-hidden">
            {{-- header row: select-all + live count --}}
            <div class="flex items-center justify-between px-4 py-3 border-b border-subtle/30 bg-surface-2">
                <label class="flex items-center gap-2 text-xs font-medium cursor-pointer">
                    <input type="checkbox" x-model="allChecked" @change="toggleAll($event)"
                           class="rounded border-subtle">
                    Select all invitable
                </label>
                <span class="text-xs text-muted"><span x-text="checkedCount" class="font-semibold"></span> of {{ $invitable->count() }} selected</span>
            </div>

            @forelse ($agents as $agent)
                @php
                    $canInvite = !$agent->is_active && filled($agent->email);
                    $alreadyInvited = !$agent->is_active && $agent->invited_at !== null;
                @endphp
                <div class="flex items-center gap-3 px-4 py-3 border-b border-subtle/20 last:border-b-0">
                    <div class="w-5 shrink-0">
                        @if ($canInvite)
                            <input type="checkbox" name="agent_ids[]" value="{{ $agent->id }}"
                                   {{ $alreadyInvited ? '' : 'checked' }}
                                   @change="recount()" x-ref="cb"
                                   class="agent-cb rounded border-subtle">
                        @endif
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-medium truncate">{{ $agent->name ?: 'Unnamed agent' }}</div>
                        <div class="text-xs text-muted truncate">{{ $agent->email ?: 'No email on file' }}</div>
                    </div>
                    <div class="shrink-0">
                        @if ($agent->is_active)
                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-green-100 text-green-700">Already on CoreX</span>
                        @elseif (blank($agent->email))
                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-amber-100 text-amber-700">No email</span>
                        @elseif ($alreadyInvited)
                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-blue-100 text-blue-700">Invited — tick to resend</span>
                        @else
                            <span class="text-[11px] px-2 py-0.5 rounded-full bg-surface-2 text-muted">Not invited yet</span>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-4 py-8 text-center text-sm text-muted">
                    No agents were imported for this agency.
                </div>
            @endforelse
        </div>

        @if ($active->isNotEmpty() || $noEmail->isNotEmpty())
            <p class="text-xs text-muted mt-2">
                @if ($active->isNotEmpty()){{ $active->count() }} already on CoreX (no invite needed). @endif
                @if ($noEmail->isNotEmpty()){{ $noEmail->count() }} have no email on file and can't be invited here. @endif
            </p>
        @endif

        <div class="flex items-center justify-between mt-6">
            <a href="{{ route('onboarding.portal.review', $portal->urlKey()) }}"
               class="rounded-md px-4 py-2 text-xs border border-subtle">← Back to review</a>
            <div class="flex items-center gap-2">
                <a href="{{ route('onboarding.portal.finish', $portal->urlKey()) }}"
                   class="rounded-md px-4 py-2 text-xs border border-subtle text-muted">Skip &amp; finish</a>
                <button type="submit"
                        class="portal-cta rounded-md px-4 py-2 text-xs font-semibold">
                    <span x-show="checkedCount > 0">Send <span x-text="checkedCount"></span> invite<span x-show="checkedCount !== 1">s</span> &amp; finish →</span>
                    <span x-show="checkedCount === 0">Finish without inviting →</span>
                </button>
            </div>
        </div>
    </form>
</div>

<script>
    function portalInvites() {
        return {
            checkedCount: 0,
            allChecked: false,
            init() { this.recount(); },
            boxes() { return Array.from(this.$root.querySelectorAll('.agent-cb')); },
            recount() {
                const boxes = this.boxes();
                this.checkedCount = boxes.filter(b => b.checked).length;
                this.allChecked = boxes.length > 0 && this.checkedCount === boxes.length;
            },
            toggleAll(e) {
                this.boxes().forEach(b => { b.checked = e.target.checked; });
                this.recount();
            },
        };
    }
</script>
@endsection
