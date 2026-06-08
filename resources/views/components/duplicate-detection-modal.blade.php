{{--
    Duplicate Detection Modal — included in contact create forms.
    Displays when session('duplicate_detected') is set by the controller.

    Expected session data structure:
    {
        duplicates: [{id, name, phone, email, owner, url}],
        mode: 'soft_warn' | 'hard_block_override' | 'hard_block_request',
        match_field: 'phone' | 'email' | 'id_number',
        can_override: bool
    }
--}}
@if(session('duplicate_detected'))
@php $dupData = session('duplicate_detected'); @endphp
<div x-data="{ showOverrideForm: false }"
     class="rounded-lg p-5 mb-4" style="background: color-mix(in srgb, var(--ds-amber) 8%, var(--surface)); border: 2px solid color-mix(in srgb, var(--ds-amber) 40%, transparent);">

    {{-- Header --}}
    <div class="flex items-start gap-3 mb-4">
        <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5 flex-shrink-0 mt-0.5" style="color:var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
        </svg>
        <div>
            <div class="text-sm font-semibold" style="color:var(--ds-amber);">
                @if($dupData['mode'] === 'soft_warn')
                    Possible Duplicate Found
                @elseif($dupData['mode'] === 'hard_block_override')
                    Duplicate Blocked
                @elseif($dupData['mode'] === 'hard_block_request')
                    Contact Already Exists
                @endif
            </div>
            <p class="text-xs mt-0.5" style="color:var(--text-secondary);">
                @if($dupData['mode'] === 'soft_warn')
                    A contact matching this {{ $dupData['match_field'] ?? 'data' }} already exists. You can use the existing contact or create a new one.
                @elseif($dupData['mode'] === 'hard_block_override')
                    A contact matching this {{ $dupData['match_field'] ?? 'data' }} already exists. Only administrators may override this block.
                @elseif($dupData['mode'] === 'hard_block_request')
                    A contact matching this {{ $dupData['match_field'] ?? 'data' }} is already managed by another agent.
                @endif
            </p>
        </div>
    </div>

    {{-- Duplicate contacts list --}}
    <div class="space-y-2 mb-4">
        @foreach($dupData['duplicates'] ?? [] as $dup)
            <div class="rounded-md p-3 flex items-center gap-3" style="background:var(--surface); border:1px solid var(--border);">
                <div class="w-8 h-8 rounded-full flex items-center justify-center text-xs font-bold text-white flex-shrink-0"
                     style="background:var(--brand-icon,#0ea5e9);">
                    {{ strtoupper(substr($dup['name'] ?? '', 0, 1)) }}
                </div>
                <div class="flex-1 min-w-0">
                    <div class="text-sm font-semibold truncate" style="color:var(--text-primary);">{{ $dup['name'] }}</div>
                    <div class="text-xs" style="color:var(--text-muted);">
                        @if(!empty($dup['phone'])) {{ $dup['phone'] }} @endif
                        @if(!empty($dup['phone']) && !empty($dup['email'])) · @endif
                        @if(!empty($dup['email'])) {{ $dup['email'] }} @endif
                        @if(empty($dup['phone']) && empty($dup['email'])) Managed by {{ $dup['owner'] }} @endif
                    </div>
                    @if($dupData['mode'] === 'hard_block_request')
                        <div class="text-xs mt-0.5" style="color:var(--text-muted);">Owner: {{ $dup['owner'] }}</div>
                    @endif
                </div>
                {{-- Only link to records this user can actually open — otherwise the show route 404s. --}}
                @if(($dup['can_view'] ?? true) && !empty($dup['url']))
                    <a href="{{ $dup['url'] }}" class="text-xs font-medium px-2 py-1 rounded" style="background:var(--surface-2); color:var(--brand-icon);">View</a>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Actions --}}
    <div class="flex flex-wrap items-center gap-2">
        {{-- Use existing (available when the match is viewable, except hard_block_request).
             An agency-wide match owned by another agent/branch has no url and is skipped
             here so we never offer a link the show route would 404 on. --}}
        @if($dupData['mode'] !== 'hard_block_request' && ($dupData['duplicates'][0]['can_view'] ?? true) && !empty($dupData['duplicates'][0]['url']))
            <a href="{{ $dupData['duplicates'][0]['url'] }}"
               class="text-xs font-semibold px-3 py-1.5 rounded-md transition hover:opacity-80"
               style="background:var(--brand-button); color:#fff;">
                Use Existing Contact
            </a>
        @endif

        {{-- Create anyway (soft_warn only) --}}
        @if($dupData['mode'] === 'soft_warn')
            <form method="POST" action="{{ url()->current() }}" class="inline">
                @csrf
                @foreach(old() as $key => $value)
                    @if(!is_array($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input type="hidden" name="bypass_duplicate_check" value="1">
                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md transition hover:opacity-80"
                        style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                    Create Anyway
                </button>
            </form>
        @endif

        {{-- Override (hard_block_override, admin only) --}}
        @if($dupData['mode'] === 'hard_block_override' && ($dupData['can_override'] ?? false))
            <button type="button" @click="showOverrideForm = !showOverrideForm"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md transition hover:opacity-80"
                    style="background:var(--surface-2); color:var(--ds-amber); border:1px solid color-mix(in srgb, var(--ds-amber) 40%, transparent);">
                Override (Admin)
            </button>
        @endif

        {{-- Request access (hard_block_request, placeholder) --}}
        @if($dupData['mode'] === 'hard_block_request')
            <button type="button" onclick="alert('Access request feature pending — will be available in M3.5.')"
                    class="text-xs font-semibold px-3 py-1.5 rounded-md transition hover:opacity-80"
                    style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                Request Access
            </button>
        @endif
    </div>

    {{-- Override reason form (admin only, hard_block_override) --}}
    @if($dupData['mode'] === 'hard_block_override' && ($dupData['can_override'] ?? false))
        <div x-show="showOverrideForm" x-cloak x-transition class="mt-3 pt-3" style="border-top:1px solid var(--border);">
            <form method="POST" action="{{ url()->current() }}">
                @csrf
                @foreach(old() as $key => $value)
                    @if(!is_array($value))
                        <input type="hidden" name="{{ $key }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input type="hidden" name="bypass_duplicate_check" value="1">
                <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Reason for override (required)</label>
                <input type="text" name="override_reason" required
                       class="w-full px-3 py-2 rounded-md text-sm mb-2"
                       style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);"
                       placeholder="Why is this not a duplicate?">
                <button type="submit" class="text-xs font-semibold px-3 py-1.5 rounded-md text-white"
                        style="background:var(--ds-amber);">
                    Create with Override
                </button>
            </form>
        </div>
    @endif
</div>
@endif
