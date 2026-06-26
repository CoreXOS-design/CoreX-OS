@extends('layouts.corex')

{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 --}}

@section('corex-content')
<div class="w-full space-y-5">

    {{-- Page header (branded — Pattern A) --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Contact Governance</h1>
                <p class="text-sm text-white/60">Agency-wide sharing, duplicate, freshness and retention rules for contacts.</p>
            </div>
            <div class="flex items-center gap-2 flex-wrap">
                <a href="{{ route('command-center.settings') }}" class="corex-btn-outline text-sm"
                   style="color:#fff; border-color:rgba(255,255,255,0.25); background:rgba(255,255,255,0.08);">
                    <svg xmlns="http://www.w3.org/2000/svg" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                    Back to Settings
                </a>
            </div>
        </div>
    </div>

    {{-- Relationship to Role Manager (info alert — §3.9) --}}
    <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
         style="background: color-mix(in srgb, var(--brand-icon, #0ea5e9) 10%, transparent);
                border: 1px solid color-mix(in srgb, var(--brand-icon, #0ea5e9) 30%, transparent);
                color: var(--text-secondary);">
        <svg class="w-5 h-5 flex-shrink-0" style="color: var(--brand-icon, #0ea5e9);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
        </svg>
        <div class="flex-1">
            <p>This is your agency-wide sharing policy for contacts — it controls how contacts are shared across the organization regardless of role. Role permissions (configured in Role Manager) control feature access and per-role scope. When the two combine, the most restrictive rule wins.</p>
            <a href="{{ route('corex.role-manager') }}" class="inline-block mt-1.5 font-semibold hover:underline" style="color: var(--brand-icon, #0ea5e9);">Configure role permissions in Role Manager &rarr;</a>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-green) 30%, transparent);
                    color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson, #c41e3a) 10%, transparent);
                    border: 1px solid color-mix(in srgb, var(--ds-crimson, #c41e3a) 30%, transparent);
                    color: var(--text-primary);">
            <ul class="list-disc pl-4 space-y-1">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form method="POST" action="{{ route('command-center.settings.contact-governance.update') }}">
        @csrf @method('PUT')

        {{-- ═══════ CONTACT VISIBILITY (now in Role Manager) ═══════ --}}
        <div class="corex-panel mb-6">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Contact Visibility</h3>
            </div>
            <div class="corex-panel-body">
                <div class="flex items-start gap-3 p-3 rounded-md" style="background: var(--surface-2); border: 1px solid var(--border);">
                    <svg class="w-5 h-5 mt-0.5 flex-shrink-0" style="color: var(--brand-button, #0ea5e9);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 11.25.041-.02a.75.75 0 0 1 1.063.852l-.708 2.836a.75.75 0 0 0 1.063.853l.041-.021M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0Zm-9-3.75h.008v.008H12V8.25Z" />
                    </svg>
                    <div class="text-xs" style="color: var(--text-secondary);">
                        <p class="font-medium mb-1" style="color:var(--text-primary);">Contact visibility is now configured per role in Role Manager.</p>
                        <p>Each role's <strong>Contacts &rarr; Scope</strong> setting controls what contacts that role can see: <em>Own</em> (created by them), <em>Branch</em> (their branch team), or <em>All</em> (agency-wide).</p>
                        <a href="{{ route('corex.role-manager') }}" class="inline-block mt-2 font-semibold hover:underline" style="color: var(--brand-button, #0ea5e9);">Configure in Role Manager &rarr;</a>
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════ BUYER PIPELINE DEFAULT SCOPE ═══════ --}}
        <div class="corex-panel mb-6">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Buyer Pipeline Default View</h3>
            </div>
            <div class="corex-panel-body space-y-4">
                <p class="text-xs" style="color:var(--text-muted);">Controls what agents see by default when they open the Buyer Pipeline. This is a workspace preference — agents can still toggle to see other scopes if their role permits.</p>

                <div class="space-y-3">
                    <label class="flex items-start gap-3 p-3 rounded-md cursor-pointer" style="background:var(--surface-2); border:1px solid var(--border);">
                        <input type="radio" name="buyer_pipeline_default_scope" value="own" {{ ($settings->buyer_pipeline_default_scope ?? 'own') === 'own' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">My Buyers</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agents see only buyers they created. Best for focused pipeline management.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-md cursor-pointer" style="background:var(--surface-2); border:1px solid var(--border);">
                        <input type="radio" name="buyer_pipeline_default_scope" value="branch" {{ ($settings->buyer_pipeline_default_scope ?? '') === 'branch' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Branch Buyers</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agents see buyers from their entire branch team. Good for collaborative branches.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-md cursor-pointer" style="background:var(--surface-2); border:1px solid var(--border);">
                        <input type="radio" name="buyer_pipeline_default_scope" value="agency" {{ ($settings->buyer_pipeline_default_scope ?? '') === 'agency' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">All Agency Buyers</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agents see all buyers agency-wide by default. Use when the entire team collaborates on buyer leads.</p>
                        </div>
                    </label>
                </div>
                <p class="text-xs" style="color:var(--text-muted);">Admin/Owner roles always see all buyers. This setting affects agents and branch managers only.</p>
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
                    <label class="flex items-start gap-3 p-3 rounded-md cursor-pointer" style="background:var(--surface-2); border:1px solid var(--border);">
                        <input type="radio" name="duplicate_mode" value="auto_link" {{ $settings->duplicate_mode === 'auto_link' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Auto-Link</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Silent. Duplicate creation is rejected and user is linked to the existing contact automatically. Best for high-volume capture workflows.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-md cursor-pointer" style="background:var(--surface-2); border:1px solid var(--border);">
                        <input type="radio" name="duplicate_mode" value="soft_warn" {{ $settings->duplicate_mode === 'soft_warn' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Soft Warn</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agent sees "duplicate found" modal and can choose: use existing or create anyway. All attempts logged for admin review.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-md cursor-pointer" style="background:var(--surface-2); border:1px solid var(--border);">
                        <input type="radio" name="duplicate_mode" value="hard_block_override" {{ $settings->duplicate_mode === 'hard_block_override' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Hard Block (Admin Override)</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agent cannot create — must use existing. Admin/owner can override with a documented reason.</p>
                        </div>
                    </label>
                    <label class="flex items-start gap-3 p-3 rounded-md cursor-pointer" style="background:var(--surface-2); border:1px solid var(--border);">
                        <input type="radio" name="duplicate_mode" value="hard_block_request" {{ $settings->duplicate_mode === 'hard_block_request' ? 'checked' : '' }} class="mt-0.5">
                        <div>
                            <span class="text-sm font-medium" style="color:var(--text-primary);">Hard Block (Request Access)</span>
                            <p class="text-xs mt-0.5" style="color:var(--text-muted);">Agent cannot create and sees owner's name only (privacy). Can request access from the owner agent. Best for closed-mode agencies.</p>
                        </div>
                    </label>
                </div>

                <div class="pt-3 border-t" style="border-color:var(--border);">
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

                <div class="pt-3 border-t" style="border-color:var(--border);">
                    <p class="text-xs font-medium mb-1" style="color:var(--text-secondary);">Property address duplicate guard</p>
                    <p class="text-xs mb-2" style="color:var(--text-muted);">When an agent uses a contact's address to create a property, how aggressively should CoreX warn that a property at that address may already exist?</p>
                    <select name="address_match_mode" class="w-full md:w-80 px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                        <option value="off"      {{ ($settings->address_match_mode ?? 'standard') === 'off' ? 'selected' : '' }}>Off — never warn</option>
                        <option value="standard" {{ ($settings->address_match_mode ?? 'standard') === 'standard' ? 'selected' : '' }}>Standard — warn on any address match (recommended)</option>
                        <option value="strict"   {{ ($settings->address_match_mode ?? 'standard') === 'strict' ? 'selected' : '' }}>Strict — warn only on an exact street match</option>
                    </select>

                    {{-- Part 3 — capture-time "already on our books" warning toggle. --}}
                    <label class="flex items-start gap-2 mt-4 cursor-pointer">
                        <input type="hidden" name="warn_on_held_address_capture" value="0">
                        <input type="checkbox" name="warn_on_held_address_capture" value="1" class="mt-0.5"
                               {{ ($settings->warn_on_held_address_capture ?? true) ? 'checked' : '' }}>
                        <span class="text-xs" style="color:var(--text-secondary);">
                            <span class="font-medium" style="color:var(--text-primary);">Warn when capturing an address we already hold</span><br>
                            When an agent captures a property address for a prospect, warn them if HFC already
                            holds that property — in agency stock (we have a mandate) or as captured property
                            intelligence — so they don't canvass an owner we already represent. Catches the
                            hidden-address cases the automatic stock badge can't. <strong>Recommended on.</strong>
                            (Honours the duplicate-guard mode above — "Off" disables this too.)
                        </span>
                    </label>

                    {{-- Buyer loop — auto-seed a criteria-bearing buyer from a portal/listing lead. --}}
                    <label class="flex items-start gap-2 mt-4 cursor-pointer">
                        <input type="hidden" name="portal_lead_auto_seed_buyer" value="0">
                        <input type="checkbox" name="portal_lead_auto_seed_buyer" value="1" class="mt-0.5"
                               {{ ($settings->portal_lead_auto_seed_buyer ?? true) ? 'checked' : '' }}>
                        <span class="text-xs" style="color:var(--text-secondary);">
                            <span class="font-medium" style="color:var(--text-primary);">Auto-build a buyer from a portal enquiry</span><br>
                            When a P24/PP lead (or a manual capture tied to a listing) arrives, seed a buyer
                            wishlist derived from the enquired property (area, price ± the configured band,
                            beds, type) so the enquirer lands on the buyer pipeline as a real, criteria-bearing
                            buyer and their demand feeds Market Intelligence. <strong>Recommended on.</strong>
                            When off, leads still appear in the inbox exactly as before.
                        </span>
                    </label>
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
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Cold → Lost (days)</label>
                        <input type="number" name="buyer_cold_days" value="{{ $settings->buyer_cold_days }}" min="1" max="365"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Lost threshold (days)</label>
                        <input type="number" name="buyer_lost_days" value="{{ $settings->buyer_lost_days }}" min="1" max="730"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                    </div>
                </div>
            </div>
        </div>

        {{-- ═══════ OUTREACH — NO-RESPONSE WINDOW (AT-81) ═══════ --}}
        <div class="corex-panel mb-6">
            <div class="corex-panel-header">
                <h3 class="corex-panel-title">Seller Outreach</h3>
            </div>
            <div class="corex-panel-body space-y-4">
                <p class="text-xs" style="color:var(--text-muted);">When a consent-request is sent the contact is marked <strong>awaiting reply</strong> and cannot be messaged again. If there is no reply, click, opt-in or opt-out within this many days, they are automatically lapsed to <strong>no response</strong> &mdash; suppressed from further outreach, but kept distinct from someone who explicitly opted out.</p>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">No-response window (days)</label>
                        <input type="number" name="outreach_no_response_days" value="{{ $settings->outreach_no_response_days }}" min="1" max="365"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
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
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Consent records (years)</label>
                        <input type="number" name="consent_retention_years" value="{{ $settings->consent_retention_years }}" min="5" max="99"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1" style="color:var(--text-secondary);">Access log (years)</label>
                        <input type="number" name="access_log_retention_years" value="{{ $settings->access_log_retention_years }}" min="5" max="99"
                               class="w-full px-3 py-2 rounded-md text-sm" style="background:var(--surface-2); color:var(--text-primary); border:1px solid var(--border);">
                    </div>
                </div>

                <div class="rounded-md px-4 py-3 text-sm flex items-start gap-3"
                     style="background: color-mix(in srgb, var(--ds-amber) 10%, transparent);
                            border: 1px solid color-mix(in srgb, var(--ds-amber) 30%, transparent);
                            color: var(--text-secondary);">
                    <svg class="w-5 h-5 flex-shrink-0" style="color: var(--ds-amber);" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z" />
                    </svg>
                    <p class="flex-1">Minimum 5 years required by law. POPIA (Protection of Personal Information Act), FICA (Financial Intelligence Centre Act), and PPA (Property Practitioners Act 22 of 2019) mandate this retention for property practitioners.</p>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button type="submit" class="corex-btn-primary text-sm">
                Save Settings
            </button>
        </div>
    </form>
</div>
@endsection
