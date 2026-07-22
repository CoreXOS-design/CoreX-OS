{{--
    AT-334 — Deal Structure tab. Pick the deal's suspensive conditions → the pipeline
    assembles on the left (base spine + each condition's steps + the movable Granted
    marker, with follows-based dates). @include('dr2._deal-structure', [...]).
    Restructure (change conditions after build) is a later phase.
--}}
<div class="corex-card" style="padding:1rem;" data-tour="dr2-deal-structure">
    <div style="font-size:.9rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;color:var(--text-muted,#6b7280);margin-bottom:.6rem;">Deal Structure</div>

    @if($hasPipeline)
        {{-- Built already — show the active conditions; Restructure lands in a later phase. --}}
        <p style="font-size:.85rem;color:var(--text-secondary,#4b5563);margin:0 0 .6rem;">This deal's pipeline is built from these suspensive conditions:</p>
        <div style="display:flex;flex-direction:column;gap:.4rem;margin-bottom:.6rem;">
            @forelse($dealConditions as $key => $c)
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.85rem;padding:.4rem .6rem;border:1px solid var(--border,rgba(0,0,0,.08));border-radius:8px;">
                    <span style="font-weight:600;">{{ $conditionCatalog[$key]['label'] ?? ucfirst($key) }}</span>
                    @php $opts = $c->options ?? []; @endphp
                    @if($key==='cash' && !empty($opts['payments']))<span style="color:var(--text-muted,#6b7280);">· {{ $opts['payments'] }} payment{{ $opts['payments']>1?'s':'' }}</span>@endif
                    @if($key==='bond' && !empty($opts['deposit']))<span style="color:var(--text-muted,#6b7280);">· with deposit</span>@endif
                    <span class="ds-badge ds-badge-{{ $c->status==='met' ? 'success' : ($c->status==='waived' ? 'default' : ($c->status==='failed' ? 'danger' : 'info')) }}" style="margin-left:auto;">{{ ucfirst($c->status) }}</span>
                </div>
            @empty
                <p style="font-size:.82rem;color:var(--text-muted,#9ca3af);">Built from a standard template (no composable conditions recorded).</p>
            @endforelse
        </div>
        <p style="font-size:.75rem;color:var(--text-muted,#9ca3af);margin:0;">Restructure (change conditions with a reason + addendum) is coming soon.</p>

    @elseif($locked)
        <p style="font-size:.85rem;color:var(--text-muted,#6b7280);margin:0;">This deal is not proceeding — its structure is locked.</p>

    @else
        <p style="font-size:.85rem;color:var(--text-secondary,#4b5563);margin:0 0 .8rem;">Choose the suspensive conditions on this deal. The pipeline builds itself from them.</p>

        <form method="POST" action="{{ route('deals-dr2.pipeline.structure', $deal) }}"
              x-data="{ bond: {{ old('conditions.bond.on') ? 'true' : 'false' }}, cash: {{ old('conditions.cash.on') ? 'true' : 'false' }}, sale: {{ old('conditions.sale_of_another.on') ? 'true' : 'false' }} }">
            @csrf

            {{-- Bond --}}
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;cursor:pointer;padding:.35rem 0;">
                <input type="checkbox" name="conditions[bond][on]" value="1" x-model="bond"> Bond
            </label>
            <div x-show="bond" x-cloak style="padding:.1rem 0 .5rem 1.6rem;">
                <label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;cursor:pointer;color:var(--text-secondary,#4b5563);">
                    <input type="checkbox" name="conditions[bond][deposit]" value="1" {{ old('conditions.bond.deposit') ? 'checked' : '' }}> Include a deposit step
                </label>
            </div>

            {{-- Cash --}}
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;cursor:pointer;padding:.35rem 0;border-top:1px solid var(--border,rgba(0,0,0,.06));">
                <input type="checkbox" name="conditions[cash][on]" value="1" x-model="cash"> Cash
            </label>
            <div x-show="cash" x-cloak style="padding:.1rem 0 .5rem 1.6rem;display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--text-secondary,#4b5563);">
                How many payments?
                <input type="number" name="conditions[cash][payments]" min="1" max="6" value="{{ old('conditions.cash.payments', 1) }}" class="corex-input" style="width:4.5rem;font-size:.82rem;padding:.2rem .4rem;">
            </div>

            {{-- Sale of another property --}}
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;cursor:pointer;padding:.35rem 0;border-top:1px solid var(--border,rgba(0,0,0,.06));">
                <input type="checkbox" name="conditions[sale_of_another][on]" value="1" x-model="sale"> Subject to sale of another property
            </label>

            <div style="margin-top:1rem;display:flex;align-items:center;gap:.75rem;">
                <button type="submit" class="corex-btn-primary" style="font-size:.9rem;" :disabled="!bond && !cash && !sale">Build pipeline →</button>
                <span x-show="!bond && !cash && !sale" x-cloak style="font-size:.75rem;color:var(--text-muted,#9ca3af);">Pick at least one condition.</span>
            </div>
        </form>
    @endif
</div>
