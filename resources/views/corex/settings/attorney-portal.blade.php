@extends('layouts.corex')

{{-- .ai/specs/attorney-portal.md §5 - one small form; the Setup Wizard saves through the same endpoint. --}}
@section('corex-content')
<div class="max-w-2xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <div>
            <h1 class="text-xl font-bold text-white leading-tight">Attorney Portal Settings</h1>
            <p class="text-sm text-white/60">Let the transferring and bond attorneys on your deals download the documents you would email them.</p>
        </div>
    </div>

    @if(session('success'))
        <div class="rounded-md px-4 py-3 text-sm font-medium"
             style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">
            {{ session('success') }}
        </div>
    @endif
    @if($errors->any())
        <div class="rounded-md px-4 py-3 text-sm"
             style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);">
            <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    <form method="POST" action="{{ route('corex.settings.attorney-portal.update') }}"
          style="background:var(--surface); border:1px solid var(--border); border-radius:6px; overflow:hidden;">
        @csrf
        <div class="p-5 space-y-6">
            <div>
                <label class="flex items-start gap-3">
                    <input type="hidden" name="attorney_portal_enabled" value="0">
                    <input type="checkbox" name="attorney_portal_enabled" value="1" @checked($enabled) class="mt-1">
                    <span>
                        <span class="text-sm font-semibold" style="color:var(--text-primary);">Attorney portal</span>
                        <span class="block text-xs" style="color:var(--text-muted);">
                            When on, you can send an attorney a personal link. They confirm with a code we email them, then see only the deals they are on and can download the documents your Document Distribution settings give their role. They cannot upload or change anything. Off by default.
                        </span>
                    </span>
                </label>
            </div>
            <div>
                <label for="grace" class="text-sm font-semibold" style="color:var(--text-primary);">Days an attorney keeps access after a deal registers or is cancelled</label>
                <input id="grace" type="number" name="attorney_portal_access_grace_days" min="0" max="365" value="{{ $graceDays }}"
                       class="corex-input mt-1" style="width:8rem;">
                <p class="text-xs mt-1" style="color:var(--text-muted);">After this the deal disappears from the attorney's page. 30 by default.</p>
            </div>
            <div>
                <label for="link" class="text-sm font-semibold" style="color:var(--text-primary);">Days an emailed link stays valid</label>
                <input id="link" type="number" name="attorney_portal_link_valid_days" min="1" max="365" value="{{ $linkDays }}"
                       class="corex-input mt-1" style="width:8rem;">
                <p class="text-xs mt-1" style="color:var(--text-muted);">After this the attorney needs a new link (use Resend link on the deal). 30 by default.</p>
            </div>
            <div>
                <label for="notify" class="text-sm font-semibold" style="color:var(--text-primary);">Email attorneys when new documents are filed</label>
                <select id="notify" name="attorney_portal_notify" class="corex-input mt-1" style="width:14rem;">
                    <option value="daily" @selected($notify === 'daily')>Once a day, per deal</option>
                    <option value="off" @selected($notify === 'off')>Never</option>
                </select>
                <p class="text-xs mt-1" style="color:var(--text-muted);">One short email per deal per day, listing the kinds of document and a link to their page. Documents are never attached. Once a day by default.</p>
            </div>
        </div>
        <div class="px-5 py-3 flex justify-end" style="border-top:1px solid var(--border);">
            <button type="submit" class="corex-btn-primary text-sm">Save</button>
        </div>
    </form>
</div>
@endsection
