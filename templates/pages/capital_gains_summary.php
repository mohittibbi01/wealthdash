<?php
/**
 * WealthDash — Capital Gains Summary (t74)
 * FY-wise ITR-ready report: LTCG/STCG breakdown, Schedule 112A format
 *
 * File: templates/pages/capital_gains_summary.php
 * API:  api/reports/fy_gains.php (action=capital_gains_summary)
 *
 * Tax Rules (FY 2024-25 onwards):
 *   Equity MF LTCG (>12 months):  10% above ₹1,25,000 exempt (pre-budget: ₹1L)
 *   Equity MF STCG (<12 months):  20% flat
 *   Debt MF (post Apr 1 2023):    Slab rate (no LTCG/STCG distinction)
 *   Debt MF (pre Apr 1 2023):     LTCG 20% with indexation / STCG slab rate
 *   Grandfathering:               Cost = max(actual, Jan 31 2018 NAV) for pre-2018 equity
 */

// ---- Backend API endpoint (add to api/reports/fy_gains.php) ----------------
/*
case 'capital_gains_summary':
    $userId = $_SESSION['user_id'];
    $fy     = $_GET['fy'] ?? '2024-25';

    // Derive FY date range
    [$fyStart, $fyEnd] = fyDateRange($fy); // e.g. 2024-04-01, 2025-03-31

    // Pull all SELL transactions in the FY with purchase details (FIFO)
    $sql = "
        SELECT
            t.id,
            t.transaction_date AS sell_date,
            t.units AS units_sold,
            t.nav  AS sell_nav,
            t.amount AS sale_consideration,
            f.scheme_code, f.fund_name, f.isin, f.category, f.plan_type,
            ph.purchase_date, ph.purchase_nav, ph.purchase_units,
            nh31.nav AS nav_jan31_2018
        FROM mf_transactions t
        JOIN mf_holdings h ON h.id = t.holding_id
        JOIN funds f ON f.id = h.fund_id
        LEFT JOIN (
            -- FIFO matched purchases from mf_purchase_lots table
            SELECT * FROM mf_purchase_lots WHERE user_id = :uid
        ) ph ON ph.holding_id = h.id
        LEFT JOIN nav_history nh31 ON nh31.fund_id = f.id
            AND nh31.nav_date = '2018-01-31'
        WHERE t.transaction_type = 'SELL'
          AND t.user_id = :uid
          AND t.transaction_date BETWEEN :fy_start AND :fy_end
        ORDER BY t.transaction_date ASC
    ";

    // Calculate gain per lot
    $rows = [];
    foreach ($stmt->fetchAll() as $r) {
        $holdMonths = monthsDiff($r['purchase_date'], $r['sell_date']);
        $isEquity   = in_array($r['category'], ['Equity','ELSS','Index','Hybrid Equity']);
        $isDebt     = !$isEquity;
        $isPostApr23 = strtotime($r['purchase_date']) >= strtotime('2023-04-01');

        // Grandfathering for equity purchased before Jan 31 2018
        $grandFathered = false;
        $costPerUnit   = $r['purchase_nav'];
        if ($isEquity && strtotime($r['purchase_date']) < strtotime('2018-02-01') && $r['nav_jan31_2018']) {
            if ($r['nav_jan31_2018'] > $r['purchase_nav']) {
                $costPerUnit  = $r['nav_jan31_2018'];
                $grandFathered = true;
            }
        }
        $totalCost  = $costPerUnit * $r['units_sold'];
        $gainAmount = $r['sale_consideration'] - $totalCost;

        $gainType = 'STCG';
        $taxRate  = 20;
        if ($isEquity && $holdMonths >= 12) { $gainType = 'LTCG'; $taxRate = 10; }
        if ($isDebt && !$isPostApr23 && $holdMonths >= 36) { $gainType = 'LTCG'; $taxRate = 20; }
        if ($isDebt && $isPostApr23) { $gainType = 'STCG_SLAB'; $taxRate = null; }

        $rows[] = [
            'fund_name'       => $r['fund_name'],
            'isin'            => $r['isin'],
            'category'        => $r['category'],
            'purchase_date'   => $r['purchase_date'],
            'sell_date'       => $r['sell_date'],
            'units'           => $r['units_sold'],
            'purchase_nav'    => $r['purchase_nav'],
            'sell_nav'        => $r['sell_nav'],
            'cost_per_unit'   => $costPerUnit,
            'total_cost'      => $totalCost,
            'sale_value'      => $r['sale_consideration'],
            'gain_amount'     => $gainAmount,
            'gain_type'       => $gainType,
            'tax_rate'        => $taxRate,
            'grand_fathered'  => $grandFathered,
            'hold_months'     => $holdMonths,
        ];
    }

    // Summary
    $ltcgTotal = array_sum(array_column(array_filter($rows, fn($r)=>$r['gain_type']==='LTCG'), 'gain_amount'));
    $stcgTotal = array_sum(array_column(array_filter($rows, fn($r)=>$r['gain_type']==='STCG'), 'gain_amount'));
    $ltcgExempt = min(max($ltcgTotal, 0), 125000);
    $ltcgTaxable = max($ltcgTotal - $ltcgExempt, 0);
    $ltcgTax  = $ltcgTaxable * 0.10;
    $stcgTax  = max($stcgTotal, 0) * 0.20;

    echo json_encode(['status'=>'ok','rows'=>$rows,'summary'=>compact('ltcgTotal','stcgTotal','ltcgExempt','ltcgTaxable','ltcgTax','stcgTax')]);
    break;
*/
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Capital Gains Summary — WealthDash</title>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;600&display=swap" rel="stylesheet">
<style>
/* ──────────────────────── ROOT VARS ──────────────────────── */
:root {
  --bg:#f0f2f8; --surface:#fff; --surface2:#f8f9fc;
  --border:#e2e6f0; --border2:#cdd3e8;
  --text:#141c2e; --muted:#5a6882; --muted2:#9aaac4;
  --accent:#4f46e5; --accent2:#3730a3; --accent-bg:#eef2ff; --accent-border:#c7d2fe;
  --green:#0d9f57; --green-bg:#edfbf2; --green-border:#a3e6c4;
  --red:#dc2626;   --red-bg:#fff1f2;   --red-border:#fca5a5;
  --yellow:#b45309;--yellow-bg:#fffbeb;--yellow-border:#fcd34d;
  --orange:#c2410c;--orange-bg:#fff7ed;--orange-border:#fdba74;
  --teal:#0f766e;  --teal-bg:#f0fdfa;  --teal-border:#5eead4;
  --purple:#7c3aed;--purple-bg:#f5f3ff;--purple-border:#c4b5fd;
  --shadow: 0 1px 3px rgba(15,23,60,.07),0 1px 2px rgba(15,23,60,.04);
  --shadow-md: 0 4px 12px rgba(15,23,60,.09),0 2px 4px rgba(15,23,60,.05);
  --radius:10px; --radius-lg:14px;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Plus Jakarta Sans',sans-serif;font-size:14px;line-height:1.5}

/* ─── PAGE LAYOUT ─── */
.page-wrap{max-width:1200px;margin:0 auto;padding:20px 18px 60px}
.page-hdr{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:16px}
.page-title{font-size:18px;font-weight:800;letter-spacing:-.4px}
.page-sub{font-size:12px;color:var(--muted);margin-top:2px}
.hdr-actions{display:flex;gap:7px;flex-wrap:wrap;align-items:center}

/* ─── FY SELECTOR ─── */
.fy-bar{display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;align-items:center}
.fy-label{font-size:11px;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.5px}
.fy-btn{padding:5px 13px;border-radius:99px;border:1.5px solid var(--border);
  font-size:11px;font-weight:700;background:var(--surface);color:var(--muted);cursor:pointer;transition:all .15s;font-family:inherit}
.fy-btn:hover{border-color:var(--accent);color:var(--accent)}
.fy-btn.active{background:var(--accent);color:#fff;border-color:var(--accent)}

/* ─── SUMMARY STRIP ─── */
.summary-strip{display:grid;grid-template-columns:repeat(6,1fr);gap:8px;margin-bottom:14px}
.sum-card{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-lg);
  padding:12px 14px;box-shadow:var(--shadow);text-align:center}
.sum-card.highlight-green{border-color:var(--green-border);background:var(--green-bg)}
.sum-card.highlight-red  {border-color:var(--red-border);  background:var(--red-bg)}
.sum-card.highlight-yel  {border-color:var(--yellow-border);background:var(--yellow-bg)}
.sum-num{font-size:18px;font-weight:800;line-height:1.1;letter-spacing:-.5px}
.sum-lbl{font-size:10px;color:var(--muted);font-weight:700;text-transform:uppercase;letter-spacing:.3px;margin-top:3px}
.sum-sub{font-size:9px;color:var(--muted2);margin-top:2px}

/* ─── TAX BANDS ─── */
.tax-info-bar{background:linear-gradient(135deg,var(--accent-bg),var(--purple-bg));
  border:1.5px solid var(--accent-border);border-radius:var(--radius-lg);
  padding:12px 16px;margin-bottom:14px;display:flex;gap:20px;flex-wrap:wrap;align-items:center}
.tib-item{display:flex;gap:6px;align-items:center;font-size:11px}
.tib-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}
.tib-lbl{font-weight:700;color:var(--text)}
.tib-rate{color:var(--muted)}

/* ─── SECTION BLOCK ─── */
.section{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-lg);
  box-shadow:var(--shadow);margin-bottom:12px;overflow:hidden}
.section-hdr{display:flex;align-items:center;justify-content:space-between;padding:11px 16px;
  border-bottom:1.5px solid var(--border);background:var(--surface2);cursor:pointer;user-select:none}
.section-hdr:hover{background:var(--bg)}
.section-ttl{font-size:13px;font-weight:800;display:flex;align-items:center;gap:7px}
.section-badge{font-size:10px;padding:2px 9px;border-radius:99px;font-weight:700;border:1px solid}
.ltcg-badge{background:var(--green-bg);color:var(--green);border-color:var(--green-border)}
.stcg-badge{background:var(--red-bg);color:var(--red);border-color:var(--red-border)}
.debt-badge{background:var(--yellow-bg);color:var(--yellow);border-color:var(--yellow-border)}
.section-meta{font-size:11px;color:var(--muted);display:flex;gap:12px;align-items:center}
.sec-chev{color:var(--muted2);font-size:10px;transition:transform .2s}
.section-body{overflow-x:auto}

/* ─── TABLE ─── */
.cg-table{width:100%;border-collapse:collapse;font-size:11px}
.cg-table th{padding:7px 12px;font-size:9px;font-weight:800;color:var(--muted);
  text-transform:uppercase;letter-spacing:.5px;border-bottom:1.5px solid var(--border);
  white-space:nowrap;text-align:left;background:var(--surface2)}
.cg-table th.num{text-align:right}
.cg-table td{padding:8px 12px;border-bottom:1px solid var(--border);vertical-align:middle}
.cg-table td.num{text-align:right;font-family:'JetBrains Mono',monospace;font-size:10px}
.cg-table tr:last-child td{border-bottom:none}
.cg-table tr:hover td{background:#f7f8ff}

.fund-cell{display:flex;flex-direction:column;gap:2px}
.fund-name{font-weight:700;color:var(--text);font-size:11px}
.fund-isin{font-size:9px;color:var(--muted2);font-family:'JetBrains Mono',monospace}

.gain-pos{color:var(--green);font-weight:700}
.gain-neg{color:var(--red);font-weight:700}
.grand-badge{font-size:8px;padding:1px 5px;border-radius:3px;
  background:var(--purple-bg);color:var(--purple);border:1px solid var(--purple-border);
  font-weight:700;margin-left:4px;vertical-align:middle}
.hold-pill{font-size:9px;padding:2px 6px;border-radius:99px;font-weight:700;border:1px solid;white-space:nowrap}
.hold-lt{background:var(--green-bg);color:var(--green);border-color:var(--green-border)}
.hold-st{background:var(--red-bg);color:var(--red);border-color:var(--red-border)}

/* ─── SUMMARY FOOTER ─── */
.table-footer{background:var(--surface2);border-top:2px solid var(--border);
  display:flex;gap:20px;padding:10px 16px;flex-wrap:wrap;justify-content:flex-end}
.tf-item{text-align:right}
.tf-lbl{font-size:9px;font-weight:700;color:var(--muted);text-transform:uppercase}
.tf-val{font-size:13px;font-weight:800;letter-spacing:-.3px}

/* ─── TAX ESTIMATE PANEL ─── */
.tax-panel{background:var(--surface);border:1.5px solid var(--border);border-radius:var(--radius-lg);
  box-shadow:var(--shadow);padding:16px 18px;margin-bottom:14px}
.tax-panel-title{font-size:13px;font-weight:800;margin-bottom:12px}
.tax-row{display:flex;justify-content:space-between;align-items:center;
  padding:7px 0;border-bottom:1px solid var(--border);font-size:12px}
.tax-row:last-child{border-bottom:none}
.tax-row.total-row{font-weight:800;font-size:13px;padding-top:10px;margin-top:4px;border-top:2px solid var(--border)}
.tax-lbl{color:var(--muted)}
.tax-val{font-family:'JetBrains Mono',monospace;font-weight:700}
.tax-val.pos{color:var(--green)}
.tax-val.neg{color:var(--red)}
.tax-val.neutral{color:var(--text)}
.exemption-row{background:var(--green-bg);border-radius:8px;padding:4px 10px;margin:4px 0}

/* ─── ITR DOWNLOAD SECTION ─── */
.itr-section{background:linear-gradient(135deg,#eff6ff,#f5f3ff);border:1.5px solid var(--accent-border);
  border-radius:var(--radius-lg);padding:14px 18px;display:flex;justify-content:space-between;
  align-items:center;flex-wrap:wrap;gap:12px;margin-bottom:14px}
.itr-info .itr-title{font-size:12px;font-weight:800;color:var(--accent);margin-bottom:2px}
.itr-info .itr-sub{font-size:11px;color:var(--muted);line-height:1.5}
.itr-btns{display:flex;gap:8px;flex-wrap:wrap}
.btn{padding:7px 15px;border-radius:8px;font-size:11px;font-weight:700;cursor:pointer;
  border:1.5px solid;transition:all .15s;font-family:inherit;white-space:nowrap}
.btn-accent{background:var(--accent);color:#fff;border-color:var(--accent)}
.btn-accent:hover{background:var(--accent2)}
.btn-green{background:var(--green-bg);color:var(--green);border-color:var(--green-border)}
.btn-green:hover{background:#c6f2d9}
.btn-outline{background:var(--surface);color:var(--muted);border-color:var(--border)}
.btn-outline:hover{border-color:var(--accent);color:var(--accent)}

/* ─── GRANDFATHERING NOTE ─── */
.gf-note{background:var(--purple-bg);border:1px solid var(--purple-border);border-radius:8px;
  padding:8px 12px;font-size:11px;color:var(--purple);margin:8px 12px;line-height:1.5}

/* ─── LOADING / EMPTY ─── */
.loading-row td{text-align:center;color:var(--muted);padding:24px;font-style:italic}
.empty-state{text-align:center;padding:32px 20px;color:var(--muted)}
.empty-ico{font-size:32px;margin-bottom:8px}

/* ─── RESPONSIVE ─── */
@media(max-width:800px){
  .summary-strip{grid-template-columns:repeat(3,1fr)}
  .itr-section{flex-direction:column}
}
@media(max-width:500px){
  .summary-strip{grid-template-columns:repeat(2,1fr)}
}
</style>
</head>
<body>

<div class="page-wrap">

  <!-- ── PAGE HEADER ── -->
  <div class="page-hdr">
    <div>
      <div class="page-title">📊 Capital Gains Summary</div>
      <div class="page-sub">FY-wise LTCG/STCG breakdown — ITR Schedule 112A ready</div>
    </div>
    <div class="hdr-actions">
      <button class="btn btn-outline" onclick="exportCsv112A()">📥 Schedule 112A CSV</button>
      <button class="btn btn-green"  onclick="exportFull()">📊 Full Report CSV</button>
      <button class="btn btn-accent" onclick="printReport()">🖨️ Print / PDF</button>
    </div>
  </div>

  <!-- ── FY SELECTOR ── -->
  <div class="fy-bar">
    <span class="fy-label">FY:</span>
    <button class="fy-btn" onclick="loadFY('2021-22',this)">2021-22</button>
    <button class="fy-btn" onclick="loadFY('2022-23',this)">2022-23</button>
    <button class="fy-btn" onclick="loadFY('2023-24',this)">2023-24</button>
    <button class="fy-btn active" id="fy2425" onclick="loadFY('2024-25',this)">2024-25</button>
    <button class="fy-btn" onclick="loadFY('2025-26',this)">2025-26</button>
    <span style="margin-left:8px;font-size:11px;color:var(--muted)" id="fyRange">Apr 2024 – Mar 2025</span>
  </div>

  <!-- ── TAX RATE INFO BAR ── -->
  <div class="tax-info-bar">
    <div class="tib-item"><div class="tib-dot" style="background:var(--green)"></div>
      <span class="tib-lbl">Equity LTCG</span><span class="tib-rate">10% (above ₹1,25,000 exempt) — held >12 months</span></div>
    <div class="tib-item"><div class="tib-dot" style="background:var(--red)"></div>
      <span class="tib-lbl">Equity STCG</span><span class="tib-rate">20% flat — held ≤12 months</span></div>
    <div class="tib-item"><div class="tib-dot" style="background:var(--yellow)"></div>
      <span class="tib-lbl">Debt STCG (post Apr'23)</span><span class="tib-rate">As per income tax slab</span></div>
    <div class="tib-item"><div class="tib-dot" style="background:var(--purple)"></div>
      <span class="tib-lbl">Grandfathering</span><span class="tib-rate">Cost = max(actual, Jan 31 2018 NAV)</span></div>
  </div>

  <!-- ── SUMMARY STRIP ── -->
  <div class="summary-strip">
    <div class="sum-card highlight-green">
      <div class="sum-num" id="sumLTCG" style="color:var(--green)">₹0</div>
      <div class="sum-lbl">Total LTCG</div>
      <div class="sum-sub">Equity, held >1yr</div>
    </div>
    <div class="sum-card highlight-red">
      <div class="sum-num" id="sumSTCG" style="color:var(--red)">₹0</div>
      <div class="sum-lbl">Total STCG</div>
      <div class="sum-sub">Equity, held ≤1yr</div>
    </div>
    <div class="sum-card">
      <div class="sum-num" id="sumDebt" style="color:var(--yellow)">₹0</div>
      <div class="sum-lbl">Debt Gains</div>
      <div class="sum-sub">Slab rate applicable</div>
    </div>
    <div class="sum-card">
      <div class="sum-num" id="sumExempt" style="color:var(--green)">₹1,25,000</div>
      <div class="sum-lbl">LTCG Exemption</div>
      <div class="sum-sub">Sec 112A limit</div>
    </div>
    <div class="sum-card highlight-red">
      <div class="sum-num" id="sumTax" style="color:var(--red)">₹0</div>
      <div class="sum-lbl">Est. Tax Liability</div>
      <div class="sum-sub">LTCG + STCG</div>
    </div>
    <div class="sum-card">
      <div class="sum-num" id="sumTxns" style="color:var(--accent)">0</div>
      <div class="sum-lbl">Total Redemptions</div>
      <div class="sum-sub">In selected FY</div>
    </div>
  </div>

  <!-- ── ITR DOWNLOAD SECTION ── -->
  <div class="itr-section">
    <div class="itr-info">
      <div class="itr-title">📋 ITR Schedule 112A — Ready to use</div>
      <div class="itr-sub">
        Download pre-formatted CSV for Schedule 112A (Equity MF LTCG).<br>
        Columns: ISIN · Fund Name · Sell Date · Units · Sale Value · Cost · FMV Jan 31 2018 · Taxable Gain
      </div>
    </div>
    <div class="itr-btns">
      <button class="btn btn-accent" onclick="exportCsv112A()">📥 Schedule 112A (LTCG)</button>
      <button class="btn btn-outline" onclick="exportScheduleCG()">📥 Schedule CG (STCG)</button>
      <button class="btn btn-green"  onclick="exportFull()">📊 Full Gains Report</button>
    </div>
  </div>

  <!-- ── TAX CALCULATION PANEL ── -->
  <div class="tax-panel">
    <div class="tax-panel-title">🧮 Tax Estimate Breakdown</div>
    <div class="tax-row">
      <span class="tax-lbl">Total LTCG (Equity)</span>
      <span class="tax-val neutral" id="tpLtcg">₹0</span>
    </div>
    <div class="tax-row exemption-row">
      <span class="tax-lbl">(-) Section 112A Exemption</span>
      <span class="tax-val pos" id="tpExempt">₹1,25,000</span>
    </div>
    <div class="tax-row">
      <span class="tax-lbl">Taxable LTCG</span>
      <span class="tax-val" id="tpTaxableLtcg">₹0</span>
    </div>
    <div class="tax-row">
      <span class="tax-lbl">LTCG Tax @ 10%</span>
      <span class="tax-val neg" id="tpLtcgTax">₹0</span>
    </div>
    <div class="tax-row">
      <span class="tax-lbl">Total STCG (Equity)</span>
      <span class="tax-val neutral" id="tpStcg">₹0</span>
    </div>
    <div class="tax-row">
      <span class="tax-lbl">STCG Tax @ 20%</span>
      <span class="tax-val neg" id="tpStcgTax">₹0</span>
    </div>
    <div class="tax-row">
      <span class="tax-lbl">Debt Gains (slab rate — enter slab separately)</span>
      <span class="tax-val neutral" id="tpDebt">₹0</span>
    </div>
    <div class="tax-row total-row">
      <span>Total Estimated Capital Gains Tax</span>
      <span class="tax-val neg" id="tpTotal">₹0</span>
    </div>
  </div>

  <!-- ── LTCG SECTION ── -->
  <div class="section">
    <div class="section-hdr" onclick="toggleSection('ltcgBody',this)">
      <div class="section-ttl">
        📈 Long-Term Capital Gains (LTCG)
        <span class="section-badge ltcg-badge" id="ltcgBadge">0 transactions</span>
      </div>
      <div class="section-meta">
        <span>Held > 12 months · 10% tax above ₹1,25,000</span>
        <span class="sec-chev">▾</span>
      </div>
    </div>
    <div id="ltcgBody">
      <div class="section-body">
        <table class="cg-table" id="ltcgTable">
          <thead>
            <tr>
              <th>Fund Name / ISIN</th>
              <th>Purchase Date</th>
              <th>Sell Date</th>
              <th>Hold Period</th>
              <th class="num">Units</th>
              <th class="num">Buy NAV</th>
              <th class="num">Sell NAV</th>
              <th class="num">Cost Basis ₹</th>
              <th class="num">Sale Value ₹</th>
              <th class="num">Gain / Loss ₹</th>
            </tr>
          </thead>
          <tbody id="ltcgRows">
            <tr class="loading-row"><td colspan="10">Loading LTCG transactions…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="table-footer">
        <div class="tf-item"><div class="tf-lbl">Total Cost</div><div class="tf-val" id="ltcgCost">₹0</div></div>
        <div class="tf-item"><div class="tf-lbl">Total Sale Value</div><div class="tf-val" id="ltcgSale">₹0</div></div>
        <div class="tf-item"><div class="tf-lbl">Total LTCG</div><div class="tf-val gain-pos" id="ltcgGain">₹0</div></div>
        <div class="tf-item"><div class="tf-lbl">After ₹1.25L Exemption</div><div class="tf-val" id="ltcgTaxable2">₹0</div></div>
        <div class="tf-item"><div class="tf-lbl">Est. LTCG Tax (10%)</div><div class="tf-val gain-neg" id="ltcgTax">₹0</div></div>
      </div>
    </div>
  </div>

  <!-- ── STCG SECTION ── -->
  <div class="section">
    <div class="section-hdr" onclick="toggleSection('stcgBody',this)">
      <div class="section-ttl">
        ⚡ Short-Term Capital Gains (STCG)
        <span class="section-badge stcg-badge" id="stcgBadge">0 transactions</span>
      </div>
      <div class="section-meta">
        <span>Held ≤ 12 months · 20% tax</span>
        <span class="sec-chev">▾</span>
      </div>
    </div>
    <div id="stcgBody">
      <div class="section-body">
        <table class="cg-table" id="stcgTable">
          <thead>
            <tr>
              <th>Fund Name / ISIN</th>
              <th>Purchase Date</th>
              <th>Sell Date</th>
              <th>Hold Period</th>
              <th class="num">Units</th>
              <th class="num">Buy NAV</th>
              <th class="num">Sell NAV</th>
              <th class="num">Cost Basis ₹</th>
              <th class="num">Sale Value ₹</th>
              <th class="num">Gain / Loss ₹</th>
            </tr>
          </thead>
          <tbody id="stcgRows">
            <tr class="loading-row"><td colspan="10">Loading STCG transactions…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="table-footer">
        <div class="tf-item"><div class="tf-lbl">Total Cost</div><div class="tf-val" id="stcgCost">₹0</div></div>
        <div class="tf-item"><div class="tf-lbl">Total Sale Value</div><div class="tf-val" id="stcgSale">₹0</div></div>
        <div class="tf-item"><div class="tf-lbl">Total STCG</div><div class="tf-val gain-neg" id="stcgGain">₹0</div></div>
        <div class="tf-item"><div class="tf-lbl">Est. STCG Tax (20%)</div><div class="tf-val gain-neg" id="stcgTax">₹0</div></div>
      </div>
    </div>
  </div>

  <!-- ── DEBT GAINS SECTION ── -->
  <div class="section">
    <div class="section-hdr" onclick="toggleSection('debtBody',this)">
      <div class="section-ttl">
        🏦 Debt MF Gains (Slab Rate)
        <span class="section-badge debt-badge" id="debtBadge">0 transactions</span>
      </div>
      <div class="section-meta">
        <span>Post Apr 2023 debt MF — taxed as per income slab</span>
        <span class="sec-chev">▾</span>
      </div>
    </div>
    <div id="debtBody">
      <div class="section-body">
        <table class="cg-table">
          <thead>
            <tr>
              <th>Fund Name / ISIN</th>
              <th>Purchase Date</th>
              <th>Sell Date</th>
              <th>Hold Period</th>
              <th class="num">Units</th>
              <th class="num">Cost ₹</th>
              <th class="num">Sale Value ₹</th>
              <th class="num">Gain ₹</th>
              <th>Tax Treatment</th>
            </tr>
          </thead>
          <tbody id="debtRows">
            <tr class="loading-row"><td colspan="9">Loading Debt MF transactions…</td></tr>
          </tbody>
        </table>
      </div>
      <div class="table-footer">
        <div class="tf-item"><div class="tf-lbl">Total Debt Gains</div><div class="tf-val" id="debtGain">₹0</div></div>
        <div class="tf-item"><div class="tf-lbl">Add to Total Income</div><div class="tf-val" style="color:var(--yellow)">Slab Rate</div></div>
      </div>
    </div>
  </div>

</div><!-- /page-wrap -->

<script>
// ══════════════════════════════════════════════════════
// DEMO DATA — replace fetchGains() with real API call
// ══════════════════════════════════════════════════════
const DEMO_GAINS = {
  '2024-25': {
    ltcg: [
      { fund:'Mirae Asset Large Cap Fund - Direct Growth', isin:'INF769K01010',
        purchaseDate:'2022-06-15', sellDate:'2024-07-20', holdMonths:25,
        units:120.456, buyNav:68.34, sellNav:89.12,
        costBasis:8237.56, saleValue:10740.23, gain:2502.67, grandFathered:false },
      { fund:'Axis Bluechip Fund - Direct Growth', isin:'INF846K01GH3',
        purchaseDate:'2021-03-10', sellDate:'2024-09-05', holdMonths:42,
        units:85.23, buyNav:32.10, sellNav:55.80,
        costBasis:2736.88, saleValue:4755.83, gain:2018.95, grandFathered:false },
      { fund:'SBI Blue Chip Fund - Direct Plan', isin:'INF200K01ZJ3',
        purchaseDate:'2017-08-14', sellDate:'2024-11-12', holdMonths:87,
        units:200.00, buyNav:28.50, sellNav:62.40,
        costBasis:5700.00, saleValue:12480.00, gain:6780.00, grandFathered:true,
        nav2018:38.20, grandCostBasis:7640.00, grandGain:4840.00 },
    ],
    stcg: [
      { fund:'Nippon India Small Cap Fund - Direct Growth', isin:'INF204K01EW2',
        purchaseDate:'2024-01-20', sellDate:'2024-10-15', holdMonths:9,
        units:55.78, buyNav:115.40, sellNav:152.30,
        costBasis:6437.99, saleValue:8497.37, gain:2059.38, grandFathered:false },
      { fund:'Parag Parikh Flexi Cap Fund - Direct Growth', isin:'INF879O01023',
        purchaseDate:'2023-12-05', sellDate:'2024-08-20', holdMonths:8,
        units:30.00, buyNav:62.80, sellNav:71.50,
        costBasis:1884.00, saleValue:2145.00, gain:261.00, grandFathered:false },
    ],
    debt: [
      { fund:'HDFC Banking & PSU Debt Fund - Direct Growth', isin:'INF179KB1856',
        purchaseDate:'2023-06-10', sellDate:'2024-12-18', holdMonths:18,
        units:145.22, costBasis:16400.00, saleValue:17220.00, gain:820.00,
        taxNote:'Add to income — slab rate applicable (post Apr 2023)' },
    ]
  }
};

let currentFY = '2024-25';
let allRows   = [];

// Format currency
function fmtInr(v) {
  if (v === undefined || v === null) return '—';
  const abs = Math.abs(v);
  let str;
  if (abs >= 1e7) str = '₹' + (abs/1e7).toFixed(2) + 'Cr';
  else if (abs >= 1e5) str = '₹' + (abs/1e5).toFixed(2) + 'L';
  else str = '₹' + abs.toLocaleString('en-IN', {minimumFractionDigits:2,maximumFractionDigits:2});
  return (v < 0 ? '-' : '') + str;
}

function fmtMonths(m) {
  const y = Math.floor(m/12), mo = m % 12;
  return y > 0 ? (y + 'y' + (mo>0?' '+mo+'m':'')) : mo + 'm';
}

function loadFY(fy, btn) {
  currentFY = fy;
  document.querySelectorAll('.fy-btn').forEach(b => b.classList.remove('active'));
  if (btn) btn.classList.add('active');

  // FY range label
  const [y1,y2] = fy.split('-');
  document.getElementById('fyRange').textContent =
    'Apr ' + y1 + ' – Mar 20' + y2;

  fetchGains(fy);
}

async function fetchGains(fy) {
  // ── Replace with real API call ──
  // const resp = await fetch('/api/reports/fy_gains.php?action=capital_gains_summary&fy=' + fy);
  // const data = await resp.json();
  const data = DEMO_GAINS[fy] || { ltcg: [], stcg: [], debt: [] };
  renderAll(data);
}

function renderAll(data) {
  const ltcg = data.ltcg || [];
  const stcg = data.stcg || [];
  const debt = data.debt || [];

  // ── LTCG rows ──
  let ltcgCost=0, ltcgSale=0, ltcgGain=0;
  document.getElementById('ltcgRows').innerHTML = ltcg.length ? ltcg.map(r => {
    const cost  = r.grandFathered ? r.grandCostBasis : r.costBasis;
    const gain  = r.grandFathered ? r.grandGain      : r.gain;
    ltcgCost += cost; ltcgSale += r.saleValue; ltcgGain += gain;
    return `<tr>
      <td><div class="fund-cell">
        <div class="fund-name">${r.fund}${r.grandFathered ? '<span class="grand-badge">GF</span>':''}</div>
        <div class="fund-isin">${r.isin}</div>
      </div></td>
      <td>${r.purchaseDate}</td>
      <td>${r.sellDate}</td>
      <td><span class="hold-pill hold-lt">${fmtMonths(r.holdMonths)}</span></td>
      <td class="num">${r.units.toFixed(3)}</td>
      <td class="num">${fmtInr(r.grandFathered ? r.nav2018 : r.buyNav)}</td>
      <td class="num">${fmtInr(r.sellNav)}</td>
      <td class="num">${fmtInr(cost)}</td>
      <td class="num">${fmtInr(r.saleValue)}</td>
      <td class="num ${gain>=0?'gain-pos':'gain-neg'}">${fmtInr(gain)}</td>
    </tr>`;
  }).join('') : '<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--muted)">No LTCG transactions in this FY</td></tr>';

  const ltcgExempt   = Math.min(Math.max(ltcgGain, 0), 125000);
  const ltcgTaxable  = Math.max(ltcgGain - ltcgExempt, 0);
  const ltcgTaxAmt   = ltcgTaxable * 0.10;

  document.getElementById('ltcgCost').textContent    = fmtInr(ltcgCost);
  document.getElementById('ltcgSale').textContent    = fmtInr(ltcgSale);
  document.getElementById('ltcgGain').textContent    = fmtInr(ltcgGain);
  document.getElementById('ltcgTaxable2').textContent= fmtInr(ltcgTaxable);
  document.getElementById('ltcgTax').textContent     = fmtInr(ltcgTaxAmt);
  document.getElementById('ltcgBadge').textContent   = ltcg.length + ' transactions';

  // ── STCG rows ──
  let stcgCost=0, stcgSale=0, stcgGain=0;
  document.getElementById('stcgRows').innerHTML = stcg.length ? stcg.map(r => {
    stcgCost += r.costBasis; stcgSale += r.saleValue; stcgGain += r.gain;
    return `<tr>
      <td><div class="fund-cell">
        <div class="fund-name">${r.fund}</div>
        <div class="fund-isin">${r.isin}</div>
      </div></td>
      <td>${r.purchaseDate}</td>
      <td>${r.sellDate}</td>
      <td><span class="hold-pill hold-st">${fmtMonths(r.holdMonths)}</span></td>
      <td class="num">${r.units.toFixed(3)}</td>
      <td class="num">${fmtInr(r.buyNav)}</td>
      <td class="num">${fmtInr(r.sellNav)}</td>
      <td class="num">${fmtInr(r.costBasis)}</td>
      <td class="num">${fmtInr(r.saleValue)}</td>
      <td class="num ${r.gain>=0?'gain-pos':'gain-neg'}">${fmtInr(r.gain)}</td>
    </tr>`;
  }).join('') : '<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--muted)">No STCG transactions in this FY</td></tr>';

  const stcgTaxAmt = Math.max(stcgGain, 0) * 0.20;
  document.getElementById('stcgCost').textContent  = fmtInr(stcgCost);
  document.getElementById('stcgSale').textContent  = fmtInr(stcgSale);
  document.getElementById('stcgGain').textContent  = fmtInr(stcgGain);
  document.getElementById('stcgTax').textContent   = fmtInr(stcgTaxAmt);
  document.getElementById('stcgBadge').textContent = stcg.length + ' transactions';

  // ── DEBT rows ──
  let debtGain = 0;
  document.getElementById('debtRows').innerHTML = debt.length ? debt.map(r => {
    debtGain += r.gain;
    return `<tr>
      <td><div class="fund-cell">
        <div class="fund-name">${r.fund}</div>
        <div class="fund-isin">${r.isin}</div>
      </div></td>
      <td>${r.purchaseDate}</td>
      <td>${r.sellDate}</td>
      <td><span class="hold-pill hold-st">${fmtMonths(r.holdMonths)}</span></td>
      <td class="num">${r.units.toFixed(3)}</td>
      <td class="num">${fmtInr(r.costBasis)}</td>
      <td class="num">${fmtInr(r.saleValue)}</td>
      <td class="num ${r.gain>=0?'gain-pos':'gain-neg'}">${fmtInr(r.gain)}</td>
      <td><span style="font-size:10px;color:var(--yellow);font-weight:600">${r.taxNote}</span></td>
    </tr>`;
  }).join('') : '<tr><td colspan="9" style="text-align:center;padding:20px;color:var(--muted)">No Debt MF transactions in this FY</td></tr>';

  document.getElementById('debtGain').textContent  = fmtInr(debtGain);
  document.getElementById('debtBadge').textContent = debt.length + ' transactions';

  // ── SUMMARY STRIP + TAX PANEL ──
  const totalTax = ltcgTaxAmt + stcgTaxAmt;
  document.getElementById('sumLTCG').textContent  = fmtInr(ltcgGain);
  document.getElementById('sumSTCG').textContent  = fmtInr(stcgGain);
  document.getElementById('sumDebt').textContent  = fmtInr(debtGain);
  document.getElementById('sumExempt').textContent= fmtInr(ltcgExempt);
  document.getElementById('sumTax').textContent   = fmtInr(totalTax);
  document.getElementById('sumTxns').textContent  = ltcg.length + stcg.length + debt.length;

  document.getElementById('tpLtcg').textContent       = fmtInr(ltcgGain);
  document.getElementById('tpExempt').textContent     = '- ' + fmtInr(ltcgExempt);
  document.getElementById('tpTaxableLtcg').textContent= fmtInr(ltcgTaxable);
  document.getElementById('tpLtcgTax').textContent    = fmtInr(ltcgTaxAmt);
  document.getElementById('tpStcg').textContent       = fmtInr(stcgGain);
  document.getElementById('tpStcgTax').textContent    = fmtInr(stcgTaxAmt);
  document.getElementById('tpDebt').textContent       = fmtInr(debtGain);
  document.getElementById('tpTotal').textContent      = fmtInr(totalTax);

  // Store for export
  allRows = { ltcg, stcg, debt, ltcgGain, stcgGain, ltcgExempt, ltcgTaxable, ltcgTaxAmt, stcgTaxAmt, totalTax };
}

function toggleSection(bodyId, hdr) {
  const body = document.getElementById(bodyId);
  const chev = hdr.querySelector('.sec-chev');
  if (body.style.display === 'none') { body.style.display = ''; chev.style.transform = ''; }
  else { body.style.display = 'none'; chev.style.transform = 'rotate(-90deg)'; }
}

// ── CSV EXPORTS ──
function exportCsv112A() {
  const rows = allRows.ltcg || [];
  const headers = ['ISIN','Fund Name','Purchase Date','Sell Date','Units','Full Value of Consideration','Cost of Acquisition','FMV on Jan 31 2018','LTCG Amount'];
  const csvRows = rows.map(r => [
    r.isin, '"' + r.fund + '"', r.purchaseDate, r.sellDate,
    r.units.toFixed(3), r.saleValue.toFixed(2),
    r.grandFathered ? r.grandCostBasis.toFixed(2) : r.costBasis.toFixed(2),
    r.grandFathered ? (r.nav2018 * r.units).toFixed(2) : 'N/A',
    (r.grandFathered ? r.grandGain : r.gain).toFixed(2)
  ].join(','));
  downloadCsv('Schedule_112A_LTCG_' + currentFY + '.csv', [headers.join(','), ...csvRows].join('\n'));
}

function exportScheduleCG() {
  const rows = allRows.stcg || [];
  const headers = ['Fund Name','ISIN','Purchase Date','Sell Date','Hold (months)','Units','Buy NAV','Sell NAV','Cost','Sale Value','STCG','Tax@20%'];
  const csvRows = rows.map(r => [
    '"'+r.fund+'"', r.isin, r.purchaseDate, r.sellDate, r.holdMonths,
    r.units.toFixed(3), r.buyNav.toFixed(4), r.sellNav.toFixed(4),
    r.costBasis.toFixed(2), r.saleValue.toFixed(2), r.gain.toFixed(2),
    (Math.max(r.gain,0)*0.20).toFixed(2)
  ].join(','));
  downloadCsv('Schedule_CG_STCG_' + currentFY + '.csv', [headers.join(','), ...csvRows].join('\n'));
}

function exportFull() {
  const all = [
    ...(allRows.ltcg||[]).map(r=>({...r, type:'LTCG', tax:10})),
    ...(allRows.stcg||[]).map(r=>({...r, type:'STCG', tax:20})),
    ...(allRows.debt||[]).map(r=>({...r, type:'DEBT_SLAB', tax:'Slab'})),
  ];
  const headers = ['Fund Name','ISIN','Type','Purchase Date','Sell Date','Hold (m)','Units','Cost','Sale Value','Gain','Tax Rate','Est Tax'];
  const rows = all.map(r => [
    '"'+r.fund+'"', r.isin, r.type, r.purchaseDate, r.sellDate,
    r.holdMonths, r.units.toFixed(3),
    (r.grandFathered ? r.grandCostBasis : r.costBasis).toFixed(2),
    r.saleValue.toFixed(2),
    (r.grandFathered ? r.grandGain : r.gain).toFixed(2),
    r.tax+'%',
    typeof r.tax==='number' ? (Math.max((r.grandFathered?r.grandGain:r.gain),0)*r.tax/100).toFixed(2) : 'Slab'
  ].join(','));
  downloadCsv('Capital_Gains_Full_' + currentFY + '.csv', [headers.join(','), ...rows].join('\n'));
}

function downloadCsv(filename, content) {
  const blob = new Blob([content], {type:'text/csv;charset=utf-8;'});
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a'); a.href = url; a.download = filename;
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
  URL.revokeObjectURL(url);
}

function printReport() { window.print(); }

// Init
loadFY('2024-25', document.getElementById('fy2425'));
</script>
</body>
</html>
