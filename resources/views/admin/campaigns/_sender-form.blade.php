{{-- AT-534 — add / edit a sending address (§3.0). Receives: $sender (null = new), $bag (its error bag), $defaultSmtpHost.
     The password is write-only: never echoed back; blank on edit keeps the saved one. --}}
@php
    $isNew = $sender === null;
    $val = fn ($f, $default = '') => $bag->any() ? old($f, $default) : ($isNew ? $default : $sender->{$f});
@endphp
<form method="POST" action="{{ $isNew ? route('admin.campaigns.senders.store') : route('admin.campaigns.senders.update', $sender->id) }}"
      class="grid grid-cols-2 md:grid-cols-6 gap-2 rounded-md p-3" style="background: var(--surface-2);" data-sender-form="{{ $isNew ? 'new' : $sender->id }}">
    @csrf
    @unless($isNew) @method('PUT') @endunless
    <div class="col-span-2"><label class="cmp-label">Email</label><input type="email" name="email" value="{{ $val('email') }}" required class="cmp-input">
        @if($bag->has('email'))<div class="cmp-err">{{ $bag->first('email') }}</div>@endif</div>
    <div class="col-span-2"><label class="cmp-label">Name people see</label><input name="from_name" value="{{ $val('from_name') }}" maxlength="150" required class="cmp-input">
        @if($bag->has('from_name'))<div class="cmp-err">{{ $bag->first('from_name') }}</div>@endif</div>
    <div class="col-span-2"><label class="cmp-label">Password{{ $isNew ? '' : ' (blank = keep)' }}</label><input type="password" name="password" autocomplete="new-password" @if($isNew) required @endif class="cmp-input">
        @if($bag->has('password'))<div class="cmp-err">{{ $bag->first('password') }}</div>@endif</div>
    <div class="col-span-2 md:col-span-6"><label class="cmp-label">Signature</label><textarea name="signature" rows="4" maxlength="2000" class="cmp-input">{{ $val('signature') }}</textarea>
        @if($bag->has('signature'))<div class="cmp-err">{{ $bag->first('signature') }}</div>@endif</div>
    <div class="col-span-2"><label class="cmp-label">Outgoing server</label><input name="smtp_host" value="{{ $val('smtp_host', $defaultSmtpHost) }}" required class="cmp-input">
        @if($bag->has('smtp_host'))<div class="cmp-err">{{ $bag->first('smtp_host') }}</div>@endif</div>
    <div><label class="cmp-label">Port</label><input type="number" name="smtp_port" min="1" max="65535" value="{{ $val('smtp_port', 587) }}" required class="cmp-input"></div>
    <div><label class="cmp-label">Security</label>
        <select name="smtp_encryption" class="cmp-input">@foreach(['tls' => 'STARTTLS', 'ssl' => 'SSL', 'none' => 'None'] as $v => $l)<option value="{{ $v }}" @selected($val('smtp_encryption', 'tls') === $v)>{{ $l }}</option>@endforeach</select></div>
    <div class="col-span-2"><label class="cmp-label">Username (blank = the email)</label><input name="username" value="{{ $isNew ? old('username') : ($bag->any() ? old('username') : $sender->username) }}" class="cmp-input"></div>
    <div class="col-span-2"><label class="cmp-label">Incoming server (blank = same)</label><input name="imap_host" value="{{ $val('imap_host') }}" class="cmp-input">
        @if($bag->has('imap_host'))<div class="cmp-err">{{ $bag->first('imap_host') }}</div>@endif</div>
    <div><label class="cmp-label">Port</label><input type="number" name="imap_port" min="1" max="65535" value="{{ $val('imap_port', 993) }}" required class="cmp-input"></div>
    <div><label class="cmp-label">Security</label>
        <select name="imap_encryption" class="cmp-input">@foreach(['ssl' => 'SSL', 'tls' => 'STARTTLS', 'none' => 'None'] as $v => $l)<option value="{{ $v }}" @selected($val('imap_encryption', 'ssl') === $v)>{{ $l }}</option>@endforeach</select></div>
    <div class="col-span-2 flex items-end justify-end gap-2">
        @if($isNew)<label class="inline-flex items-center gap-1 text-xs" style="color: var(--text-secondary);"><input type="checkbox" name="is_default" value="1"> Default</label>@endif
        <button class="corex-btn-primary text-xs">{{ $isNew ? 'Add' : 'Save' }}</button>
    </div>
</form>
