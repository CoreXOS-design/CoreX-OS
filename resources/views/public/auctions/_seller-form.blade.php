{{--
    "Sell at auction" - the 7-step seller questionnaire (.ai/specs/auctions.md §22). Vars: $agency, $branding, $sellerResponse, $isPreview.
    Everything is the AGENCY'S: its brand colour (--brand), button colour (--button), the site font; "sand" is a tint of the brand colour.
--}}
@php
    $isPreview = $isPreview ?? false;
    $S = \App\Models\AuctionSellerSubmission::class;
    $pill = function (string $group, array $options, string $default = null) {
        return collect($options)->map(fn ($label, $value) => ['group' => $group, 'value' => $value, 'label' => $label, 'on' => $value === $default])->values()->all();
    };
    $featureOptions = ['pool' => 'Pool', 'borehole' => 'Borehole', 'solar' => 'Solar power', 'garden_flat' => 'Garden flat', 'electric_fence' => 'Electric fence', 'fibre' => 'Fibre ready', 'staff_quarters' => 'Staff quarters', 'double_garage' => 'Double garage'];
    $docOptions = ['title_deed' => 'Title deed copy', 'rates_account' => 'Latest rates account', 'building_plans' => 'Approved building plans', 'levy_statement' => 'Levy statement', 'lease_agreement' => 'Lease agreement', 'bond_statement' => 'Bond statement', 'id_copies' => 'ID copies'];
    $steps = ['About you', 'The property', 'Occupancy', 'Bond & finances', 'Auction options', 'Documents', 'Terms & consent'];
    $agencyName = $branding['name'];
@endphp

@push('styles')
        /* "Sell at auction" questionnaire - agency colours only */
        .sq { margin-top:56px; scroll-margin-top:16px; --sand:color-mix(in srgb, var(--brand) 6%, #fff); --sand-line:color-mix(in srgb, var(--brand) 15%, #fff); }
        .sq .eyebrow { color:var(--brand); font-size:.75rem; font-weight:800; letter-spacing:.16em; text-transform:uppercase; }
        .sq h2.sq-title { margin:6px 0 0; font-size:2rem; font-weight:800; letter-spacing:-.02em; }
        .sq .sq-intro { margin:10px 0 0; max-width:680px; color:var(--muted); font-weight:500; }
        .sq .sq-note { margin:6px 0 0; font-size:.85rem; font-weight:700; color:var(--brand); }
        .sq-grid { display:flex; flex-wrap:wrap; gap:28px; align-items:flex-start; margin-top:26px; }
        .sq-main { flex:999 1 520px; min-width:0; }
        .sq-aside { flex:1 1 300px; max-width:340px; width:100%; }
        @media (min-width:900px) { .sq-aside { position:sticky; top:20px; } }
        @media (max-width:899px) { .sq-aside { max-width:none; } }
        .sq-card { background:#fff; border:1px solid var(--sand-line); border-radius:20px; padding:28px; box-shadow:0 10px 30px rgba(12,28,48,.07); }
        .sq-head { display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; font-size:.9rem; font-weight:700; }
        .sq-head span:last-child { color:var(--muted); font-weight:600; }
        .sq-bar { margin-top:10px; height:6px; border-radius:99px; background:var(--sand-line); overflow:hidden; } .sq-bar i { display:block; height:100%; width:0; background:var(--button); border-radius:99px; transition:width .25s ease; }
        .sq-tabs { display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; }
        .sq-tabs button { display:inline-flex; align-items:center; gap:6px; border:0; background:var(--sand); color:var(--ink); border-radius:99px; padding:8px 14px; min-height:40px; font:inherit; font-size:.8rem; font-weight:700; cursor:pointer; }
        .sq-tabs button[aria-current="step"] { background:var(--brand); color:var(--on-brand); }
        .sq-tabs button:focus-visible, .sq-pills button:focus-visible, .sq-nav button:focus-visible { outline:3px solid var(--button); outline-offset:2px; }
        .sq [hidden] { display:none !important; } .sq-col { display:flex; flex-direction:column; gap:18px; }
        .sq-step { margin-top:22px; display:flex; flex-direction:column; gap:18px; } .sq-step[hidden] { display:none; }
        .sq-f label.sq-l, .sq-f .sq-l { display:block; font-size:.85rem; font-weight:700; margin-bottom:6px; }
        .sq-f input[type=text], .sq-f input[type=email], .sq-f input[type=tel], .sq-f input[type=number], .sq-f select, .sq-f textarea { width:100%; box-sizing:border-box; padding:11px 13px; border:1px solid #cbd2dc; border-radius:10px; background:#fff; color:var(--ink); font:inherit; font-size:.92rem; }
        .sq-f input:focus, .sq-f select:focus, .sq-f textarea:focus { outline:0; border-color:var(--button); box-shadow:0 0 0 3px color-mix(in srgb, var(--button) 28%, transparent); }
        .sq-f.bad input, .sq-f.bad select, .sq-f.bad textarea { border-color:#d92d20; }
        .sq-hint { margin:5px 0 0; font-size:.78rem; color:var(--muted); } .sq-err { margin:5px 0 0; font-size:.8rem; font-weight:600; color:#b42318; } .sq-err[hidden], .sq-hint[hidden] { display:none; }
        .sq-row { display:grid; grid-template-columns:repeat(auto-fit, minmax(200px, 1fr)); gap:14px; } .sq-row4 { display:grid; grid-template-columns:repeat(auto-fit, minmax(120px, 1fr)); gap:14px; }
        .sq-pills { display:flex; flex-wrap:wrap; gap:8px; }
        .sq-pills button { border:1px solid #cbd2dc; background:#fff; color:var(--ink); border-radius:99px; padding:9px 16px; min-height:42px; font:inherit; font-size:.88rem; font-weight:600; cursor:pointer; }
        .sq-pills button:hover { border-color:var(--brand); }
        .sq-pills button[aria-pressed="true"] { background:var(--brand); border-color:var(--brand); color:var(--on-brand); }
        .sq-docs { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:10px; }
        .sq-docs label { display:flex; align-items:center; gap:10px; border:1px solid #cbd2dc; border-radius:12px; padding:12px 14px; font-size:.9rem; font-weight:600; cursor:pointer; }
        .sq-docs label:has(input:checked) { border-color:var(--brand); background:var(--sand); }
        .sq-terms { background:var(--sand); border:1px solid var(--sand-line); border-radius:14px; padding:18px 20px 18px 38px; margin:0; font-size:.88rem; line-height:1.6; } .sq-terms li { margin:0 0 6px; }
        .sq-check { display:flex; gap:10px; align-items:flex-start; font-size:.9rem; font-weight:500; } .sq-check input { margin-top:4px; width:18px; height:18px; flex:0 0 auto; }
        .sq-nav { display:flex; justify-content:space-between; align-items:center; gap:12px; margin-top:26px; }
        .sq-nav .back { border:0; background:none; font:inherit; font-weight:700; color:var(--muted); cursor:pointer; padding:10px 6px; min-height:44px; } .sq-nav .back[hidden] { visibility:hidden; display:inline; }
        .sq-nav .go { border:0; border-radius:99px; background:var(--brand); color:var(--on-brand); font:inherit; font-weight:800; padding:13px 28px; min-height:48px; cursor:pointer; } .sq-nav .go[disabled] { opacity:.55; cursor:not-allowed; }
        .sq-msg { margin-top:12px; font-size:.88rem; font-weight:600; color:#b42318; } .sq-msg.ok { color:#166534; }
        .sq-prev { background:var(--brand); color:var(--on-brand); border-radius:20px; padding:26px; }
        .sq-prev .ey { font-size:.72rem; font-weight:800; letter-spacing:.16em; color:var(--button); }
        .sq-prev h3 { margin:10px 0 0; font-size:1.55rem; font-weight:800; letter-spacing:-.02em; line-height:1.15; color:inherit; }
        .sq-prev .ad { margin-top:6px; font-size:.9rem; opacity:.78; }
        .sq-prev .st { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:18px; } .sq-prev .st div { background:color-mix(in srgb, var(--on-brand) 10%, transparent); border-radius:12px; padding:10px 12px; }
        .sq-prev .st small { display:block; font-size:.7rem; opacity:.7; font-weight:700; letter-spacing:.06em; text-transform:uppercase; } .sq-prev .st b { font-size:.95rem; }
        .sq-prev .ft { display:flex; flex-wrap:wrap; gap:6px; margin-top:14px; min-height:6px; } .sq-prev .ft span { font-size:.75rem; font-weight:700; background:color-mix(in srgb, var(--on-brand) 14%, transparent); border-radius:99px; padding:5px 11px; }
        .sq-prev .pr { margin-top:18px; padding-top:16px; border-top:1px solid color-mix(in srgb, var(--on-brand) 22%, transparent); } .sq-prev .pr small { display:block; font-size:.78rem; opacity:.75; } .sq-prev .pr b { display:block; margin-top:2px; font-size:1.9rem; font-weight:800; color:var(--button); letter-spacing:-.02em; }
        .sq-done { text-align:center; padding:48px 28px; } .sq-done svg { width:72px; height:72px; color:#16a34a; } .sq-done h3 { margin:14px 0 6px; font-size:1.6rem; font-weight:800; } .sq-done p { margin:0; color:var(--muted); font-weight:500; } .sq-done a { display:inline-block; margin-top:18px; color:var(--brand); font-weight:700; text-decoration:underline; }
@endpush

<section class="sq" id="questionnaire" data-seller-form>
    <div class="eyebrow">Sell at auction</div>
    <h2 class="sq-title">Seller questionnaire</h2>
    <p class="sq-intro">Seven short sections. Your answers build a live catalogue preview as you go, and you can move between sections at any time.</p>
    <p class="sq-note">Takes about 8 minutes · No obligation to sell</p>

    <div class="sq-grid">
        <div class="sq-main">
            <div class="sq-card" id="sq-card">
                <form id="sq-form" novalidate autocomplete="on" @if($isPreview) data-preview-live @endif>
                    <div class="sq-head"><span id="sq-step-label">Step 1: About you</span><span id="sq-done-label">0 of 7 sections complete</span></div>
                    <div class="sq-bar" role="progressbar" aria-label="Sections complete" aria-valuemin="0" aria-valuemax="7" aria-valuenow="0" id="sq-bar"><i></i></div>
                    <div class="sq-tabs" id="sq-tabs">
                        @foreach($steps as $i => $name)<button type="button" data-tab="{{ $i + 1 }}" aria-label="Section {{ $i + 1 }}: {{ $name }}"><span class="n">{{ $i + 1 }}</span><span>{{ $name }}</span></button>@endforeach
                    </div>

                    <input type="text" name="website" value="" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-9999px;width:1px;height:1px;opacity:0;">

                    {{-- 1 --}}
                    <div class="sq-step" data-step="1">
                        <div class="sq-f" data-field="seller_type"><label class="sq-l" for="sq-seller_type">I am selling as</label>
                            <select id="sq-seller_type" data-bind="seller_type">@foreach($S::SELLER_TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="spouse_name" data-if="seller_type:married_in_community" hidden><label class="sq-l" for="sq-spouse_name">Spouse's full name</label>
                            <input type="text" id="sq-spouse_name" data-bind="spouse_name" maxlength="120"><p class="sq-hint">Both spouses must sign the auction mandate.</p><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="executor_name" data-if="seller_type:deceased_estate" hidden><label class="sq-l" for="sq-executor_name">Executor's name</label>
                            <input type="text" id="sq-executor_name" data-bind="executor_name" maxlength="120"><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="name"><label class="sq-l" for="sq-name">Full name</label><input type="text" id="sq-name" data-bind="name" maxlength="120" autocomplete="name"><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-row">
                            <div class="sq-f" data-field="email"><label class="sq-l" for="sq-email">Email</label><input type="email" id="sq-email" data-bind="email" maxlength="190" autocomplete="email"><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="phone"><label class="sq-l" for="sq-phone">Mobile number</label><input type="tel" id="sq-phone" data-bind="phone" maxlength="30" autocomplete="tel"><p class="sq-err" role="alert" hidden></p></div>
                        </div>
                        <div class="sq-f" data-field="preferred_contact"><span class="sq-l" id="sq-l-pc">Best way to reach you</span>
                            <div class="sq-pills" role="group" aria-labelledby="sq-l-pc">@foreach($pill('preferred_contact', $S::CONTACT_METHODS, 'phone') as $p)<button type="button" data-pill="{{ $p['group'] }}" data-value="{{ $p['value'] }}" aria-pressed="{{ $p['on'] ? 'true' : 'false' }}">{{ $p['label'] }}</button>@endforeach</div><p class="sq-err" role="alert" hidden></p></div>
                    </div>

                    {{-- 2 --}}
                    <div class="sq-step" data-step="2" hidden>
                        <div class="sq-f" data-field="property_type"><label class="sq-l" for="sq-property_type">Property type</label>
                            <select id="sq-property_type" data-bind="property_type">@foreach($S::PROPERTY_TYPES as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="address"><label class="sq-l" for="sq-address">Street address</label><input type="text" id="sq-address" data-bind="address" maxlength="255" autocomplete="street-address"><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-row">
                            <div class="sq-f" data-field="suburb"><label class="sq-l" for="sq-suburb">Suburb</label><input type="text" id="sq-suburb" data-bind="suburb" maxlength="120"><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="zoning"><label class="sq-l" for="sq-zoning">Zoning (if known)</label><input type="text" id="sq-zoning" data-bind="zoning" maxlength="60"><p class="sq-err" role="alert" hidden></p></div>
                        </div>
                        <div class="sq-row4">
                            <div class="sq-f" data-field="erf_size"><label class="sq-l" for="sq-erf_size">Erf size (m²)</label><input type="text" inputmode="numeric" id="sq-erf_size" data-bind="erf_size" data-num><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="floor_size"><label class="sq-l" for="sq-floor_size">Floor size (m²)</label><input type="text" inputmode="numeric" id="sq-floor_size" data-bind="floor_size" data-num><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="bedrooms"><label class="sq-l" for="sq-bedrooms">Bedrooms</label><input type="text" inputmode="numeric" id="sq-bedrooms" data-bind="bedrooms" data-num><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="bathrooms"><label class="sq-l" for="sq-bathrooms">Bathrooms</label><input type="text" inputmode="numeric" id="sq-bathrooms" data-bind="bathrooms" data-num><p class="sq-err" role="alert" hidden></p></div>
                        </div>
                        <div class="sq-f" data-field="features"><span class="sq-l" id="sq-l-ft">Notable features</span>
                            <div class="sq-pills" role="group" aria-labelledby="sq-l-ft">@foreach($featureOptions as $k => $l)<button type="button" data-multi="features" data-value="{{ $k }}" aria-pressed="false">{{ $l }}</button>@endforeach</div><p class="sq-err" role="alert" hidden></p></div>
                    </div>

                    {{-- 3 --}}
                    <div class="sq-step" data-step="3" hidden>
                        <div class="sq-f" data-field="occupancy"><span class="sq-l" id="sq-l-oc">Who lives in the property?</span>
                            <div class="sq-pills" role="group" aria-labelledby="sq-l-oc">@foreach($pill('occupancy', ['owner' => 'Owner occupied', 'tenant' => 'Tenanted', 'vacant' => 'Vacant'], 'owner') as $p)<button type="button" data-pill="{{ $p['group'] }}" data-value="{{ $p['value'] }}" aria-pressed="{{ $p['on'] ? 'true' : 'false' }}">{{ $p['label'] }}</button>@endforeach</div><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="monthly_rent" data-if="occupancy:tenant" hidden><label class="sq-l" for="sq-monthly_rent">Monthly rent (R)</label><input type="text" inputmode="numeric" id="sq-monthly_rent" data-bind="monthly_rent" data-num><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="condition"><label class="sq-l" for="sq-condition">Overall condition</label>
                            <select id="sq-condition" data-bind="condition"><option value="excellent">Excellent – move-in ready</option><option value="good" selected>Good – minor wear</option><option value="fair">Fair – some repairs needed</option><option value="needs_work">Needs significant work</option></select><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="defects"><label class="sq-l" for="sq-defects">Known defects</label><textarea id="sq-defects" rows="3" data-bind="defects" maxlength="2000"></textarea><p class="sq-hint">Disclosing defects up front protects you after the sale.</p><p class="sq-err" role="alert" hidden></p></div>
                    </div>

                    {{-- 4 --}}
                    <div class="sq-step" data-step="4" hidden>
                        <div class="sq-f" data-field="has_bond"><span class="sq-l" id="sq-l-hb">Is there a bond registered over the property?</span>
                            <div class="sq-pills" role="group" aria-labelledby="sq-l-hb"><button type="button" data-pill="has_bond" data-value="yes" aria-pressed="false">Yes</button><button type="button" data-pill="has_bond" data-value="no" aria-pressed="true">No</button></div><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-col" data-if="has_bond:true" hidden>
                            <div class="sq-f" data-field="bond_bank"><label class="sq-l" for="sq-bond_bank">Bank</label><input type="text" id="sq-bond_bank" data-bind="bond_bank" maxlength="120"><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="bond_amount"><label class="sq-l" for="sq-bond_amount">Approximate amount outstanding (R)</label><input type="text" inputmode="numeric" id="sq-bond_amount" data-bind="bond_amount" data-num><p class="sq-err" role="alert" hidden></p></div>
                            <label class="sq-check"><input type="checkbox" data-check="bond_arrears"> The bond is currently in arrears</label>
                        </div>
                        <div class="sq-row">
                            <div class="sq-f" data-field="levies"><label class="sq-l" for="sq-levies">Monthly levies (R)</label><input type="text" inputmode="numeric" id="sq-levies" data-bind="levies" data-num><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="municipal_valuation"><label class="sq-l" for="sq-municipal_valuation">Municipal valuation (R)</label><input type="text" inputmode="numeric" id="sq-municipal_valuation" data-bind="municipal_valuation" data-num><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="price_expectation"><label class="sq-l" for="sq-price_expectation">Price you hope to achieve (R)</label><input type="text" inputmode="numeric" id="sq-price_expectation" data-bind="price_expectation" data-num><p class="sq-err" role="alert" hidden></p></div>
                        </div>
                    </div>

                    {{-- 5 --}}
                    <div class="sq-step" data-step="5" hidden>
                        <div class="sq-f" data-field="auction_format"><span class="sq-l" id="sq-l-af">Preferred auction format</span>
                            <div class="sq-pills" role="group" aria-labelledby="sq-l-af">@foreach($pill('auction_format', $S::FORMATS, 'online') as $p)<button type="button" data-pill="{{ $p['group'] }}" data-value="{{ $p['value'] }}" aria-pressed="{{ $p['on'] ? 'true' : 'false' }}">{{ $p['label'] }}</button>@endforeach</div><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="reserve"><span class="sq-l" id="sq-l-rs">Would you like a confidential reserve price?</span>
                            <div class="sq-pills" role="group" aria-labelledby="sq-l-rs">@foreach($pill('reserve', ['confidential' => 'Yes, protect my minimum', 'none' => 'No reserve'], 'confidential') as $p)<button type="button" data-pill="{{ $p['group'] }}" data-value="{{ $p['value'] }}" aria-pressed="{{ $p['on'] ? 'true' : 'false' }}">{{ $p['label'] }}</button>@endforeach</div><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-row">
                            <div class="sq-f" data-field="timing"><label class="sq-l" for="sq-timing">When would you like to sell?</label>
                                <select id="sq-timing" data-bind="timing"><option value="asap">As soon as possible</option><option value="1_3_months" selected>Within 1–3 months</option><option value="3_6_months">Within 3–6 months</option><option value="exploring">Just exploring my options</option></select><p class="sq-err" role="alert" hidden></p></div>
                            <div class="sq-f" data-field="viewing"><label class="sq-l" for="sq-viewing">Viewing access</label>
                                <select id="sq-viewing" data-bind="viewing"><option value="most_days">Available most days</option><option value="appointment" selected>By appointment</option><option value="restricted">Restricted (tenant or security)</option></select><p class="sq-err" role="alert" hidden></p></div>
                        </div>
                        <div class="sq-f" data-field="sale_reason"><label class="sq-l" for="sq-sale_reason">Reason for selling (optional)</label><input type="text" id="sq-sale_reason" data-bind="sale_reason" maxlength="500"><p class="sq-err" role="alert" hidden></p></div>
                    </div>

                    {{-- 6 --}}
                    <div class="sq-step" data-step="6" hidden>
                        <div class="sq-f" data-field="documents"><span class="sq-l" id="sq-l-dc">Which documents do you already have?</span>
                            <div class="sq-docs" role="group" aria-labelledby="sq-l-dc">@foreach($docOptions as $k => $l)<label><input type="checkbox" data-multicheck="documents" value="{{ $k }}"> {{ $l }}</label>@endforeach</div>
                            <p class="sq-hint">Don't worry if you're missing some – we can help you obtain them.</p><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="referral_source"><label class="sq-l" for="sq-referral_source">How did you hear about us?</label><input type="text" id="sq-referral_source" data-bind="referral_source" maxlength="190"><p class="sq-err" role="alert" hidden></p></div>
                    </div>

                    {{-- 7 --}}
                    <div class="sq-step" data-step="7" hidden>
                        <ol class="sq-terms">
                            <li>Submitting this questionnaire does not commit you to selling. A sale only proceeds once you sign an auction mandate.</li>
                            <li>The valuation and marketing proposal we prepare for you are free of charge.</li>
                            <li>The information you provide is used to assess the property and will be verified before any listing goes live.</li>
                            <li>Your personal information is handled in line with the Protection of Personal Information Act (POPIA).</li>
                        </ol>
                        <div class="sq-f" data-field="consent_terms"><label class="sq-check"><input type="checkbox" data-check="consent_terms"> I have read and accept the terms above.</label><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="consent_authorised"><label class="sq-check"><input type="checkbox" data-check="consent_authorised"> I am the owner or am legally authorised to act on behalf of the owner.</label><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="consent_processing"><label class="sq-check"><input type="checkbox" data-check="consent_processing"> I consent to {{ $agencyName }} processing my information to prepare a proposal.</label><p class="sq-err" role="alert" hidden></p></div>
                        <div class="sq-f" data-field="marketing_opt_in"><label class="sq-check"><input type="checkbox" data-check="marketing_opt_in"> Send me occasional news about upcoming auctions (optional)</label></div>
                    </div>

                    <div class="sq-msg" id="sq-msg" role="alert" aria-live="polite"></div>
                    <div class="sq-nav">
                        <button type="button" class="back" id="sq-back" hidden>‹ Back</button>
                        <button type="button" class="go" id="sq-next">Continue ›</button>
                    </div>
                </form>
            </div>
            <div class="sq-card sq-done" id="sq-done" hidden data-seller-done>
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="10"/><path d="m8 12.5 2.8 2.8L16.5 9.5"/></svg>
                <h3>Questionnaire received</h3>
                <p>Thank you. An auctioneer will contact you {{ $sellerResponse }}.</p>
                <a href="#questionnaire" id="sq-again">Submit another property</a>
            </div>
        </div>

        <aside class="sq-aside" id="sq-aside" aria-label="Catalogue preview">
            <div class="sq-prev">
                <div class="ey">CATALOGUE PREVIEW</div>
                <h3 id="pv-title">House</h3>
                <div class="ad" id="pv-address">Your address will appear here</div>
                <div class="st">
                    <div><small>Erf size</small><b id="pv-erf">—</b></div><div><small>Floor size</small><b id="pv-floor">—</b></div>
                    <div><small>Format</small><b id="pv-format">Online</b></div><div><small>Reserve</small><b id="pv-reserve">Confidential</b></div>
                </div>
                <div class="ft" id="pv-features"></div>
                <div class="pr"><small>Your price expectation</small><b id="pv-price">—</b></div>
            </div>
        </aside>
    </div>
</section>

<script>
(function () {
    // The seller questionnaire (.ai/specs/auctions.md §22). State lives in `st`; the page only ever reflects it.
    var endpoint = @json(route('api.v1.auctions.public.sell', $agency->slug)), csrf = @json(csrf_token()), preview = @json((bool) $isPreview);
    var stepNames = @json($steps), formatLabels = @json($S::FORMATS), typeLabels = @json($S::PROPERTY_TYPES);
    var $ = function (s, r) { return (r || document).querySelector(s); }, $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };
    var root = $('#sq-form'); if (!root) return;
    function fresh() { return { seller_type: 'individual', spouse_name: '', executor_name: '', name: '', email: '', phone: '', preferred_contact: 'phone', property_type: 'house', address: '', suburb: '', zoning: '', erf_size: '', floor_size: '', bedrooms: '', bathrooms: '', features: [], occupancy: 'owner', monthly_rent: '', condition: 'good', defects: '', has_bond: false, bond_bank: '', bond_amount: '', bond_arrears: false, levies: '', municipal_valuation: '', price_expectation: '', auction_format: 'online', reserve: 'confidential', timing: '1_3_months', viewing: 'appointment', sale_reason: '', documents: [], referral_source: '', consent_terms: false, consent_authorised: false, consent_processing: false, marketing_opt_in: false }; }
    var st = fresh(), step = 1, sending = false;
    var digits = function (v) { var d = String(v == null ? '' : v).replace(/[^0-9]/g, ''); return d === '' ? null : parseInt(d, 10); };
    var rand = function (n) { return n == null ? '—' : 'R ' + String(n).replace(/\B(?=(\d{3})+(?!\d))/g, ' '); };
    var filled = function (v) { return String(v == null ? '' : v).trim() !== ''; };

    // which sections are complete (the ticks and the progress bar)
    function complete(n) {
        switch (n) {
            case 1: return filled(st.name) && filled(st.email) && filled(st.phone);
            case 2: return filled(st.address) && filled(st.suburb);
            case 3: return !!st.occupancy && !!st.condition && (st.occupancy !== 'tenant' || filled(st.monthly_rent));
            case 4: return !st.has_bond || filled(st.bond_bank);
            case 5: return !!st.auction_format && !!st.timing;
            case 6: return st.documents.length > 0 || filled(st.referral_source);
            case 7: return st.consent_terms && st.consent_authorised && st.consent_processing;
        }
        return false;
    }
    function cond(expr) { var p = expr.split(':'), v = st[p[0]]; return String(v) === p[1]; }

    function render() {
        $$('[data-step]', root).forEach(function (s) { s.hidden = parseInt(s.getAttribute('data-step'), 10) !== step; });
        $$('[data-if]', root).forEach(function (el) { el.hidden = !cond(el.getAttribute('data-if')); });
        var done = 0;
        $$('#sq-tabs button').forEach(function (b) {
            var n = parseInt(b.getAttribute('data-tab'), 10), c = complete(n); if (c) done++;
            b.setAttribute('aria-current', n === step ? 'step' : 'false');
            $('.n', b).textContent = c ? '✓' : String(n);
        });
        $('#sq-step-label').textContent = 'Step ' + step + ': ' + stepNames[step - 1];
        $('#sq-done-label').textContent = done + ' of 7 sections complete';
        var bar = $('#sq-bar'); bar.setAttribute('aria-valuenow', String(done)); $('i', bar).style.width = (done / 7 * 100) + '%';
        $('#sq-back').hidden = step === 1;
        var nx = $('#sq-next'); nx.textContent = sending ? 'Submitting…' : (step === 7 ? 'Submit questionnaire' : 'Continue ›'); nx.disabled = sending;
        // pills reflect state
        $$('[data-pill]', root).forEach(function (b) { var g = b.getAttribute('data-pill'), v = b.getAttribute('data-value'); b.setAttribute('aria-pressed', String(g === 'has_bond' ? ((v === 'yes') === st.has_bond) : st[g] === v)); });
        $$('[data-multi]', root).forEach(function (b) { b.setAttribute('aria-pressed', String(st[b.getAttribute('data-multi')].indexOf(b.getAttribute('data-value')) > -1)); });
        $$('[data-multicheck]', root).forEach(function (c) { c.checked = st[c.getAttribute('data-multicheck')].indexOf(c.value) > -1; });
        $$('[data-check]', root).forEach(function (c) { c.checked = !!st[c.getAttribute('data-check')]; });
        // live catalogue preview
        var tl = (typeLabels[st.property_type] || 'House').toLowerCase();
        var title = (filled(st.bedrooms) ? digits(st.bedrooms) + '-bedroom ' : '') + tl + (filled(st.suburb) ? ' in ' + st.suburb.trim() : '');
        $('#pv-title').textContent = title.charAt(0).toUpperCase() + title.slice(1);
        $('#pv-address').textContent = filled(st.address) ? st.address.trim() : 'Your address will appear here';
        $('#pv-erf').textContent = digits(st.erf_size) != null ? digits(st.erf_size) + ' m²' : '—';
        $('#pv-floor').textContent = digits(st.floor_size) != null ? digits(st.floor_size) + ' m²' : '—';
        $('#pv-format').textContent = formatLabels[st.auction_format] || '—';
        $('#pv-reserve').textContent = st.reserve === 'confidential' ? 'Confidential' : 'None';
        $('#pv-price').textContent = rand(digits(st.price_expectation));
        var ft = $('#pv-features'); ft.textContent = '';
        st.features.forEach(function (k) { var b = $('[data-multi="features"][data-value="' + k + '"]', root); if (b) { var s = document.createElement('span'); s.textContent = b.textContent; ft.appendChild(s); } });
    }

    // input wiring
    root.addEventListener('input', function (e) {
        var t = e.target, key = t.getAttribute && t.getAttribute('data-bind');
        if (key) { st[key] = t.value; clearError(key); render(); }
    });
    root.addEventListener('change', function (e) {
        var t = e.target;
        if (t.hasAttribute('data-bind') && t.tagName === 'SELECT') { st[t.getAttribute('data-bind')] = t.value; render(); }
        else if (t.hasAttribute('data-check')) { st[t.getAttribute('data-check')] = t.checked; clearError(t.getAttribute('data-check')); render(); }
        else if (t.hasAttribute('data-multicheck')) { var k = t.getAttribute('data-multicheck'), a = st[k], i = a.indexOf(t.value); if (t.checked && i < 0) a.push(t.value); if (!t.checked && i > -1) a.splice(i, 1); render(); }
    });
    root.addEventListener('click', function (e) {
        var b = e.target.closest ? e.target.closest('button') : null; if (!b) return;
        if (b.hasAttribute('data-pill')) { var g = b.getAttribute('data-pill'), v = b.getAttribute('data-value'); st[g] = g === 'has_bond' ? v === 'yes' : v; clearError(g); render(); }
        else if (b.hasAttribute('data-multi')) { var k = b.getAttribute('data-multi'), v2 = b.getAttribute('data-value'), a = st[k], i = a.indexOf(v2); if (i > -1) a.splice(i, 1); else a.push(v2); render(); }
        else if (b.hasAttribute('data-tab')) { go(parseInt(b.getAttribute('data-tab'), 10)); }
    });
    function go(n) { step = Math.max(1, Math.min(7, n)); $('#sq-msg').textContent = ''; render(); var t = $('#sq-tabs'); if (t && t.scrollIntoView && window.scrollY > t.getBoundingClientRect().top + window.scrollY - 120) t.scrollIntoView({ block: 'start', behavior: 'smooth' }); }
    $('#sq-back').addEventListener('click', function () { go(step - 1); });
    $('#sq-next').addEventListener('click', function () { if (step < 7) go(step + 1); else submit(); });

    // errors
    function field(key) { return $('[data-field="' + key + '"]', root); }
    function clearError(key) { var f = field(key); if (!f) return; f.classList.remove('bad'); var e = $('.sq-err', f), h = $('.sq-hint', f); if (e) { e.hidden = true; e.textContent = ''; } if (h) h.hidden = false; }
    function showError(key, msg) { var f = field(key); if (!f) return null; f.classList.add('bad'); var e = $('.sq-err', f), h = $('.sq-hint', f); if (e) { e.textContent = msg; e.hidden = false; } if (h) h.hidden = true; var s = f.closest('[data-step]'); return s ? parseInt(s.getAttribute('data-step'), 10) : null; }
    function clearAllErrors() { $$('.sq-f.bad', root).forEach(function (f) { f.classList.remove('bad'); }); $$('.sq-err', root).forEach(function (e) { e.hidden = true; e.textContent = ''; }); $$('.sq-hint', root).forEach(function (h) { h.hidden = false; }); }

    function payload() {
        var o = {}, nums = ['erf_size', 'floor_size', 'bedrooms', 'bathrooms', 'monthly_rent', 'bond_amount', 'levies', 'municipal_valuation', 'price_expectation'];
        Object.keys(st).forEach(function (k) { o[k] = st[k]; });
        nums.forEach(function (k) { o[k] = digits(st[k]); });                     // "R 1,500,000" -> 1500000, empty -> null
        ['spouse_name', 'executor_name', 'zoning', 'bond_bank', 'defects', 'sale_reason', 'referral_source'].forEach(function (k) { if (!filled(o[k])) o[k] = null; });
        if (st.seller_type !== 'married_in_community') o.spouse_name = null;
        if (st.seller_type !== 'deceased_estate') o.executor_name = null;
        if (st.occupancy !== 'tenant') o.monthly_rent = null;
        if (!st.has_bond) { o.bond_bank = null; o.bond_amount = null; o.bond_arrears = false; }
        o.website = ($('[name="website"]', root) || {}).value || '';
        return o;
    }
    function submit() {
        if (sending) return;
        var msg = $('#sq-msg'); msg.className = 'sq-msg';
        if (preview) { msg.textContent = 'Submitting is switched off in the preview. Visitors can send the form on your live page.'; return; }
        clearAllErrors(); sending = true; msg.textContent = ''; render();
        fetch(endpoint, { method: 'POST', credentials: 'same-origin', headers: { 'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf }, body: JSON.stringify(payload()) })
            .then(function (r) { return r.json().catch(function () { return {}; }).then(function (j) { return { status: r.status, j: j }; }); })
            .then(function (res) {
                sending = false;
                if (res.status === 201) { st = fresh(); step = 1; $('#sq-card').hidden = true; $('#sq-aside').hidden = true; $('#sq-done').hidden = false; $$('input[type=text],input[type=email],input[type=tel],textarea', root).forEach(function (i) { if (i.name !== 'website') i.value = ''; }); $$('select', root).forEach(function (s) { s.selectedIndex = 0; var d = st[s.getAttribute('data-bind')]; if (d !== undefined) s.value = d; }); render(); var q = $('#questionnaire'); if (q && q.scrollIntoView) q.scrollIntoView({ behavior: 'smooth' }); return; }
                if (res.status === 422 && res.j && res.j.errors) {
                    var first = null;
                    Object.keys(res.j.errors).forEach(function (k) { var base = k.split('.')[0], s = showError(base, res.j.errors[k][0]); if (s !== null && (first === null || s < first)) first = s; });
                    if (first !== null) step = first;
                    msg.textContent = 'Please check the highlighted fields.'; render(); return;
                }
                if (res.status === 429) { msg.textContent = 'Too many attempts. Please wait a minute and try again.'; render(); return; }
                msg.textContent = (res.j && res.j.message) || 'Something went wrong. Please try again.'; render();
            })
            .catch(function () { sending = false; msg.textContent = 'We could not reach the server. Please check your connection and try again.'; render(); });
    }
    var again = $('#sq-again'); if (again) again.addEventListener('click', function (e) { e.preventDefault(); $('#sq-done').hidden = true; $('#sq-card').hidden = false; $('#sq-aside').hidden = false; $('#sq-msg').textContent = ''; render(); });
    render();
})();
</script>
