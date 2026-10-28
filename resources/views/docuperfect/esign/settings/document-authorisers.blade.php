{{-- DESIGN SYSTEM COMPLIANCE: UI_DESIGN_SYSTEM.md v 2026-04-20 — spec: .ai/specs/esign-template-transfer.md §13.3 --}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5" data-document-authorisers>

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-base font-bold leading-tight" style="color: var(--text-primary);">Who may authorise documents</h1>
        <p class="text-xs" style="color: var(--text-muted);">When CoreX prepares a document for your agency (a lease, a mandate, a disclosure form), it is not live until one of the people you choose here approves it. Tick the people, or whole roles, who may do that.</p>
    </div>

    @include('corex.partials._hub-tabs', ['hub' => \App\Support\Navigation\Hubs\ESignSettingsTabs::class])

    @if(session('status'))
        <div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-green, #059669) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-green, #059669) 30%, transparent); color: var(--ds-green, #059669);">{{ session('status') }}</div>
    @endif
    @if(session('error') || $errors->any())
        <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">{{ session('error') ?: $errors->first() }}</div>
    @endif

    @unless($configured)
        <div class="rounded-md px-4 py-3 text-xs" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent); color: var(--text-primary);" data-not-configured>
            No one is chosen yet, so for now your administrators can approve. Choose who here.
        </div>
    @endunless
    @if($withoutPermission !== [])
        <div class="rounded-md px-4 py-3 text-xs" style="background: color-mix(in srgb, var(--ds-amber, #d97706) 10%, transparent); border: 1px solid color-mix(in srgb, var(--ds-amber, #d97706) 30%, transparent); color: var(--text-primary);" data-missing-permission>
            {{ implode(', ', $withoutPermission) }}: their role cannot open the Approvals screen yet. Give that role "Approve documents released to your agency" in Role Manager.
        </div>
    @endif

    <form method="POST" action="{{ route('docuperfect.esign.settings.document-authorisers.update') }}" class="space-y-5">
        @csrf
        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">People</h2>
            <p class="text-xs mb-2" style="color: var(--text-muted);">These named people may approve or decline a document released to your agency.</p>
            <input type="hidden" name="document_authoriser_user_ids[]" value="">
            <div class="rounded-md divide-y" style="border: 1px solid var(--border);">
                @forelse($users as $u)
                    <label class="flex items-center gap-2.5 px-3 py-2 cursor-pointer">
                        <input type="checkbox" name="document_authoriser_user_ids[]" value="{{ $u->id }}" @checked(in_array((int) $u->id, $settings['user_ids'], true))>
                        <span class="text-sm" style="color: var(--text-primary);">{{ $u->name }}</span>
                        <span class="text-xs" style="color: var(--text-muted);">{{ $u->email }}</span>
                    </label>
                @empty
                    <p class="px-3 py-2 text-xs" style="color: var(--text-muted);">You haven't added anyone yet. Add your team first, then come back and choose.</p>
                @endforelse
            </div>
        </div>

        <div class="rounded-md p-5" style="background: var(--surface); border: 1px solid var(--border);">
            <h2 class="text-sm font-semibold" style="color: var(--text-primary);">Roles</h2>
            <p class="text-xs mb-2" style="color: var(--text-muted);">Everyone who holds a ticked role may approve too.</p>
            <input type="hidden" name="document_authoriser_roles[]" value="">
            <div class="flex flex-wrap gap-x-5 gap-y-2">
                @foreach($roles as $r)
                    <label class="flex items-center gap-2 text-sm cursor-pointer" style="color: var(--text-primary);">
                        <input type="checkbox" name="document_authoriser_roles[]" value="{{ $r->name }}" @checked(in_array((string) $r->name, $settings['roles'], true))>
                        {{ $r->label ?? ucwords(str_replace('_', ' ', $r->name)) }}
                    </label>
                @endforeach
            </div>
        </div>

        <div class="flex items-center justify-between">
            <p class="text-xs" style="color: var(--text-muted);" data-current-authorisers>
                Right now these people can approve: {{ $currentNames !== [] ? implode(', ', $currentNames) : 'nobody (no active administrator found)' }}.
            </p>
            <button type="submit" class="corex-btn-primary text-sm px-5 py-2">Save</button>
        </div>
    </form>
</div>
@endsection
