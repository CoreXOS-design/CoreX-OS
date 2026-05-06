@extends('layouts.corex')

@section('corex-content')
<div class="space-y-6">

    <div class="flex items-center justify-between">
        <h1 class="text-xl font-bold" style="color:var(--text-primary);">Contact Governance</h1>
        <a href="{{ route('command-center.settings') }}" class="text-xs px-3 py-1.5 rounded-md" style="background:var(--surface-2); color:var(--text-secondary);">Back to Settings</a>
    </div>

    {{-- Relationship to Role Manager --}}
    <div class="flex items-start gap-3 px-4 py-3 rounded-lg" style="background: var(--surface-2); border: 1px solid var(--border);">
        <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color: #00d4aa;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
        </svg>
        <div class="text-xs" style="color: var(--text-secondary);">
            <p>This is your agency-wide sharing policy for contacts — it controls how contacts are shared across the organization regardless of role. Role permissions (configured in Role Manager) control feature access and per-role scope. When the two combine, the most restrictive rule wins.</p>
            <a href="{{ route('corex.role-manager') }}" class="inline-block mt-1.5 font-medium hover:underline" style="color: #00d4aa;">Configure role permissions in Role Manager &rarr;</a>
        </div>
    </div>

    @if(session('success'))
        <div class="px-4 py-3 rounded-lg text-sm font-medium" style="background:rgba(16,185,129,0.1); color:#10b981; border:1px solid rgba(16,185,129,0.2);">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="px-4 py-3 rounded-lg text-sm" style="background:color-mix(in srgb, var(--ds-crimson) 10%, transparent); color:var(--ds-crimson); border:1px solid rgba(239,68,68,0.2);">
            <ul class="list-disc pl-4 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('command-center.settings.contact-governance.update') }}">
        @csrf @method('PUT')

        {{-- ═══════ CONTACT SHARING ═══════ --}}
        <div class="corex-panel mb-6">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Contact Sharing</h3>
            </div>
            <div class="corex-panel-body space-y-4">
                <p class="text-xs" style="color:var(--text-muted);">Controls how contacts are shared between agents within the agency.</p>

                <div class="space-y-3">
                    <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer" style="background:var(--surface-2);">
                        <input type="radio" name="sharing_mode" value="open" {{ $settings->sharing_mode === 'open' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Open</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">All contacts visible agency-wide. Any agent can view any contact.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer" style="background:var(--surface-2);">
                        <input type="radio" name="sharing_mode" value="branch" {{ $settings->sharing_mode === 'branch' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Branch</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Contacts visible within branch only. BM sees branch, admin sees all.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer" style="background:var(--surface-2);">
                        <input type="radio" name="sharing_mode" value="closed" {{ $settings->sharing_mode === 'closed' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Closed</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Contact owned by capturing agent only. BM sees branch, admin sees all.</p>
                        </div>
                    </label>
                </div>
            </div>
        </div>

        {{-- ═══════ DUPLICATE DETECTION ═══════ --}}
        <div class="corex-panel mb-6">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Duplicate Detection</h3>
            </div>
            <div class="corex-panel-body space-y-4">
                <p class="text-xs" style="color:var(--text-muted);">How the system handles potential duplicate contacts (agency-scoped).</p>

                <div class="space-y-3">
                    <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer" style="background:var(--surface-2);">
                        <input type="radio" name="duplicate_mode" value="auto_link" {{ $settings->duplicate_mode === 'auto_link' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Auto-Link</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Silent. Duplicate creation is rejected and user is linked to the existing contact automatically. Best for high-volume capture workflows.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer" style="background:var(--surface-2);">
                        <input type="radio" name="duplicate_mode" value="soft_warn" {{ $settings->duplicate_mode === 'soft_warn' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Soft Warn</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agent sees "duplicate found" modal and can choose: use existing or create anyway. All attempts logged for admin review.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer" style="background:var(--surface-2);">
                        <input type="radio" name="duplicate_mode" value="hard_block_override" {{ $settings->duplicate_mode === 'hard_block_override' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Hard Block (Admin Override)</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agent cannot create — must use existing. Admin/owner can override with a documented reason.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-lg cursor-pointer" style="background:var(--surface-2);">
                        <input type="radio" name="duplicate_mode" value="hard_block_request" {{ $settings->duplicate_mode === 'hard_block_request' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Hard Block (Request Access)</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agent cannot create and sees owner's name only (privacy). Can request access from the owner agent. Best for closed-mode agencies.</p>
                        </div>
                    </label>
                </div>

                <div class="pt-3 border-t" style="border-color:var(--border-default);">
                    <p class="text-xs font-medium mb-2" style="color:var(--text-secondary);">Match on these fields:</p>
                    <div class="flex flex-wrap gap-4">
                        @foreach(['phone' => 'Phone Number', 'email' => 'Email Address', 'id_number' => 'ID Number'] as $field => $label)
                            <label class="flex items-center gap-2 text-sm" style="color:var(--text-primary);">
                                <input type="checkbox" name="duplicate_match_fields[]" value="{{ $field }}"
                                       {{ in_array($field, $settings->duplicate_match_fields ?? []) ? 'checked' : '' }}>
                                {{ $label }}
                            </label>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════ BUYER FRESHNESS ═══════ --}}
        <div class="corex-panel mb-6">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Buyer Freshness Windows</h3>
            </div>
            <div class="corex-panel-body space-y-4">
                <p class="text-xs" style="color:var(--text-muted);">Number of days since last activity before buyer lifecycle state changes.</p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Warm → Cold (days)</label>
                        <input type="number" name="buyer_warm_days" value="{{ $settings->buyer_warm_days }}" min="1" max="365"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border-default);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Cold → Lost (days)</label>
                        <input type="number" name="buyer_cold_days" value="{{ $settings->buyer_cold_days }}" min="1" max="365"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border-default);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Lost threshold (days)</label>
                        <input type="number" name="buyer_lost_days" value="{{ $settings->buyer_lost_days }}" min="1" max="730"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border-default);">
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════ RETENTION POLICY ═══════ --}}
        <div class="corex-panel mb-6">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Retention Policy</h3>
            </div>
            <div class="corex-panel-body space-y-4">
                <p class="text-xs" style="color:var(--text-muted);">Records auto-purged after this period (POPIA + FICA + PPA require 5 years minimum for property practitioners).</p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Contact records (years)</label>
                        <input type="number" name="contact_retention_years" value="{{ $settings->contact_retention_years }}" min="5" max="99"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border-default);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Consent records (years)</label>
                        <input type="number" name="consent_retention_years" value="{{ $settings->consent_retention_years }}" min="5" max="99"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border-default);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Access log (years)</label>
                        <input type="number" name="access_log_retention_years" value="{{ $settings->access_log_retention_years }}" min="5" max="99"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border-default);">
                    </div>
                </div>

                <div class="flex items-start gap-2 p-3 rounded-lg" style="background:rgba(245,158,11,0.08); border:1px solid rgba(245,158,11,0.2);">
                    <svg class="w-4 h-4 mt-0.5 flex-shrink-0" style="color:#f59e0b;" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <p class="text-xs" style="color:var(--text-secondary);">Minimum 5 years required by law. POPIA (Protection of Personal Information Act), FICA (Financial Intelligence Centre Act), and PPA (Property Practitioners Act 22 of 2019) mandate this retention for property practitioners.</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="px-5 py-2.5 rounded-md text-sm font-semibold text-white" style="background:var(--brand-button);">
                Save Settings
            </button>
        </div>
    </form>
</div>
@endsection
