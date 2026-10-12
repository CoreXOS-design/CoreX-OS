@extends('layouts.corex')

@section('corex-content')
@php
    $input = 'rounded-md px-3 py-2 text-sm';
    $inputStyle = 'background: var(--surface-2); border: 1px solid var(--border); color: var(--text-primary);';
    $yn = ['1' => 'Yes', '0' => 'No'];
    $three = $proofModes;
    $bool = fn ($key) => (string) (int) filter_var(old($key, $values[$key] ?? false), FILTER_VALIDATE_BOOLEAN);
    $card = 'rounded-md px-5 pt-4 pb-2';
    $cardStyle = 'background: var(--surface); border: 1px solid var(--border);';
    $numbers = [
        ['pin_lock_attempts', 'Wrong PIN attempts before the PIN locks', 'attempts', 'After this many wrong PINs the deed locks until someone issues a new PIN.', "pin !== 'not_used'"],
        ['consent_validity_minutes', "How long an owner's confirmation lasts", 'minutes', 'After this long the owner has to confirm again before the representative can collect.', "reps === '1' && pin !== 'required' && rule !== 'refuse'"],
        ['signing_link_minutes', 'How long a signing link lasts', 'minutes', 'A signing link works once, and stops working after this long.', "signature !== 'not_used' && link === '1'"],
        ['reminder_days', 'Remind the owners every', 'days', 'An owner who has been told the deed is here gets a reminder email this many days after the last notice.', 'true'],
        ['max_reminders', 'Most reminders for one deed', 'reminders (0 = none)', 'After this many reminders no more are sent for that deed.', 'true'],
        ['contact_changed_block_days', "Extra check after an owner's email or phone changed", 'days (0 = no extra check)', 'If an owner\'s email or phone changed within this many days, only someone with the override permission can hand the deed over, and must give a reason.', 'true'],
    ];
@endphp
<div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 py-6 space-y-5">

    <div class="rounded-md px-6 py-5 corex-page-banner">
        <h1 class="text-xl font-bold text-white leading-tight">Title Deed settings</h1>
        <p class="text-sm mt-1" style="color: rgba(255,255,255,.85);">Each question below has its own answer. Nothing here is a tick you have to interpret.</p>
    </div>

    @if(session('success'))<div class="rounded-md px-4 py-3 text-sm font-medium" style="background: color-mix(in srgb, var(--ds-green) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-green) 30%, transparent); color: var(--text-primary);">{{ session('success') }}</div>@endif
    @if($errors->any())
    <div class="rounded-md px-4 py-3 text-sm" style="background: color-mix(in srgb, var(--ds-crimson) 10%, transparent); border:1px solid color-mix(in srgb, var(--ds-crimson) 30%, transparent); color: var(--text-primary);" role="alert">
        <div class="font-semibold mb-1">Nothing was saved - these answers do not work together:</div>
        <ul class="list-disc list-inside space-y-1">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
    @endif

    @if($noAgency)
        <div class="rounded-md p-4 text-sm" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-secondary);">Switch into an agency to change its settings.</div>
    @else
    <form method="POST" action="{{ route('corex.settings.title-deeds.update') }}" class="space-y-5" id="td-settings-form"
          x-data="{
              pin: @js((string) old('pin_mode', $values['pin_mode'])),
              signature: @js((string) old('signature_mode', $values['signature_mode'])),
              photo: @js((string) old('collector_id_photo_mode', $values['collector_id_photo_mode'])),
              reps: @js($bool('representatives_allowed')),
              rule: @js((string) old('rep_without_pin_mode', $values['rep_without_pin_mode'])),
              phone: @js($bool('phone_consent_allowed')),
              letter: @js((string) old('authority_letter_mode', $values['authority_letter_mode'])),
              screen: @js($bool('sign_on_screen_enabled')),
              link: @js($bool('sign_by_link_enabled')),
              notify: @js($bool('notify_listing_agent')),
          }">
        @csrf

        {{-- ═════ To collect a title deed ═════ --}}
        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-base font-bold pb-2" style="color: var(--text-primary);">To collect a title deed</h2>
            <p class="text-xs pb-3" style="color: var(--text-secondary);"><strong>Required</strong>: the deed cannot be handed over without it. <strong>Optional</strong>: it is offered and may be skipped; the record then says "not captured". <strong>Not used</strong>: staff never see it.</p>
            @include('title-deeds._setting-choice', ['key' => 'pin_mode', 'label' => 'Collection PIN from the owner', 'model' => 'pin', 'options' => $three,
                'help' => 'Required: owners are sent a PIN and the deed is only handed over against it (no ID-check route). Optional: the PIN is sent and may be used, or staff can check the owner\'s ID instead. Not used: no PIN is sent.'])
            @include('title-deeds._setting-choice', ['key' => 'signature_mode', 'label' => 'Signature of the person collecting', 'model' => 'signature', 'options' => $three,
                'help' => 'Required: no hand-over without a signature. Optional: staff may skip it. Not used: no signature box and no signing link.'])
            @include('title-deeds._setting-choice', ['key' => 'collector_id_photo_mode', 'label' => "Photo of the collector's ID", 'model' => 'photo', 'options' => $three,
                'help' => 'Required: staff must photograph the ID. Optional: staff may skip it. Not used: staff are never asked.'])
        </section>

        {{-- ═════ Someone collecting for the owner ═════ --}}
        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-base font-bold pb-2" style="color: var(--text-primary);">Someone collecting for the owner</h2>
            @include('title-deeds._setting-choice', ['key' => 'representatives_allowed', 'label' => 'May a representative collect?', 'model' => 'reps', 'options' => $yn,
                'help' => 'Yes: staff can hand the deed to someone the owner sent. No: only the owners themselves can collect.'])

            <div x-show="reps === '1' && pin === 'required'" x-cloak class="py-3 text-sm" style="border-top: 1px solid var(--border); color: var(--text-secondary);">
                The owner's PIN is Required, so a representative has to have the PIN too. The questions about a representative who arrives without it do not apply.
            </div>

            <div x-show="reps === '1' && pin !== 'required'" x-cloak class="py-4 flex flex-col md:flex-row md:items-start md:justify-between gap-3" style="border-top: 1px solid var(--border);" data-setting="rep_without_pin_mode">
                <div class="md:max-w-md">
                    <label for="s-rep_without_pin_mode" class="text-sm font-semibold" style="color: var(--text-primary);">A representative who arrives without the PIN</label>
                    <div class="text-xs mt-0.5" style="color: var(--text-secondary);">What staff may do when the person collecting for the owner cannot give the PIN.</div>
                    @error('rep_without_pin_mode')<div class="text-xs mt-1 font-medium" style="color: var(--ds-crimson);" role="alert">{{ $message }}</div>@enderror
                </div>
                <select id="s-rep_without_pin_mode" name="rep_without_pin_mode" x-model="rule" :disabled="!(reps === '1' && pin !== 'required')" class="{{ $input }} md:max-w-xs" style="{{ $inputStyle }}">
                    @foreach($repModes as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
                </select>
            </div>

            @include('title-deeds._setting-choice', ['key' => 'phone_consent_allowed', 'label' => "Accept the owner's consent by phone call", 'model' => 'phone', 'options' => $yn,
                'show' => "reps === '1' && pin !== 'required' && rule !== 'refuse'",
                'help' => 'Yes: staff can phone an owner on a stored number and record that they agreed to the representative collecting. No: only the confirmation link counts.'])
            @include('title-deeds._setting-choice', ['key' => 'authority_letter_mode', 'label' => 'Authority letter', 'model' => 'letter', 'options' => $three, 'show' => "reps === '1'",
                'help' => "Required: every representative must hand in the owner's signed letter. Optional: staff can upload one (and it can replace the owner's confirmation if the rule above allows). Not used: never asked for."])
        </section>

        {{-- ═════ Ways to sign ═════ --}}
        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-base font-bold pb-2" style="color: var(--text-primary);">Ways to sign</h2>
            <div x-show="signature === 'not_used'" x-cloak class="py-3 text-sm" style="border-top: 1px solid var(--border); color: var(--text-secondary);">The signature is Not used, so there is no way to sign to choose.</div>
            @include('title-deeds._setting-choice', ['key' => 'sign_on_screen_enabled', 'label' => 'Sign on our screen', 'model' => 'screen', 'options' => $yn, 'show' => "signature !== 'not_used'",
                'help' => 'Yes: the collector signs in a box on the staff member\'s screen. No: no signature box on our screen.'])
            @include('title-deeds._setting-choice', ['key' => 'sign_by_link_enabled', 'label' => 'Sign on their own phone from a link or QR', 'model' => 'link', 'options' => $yn, 'show' => "signature !== 'not_used'",
                'help' => 'Yes: staff can send the collector a one-time link (or show a QR code) and they sign on their own phone. No: no signing links.'])
            <p x-show="signature === 'required' && screen === '0' && link === '0'" x-cloak class="pb-3 text-sm font-medium" style="color: var(--ds-crimson);">The signature is Required, so at least one of these two must be Yes.</p>
        </section>

        {{-- ═════ Who is told ═════ --}}
        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-base font-bold pb-2" style="color: var(--text-primary);">Who is told</h2>
            @include('title-deeds._setting-choice', ['key' => 'notify_listing_agent', 'label' => "Tell the deal's listing agent when a deed is collected", 'model' => 'notify', 'options' => $yn,
                'help' => 'Yes: the listing agent of the deal the deed came from gets a notification when it is collected. No: only the responsible agent and the other agents on the deed are told.'])
        </section>

        {{-- ═════ Limits and reminders ═════ --}}
        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-base font-bold pb-2" style="color: var(--text-primary);">Limits and reminders</h2>
            @foreach($numbers as [$key, $label, $unit, $help, $show])
            <div x-show="{{ $show }}" x-cloak class="py-4 flex flex-col md:flex-row md:items-start md:justify-between gap-3" style="border-top: 1px solid var(--border);" data-setting="{{ $key }}">
                <div class="md:max-w-md">
                    <label for="s-{{ $key }}" class="text-sm font-semibold" style="color: var(--text-primary);">{{ $label }} ({{ $unit }})</label>
                    <div class="text-xs mt-0.5" style="color: var(--text-secondary);">{{ $help }}</div>
                    @error($key)<div class="text-xs mt-1 font-medium" style="color: var(--ds-crimson);" role="alert">{{ $message }}</div>@enderror
                </div>
                <input id="s-{{ $key }}" type="number" name="{{ $key }}" min="{{ $ranges[$key][0] }}" max="{{ $ranges[$key][1] }}" step="1" required
                       value="{{ old($key, $values[$key]) }}" :disabled="!({{ $show }})" class="w-28 {{ $input }}" style="{{ $inputStyle }}">
            </div>
            @endforeach
        </section>

        {{-- ═════ Reference numbers ═════ --}}
        <section class="{{ $card }}" style="{{ $cardStyle }}">
            <h2 class="text-base font-bold pb-2" style="color: var(--text-primary);">Reference numbers</h2>
            <div class="py-4 flex flex-col md:flex-row md:items-start md:justify-between gap-3" style="border-top: 1px solid var(--border);" data-setting="reference_prefix">
                <div class="md:max-w-md">
                    <label for="s-reference_prefix" class="text-sm font-semibold" style="color: var(--text-primary);">Reference prefix</label>
                    <div class="text-xs mt-0.5" style="color: var(--text-secondary);">Printed on every title deed record, e.g. TD-00042. Changing it only affects new deeds.</div>
                    <div class="text-xs mt-1" style="color: var(--text-muted);">The next deed logged will be {{ $nextReference }}.</div>
                    @error('reference_prefix')<div class="text-xs mt-1 font-medium" style="color: var(--ds-crimson);" role="alert">{{ $message }}</div>@enderror
                </div>
                <input id="s-reference_prefix" type="text" name="reference_prefix" maxlength="10" required value="{{ old('reference_prefix', $values['reference_prefix']) }}" class="w-32 {{ $input }} uppercase" style="{{ $inputStyle }}" autocomplete="off">
            </div>
        </section>

        {{-- ═════ Storage locations ═════ --}}
        <section class="{{ $card }} pb-4" style="{{ $cardStyle }}">
            <h2 class="text-base font-bold pb-2" style="color: var(--text-primary);">Storage locations</h2>
            <div class="flex flex-wrap items-center justify-between gap-3" style="border-top: 1px solid var(--border); padding-top: 1rem;">
                <div>
                    @if($locationCount > 0)
                        <div class="text-sm font-semibold" style="color: var(--text-primary);">{{ $locationCount }} active {{ $locationCount === 1 ? 'location' : 'locations' }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">Staff choose one of these when they log a title deed.</div>
                    @else
                        <div class="text-sm font-semibold" style="color: var(--ds-crimson);">No storage locations - deeds cannot be logged.</div>
                        <div class="text-xs mt-0.5" style="color: var(--text-secondary);">Add at least one place a deed can be kept (for example "Safe 2").</div>
                    @endif
                </div>
                <a href="{{ route('corex.settings.title-deeds.locations') }}" class="corex-btn-outline text-sm">{{ $locationCount > 0 ? 'Manage storage locations' : 'Add a storage location' }}</a>
            </div>
        </section>

        <div class="flex items-center gap-3">
            <button type="submit" class="corex-btn-primary">Save the settings</button>
            <span class="text-xs" style="color: var(--text-secondary);">Every change is recorded below.</span>
        </div>
    </form>

    {{-- ═════ History ═════ --}}
    <section class="rounded-md px-5 py-4" style="background: var(--surface); border: 1px solid var(--border);">
        <h2 class="text-base font-bold pb-2" style="color: var(--text-primary);">Who changed what</h2>
        @if($history->isEmpty())
            <p class="text-sm" style="color: var(--text-secondary);">No setting has been changed yet.</p>
        @else
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead><tr class="text-left text-xs" style="color: var(--text-secondary);"><th class="py-2 pr-3 font-medium">When</th><th class="py-2 pr-3 font-medium">Who</th><th class="py-2 pr-3 font-medium">Setting</th><th class="py-2 pr-3 font-medium">From</th><th class="py-2 font-medium">To</th></tr></thead>
                <tbody>
                @foreach($history as $h)
                    <tr style="border-top: 1px solid var(--border); color: var(--text-primary);">
                        <td class="py-2 pr-3 whitespace-nowrap">{{ $h->created_at?->format('d M Y H:i') }}</td>
                        <td class="py-2 pr-3">{{ $h->user?->name ?? 'System' }}{{ $h->source === 'wizard' ? ' (Setup Wizard)' : '' }}</td>
                        <td class="py-2 pr-3">{{ $h->label() }}</td>
                        <td class="py-2 pr-3">{{ $h->fromText() }}</td>
                        <td class="py-2 font-medium">{{ $h->toText() }}</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
        @endif
    </section>
    @endif
</div>
@endsection
