{{-- A date typed or picked as dd/mm/yyyy whatever the browser's language is (the native date box follows the browser: 11/02/2026 reads as November in some of them).
     It still posts ONE field, named $name, as YYYY-MM-DD (what the server already reads). A small calendar button opens the browser's own picker.
     Props: name; value (YYYY-MM-DD or null); id; min (YYYY-MM-DD); required; submit (true = send the surrounding form as soon as a whole, valid date is in). --}}
@props(['name', 'value' => null, 'id' => null, 'min' => null, 'required' => false, 'submit' => false])
@php
    $dmyIso = preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $value, $dmyM) ? $dmyM[0] : '';
    $dmyShown = $dmyIso !== '' ? substr($dmyIso, 8, 2) . '/' . substr($dmyIso, 5, 2) . '/' . substr($dmyIso, 0, 4) : '';
@endphp
<span {{ $attributes->merge(['class' => 'cx-dmy']) }} data-dmy-date x-data="dmyDate({ iso: @js($dmyIso), shown: @js($dmyShown), min: @js((string) $min), required: @js((bool) $required), submit: @js((bool) $submit) })">
    <input type="hidden" name="{{ $name }}" :value="iso" value="{{ $dmyIso }}">
    <input type="text" @if($id) id="{{ $id }}" @endif x-ref="txt" x-model="shown" inputmode="numeric" autocomplete="off" maxlength="10" placeholder="dd/mm/yyyy" @if($required) required @endif
           pattern="[0-9]{1,2}[/.\-][0-9]{1,2}[/.\-][0-9]{4}" title="Type the date as dd/mm/yyyy" class="cx-dmy-txt rounded-md px-3 py-2 text-xs" style="background: var(--surface); border: 1px solid var(--border); color: var(--text-primary);"
           @change="change()" @blur="commit()" @keydown.enter="commit()">
    <button type="button" class="cx-dmy-cal" aria-label="Pick the date from a calendar" @click="pick()" tabindex="-1">
        <svg width="14" height="14" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.4" aria-hidden="true"><rect x="2" y="3" width="12" height="11" rx="1.5"/><path d="M2 6.5h12M5.5 1.8v2.6M10.5 1.8v2.6"/></svg>
    </button>
    <input type="date" x-ref="nat" tabindex="-1" aria-hidden="true" class="cx-dmy-nat" @if($min) min="{{ $min }}" @endif @change="fromPicker($event.target.value)">
</span>
@once
<style>
    .cx-dmy { position: relative; display: inline-flex; align-items: center; }
    .cx-dmy-txt { width: 8.5rem; padding-right: 1.9rem !important; }
    .cx-dmy-cal { position: absolute; right: 6px; top: 50%; transform: translateY(-50%); display: inline-flex; padding: 2px; background: none; border: 0; cursor: pointer; color: var(--text-muted); }
    .cx-dmy-cal:hover { color: var(--text-primary); }
    .cx-dmy-nat { position: absolute; right: 0; bottom: 0; width: 1px; height: 1px; opacity: 0; pointer-events: none; }
</style>
<script>
function dmyDate(cfg) {
    return {
        iso: cfg.iso || '', shown: cfg.shown || '',
        parse(t) {
            var m = /^\s*(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\s*$/.exec(t || '');
            if (!m) { return null; }
            var d = +m[1], mo = +m[2], y = +m[3], dt = new Date(y, mo - 1, d);
            if (dt.getFullYear() !== y || dt.getMonth() !== mo - 1 || dt.getDate() !== d) { return null; }
            return y + '-' + String(mo).padStart(2, '0') + '-' + String(d).padStart(2, '0');
        },
        // a whole valid date sets the posted value (and tidies what is shown); anything else clears it and says so on the box
        commit() {
            var txt = this.$refs.txt, v = this.shown.trim() === '' ? '' : this.parse(this.shown);
            if (v === null || (v !== '' && cfg.min && v < cfg.min)) {
                this.iso = ''; txt.setCustomValidity(v === null ? 'Type the date as dd/mm/yyyy' : 'That date has passed'); return false;
            }
            txt.setCustomValidity('');
            this.iso = v;
            if (v !== '') { this.shown = v.slice(8) + '/' + v.slice(5, 7) + '/' + v.slice(0, 4); }
            return v !== '' || !cfg.required;
        },
        change() {
            if (this.commit() && cfg.submit && this.iso !== '') {
                var f = this.$root.closest('form');
                if (f) { this.$nextTick(function () { f.submit(); }); }
            }
        },
        pick() {
            var n = this.$refs.nat;
            n.value = this.iso || '';
            if (n.showPicker) { try { n.showPicker(); return; } catch (e) { /* fall through */ } }
            n.focus(); n.click();
        },
        fromPicker(v) {
            if (!v) { return; }
            this.shown = v.slice(8) + '/' + v.slice(5, 7) + '/' + v.slice(0, 4);
            this.change();
        },
    };
}
</script>
@endonce
