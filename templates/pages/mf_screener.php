<?php
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Find Funds';
$activePage  = 'mf_screener';
$totalFunds  = (int)DB::conn()->query("SELECT COUNT(*) FROM funds WHERE is_active=1")->fetchColumn();
$navFunds    = (int)DB::conn()->query("SELECT COUNT(*) FROM funds WHERE is_active=1 AND latest_nav IS NOT NULL AND latest_nav > 0")->fetchColumn();
$noNavFunds  = $totalFunds - $navFunds;

ob_start();
?>
<style>
/* ─── Full-width layout, no sidebar ──────────────────────── */
.sc-page { display:flex; flex-direction:column; gap:0; height:calc(100vh - 130px); overflow:hidden; }

/* ─── Stats bar ────────────────────────────────────────────── */
.sc-stats-bar {
  display:flex; align-items:center; gap:8px; padding:8px 0 10px;
  flex-wrap:wrap; flex-shrink:0;
}
.sc-stat-card {
  display:flex; flex-direction:column; align-items:center;
  padding:6px 14px; border-radius:8px; border:1.5px solid var(--border-color);
  background:var(--bg-card); min-width:80px; text-align:center; cursor:pointer;
  transition:all .15s; user-select:none;
}
.sc-stat-card:hover { border-color:var(--accent); transform:translateY(-1px); box-shadow:0 3px 10px rgba(0,0,0,.08); }
.sc-stat-card.active { border-color:var(--accent); background:rgba(37,99,235,.07); box-shadow:0 2px 8px rgba(37,99,235,.15); }
.sc-stat-num { font-size:16px; font-weight:800; line-height:1.2; }
.sc-stat-lbl { font-size:10px; font-weight:600; color:var(--text-muted); margin-top:2px; }

/* ─── Filter tab bar (MoneyControl style) ───────────────────── */
.sc-filter-bar {
  display:flex; align-items:center; gap:0;
  border:1px solid var(--border-color); border-radius:8px;
  background:var(--bg-card); flex-shrink:0; overflow:hidden;
  position:relative;
}
.sc-filter-tab {
  display:flex; align-items:center; gap:6px;
  padding:9px 16px; cursor:pointer; border-right:1px solid var(--border-color);
  font-size:11px; font-weight:800; letter-spacing:.3px; color:var(--text-muted);
  background:var(--bg-card); transition:all .15s; white-space:nowrap;
  user-select:none; position:relative;
}
.sc-filter-tab:last-of-type { border-right:none; }
.sc-filter-tab:hover { background:var(--bg-secondary); color:var(--text-primary); }
.sc-filter-tab.active { background:rgba(37,99,235,.05); color:var(--accent); border-bottom:2px solid var(--accent); }
.sc-filter-tab .tab-check {
  width:16px; height:16px; border-radius:50%; background:var(--accent); color:#fff;
  font-size:9px; display:none; align-items:center; justify-content:center; flex-shrink:0;
}
.sc-filter-tab .tab-check.show { display:flex; }
.sc-filter-tab .tab-cnt {
  font-size:10px; font-weight:800; background:var(--accent); color:#fff;
  border-radius:99px; padding:0 5px; min-width:16px; text-align:center;
}
.sc-filter-btn {
  margin-left:auto; display:flex; align-items:center; gap:6px;
  padding:9px 16px; cursor:pointer; font-size:12px; font-weight:800;
  color:var(--accent); background:var(--bg-card); border-left:1px solid var(--border-color);
  transition:all .15s; white-space:nowrap; flex-shrink:0;
}
.sc-filter-btn:hover { background:rgba(37,99,235,.05); }
.sc-filter-btn.open { background:rgba(37,99,235,.07); }

/* ─── Filter panel (dropdown) ───────────────────────────────── */
.sc-filter-panel {
  display:none; border:1px solid var(--border-color); border-radius:8px;
  background:var(--bg-card); padding:16px 20px 20px;
  flex-shrink:0; margin-top:-1px;
  box-shadow:0 4px 24px rgba(0,0,0,.1);
  max-height:340px; overflow-y:auto;
}
.sc-filter-panel.open { display:block; }

.fp-section-title {
  font-size:11px; font-weight:800; text-transform:uppercase; letter-spacing:.6px;
  color:var(--text-muted); margin-bottom:10px; display:flex; align-items:center; gap:10px;
}
.fp-actions { display:flex; gap:8px; margin-left:10px; }
.fp-action-btn {
  font-size:10px; font-weight:700; color:var(--accent); cursor:pointer;
  padding:1px 7px; border:1px solid rgba(37,99,235,.3); border-radius:4px; background:rgba(37,99,235,.07);
}
.fp-action-btn:hover { background:rgba(37,99,235,.15); }
.fp-action-btn.danger { color:#dc2626; border-color:rgba(239,68,68,.3); background:rgba(239,68,68,.05); }
.fp-action-btn.danger:hover { background:rgba(239,68,68,.15); }

/* Quick type pills grid */
.fp-type-grid { display:flex; flex-wrap:wrap; gap:6px; margin-bottom:14px; }
.fp-type-pill {
  display:inline-flex; align-items:center; gap:5px;
  padding:5px 12px; border-radius:99px; cursor:pointer;
  border:1.5px solid var(--border-color); font-size:11px; font-weight:700;
  background:var(--bg-secondary); color:var(--text-muted); transition:all .15s;
}
.fp-type-pill:hover { border-color:var(--accent); color:var(--accent); }
.fp-type-pill.active { border-color:var(--accent); background:rgba(37,99,235,.1); color:var(--accent); }
.fp-type-pill .pill-cnt { font-size:10px; font-weight:700; color:var(--text-muted); }
.fp-type-pill.active .pill-cnt { color:var(--accent); }

/* Plan/Option inline radio pills */
.fp-radio-group { display:flex; gap:6px; flex-wrap:wrap; margin-bottom:6px; }
.fp-radio-pill {
  display:inline-flex; align-items:center; gap:4px;
  padding:4px 12px; border-radius:99px; cursor:pointer;
  border:1.5px solid var(--border-color); font-size:11px; font-weight:700;
  background:var(--bg-secondary); color:var(--text-muted); transition:all .15s;
}
.fp-radio-pill input { display:none; }
.fp-radio-pill:has(input:checked) { border-color:var(--accent); color:var(--accent); background:rgba(37,99,235,.1); }

/* LTCG pills */
.fp-ltcg-grid { display:flex; gap:6px; flex-wrap:wrap; }
.fp-ltcg-pill {
  display:flex; flex-direction:column; align-items:center; gap:2px;
  padding:8px 14px; border-radius:8px; cursor:pointer; min-width:70px;
  border:1.5px solid var(--border-color); background:var(--bg-secondary);
  transition:all .15s; text-align:center;
}
.fp-ltcg-pill:hover { border-color:#16a34a; background:rgba(22,163,74,.05); }
.fp-ltcg-pill.active { border-color:#16a34a; background:rgba(22,163,74,.1); }
.fp-ltcg-pill .lp-icon { font-size:16px; }
.fp-ltcg-pill .lp-lbl { font-size:11px; font-weight:700; color:var(--text-primary); }
.fp-ltcg-pill .lp-cnt { font-size:10px; color:var(--text-muted); }
.fp-ltcg-pill.active .lp-lbl { color:#15803d; }

/* AMC grid — 4 columns like MoneyControl */
.fp-amc-grid { display:grid; grid-template-columns:repeat(4,1fr); gap:6px 16px; }
.fp-amc-item { display:flex; align-items:center; gap:6px; cursor:pointer; }
.fp-amc-item input { accent-color:var(--accent); cursor:pointer; flex-shrink:0; }
.fp-amc-label { font-size:12px; color:var(--text-primary); line-height:1.3; }
.fp-amc-search {
  width:220px; padding:5px 10px; font-size:12px; border-radius:6px;
  border:1.5px solid var(--border-color); background:var(--bg-secondary); color:var(--text-primary);
  margin-bottom:10px;
}
.fp-amc-search:focus { outline:none; border-color:var(--accent); }

/* Panel footer */
.fp-footer {
  display:flex; align-items:center; justify-content:space-between;
  padding:12px 20px 0; border-top:1px solid var(--border-color); margin-top:16px;
}
.fp-apply {
  padding:7px 24px; border-radius:7px; background:var(--accent); color:#fff;
  font-size:13px; font-weight:700; border:none; cursor:pointer; transition:opacity .15s;
}
.fp-apply:hover { opacity:.9; }
.fp-reset {
  padding:7px 18px; border-radius:7px; border:1.5px solid var(--border-color);
  background:none; color:var(--text-muted); font-size:13px; font-weight:600; cursor:pointer;
}
.fp-reset:hover { border-color:#dc2626; color:#dc2626; }

/* ─── Search + count bar ────────────────────────────────────── */
.sc-search-bar {
  display:flex; align-items:center; gap:8px; padding:8px 0 6px;
  flex-shrink:0; flex-wrap:wrap;
}
.sc-search-wrap { position:relative; flex:1; min-width:200px; max-width:360px; }
.sc-search-wrap svg { position:absolute;left:9px;top:50%;transform:translateY(-50%);opacity:.35;pointer-events:none; }
.sc-search { width:100%;padding:7px 10px 7px 30px;border-radius:8px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);font-size:13px;transition:border-color .15s; }
.sc-search:focus { outline:none;border-color:var(--accent); }
.sc-count-pill { font-size:12px;font-weight:800;color:var(--accent);background:rgba(37,99,235,.1);padding:5px 14px;border-radius:99px;white-space:nowrap; }
.sc-sel { padding:6px 8px;font-size:12px;border-radius:7px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);cursor:pointer; }

/* ─── Active chips ──────────────────────────────────────────── */
.sc-chips { display:flex;gap:5px;flex-wrap:wrap;align-items:center;padding:4px 0 6px;flex-shrink:0;min-height:28px; }
.sc-chip { display:inline-flex;align-items:center;gap:3px;padding:2px 8px;border-radius:99px;font-size:11px;font-weight:700;background:rgba(37,99,235,.1);color:var(--accent);border:1.5px solid rgba(37,99,235,.2);cursor:pointer;transition:all .12s; }
.sc-chip:hover { background:rgba(239,68,68,.1);color:#dc2626;border-color:rgba(239,68,68,.3); }
.sc-clear-all { margin-left:auto;font-size:10px;font-weight:600;cursor:pointer;padding:2px 7px;border-radius:4px;border:1px solid var(--border-color);background:none;color:var(--text-muted); }
.sc-clear-all:hover { color:#dc2626;border-color:#dc2626; }

/* ─── Results ───────────────────────────────────────────────── */
.sc-results-wrap {
  display:flex; flex-direction:column; overflow:hidden; flex:1;
  border:1px solid var(--border-color); border-radius:8px; background:var(--bg-card);
}
.sc-results { flex:1; overflow-y:auto; }
.sc-table { width:100%;border-collapse:collapse;font-size:12px;table-layout:auto; }
.sc-table thead th {
  position:sticky;top:0;z-index:5;background:var(--bg-secondary);
  padding:8px 10px;text-align:left;font-size:10px;font-weight:800;
  text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);
  border-bottom:2px solid var(--border-color);border-right:1px solid var(--border-color);white-space:nowrap;
}
.sc-table thead th:last-child { border-right:none; }
.sc-table tbody tr { border-bottom:1px solid var(--border-color);transition:background .1s; }
.sc-table tbody tr:hover { background:var(--bg-secondary); }
.sc-table td { padding:7px 10px;vertical-align:middle;border-right:1px solid var(--border-color); }
.sc-table td:last-child { border-right:none; }
.sc-b { display:inline-block;padding:2px 7px;border-radius:5px;font-size:10px;font-weight:700; }
.b-eq{background:rgba(22,163,74,.1);color:#15803d;} .b-dt{background:rgba(99,102,241,.1);color:#4338ca;}
.b-cm{background:rgba(234,179,8,.1);color:#b45309;} .b-fo{background:rgba(168,85,247,.1);color:#7c3aed;}
.b-sl{background:rgba(239,68,68,.1);color:#dc2626;} .b-ot{background:var(--bg-secondary);color:var(--text-muted);}
.b-direct{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(22,163,74,.1);color:#15803d;border:1px solid rgba(22,163,74,.2);}
.b-regular{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(234,179,8,.1);color:#b45309;border:1px solid rgba(234,179,8,.2);}
.b-growth{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(37,99,235,.08);color:var(--accent);}
.b-idcw{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(168,85,247,.08);color:#7c3aed;}
.b-ltcg{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(22,163,74,.08);color:#15803d;}
.b-lock{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:700;background:rgba(234,179,8,.1);color:#b45309;}

/* Pagination */
.sc-pagination{padding:7px 12px;border-top:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;background:var(--bg-card);}
.sc-pgbtn{padding:3px 10px;border:1.5px solid var(--border-color);border-radius:6px;background:var(--bg-secondary);color:var(--text-primary);cursor:pointer;font-size:11px;font-weight:600;transition:all .15s;}
.sc-pgbtn:hover:not(:disabled){border-color:var(--accent);color:var(--accent);}
.sc-pgbtn:disabled{opacity:.35;cursor:default;}
.sc-pgnum{width:26px;height:26px;border:1.5px solid var(--border-color);border-radius:5px;background:var(--bg-secondary);color:var(--text-muted);cursor:pointer;font-size:11px;font-weight:600;display:inline-flex;align-items:center;justify-content:center;transition:all .15s;}
.sc-pgnum:hover:not(.active){border-color:var(--accent);color:var(--accent);}
.sc-pgnum.active{background:var(--accent);color:#fff;border-color:var(--accent);}

/* Sort arrows */
.sh-arr { font-size:10px; margin-left:3px; opacity:.5; }
.sc-table thead th:hover .sh-arr { opacity:1; }
.sc-table thead th.sort-asc  { color:var(--accent); }
.sc-table thead th.sort-desc { color:var(--accent); }
.sc-table thead th.sort-asc  .sh-arr::after { content:'↑'; }
.sc-table thead th.sort-desc .sh-arr::after { content:'↓'; }
.sc-table thead th:not(.sort-asc):not(.sort-desc) .sh-arr::after { content:'↕'; }

/* Drawer */
.sc-ov{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:900;display:none;}
.sc-ov.open{display:block;}
.sc-dr{position:fixed;right:-460px;top:0;bottom:0;width:440px;max-width:95vw;background:var(--bg-card);box-shadow:-8px 0 32px rgba(0,0,0,.2);z-index:901;display:flex;flex-direction:column;transition:right .25s cubic-bezier(.4,0,.2,1);}
.sc-dr.open{right:0;}
.sc-dr-hdr{padding:14px 18px;border-bottom:1px solid var(--border-color);display:flex;align-items:flex-start;gap:10px;flex-shrink:0;}
.sc-dr-body{flex:1;overflow-y:auto;padding:14px 18px;}
.sc-dr-footer{padding:10px 18px;border-top:1px solid var(--border-color);display:flex;gap:8px;flex-shrink:0;}
.d-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
.d-box{padding:9px 11px;border-radius:8px;background:var(--bg-secondary);border:1px solid var(--border-color);}
.d-lbl{font-size:9px;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);font-weight:800;margin-bottom:3px;}
.d-val{font-size:14px;font-weight:700;color:var(--text-primary);}
.d-sub{font-size:10px;color:var(--text-muted);margin-top:1px;}
.d-sec{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin:12px 0 7px;padding-bottom:4px;border-bottom:1px solid var(--border-color);}
</style>

<!-- Stats bar -->
<div class="sc-stats-bar" id="scStatsBar">
  <div class="sc-stat-card" onclick="SC.setQuickType('');SC.trigger()" id="stat_All">
    <div class="sc-stat-num" style="color:var(--accent);" id="sn_all"><?= number_format($totalFunds) ?></div>
    <div class="sc-stat-lbl">All Funds</div>
  </div>
  <div class="sc-stat-card" style="border-color:rgba(22,163,74,.3);cursor:default;" title="<?= $noNavFunds ?> funds have no NAV (closed/suspended/new)">
    <div class="sc-stat-num" style="color:#15803d;"><?= number_format($navFunds) ?></div>
    <div class="sc-stat-lbl" style="color:#15803d;">With NAV</div>
  </div>
  <div class="sc-stat-card" onclick="SC.setQuickType('Equity')" id="stat_Equity">
    <div class="sc-stat-num" style="color:#15803d;" id="sn_Equity">—</div>
    <div class="sc-stat-lbl">📈 Equity</div>
  </div>
  <div class="sc-stat-card" onclick="SC.setQuickType('Debt')" id="stat_Debt">
    <div class="sc-stat-num" style="color:#4338ca;" id="sn_Debt">—</div>
    <div class="sc-stat-lbl">🏛 Debt</div>
  </div>
  <div class="sc-stat-card" onclick="SC.setQuickType('Commodity')" id="stat_Commodity">
    <div class="sc-stat-num" style="color:#b45309;" id="sn_Commodity">—</div>
    <div class="sc-stat-lbl">🥇 Gold/Silver</div>
  </div>
  <div class="sc-stat-card" onclick="SC.setQuickType('FoF/Intl')" id="stat_FoF">
    <div class="sc-stat-num" style="color:#7c3aed;" id="sn_FoF">—</div>
    <div class="sc-stat-lbl">🌍 Intl/FoF</div>
  </div>
  <div class="sc-stat-card" onclick="SC.setQuickType('ELSS')" id="stat_ELSS">
    <div class="sc-stat-num" style="color:#dc2626;" id="sn_ELSS">161</div>
    <div class="sc-stat-lbl">🔒 ELSS</div>
  </div>
  <div class="sc-stat-card" onclick="SC.setQuickType('Solution')" id="stat_Solution">
    <div class="sc-stat-num" style="color:#0891b2;" id="sn_Solution">—</div>
    <div class="sc-stat-lbl">🎯 Solution</div>
  </div>
</div>

<!-- MoneyControl-style filter tab bar -->
<div class="sc-filter-bar" id="scFilterBar">
  <div class="sc-filter-tab" data-tab="fund_house" onclick="toggleTab('fund_house',this)">
    <span class="tab-check" id="tck_fh">✓</span>
    FUND HOUSE
    <span class="tab-cnt" id="tcnt_fh" style="display:none;"></span>
  </div>
  <div class="sc-filter-tab" data-tab="category" onclick="toggleTab('category',this)">
    <span class="tab-check" id="tck_cat">✓</span>
    CATEGORY
    <span class="tab-cnt" id="tcnt_cat" style="display:none;"></span>
  </div>
  <div class="sc-filter-tab" data-tab="ltcg" onclick="toggleTab('ltcg',this)">
    <span class="tab-check" id="tck_ltcg">✓</span>
    LTCG PERIOD
    <span class="tab-cnt" id="tcnt_ltcg" style="display:none;"></span>
  </div>
  <div class="sc-filter-tab" data-tab="plan" onclick="toggleTab('plan',this)">
    <span class="tab-check" id="tck_plan">✓</span>
    PLAN &amp; OPTION
    <span class="tab-cnt" id="tcnt_plan" style="display:none;"></span>
  </div>
  <div class="sc-filter-tab" data-tab="lockin" onclick="toggleTab('lockin',this)">
    <span class="tab-check" id="tck_lock">✓</span>
    LOCK-IN
  </div>
  <div class="sc-filter-tab" data-tab="expense" onclick="toggleTab('expense',this)">
    <span class="tab-check" id="tck_exp">✓</span>
    EXPENSE RATIO
    <span class="tab-cnt" id="tcnt_exp" style="display:none;"></span>
  </div>
  <div class="sc-filter-btn" id="scFilterBtn" onclick="SC.applyFilters()">
    <svg width="14" height="14" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24"><line x1="4" y1="6" x2="20" y2="6"/><line x1="8" y1="12" x2="16" y2="12"/><line x1="11" y1="18" x2="13" y2="18"/></svg>
    FILTER
  </div>
</div>

<!-- Filter panel (expands below tab bar) -->
<div class="sc-filter-panel" id="scFilterPanel">

  <!-- FUND HOUSE panel -->
  <div id="fp_fund_house" style="display:none;">
    <div class="fp-section-title">
      SELECT FUND HOUSE
      <div class="fp-actions">
        <span class="fp-action-btn" onclick="FP.amcSelectAll()">SELECT ALL</span>
        <span class="fp-action-btn danger" onclick="FP.amcClearAll()">REMOVE ALL</span>
        <span class="fp-action-btn" onclick="FP.amcSelectTop(5)">SELECT TOP 5</span>
        <span class="fp-action-btn" onclick="FP.amcSelectTop(10)">SELECT TOP 10</span>
      </div>
      <input type="search" class="fp-amc-search" style="margin-left:auto;margin-bottom:0;" placeholder="Search AMC…" oninput="FP.filterAmc(this.value)">
    </div>
    <div class="fp-amc-grid" id="fpAmcGrid">
      <div style="color:var(--text-muted);font-size:12px;">Loading…</div>
    </div>
  </div>

  <!-- CATEGORY panel -->
  <div id="fp_category" style="display:none;">
    <div class="fp-section-title">
      QUICK TYPE
      <div class="fp-actions">
        <span class="fp-action-btn danger" onclick="SC.state.categories=[];SC.state.quickType='';updCatChecks();updTabBadge();">CLEAR</span>
      </div>
    </div>
    <div class="fp-type-grid" id="fpTypePills">
      <div class="fp-type-pill" data-type="Equity"    onclick="SC.setQuickType('Equity')">📈 Equity <span class="pill-cnt" id="ptc_Equity">—</span></div>
      <div class="fp-type-pill" data-type="Debt"      onclick="SC.setQuickType('Debt')">🏛 Debt <span class="pill-cnt" id="ptc_Debt">—</span></div>
      <div class="fp-type-pill" data-type="Commodity" onclick="SC.setQuickType('Commodity')">🥇 Gold/Silver <span class="pill-cnt" id="ptc_Commodity">—</span></div>
      <div class="fp-type-pill" data-type="FoF/Intl"  onclick="SC.setQuickType('FoF/Intl')">🌍 Intl/FoF <span class="pill-cnt" id="ptc_FoFIntl">—</span></div>
      <div class="fp-type-pill" data-type="Solution"  onclick="SC.setQuickType('Solution')">🎯 Solution <span class="pill-cnt" id="ptc_Solution">—</span></div>
      <div class="fp-type-pill" data-type="ELSS"      onclick="SC.setQuickType('ELSS')">🔒 ELSS <span class="pill-cnt" id="ptc_ELSS">161</span></div>
    </div>
    <div class="fp-section-title" style="margin-top:14px;">
      ALL CATEGORIES
      <input type="search" class="fp-amc-search" style="margin-left:auto;margin-bottom:0;" placeholder="Search category…" oninput="FP.filterCat(this.value)">
    </div>
    <div class="fp-amc-grid" id="fpCatGrid">
      <div style="color:var(--text-muted);font-size:12px;">Loading…</div>
    </div>
  </div>

  <!-- LTCG PERIOD panel -->
  <div id="fp_ltcg" style="display:none;">
    <div class="fp-section-title">SELECT LTCG HOLDING PERIOD</div>
    <div class="fp-ltcg-grid" id="fpLtcgPills">
      <div class="fp-ltcg-pill active" data-val="0" onclick="SC.setLtcgPill(0,this)"><span class="lp-icon">🗓</span><span class="lp-lbl">All</span><span class="lp-cnt" id="lp_all">—</span></div>
      <div class="fp-ltcg-pill" data-val="365" onclick="SC.setLtcgPill(365,this)"><span class="lp-icon">📈</span><span class="lp-lbl">1 Year</span><span class="lp-cnt" id="lp_365">—</span></div>
      <div class="fp-ltcg-pill" data-val="730" onclick="SC.setLtcgPill(730,this)"><span class="lp-icon">🏛</span><span class="lp-lbl">2 Years</span><span class="lp-cnt" id="lp_730">—</span></div>
      <div class="fp-ltcg-pill" data-val="1095" onclick="SC.setLtcgPill(1095,this)"><span class="lp-icon">🔒</span><span class="lp-lbl">3 Years</span><span class="lp-cnt" id="lp_1095">—</span></div>
      <div class="fp-ltcg-pill" data-val="1825" onclick="SC.setLtcgPill(1825,this)"><span class="lp-icon">🎯</span><span class="lp-lbl">5 Years</span><span class="lp-cnt" id="lp_1825">—</span></div>
    </div>
  </div>

  <!-- PLAN & OPTION panel -->
  <div id="fp_plan" style="display:none;">
    <div class="fp-section-title">PLAN TYPE</div>
    <div class="fp-radio-group">
      <label class="fp-radio-pill"><input type="radio" name="planType" value="all" checked onchange="SC.setPlanType(this.value)">All Plans</label>
      <label class="fp-radio-pill"><input type="radio" name="planType" value="direct" onchange="SC.setPlanType(this.value)">✅ Direct <span style="font-size:10px;opacity:.7;">(No commission)</span></label>
      <label class="fp-radio-pill"><input type="radio" name="planType" value="regular" onchange="SC.setPlanType(this.value)">📦 Regular <span style="font-size:10px;opacity:.7;">(Via distributor)</span></label>
    </div>
    <div class="fp-section-title" style="margin-top:14px;">OPTION TYPE</div>
    <div class="fp-radio-group">
      <label class="fp-radio-pill"><input type="radio" name="optionType" value="all" checked onchange="SC.setOption(this.value)">All Options</label>
      <label class="fp-radio-pill"><input type="radio" name="optionType" value="growth" onchange="SC.setOption(this.value)">📈 Growth</label>
      <label class="fp-radio-pill"><input type="radio" name="optionType" value="idcw" onchange="SC.setOption(this.value)">💰 IDCW / Dividend</label>
    </div>
  </div>

  <!-- EXPENSE RATIO panel -->
  <div id="fp_expense" style="display:none;">
    <div class="fp-section-title">EXPENSE RATIO RANGE</div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:8px;">
      <div style="display:flex;align-items:center;gap:6px;">
        <label style="font-size:12px;color:var(--text-muted);font-weight:600;">Min</label>
        <input type="number" id="fpExpMin" min="0" max="5" step="0.1" placeholder="0"
          style="width:70px;padding:5px 8px;font-size:12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);"
          oninput="SC.setExpense()">
        <span style="font-size:12px;color:var(--text-muted);">%</span>
      </div>
      <span style="color:var(--text-muted);">—</span>
      <div style="display:flex;align-items:center;gap:6px;">
        <label style="font-size:12px;color:var(--text-muted);font-weight:600;">Max</label>
        <input type="number" id="fpExpMax" min="0" max="5" step="0.1" placeholder="5"
          style="width:70px;padding:5px 8px;font-size:12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);"
          oninput="SC.setExpense()">
        <span style="font-size:12px;color:var(--text-muted);">%</span>
      </div>
    </div>
    <div class="fp-radio-group">
      <span class="fp-type-pill" onclick="SC.setExpPreset(0,0.5)">Ultra Low (&lt;0.5%)</span>
      <span class="fp-type-pill" onclick="SC.setExpPreset(0,1)">Low (&lt;1%)</span>
      <span class="fp-type-pill" onclick="SC.setExpPreset(0,1.5)">Moderate (&lt;1.5%)</span>
      <span class="fp-type-pill" onclick="SC.setExpPreset(1.5,3)">High (1.5-3%)</span>
    </div>
    <div style="margin-top:8px;">
      <label class="fp-radio-pill"><input type="checkbox" id="fpHasTer" onchange="SC.setExpense()">
        Only show funds with TER data</label>
    </div>
  </div>

  <!-- LOCK-IN panel -->
  <div id="fp_lockin" style="display:none;">
    <div class="fp-section-title">LOCK-IN FILTER</div>
    <div class="fp-radio-group">
      <label class="fp-radio-pill"><input type="radio" name="liF" value="-1" checked onchange="SC.setLockin(-1)">All Funds</label>
      <label class="fp-radio-pill"><input type="radio" name="liF" value="0" onchange="SC.setLockin(0)">🟢 No Lock-in</label>
      <label class="fp-radio-pill"><input type="radio" name="liF" value="1" onchange="SC.setLockin(1)">🔒 Has Lock-in (ELSS / Retirement)</label>
    </div>
  </div>

  <!-- Panel footer -->
  <div class="fp-footer">
    <div style="font-size:12px;color:var(--text-muted);" id="fpFilterSummary">No filters applied</div>
    <div style="display:flex;gap:8px;">
      <button class="fp-reset" onclick="SC.reset()">Reset</button>
      <button class="fp-apply" onclick="SC.applyFilters()">Apply Filter</button>
    </div>
  </div>
</div>

<!-- Search + results bar -->
<div class="sc-search-bar">
  <div class="sc-search-wrap">
    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><circle cx="11" cy="11" r="8"/><path d="M21 21l-4.35-4.35"/></svg>
    <input type="search" class="sc-search" id="scQ" placeholder="Fund name, AMC, scheme code…" oninput="SC.onSearch(this.value)">
  </div>
  <span class="sc-count-pill" id="scPill">— funds</span>
  <select class="sc-sel" id="scSort" onchange="SC.setSort(this.value)">
    <option value="name">A → Z</option>
    <option value="nav_desc">NAV ↓</option>
    <option value="nav_asc">NAV ↑</option>
    <option value="ltcg">LTCG Period</option>
    <option value="house">AMC</option>
  </select>
  <select class="sc-sel" id="scPP" onchange="SC.setPerPage(+this.value)">
    <option value="25">25</option>
    <option value="50" selected>50</option>
    <option value="100">100</option>
  </select>
</div>

<!-- Active chips -->
<div class="sc-chips" id="scChips"></div>

<!-- Results -->
<div class="sc-results-wrap">
  <div class="sc-results" id="scRes">
    <div style="display:flex;align-items:center;justify-content:center;padding:60px;"><div class="spinner"></div></div>
  </div>
  <div class="sc-pagination" id="scPag" style="display:none;">
    <button class="sc-pgbtn" id="pgPv" onclick="SC.goPage(SC.state.page-1)">‹ Prev</button>
    <div style="display:flex;align-items:center;gap:3px;" id="pgNums"></div>
    <div style="display:flex;align-items:center;gap:8px;">
      <span style="font-size:11px;color:var(--text-muted);" id="pgInfo"></span>
      <button class="sc-pgbtn" id="pgNx" onclick="SC.goPage(SC.state.page+1)">Next ›</button>
    </div>
  </div>
</div>

<!-- Drawer -->
<div class="sc-ov" id="scOv" onclick="drClose()"></div>
<div class="sc-dr" id="scDr">
  <div class="sc-dr-hdr">
    <div style="flex:1;"><div style="font-size:13px;font-weight:700;line-height:1.3;margin-bottom:2px;" id="drTitle">—</div><div style="font-size:11px;color:var(--text-muted);" id="drSub">—</div></div>
    <button onclick="drClose()" style="background:none;border:none;font-size:16px;cursor:pointer;color:var(--text-muted);padding:2px 6px;">✕</button>
  </div>
  <div class="sc-dr-body" id="drBody"></div>
  <div class="sc-dr-footer">
    <button class="btn btn-primary btn-sm" id="drAddBtn">+ Add Transaction</button>
    <button class="btn btn-ghost btn-sm" onclick="drClose()">Close</button>
  </div>
</div>

<script>
/* ══════════════════════════════════════════════════
   STATE
══════════════════════════════════════════════════ */
const SC = {
  state:{ q:'',categories:[],fundHouses:[],optionType:'all',planType:'all',ltcgDays:0,hasLockin:-1,expMin:null,expMax:null,hasTer:false,sort:'name',sortDir:'asc',page:1,perPage:50,quickType:'' },
  facets:{ fund_houses:{},categories:[],ltcg_days:{} },
  _db:null, loading:false,

  trigger(rp=true){ if(rp) this.state.page=1; clearTimeout(this._db); this._db=setTimeout(()=>this.fetch(),240); },
  onSearch(v){ this.state.q=v.trim(); this.trigger(); },
  setSort(v){ this.state.sort=v; this.trigger(false); },
  setPerPage(v){ this.state.perPage=v; this.trigger(); },
  setOption(v){ this.state.optionType=v; updTabBadge(); },
  setPlanType(v){ this.state.planType=v; updTabBadge(); },
  setLtcg(v){ this.state.ltcgDays=+v; updTabBadge(); },
  setLockin(v){ this.state.hasLockin=+v; updTabBadge(); },
  goPage(p){ this.state.page=p; this.trigger(false); },

  setLtcgPill(v,el){
    this.state.ltcgDays=v;
    document.querySelectorAll('.fp-ltcg-pill').forEach(p=>p.classList.toggle('active',+p.dataset.val===v));
    updTabBadge();
  },

  toggleCat(cat,el){
    const i=this.state.categories.indexOf(cat);
    if(i>=0){this.state.categories.splice(i,1);el.checked=false;}
    else{this.state.categories.push(cat);el.checked=true;}
    updTabBadge();
  },
  toggleFh(fh,el){
    const i=this.state.fundHouses.indexOf(fh);
    if(i>=0){this.state.fundHouses.splice(i,1);el.checked=false;}
    else{this.state.fundHouses.push(fh);el.checked=true;}
    updTabBadge();
  },

  setQuickType(type){
    // Toggle
    if(this.state.quickType===type&&type!==''){this.state.quickType='';this.state.categories=[];}
    else{
      this.state.quickType=type;
      if(type===''){this.state.categories=[];}
      else{
        const km={Equity:['equity','large cap','mid cap','small cap','flexi','multi cap','index','etf','hybrid','arbitrage','balanced','thematic','sectoral','dividend yield','value fund','contra','focused'],Debt:['debt','liquid','overnight','money market','gilt','credit','duration','floater','banking and psu','corporate bond'],Commodity:['gold','silver','commodit','precious metal'],'FoF/Intl':['fund of fund','overseas','international','fof'],Solution:['retirement','children'],ELSS:['elss','tax sav']};
        const kws=km[type]||[];
        this.state.categories=this.facets.categories.length?this.facets.categories.filter(c=>kws.some(k=>(c.category||'').toLowerCase().includes(k))).map(c=>c.category):[];
      }
    }
    // Update UI
    document.querySelectorAll('.fp-type-pill,.sc-stat-card').forEach(p=>{
      const t=p.dataset.type||p.id?.replace('stat_','');
      if(t) p.classList.toggle('active',t===this.state.quickType);
    });
    updCatChecks();
    updTabBadge();
    this.trigger();
  },

  applyFilters(){
    closeAllTabs();
    this.trigger();
  },

  reset(){
    this.state={q:'',categories:[],fundHouses:[],optionType:'all',planType:'all',ltcgDays:0,hasLockin:-1,sort:'name',page:1,perPage:50,quickType:''};
    document.getElementById('scQ').value='';
    document.getElementById('scSort').value='name';
    document.getElementById('scPP').value='50';
    document.querySelector('input[name=planType]').checked=true;
    document.querySelector('input[name=optionType]').checked=true;
    document.querySelector('input[name=liF]').checked=true;
    document.querySelectorAll('.fp-ltcg-pill').forEach(p=>p.classList.toggle('active',+p.dataset.val===0));
    document.querySelectorAll('#fpAmcGrid input,#fpCatGrid input').forEach(cb=>cb.checked=false);
    const minEl=document.getElementById('fpExpMin'); if(minEl) minEl.value='';
    const maxEl=document.getElementById('fpExpMax'); if(maxEl) maxEl.value='';
    const htEl=document.getElementById('fpHasTer');  if(htEl)  htEl.checked=false;
    const tckE=document.getElementById('tck_exp');   if(tckE)  tckE.classList.remove('show');
    const cntE=document.getElementById('tcnt_exp');  if(cntE)  cntE.style.display='none';
    document.querySelectorAll('.fp-type-pill,.sc-stat-card').forEach(p=>p.classList.remove('active'));
    ['tck_fh','tck_cat','tck_ltcg','tck_plan','tck_lock'].forEach(id=>{const e=document.getElementById(id);if(e)e.classList.remove('show');});
    ['tcnt_fh','tcnt_cat','tcnt_ltcg','tcnt_plan'].forEach(id=>{const e=document.getElementById(id);if(e)e.style.display='none';});
    document.getElementById('fpFilterSummary').textContent='No filters applied';
    this.trigger();
  },

  buildParams(){
    const s=this.state,p=new URLSearchParams();
    if(s.q) p.set('q',s.q);
    s.categories.forEach(c=>p.append('category[]',c));
    s.fundHouses.forEach(h=>p.append('fund_house[]',h));
    if(s.optionType!=='all') p.set('option_type',s.optionType);
    if(s.planType!=='all')   p.set('plan_type',s.planType);
    if(s.ltcgDays>0) p.set('ltcg_days',s.ltcgDays);
    if(s.hasLockin>=0) p.set('has_lockin',s.hasLockin);
    if(s.expMin!==null&&s.expMin!==undefined) p.set('exp_min',s.expMin);
    if(s.expMax!==null&&s.expMax!==undefined) p.set('exp_max',s.expMax);
    if(s.hasTer) p.set('has_ter','1');
    p.set('sort',s.sort);p.set('page',s.page);p.set('per_page',s.perPage);
    return p.toString();
  },

  async fetch(){
    // Allow re-fetch even if previous was loading (handles back-navigation)
    this.loading=true;
    document.getElementById('scRes').innerHTML='<div style="display:flex;align-items:center;justify-content:center;padding:60px;"><div class="spinner"></div></div>';
    try{
      const base=window._SCBASE||window.WD?.appUrl||window.APP_URL||'';
      const res=await fetch(`${base}/api/mutual_funds/fund_screener.php?${this.buildParams()}`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
      const txt=await res.text();
      let d;
      try{ d=JSON.parse(txt); }catch(e){ throw new Error('Server error: '+txt.substring(0,100)); }
      if(!d.success) throw new Error(d.message||'Error');

      if(d.facets?.categories?.length){
        this.facets=d.facets;
        FP.renderAmc(d.facets.fund_houses);
        FP.renderCat(d.facets.categories);
        updLtcgCounts(d.facets.ltcg_days);
        updPillCounts(d.facets.categories);
        updStatCards(d.facets.categories);
        if(this.state.quickType&&this.state.categories.length===0){this.setQuickType(this.state.quickType);return;}
      }
      renderTable(d.data,d.total);
      renderPag(d.page,d.pages,d.total);
      renderChips();
      updFpSummary();
      document.getElementById('scPill').textContent=(d.total||0).toLocaleString('en-IN')+' funds';
    }catch(e){
      console.error('SC.fetch error:', e);
      document.getElementById('scRes').innerHTML=`<div style="padding:60px;text-align:center;">
        <div style="font-size:32px;margin-bottom:12px;">⚠️</div>
        <div style="font-size:14px;font-weight:700;color:var(--text-primary);margin-bottom:8px;">${e.message}</div>
        <button onclick="SC.fetch()" style="padding:8px 20px;border-radius:7px;border:1.5px solid var(--accent);background:rgba(37,99,235,.07);color:var(--accent);cursor:pointer;font-weight:700;">↺ Retry</button>
      </div>`;
    }finally{this.loading=false;}
  },
};

/* ══════════════════════════════════════════════════
   FILTER PANEL (FP)
══════════════════════════════════════════════════ */
const FP = {
  _amcAll:[],_catAll:[],

  renderAmc(f){
    this._amcAll=Object.entries(f);
    this._drawAmc(this._amcAll);
  },
  _drawAmc(entries){
    document.getElementById('fpAmcGrid').innerHTML=entries.map(([fh,cnt])=>
      `<label class="fp-amc-item"><input type="checkbox" data-fh="${fh}" ${SC.state.fundHouses.includes(fh)?'checked':''} onchange="SC.toggleFh('${fh.replace(/'/g,"\\'")}',this)"><span class="fp-amc-label">${fh} <span style="font-size:10px;color:var(--text-muted);">(${Number(cnt).toLocaleString('en-IN')})</span></span></label>`
    ).join('');
  },
  filterAmc(q){ this._drawAmc(q?this._amcAll.filter(([f])=>f.toLowerCase().includes(q.toLowerCase())):this._amcAll); },
  amcSelectAll(){ SC.state.fundHouses=this._amcAll.map(([f])=>f); document.querySelectorAll('#fpAmcGrid input').forEach(cb=>cb.checked=true); updTabBadge(); },
  amcClearAll(){ SC.state.fundHouses=[]; document.querySelectorAll('#fpAmcGrid input').forEach(cb=>cb.checked=false); updTabBadge(); },
  amcSelectTop(n){ SC.state.fundHouses=this._amcAll.slice(0,n).map(([f])=>f); document.querySelectorAll('#fpAmcGrid input').forEach((cb,i)=>{cb.checked=i<n;}); updTabBadge(); },

  renderCat(f){
    this._catAll=f;
    this._drawCat(f);
  },
  _drawCat(f){
    document.getElementById('fpCatGrid').innerHTML=f.map(c=>
      `<label class="fp-amc-item"><input type="checkbox" data-cat="${(c.category||'').replace(/"/g,'&quot;')}" ${SC.state.categories.includes(c.category)?'checked':''} onchange="SC.toggleCat(this.dataset.cat,this)"><span class="fp-amc-label">${(c.short||c.category||'—').substring(0,30)} <span style="font-size:10px;color:var(--text-muted);">(${c.count.toLocaleString('en-IN')})</span></span></label>`
    ).join('');
  },
  filterCat(q){ this._drawCat(q?this._catAll.filter(c=>(c.short||'').toLowerCase().includes(q.toLowerCase())):this._catAll); },
};

/* ══════════════════════════════════════════════════
   TAB TOGGLE
══════════════════════════════════════════════════ */
let _openTab = null;
function toggleTab(name, el) {
  const panel = document.getElementById('scFilterPanel');
  const allFps = ['fp_fund_house','fp_category','fp_ltcg','fp_plan','fp_lockin','fp_expense'];
  const tabs = document.querySelectorAll('.sc-filter-tab');

  if (_openTab === name) {
    // Close
    _openTab = null;
    panel.classList.remove('open');
    tabs.forEach(t=>t.classList.remove('active'));
    return;
  }
  _openTab = name;
  panel.classList.add('open');
  tabs.forEach(t=>t.classList.remove('active'));
  el.classList.add('active');
  allFps.forEach(id=>{ const e=document.getElementById(id); if(e) e.style.display='none'; });
  const fp = document.getElementById('fp_'+name);
  if (fp) fp.style.display='block';
}
function closeAllTabs(){
  _openTab=null;
  document.getElementById('scFilterPanel').classList.remove('open');
  document.querySelectorAll('.sc-filter-tab').forEach(t=>t.classList.remove('active'));
}
document.addEventListener('click', e=>{
  if(!e.target.closest('#scFilterBar')&&!e.target.closest('#scFilterPanel')) closeAllTabs();
});

/* ══════════════════════════════════════════════════
   HELPERS
══════════════════════════════════════════════════ */
function updTabBadge(){
  const s=SC.state;
  // FH
  const fhEl=document.getElementById('tck_fh'),fhCnt=document.getElementById('tcnt_fh');
  if(fhEl){fhEl.classList.toggle('show',s.fundHouses.length>0); if(fhCnt){fhCnt.textContent=s.fundHouses.length;fhCnt.style.display=s.fundHouses.length?'inline-block':'none';}}
  // Cat
  const catEl=document.getElementById('tck_cat'),catCnt=document.getElementById('tcnt_cat');
  if(catEl){catEl.classList.toggle('show',s.categories.length>0); if(catCnt){catCnt.textContent=s.categories.length;catCnt.style.display=s.categories.length?'inline-block':'none';}}
  // LTCG
  const ltcgEl=document.getElementById('tck_ltcg'),ltcgCnt=document.getElementById('tcnt_ltcg');
  if(ltcgEl){const on=s.ltcgDays>0;ltcgEl.classList.toggle('show',on);if(ltcgCnt){ltcgCnt.textContent=s.ltcgDays===365?'1yr':s.ltcgDays===730?'2yr':s.ltcgDays===1095?'3yr':s.ltcgDays===1825?'5yr':'';ltcgCnt.style.display=on?'inline-block':'none';}}
  // Plan
  const planEl=document.getElementById('tck_plan'),planCnt=document.getElementById('tcnt_plan');
  const planOn=s.planType!=='all'||s.optionType!=='all';
  if(planEl)planEl.classList.toggle('show',planOn);
  if(planCnt){planCnt.style.display=planOn?'inline-block':'none'; planCnt.textContent=[s.planType!=='all'?s.planType:'',s.optionType!=='all'?s.optionType:''].filter(Boolean).join('+')||'';}
  // Lock
  const lockEl=document.getElementById('tck_lock');
  if(lockEl)lockEl.classList.toggle('show',s.hasLockin>=0);
  updFpSummary();
}

function updFpSummary(){
  const s=SC.state;const parts=[];
  if(s.fundHouses.length) parts.push(`${s.fundHouses.length} AMC`);
  if(s.categories.length) parts.push(`${s.categories.length} category`);
  if(s.ltcgDays>0) parts.push(`LTCG ${s.ltcgDays===365?'1yr':s.ltcgDays===730?'2yr':s.ltcgDays===1095?'3yr':'5yr'}`);
  if(s.planType!=='all') parts.push(s.planType==='direct'?'Direct':'Regular');
  if(s.optionType!=='all') parts.push(s.optionType==='growth'?'Growth':'IDCW');
  if(s.hasLockin>=0) parts.push(s.hasLockin===1?'Lock-in':'No Lock-in');
  document.getElementById('fpFilterSummary').textContent=parts.length?'Filters: '+parts.join(' · '):'No filters applied';
}

function updCatChecks(){
  document.querySelectorAll('#fpCatGrid input').forEach(cb=>{ cb.checked=SC.state.categories.includes(cb.dataset.cat); });
  document.querySelectorAll('.fp-type-pill').forEach(p=>p.classList.toggle('active',p.dataset.type===SC.state.quickType));
}

function updLtcgCounts(f){
  const total=Object.values(f).reduce((a,b)=>a+Number(b),0);
  const el_all=document.getElementById('lp_all'); if(el_all) el_all.textContent=total.toLocaleString('en-IN');
  [365,730,1095,1825].forEach(d=>{const e=document.getElementById('lp_'+d);if(e)e.textContent=f[d]?Number(f[d]).toLocaleString('en-IN'):'0';});
}

function updPillCounts(cats){
  const m={Equity:0,Debt:0,Commodity:0,'FoF/Intl':0,Solution:0,ELSS:0};
  const km={Equity:['equity','large cap','mid cap','small cap','flexi','multi cap','index','etf','hybrid','arbitrage','balanced','thematic','sectoral'],Debt:['debt','liquid','overnight','money market','gilt','credit','duration','floater','banking and psu','corporate bond'],Commodity:['gold','silver','commodit','precious metal'],'FoF/Intl':['fund of fund','overseas','international','foreign','global','world','us equity','nasdaq'],Solution:['retirement','children'],ELSS:['elss','tax sav']};
  cats.forEach(c=>{const cl=(c.category||'').toLowerCase();Object.entries(km).forEach(([t,kws])=>{if(kws.some(k=>cl.includes(k)))m[t]=(m[t]||0)+c.count;});});
  const im={'FoF/Intl':'FoFIntl'};
  Object.entries(m).forEach(([t,n])=>{
    const key=im[t]||t;
    ['ptc_','sn_'].forEach(pfx=>{const e=document.getElementById(pfx+key);if(e)e.textContent=n?n.toLocaleString('en-IN'):'0';});
  });
}

function updStatCards(cats){updPillCounts(cats);}

/* ══════════════════════════════════════════════════
   TABLE RENDER
══════════════════════════════════════════════════ */
function renderTable(funds,total){
  const el=document.getElementById('scRes');
  if(!funds.length){el.innerHTML='<div style="padding:60px;text-align:center;"><div style="font-size:40px;margin-bottom:12px;">🔍</div><div style="font-size:14px;font-weight:600;margin-bottom:6px;">No funds found</div><div style="font-size:12px;color:var(--text-muted);">Try adjusting your filters</div></div>';return;}
  const bm={Equity:'eq',Debt:'dt',Commodity:'cm','FoF/Intl':'fo',Solution:'sl'};
  window._scFunds=funds;
  const rows=funds.map((f,i)=>{
    const bc=bm[f.broad_type]||'ot';
    const ltcg=f.min_ltcg_days===365?'1yr':f.min_ltcg_days===730?'2yr':f.min_ltcg_days===1095?'3yr':f.min_ltcg_days===1825?'5yr':f.min_ltcg_days+'d';
    const nav=f.latest_nav
      ?'₹'+Number(f.latest_nav).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:4})
      :'<span style="color:var(--text-muted);font-size:10px;">NAV N/A</span>';
    const dd=f.drawdown_pct!==null&&f.drawdown_pct!==undefined
      ?(f.drawdown_pct<=0
        ?'<span style="color:#16a34a;font-size:10px;font-weight:700;">🏆 ATH</span>'
        :`<span style="color:${f.drawdown_pct>20?'#dc2626':f.drawdown_pct>10?'#d97706':'#16a34a'};font-size:11px;font-weight:700;">▼${f.drawdown_pct}%</span>`)
      :(f.latest_nav?'<span style="color:var(--text-muted);font-size:10px;">No peak data</span>':'<span style="color:var(--text-muted);">—</span>');
    const cs=f.category_short||'';
    const lockHtml=f.lock_in_days>0
      ?`<span class="b-lock">🔒 ${f.lock_in_days===1095?'3yr':f.lock_in_days===1825?'5yr':f.lock_in_days+'d'}</span>`
      :'<span style="color:var(--text-muted);font-size:11px;">—</span>';
    const safeName=(f.scheme_name||'').replace(/\\/g,'\\\\').replace(/'/g,"\\'");
    const safeFh=(f.fund_house||'').replace(/'/g,"\\'");

    // Expense ratio + exit load
    const erHtml = f.expense_ratio!==null && f.expense_ratio!==undefined
      ? `<div style="font-weight:700;font-size:12px;">${Number(f.expense_ratio).toFixed(2)}%</div>`
        + (f.exit_load_pct>0&&f.exit_load_days>0
          ? `<div style="font-size:10px;color:#d97706;margin-top:1px;" title="Exit load: ${f.exit_load_pct}% if sold within ${f.exit_load_days} days">⚠ ${f.exit_load_pct}% / ${f.exit_load_days}d</div>`
          : `<div style="font-size:10px;color:var(--text-muted);">No exit load</div>`)
      : '<span style="color:var(--text-muted);font-size:11px;">—</span>';

    // Risk badge
    const riskColors={'Low':'#15803d','Low to Moderate':'#16a34a','Moderate':'#d97706','Moderately High':'#ea580c','High':'#dc2626','Very High':'#9f1239'};
    const riskHtml = f.risk_level
      ? `<span style="font-size:9px;font-weight:700;padding:1px 5px;border-radius:4px;background:rgba(0,0,0,.06);color:${riskColors[f.risk_level]||'var(--text-muted)'};">${f.risk_level}</span>`
      : '';

    // Deduplicate category badge
    const showCat = cs && cs.toLowerCase() !== f.broad_type.toLowerCase()
                    && !cs.toLowerCase().startsWith(f.broad_type.toLowerCase());
    const catShortLabel = showCat ? (cs.length>14?cs.substring(0,13)+'…':cs) : '';

    return `<tr>
      <td>
        <div style="font-size:12px;font-weight:600;color:var(--accent);cursor:pointer;line-height:1.4;word-break:break-word;white-space:normal;" onclick="drOpen(${i})">${f.scheme_name||''}</div>
        <div style="font-size:10px;color:var(--text-muted);margin-top:2px;">${f.fund_house||''}</div>
      </td>
      <td style="width:150px;vertical-align:middle;padding:7px 8px;">
        <div style="display:flex;gap:3px;flex-wrap:nowrap;align-items:center;">
          <span class="sc-b b-${bc}" style="font-size:10px;padding:2px 6px;white-space:nowrap;">${f.broad_type}</span>
          ${catShortLabel?`<span style="font-size:10px;font-weight:600;padding:2px 5px;border-radius:4px;background:var(--bg-secondary);color:var(--text-muted);border:1px solid var(--border-color);white-space:nowrap;">${catShortLabel}</span>`:''}
          <span class="${f.plan_type==='direct'?'b-direct':'b-regular'}" style="font-size:10px;padding:2px 6px;white-space:nowrap;">${f.plan_type==='direct'?'Direct':'Regular'}</span>
          <span class="${f.option_type==='growth'?'b-growth':'b-idcw'}" style="font-size:10px;padding:2px 6px;white-space:nowrap;">${f.option_type==='growth'?'Growth':'IDCW'}</span>
        </div>
      </td>
      <td style="width:110px;"><div style="font-weight:700;font-size:13px;">${nav}</div>${f.latest_nav?`<div style="font-size:10px;color:var(--text-muted);">${f.latest_nav_date||''}</div>`:''}</td>
      <td style="width:70px;text-align:center;">${f.nav_change_pct!==null&&f.nav_change_pct!==undefined
        ?`<span style="font-size:11px;font-weight:700;color:${f.nav_change_pct>=0?'#16a34a':'#dc2626'};">${f.nav_change_pct>=0?'▲':'▼'}${Math.abs(f.nav_change_pct).toFixed(2)}%</span>`
        :'<span style="color:var(--text-muted);font-size:10px;">—</span>'}</td>
      <td style="width:110px;">${f.highest_nav
        ?`<div style="font-weight:700;font-size:13px;">₹${Number(f.highest_nav).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:4})}</div>
          <div style="font-size:10px;color:var(--text-muted);">${f.highest_nav_date||''}</div>`
        :'<span style="color:var(--text-muted);font-size:11px;">—</span>'
      }</td>
      <td style="width:76px;">${dd}</td>
      <td style="width:60px;"><span class="b-ltcg">${ltcg}</span></td>
      <td style="width:80px;">${lockHtml}</td>
      <td style="width:100px;">${erHtml}</td>
      <td style="width:60px;text-align:center;padding:6px 4px;">
        <button onclick="scAdd(${f.id},'${safeName}','${safeFh}');"
          style="display:inline-flex;align-items:center;justify-content:center;
                 width:48px;height:28px;font-size:11px;font-weight:700;
                 border:1.5px solid var(--accent);border-radius:6px;
                 background:rgba(37,99,235,.07);color:var(--accent);
                 cursor:pointer;transition:all .15s;white-space:nowrap;"
          onmouseover="this.style.background='var(--accent)';this.style.color='#fff'"
          onmouseout="this.style.background='rgba(37,99,235,.07)';this.style.color='var(--accent)'">
          +Add
        </button>
      </td>
    </tr>`;
  }).join('');
  el.innerHTML=`<table class="sc-table"><colgroup><col><col><col style="width:108px"><col style="width:76px"><col style="width:58px"><col style="width:76px"><col style="width:98px"><col style="width:60px"></colgroup>
    <thead><tr>
      <th onclick="scSort('name')" id="sh_name" style="cursor:pointer;user-select:none;">Fund · AMC <span class="sh-arr" id="sa_name"></span></th>
      <th style="cursor:default;">Type / Plan</th>
      <th onclick="scSort('nav_desc')" id="sh_nav" style="cursor:pointer;user-select:none;">NAV <span class="sh-arr" id="sa_nav"></span></th>
      <th style="cursor:default;width:70px;">1D Change</th>
      <th onclick="scSort('peak_nav')" id="sh_peak" style="cursor:pointer;user-select:none;">Peak NAV <span class="sh-arr" id="sa_peak"></span></th>
      <th onclick="scSort('drawdown')" id="sh_dd" style="cursor:pointer;user-select:none;">Drawdown <span class="sh-arr" id="sa_dd"></span></th>
      <th onclick="scSort('ltcg')" id="sh_ltcg" style="cursor:pointer;user-select:none;">LTCG <span class="sh-arr" id="sa_ltcg"></span></th>
      <th style="cursor:default;">Lock-in</th>
      <th onclick="scSort('expense')" id="sh_exp" style="cursor:pointer;user-select:none;">Expense Ratio <span class="sh-arr" id="sa_exp"></span></th>
      <th></th>
    </tr></thead>
    <tbody>${rows}</tbody></table>`;
}

function renderPag(page,pages,total){
  const pg=document.getElementById('scPag');pg.style.display=total>0?'flex':'none';if(!total)return;
  document.getElementById('pgPv').disabled=page<=1;document.getElementById('pgNx').disabled=page>=pages;
  const from=(page-1)*SC.state.perPage+1,to=Math.min(page*SC.state.perPage,total);
  document.getElementById('pgInfo').textContent=`${from.toLocaleString('en-IN')}–${to.toLocaleString('en-IN')} of ${total.toLocaleString('en-IN')}`;
  let h='';const btn=(p)=>`<button class="sc-pgnum${p===page?' active':''}" onclick="SC.goPage(${p})">${p}</button>`;
  const dot='<span style="padding:0 2px;color:var(--text-muted);">…</span>';
  if(pages<=7){for(let i=1;i<=pages;i++)h+=btn(i);}
  else{h+=btn(1);if(page>3)h+=dot;for(let i=Math.max(2,page-1);i<=Math.min(pages-1,page+1);i++)h+=btn(i);if(page<pages-2)h+=dot;h+=btn(pages);}
  document.getElementById('pgNums').innerHTML=h;
}

function renderChips(){
  const s=SC.state,chips=[];
  if(s.q) chips.push({l:`"${s.q}"`,r:()=>{SC.state.q='';document.getElementById('scQ').value='';SC.trigger();}});
  if(s.planType!=='all') chips.push({l:s.planType==='direct'?'Direct':'Regular',r:()=>{SC.state.planType='all';document.querySelector('input[name=planType]').checked=true;updTabBadge();SC.trigger();}});
  if(s.optionType!=='all') chips.push({l:s.optionType==='growth'?'Growth':'IDCW',r:()=>{SC.state.optionType='all';document.querySelector('input[name=optionType]').checked=true;updTabBadge();SC.trigger();}});
  if(s.ltcgDays>0) chips.push({l:`LTCG ${s.ltcgDays===365?'1yr':s.ltcgDays===730?'2yr':s.ltcgDays===1095?'3yr':'5yr'}`,r:()=>{SC.state.ltcgDays=0;document.querySelectorAll('.fp-ltcg-pill').forEach(p=>p.classList.toggle('active',+p.dataset.val===0));updTabBadge();SC.trigger();}});
  if(s.hasLockin>=0) chips.push({l:s.hasLockin===1?'Lock-in':'No Lock-in',r:()=>{SC.state.hasLockin=-1;document.querySelector('input[name=liF]').checked=true;updTabBadge();SC.trigger();}});
  s.fundHouses.forEach(fh=>chips.push({l:fh,r:()=>{SC.state.fundHouses=SC.state.fundHouses.filter(h=>h!==fh);document.querySelectorAll('#fpAmcGrid input').forEach(cb=>{if(cb.dataset.fh===fh)cb.checked=false;});updTabBadge();SC.trigger();}}));
  if(s.categories.length) chips.push({l:`${s.categories.length} categor${s.categories.length>1?'ies':'y'}`,r:()=>{SC.state.categories=[];SC.state.quickType='';updCatChecks();updTabBadge();SC.trigger();}});
  const bar=document.getElementById('scChips');SC._chips=chips;
  bar.innerHTML=chips.map((c,i)=>`<span class="sc-chip" onclick="SC._chips[${i}].r()">✕ ${c.l}</span>`).join('')+(chips.length?`<button class="sc-clear-all" onclick="SC.reset()">Clear all</button>`:'');
}

/* ══════════════════════════════════════════════════
   DRAWER
══════════════════════════════════════════════════ */
function drOpen(i){
  const f=window._scFunds[i]; if(!f)return;
  document.getElementById('drTitle').textContent=f.scheme_name;
  document.getElementById('drSub').textContent=(f.fund_house||'')+(f.category_short?' · '+f.category_short:'');
  document.getElementById('drAddBtn').onclick=()=>scAdd(f.id,f.scheme_name,f.fund_house);
  const ltcgLbl=f.min_ltcg_days===365?'1 Year':f.min_ltcg_days===730?'2 Years':f.min_ltcg_days===1095?'3 Years':f.min_ltcg_days===1825?'5 Years':f.min_ltcg_days+' days';
  const lockLbl=f.lock_in_days>0?(f.lock_in_days===1095?'3 Years (ELSS)':f.lock_in_days===1825?'5 Years (Ret.)':f.lock_in_days+' days'):'None';
  const navFmt=f.latest_nav?'₹'+Number(f.latest_nav).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:4}):'—';
  const peakFmt=f.highest_nav?'₹'+Number(f.highest_nav).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:4}):'—';
  const dd=f.drawdown_pct!==null&&f.drawdown_pct!==undefined?(f.drawdown_pct<=0?'<span style="color:#16a34a;font-weight:700;">🏆 At All-Time High</span>':`<span style="color:${f.drawdown_pct>20?'#dc2626':f.drawdown_pct>10?'#d97706':'#16a34a'};font-weight:700;">▼ ${f.drawdown_pct}% below ATH</span>`):'—';
  document.getElementById('drBody').innerHTML=`
    <div class="d-grid">
      <div class="d-box"><div class="d-lbl">Latest NAV</div><div class="d-val">${navFmt}</div><div class="d-sub">${f.latest_nav_date||''}</div></div>
      <div class="d-box"><div class="d-lbl">Peak NAV</div><div class="d-val">${peakFmt}</div><div class="d-sub">${f.highest_nav_date||''}</div></div>
      <div class="d-box"><div class="d-lbl">LTCG Period</div><div class="d-val" style="font-size:13px;">${ltcgLbl}</div><div class="d-sub">Hold for LTCG</div></div>
      <div class="d-box"><div class="d-lbl">Lock-in</div><div class="d-val" style="font-size:13px;color:${f.lock_in_days>0?'#b45309':'var(--text-primary)'};">${lockLbl}</div><div class="d-sub">${f.lock_in_days>0?'Mandatory':'No restrictions'}</div></div>
    </div>
    <div style="margin-bottom:12px;"><div class="d-sec">Drawdown</div><div style="font-size:13px;">${dd}</div></div>
    <div class="d-sec">Fund Details</div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;">
      ${[
        ['Fund House', f.fund_house||'—'],
        ['Category', f.category_short||f.category||'—'],
        ['Risk Level', f.risk_level ? `<span style="font-weight:700;color:${{'Low':'#15803d','Low to Moderate':'#16a34a','Moderate':'#d97706','Moderately High':'#ea580c','High':'#dc2626','Very High':'#9f1239'}[f.risk_level]||'var(--text-muted)'}">${f.risk_level}</span>` : '—'],
        ['Plan', f.plan_type==='direct'?'<span style="color:#16a34a;font-weight:700;">✅ Direct</span>':'<span style="color:#d97706;font-weight:700;">📦 Regular</span>'],
        ['Option', f.option_type==='growth'?'📈 Growth':'💰 IDCW'],
        ['Expense Ratio', f.expense_ratio!==null&&f.expense_ratio!==undefined ? `<strong>${Number(f.expense_ratio).toFixed(2)}%</strong> per annum` : '—'],
        ['Exit Load', f.exit_load_pct>0&&f.exit_load_days>0 ? `<span style="color:#d97706;font-weight:700;">⚠ ${f.exit_load_pct}% if sold within ${f.exit_load_days} days</span>` : f.exit_load_pct===0 ? '<span style="color:#15803d;">Nil</span>' : '—'],
        ['AUM', f.aum_crore ? '₹'+Number(f.aum_crore).toLocaleString('en-IN',{maximumFractionDigits:0})+' Cr' : '—'],
        ['Scheme Code', `<code style="font-size:11px;background:var(--bg-secondary);padding:2px 6px;border-radius:4px;">${f.scheme_code}</code>`],
      ].map(([k,v])=>`<tr style="border-bottom:1px solid var(--border-color);"><td style="padding:6px 0;color:var(--text-muted);font-weight:600;width:110px;font-size:11px;">${k}</td><td style="padding:6px 0;font-size:12px;">${v}</td></tr>`).join('')}
    </table>
    <div class="d-sec" style="margin-top:12px;">Tax Info</div>
    <div style="font-size:12px;line-height:1.8;color:var(--text-muted);background:var(--bg-secondary);padding:10px 12px;border-radius:8px;">
      ${f.broad_type==='Equity'?`<strong style="color:var(--text-primary);">Equity Fund</strong> — LTCG @ 12.5% (above ₹1.25L) after ${ltcgLbl}. STCG @ 20%.`:f.broad_type==='Debt'?`<strong style="color:var(--text-primary);">Debt Fund</strong> — Gains at slab rate (post Apr 2023).`:`<strong style="color:var(--text-primary);">${f.broad_type}</strong> — Check with your tax advisor.`}
      ${f.lock_in_days>0?`<br><br><strong style="color:#b45309;">⚠️ Lock-in:</strong> Cannot redeem for ${lockLbl}.`:''}
    </div>`;
  document.getElementById('scOv').classList.add('open');
  document.getElementById('scDr').classList.add('open');
}
function drClose(){document.getElementById('scOv').classList.remove('open');document.getElementById('scDr').classList.remove('open');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')drClose();});

function scAdd(id,name,house){
  try{sessionStorage.setItem('sc_add_fund_id',id);sessionStorage.setItem('sc_add_fund_name',name);}catch(e){}
  window.location.href=(window.APP_URL||window.WD?.appUrl||'')+'/templates/pages/mf_holdings.php?add_fund='+id+'&fund_name='+encodeURIComponent(name);
}

// ── Column header sort ────────────────────────────────────
const _sortMap = {
  peak_nav: { asc:'peak_nav_asc', desc:'peak_nav_desc' },
  name:     { asc:'name',     desc:'name_desc'  },
  nav_desc: { asc:'nav_asc',  desc:'nav_desc'   },
  dd:       { asc:'dd_asc',   desc:'dd_desc'    },
  ltcg:     { asc:'ltcg',     desc:'ltcg_desc'  },
  expense:  { asc:'expense',  desc:'expense_desc'},
};
const _sortColMap = {
  peak_nav_asc:'peak_nav', peak_nav_desc:'peak_nav',
  name:'name', nav_desc:'nav_desc', nav_asc:'nav_desc',
  ltcg:'ltcg', ltcg_desc:'ltcg', dd_asc:'dd', dd_desc:'dd',
  expense:'expense', expense_desc:'expense', name_desc:'name',
};

function scSort(col) {
  const cur = SC.state.sort;
  const colGroup = _sortColMap[cur] || cur;
  // Toggle: if already on this col, flip direction
  let newSort;
  if (colGroup === col || cur === col) {
    // already on this col — toggle
    if (cur.endsWith('_desc') || cur === 'nav_desc') {
      newSort = col === 'nav_desc' ? 'nav_asc' : col;
    } else {
      newSort = col === 'nav_desc' ? 'nav_desc' : col + '_desc';
    }
  } else {
    newSort = col === 'nav_desc' ? 'nav_desc' : col;
  }
  SC.state.sort = newSort;
  updSortHeaders(newSort);
  // Sync the sort select
  const sel = document.getElementById('scSort');
  if(sel && ['name','nav_desc','nav_asc','ltcg','house'].includes(newSort)) sel.value = newSort;
  SC.trigger(false);
}

function updSortHeaders(sort) {
  const cols = ['name','nav','dd','ltcg','exp'];
  cols.forEach(col => {
    const th = document.getElementById('sh_'+col);
    if (!th) return;
    th.classList.remove('sort-asc','sort-desc');
  });
  const colGroup = _sortColMap[sort] || sort.replace('_desc','');
  const isDesc = sort.endsWith('_desc') || sort === 'nav_desc';
  const thId = {name:'sh_name', nav_desc:'sh_nav', nav_asc:'sh_nav', ltcg:'sh_ltcg', ltcg_desc:'sh_ltcg', dd_asc:'sh_dd', dd_desc:'sh_dd', expense:'sh_exp', expense_desc:'sh_exp', name_desc:'sh_name', peak_nav_asc:'sh_peak', peak_nav_desc:'sh_peak'};
  const th = document.getElementById(thId[sort] || 'sh_'+colGroup);
  if (th) th.classList.add(isDesc ? 'sort-desc' : 'sort-asc');
}

/* ── Expense ratio filter ──────────────────────────────── */
SC.setExpense = function() {
  const mn = parseFloat(document.getElementById('fpExpMin')?.value);
  const mx = parseFloat(document.getElementById('fpExpMax')?.value);
  const ht = document.getElementById('fpHasTer')?.checked;
  this.state.expMin = isNaN(mn) ? null : mn;
  this.state.expMax = isNaN(mx) ? null : mx;
  this.state.hasTer = ht || false;
  const active = this.state.expMin!==null || this.state.expMax!==null || this.state.hasTer;
  const tck = document.getElementById('tck_exp');
  if(tck) tck.classList.toggle('show', active);
  const cnt = document.getElementById('tcnt_exp');
  if(cnt) { cnt.style.display = active?'inline-block':'none'; cnt.textContent='✓'; }
  updTabBadge();
};

SC.setExpPreset = function(mn, mx) {
  const minEl = document.getElementById('fpExpMin');
  const maxEl = document.getElementById('fpExpMax');
  if(minEl) minEl.value = mn > 0 ? mn : '';
  if(maxEl) maxEl.value = mx;
  this.setExpense();
};



/* ── Export CSV ─────────────────────────────────────────── */
function scExportCSV() {
  const funds = window._scFunds || [];
  if (!funds.length) { alert('No funds to export. Run a search first.'); return; }

  const headers = ['Scheme Name','Fund House','Category','Type','Plan','Option','NAV','NAV Date',
    'Peak NAV','Peak Date','1D Change%','Drawdown%','LTCG','Lock-in Days','Expense Ratio%','Scheme Code'];
  const rows = funds.map(f => [
    `"${(f.scheme_name||'').replace(/"/g,'""')}"`,
    `"${(f.fund_house||'').replace(/"/g,'""')}"`,
    `"${(f.category_short||'').replace(/"/g,'""')}"`,
    f.broad_type,
    f.plan_type==='direct'?'Direct':'Regular',
    f.option_type==='growth'?'Growth':'IDCW',
    f.latest_nav||'',
    f.latest_nav_date||'',
    f.highest_nav||'',
    f.highest_nav_date||'',
    f.nav_change_pct!==null?f.nav_change_pct:'',
    f.drawdown_pct!==null?f.drawdown_pct:'',
    f.min_ltcg_days===365?'1yr':f.min_ltcg_days===730?'2yr':f.min_ltcg_days===1095?'3yr':f.min_ltcg_days+'d',
    f.lock_in_days||0,
    f.expense_ratio!==null?f.expense_ratio:'',
    f.scheme_code||''
  ].join(','));

  const csv  = [headers.join(','), ...rows].join('
');
  const blob = new Blob([csv], {type:'text/csv'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `wealthdash_funds_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// Init immediately — works both on fresh load and back-navigation
// APP_URL from WD object (set in layout.php) or fallback
window._SCBASE = window.WD?.appUrl || window.APP_URL || '';

function _scInit() {
  SC.fetch();
  updSortHeaders('name');
}

// If DOM already ready (back navigation), run immediately
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', _scInit);
} else {
  // DOM already loaded — run on next tick
  setTimeout(_scInit, 0);
}
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>