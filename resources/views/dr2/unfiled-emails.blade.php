{{--
    CX-109 (Johan, 2026-08-20) — the Unfiled Emails screen, DR2's primary email-filing
    workflow. "unfiled email arrives -> agent works through the unfiled pile -> picks
    the deal it belongs to." Backed by UnfiledEmailsController
    (routes: deals-dr2.unfiled-emails.*).
--}}
@extends('layouts.corex')

@section('corex-content')
<div class="w-full space-y-5"
     x-data="{
        filingId: null,
        dealQ: '', dealResults: [], dealSearching: false, filing: false, err: '',
        suggestModal: false, suggestDeal: null, suggestions: [], suggestSelected: [], batchFiling: false,
        openPicker(commId){ this.filingId = commId; this.dealQ=''; this.dealResults=[]; this.err=''; },
        closePicker(){ this.filingId = null; },
        async searchDeals(){
            if(this.dealQ.trim().length < 2){ this.dealResults = []; return; }
            this.dealSearching = true;
            try {
                const r = await fetch('{{ route('deals-dr2.unfiled-emails.deal-search') }}?q=' + encodeURIComponent(this.dealQ.trim()), {headers:{Accept:'application/json'}});
                this.dealResults = await r.json();
            } catch(e) { this.dealResults = []; }
            this.dealSearching = false;
        },
        async file(commId, dealId){
            this.filing = true; this.err = '';
            try {
                const r = await fetch('{{ url('deals-dr2/unfiled-emails') }}/' + commId + '/file', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    credentials: 'same-origin',
                    body: JSON.stringify({deal_id: dealId})
                });
                const j = await r.json();
                if(r.ok && j.ok){
                    this.filingId = null;
                    document.getElementById('unfiled-row-' + commId)?.remove();
                    if(j.suggestions && j.suggestions.length){
                        this.suggestDeal = j.deal;
                        this.suggestions = j.suggestions;
                        this.suggestSelected = j.suggestions.map(s => s.id);
                        this.suggestModal = true;
                    } else {
                        location.reload();
                    }
                } else {
                    this.err = (j.message || 'Could not file that email.');
                }
            } catch(e) { this.err = 'Could not file that email.'; }
            this.filing = false;
        },
        async confirmSuggestions(){
            if(!this.suggestSelected.length){ this.suggestModal = false; location.reload(); return; }
            this.batchFiling = true;
            try {
                await fetch('{{ route('deals-dr2.unfiled-emails.file-batch') }}', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json', Accept:'application/json', 'X-CSRF-TOKEN':'{{ csrf_token() }}'},
                    credentials: 'same-origin',
                    body: JSON.stringify({deal_id: this.suggestDeal.id, communication_ids: this.suggestSelected})
                });
            } catch(e) {}
            this.batchFiling = false;
            this.suggestModal = false;
            location.reload();
        }
     }">

    {{-- Page header --}}
    <div class="rounded-md px-6 py-5" style="background: var(--brand-default, #0b2a4a);">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between gap-3">
            <div>
                <h1 class="text-xl font-bold text-white leading-tight">Unfiled Emails</h1>
                <p class="text-sm text-white/60">Emails not yet filed to a deal. File one, and any related unfiled emails are suggested.</p>
            </div>
            <div class="text-sm text-white/80">{{ $emails->total() }} unfiled</div>
        </div>
    </div>

    {{-- Search --}}
    <form method="GET" action="{{ route('deals-dr2.unfiled-emails.index') }}" class="flex items-center gap-2">
        <input type="text" name="q" value="{{ $search }}" placeholder="Search by subject or sender…"
               class="corex-input" style="max-width:24rem;">
        <button type="submit" class="corex-btn-outline text-sm">Search</button>
        @if($search)
            <a href="{{ route('deals-dr2.unfiled-emails.index') }}" class="text-sm" style="color: var(--text-muted,#6b7280);">Clear</a>
        @endif
    </form>

    <p x-show="err" x-cloak x-text="err" style="color:#b91c1c;font-size:.85rem;"></p>

    {{-- List --}}
    <div style="padding:0;overflow:hidden;background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:.5rem;">
        @if($emails->isEmpty())
            <div style="padding:2rem;text-align:center;color:var(--text-muted,#9ca3af);">
                @if($search)
                    No unfiled emails match "{{ $search }}".
                @else
                    Nothing unfiled — every ingested email is filed to a deal.
                @endif
            </div>
        @else
            <table class="w-full text-sm" style="border-collapse:collapse;">
                <thead>
                    <tr style="background: var(--surface-muted,#f9fafb); text-align:left;">
                        <th style="padding:.6rem .9rem;">Sender</th>
                        <th style="padding:.6rem .9rem;">Subject</th>
                        <th style="padding:.6rem .9rem;">Date</th>
                        <th style="padding:.6rem .9rem;">Preview</th>
                        <th style="padding:.6rem .9rem;"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($emails as $email)
                        <tr id="unfiled-row-{{ $email->id }}" style="border-top:1px solid var(--border,rgba(0,0,0,.06));">
                            <td style="padding:.6rem .9rem;white-space:nowrap;">{{ $email->from_identifier ?: '(unknown)' }}</td>
                            <td style="padding:.6rem .9rem;font-weight:600;">{{ $email->subject ?: '(no subject)' }}</td>
                            <td style="padding:.6rem .9rem;white-space:nowrap;color:var(--text-muted,#6b7280);">{{ optional($email->occurred_at)->format('j M Y H:i') }}</td>
                            <td style="padding:.6rem .9rem;color:var(--text-muted,#6b7280);max-width:22rem;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                {{ \Illuminate\Support\Str::limit((string) ($email->body_display ?: ($email->body_text ?: $email->body_preview)), 90) }}
                            </td>
                            <td style="padding:.6rem .9rem;text-align:right;">
                                <button type="button" class="corex-btn-primary text-sm" @click="openPicker({{ $email->id }})">File</button>
                            </td>
                        </tr>
                        {{-- Deal picker, inline under the row it belongs to --}}
                        <tr x-show="filingId === {{ $email->id }}" x-cloak>
                            <td colspan="5" style="padding:.6rem .9rem;background:var(--surface-muted,#f9fafb);">
                                <div style="position:relative;max-width:26rem;">
                                    <input type="text" x-model="dealQ" @input.debounce.220ms="searchDeals()" autocomplete="off"
                                           placeholder="Search for the deal (address, deal no, seller/buyer)…" class="corex-input" style="width:100%;">
                                    <div x-show="dealResults.length" x-cloak style="position:absolute;z-index:40;left:0;right:0;top:100%;background:var(--surface,#fff);border:1px solid #e5e7eb;border-radius:.5rem;box-shadow:0 8px 24px rgba(0,0,0,.08);max-height:14rem;overflow:auto;">
                                        <template x-for="d in dealResults" :key="d.id">
                                            <div style="display:flex;align-items:center;justify-content:space-between;gap:.5rem;padding:.5rem .7rem;border-bottom:1px solid #f3f4f6;">
                                                <span x-text="d.label" style="font-size:.85rem;"></span>
                                                <button type="button" class="corex-btn-outline text-sm" :disabled="filing" @click="file({{ $email->id }}, d.id)">Select</button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                                <button type="button" class="text-sm" style="color:var(--text-muted,#6b7280);margin-top:.4rem;" @click="closePicker()">Cancel</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    <div>{{ $emails->links() }}</div>

    {{-- Suggestion modal — Johan: surface, never auto-file. Backdrop + centering matches the
         established modal pattern used elsewhere (e.g. partials/whatsapp-send-confirm-modal.blade.php,
         dr2/partials/_grant-conflict-modal.blade.php): fixed inset:0 backdrop, flex-centered content
         box with its OWN explicit background/border — "corex-card" below was never a real CSS class
         (confirmed by grep against resources/css/corex.css — only .corex-kpi-card and .corex-panel
         consume the --corex-card-bg variable), so the box rendered with no background at all and
         text sat directly on the semi-transparent backdrop. --}}
    <div x-show="suggestModal" x-cloak style="position:fixed;inset:0;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;z-index:9999;padding:1rem;">
        <div style="max-width:32rem;width:92%;padding:1.25rem;background:var(--surface,#fff);border:1px solid var(--border,#e5e7eb);border-radius:.5rem;box-shadow:0 20px 40px rgba(0,0,0,.25);">
            <h3 style="margin:0 0 .4rem;font-size:1rem;font-weight:700;">Filed. Related emails found.</h3>
            <p style="margin:0 0 .8rem;font-size:.85rem;color:var(--text-muted,#6b7280);">
                These unfiled emails share a sender, thread, or subject with the one just filed to
                <strong x-text="suggestDeal && suggestDeal.label"></strong> — they may belong to the same deal. Confirm the ones that do.
            </p>
            <div style="max-height:16rem;overflow:auto;display:flex;flex-direction:column;gap:.4rem;margin-bottom:1rem;">
                <template x-for="s in suggestions" :key="s.id">
                    <label style="display:flex;align-items:flex-start;gap:.5rem;font-size:.82rem;padding:.4rem;border:1px solid var(--border,rgba(0,0,0,.06));border-radius:6px;">
                        <input type="checkbox" :value="s.id" x-model="suggestSelected" style="margin-top:.2rem;">
                        <span>
                            <span style="font-weight:600;" x-text="s.subject || '(no subject)'"></span><br>
                            <span style="color:#9ca3af;" x-text="[s.from, s.when].filter(Boolean).join(' · ')"></span>
                        </span>
                    </label>
                </template>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:.5rem;">
                <button type="button" class="corex-btn-outline text-sm" :disabled="batchFiling" @click="suggestModal=false; location.reload();">Skip</button>
                <button type="button" class="corex-btn-primary text-sm" :disabled="batchFiling" @click="confirmSuggestions()">File selected too</button>
            </div>
        </div>
    </div>
</div>
@endsection
