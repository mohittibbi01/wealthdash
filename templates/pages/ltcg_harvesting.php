<?php
/**
 * WealthDash — LTCG Harvesting Tool (t83)
 * File: templates/pages/ltcg_harvesting.php
 *
 * Tax-free ₹1,25,000 LTCG limit mein gains book karo.
 * March 31 se pehle sabse important tax planning tool.
 *
 * Logic:
 *  - Current FY mein already booked LTCG fetch karo (api/reports/fy_gains.php)
 *  - Remaining headroom = ₹1,25,000 - already_booked
 *  - All holdings se unrealized LTCG calculate karo (held >12 months, equity)
 *  - Suggest: which funds to partially redeem to use up headroom
 *  - Calculator: units → gain → tax impact
 *  - March 31 countdown
 *
 * Backend needed:
 *   api/reports/fy_gains.php?action=ltcg_booked_this_fy  → {booked: 45000}
 *   api/mutual_funds/mf_list.php?action=mf_list          → holdings with unrealized gains
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>LTCG Harvesting Tool — WealthDash</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#f0f2f8;--surface:#fff;--surface2:#f8f9fc;
  --border:#e2e6f0;--border2:#cdd3e8;
  --text:#141c2e;--muted:#5a6882;--muted2:#9aaac4;
  --accent:#4f46e5;--accent-bg:#eef2ff;--accent-border:#c7d2fe;
  --green:#0d9f57;--green-bg:#edfbf2;--green-border:#a3e6c4;
  --red:#dc2626;--red-bg:#fff1f2;--red-border:#fca5a5;
  --yellow:#b45309;--yellow-bg:#fffbeb;--yellow-border:#fcd34d;
  --orange:#c2410c;--orange-bg:#fff7ed;--orange-border:#fdba74;
  --purple:#7c3aed;--purple-bg:#f5f3ff;--purple-border:#c4b5fd;
  --shadow:0 1px 3px rgba(15,23,60,.07),0 1px 2px rgba(15,23,60,.04);
  --shadow-md:0 4px 12px rgba(15,23,60,.09),0 2px 4px rgba(15,23,60,.05);
  --radius:10px;--radius-lg:14px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;line-height:1.5}

.page{max-width:1100px;margin:0 auto;padding:20px 18px 80px}

/* ── Header ── */
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.page-title{font-size:18px;font-weight:800;letter-spacing:-.4px;display:flex;align-items:center;gap:8px}
.page-sub{font-size:12px;color:var(--muted);margin-top:3px}
.urgency-pill{font-size:11px;padding:3px 10px;border-radius:99px;font-weight:700;border:1px solid}
.urgency-red{background:var(--red-bg);color:var(--red);border-color:var(--red-border)}
.urgency-green{background:var(--green-bg);color:var(--green);border-color:var(--green-border)}

/* ── Hero Headroom Card ── */
.hero-card{
  background:linear-gradient(135deg,#f0fdf4,#edfbf2);
  border:2px solid var(--green-border);border-radius:var(--radius-lg);
  padding:20px 24px;margin-bottom:14px;display:grid;
  grid-template-columns:1fr auto;gap:20px;align-items:center;
}
.hero-left{}
.hero-title{font-size:13px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}
.hero-nums{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:12px}
.hnum{text-align:center}
.hnum-val{font-size:22px;font-weight:800;letter-spacing:-.5px;line-height:1}
.hnum-lbl{font-size:10px;color:var(--muted);font-weight:600;margin-top:3px;text-transform:uppercase}
.headroom-bar-wrap{background:rgba(0,0,0,.08);border-radius:99px;height:10px;overflow:hidden;position:relative}
.headroom-bar{height:100%;border-radius:99px;background:linear-gradient(90deg,#0d9f57,#10b981);transition:width .8s cubic-bezier(.4,0,.2,1)}
.headroom-bar-label{font-size:10px;color:var(--muted);margin-top:5px;display:flex;justify-content:space-between}
.hero-right{text-align:center}
.countdown-circle{
  width:96px;height:96px;border-radius:50%;
  background:conic-gradient(var(--red) var(--conic-deg,0deg), var(--border) 0deg);
  display:flex;align-items:center;justify-content:center;position:relative;
  box-shadow:0 4px 16px rgba(220,38,38,.20);
}
.countdown-inner{
  width:74px;height:74px;border-radius:50%;background:var(--surface);
  display:flex;flex-direction:column;align-items:center;justify-content:center;
}
.countdown-days{font-size:22px;font-weight:800;color:var(--red);line-height:1}
.countdown-lbl{font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase}

/* ── Info Banner ── */
.info-banner{
  background:var(--accent-bg);border:1px solid var(--accent-border);border-radius:var(--radius);
  padding:10px 14px;font-size:11px;color:var(--accent);line-height:1.6;margin-bottom:14px;display:flex;gap:10px;align-items:flex-start;
}
.info-ico{font-size:16px;flex-shrink:0;margin-top:1px}

/* ── Section ── */
.section{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-lg);overflow:hidden;margin-bottom:12px;box-shadow:var(--shadow)}
.section-hdr{display:flex;align-items:center;justify-content:space-between;padding:11px 16px;border-bottom:1.5px solid var(--border);background:var(--surface2)}
.section-ttl{font-size:13px;font-weight:800;display:flex;align-items:center;gap:7px}
.sec-badge{font-size:10px;padding:2px 8px;border-radius:99px;font-weight:700;border:1px solid}
.badge-green{background:var(--green-bg);color:var(--green);border-color:var(--green-border)}
.badge-red{background:var(--red-bg);color:var(--red);border-color:var(--red-border)}
.badge-yellow{background:var(--yellow-bg);color:var(--yellow);border-color:var(--yellow-border)}
.badge-purple{background:var(--purple-bg);color:var(--purple);border-color:var(--purple-border)}

/* ── Harvest Table ── */
.htable{width:100%;border-collapse:collapse;font-size:11px}
.htable th{padding:7px 12px;font-size:9px;font-weight:800;color:var(--muted);text-transform:uppercase;letter-spacing:.5px;border-bottom:1.5px solid var(--border);background:var(--surface2);text-align:left;white-space:nowrap}
.htable th.num{text-align:right}
.htable td{padding:9px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
.htable td.num{text-align:right;font-family:'JetBrains Mono',monospace;font-size:10px}
.htable tr:last-child td{border-bottom:none}
.htable tr:hover td{background:#f7f8ff}
.htable tr.selected td{background:#f0fdf4}

.fund-name{font-weight:700;font-size:11px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.fund-cat{font-size:9px;color:var(--muted2);margin-top:2px}
.hold-pill{font-size:9px;padding:2px 6px;border-radius:99px;font-weight:700;border:1px solid}
.hold-lt{background:var(--green-bg);color:var(--green);border-color:var(--green-border)}
.gain-pos{color:var(--green);font-weight:700}
.gain-neg{color:var(--red);font-weight:700}

/* Harvest amount slider cell */
.harvest-cell{min-width:180px}
.harvest-slider{width:100%;accent-color:var(--green);cursor:pointer;margin-bottom:3px}
.harvest-inputs{display:flex;gap:6px;align-items:center}
.harvest-inp{
  width:72px;padding:3px 6px;border:1.5px solid var(--border);border-radius:6px;
  font-size:10px;font-family:'JetBrains Mono',monospace;color:var(--text);
  background:var(--bg);outline:none;
}
.harvest-inp:focus{border-color:var(--green)}
.harvest-lbl{font-size:9px;color:var(--muted)}

/* Tax impact badge in table */
.tax-impact{font-size:10px;font-weight:700;color:var(--green)}
.tax-zero{color:var(--green-border)}

/* ── Add to Plan button ── */
.add-plan-btn{
  padding:4px 10px;border-radius:6px;border:1.5px solid var(--green-border);
  background:var(--green-bg);color:var(--green);font-size:10px;font-weight:700;
  cursor:pointer;transition:all .15s;font-family:inherit;white-space:nowrap;
}
.add-plan-btn:hover{background:#c6f2d9}
.add-plan-btn.added{background:var(--green);color:#fff;border-color:var(--green)}

/* ── Harvest Plan Panel ── */
.plan-panel{
  background:var(--surface);border:2px solid var(--green-border);border-radius:var(--radius-lg);
  padding:16px 18px;margin-bottom:14px;box-shadow:var(--shadow-md);
}
.plan-panel-hdr{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-wrap:wrap;gap:8px}
.plan-title{font-size:13px;font-weight:800;color:var(--green)}
.plan-items{display:flex;flex-direction:column;gap:6px;margin-bottom:14px;min-height:48px}
.plan-item{display:flex;align-items:center;gap:10px;padding:8px 12px;background:var(--green-bg);border-radius:8px;border:1px solid var(--green-border)}
.plan-item-name{font-size:11px;font-weight:700;flex:1}
.plan-item-gain{font-size:11px;font-weight:700;color:var(--green)}
.plan-item-del{background:none;border:none;cursor:pointer;color:var(--muted);font-size:14px;padding:0 4px;border-radius:4px}
.plan-item-del:hover{color:var(--red)}
.plan-empty{text-align:center;padding:16px;color:var(--muted2);font-size:11px;font-style:italic}
.plan-summary{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-bottom:14px}
.ps-card{background:var(--surface2);border:1px solid var(--border);border-radius:8px;padding:10px 12px;text-align:center}
.ps-num{font-size:16px;font-weight:800;line-height:1.1;letter-spacing:-.3px}
.ps-lbl{font-size:9px;color:var(--muted);font-weight:700;text-transform:uppercase;margin-top:3px}
.plan-actions{display:flex;gap:8px;flex-wrap:wrap}
.btn{padding:8px 16px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;border:1.5px solid;transition:all .15s;font-family:inherit}
.btn-green{background:var(--green);color:#fff;border-color:var(--green)}
.btn-green:hover{background:#0a8049}
.btn-outline{background:var(--surface);color:var(--muted);border-color:var(--border)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent)}
.btn-red{background:var(--red-bg);color:var(--red);border-color:var(--red-border)}
.btn-red:hover{background:#ffe4e4}

/* ── Calculator Section ── */
.calc-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;padding:16px}
.calc-left,.calc-right{display:flex;flex-direction:column;gap:10px}
.calc-group{}
.calc-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.4px;margin-bottom:5px}
.calc-input{
  width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;
  font-size:12px;font-family:'JetBrains Mono',monospace;color:var(--text);background:var(--bg);outline:none;transition:border-color .15s;
}
.calc-input:focus{border-color:var(--green)}
.calc-select{width:100%;padding:8px 10px;border:1.5px solid var(--border);border-radius:8px;font-size:12px;color:var(--text);background:var(--bg);outline:none;font-family:inherit}
.calc-select:focus{border-color:var(--green)}
.calc-result{background:var(--surface2);border:1.5px solid var(--border);border-radius:var(--radius);padding:14px;display:flex;flex-direction:column;gap:8px}
.calc-row{display:flex;justify-content:space-between;font-size:12px;padding:3px 0;border-bottom:1px dashed var(--border)}
.calc-row:last-child{border-bottom:none;font-weight:800;font-size:13px;padding-top:8px;margin-top:4px;border-top:2px solid var(--border)}
.calc-label2{color:var(--muted)}
.calc-val{font-family:'JetBrains Mono',monospace;font-weight:700}
.calc-exemption{background:var(--green-bg);border-radius:6px;padding:4px 10px;font-size:11px;display:flex;justify-content:space-between;align-items:center;border:1px solid var(--green-border)}

/* ── Tip Cards ── */
.tips-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:14px 16px}
.tip-card{background:var(--surface2);border:1px solid var(--border);border-radius:var(--radius);padding:12px 13px}
.tip-ico{font-size:20px;margin-bottom:6px}
.tip-title{font-size:11px;font-weight:800;margin-bottom:4px}
.tip-text{font-size:10px;color:var(--muted);line-height:1.6}

/* ── Responsive ── */
@media(max-width:700px){
  .hero-card{grid-template-columns:1fr}
  .hero-right{display:flex;justify-content:center}
  .calc-grid{grid-template-columns:1fr}
  .plan-summary{grid-template-columns:repeat(2,1fr)}
  .tips-grid{grid-template-columns:1fr}
}

/* ── Print ── */
@media print{
  .plan-actions,.add-plan-btn,.harvest-slider,.harvest-inp,.info-banner{display:none!important}
  .page{padding:10px}
}
</style>
</head>
<body>

<div class="page">

  <!-- ── PAGE HEADER ── -->
  <div class="page-hdr">
    <div>
      <div class="page-title">🌾 LTCG Harvesting Tool
        <span class="urgency-pill" id="urgencyPill">Loading...</span>
      </div>
      <div class="page-sub">Tax-free ₹1,25,000 LTCG limit mein gains book karo — FY 2024-25</div>
    </div>
    <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center">
      <button class="btn btn-outline" onclick="printPlan()">🖨️ Print Plan</button>
      <button class="btn btn-green" onclick="exportPlan()">📥 Export CSV</button>
    </div>
  </div>

  <!-- ── INFO BANNER ── -->
  <div class="info-banner">
    <span class="info-ico">💡</span>
    <div>
      <strong>Kya hai LTCG Harvesting?</strong> Equity MF mein held >12 months ki units pe LTCG ₹1,25,000/FY tak bilkul tax-free hai (Section 112A).
      Agar aapka unrealized LTCG ₹2L hai aur aapne kuch book nahi kiya, to ₹1.25L tax-free waste ho gaya.
      <strong>Strategy:</strong> March 31 se pehle ₹1.25L tak LTCG book karo → sell → 1 din baad repurchase karo → cost basis step-up ho jaata hai.
      Repeat every FY = compounding tax saving.
    </div>
  </div>

  <!-- ── HERO: HEADROOM CARD ── -->
  <div class="hero-card">
    <div class="hero-left">
      <div class="hero-title">📊 FY 2024-25 LTCG Headroom</div>
      <div class="hero-nums">
        <div class="hnum">
          <div class="hnum-val" style="color:var(--accent)" id="heroLimit">₹1,25,000</div>
          <div class="hnum-lbl">Annual Limit</div>
        </div>
        <div class="hnum">
          <div class="hnum-val" style="color:var(--orange)" id="heroBooked">₹0</div>
          <div class="hnum-lbl">Already Booked</div>
        </div>
        <div class="hnum">
          <div class="hnum-val" style="color:var(--green)" id="heroHeadroom">₹1,25,000</div>
          <div class="hnum-lbl">Remaining Headroom</div>
        </div>
        <div class="hnum">
          <div class="hnum-val" style="color:var(--red)" id="heroTaxSaved">₹0</div>
          <div class="hnum-lbl">Potential Tax Saved</div>
        </div>
      </div>
      <div class="headroom-bar-wrap">
        <div class="headroom-bar" id="headroomBar" style="width:0%"></div>
      </div>
      <div class="headroom-bar-label">
        <span id="headroomPct">0% used</span>
        <span id="headroomRemText">₹1,25,000 remaining</span>
      </div>
    </div>
    <div class="hero-right">
      <div class="countdown-circle" id="countdownCircle" style="--conic-deg:0deg">
        <div class="countdown-inner">
          <div class="countdown-days" id="countdownDays">—</div>
          <div class="countdown-lbl">Days left</div>
        </div>
      </div>
      <div style="font-size:10px;color:var(--muted);margin-top:6px;text-align:center">Until Mar 31, <span id="fyYear">2025</span></div>
    </div>
  </div>

  <!-- ── HARVEST PLAN (sticky summary) ── -->
  <div class="plan-panel" id="planPanel">
    <div class="plan-panel-hdr">
      <span class="plan-title">🌾 My Harvest Plan <span id="planCount" style="font-size:11px;font-weight:600;color:var(--muted)">(0 funds)</span></span>
      <div style="display:flex;gap:6px">
        <button class="btn btn-outline" onclick="clearPlan()" style="padding:5px 10px;font-size:10px">Clear All</button>
        <button class="btn btn-green" onclick="exportPlan()" style="padding:5px 12px;font-size:10px">📥 Export</button>
      </div>
    </div>
    <div class="plan-items" id="planItems">
      <div class="plan-empty" id="planEmpty">Neeche se funds add karo apne harvest plan mein ↓</div>
    </div>
    <div class="plan-summary">
      <div class="ps-card"><div class="ps-num" id="planTotalGain" style="color:var(--green)">₹0</div><div class="ps-lbl">Total LTCG to Book</div></div>
      <div class="ps-card"><div class="ps-num" id="planExempt"  style="color:var(--accent)">₹0</div><div class="ps-lbl">Under ₹1.25L Limit</div></div>
      <div class="ps-card"><div class="ps-num" id="planTaxable"style="color:var(--red)">₹0</div><div class="ps-lbl">Taxable LTCG</div></div>
      <div class="ps-card" style="border-color:var(--green-border);background:var(--green-bg)"><div class="ps-num" id="planTaxSaved" style="color:var(--green)">₹0</div><div class="ps-lbl">Tax Saved vs Full Redemption</div></div>
    </div>
    <div style="font-size:11px;color:var(--muted);margin-bottom:10px;padding:8px 10px;background:var(--bg);border-radius:7px;border:1px solid var(--border)">
      📌 <strong>After harvesting:</strong> Yeh units sell karo → 1 working day baad SAME fund mein repurchase karo.
      Cost basis step-up ho jaayega. No 30-day wash sale rule in India for MF.
    </div>
  </div>

  <!-- ── ELIGIBLE HOLDINGS TABLE ── -->
  <div class="section">
    <div class="section-hdr">
      <div class="section-ttl">
        📈 Eligible Holdings (LTCG — held >12 months)
        <span class="sec-badge badge-green" id="eligibleBadge">0 funds</span>
      </div>
      <div style="display:flex;gap:6px;align-items:center">
        <span style="font-size:10px;color:var(--muted)">Sort by:</span>
        <select class="calc-select" style="width:130px;padding:4px 8px;font-size:10px" onchange="sortHoldings(this.value)">
          <option value="gain_desc">Gain (High→Low)</option>
          <option value="gain_asc">Gain (Low→High)</option>
          <option value="hold_desc">Hold Period (Long)</option>
          <option value="name_asc">Fund Name A-Z</option>
        </select>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="htable">
        <thead>
          <tr>
            <th>Fund Name</th>
            <th>Hold Period</th>
            <th class="num">Invested ₹</th>
            <th class="num">Current ₹</th>
            <th class="num">Unrealized LTCG ₹</th>
            <th class="num">Gain %</th>
            <th>Harvest Amount</th>
            <th class="num">Tax Impact</th>
            <th>Action</th>
          </tr>
        </thead>
        <tbody id="holdingsBody">
          <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted);font-style:italic">Loading eligible holdings…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── ALREADY BOOKED LTCG THIS FY ── -->
  <div class="section">
    <div class="section-hdr">
      <div class="section-ttl">
        📋 Already Booked LTCG This FY
        <span class="sec-badge badge-yellow" id="bookedBadge">0 transactions</span>
      </div>
    </div>
    <div style="overflow-x:auto">
      <table class="htable">
        <thead>
          <tr>
            <th>Fund Name</th>
            <th>Sell Date</th>
            <th class="num">Units Sold</th>
            <th class="num">Sell NAV ₹</th>
            <th class="num">LTCG Booked ₹</th>
            <th>Tax (10%)</th>
          </tr>
        </thead>
        <tbody id="bookedBody">
          <tr><td colspan="6" style="text-align:center;padding:24px;color:var(--muted);font-style:italic">Loading booked transactions…</td></tr>
        </tbody>
      </table>
    </div>
  </div>

  <!-- ── CALCULATOR ── -->
  <div class="section">
    <div class="section-hdr">
      <div class="section-ttl">🧮 LTCG Calculator — Units → Tax Impact</div>
    </div>
    <div class="calc-grid">
      <div class="calc-left">
        <div class="calc-group">
          <div class="calc-label">Select Fund</div>
          <select class="calc-select" id="calcFund" onchange="calcUpdate()">
            <option value="">-- Choose a fund --</option>
          </select>
        </div>
        <div class="calc-group">
          <div class="calc-label">Units to Redeem</div>
          <input type="number" class="calc-input" id="calcUnits" placeholder="e.g. 50.000" oninput="calcUpdate()">
        </div>
        <div class="calc-group">
          <div class="calc-label">Current NAV (₹)</div>
          <input type="number" class="calc-input" id="calcNav" placeholder="Auto-fills from selection" oninput="calcUpdate()">
        </div>
        <div class="calc-group">
          <div class="calc-label">Average Cost NAV (₹)</div>
          <input type="number" class="calc-input" id="calcCostNav" placeholder="Auto-fills from selection" oninput="calcUpdate()">
        </div>
        <div class="calc-group">
          <div class="calc-label">Already Booked LTCG This FY (₹)</div>
          <input type="number" class="calc-input" id="calcAlreadyBooked" placeholder="Auto-fills" oninput="calcUpdate()">
        </div>
      </div>
      <div class="calc-right">
        <div class="calc-label">Result</div>
        <div class="calc-result" id="calcResult">
          <div style="text-align:center;padding:20px;color:var(--muted2);font-size:12px">← Fill in the details to see tax impact</div>
        </div>
        <div style="margin-top:10px">
          <button class="btn btn-green" onclick="addCalcToPlan()" style="width:100%">+ Add to Harvest Plan</button>
        </div>
      </div>
    </div>
  </div>

  <!-- ── TIPS ── -->
  <div class="section">
    <div class="section-hdr">
      <div class="section-ttl">💡 Smart Harvesting Tips</div>
    </div>
    <div class="tips-grid">
      <div class="tip-card">
        <div class="tip-ico">🔄</div>
        <div class="tip-title">Sell & Repurchase</div>
        <div class="tip-text">LTCG book karne ke baad 1 working day baad SAME fund mein same amount invest karo. Cost basis step-up ho jaata hai. India mein MF ke liye 30-day wash sale rule nahi hai.</div>
      </div>
      <div class="tip-card">
        <div class="tip-ico">📅</div>
        <div class="tip-title">Every FY Repeat Karo</div>
        <div class="tip-text">Yeh strategy har FY karo. ₹1.25L × 10% = ₹12,500 tax bachta hai. 20 saal mein ₹2.5L+ sirf harvesting se — compound karke zyada. Kyunki bachaya paisa bhi invest hota hai.</div>
      </div>
      <div class="tip-card">
        <div class="tip-ico">⚠️</div>
        <div class="tip-title">Market Timing Mat Karo</div>
        <div class="tip-text">Harvesting ke liye market timing ki zaroorat nahi. NAV aaj hai ya kal — gain book karo. Same fund rebuy karo. Net position wahi rehti hai — sirf tax basis improve hota hai.</div>
      </div>
      <div class="tip-card">
        <div class="tip-ico">🧾</div>
        <div class="tip-title">ITR mein Schedule 112A</div>
        <div class="tip-text">Harvested gains ITR mein Schedule 112A mein file karo. Agar ₹1.25L ke andar hai to tax zero. Phir bhi declare karna zaroori hai. WealthDash Capital Gains Summary se CSV download karo.</div>
      </div>
      <div class="tip-card">
        <div class="tip-ico">💰</div>
        <div class="tip-title">STCG bhi Dekho</div>
        <div class="tip-text">Agar STCG loss hai kisi fund mein, to pehle STCL book karo. STCL se STCG aur LTCG dono set-off ho sakte hain. Double benefit mil sakta hai.</div>
      </div>
      <div class="tip-card">
        <div class="tip-ico">🏦</div>
        <div class="tip-title">Direct Plan Switch Bhi</div>
        <div class="tip-text">Agar Regular plan mein ho, harvesting ke time Direct plan mein switch karo. Cost basis step-up + Regular → Direct switch — do kaam ek baar mein.</div>
      </div>
    </div>
  </div>

</div>

<script>
// ════════════════════════════════════════════
// DEMO DATA — replace with API calls
// ════════════════════════════════════════════
const DEMO = {
  booked_ltcg: 32500, // already booked LTCG this FY
  booked_txns: [
    { fund:'Axis Bluechip Fund - Direct', sell_date:'2024-06-12', units:45.22, sell_nav:52.80, ltcg:8200 },
    { fund:'Mirae Asset Large Cap - Direct', sell_date:'2024-09-03', units:28.10, sell_nav:95.40, ltcg:14300 },
    { fund:'HDFC Mid-Cap Opp - Direct', sell_date:'2024-11-20', units:18.00, sell_nav:112.50, ltcg:10000 },
  ],
  eligible: [
    { id:1, fund:'SBI Bluechip Fund - Direct Growth', cat:'Large Cap', hold_months:38, invested:85000, current:128500, avg_nav:28.30, current_nav:42.85, units:300.35, ltcg:43500, gain_pct:51.2 },
    { id:2, fund:'Parag Parikh Flexi Cap - Direct Growth', cat:'Flexi Cap', hold_months:30, invested:120000, current:168000, avg_nav:42.10, current_nav:58.95, units:284.95, ltcg:48000, gain_pct:40.0 },
    { id:3, fund:'Nippon India Small Cap - Direct', cat:'Small Cap', hold_months:24, invested:50000, current:82000, avg_nav:58.40, current_nav:95.80, units:856.16, ltcg:32000, gain_pct:64.0 },
    { id:4, fund:'HDFC Index Fund - Nifty 50 Plan', cat:'Index', hold_months:42, invested:200000, current:318000, avg_nav:132.50, current_nav:210.45, units:1509.43, ltcg:118000, gain_pct:59.0 },
    { id:5, fund:'Kotak Flexi Cap Fund - Direct', cat:'Flexi Cap', hold_months:18, invested:75000, current:94500, avg_nav:48.20, current_nav:60.70, units:1556.85, ltcg:19500, gain_pct:26.0 },
    { id:6, fund:'Canara Robeco Emerging Equities', cat:'Mid Cap', hold_months:15, invested:40000, current:47200, avg_nav:88.40, current_nav:104.25, units:452.49, ltcg:7200, gain_pct:18.0 },
  ]
};

const LIMIT = 125000;
let alreadyBooked = DEMO.booked_ltcg;
let headroom = LIMIT - alreadyBooked;
let harvestPlan = {}; // fundId → { fund, gain, units }
let holdings = [...DEMO.eligible];

// ─── Helpers ─────────────────────────────────────────
function fmtInr(v, short=true) {
  if (v === undefined || v === null) return '—';
  const abs = Math.abs(v);
  let s;
  if (short && abs >= 1e5) s = '₹' + (abs/1e5).toFixed(2) + 'L';
  else s = '₹' + Math.round(abs).toLocaleString('en-IN');
  return (v < 0 ? '-' : '') + s;
}
function fmtMonths(m) {
  const y = Math.floor(m/12), mo = m%12;
  return y > 0 ? y+'y '+(mo>0?mo+'m':'') : mo+'m';
}
function daysUntilMar31() {
  const now = new Date();
  const fyEnd = new Date(now.getFullYear() + (now.getMonth() >= 3 ? 1 : 0), 2, 31);
  return Math.max(0, Math.ceil((fyEnd - now) / 86400000));
}

// ─── Init ─────────────────────────────────────────────
function init() {
  updateHeadroom();
  renderCountdown();
  renderEligible();
  renderBooked();
  populateCalcFunds();
}

function updateHeadroom() {
  headroom = Math.max(LIMIT - alreadyBooked, 0);
  const pct = Math.min((alreadyBooked / LIMIT) * 100, 100);
  const taxSaved = Math.min(Math.max(LIMIT - alreadyBooked, 0), getTotalUnharvestedGain()) * 0.10;

  document.getElementById('heroBooked').textContent     = fmtInr(alreadyBooked);
  document.getElementById('heroHeadroom').textContent   = fmtInr(headroom);
  document.getElementById('heroTaxSaved').textContent   = fmtInr(taxSaved);
  document.getElementById('headroomBar').style.width    = pct + '%';
  document.getElementById('headroomPct').textContent    = pct.toFixed(0) + '% used';
  document.getElementById('headroomRemText').textContent= fmtInr(headroom) + ' remaining';
  document.getElementById('calcAlreadyBooked').value    = Math.round(alreadyBooked);
}

function getTotalUnharvestedGain() {
  return holdings.reduce((s, h) => s + h.ltcg, 0);
}

function renderCountdown() {
  const days = daysUntilMar31();
  const now = new Date();
  const fyYear = now.getMonth() >= 3 ? now.getFullYear() + 1 : now.getFullYear();
  document.getElementById('countdownDays').textContent = days;
  document.getElementById('fyYear').textContent = fyYear;

  // Conic gradient: full = 360deg, map 365 days → 0deg remaining → 360deg used
  const deg = Math.round((1 - days / 365) * 360);
  document.getElementById('countdownCircle').style.setProperty('--conic-deg', deg + 'deg');

  const pill = document.getElementById('urgencyPill');
  if (days <= 15) { pill.textContent = '🔴 ' + days + ' days left'; pill.className = 'urgency-pill urgency-red'; }
  else if (days <= 60) { pill.textContent = '🟡 ' + days + ' days left'; pill.className = 'urgency-pill urgency-red'; }
  else { pill.textContent = '🟢 ' + days + ' days left'; pill.className = 'urgency-pill urgency-green'; }
}

// ─── Eligible Table ──────────────────────────────────
function renderEligible() {
  document.getElementById('eligibleBadge').textContent = holdings.length + ' funds';
  const tbody = document.getElementById('holdingsBody');
  if (!holdings.length) {
    tbody.innerHTML = '<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--muted)">No LTCG-eligible holdings (held >12 months) found</td></tr>';
    return;
  }
  tbody.innerHTML = holdings.map(h => {
    const inPlan = !!harvestPlan[h.id];
    const harvestGain = harvestPlan[h.id] ? harvestPlan[h.id].gain : 0;
    const taxImpact = harvestGain > 0 ? (Math.max(alreadyBooked + harvestGain - LIMIT, 0) * 0.10) : 0;
    const taxNote = harvestGain > 0 && taxImpact === 0 ? '<span class="tax-zero">₹0 — tax-free ✅</span>' :
                    harvestGain > 0 ? '<span style="color:var(--red);font-weight:700">' + fmtInr(taxImpact) + '</span>' : '—';
    return `<tr class="${inPlan ? 'selected' : ''}" id="hrow_${h.id}">
      <td><div class="fund-name" title="${h.fund}">${h.fund}</div><div class="fund-cat">${h.cat}</div></td>
      <td><span class="hold-pill hold-lt">${fmtMonths(h.hold_months)}</span></td>
      <td class="num">${fmtInr(h.invested)}</td>
      <td class="num">${fmtInr(h.current)}</td>
      <td class="num gain-pos">${fmtInr(h.ltcg)}</td>
      <td class="num" style="color:var(--green)">${h.gain_pct.toFixed(1)}%</td>
      <td class="harvest-cell">
        <input type="range" class="harvest-slider" min="0" max="${h.ltcg}" value="${inPlan?harvestPlan[h.id].gain:0}"
          oninput="sliderChange(${h.id},this.value)">
        <div class="harvest-inputs">
          <input type="number" class="harvest-inp" id="inp_${h.id}" value="${inPlan?Math.round(harvestPlan[h.id].gain):0}"
            min="0" max="${h.ltcg}" oninput="inputChange(${h.id},this.value)" placeholder="₹ gain">
          <span class="harvest-lbl">of ${fmtInr(h.ltcg)}</span>
        </div>
      </td>
      <td class="num">${taxNote}</td>
      <td>
        <button class="add-plan-btn ${inPlan?'added':''}" id="addbtn_${h.id}" onclick="togglePlan(${h.id})">
          ${inPlan ? '✓ Added' : '+ Add'}
        </button>
      </td>
    </tr>`;
  }).join('');
}

function sliderChange(id, val) {
  document.getElementById('inp_'+id).value = Math.round(val);
  if (harvestPlan[id]) { harvestPlan[id].gain = parseFloat(val); updatePlan(); }
}
function inputChange(id, val) {
  const h = holdings.find(x => x.id === id);
  const v = Math.min(Math.max(parseFloat(val)||0, 0), h.ltcg);
  if (harvestPlan[id]) { harvestPlan[id].gain = v; updatePlan(); }
  // sync slider
  const slider = document.querySelector(`#hrow_${id} .harvest-slider`);
  if (slider) slider.value = v;
}

function togglePlan(id) {
  const h = holdings.find(x => x.id === id);
  if (!h) return;
  if (harvestPlan[id]) {
    delete harvestPlan[id];
  } else {
    // Auto-suggest: fill up remaining headroom
    const remaining = Math.max(LIMIT - alreadyBooked - getPlanTotal(), 0);
    const suggest = Math.min(h.ltcg, remaining);
    harvestPlan[id] = { fund: h.fund, gain: suggest, units: (suggest / (h.ltcg / h.units)) };
    // Update slider and input
    const slider = document.querySelector(`#hrow_${id} .harvest-slider`);
    const inp = document.getElementById('inp_'+id);
    if (slider) slider.value = suggest;
    if (inp) inp.value = Math.round(suggest);
  }
  renderEligible();
  updatePlan();
}

function getPlanTotal() {
  return Object.values(harvestPlan).reduce((s, p) => s + p.gain, 0);
}

function sortHoldings(by) {
  const sorts = {
    gain_desc: (a,b) => b.ltcg - a.ltcg,
    gain_asc:  (a,b) => a.ltcg - b.ltcg,
    hold_desc: (a,b) => b.hold_months - a.hold_months,
    name_asc:  (a,b) => a.fund.localeCompare(b.fund),
  };
  holdings.sort(sorts[by] || sorts.gain_desc);
  renderEligible();
}

// ─── Booked Table ────────────────────────────────────
function renderBooked() {
  const txns = DEMO.booked_txns;
  document.getElementById('bookedBadge').textContent = txns.length + ' transactions';
  document.getElementById('bookedBody').innerHTML = txns.length ? txns.map(t => `
    <tr>
      <td><div class="fund-name">${t.fund}</div></td>
      <td>${t.sell_date}</td>
      <td class="num">${t.units.toFixed(3)}</td>
      <td class="num">₹${t.sell_nav.toFixed(2)}</td>
      <td class="num gain-pos">${fmtInr(t.ltcg)}</td>
      <td class="num ${t.ltcg > LIMIT ? 'gain-neg' : 'gain-pos'}">${t.ltcg > LIMIT ? fmtInr(t.ltcg * 0.1) : '₹0 (exempt)'}</td>
    </tr>`) .join('') :
    '<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--muted)">No LTCG booked this FY</td></tr>';
}

// ─── Plan Panel ──────────────────────────────────────
function updatePlan() {
  const items = Object.entries(harvestPlan);
  const total = getPlanTotal();
  const totalWithBooked = alreadyBooked + total;
  const exempt = Math.min(Math.max(total, 0), Math.max(LIMIT - alreadyBooked, 0));
  const taxable = Math.max(totalWithBooked - LIMIT, 0);
  const taxSaved = exempt * 0.10;

  document.getElementById('planCount').textContent = '(' + items.length + ' funds)';
  document.getElementById('planTotalGain').textContent = fmtInr(total);
  document.getElementById('planExempt').textContent   = fmtInr(exempt);
  document.getElementById('planTaxable').textContent  = fmtInr(taxable);
  document.getElementById('planTaxSaved').textContent = fmtInr(taxSaved);

  const el = document.getElementById('planItems');
  const empty = document.getElementById('planEmpty');
  if (!items.length) {
    el.innerHTML = '';
    el.appendChild(document.getElementById('planEmpty') || (() => { const d=document.createElement('div'); d.className='plan-empty'; d.id='planEmpty'; d.textContent='Neeche se funds add karo apne harvest plan mein ↓'; return d; })());
    return;
  }
  if (empty) empty.remove();
  el.innerHTML = items.map(([id, p]) => `
    <div class="plan-item">
      <span class="plan-item-name">${p.fund}</span>
      <span class="plan-item-gain">${fmtInr(p.gain)}</span>
      <button class="plan-item-del" onclick="togglePlan(${id})" title="Remove">✕</button>
    </div>`).join('');
}

function clearPlan() {
  harvestPlan = {};
  renderEligible();
  updatePlan();
}

// ─── Calculator ──────────────────────────────────────
function populateCalcFunds() {
  const sel = document.getElementById('calcFund');
  sel.innerHTML = '<option value="">-- Choose a fund --</option>' +
    holdings.map(h => `<option value="${h.id}" data-nav="${h.current_nav}" data-cost="${h.avg_nav}" data-units="${h.units}">${h.fund}</option>`).join('');
}

document.getElementById('calcFund').addEventListener('change', function() {
  const opt = this.options[this.selectedIndex];
  if (!opt.value) return;
  document.getElementById('calcNav').value     = opt.dataset.nav;
  document.getElementById('calcCostNav').value = opt.dataset.cost;
  document.getElementById('calcUnits').value   = '';
  calcUpdate();
});

function calcUpdate() {
  const units    = parseFloat(document.getElementById('calcUnits').value) || 0;
  const sellNav  = parseFloat(document.getElementById('calcNav').value)   || 0;
  const costNav  = parseFloat(document.getElementById('calcCostNav').value)|| 0;
  const booked   = parseFloat(document.getElementById('calcAlreadyBooked').value) || 0;

  if (!units || !sellNav || !costNav) {
    document.getElementById('calcResult').innerHTML =
      '<div style="text-align:center;padding:20px;color:var(--muted2);font-size:12px">← Fill in units to see calculation</div>';
    return;
  }

  const saleValue   = units * sellNav;
  const costBasis   = units * costNav;
  const gain        = saleValue - costBasis;
  const totalLtcg   = booked + gain;
  const exempt      = Math.min(Math.max(gain, 0), Math.max(LIMIT - booked, 0));
  const taxableGain = Math.max(totalLtcg - LIMIT, 0);
  const taxDue      = taxableGain * 0.10;
  const taxSaved    = exempt * 0.10;

  document.getElementById('calcResult').innerHTML = `
    <div class="calc-row"><span class="calc-label2">Sale Value (${units.toFixed(3)} units × ₹${sellNav})</span><span class="calc-val">${fmtInr(saleValue,false)}</span></div>
    <div class="calc-row"><span class="calc-label2">(-) Cost Basis (× ₹${costNav})</span><span class="calc-val">${fmtInr(costBasis,false)}</span></div>
    <div class="calc-row"><span class="calc-label2">LTCG from this redemption</span><span class="calc-val ${gain>=0?'gain-pos':'gain-neg'}">${fmtInr(gain,false)}</span></div>
    <div class="calc-exemption"><span style="color:var(--green);font-weight:600">Section 112A exemption used</span><span style="color:var(--green);font-weight:700">${fmtInr(exempt,false)}</span></div>
    <div class="calc-row"><span class="calc-label2">Taxable LTCG (above limit)</span><span class="calc-val ${taxableGain>0?'gain-neg':''}">${fmtInr(taxableGain,false)}</span></div>
    <div class="calc-row"><span class="calc-label2">Tax @ 10%</span><span class="calc-val ${taxDue>0?'gain-neg':'gain-pos'}">${fmtInr(taxDue,false)}</span></div>
    <div class="calc-row"><span>Tax Saved by Harvesting</span><span class="calc-val gain-pos">₹${Math.round(taxSaved).toLocaleString('en-IN')}</span></div>
  `;
  // Store for add-to-plan
  window._calcResult = { gain, units, sellNav, costNav };
}

function addCalcToPlan() {
  const fundSel = document.getElementById('calcFund');
  if (!fundSel.value || !window._calcResult) { alert('Pehle fund aur units select karo'); return; }
  const id = parseInt(fundSel.value);
  harvestPlan[id] = {
    fund: fundSel.options[fundSel.selectedIndex].text,
    gain: window._calcResult.gain,
    units: window._calcResult.units
  };
  renderEligible();
  updatePlan();
}

// ─── Export ──────────────────────────────────────────
function exportPlan() {
  const items = Object.entries(harvestPlan);
  if (!items.length) { alert('Harvest plan mein koi fund nahi hai'); return; }
  const headers = ['Fund Name','LTCG to Book (₹)','Est. Units to Redeem','Tax Impact (₹)','Action'];
  const rows = items.map(([id, p]) => {
    const h = holdings.find(x => x.id == id);
    const taxable = Math.max(alreadyBooked + p.gain - LIMIT, 0) * 0.10;
    return [`"${p.fund}"`, Math.round(p.gain), p.units.toFixed(3), Math.round(taxable), 'Sell → wait 1 day → repurchase'];
  });
  const csv = [headers.join(','), ...rows.map(r=>r.join(','))].join('\n');
  const blob = new Blob([csv], {type:'text/csv'});
  const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
  a.download = 'LTCG_Harvest_Plan_FY2024-25.csv'; document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

function printPlan() { window.print(); }

// ─── Boot ─────────────────────────────────────────────
init();
</script>
</body>
</html>
