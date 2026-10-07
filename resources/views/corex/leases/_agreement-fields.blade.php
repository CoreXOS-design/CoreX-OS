{{--
    .ai/specs/leases.md §15.3 (Build L2) — the agreement details the agency's OWN linked lease needs, and only
    those: nothing here is asked unless that lease's field map carries it. Included by capture.blade.php inside
    the capture form (Alpine scope: leaseCaptureForm). One group per linked lease agreement; only the chosen
    group is enabled, so only its values are posted. In a renewal, a detail the previous term does not hold is
    marked "Not on record — fill in" (§15.6.3).
--}}
@php
    $monthNames = [1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April', 5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August', 9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'];
@endphp
@if($agreementCount > 1)
    <div>
        <label class="prop-label" for="lease-agreement-picker">Lease agreement</label>
        <select id="lease-agreement-picker" name="agreement_id" x-model="agreementId" class="prop-select">
            @foreach($agreements as $a)
                <option value="{{ $a['id'] }}" @selected((int) $selectedAgreementId === (int) $a['id'])>{{ $a['name'] }}</option>
            @endforeach
        </select>
    </div>
@elseif($agreementCount === 1)
    <input type="hidden" name="agreement_id" value="{{ $agreements[0]['id'] }}">
@endif

@foreach($agreements as $a)
    @if(count($a['fields']) > 0)
        <div class="grid grid-cols-2 gap-3" data-qa="agreement-fields-{{ $a['id'] }}"
             @if($agreementCount > 1) x-show="String(agreementId) === '{{ $a['id'] }}'" @if(!$loop->first) x-cloak @endif @endif>
            @foreach($a['fields'] as $f)
                @php
                    $key = $f['key'];
                    $name = 'agreement[' . $key . ']';
                    $notOnRecord = $isRenew && array_key_exists($key, $a['on_record']) && $a['on_record'][$key] === false;
                    $wide = $f['type'] === 'longtext';
                @endphp
                <div class="col-span-2 {{ $wide ? '' : 'sm:col-span-1' }}">
                    <label class="prop-label">{{ $f['label'] }}@if($f['required']) <span class="text-[10px]" style="color: var(--text-muted);">· needed for signing</span>@endif</label>
                    @switch($f['type'])
                        @case('longtext')
                            <textarea name="{{ $name }}" rows="3" maxlength="{{ $f['max'] }}" x-model="vals[{{ \Illuminate\Support\Js::from($key) }}]"
                                      @if($agreementCount > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif class="prop-input"></textarea>
                            @break
                        @case('month')
                            <select name="{{ $name }}" x-model="vals[{{ \Illuminate\Support\Js::from($key) }}]"
                                    @if($agreementCount > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif class="prop-select">
                                <option value="">—</option>
                                @foreach($monthNames as $num => $monthName)
                                    <option value="{{ $num }}">{{ $monthName }}</option>
                                @endforeach
                            </select>
                            @break
                        @case('date')
                            <input type="date" name="{{ $name }}" x-model="vals[{{ \Illuminate\Support\Js::from($key) }}]"
                                   @if($agreementCount > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif class="prop-input" style="color-scheme: light dark;">
                            @break
                        @case('integer')
                            <input type="number" name="{{ $name }}" step="1" min="0" x-model="vals[{{ \Illuminate\Support\Js::from($key) }}]"
                                   @if($agreementCount > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif class="prop-input">
                            @break
                        @case('percent')
                            <input type="number" name="{{ $name }}" step="0.01" min="0" max="100" x-model="vals[{{ \Illuminate\Support\Js::from($key) }}]"
                                   @if($agreementCount > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif class="prop-input">
                            @break
                        @case('money')
                            <input type="number" name="{{ $name }}" step="0.01" min="0" x-model="vals[{{ \Illuminate\Support\Js::from($key) }}]"
                                   @if($agreementCount > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif class="prop-input">
                            @break
                        @default
                            <input type="text" name="{{ $name }}" maxlength="{{ $f['max'] }}" x-model="vals[{{ \Illuminate\Support\Js::from($key) }}]"
                                   @if($agreementCount > 1) x-bind:disabled="String(agreementId) !== '{{ $a['id'] }}'" @endif class="prop-input">
                    @endswitch
                    @if($notOnRecord)
                        <p class="text-xs mt-0.5" style="color: var(--text-muted);">Not on record — fill in</p>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
@endforeach
