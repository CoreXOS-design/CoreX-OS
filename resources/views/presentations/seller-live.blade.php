<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
<title>Seller Live — {{ $presentation->property_address ?? 'Property' }}</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@700;800;900&display=swap" rel="stylesheet">
<style>
  :root {
    --bg-deep: #0a1628;
    --bg-card: rgba(15, 61, 76, 0.2);
    --card-border: rgba(26, 92, 110, 0.35);
    --teal-primary: #1a5c6e;
    --teal-light: #2a8a9e;
    --teal-glow: #0ef0d4;
    --teal-accent: #0ef0d4;
    --text-primary: #f0f7fa;
    --text-secondary: rgba(240, 247, 250, 0.5);
    --text-muted: rgba(240, 247, 250, 0.3);
    --green: #22c55e;
    --lime: #84cc16;
    --yellow: #eab308;
    --orange: #f97316;
    --red: #ef4444;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  body {
    background: var(--bg-deep);
    color: var(--text-primary);
    font-family: 'DM Sans', sans-serif;
    height: 100vh;
    overflow: hidden;
    position: relative;
  }
  body::before {
    content: '';
    position: fixed;
    top: -30%;
    left: 50%;
    transform: translateX(-50%);
    width: 120%;
    height: 60%;
    background: radial-gradient(ellipse at center, rgba(14, 240, 212, 0.06) 0%, transparent 70%);
    pointer-events: none;
    transition: background 1.5s ease;
  }
  body::after {
    content: '';
    position: fixed;
    bottom: -20%;
    left: 50%;
    transform: translateX(-50%);
    width: 100%;
    height: 50%;
    background: radial-gradient(ellipse at center, rgba(14, 240, 212, 0.03) 0%, transparent 60%);
    pointer-events: none;
  }
  .grid-overlay {
    position: fixed;
    inset: 0;
    background-image:
      linear-gradient(rgba(14, 240, 212, 0.02) 1px, transparent 1px),
      linear-gradient(90deg, rgba(14, 240, 212, 0.02) 1px, transparent 1px);
    background-size: 60px 60px;
    pointer-events: none;
    z-index: 0;
  }
  .container {
    position: relative;
    z-index: 1;
    height: 100vh;
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 16px 40px;
  }
  .header {
    width: 100%;
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 8px;
  }
  .property-badge { display: flex; align-items: center; gap: 12px; }
  .property-badge .dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--teal-accent);
    box-shadow: 0 0 12px var(--teal-accent);
    animation: pulse-dot 2s ease-in-out infinite;
  }
  @keyframes pulse-dot {
    0%, 100% { opacity: 1; box-shadow: 0 0 12px var(--teal-accent); }
    50% { opacity: 0.5; box-shadow: 0 0 4px var(--teal-accent); }
  }
  .property-address {
    font-size: 14px; font-weight: 500; letter-spacing: 2px;
    text-transform: uppercase; color: var(--text-secondary);
  }
  .back-btn {
    padding: 8px 20px; background: rgba(255,255,255,0.05);
    border: 1px solid rgba(255,255,255,0.1); border-radius: 8px;
    color: var(--text-secondary); font-family: 'DM Sans', sans-serif;
    font-size: 13px; cursor: pointer; transition: all 0.3s; text-decoration: none;
  }
  .back-btn:hover { background: rgba(255,255,255,0.1); color: var(--text-primary); }
  .brand-line {
    font-size: 11px; letter-spacing: 4px; text-transform: uppercase;
    color: var(--text-muted); text-align: center; margin-bottom: 10px;
  }
  .price-section { text-align: center; margin-bottom: 14px; }
  .price-label {
    font-size: 12px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--text-muted); margin-bottom: 4px;
  }
  .price-display {
    font-family: 'Playfair Display', serif; font-weight: 900;
    font-size: 64px; letter-spacing: -1px; transition: color 0.6s ease;
    position: relative; display: inline-block;
  }
  .price-display .currency {
    font-size: 36px; font-weight: 700; vertical-align: top;
    margin-right: 4px; opacity: 0.6; position: relative; top: 10px;
  }
  .price-glow {
    position: absolute; inset: -20px -40px; border-radius: 20px;
    filter: blur(40px); opacity: 0.15; transition: background 0.8s ease;
    pointer-events: none; z-index: -1;
  }
  .controls { display: flex; gap: 10px; justify-content: center; margin-bottom: 20px; }
  .adj-btn {
    padding: 12px 22px; border-radius: 12px; font-family: 'DM Sans', sans-serif;
    font-weight: 600; font-size: 15px; cursor: pointer; transition: all 0.25s ease;
    position: relative; overflow: hidden;
  }
  .adj-btn.decrease {
    background: rgba(239, 68, 68, 0.1); border: 1px solid rgba(239, 68, 68, 0.3); color: #fca5a5;
  }
  .adj-btn.decrease:hover {
    background: rgba(239, 68, 68, 0.2); border-color: rgba(239, 68, 68, 0.5);
    color: #fecaca; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(239, 68, 68, 0.15);
  }
  .adj-btn.increase {
    background: rgba(34, 197, 94, 0.1); border: 1px solid rgba(34, 197, 94, 0.3); color: #86efac;
  }
  .adj-btn.increase:hover {
    background: rgba(34, 197, 94, 0.2); border-color: rgba(34, 197, 94, 0.5);
    color: #bbf7d0; transform: translateY(-2px); box-shadow: 0 8px 24px rgba(34, 197, 94, 0.15);
  }
  .adj-btn:active { transform: translateY(0) scale(0.97); }
  .content-grid {
    display: grid; grid-template-columns: 1fr 1fr; gap: 20px;
    width: 100%; max-width: 960px; flex: 1; min-height: 0;
  }
  .prob-card {
    background: var(--bg-card); border: 1px solid var(--card-border);
    border-radius: 20px; padding: 24px; display: flex; flex-direction: column;
    align-items: center; justify-content: center; position: relative;
    overflow: hidden; backdrop-filter: blur(10px);
  }
  .prob-card::before {
    content: ''; position: absolute; top: 0; left: 50%; transform: translateX(-50%);
    width: 80%; height: 1px;
    background: linear-gradient(90deg, transparent, var(--teal-accent), transparent); opacity: 0.3;
  }
  .prob-label-small {
    font-size: 11px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--text-muted); margin-bottom: 10px;
  }
  .gauge-container { width: 180px; height: 110px; position: relative; margin-bottom: 12px; }
  .gauge-svg { width: 100%; height: 100%; }
  .gauge-bg { fill: none; stroke: rgba(255,255,255,0.05); stroke-width: 12; stroke-linecap: round; }
  .gauge-fill {
    fill: none; stroke-width: 12; stroke-linecap: round;
    transition: stroke-dashoffset 0.8s cubic-bezier(0.4, 0, 0.2, 1), stroke 0.6s ease;
    filter: drop-shadow(0 0 8px currentColor);
  }
  .gauge-glow {
    fill: none; stroke-width: 20; stroke-linecap: round; opacity: 0.15;
    transition: stroke-dashoffset 0.8s cubic-bezier(0.4, 0, 0.2, 1), stroke 0.6s ease;
  }
  .prob-text {
    font-family: 'Playfair Display', serif; font-weight: 800; font-size: 28px;
    letter-spacing: 2px; text-transform: uppercase; text-align: center;
    transition: color 0.6s ease; animation: prob-enter 0.5s ease;
  }
  @keyframes prob-enter {
    from { opacity: 0; transform: scale(0.9); }
    to { opacity: 1; transform: scale(1); }
  }
  .stats-row { display: flex; gap: 40px; margin-top: 16px; }
  .stat-item { text-align: center; }
  .stat-value {
    font-family: 'Playfair Display', serif; font-weight: 700;
    font-size: 28px; color: var(--text-primary); line-height: 1;
  }
  .stat-label {
    font-size: 10px; letter-spacing: 2px; text-transform: uppercase;
    color: var(--text-muted); margin-top: 6px;
  }
  .proceeds-card {
    background: var(--bg-card); border: 1px solid var(--card-border);
    border-radius: 20px; padding: 24px; display: flex; flex-direction: column;
    position: relative; overflow: hidden; backdrop-filter: blur(10px);
  }
  .proceeds-card::before {
    content: ''; position: absolute; top: 0; left: 50%; transform: translateX(-50%);
    width: 80%; height: 1px;
    background: linear-gradient(90deg, transparent, var(--teal-accent), transparent); opacity: 0.3;
  }
  .proceeds-title {
    font-size: 11px; letter-spacing: 3px; text-transform: uppercase;
    color: var(--text-muted); margin-bottom: 20px;
  }
  .bar-group {
    margin-bottom: 16px; flex: 1; display: flex; flex-direction: column;
    justify-content: center; gap: 16px;
  }
  .bar-item { position: relative; }
  .bar-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 8px; }
  .bar-label { font-size: 13px; font-weight: 500; color: var(--text-secondary); }
  .bar-label.active { color: var(--teal-accent); font-weight: 600; }
  .bar-value {
    font-family: 'Playfair Display', serif; font-weight: 700;
    font-size: 18px; transition: color 0.6s ease;
  }
  .bar-value.active { color: var(--teal-accent); }
  .bar-track {
    height: 8px; background: rgba(255,255,255,0.05);
    border-radius: 4px; overflow: hidden; position: relative;
  }
  .bar-fill {
    height: 100%; border-radius: 4px;
    transition: width 0.8s cubic-bezier(0.4, 0, 0.2, 1), background 0.6s ease;
    position: relative;
  }
  .bar-fill.active {
    background: linear-gradient(90deg, var(--teal-primary), var(--teal-accent));
    box-shadow: 0 0 16px rgba(14, 240, 212, 0.3);
  }
  .bar-fill.reference { background: rgba(255,255,255,0.15); }
  .capture-section {
    width: 100%; max-width: 960px; display: flex;
    justify-content: center; padding: 12px 0;
  }
  .capture-btn {
    padding: 14px 40px;
    background: linear-gradient(135deg, var(--teal-primary), var(--teal-light));
    border: 1px solid rgba(14, 240, 212, 0.3); border-radius: 14px;
    color: var(--text-primary); font-family: 'DM Sans', sans-serif;
    font-weight: 600; font-size: 15px; letter-spacing: 1px;
    cursor: pointer; transition: all 0.3s ease; position: relative; overflow: hidden;
  }
  .capture-btn::before {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(135deg, transparent, rgba(14, 240, 212, 0.2));
    opacity: 0; transition: opacity 0.3s;
  }
  .capture-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 12px 32px rgba(14, 240, 212, 0.2);
    border-color: var(--teal-accent);
  }
  .capture-btn:hover::before { opacity: 1; }
  .capture-btn:active { transform: translateY(0) scale(0.98); }
  .capture-btn:disabled { opacity: 0.5; cursor: not-allowed; transform: none; box-shadow: none; }
  .capture-btn.captured {
    background: rgba(34, 197, 94, 0.15);
    border-color: rgba(34, 197, 94, 0.4); color: #86efac;
  }
  @media (max-width: 768px) {
    .container { padding: 12px 16px; }
    .price-display { font-size: 42px; }
    .price-display .currency { font-size: 24px; top: 4px; }
    .content-grid { grid-template-columns: 1fr; gap: 12px; }
    .controls { gap: 4px; flex-wrap: wrap; }
    .adj-btn { padding: 8px 14px; font-size: 13px; }
    .gauge-container { width: 150px; height: 95px; }
    .stats-row { gap: 24px; }
    .stat-value { font-size: 22px; }
    .prob-text { font-size: 22px; }
  }
</style>
</head>
<body>
<div class="grid-overlay"></div>
<div class="container">
  <div class="header">
    <div class="property-badge">
      <div class="dot"></div>
      <span class="property-address">{{ $presentation->property_address ?? 'Property' }}</span>
    </div>
    <a class="back-btn" href="{{ route('presentations.show', $presentation) }}">&larr; Back</a>
  </div>
  <div class="brand-line">{{ config('app.name') }}</div>
  <div class="price-section">
    <div class="price-label">Asking Price</div>
    <div class="price-display" id="priceDisplay">
      <div class="price-glow" id="priceGlow"></div>
      <span class="currency">R</span><span id="priceValue"></span>
    </div>
  </div>
  <div class="controls">
    <button class="adj-btn decrease" onclick="adjustPrice(-100000)">&minus;100k</button>
    <button class="adj-btn decrease" onclick="adjustPrice(-50000)">&minus;50k</button>
    <button class="adj-btn decrease" onclick="adjustPrice(-10000)">&minus;10k</button>
    <button class="adj-btn increase" onclick="adjustPrice(10000)">+10k</button>
    <button class="adj-btn increase" onclick="adjustPrice(50000)">+50k</button>
    <button class="adj-btn increase" onclick="adjustPrice(100000)">+100k</button>
  </div>
  <div class="content-grid">
    <div class="prob-card">
      <div class="prob-label-small">Probability of Sale</div>
      <div class="gauge-container">
        <svg class="gauge-svg" viewBox="0 0 220 130">
          <path class="gauge-bg" d="M 20 120 A 90 90 0 0 1 200 120" />
          <path class="gauge-glow" id="gaugeGlow" d="M 20 120 A 90 90 0 0 1 200 120"
            stroke-dasharray="283" stroke-dashoffset="283" />
          <path class="gauge-fill" id="gaugeFill" d="M 20 120 A 90 90 0 0 1 200 120"
            stroke-dasharray="283" stroke-dashoffset="283" />
        </svg>
      </div>
      <div class="prob-text" id="probText"></div>
      <div class="stats-row">
        <div class="stat-item">
          <div class="stat-value" id="competingCount">0</div>
          <div class="stat-label">Competing</div>
        </div>
        <div class="stat-item">
          <div class="stat-value" id="estMonths">0</div>
          <div class="stat-label">Est. Months</div>
        </div>
      </div>
    </div>
    <div class="proceeds-card">
      <div class="proceeds-title">Net Proceeds Comparison</div>
      <div class="bar-group">
        <div class="bar-item">
          <div class="bar-header">
            <span class="bar-label active">At This Price</span>
            <span class="bar-value active" id="netCurrent"></span>
          </div>
          <div class="bar-track">
            <div class="bar-fill active" id="barCurrent" style="width: 0%"></div>
          </div>
        </div>
        <div class="bar-item">
          <div class="bar-header">
            <span class="bar-label">At CMA Middle</span>
            <span class="bar-value" id="netCma" style="color: var(--text-secondary)"></span>
          </div>
          <div class="bar-track">
            <div class="bar-fill reference" id="barCma" style="width: 0%"></div>
          </div>
        </div>
        <div class="bar-item">
          <div class="bar-header">
            <span class="bar-label">At Asking Price</span>
            <span class="bar-value" id="netAsking" style="color: var(--text-secondary)"></span>
          </div>
          <div class="bar-track">
            <div class="bar-fill reference" id="barAsking" style="width: 0%"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
  <div class="capture-section">
    <button class="capture-btn" id="captureBtn" onclick="capturePrice()">
      Capture &amp; Include in Presentation
    </button>
  </div>
</div>

<script>
  // Data from Laravel controller ($liveData)
  const DATA = @json($liveData);

  let currentPrice = DATA.askingPrice || 0;

  // Pre-computed reference values (fixed on page load)
  const cmaMiddleNet = DATA.cmaMiddle ? calcNet(DATA.cmaMiddle) : null;
  const askingNet    = DATA.askingPrice ? calcNet(DATA.askingPrice) : null;

  // ── Competing count: listings with list_price <= price (matches PricingSimulatorService) ──
  function competingCount(price) {
    let count = 0;
    const prices = DATA.listingPrices || [];
    for (let i = 0; i < prices.length; i++) {
      if (prices[i] <= price) count++;
    }
    return count;
  }

  // ── Est months: (competing+1)/monthlySalesRate, capped at 12, rounded to 1dp ──
  function estMonthsCalc(competing) {
    const rate = DATA.monthlySalesRate || 1;
    if (rate <= 0) return 12;
    let months = (competing + 1) / rate;
    if (months > 12) months = 12;
    return Math.round(months * 10) / 10;
  }

  // ── Net proceeds: matches PricingSimulatorService exactly (Math.round on intermediates) ──
  function calcNet(price) {
    const competing  = competingCount(price);
    const months     = estMonthsCalc(competing);
    const commission = Math.round(price * (DATA.commissionPct || 0) / 100);
    const transfer   = Math.round(price * (DATA.transferCostPct || 0) / 100);
    const holding    = Math.round(months * (DATA.monthlyHoldingCost || 0));
    return price - commission - transfer - holding;
  }

  // ── Probability: same thresholds as PricingSimulatorService::probabilityLabel() ──
  function getProb(price) {
    const cl = DATA.cmaLower || 0, cm = DATA.cmaMiddle || 0, cu = DATA.cmaUpper || 0;
    if (cl && price <= cl) return { label: 'VERY LIKELY', color: 'var(--green)', pct: 95 };
    if (cm && price <= cm) return { label: 'LIKELY', color: 'var(--lime)', pct: 75 };
    if (cu && price <= cu) return { label: 'POSSIBLE', color: 'var(--yellow)', pct: 50 };
    if (cu && price <= cu * 1.10) return { label: 'UNLIKELY', color: 'var(--orange)', pct: 25 };
    return { label: 'VERY UNLIKELY', color: 'var(--red)', pct: 8 };
  }

  // ── ZAR formatting: R 1 850 000 ──
  function formatR(n) {
    if (n == null) return '\u2014';
    const neg = n < 0;
    const str = Math.abs(Math.round(n)).toLocaleString('en-ZA');
    return (neg ? '-R ' : 'R ') + str;
  }

  function adjustPrice(amount) {
    currentPrice = Math.max(0, currentPrice + amount);
    updateDisplay();
  }

  function updateDisplay() {
    const prob = getProb(currentPrice);
    const competing = competingCount(currentPrice);
    const months = estMonthsCalc(competing);
    const net = calcNet(currentPrice);

    // Price display
    document.getElementById('priceValue').textContent =
      Math.round(currentPrice).toLocaleString('en-ZA');
    document.getElementById('priceDisplay').style.color = prob.color;
    document.getElementById('priceGlow').style.background = prob.color;

    // Gauge arc
    const arcLen = 283;
    const offset = arcLen - (arcLen * prob.pct / 100);
    document.getElementById('gaugeFill').style.strokeDashoffset = offset;
    document.getElementById('gaugeFill').style.stroke = prob.color;
    document.getElementById('gaugeGlow').style.strokeDashoffset = offset;
    document.getElementById('gaugeGlow').style.stroke = prob.color;

    // Probability label
    const probEl = document.getElementById('probText');
    probEl.textContent = prob.label;
    probEl.style.color = prob.color;
    probEl.style.animation = 'none';
    probEl.offsetHeight; // force reflow
    probEl.style.animation = 'prob-enter 0.4s ease';

    // Stats
    document.getElementById('competingCount').textContent = competing;
    document.getElementById('estMonths').textContent = months.toFixed(1);

    // Net proceeds bars
    const vals = [net];
    if (cmaMiddleNet != null) vals.push(cmaMiddleNet);
    if (askingNet != null) vals.push(askingNet);
    const maxNet = Math.max(...vals.map(function(v) { return Math.max(v, 0); }), 1);

    document.getElementById('netCurrent').textContent = formatR(net);
    document.getElementById('barCurrent').style.width = Math.max(net / maxNet * 100, 0) + '%';

    if (cmaMiddleNet != null) {
      document.getElementById('netCma').textContent = formatR(cmaMiddleNet);
      document.getElementById('barCma').style.width = Math.max(cmaMiddleNet / maxNet * 100, 0) + '%';
    } else {
      document.getElementById('netCma').textContent = '\u2014';
      document.getElementById('barCma').style.width = '0%';
    }

    if (askingNet != null) {
      document.getElementById('netAsking').textContent = formatR(askingNet);
      document.getElementById('barAsking').style.width = Math.max(askingNet / maxNet * 100, 0) + '%';
    } else {
      document.getElementById('netAsking').textContent = '\u2014';
      document.getElementById('barAsking').style.width = '0%';
    }
  }

  function capturePrice() {
    const prob = getProb(currentPrice);
    const net = calcNet(currentPrice);
    const btn = document.getElementById('captureBtn');
    const token = document.querySelector('meta[name="csrf-token"]').content;

    btn.disabled = true;
    btn.textContent = 'Saving\u2026';

    fetch('{{ route("presentations.seller-live.capture", $presentation) }}', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': token, 'Accept': 'application/json' },
      body: JSON.stringify({ price: Math.round(currentPrice), probability: prob.label, net_proceeds: Math.round(net) })
    }).then(function(r) {
      if (r.ok) {
        btn.classList.add('captured');
        btn.textContent = '\u2713 Captured at ' + formatR(currentPrice);
      } else {
        btn.textContent = 'Error \u2014 try again';
        btn.disabled = false;
      }
    }).catch(function() {
      btn.textContent = 'Error \u2014 try again';
      btn.disabled = false;
    });
  }

  // Init
  updateDisplay();
</script>
</body>
</html>
