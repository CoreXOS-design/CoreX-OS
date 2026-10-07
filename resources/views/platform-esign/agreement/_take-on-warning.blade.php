{{-- Owner warning: the take-on month on this Subscription Agreement has passed (spec §11.19). Needs $doc and $lapse (AgreementService::takeOnLapse). --}}
@if(!empty($lapse))
    <div class="rounded-md px-4 py-3 space-y-2" style="background: #fef2f2; border: 1px solid #fecaca; color: #991b1b;" role="alert">
        <div class="font-semibold">The start month on this agreement has passed</div>
        <div class="text-sm">The take-on month is {{ $lapse['label'] }}, so the first debit date ({{ $lapse['billing'] }}) can no longer be collected as written. The agency cannot sign it, and you cannot countersign it, until this is fixed.</div>
        @if($lapse['can_reset'])
            <form method="POST" action="{{ route('platform-esign.agreements.take-on', $doc->id) }}" class="flex flex-wrap items-center gap-2">
                @csrf
                <label class="text-sm" for="take-on-reset-{{ $doc->id }}">Set a new take-on month</label>
                <select id="take-on-reset-{{ $doc->id }}" name="take_on_month" class="ds-field" required>
                    @foreach(\App\Services\PlatformEsign\Agreement\AgreementTakeOn::options(12) as $o)
                        <option value="{{ $o['value'] }}">{{ $o['label'] }} — starts {{ $o['start'] }}, billing from {{ $o['billing'] }}</option>
                    @endforeach
                </select>
                <button type="submit" class="corex-btn-primary">Change take-on month</button>
            </form>
            <div class="text-xs">The agency’s link and everything it has entered are kept; only the dates change.</div>
        @else
            <div class="text-sm">The agency has already signed these dates, so they cannot be changed under it. Cancel this agreement from the document page and send a corrected one.</div>
        @endif
        @if($errors->has('take_on'))<div class="text-sm font-semibold">{{ $errors->first('take_on') }}</div>@endif
    </div>
@endif
