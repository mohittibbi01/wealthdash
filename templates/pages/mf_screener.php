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

/* ─── View tabs (All Funds / Top Performers) ─────────────────── */
.sc-view-tabs { display:flex;gap:0;border-bottom:2px solid var(--border-color);margin-bottom:4px;flex-shrink:0; }
.sc-view-tab { padding:8px 18px;font-size:12px;font-weight:700;cursor:pointer;border-bottom:2.5px solid transparent;margin-bottom:-2px;color:var(--text-muted);transition:all .15s;white-space:nowrap; }
.sc-view-tab:hover { color:var(--text-primary); }
.sc-view-tab.active { color:var(--accent);border-bottom-color:var(--accent); }

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

/* ─── Top Performers view ───────────────────────────────────── */
.tp-wrap { padding:16px;overflow-y:auto;flex:1; }
.tp-period-bar { display:flex;gap:6px;margin-bottom:16px;align-items:center; }
.tp-period-btn { padding:5px 14px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);font-size:12px;font-weight:700;cursor:pointer;color:var(--text-muted);transition:all .15s; }
.tp-period-btn:hover { border-color:var(--accent);color:var(--accent); }
.tp-period-btn.tp-active { background:var(--accent);color:#fff;border-color:var(--accent); }
.tp-section { margin-bottom:20px; }
.tp-section-title { font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);margin-bottom:8px;padding-bottom:4px;border-bottom:1px solid var(--border-color); }
.tp-card { display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:7px;background:var(--bg-secondary);border:1px solid var(--border-color);margin-bottom:5px;cursor:pointer;transition:all .15s; }
.tp-card:hover { border-color:var(--accent);background:rgba(37,99,235,.04); }
.tp-rank { font-size:13px;font-weight:800;color:var(--text-muted);width:18px;text-align:center;flex-shrink:0; }
.tp-info { flex:1;min-width:0; }
.tp-name { font-size:12px;font-weight:600;color:var(--text-primary);white-space:nowrap;overflow:hidden;text-overflow:ellipsis; }
.tp-house { font-size:10px;color:var(--text-muted); }
.tp-ret { font-size:15px;font-weight:800;min-width:60px;text-align:right; }

/* ─── Compare bar (floating bottom) ────────────────────────── */
.cmp-bar { position:fixed;bottom:0;left:0;right:0;background:var(--accent);color:#fff;z-index:800;padding:10px 24px;display:none;align-items:center;gap:12px;box-shadow:0 -4px 20px rgba(37,99,235,.3); }
.cmp-bar.visible { display:flex; }
.cmp-fund-chip { background:rgba(255,255,255,.2);border-radius:20px;padding:3px 10px;font-size:12px;font-weight:600;display:flex;align-items:center;gap:5px; }
.cmp-fund-chip button { background:none;border:none;color:#fff;cursor:pointer;font-size:14px;line-height:1;padding:0;opacity:.7; }
.cmp-fund-chip button:hover { opacity:1; }
.cmp-actions { margin-left:auto;display:flex;gap:8px; }
.cmp-go-btn { padding:6px 18px;border-radius:6px;background:#fff;color:var(--accent);font-size:13px;font-weight:800;border:none;cursor:pointer;transition:all .15s; }
.cmp-go-btn:hover { background:#e0e7ff; }
.cmp-clear-btn { padding:6px 14px;border-radius:6px;background:rgba(255,255,255,.15);color:#fff;font-size:12px;font-weight:700;border:none;cursor:pointer; }

/* ─── Compare modal ─────────────────────────────────────────── */
.cmp-modal-ov { display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center; }
.cmp-modal-ov.open { display:flex; }
.cmp-modal { background:var(--bg-card);border-radius:12px;width:95vw;max-width:900px;max-height:88vh;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 20px 60px rgba(0,0,0,.25); }
.cmp-modal-hdr { padding:14px 20px;border-bottom:1px solid var(--border-color);display:flex;align-items:center;justify-content:space-between;flex-shrink:0; }
.cmp-modal-body { overflow-y:auto;flex:1; }
.cmp-table { width:100%;border-collapse:collapse;font-size:13px; }
.cmp-table th { padding:10px 14px;background:var(--bg-secondary);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);text-align:left;border-bottom:2px solid var(--border-color); }
.cmp-table th:not(:first-child) { text-align:center; }
.cmp-table td { padding:10px 14px;border-bottom:1px solid var(--border-color);vertical-align:middle; }
.cmp-table td:not(:first-child) { text-align:center; }
.cmp-table tr:hover td { background:rgba(37,99,235,.03); }
.cmp-row-label { font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase; }
.cmp-best { background:rgba(22,163,74,.1);border-radius:4px;padding:2px 6px;color:#15803d;font-weight:700; }

/* ─── Alert modal ───────────────────────────────────────────── */
.alert-modal-ov { display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1100;align-items:center;justify-content:center; }
.alert-modal-ov.open { display:flex; }
.alert-modal { background:var(--bg-card);border-radius:12px;width:380px;max-width:95vw;padding:20px;box-shadow:0 20px 60px rgba(0,0,0,.25); }
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
.dr-period-btn{padding:3px 9px;border-radius:5px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-muted);font-size:11px;font-weight:700;cursor:pointer;transition:all .15s;}
.dr-period-btn:hover{border-color:var(--accent);color:var(--accent);}
.dr-period-btn.dr-active{background:var(--accent);color:#fff;border-color:var(--accent);}
@keyframes spin{to{transform:rotate(360deg);}}
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
  <div class="sc-filter-tab" data-tab="aum" onclick="toggleTab('aum',this)">
    <span class="tab-check" id="tck_aum">✓</span>
    AUM
    <span class="tab-cnt" id="tcnt_aum" style="display:none;"></span>
  </div>
  <div class="sc-filter-tab" data-tab="risk" onclick="toggleTab('risk',this)">
    <span class="tab-check" id="tck_risk">✓</span>
    RISK LEVEL
    <span class="tab-cnt" id="tcnt_risk" style="display:none;"></span>
  </div>
  <div class="sc-filter-tab" data-tab="returns" onclick="toggleTab('returns',this)">
    <span class="tab-check" id="tck_ret">✓</span>
    RETURNS
    <span class="tab-cnt" id="tcnt_ret" style="display:none;"></span>
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

  <!-- AUM panel (t63) -->
  <div id="fp_aum" style="display:none;">
    <div class="fp-section-title">AUM RANGE (CRORES)</div>
    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;">
      <div style="display:flex;align-items:center;gap:6px;">
        <label style="font-size:12px;color:var(--text-muted);font-weight:600;">Min ₹</label>
        <input type="number" id="fpAumMin" min="0" step="100" placeholder="0"
          style="width:90px;padding:5px 8px;font-size:12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);"
          oninput="SC.setAum()"> Cr
      </div>
      <span style="color:var(--text-muted);">—</span>
      <div style="display:flex;align-items:center;gap:6px;">
        <label style="font-size:12px;color:var(--text-muted);font-weight:600;">Max ₹</label>
        <input type="number" id="fpAumMax" min="0" step="100" placeholder="any"
          style="width:90px;padding:5px 8px;font-size:12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);"
          oninput="SC.setAum()"> Cr
      </div>
    </div>
    <div class="fp-radio-group">
      <span class="fp-type-pill" onclick="SC.setAumPreset(0,500)">Small (&lt;500 Cr)</span>
      <span class="fp-type-pill" onclick="SC.setAumPreset(500,2000)">Mid (500–2K Cr)</span>
      <span class="fp-type-pill" onclick="SC.setAumPreset(2000,10000)">Large (2K–10K Cr)</span>
      <span class="fp-type-pill" onclick="SC.setAumPreset(10000,null)">Giant (&gt;10K Cr)</span>
    </div>
  </div>

  <!-- RISK LEVEL panel (t65) -->
  <div id="fp_risk" style="display:none;">
    <div class="fp-section-title">RISK LEVEL</div>
    <div class="fp-radio-group" style="flex-direction:column;gap:6px;">
      ${[
        ['Low',               '#15803d', 'bg:#dcfce7'],
        ['Low to Moderate',   '#16a34a', 'bg:#f0fdf4'],
        ['Moderate',          '#d97706', 'bg:#fefce8'],
        ['Moderately High',   '#ea580c', 'bg:#fff7ed'],
        ['High',              '#dc2626', 'bg:#fef2f2'],
        ['Very High',         '#9f1239', 'bg:#fff1f2'],
      ].map(([r,c,b])=>`<label class="fp-radio-pill" style="display:flex;align-items:center;gap:6px;">
        <input type="checkbox" class="fp-risk-cb" value="${r}" onchange="SC.setRisk()">
        <span style="color:${c};font-weight:700;">● ${r}</span>
      </label>`).join('')}
    </div>
  </div>

  <!-- RETURNS FILTER panel (t66) -->
  <div id="fp_returns" style="display:none;">
    <div class="fp-section-title">MINIMUM RETURNS</div>
    <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:10px;">
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:5px;">1Y Return ≥</div>
        <select id="fpRet1y" onchange="SC.setReturns()" style="width:100%;padding:5px 8px;font-size:12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);">
          <option value="">Any</option>
          <option value="5">+5%</option>
          <option value="10">+10%</option>
          <option value="15">+15%</option>
          <option value="20">+20%</option>
          <option value="25">+25%</option>
          <option value="30">+30%</option>
        </select>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:5px;">3Y CAGR ≥</div>
        <select id="fpRet3y" onchange="SC.setReturns()" style="width:100%;padding:5px 8px;font-size:12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);">
          <option value="">Any</option>
          <option value="5">+5%</option>
          <option value="10">+10%</option>
          <option value="12">+12%</option>
          <option value="15">+15%</option>
          <option value="20">+20%</option>
        </select>
      </div>
      <div>
        <div style="font-size:11px;font-weight:700;color:var(--text-muted);margin-bottom:5px;">5Y CAGR ≥</div>
        <select id="fpRet5y" onchange="SC.setReturns()" style="width:100%;padding:5px 8px;font-size:12px;border-radius:6px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-primary);">
          <option value="">Any</option>
          <option value="5">+5%</option>
          <option value="10">+10%</option>
          <option value="12">+12%</option>
          <option value="15">+15%</option>
          <option value="20">+20%</option>
        </select>
      </div>
    </div>
    <div class="fp-radio-group">
      <span class="fp-type-pill" onclick="SC.setRetPreset(10,10,10)">🟢 All &gt;10%</span>
      <span class="fp-type-pill" onclick="SC.setRetPreset(15,12,10)">🏆 Top Performers</span>
      <span class="fp-type-pill" onclick="SC.setRetPreset(20,15,12)">⭐ Exceptional</span>
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

<!-- View Tabs: All Funds / Top Performers -->
<div class="sc-view-tabs">
  <div class="sc-view-tab active" id="vtab_all" onclick="switchView('all')">📋 All Funds</div>
  <div class="sc-view-tab" id="vtab_top" onclick="switchView('top')">🏆 Top Performers</div>
</div>

<!-- Search + results bar -->
<div class="sc-search-bar" id="scSearchBar">
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
  <button onclick="exportScreenerCsv()"
    style="display:flex;align-items:center;gap:5px;padding:6px 12px;border-radius:7px;border:1.5px solid var(--border-color);background:var(--bg-secondary);color:var(--text-muted);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s;white-space:nowrap;"
    onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)'"
    onmouseout="this.style.borderColor='var(--border-color)';this.style.color='var(--text-muted)'"
    title="Export filtered funds to CSV">
    <svg width="13" height="13" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
    Export CSV
  </button>
</div>

<!-- Active chips -->
<div class="sc-chips" id="scChips"></div>

<!-- Results -->
<div class="sc-results-wrap" id="scResultsWrap">
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

<!-- Top Performers panel (hidden by default) -->
<div class="sc-results-wrap" id="scTopWrap" style="display:none;">
  <div class="tp-wrap" id="scTopBody">
    <div style="display:flex;align-items:center;justify-content:center;padding:60px;"><div class="spinner"></div></div>
  </div>
</div>

<!-- Compare floating bar -->
<div class="cmp-bar" id="cmpBar">
  <span style="font-size:13px;font-weight:700;white-space:nowrap;">Compare:</span>
  <div id="cmpChips" style="display:flex;gap:6px;flex-wrap:wrap;"></div>
  <div class="cmp-actions">
    <button class="cmp-go-btn" onclick="openCompareModal()">Compare →</button>
    <button class="cmp-clear-btn" onclick="clearCompare()">✕ Clear</button>
  </div>
</div>

<!-- Compare modal -->
<div class="cmp-modal-ov" id="cmpModalOv" onclick="if(event.target===this)closeCompareModal()">
  <div class="cmp-modal">
    <div class="cmp-modal-hdr">
      <span style="font-size:14px;font-weight:800;">⚖️ Fund Comparison</span>
      <button onclick="closeCompareModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-muted);">✕</button>
    </div>
    <div class="cmp-modal-body" id="cmpModalBody">
      <div style="padding:40px;text-align:center;color:var(--text-muted);">Select funds to compare</div>
    </div>
  </div>
</div>

<!-- Price Alert modal -->
<div class="alert-modal-ov" id="alertModalOv" onclick="if(event.target===this)closeAlertModal()">
  <div class="alert-modal">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
      <span style="font-size:14px;font-weight:800;">🔔 Set Price Alert</span>
      <button onclick="closeAlertModal()" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-muted);">✕</button>
    </div>
    <div id="alertFundName" style="font-size:12px;font-weight:600;color:var(--text-muted);margin-bottom:14px;"></div>
    <div style="margin-bottom:12px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;">Alert Type</div>
      <div style="display:flex;gap:8px;">
        <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
          <input type="radio" name="alertType" value="above" id="alertAbove" checked> NAV goes <strong>above</strong>
        </label>
        <label style="display:flex;align-items:center;gap:5px;font-size:13px;cursor:pointer;">
          <input type="radio" name="alertType" value="below" id="alertBelow"> NAV goes <strong>below</strong>
        </label>
      </div>
    </div>
    <div style="margin-bottom:16px;">
      <div style="font-size:11px;font-weight:700;text-transform:uppercase;color:var(--text-muted);margin-bottom:6px;">Target NAV (₹)</div>
      <div style="display:flex;align-items:center;gap:8px;">
        <span style="font-size:16px;font-weight:700;color:var(--text-muted);">₹</span>
        <input type="number" id="alertTargetNav" step="0.01" min="0"
          style="flex:1;padding:9px 12px;border:1.5px solid var(--border-color);border-radius:8px;font-size:15px;font-weight:700;background:var(--bg-secondary);color:var(--text-primary);outline:none;"
          placeholder="Enter target NAV">
      </div>
      <div id="alertCurrentNav" style="font-size:11px;color:var(--text-muted);margin-top:5px;"></div>
    </div>
    <div style="display:flex;gap:8px;">
      <button onclick="closeAlertModal()" style="flex:1;padding:9px;border-radius:8px;border:1.5px solid var(--border-color);background:none;cursor:pointer;font-weight:600;color:var(--text-muted);">Cancel</button>
      <button onclick="saveAlert()" style="flex:1;padding:9px;border-radius:8px;border:none;background:var(--accent);color:#fff;cursor:pointer;font-weight:700;font-size:13px;">🔔 Set Alert</button>
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
    <button class="btn btn-ghost btn-sm" id="drAlertBtn" onclick="" style="color:#f59e0b;border-color:#fcd34d;">🔔 Alert</button>
    <button class="btn btn-ghost btn-sm" onclick="drClose()">Close</button>
  </div>
</div>

<script>
/* ══════════════════════════════════════════════════
   STATE
══════════════════════════════════════════════════ */
const SC = {
  state:{ q:'',categories:[],fundHouses:[],optionType:'all',planType:'all',ltcgDays:0,hasLockin:-1,expMin:null,expMax:null,hasTer:false,sort:'name',sortDir:'asc',page:1,perPage:50,quickType:'',aumMin:null,aumMax:null,riskLevels:[],retMin1y:null,retMin3y:null,retMin5y:null },
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

  // t63: AUM filter
  setAum(){
    this.state.aumMin = parseFloat(document.getElementById('fpAumMin')?.value)||null;
    this.state.aumMax = parseFloat(document.getElementById('fpAumMax')?.value)||null;
    updTabBadge();
  },
  setAumPreset(min,max){
    if(document.getElementById('fpAumMin')) document.getElementById('fpAumMin').value = min||'';
    if(document.getElementById('fpAumMax')) document.getElementById('fpAumMax').value = max||'';
    this.state.aumMin=min||null; this.state.aumMax=max||null; updTabBadge();
  },

  // t65: Risk level filter
  setRisk(){
    this.state.riskLevels = Array.from(document.querySelectorAll('.fp-risk-cb:checked')).map(cb=>cb.value);
    updTabBadge();
  },

  // t66: Returns filter
  setReturns(){
    const v1=document.getElementById('fpRet1y')?.value; this.state.retMin1y=v1?parseFloat(v1):null;
    const v3=document.getElementById('fpRet3y')?.value; this.state.retMin3y=v3?parseFloat(v3):null;
    const v5=document.getElementById('fpRet5y')?.value; this.state.retMin5y=v5?parseFloat(v5):null;
    updTabBadge();
  },
  setRetPreset(r1,r3,r5){
    if(document.getElementById('fpRet1y')) document.getElementById('fpRet1y').value=r1;
    if(document.getElementById('fpRet3y')) document.getElementById('fpRet3y').value=r3;
    if(document.getElementById('fpRet5y')) document.getElementById('fpRet5y').value=r5;
    this.state.retMin1y=r1; this.state.retMin3y=r3; this.state.retMin5y=r5; updTabBadge();
  },

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
    this.state={q:'',categories:[],fundHouses:[],optionType:'all',planType:'all',ltcgDays:0,hasLockin:-1,expMin:null,expMax:null,hasTer:false,sort:'name',page:1,perPage:50,quickType:'',aumMin:null,aumMax:null,riskLevels:[],retMin1y:null,retMin3y:null,retMin5y:null};
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
    // Reset new filters
    ['fpAumMin','fpAumMax','fpRet1y','fpRet3y','fpRet5y'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});
    document.querySelectorAll('.fp-risk-cb').forEach(cb=>cb.checked=false);
    ['tck_aum','tck_risk','tck_ret'].forEach(id=>{const e=document.getElementById(id);if(e)e.classList.remove('show');});
    ['tcnt_aum','tcnt_risk','tcnt_ret'].forEach(id=>{const e=document.getElementById(id);if(e)e.style.display='none';});
    document.getElementById('fpFilterSummary').textContent='No filters applied';
    this.trigger();
  },

  buildParams(extra={}){
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
    if(s.aumMin!==null&&s.aumMin!==undefined) p.set('aum_min',s.aumMin);
    if(s.aumMax!==null&&s.aumMax!==undefined) p.set('aum_max',s.aumMax);
    if(s.riskLevels?.length) s.riskLevels.forEach(r=>p.append('risk_level[]',r));
    if(s.retMin1y!==null&&s.retMin1y!==undefined) p.set('ret_min_1y',s.retMin1y);
    if(s.retMin3y!==null&&s.retMin3y!==undefined) p.set('ret_min_3y',s.retMin3y);
    if(s.retMin5y!==null&&s.retMin5y!==undefined) p.set('ret_min_5y',s.retMin5y);
    p.set('sort',s.sort);p.set('page',s.page);p.set('per_page',s.perPage);
    Object.entries(extra).forEach(([k,v])=>p.set(k,v));
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
      checkPriceAlerts(d.data);
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
  const allFps = ['fp_fund_house','fp_category','fp_ltcg','fp_plan','fp_lockin','fp_expense','fp_aum','fp_risk','fp_returns'];
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
  // AUM (t63)
  const aumEl=document.getElementById('tck_aum'),aumCnt=document.getElementById('tcnt_aum');
  const aumOn=s.aumMin!==null||s.aumMax!==null;
  if(aumEl)aumEl.classList.toggle('show',aumOn);
  if(aumCnt){aumCnt.style.display=aumOn?'inline-block':'none';aumCnt.textContent=aumOn?'✓':'';}
  // Risk (t65)
  const riskEl=document.getElementById('tck_risk'),riskCnt=document.getElementById('tcnt_risk');
  const riskOn=(s.riskLevels||[]).length>0;
  if(riskEl)riskEl.classList.toggle('show',riskOn);
  if(riskCnt){riskCnt.textContent=(s.riskLevels||[]).length||'';riskCnt.style.display=riskOn?'inline-block':'none';}
  // Returns (t66)
  const retEl=document.getElementById('tck_ret'),retCnt=document.getElementById('tcnt_ret');
  const retOn=s.retMin1y!==null||s.retMin3y!==null||s.retMin5y!==null;
  if(retEl)retEl.classList.toggle('show',retOn);
  if(retCnt){retCnt.style.display=retOn?'inline-block':'none';retCnt.textContent=retOn?'✓':'';}
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
  if(s.aumMin!==null||s.aumMax!==null) parts.push(`AUM ${s.aumMin||0}–${s.aumMax||'∞'} Cr`);
  if((s.riskLevels||[]).length) parts.push(`Risk: ${s.riskLevels.join('/')}`);
  if(s.retMin1y!==null) parts.push(`1Y≥${s.retMin1y}%`);
  if(s.retMin3y!==null) parts.push(`3Y≥${s.retMin3y}%`);
  if(s.retMin5y!==null) parts.push(`5Y≥${s.retMin5y}%`);
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
  // Returns cell renderer
  function _retCell(v) {
    if (v === null || v === undefined) return '<span style="color:var(--text-muted);font-size:11px;">—</span>';
    const color = v >= 15 ? '#15803d' : v >= 10 ? '#16a34a' : v >= 0 ? '#d97706' : '#dc2626';
    const sign  = v > 0 ? '+' : '';
    return `<span style="font-size:12px;font-weight:700;color:${color};">${sign}${v.toFixed(1)}%</span>`;
  }
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
    const exitHtml = f.exit_load_pct>0&&f.exit_load_days>0
      ? `<div style="font-size:10px;color:#d97706;margin-top:1px;" title="Exit load: ${f.exit_load_pct}% if sold within ${f.exit_load_days} days">⚠ ${f.exit_load_pct}% / ${f.exit_load_days}d</div>`
      : f.exit_load_pct===0
        ? `<div style="font-size:10px;color:#16a34a;margin-top:1px;">✓ Nil exit load</div>`
        : '';
    const erHtml = f.expense_ratio!==null && f.expense_ratio!==undefined
      ? `<div style="font-weight:700;font-size:12px;">${Number(f.expense_ratio).toFixed(2)}%</div>` + exitHtml
      : (exitHtml || '<span style="color:var(--text-muted);font-size:11px;">—</span>');

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
      <td style="width:80px;text-align:center;">${f.nav_change_pct!==null&&f.nav_change_pct!==undefined&&f.latest_nav
        ?(()=>{
            const chg = f.latest_nav - (f.latest_nav / (1 + f.nav_change_pct/100));
            const color = f.nav_change_pct>=0 ? '#16a34a' : '#dc2626';
            const arr   = f.nav_change_pct>=0 ? '▲' : '▼';
            return `<span style="font-size:11px;font-weight:700;color:${color};line-height:1.3;">${arr}${Math.abs(f.nav_change_pct).toFixed(2)}%</span>`
                 + `<div style="font-size:10px;font-weight:600;color:${color};margin-top:1px;">${arr}₹${Math.abs(chg).toFixed(4)}</div>`;
          })()
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
      <td style="width:70px;text-align:center;">${_retCell(f.returns_1y)}</td>
      <td style="width:70px;text-align:center;">${_retCell(f.returns_3y)}</td>
      <td style="width:70px;text-align:center;">${_retCell(f.returns_5y)}</td>
      <td style="width:60px;text-align:center;padding:6px 4px;">
        <div style="display:flex;flex-direction:column;align-items:center;gap:4px;">
        <button onclick="scAdd(${f.id},'${safeName}','${safeFh}');"
          style="display:inline-flex;align-items:center;justify-content:center;
                 width:48px;height:26px;font-size:11px;font-weight:700;
                 border:1.5px solid var(--accent);border-radius:6px;
                 background:rgba(37,99,235,.07);color:var(--accent);
                 cursor:pointer;transition:all .15s;white-space:nowrap;"
          onmouseover="this.style.background='var(--accent)';this.style.color='#fff'"
          onmouseout="this.style.background='rgba(37,99,235,.07)';this.style.color='var(--accent)'">
          +Add
        </button>
        <button onclick="toggleCompare(${f.id},'${safeName}')" id="cmpBtn_${f.id}"
          style="display:inline-flex;align-items:center;justify-content:center;
                 width:48px;height:22px;font-size:10px;font-weight:700;
                 border:1.5px solid var(--border-color);border-radius:6px;
                 background:var(--bg-secondary);color:var(--text-muted);
                 cursor:pointer;transition:all .15s;white-space:nowrap;"
          onmouseover="this.style.borderColor='#9333ea';this.style.color='#9333ea'"
          onmouseout="resetCmpBtnStyle(${f.id})">
          ⚖ Cmp
        </button>
        </div>
      </td>
    </tr>`;
  }).join('');
  el.innerHTML=`<table class="sc-table"><colgroup><col><col><col style="width:108px"><col style="width:76px"><col style="width:58px"><col style="width:76px"><col style="width:60px"><col style="width:70px"><col style="width:82px"><col style="width:70px"><col style="width:70px"><col style="width:70px"><col style="width:52px"></colgroup>
    <thead><tr>
      <th onclick="scSort('name')" id="sh_name" style="cursor:pointer;user-select:none;">Fund · AMC <span class="sh-arr" id="sa_name"></span></th>
      <th style="cursor:default;">Type / Plan</th>
      <th onclick="scSort('nav_desc')" id="sh_nav" style="cursor:pointer;user-select:none;">NAV <span class="sh-arr" id="sa_nav"></span></th>
      <th style="cursor:default;width:80px;">1D Change</th>
      <th onclick="scSort('peak_nav')" id="sh_peak" style="cursor:pointer;user-select:none;">Peak NAV <span class="sh-arr" id="sa_peak"></span></th>
      <th onclick="scSort('drawdown')" id="sh_dd" style="cursor:pointer;user-select:none;">Drawdown <span class="sh-arr" id="sa_dd"></span></th>
      <th onclick="scSort('ltcg')" id="sh_ltcg" style="cursor:pointer;user-select:none;">LTCG <span class="sh-arr" id="sa_ltcg"></span></th>
      <th style="cursor:default;">Lock-in</th>
      <th onclick="scSort('expense')" id="sh_exp" style="cursor:pointer;user-select:none;">Exp% <span class="sh-arr" id="sa_exp"></span></th>
      <th onclick="scSort('ret1y_desc')" id="sh_r1" style="cursor:pointer;user-select:none;text-align:center;">1Y <span class="sh-arr" id="sa_r1"></span></th>
      <th onclick="scSort('ret3y_desc')" id="sh_r3" style="cursor:pointer;user-select:none;text-align:center;">3Y <span class="sh-arr" id="sa_r3"></span></th>
      <th onclick="scSort('ret5y_desc')" id="sh_r5" style="cursor:pointer;user-select:none;text-align:center;">5Y <span class="sh-arr" id="sa_r5"></span></th>
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
   DRAWER — Phase 5: NAV Chart + Returns + SIP Calculator
══════════════════════════════════════════════════ */
let _drChartInst = null;

function drOpen(i){
  const f=window._scFunds[i]; if(!f)return;
  drOpenFund(f);
}

// Open drawer with a fund object directly (from Top Performers or Compare)
function drOpenFund(f){
  if(!f)return;
  document.getElementById('drTitle').textContent=f.scheme_name;
  document.getElementById('drSub').textContent=(f.fund_house||'')+(f.category_short?' · '+f.category_short:'');
  document.getElementById('drAddBtn').onclick=()=>scAdd(f.id,f.scheme_name,f.fund_house);
  const ltcgLbl=f.min_ltcg_days===365?'1 Year':f.min_ltcg_days===730?'2 Years':f.min_ltcg_days===1095?'3 Years':f.min_ltcg_days===1825?'5 Years':f.min_ltcg_days+' days';
  const lockLbl=f.lock_in_days>0?(f.lock_in_days===1095?'3 Years (ELSS)':f.lock_in_days===1825?'5 Years (Ret.)':f.lock_in_days+' days'):'None';
  const navFmt=f.latest_nav?'₹'+Number(f.latest_nav).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:4}):'—';
  const peakFmt=f.highest_nav?'₹'+Number(f.highest_nav).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:4}):'—';
  const dd=f.drawdown_pct!==null&&f.drawdown_pct!==undefined?(f.drawdown_pct<=0?'<span style="color:#16a34a;font-weight:700;">🏆 At All-Time High</span>':`<span style="color:${f.drawdown_pct>20?'#dc2626':f.drawdown_pct>10?'#d97706':'#16a34a'};font-weight:700;">▼ ${f.drawdown_pct}% below ATH</span>`):'—';
  const riskColors={'Low':'#15803d','Low to Moderate':'#16a34a','Moderate':'#d97706','Moderately High':'#ea580c','High':'#dc2626','Very High':'#9f1239'};
  function retBadge(v,label){
    if(v===null||v===undefined) return `<div class="d-box"><div class="d-lbl">${label}</div><div class="d-val" style="color:var(--text-muted);font-size:13px;">—</div><div class="d-sub">No data</div></div>`;
    const color=v>=15?'#15803d':v>=10?'#16a34a':v>=0?'#d97706':'#dc2626';
    const sign=v>0?'+':'';
    return `<div class="d-box"><div class="d-lbl">${label}</div><div class="d-val" style="color:${color};">${sign}${v.toFixed(1)}%</div><div class="d-sub">p.a. CAGR</div></div>`;
  }
  const hasRet=f.returns_1y!==null||f.returns_3y!==null||f.returns_5y!==null;
  const retSec=hasRet?`
    <div class="d-sec">📈 Returns</div>
    <div class="d-grid" style="grid-template-columns:1fr 1fr 1fr;margin-bottom:14px;">
      ${retBadge(f.returns_1y,'1 Year')}${retBadge(f.returns_3y,'3 Year CAGR')}${retBadge(f.returns_5y,'5 Year CAGR')}
    </div>`:'';

  document.getElementById('drBody').innerHTML=`
    <div class="d-grid">
      <div class="d-box"><div class="d-lbl">Latest NAV</div><div class="d-val">${navFmt}</div><div class="d-sub">${f.latest_nav_date||''}</div></div>
      <div class="d-box"><div class="d-lbl">Peak NAV</div><div class="d-val">${peakFmt}</div><div class="d-sub">${f.highest_nav_date||''}</div></div>
      <div class="d-box"><div class="d-lbl">LTCG Period</div><div class="d-val" style="font-size:13px;">${ltcgLbl}</div><div class="d-sub">Hold for LTCG</div></div>
      <div class="d-box"><div class="d-lbl">Lock-in</div><div class="d-val" style="font-size:13px;color:${f.lock_in_days>0?'#b45309':'var(--text-primary)'};">${lockLbl}</div><div class="d-sub">${f.lock_in_days>0?'Mandatory':'No restrictions'}</div></div>
    </div>
    <div style="margin-bottom:12px;"><div class="d-sec">Drawdown</div><div style="font-size:13px;">${dd}</div></div>
    ${retSec}
    <div class="d-sec">📊 NAV History</div>
    <div style="display:flex;gap:5px;margin-bottom:8px;flex-wrap:wrap;">
      ${['1M','3M','6M','1Y','3Y','5Y','Max'].map(p=>`<button class="dr-period-btn${p==='1Y'?' dr-active':''}" onclick="drLoadChart(${f.id},'${p}',this)">${p}</button>`).join('')}
    </div>
    <div style="position:relative;height:160px;margin-bottom:2px;">
      <canvas id="drNavChart" style="height:160px;"></canvas>
      <div id="drChartLoader" style="display:none;position:absolute;inset:0;background:rgba(var(--bg-card-rgb,255,255,255),.85);align-items:center;justify-content:center;">
        <div style="width:22px;height:22px;border:3px solid var(--border-color);border-top-color:var(--accent);border-radius:50%;animation:spin .7s linear infinite;"></div>
      </div>
    </div>
    <div id="drChartInfo" style="font-size:11px;color:var(--text-muted);text-align:center;margin-bottom:14px;min-height:16px;"></div>
    <div class="d-sec">🧮 SIP Calculator</div>
    <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;margin-bottom:14px;">
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:10px;">
        <div>
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;">Monthly SIP (₹)</div>
          <input id="sipCalcAmt" type="number" value="5000" min="100" step="500"
            style="width:100%;padding:7px 9px;border:1.5px solid var(--border-color);border-radius:6px;font-size:13px;font-weight:600;background:var(--bg-card);color:var(--text-primary);outline:none;box-sizing:border-box;"
            oninput="drCalcSip(${f.returns_1y},${f.returns_3y},${f.returns_5y})">
        </div>
        <div>
          <div style="font-size:10px;font-weight:700;color:var(--text-muted);margin-bottom:4px;text-transform:uppercase;">Duration</div>
          <select id="sipCalcYrs"
            style="width:100%;padding:7px 9px;border:1.5px solid var(--border-color);border-radius:6px;font-size:13px;background:var(--bg-card);color:var(--text-primary);outline:none;box-sizing:border-box;"
            onchange="drCalcSip(${f.returns_1y},${f.returns_3y},${f.returns_5y})">
            <option value="1">1 Year</option>
            <option value="3" selected>3 Years</option>
            <option value="5">5 Years</option>
            <option value="10">10 Years</option>
            <option value="15">15 Years</option>
            <option value="20">20 Years</option>
          </select>
        </div>
      </div>
      <div id="sipCalcResult" style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;"></div>
    </div>
    <div class="d-sec">Fund Details</div>
    <table style="width:100%;font-size:12px;border-collapse:collapse;">
      ${[
        ['Fund House',f.fund_house||'—'],
        ['Category',f.category_short||f.category||'—'],
        ['Risk Level',f.risk_level?`<span style="font-weight:700;color:${riskColors[f.risk_level]||'var(--text-muted)'}">${f.risk_level}</span>`:'—'],
        ['Plan',f.plan_type==='direct'?'<span style="color:#16a34a;font-weight:700;">✅ Direct</span>':'<span style="color:#d97706;font-weight:700;">📦 Regular</span>'],
        ['Option',f.option_type==='growth'?'📈 Growth':'💰 IDCW'],
        ['Expense Ratio',f.expense_ratio!==null&&f.expense_ratio!==undefined?`<strong>${Number(f.expense_ratio).toFixed(2)}%</strong> per annum`:'—'],
        ['Exit Load',f.exit_load_pct>0&&f.exit_load_days>0?`<span style="color:#d97706;font-weight:700;">⚠ ${f.exit_load_pct}% if sold within ${f.exit_load_days} days</span>`:f.exit_load_pct===0?'<span style="color:#15803d;">Nil</span>':'—'],
        ['AUM',f.aum_crore?'₹'+Number(f.aum_crore).toLocaleString('en-IN',{maximumFractionDigits:0})+' Cr':'—'],
        ['Scheme Code',`<code style="font-size:11px;background:var(--bg-secondary);padding:2px 6px;border-radius:4px;">${f.scheme_code}</code>`],
      ].map(([k,v])=>`<tr style="border-bottom:1px solid var(--border-color);"><td style="padding:6px 0;color:var(--text-muted);font-weight:600;width:110px;font-size:11px;">${k}</td><td style="padding:6px 0;font-size:12px;">${v}</td></tr>`).join('')}
    </table>
    <div class="d-sec" style="margin-top:12px;">Tax Info</div>
    <div style="font-size:12px;line-height:1.8;color:var(--text-muted);background:var(--bg-secondary);padding:10px 12px;border-radius:8px;">
      ${f.broad_type==='Equity'?`<strong style="color:var(--text-primary);">Equity Fund</strong> — LTCG @ 12.5% (above ₹1.25L) after ${ltcgLbl}. STCG @ 20%.`:f.broad_type==='Debt'?`<strong style="color:var(--text-primary);">Debt Fund</strong> — Gains at slab rate (post Apr 2023).`:`<strong style="color:var(--text-primary);">${f.broad_type}</strong> — Check with your tax advisor.`}
      ${f.lock_in_days>0?`<br><br><strong style="color:#b45309;">⚠️ Lock-in:</strong> Cannot redeem for ${lockLbl}.`:''}
    </div>`;

  document.getElementById('scOv').classList.add('open');
  document.getElementById('scDr').classList.add('open');
  // Wire alert button
  const alertBtn = document.getElementById('drAlertBtn');
  if (alertBtn) alertBtn.onclick = () => openAlertModal(f.id, f.scheme_name, f.latest_nav);
  drLoadChart(f.id,'1Y',document.querySelector('.dr-period-btn.dr-active'));
  drCalcSip(f.returns_1y,f.returns_3y,f.returns_5y);
}

async function drLoadChart(fundId,period,btn){
  document.querySelectorAll('.dr-period-btn').forEach(b=>b.classList.remove('dr-active'));
  if(btn)btn.classList.add('dr-active');
  if(_drChartInst){_drChartInst.destroy();_drChartInst=null;}
  const loader=document.getElementById('drChartLoader');
  const infoEl=document.getElementById('drChartInfo');
  if(loader){loader.style.display='flex';}
  if(infoEl)infoEl.textContent='';
  const today=new Date();
  const toDate=today.toISOString().slice(0,10);
  const days={'1M':30,'3M':90,'6M':180,'1Y':365,'3Y':1095,'5Y':1825,'Max':3650}[period]||365;
  const from=new Date(today);from.setDate(from.getDate()-days);
  const fromDate=from.toISOString().slice(0,10);
  try{
    const appUrl=window.WD?.appUrl||window.APP_URL||'';
    const res=await fetch(`${appUrl}/api/mutual_funds/mf_nav_history.php?fund_id=${fundId}&from=${fromDate}&to=${toDate}`,{headers:{'X-Requested-With':'XMLHttpRequest'}});
    const json=await res.json();
    if(loader){loader.style.display='none';}
    if(!json.success||!json.data||json.data.length<2){
      if(infoEl)infoEl.textContent='Not enough NAV history for this period.';
      return;
    }
    const data=json.data;
    const first=data[0].nav,last=data[data.length-1].nav;
    const retPct=((last-first)/first*100).toFixed(2);
    const isPos=retPct>=0;
    let plotData=data;
    if(data.length>200){const step=Math.ceil(data.length/200);plotData=data.filter((_,i)=>i%step===0||i===data.length-1);}
    const lineColor=isPos?'#16a34a':'#dc2626';
    const fillColor=isPos?'rgba(22,163,74,0.08)':'rgba(220,38,38,0.08)';
    const canvas=document.getElementById('drNavChart');if(!canvas)return;
    _drChartInst=new Chart(canvas.getContext('2d'),{
      type:'line',
      data:{labels:plotData.map(d=>d.date),datasets:[{data:plotData.map(d=>d.nav),borderColor:lineColor,backgroundColor:fillColor,borderWidth:1.5,pointRadius:0,pointHoverRadius:4,fill:true,tension:0.3}]},
      options:{responsive:true,maintainAspectRatio:false,animation:{duration:300},
        plugins:{legend:{display:false},tooltip:{callbacks:{title:i=>i[0].label,label:i=>'₹'+Number(i.raw).toLocaleString('en-IN',{minimumFractionDigits:4,maximumFractionDigits:4})}}},
        scales:{
          x:{ticks:{maxTicksLimit:5,font:{size:9},color:'var(--text-muted)',maxRotation:0},grid:{display:false}},
          y:{position:'right',ticks:{font:{size:9},color:'var(--text-muted)',callback:v=>'₹'+Number(v).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:2})},grid:{color:'rgba(0,0,0,0.04)'}}
        }
      }
    });
    if(infoEl){const sign=retPct>=0?'+':'';infoEl.innerHTML=`<span style="color:${lineColor};font-weight:700;">${sign}${retPct}%</span> return over ${period} &nbsp;·&nbsp; ${data.length} data points`;}
  }catch(e){
    if(loader){loader.style.display='none';}
    if(infoEl)infoEl.textContent='Could not load NAV history.';
  }
}

function drCalcSip(ret1y,ret3y,ret5y){
  const monthly=parseFloat(document.getElementById('sipCalcAmt')?.value)||5000;
  const years=parseInt(document.getElementById('sipCalcYrs')?.value)||3;
  const resEl=document.getElementById('sipCalcResult');
  if(!resEl)return;
  let rate;
  if(years<=1&&ret1y!=null)rate=ret1y;
  else if(years<=3&&ret3y!=null)rate=ret3y;
  else if(ret5y!=null)rate=ret5y;
  else rate=ret3y||ret1y||12;
  if(!rate||isNaN(rate))rate=12;
  const n=years*12,r=rate/100/12;
  const fv=r===0?monthly*n:monthly*((Math.pow(1+r,n)-1)/r)*(1+r);
  const invested=monthly*n,gains=fv-invested;
  const gainPct=(gains/invested*100).toFixed(1);
  function fmtInr(v){v=Math.abs(v);if(v>=1e7)return'₹'+(v/1e7).toFixed(2)+' Cr';if(v>=1e5)return'₹'+(v/1e5).toFixed(2)+' L';return'₹'+v.toLocaleString('en-IN',{maximumFractionDigits:0});}
  const isPos=gains>=0;
  resEl.innerHTML=`
    <div class="d-box" style="text-align:center;"><div class="d-lbl">Invested</div><div class="d-val" style="font-size:12px;">${fmtInr(invested)}</div><div class="d-sub">${n}mo</div></div>
    <div class="d-box" style="text-align:center;"><div class="d-lbl">Est. Value</div><div class="d-val" style="font-size:12px;color:var(--accent);">${fmtInr(fv)}</div><div class="d-sub">@${rate.toFixed(1)}%</div></div>
    <div class="d-box" style="text-align:center;"><div class="d-lbl">Gains</div><div class="d-val" style="font-size:12px;color:${isPos?'#16a34a':'#dc2626'};">${isPos?'+':''}${fmtInr(gains)}</div><div class="d-sub">${isPos?'+':''}${gainPct}%</div></div>`;
}

function drClose(){
  document.getElementById('scOv').classList.remove('open');
  document.getElementById('scDr').classList.remove('open');
  if(_drChartInst){_drChartInst.destroy();_drChartInst=null;}
}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){ drClose(); closeCompareModal(); closeAlertModal(); }});

/* ══════════════════════════════════════════════════
   t28 — TOP PERFORMERS VIEW
══════════════════════════════════════════════════ */
let _tpPeriod = '1Y';
let _tpCache  = {};

function switchView(v) {
  const isTop = v === 'top';
  document.getElementById('vtab_all').classList.toggle('active', !isTop);
  document.getElementById('vtab_top').classList.toggle('active', isTop);
  document.getElementById('scSearchBar').style.display   = isTop ? 'none'  : '';
  document.getElementById('scChips').style.display       = isTop ? 'none'  : '';
  document.getElementById('scResultsWrap').style.display = isTop ? 'none'  : '';
  document.getElementById('scTopWrap').style.display     = isTop ? 'flex'  : 'none';
  if (isTop) loadTopPerformers(_tpPeriod);
}

async function loadTopPerformers(period) {
  _tpPeriod = period;
  // Update buttons
  document.querySelectorAll('.tp-period-btn').forEach(b => b.classList.toggle('tp-active', b.dataset.p === period));
  const bodyEl = document.getElementById('scTopBody');
  if (_tpCache[period]) { renderTopPerformers(_tpCache[period], period); return; }
  bodyEl.innerHTML = '<div style="display:flex;align-items:center;justify-content:center;padding:60px;"><div class="spinner"></div></div>';
  try {
    const sortKey = period === '1Y' ? 'ret1y_desc' : period === '3Y' ? 'ret3y_desc' : 'ret5y_desc';
    const base = window._SCBASE || window.WD?.appUrl || window.APP_URL || '';
    // Fetch top 5 per broad category
    const categories = ['Equity','Debt','Commodity','FoF/Intl'];
    const results = {};
    await Promise.all(categories.map(async cat => {
      const params = new URLSearchParams({sort: sortKey, per_page: 5, page: 1, option_type: 'growth', plan_type: 'direct'});
      // Map broad type to keyword filter
      const kwMap = {Equity: 'Large Cap,Mid Cap,Small Cap,Flexi Cap,Index,ELSS,Multi Cap', Debt: 'Debt,Liquid,Gilt,Overnight', Commodity: 'Gold,Commodity', 'FoF/Intl': 'Fund of Funds,International'};
      const kw = kwMap[cat] || cat;
      kw.split(',').forEach(k => params.append('category[]', k.trim()));
      const res = await fetch(`${base}/api/mutual_funds/fund_screener.php?${params}`, {headers:{'X-Requested-With':'XMLHttpRequest'}});
      const d = await res.json();
      if (d.success && d.data.length) results[cat] = d.data;
    }));
    _tpCache[period] = results;
    renderTopPerformers(results, period);
  } catch(e) {
    bodyEl.innerHTML = `<div style="padding:40px;text-align:center;color:#dc2626;">Error loading data: ${e.message}</div>`;
  }
}

function renderTopPerformers(results, period) {
  const bodyEl = document.getElementById('scTopBody');
  const retKey = period === '1Y' ? 'returns_1y' : period === '3Y' ? 'returns_3y' : 'returns_5y';
  const catNames = { Equity:'📈 Equity — Top Direct Funds', Debt:'🏛 Debt — Top Direct Funds', Commodity:'🥇 Commodity / Gold', 'FoF/Intl':'🌍 International / FoF' };
  let html = `
    <div class="tp-period-bar">
      <span style="font-size:12px;font-weight:700;color:var(--text-muted);">Period:</span>
      ${['1Y','3Y','5Y'].map(p=>`<button class="tp-period-btn${p===period?' tp-active':''}" data-p="${p}" onclick="loadTopPerformers('${p}')">${p} Returns</button>`).join('')}
      <span style="margin-left:auto;font-size:11px;color:var(--text-muted);">Direct · Growth plans only &nbsp;·&nbsp; Click fund to open details</span>
    </div>`;
  const catOrder = ['Equity','Debt','Commodity','FoF/Intl'];
  for (const cat of catOrder) {
    const funds = results[cat];
    if (!funds || !funds.length) continue;
    const hasData = funds.some(f => f[retKey] !== null);
    html += `<div class="tp-section"><div class="tp-section-title">${catNames[cat]||cat}</div>`;
    if (!hasData) {
      html += `<div style="padding:12px;font-size:12px;color:var(--text-muted);background:var(--bg-secondary);border-radius:6px;">Returns data not yet available. Run DB migration to populate.</div>`;
    } else {
      funds.forEach((f, idx) => {
        const ret = f[retKey];
        const retColor = ret === null ? 'var(--text-muted)' : ret >= 15 ? '#15803d' : ret >= 10 ? '#16a34a' : ret >= 0 ? '#d97706' : '#dc2626';
        const retTxt = ret !== null ? (ret > 0 ? '+' : '') + ret.toFixed(1) + '%' : '—';
        html += `<div class="tp-card" onclick="drOpenFund(${JSON.stringify(f).replace(/"/g,'&quot;')})">
          <div class="tp-rank">${idx+1}</div>
          <div class="tp-info">
            <div class="tp-name">${f.scheme_name}</div>
            <div class="tp-house">${f.fund_house||''} · ${f.category_short||f.category||''}</div>
          </div>
          ${f.aum_crore ? `<div style="font-size:10px;color:var(--text-muted);text-align:right;min-width:50px;">AUM<br><strong>₹${Number(f.aum_crore)>=1000?(Number(f.aum_crore)/1000).toFixed(0)+'K':Number(f.aum_crore).toFixed(0)} Cr</strong></div>` : ''}
          <div class="tp-ret" style="color:${retColor};">${retTxt}</div>
        </div>`;
      });
    }
    html += `</div>`;
  }
  if (!Object.keys(results).length) html += `<div style="padding:40px;text-align:center;color:var(--text-muted);">No data available. Run the DB migration first.</div>`;
  bodyEl.innerHTML = html;
}

/* ══════════════════════════════════════════════════
   t29 — FUND COMPARISON (max 3)
══════════════════════════════════════════════════ */
let _cmpFunds = []; // [{id, name}]

function removeCmpFund(id) {
  _cmpFunds = _cmpFunds.filter(f => f.id !== id);
  renderCmpBar();
  if (_cmpFunds.length === 0) closeCompareModal();
}

function toggleCompare(id, name) {
  const existing = _cmpFunds.findIndex(f => f.id === id);
  if (existing >= 0) {
    _cmpFunds.splice(existing, 1);
  } else {
    if (_cmpFunds.length >= 3) {
      // Flash warning
      const bar = document.getElementById('cmpBar');
      bar.style.background = '#dc2626';
      setTimeout(() => bar.style.background = '', 600);
      return;
    }
    _cmpFunds.push({id, name});
  }
  renderCmpBar();
}

function resetCmpBtnStyle(id) {
  const btn = document.getElementById('cmpBtn_' + id);
  if (!btn) return;
  const isSelected = _cmpFunds.some(f => f.id === id);
  if (isSelected) {
    btn.style.borderColor = '#9333ea'; btn.style.color = '#9333ea'; btn.style.background = 'rgba(147,51,234,.1)';
  } else {
    btn.style.borderColor = 'var(--border-color)'; btn.style.color = 'var(--text-muted)'; btn.style.background = 'var(--bg-secondary)';
  }
}

function renderCmpBar() {
  const bar = document.getElementById('cmpBar');
  const chips = document.getElementById('cmpChips');
  if (!_cmpFunds.length) { bar.classList.remove('visible'); return; }
  bar.classList.add('visible');
  chips.innerHTML = _cmpFunds.map(f =>
    `<div class="cmp-fund-chip">${f.name.length>25?f.name.slice(0,24)+'…':f.name}<button onclick="removeCmpFund(${f.id})">✕</button></div>`
  ).join('');
  // Update all compare buttons
  document.querySelectorAll('[id^="cmpBtn_"]').forEach(btn => {
    const bid = parseInt(btn.id.replace('cmpBtn_',''));
    resetCmpBtnStyle(bid);
  });
}

function clearCompare() {
  _cmpFunds = [];
  renderCmpBar();
  document.querySelectorAll('[id^="cmpBtn_"]').forEach(btn => {
    btn.style.borderColor = 'var(--border-color)'; btn.style.color = 'var(--text-muted)'; btn.style.background = 'var(--bg-secondary)';
  });
}

function openCompareModal() {
  if (_cmpFunds.length < 2) return;
  document.getElementById('cmpModalOv').classList.add('open');
  renderCompareTable();
}

function closeCompareModal() { document.getElementById('cmpModalOv').classList.remove('open'); }

function renderCompareTable() {
  const funds = _cmpFunds.map(cf => window._scFunds?.find(f => f.id === cf.id)).filter(Boolean);
  if (!funds.length) return;
  const body = document.getElementById('cmpModalBody');

  function fmtNav(v) { return v ? '₹' + Number(v).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:4}) : '—'; }
  function fmtRet(v, allVals) {
    if (v === null || v === undefined) return '<span style="color:var(--text-muted);">—</span>';
    const valid = allVals.filter(x => x !== null && x !== undefined);
    const isBest = valid.length > 1 && v === Math.max(...valid);
    const color  = v >= 15 ? '#15803d' : v >= 10 ? '#16a34a' : v >= 0 ? '#d97706' : '#dc2626';
    const txt    = `<span style="font-weight:700;color:${color};">${v > 0 ? '+' : ''}${v.toFixed(1)}%</span>`;
    return isBest ? `<span class="cmp-best">${v > 0 ? '+' : ''}${v.toFixed(1)}%</span>` : txt;
  }
  function fmtExp(v, allVals) {
    if (v === null || v === undefined) return '—';
    const valid = allVals.filter(x => x !== null && x !== undefined);
    const isBest = valid.length > 1 && v === Math.min(...valid);
    return isBest ? `<span class="cmp-best">${v.toFixed(2)}%</span>` : `${v.toFixed(2)}%`;
  }

  const rows = [
    {label:'Fund House', vals: funds.map(f => f.fund_house||'—')},
    {label:'Category',   vals: funds.map(f => f.category_short||f.category||'—')},
    {label:'Plan',       vals: funds.map(f => f.plan_type==='direct'?'<span style="color:#16a34a;font-weight:700;">✅ Direct</span>':'<span style="color:#d97706;">Regular</span>')},
    {label:'Risk Level', vals: funds.map(f => f.risk_level||'—')},
    {label:'Latest NAV', vals: funds.map(f => fmtNav(f.latest_nav))},
    {label:'Peak NAV',   vals: funds.map(f => fmtNav(f.highest_nav))},
    {label:'Drawdown',   vals: funds.map(f => f.drawdown_pct !== null ? `<span style="color:${f.drawdown_pct>20?'#dc2626':f.drawdown_pct>10?'#d97706':'#16a34a'};">▼${f.drawdown_pct}%</span>` : '—')},
    {label:'1Y Return',  vals: funds.map(f => fmtRet(f.returns_1y, funds.map(x=>x.returns_1y))), isBest:true},
    {label:'3Y CAGR',    vals: funds.map(f => fmtRet(f.returns_3y, funds.map(x=>x.returns_3y))), isBest:true},
    {label:'5Y CAGR',    vals: funds.map(f => fmtRet(f.returns_5y, funds.map(x=>x.returns_5y))), isBest:true},
    {label:'Expense Ratio', vals: funds.map(f => fmtExp(f.expense_ratio, funds.map(x=>x.expense_ratio)))},
    {label:'Exit Load',  vals: funds.map(f => f.exit_load_pct > 0 ? `⚠ ${f.exit_load_pct}% / ${f.exit_load_days}d` : f.exit_load_pct===0 ? '✓ Nil' : '—')},
    {label:'AUM',        vals: funds.map(f => f.aum_crore ? '₹'+Number(f.aum_crore).toLocaleString('en-IN',{maximumFractionDigits:0})+' Cr' : '—')},
    {label:'LTCG Period',vals: funds.map(f => f.min_ltcg_days===365?'1 Year':f.min_ltcg_days===730?'2 Years':f.min_ltcg_days===1095?'3 Years':f.min_ltcg_days+' days')},
    {label:'Lock-in',    vals: funds.map(f => f.lock_in_days>0?(f.lock_in_days===1095?'3yr (ELSS)':f.lock_in_days+'d'):'None')},
  ];

  body.innerHTML = `
    <table class="cmp-table">
      <thead><tr>
        <th style="width:130px;">Parameter</th>
        ${funds.map(f=>`<th><div style="font-size:12px;font-weight:700;color:var(--text-primary);">${f.scheme_name.length>35?f.scheme_name.slice(0,34)+'…':f.scheme_name}</div><div style="font-size:10px;color:var(--text-muted);margin-top:2px;">${f.fund_house||''}</div></th>`).join('')}
      </tr></thead>
      <tbody>
        ${rows.map(r=>`<tr><td class="cmp-row-label">${r.label}</td>${r.vals.map(v=>`<td>${v}</td>`).join('')}</tr>`).join('')}
      </tbody>
    </table>`;
}

/* ══════════════════════════════════════════════════
   t30 — PRICE ALERTS (localStorage based)
══════════════════════════════════════════════════ */
let _alertFundId   = null;
let _alertFundNav  = null;
const ALERT_KEY    = 'wd_price_alerts_v1';

function getAlerts() {
  try { return JSON.parse(localStorage.getItem(ALERT_KEY) || '[]'); } catch(e) { return []; }
}
function saveAlerts(alerts) {
  try { localStorage.setItem(ALERT_KEY, JSON.stringify(alerts)); } catch(e) {}
}

function openAlertModal(fundId, fundName, currentNav) {
  _alertFundId  = fundId;
  _alertFundNav = currentNav;
  document.getElementById('alertFundName').textContent = fundName;
  document.getElementById('alertCurrentNav').textContent = currentNav ? `Current NAV: ₹${Number(currentNav).toFixed(4)}` : '';
  document.getElementById('alertTargetNav').value = '';
  // Check if alert already exists
  const alerts = getAlerts();
  const existing = alerts.find(a => a.fund_id === fundId);
  if (existing) {
    document.getElementById('alertTargetNav').value = existing.target_nav;
    document.getElementById(existing.type === 'above' ? 'alertAbove' : 'alertBelow').checked = true;
  }
  document.getElementById('alertModalOv').classList.add('open');
  setTimeout(() => document.getElementById('alertTargetNav').focus(), 100);
}

function closeAlertModal() { document.getElementById('alertModalOv').classList.remove('open'); }

function saveAlert() {
  const targetNav = parseFloat(document.getElementById('alertTargetNav').value);
  const type = document.querySelector('input[name="alertType"]:checked')?.value || 'above';
  if (!targetNav || isNaN(targetNav) || targetNav <= 0) {
    document.getElementById('alertTargetNav').style.borderColor = '#dc2626';
    setTimeout(() => document.getElementById('alertTargetNav').style.borderColor = '', 1500);
    return;
  }
  const alerts = getAlerts().filter(a => a.fund_id !== _alertFundId);
  alerts.push({
    fund_id: _alertFundId, type, target_nav: targetNav,
    current_nav: _alertFundNav, created_at: new Date().toISOString()
  });
  saveAlerts(alerts);
  closeAlertModal();
  // Visual feedback — update alert button in drawer
  const alertBtn = document.getElementById('drAlertBtn');
  if (alertBtn) { alertBtn.textContent = '🔔 Alert Set ✓'; alertBtn.style.color = '#16a34a'; alertBtn.style.borderColor = '#86efac'; }
  // Show toast if available
  if (typeof showToast === 'function') {
    showToast(`Alert set: NAV ${type} ₹${targetNav.toFixed(2)}`, 'success');
  }
}

// Check alerts against current NAV data (called after SC.fetch)
function checkPriceAlerts(funds) {
  if (!funds || !funds.length) return;
  const alerts = getAlerts();
  if (!alerts.length) return;
  const triggered = [];
  alerts.forEach(alert => {
    const fund = funds.find(f => f.id === alert.fund_id);
    if (!fund || !fund.latest_nav) return;
    const nav = fund.latest_nav;
    if (alert.type === 'above' && nav >= alert.target_nav) {
      triggered.push({fund, alert, nav});
    } else if (alert.type === 'below' && nav <= alert.target_nav) {
      triggered.push({fund, alert, nav});
    }
  });
  if (triggered.length && typeof showToast === 'function') {
    triggered.forEach(({fund, alert, nav}) => {
      showToast(`🔔 ${fund.scheme_name.slice(0,30)}: NAV ₹${nav.toFixed(2)} ${alert.type} target ₹${alert.target_nav}`, 'warning');
    });
  }
}



function scAdd(id,name,house){
  try{sessionStorage.setItem('sc_add_fund_id',id);sessionStorage.setItem('sc_add_fund_name',name);}catch(e){}
  window.location.href=(window.APP_URL||window.WD?.appUrl||'')+'/templates/pages/mf_holdings.php?add_fund='+id+'&fund_name='+encodeURIComponent(name);
}

// t69: Export screener results as CSV with active filters
async function exportScreenerCsv() {
  const btn = event?.target?.closest('button');
  const origHtml = btn?.innerHTML || '';
  if (btn) { btn.innerHTML = '⏳ Exporting...'; btn.disabled = true; }

  try {
    // Fetch ALL matching funds (up to 5000, no pagination)
    const base = window._SCBASE || window.WD?.appUrl || window.APP_URL || '';
    const params = SC.buildParams({ page: 1, per_page: 5000 });
    const res = await fetch(`${base}/api/mutual_funds/fund_screener.php?${params}`, {
      headers: { 'X-Requested-With': 'XMLHttpRequest' }
    });
    const d = await res.json();
    if (!d.success || !d.data?.length) {
      if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
      alert('No funds to export with current filters.');
      return;
    }

    // Build CSV
    const headers = ['Fund Name','AMC','Category','Plan','Option','NAV (₹)','NAV Date','Peak NAV (₹)','Drawdown (%)','1Y Return (%)','3Y CAGR (%)','5Y CAGR (%)','Expense Ratio (%)','AUM (Cr)','Risk Level','LTCG Period','Exit Load','Scheme Code'];
    const rows = d.data.map(f => [
      f.scheme_name || '',
      f.fund_house   || '',
      f.category     || '',
      f.plan_type    || '',
      f.option_type  || '',
      f.latest_nav   !== null ? f.latest_nav  : '',
      f.latest_nav_date || '',
      f.highest_nav  !== null ? f.highest_nav : '',
      f.drawdown_pct !== null ? f.drawdown_pct : '',
      f.returns_1y   !== null ? f.returns_1y   : '',
      f.returns_3y   !== null ? f.returns_3y   : '',
      f.returns_5y   !== null ? f.returns_5y   : '',
      f.expense_ratio !== null ? f.expense_ratio : '',
      f.aum_crore    !== null ? f.aum_crore    : '',
      f.risk_level   || '',
      f.min_ltcg_days === 365 ? '1 Year' : f.min_ltcg_days === 730 ? '2 Years' : f.min_ltcg_days + ' days',
      f.exit_load_pct > 0 ? `${f.exit_load_pct}% / ${f.exit_load_days}d` : 'Nil',
      f.scheme_code  || '',
    ].map(v => `"${String(v).replace(/"/g,'""')}"`));

    const csv = [headers.join(','), ...rows.map(r => r.join(','))].join('\n');
    const blob = new Blob(['\uFEFF' + csv], { type: 'text/csv;charset=utf-8;' }); // BOM for Excel
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `wealthdash_screener_${new Date().toISOString().slice(0,10)}.csv`;
    a.click();
    URL.revokeObjectURL(url);

    if (btn) btn.innerHTML = '✅ Exported!';
    setTimeout(() => { if (btn) { btn.innerHTML = origHtml; btn.disabled = false; } }, 2000);
  } catch(e) {
    if (btn) { btn.innerHTML = origHtml; btn.disabled = false; }
    console.error('Export error:', e);
    alert('Export failed: ' + e.message);
  }
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
  const thId = {name:'sh_name', nav_desc:'sh_nav', nav_asc:'sh_nav', ltcg:'sh_ltcg', ltcg_desc:'sh_ltcg', dd_asc:'sh_dd', dd_desc:'sh_dd', expense:'sh_exp', expense_desc:'sh_exp', name_desc:'sh_name', peak_nav_asc:'sh_peak', peak_nav_desc:'sh_peak', ret1y_desc:'sh_r1', ret1y_asc:'sh_r1', ret3y_desc:'sh_r3', ret3y_asc:'sh_r3', ret5y_desc:'sh_r5', ret5y_asc:'sh_r5', aum_desc:'sh_aum', aum_asc:'sh_aum'};
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

  const csv  = [headers.join(','), ...rows].join('\n');
  const blob = new Blob([csv], {type:'text/csv'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href     = url;
  a.download = `wealthdash_funds_${new Date().toISOString().slice(0,10)}.csv`;
  a.click();
  URL.revokeObjectURL(url);
}

// Init — window.WD is defined AFTER pageContent in layout.php,
// so always wait for DOMContentLoaded to ensure WD is available.
function _scInit() {
  var metaUrl = document.querySelector('meta[name="app-url"]');
  window._SCBASE = (metaUrl ? metaUrl.getAttribute('content') : '') || '';
  SC.fetch();
  updSortHeaders('name');
}

document.addEventListener('DOMContentLoaded', _scInit);
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>