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
        <p style="font-size:.85rem;color:var(--text-secondary,#4b5563);margin:0 0 .8rem;">Choose the suspensive conditions on this deal, and when each is due. The pipeline builds itself from them.</p>

        @php $pdOld = (array) old('conditions.cash.payment_dues', []); @endphp
        <form method="POST" action="{{ route('deals-dr2.pipeline.structure', $deal) }}"
              x-data="{
                signed: {{ \Illuminate\Support\Js::from(old('deal_signed_date', optional($deal->deal_date)->format('Y-m-d'))) }},
                bond: {{ old('conditions.bond.on') ? 'true' : 'false' }},
                cash: {{ old('conditions.cash.on') ? 'true' : 'false' }},
                sale: {{ old('conditions.sale_of_another.on') ? 'true' : 'false' }},
                deposit: {{ old('conditions.bond.deposit') ? 'true' : 'false' }},
                bondDue: {{ \Illuminate\Support\Js::from(old('conditions.bond.bond_due', '')) }},
                bondDueDirty: {{ old('conditions.bond.bond_due') ? 'true' : 'false' }},
                depositDue: {{ \Illuminate\Support\Js::from(old('conditions.bond.deposit_due', '')) }},
                depositAnchor: {{ \Illuminate\Support\Js::from(old('conditions.bond.deposit_anchor', 'signed')) }},
                depositOffset: {{ (int) old('conditions.bond.deposit_offset', 3) }},
                fundsMode: {{ \Illuminate\Support\Js::from(old('conditions.cash.funds_mode', 'available')) }},
                proofDue: {{ \Illuminate\Support\Js::from(old('conditions.cash.proof_due', '')) }},
                payments: {{ (int) old('conditions.cash.payments', 1) }},
                paymentDues: {{ \Illuminate\Support\Js::from((object) $pdOld) }},
                propertySoldDue: {{ \Illuminate\Support\Js::from(old('conditions.sale_of_another.property_sold_due', '')) }},
                addDays(d, n) { if (!d) return ''; const x = new Date(d + 'T00:00:00'); if (isNaN(x.getTime())) return ''; x.setDate(x.getDate() + n); return x.toISOString().slice(0, 10); },
                syncDefaults() { if (!this.bondDueDirty) this.bondDue = this.addDays(this.signed, 30); },
                init() { if (!this.bondDue) this.bondDue = this.addDays(this.signed, 30); for (let i = 1; i <= 6; i++) { if (this.paymentDues[i] === undefined) this.paymentDues[i] = ''; } }
              }">
            @csrf

            {{-- Editable deal-signed (anchor) date — the 30-day bond default and every pipeline date run from here. --}}
            <div style="margin-bottom:.6rem;">
                <label style="display:block;font-size:.82rem;font-weight:600;color:var(--text-secondary,#4b5563);margin-bottom:.2rem;">Deal signed date</label>
                <input type="date" name="deal_signed_date" x-model="signed" @change="syncDefaults()" class="corex-input" style="font-size:.82rem;padding:.25rem .45rem;">
                <p style="font-size:.72rem;color:var(--text-muted,#9ca3af);margin:.2rem 0 0;">The real signed date (not the capture date). Pipeline dates run from here.</p>
            </div>

            {{-- Bond --}}
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;cursor:pointer;padding:.35rem 0;border-top:1px solid var(--border,rgba(0,0,0,.06));">
                <input type="checkbox" name="conditions[bond][on]" value="1" x-model="bond"> Bond
            </label>
            <div x-show="bond" x-cloak style="padding:.1rem 0 .5rem 1.6rem;display:flex;flex-direction:column;gap:.4rem;">
                <label style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;cursor:pointer;color:var(--text-secondary,#4b5563);">
                    <input type="checkbox" name="conditions[bond][deposit]" value="1" x-model="deposit"> Include a deposit step
                </label>
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--text-secondary,#4b5563);">
                    <span style="min-width:6.5rem;">Bond due by</span>
                    <input type="date" name="conditions[bond][bond_due]" x-model="bondDue" @input="bondDueDirty = true" class="corex-input" style="font-size:.82rem;padding:.2rem .4rem;">
                    <span style="font-size:.7rem;color:var(--text-muted,#9ca3af);">default 30 days from signed</span>
                </div>
                <div x-show="deposit" x-cloak style="display:flex;align-items:center;gap:.5rem;flex-wrap:wrap;font-size:.82rem;color:var(--text-secondary,#4b5563);">
                    <span style="min-width:6.5rem;">Deposit due</span>
                    <select name="conditions[bond][deposit_anchor]" x-model="depositAnchor" class="corex-input" style="font-size:.82rem;padding:.2rem .4rem;">
                        <option value="signed">Deal Signed +</option>
                        <option value="bond_approved">Bond Approved (bond grant) +</option>
                        <option value="fixed">a fixed date</option>
                    </select>
                    <template x-if="depositAnchor !== 'fixed'">
                        <span style="display:inline-flex;align-items:center;gap:.3rem;">
                            <input type="number" min="0" name="conditions[bond][deposit_offset]" x-model.number="depositOffset" class="corex-input" style="width:3.6rem;font-size:.82rem;padding:.2rem .4rem;">
                            <span style="font-size:.72rem;color:var(--text-muted,#9ca3af);">days</span>
                        </span>
                    </template>
                    <template x-if="depositAnchor === 'fixed'">
                        <input type="date" name="conditions[bond][deposit_due]" x-model="depositDue" class="corex-input" style="font-size:.82rem;padding:.2rem .4rem;">
                    </template>
                    <span x-show="depositAnchor === 'bond_approved'" style="width:100%;font-size:.72rem;color:var(--text-muted,#9ca3af);">Still a suspensive condition — if this lands after Bond Approved it becomes the deal's Granted date.</span>
                </div>
            </div>

            {{-- Cash --}}
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;cursor:pointer;padding:.35rem 0;border-top:1px solid var(--border,rgba(0,0,0,.06));">
                <input type="checkbox" name="conditions[cash][on]" value="1" x-model="cash"> Cash
            </label>
            <div x-show="cash" x-cloak style="padding:.1rem 0 .5rem 1.6rem;display:flex;flex-direction:column;gap:.45rem;font-size:.82rem;color:var(--text-secondary,#4b5563);">
                <div style="display:flex;flex-direction:column;gap:.25rem;">
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                        <input type="radio" name="conditions[cash][funds_mode]" value="available" x-model="fundsMode"> Funds available now
                    </label>
                    <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer;">
                        <input type="radio" name="conditions[cash][funds_mode]" value="proof_later" x-model="fundsMode"> Proof of funds now, payment later
                    </label>
                </div>
                <div x-show="fundsMode === 'proof_later'" x-cloak style="display:flex;align-items:center;gap:.5rem;">
                    <span style="min-width:6.5rem;">Proof of funds by</span>
                    <input type="date" name="conditions[cash][proof_due]" x-model="proofDue" class="corex-input" style="font-size:.82rem;padding:.2rem .4rem;">
                </div>
                <div style="display:flex;align-items:center;gap:.5rem;">
                    <span style="min-width:6.5rem;">How many payments?</span>
                    <input type="number" name="conditions[cash][payments]" min="1" max="6" x-model.number="payments" class="corex-input" style="width:4.5rem;font-size:.82rem;padding:.2rem .4rem;">
                </div>
                <template x-for="i in payments" :key="i">
                    <div style="display:flex;align-items:center;gap:.5rem;">
                        <span style="min-width:6.5rem;">Payment <span x-text="i"></span> due by</span>
                        <input type="date" :name="`conditions[cash][payment_dues][${i}]`" x-model="paymentDues[i]" class="corex-input" style="font-size:.82rem;padding:.2rem .4rem;">
                    </div>
                </template>
            </div>

            {{-- Sale of another property --}}
            <label style="display:flex;align-items:center;gap:.5rem;font-size:.9rem;font-weight:600;cursor:pointer;padding:.35rem 0;border-top:1px solid var(--border,rgba(0,0,0,.06));">
                <input type="checkbox" name="conditions[sale_of_another][on]" value="1" x-model="sale"> Subject to sale of another property
            </label>
            <div x-show="sale" x-cloak style="padding:.1rem 0 .5rem 1.6rem;display:flex;align-items:center;gap:.5rem;font-size:.82rem;color:var(--text-secondary,#4b5563);">
                <span style="min-width:6.5rem;">Property sold by</span>
                <input type="date" name="conditions[sale_of_another][property_sold_due]" x-model="propertySoldDue" class="corex-input" style="font-size:.82rem;padding:.2rem .4rem;">
            </div>

            <div style="margin-top:1rem;display:flex;align-items:center;gap:.75rem;">
                <button type="submit" class="corex-btn-primary" style="font-size:.9rem;" :disabled="!bond && !cash && !sale">Build pipeline →</button>
                <span x-show="!bond && !cash && !sale" x-cloak style="font-size:.75rem;color:var(--text-muted,#9ca3af);">Pick at least one condition.</span>
            </div>
        </form>
    @endif

    {{-- (The manual "Decline deal" control moved OUT of this tab to a visible deal-level spot — the List
         header and the top of the Timeline's deal-panels — so agents can find it without opening a tab.) --}}
</div>
