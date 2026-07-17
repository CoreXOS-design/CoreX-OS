<?php
// header_display: 'all_pages' (default), 'first_page', 'none'
// When 'first_page', only the first @include renders; subsequent calls output nothing.
static $_companyHeaderRenderCount = 0;
$_companyHeaderRenderCount++;
$_headerDisplay = $header_display ?? 'all_pages';

if ($_headerDisplay === 'none') {
    return;
}
if ($_headerDisplay === 'first_page' && $_companyHeaderRenderCount > 1) {
    return;
}

$headerAgency = null;
$headerBranch = null;

// Allow preview mode: pass $previewAgency to bypass DB lookups
if (isset($previewAgency)) {
    $headerAgency = $previewAgency;
} elseif (isset($branch)) {
    $headerBranch = $branch;
    $headerAgency = $branch->agency;
} elseif (Auth::check()) {
    $user = Auth::user();
    $branchId = $user->effectiveBranchId();
    if ($branchId) {
        $headerBranch = \App\Models\Branch::with('agency')->find($branchId);
        $headerAgency = $headerBranch?->agency;
    }
}
if (!$headerAgency) {
    $headerAgency = \App\Models\Agency::where('slug', 'hfc-coastal')->first();
}
$d = function(string $field) use ($headerBranch, $headerAgency): string {
    if ($headerBranch && !empty($headerBranch->{$field})) {
        return $headerBranch->{$field};
    }
    return $headerAgency?->{$field} ?? '';
};
$logoPath = null;
if ($headerBranch && $headerBranch->logo_path) {
    $logoPath = asset('storage/' . $headerBranch->logo_path);
} elseif ($headerAgency && $headerAgency->logo_path) {
    $logoPath = asset('storage/' . $headerAgency->logo_path);
}
if (isset($logo_url)) $logoPath = $logo_url;
?>
<div style="border:1px solid #000; padding:4px 8px 4px 8px; margin-bottom:10pt;">

    {{-- Line 1: Trading name — Times New Roman, 10pt, bold, underlined --}}
    <div style="font-family:'Times New Roman',Times,serif; font-size:10pt; font-weight:bold; text-decoration:underline; margin-bottom:3px;">{{ $d('trading_name') }}</div>

    {{-- Logo — centred --}}
    <div style="text-align:center; margin-bottom:2px; margin-top:0;">
        @if($logoPath)
            <img src="{{ $logoPath }}" style="width:100%; height:150px; display:block; object-fit:contain; object-position:center;">
        @else
            <div style="font-family:Arial,Helvetica,sans-serif; font-size:20pt; font-weight:bold;">{{ $d('name') }}</div>
            <div style="font-family:Arial,Helvetica,sans-serif; font-size:9pt; font-weight:bold; text-transform:uppercase; letter-spacing:2pt;">{{ $d('tagline') }}</div>
        @endif
    </div>

    {{-- Contact strip — Arial, 9pt, bold, two columns --}}
    <div class="company-header-contact-grid" style="display:grid; grid-template-columns:1fr 1fr; font-family:Arial,Helvetica,sans-serif; font-size:9pt; font-weight:bold; line-height:1.5; border-top:1px solid #000; padding-top:3px;">
        <div>
            {{ $d('address') }}<br>
            Reg no: &nbsp;&nbsp; {{ $d('reg_no') }}<br>
            Vat: {{ $d('vat_no') }}<br>
            @if($d('ncc_registration_number'))NCC: &nbsp;&nbsp; {{ $d('ncc_registration_number') }}<br>@endif
            Email Address: &nbsp;&nbsp; {{ $d('email') }}<br>
            {{ $d('phone_label') ?: 'Cell:' }} &nbsp;&nbsp; {{ $d('phone') }}
        </div>
        <div style="text-align:right;">
            Fax No: {{ $d('fax') }}<br>
            FFC: {{ $d('ffc_no') }}<br>
            <br>
            FIC {{ $d('fic_no') }}<br>
            @if($d('phone_secondary'))
                {{ $d('phone_secondary_label') ?: 'Cell:' }} {{ $d('phone_secondary') }}
            @endif
        </div>
    </div>

</div>
