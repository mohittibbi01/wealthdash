<?php
/**
 * WealthDash — NPS Screener (t100)
 * Find & compare NPS schemes by PFM, Tier, Asset Class, Returns
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'NPS Scheme Finder';
$activePage  = 'nps_screener';

$schemeCount   = (int)DB::conn()->query("SELECT COUNT(*) FROM nps_schemes WHERE is_active=1")->fetchColumn();
$withReturns   = (int)DB::conn()->query("SELECT COUNT(*) FROM nps_schemes WHERE is_active=1 AND return_1y IS NOT NULL")->fetchColumn();
$navToday      = (int)DB::conn()->query("SELECT COUNT(*) FROM nps_schemes WHERE is_active=1 AND latest_nav_date=CURDATE()")->fetchColumn();

ob_start();
?>
<style>
.nsc-page{display:flex;flex-direction:column;gap:0;height:calc(100vh - 130px);overflow:hidden}
.nsc-stats-bar{display:flex;align-items:center;gap:8px;padding:8px 0 10px;flex-wrap:wrap;flex-shrink:0}
.nsc-stat-card{display:flex;flex-direction:column;align-items:center;padding:6px 14px;border-radius:8px;border:1.5px solid var(--border-color);background:var(--bg-card);min-width:80px;text-align:center;cursor:pointer;transition:all .15s;user-select:none}
.nsc-stat-card:hover{border-color:var(--accent);transform:translateY(-1px)}
.nsc-stat-card.active{border-color:var(--accent);background:rgba(3,105,161,.08)}
.nsc-stat-num{font-size:16px;font-weight:800;line-height:1.2}
.nsc-stat-lbl{font-size:10px;font-weight:600;color:var(--text-muted);margin-top:2px}

.nsc-toolbar{display:flex;align-items:center;gap:8px;flex-shrink:0;padding-bottom:10px;flex-wrap:wrap}
.nsc-search{flex:1;min-width:200px;max-width:300px;padding:8px 12px 8px 34px;border:1.5px solid var(--border-color);border-radius:8px;background:var(--bg-card);color:var(--text-primary);font-size:13px;outline:none;position:relative}
.nsc-search:focus{border-color:var(--accent)}
.nsc-search-wrap{position:relative;flex:1;max-width:300px}
.nsc-search-ico{position:absolute;left:10px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:13px;pointer-events:none}
.nsc-filter-group{display:flex;align-items:center;gap:6px;flex-wrap:wrap}
.nsc-select{padding:7px 10px;border:1.5px solid var(--border-color);border-radius:7px;background:var(--bg-card);color:var(--text-primary);font-size:12px;font-weight:600;outline:none;cursor:pointer;transition:border-color .15s}
.nsc-select:focus{border-color:var(--accent)}
.nsc-btn{padding:7px 14px;border-radius:7px;border:1.5px solid;font-size:12px;font-weight:700;cursor:pointer;transition:all .15s;white-space:nowrap}
.nsc-btn-primary{background:var(--accent);color:#fff;border-color:var(--accent)}
.nsc-btn-outline{background:var(--bg-card);color:var(--text-muted);border-color:var(--border-color)}
.nsc-btn-outline:hover{border-color:var(--accent);color:var(--accent)}
.nsc-btn-compare{background:rgba(3,105,161,.08);color:#0369a1;border-color:#bae6fd}
.nsc-btn-compare.has-compare{background:#0369a1;color:#fff}

.nsc-sort-bar{display:flex;align-items:center;gap:0;border:1px solid var(--border-color);border-radius:8px;background:var(--bg-card);overflow:hidden;flex-shrink:0;margin-bottom:8px}
.nsc-sort-tab{padding:7px 14px;font-size:11px;font-weight:700;cursor:pointer;border-right:1px solid var(--border-color);color:var(--text-muted);transition:all .15s;white-space:nowrap}
.nsc-sort-tab:last-child{border-right:none}
.nsc-sort-tab:hover{background:var(--bg-secondary);color:var(--text-primary)}
.nsc-sort-tab.active{background:rgba(3,105,161,.07);color:#0369a1}

.nsc-body{flex:1;overflow:auto;position:relative}
.nsc-table{width:100%;border-collapse:collapse;font-size:12px}
.nsc-table thead{position:sticky;top:0;z-index:5;background:var(--bg-secondary)}
.nsc-table th{padding:9px 10px;text-align:left;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.4px;color:var(--text-muted);border-bottom:1px solid var(--border-color);white-space:nowrap;cursor:pointer;user-select:none}
.nsc-table th:hover{color:var(--text-primary)}
.nsc-table th.sorted{color:#0369a1}
.nsc-table td{padding:9px 10px;border-bottom:1px solid var(--border-color);vertical-align:middle}
.nsc-table tr:hover td{background:rgba(3,105,161,.03)}
.nsc-table tr.compare-selected td{background:rgba(3,105,161,.06)}
.nsc-table .text-right{text-align:right}
.nsc-table .text-center{text-align:center}

.nsc-scheme-name{font-weight:700;font-size:13px;color:var(--text-primary);margin-bottom:2px}
.nsc-pfm-tag{font-size:10px;color:var(--text-muted);font-weight:600}
.nsc-tier-badge{font-size:10px;font-weight:700;padding:2px 7px;border-radius:99px;border:1px solid}
.tier-i{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.tier-ii{background:#faf5ff;color:#7e22ce;border-color:#e9d5ff}
.nsc-asset-badge{font-size:10px;font-weight:800;padding:2px 7px;border-radius:4px;border:1px solid}
.asset-E{background:#f0fdf4;color:#15803d;border-color:#bbf7d0}
.asset-C{background:#fff7ed;color:#c2410c;border-color:#fed7aa}
.asset-G{background:#eff6ff;color:#1d4ed8;border-color:#bfdbfe}
.asset-A{background:#faf5ff;color:#7e22ce;border-color:#e9d5ff}
.nsc-ret{font-weight:700;font-size:12px}
.nsc-ret.pos{color:#15803d}
.nsc-ret.neg{color:#dc2626}
.nsc-ret.na{color:var(--text-muted);font-weight:400;font-size:11px}
.nsc-nav-val{font-weight:700;font-size:12px}
.nsc-nav-date{font-size:10px;color:var(--text-muted)}

.nsc-compare-btn{width:28px;height:28px;border-radius:50%;border:1.5px solid var(--border-color);background:transparent;cursor:pointer;font-size:13px;display:flex;align-items:center;justify-content:center;transition:all .15s;color:var(--text-muted)}
.nsc-compare-btn:hover{border-color:#0369a1;color:#0369a1;background:rgba(3,105,161,.07)}
.nsc-compare-btn.selected{background:#0369a1;color:#fff;border-color:#0369a1}

.nsc-compare-tray{position:fixed;bottom:0;left:0;right:0;background:var(--bg-card);border-top:2px solid #0369a1;padding:12px 24px;display:flex;align-items:center;gap:12px;z-index:100;transform:translateY(100%);transition:transform .25s;box-shadow:0 -4px 20px rgba(0,0,0,.12)}
.nsc-compare-tray.show{transform:translateY(0)}
.nsc-compare-tray-items{display:flex;gap:10px;flex:1;flex-wrap:wrap}
.nsc-compare-chip{display:flex;align-items:center;gap:6px;padding:4px 12px;background:rgba(3,105,161,.09);border:1px solid #bae6fd;border-radius:99px;font-size:12px;font-weight:600;color:#0369a1}
.nsc-compare-chip button{background:none;border:none;cursor:pointer;color:#0369a1;font-size:14px;line-height:1;padding:0 0 0 4px}

.nsc-compare-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.4);z-index:500;align-items:flex-start;justify-content:center;padding-top:60px;backdrop-filter:blur(3px)}
.nsc-compare-modal-overlay.open{display:flex}
.nsc-compare-modal{background:var(--bg-card);border-radius:12px;padding:0;width:92%;max-width:860px;max-height:80vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2)}
.nsc-cmp-hdr{display:flex;align-items:center;justify-content:space-between;padding:16px 20px;border-bottom:1px solid var(--border-color)}
.nsc-cmp-grid{display:grid;grid-template-columns:140px 1fr 1fr;gap:0}
.nsc-cmp-row{display:contents}
.nsc-cmp-label{padding:10px 16px;font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid var(--border-color);background:var(--bg-secondary)}
.nsc-cmp-cell{padding:10px 16px;font-size:12px;border-bottom:1px solid var(--border-color);border-left:1px solid var(--border-color)}
.nsc-cmp-cell.hdr{font-weight:800;font-size:13px;background:rgba(3,105,161,.04)}

.nsc-empty{padding:60px;text-align:center;color:var(--text-muted)}
.nsc-loading{padding:40px;text-align:center;color:var(--text-muted)}
.nsc-spinner{display:inline-block;width:20px;height:20px;border:2px solid var(--border-color);border-top-color:#0369a1;border-radius:50%;animation:spin .7s linear infinite;margin-right:8px;vertical-align:middle}
@keyframes spin{to{transform:rotate(360deg)}}
.nsc-pager{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-top:1px solid var(--border-color);font-size:12px;color:var(--text-muted);flex-shrink:0}
.nsc-pager-btns{display:flex;gap:6px}
.nsc-page-btn{padding:5px 12px;border:1.5px solid var(--border-color);border-radius:6px;background:var(--bg-card);color:var(--text-primary);font-size:12px;font-weight:600;cursor:pointer;transition:all .15s}
.nsc-page-btn:hover{border-color:#0369a1;color:#0369a1}
.nsc-page-btn:disabled{opacity:.4;cursor:not-allowed}
.nsc-page-btn.active{background:#0369a1;color:#fff;border-color:#0369a1}
</style>

<div class="nsc-page">

  <!-- Stats bar -->
  <div class="nsc-stats-bar">
    <div class="nsc-stat-card" onclick="NSC.filterAsset('')" id="nscStatAll">
      <div class="nsc-stat-num" id="nscTotalCount"><?= $schemeCount ?></div>
      <div class="nsc-stat-lbl">Total Schemes</div>
    </div>
    <div class="nsc-stat-card" onclick="NSC.filterAsset('E')">
      <div class="nsc-stat-num" style="color:#15803d">E</div>
      <div class="nsc-stat-lbl">Equity</div>
    </div>
    <div class="nsc-stat-card" onclick="NSC.filterAsset('C')">
      <div class="nsc-stat-num" style="color:#c2410c">C</div>
      <div class="nsc-stat-lbl">Corporate</div>
    </div>
    <div class="nsc-stat-card" onclick="NSC.filterAsset('G')">
      <div class="nsc-stat-num" style="color:#1d4ed8">G</div>
      <div class="nsc-stat-lbl">Govt Bond</div>
    </div>
    <div class="nsc-stat-card" onclick="NSC.filterAsset('A')">
      <div class="nsc-stat-num" style="color:#7e22ce">A</div>
      <div class="nsc-stat-lbl">Alternative</div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:8px;font-size:11px;color:var(--text-muted)">
      <span><?= $navToday ?>/<?= $schemeCount ?> NAVs today</span>
      <span>·</span>
      <span><?= $withReturns ?> with returns data</span>
    </div>
  </div>

  <!-- Toolbar -->
  <div class="nsc-toolbar">
    <div class="nsc-search-wrap">
      <span class="nsc-search-ico">🔍</span>
      <input type="text" class="nsc-search" id="nscSearch" placeholder="Search scheme, PFM..." oninput="NSC.onSearch(this.value)">
    </div>
    <div class="nsc-filter-group">
      <select class="nsc-select" id="nscPfmFilter" onchange="NSC.fetch()">
        <option value="">All PFMs</option>
        <option value="SBI Pension Funds">SBI</option>
        <option value="HDFC Pension Management">HDFC</option>
        <option value="ICICI Prudential Pension">ICICI</option>
        <option value="UTI Retirement Solutions">UTI</option>
        <option value="Kotak Mahindra Pension">Kotak</option>
        <option value="Aditya Birla Sun Life Pension">Aditya Birla</option>
        <option value="Max Life Pension">Max Life</option>
        <option value="Tata Pension Management">Tata</option>
        <option value="Axis Pension">Axis</option>
      </select>
      <select class="nsc-select" id="nscTierFilter" onchange="NSC.fetch()">
        <option value="">Tier I & II</option>
        <option value="tier1">Tier I Only</option>
        <option value="tier2">Tier II Only</option>
      </select>
      <select class="nsc-select" id="nscAssetFilter" onchange="NSC.fetch()">
        <option value="">All Asset Classes</option>
        <option value="E">E — Equity</option>
        <option value="C">C — Corporate Bond</option>
        <option value="G">G — Govt Bond</option>
        <option value="A">A — Alternative</option>
      </select>
    </div>
    <button class="nsc-btn nsc-btn-outline" onclick="NSC.reset()">Reset</button>
    <button class="nsc-btn nsc-btn-compare" id="nscCompareBtn" onclick="NSC.openCompare()" style="display:none">
      Compare (<span id="nscCompareCnt">0</span>)
    </button>
  </div>

  <!-- Sort tabs -->
  <div class="nsc-sort-bar">
    <div class="nsc-sort-tab active" data-sort="return_1y" onclick="NSC.setSort('return_1y', this)">1Y Returns ↓</div>
    <div class="nsc-sort-tab" data-sort="return_3y" onclick="NSC.setSort('return_3y', this)">3Y Returns</div>
    <div class="nsc-sort-tab" data-sort="return_5y" onclick="NSC.setSort('return_5y', this)">5Y Returns</div>
    <div class="nsc-sort-tab" data-sort="return_since" onclick="NSC.setSort('return_since', this)">Since Inception</div>
    <div class="nsc-sort-tab" data-sort="nav_desc" onclick="NSC.setSort('nav_desc', this)">NAV (High)</div>
    <div class="nsc-sort-tab" data-sort="name" onclick="NSC.setSort('name', this)">A–Z</div>
    <div style="margin-left:auto;padding:0 12px;font-size:11px;color:var(--text-muted);display:flex;align-items:center" id="nscResultMeta"></div>
  </div>

  <!-- Table body -->
  <div class="nsc-body" id="nscBody">
    <div class="nsc-loading"><span class="nsc-spinner"></span>Loading NPS schemes...</div>
  </div>

  <!-- Pager -->
  <div class="nsc-pager" id="nscPager" style="display:none">
    <span id="nscPagerInfo"></span>
    <div class="nsc-pager-btns" id="nscPagerBtns"></div>
  </div>
</div>

<!-- Compare Tray -->
<div class="nsc-compare-tray" id="nscCompareTray">
  <span style="font-size:12px;font-weight:700;color:#0369a1;white-space:nowrap">Compare:</span>
  <div class="nsc-compare-tray-items" id="nscCompareTrayItems"></div>
  <button class="nsc-btn nsc-btn-primary" onclick="NSC.openCompare()">Compare Now</button>
  <button class="nsc-btn nsc-btn-outline" onclick="NSC.clearCompare()">Clear</button>
</div>

<!-- Compare Modal -->
<div class="nsc-compare-modal-overlay" id="nscCompareModal" onclick="if(event.target===this)NSC.closeCompare()">
  <div class="nsc-compare-modal">
    <div class="nsc-cmp-hdr">
      <span style="font-size:15px;font-weight:800">📊 NPS Scheme Comparison</span>
      <button onclick="NSC.closeCompare()" style="background:none;border:none;font-size:18px;cursor:pointer;color:var(--text-muted)">✕</button>
    </div>
    <div id="nscCompareContent" style="padding:16px">
      <div class="nsc-loading">Loading comparison...</div>
    </div>
  </div>
</div>

<script>
const NSC_API = (document.querySelector('meta[name="app-url"]')?.content || '') + '/api/nps/nps_screener.php';

const NSC = {
  state: { q:'', pfm:'', tier:'', asset_class:'', sort:'return_1y', page:1, per_page:40 },
  compare: [],
  _debTimer: null,
  _lastData: null,

  onSearch(v) {
    clearTimeout(this._debTimer);
    this._debTimer = setTimeout(() => { this.state.q = v; this.state.page = 1; this.fetch(); }, 300);
  },

  filterAsset(ac) {
    document.getElementById('nscAssetFilter').value = ac;
    this.state.asset_class = ac;
    this.state.page = 1;
    this.fetch();
  },

  setSort(s, el) {
    document.querySelectorAll('.nsc-sort-tab').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
    this.state.sort = s;
    this.state.page = 1;
    this.fetch();
  },

  reset() {
    this.state = { q:'', pfm:'', tier:'', asset_class:'', sort:'return_1y', page:1, per_page:40 };
    document.getElementById('nscSearch').value = '';
    document.getElementById('nscPfmFilter').value = '';
    document.getElementById('nscTierFilter').value = '';
    document.getElementById('nscAssetFilter').value = '';
    document.querySelectorAll('.nsc-sort-tab').forEach((t,i)=>t.classList.toggle('active',i===0));
    this.fetch();
  },

  async fetch() {
    this.state.pfm = document.getElementById('nscPfmFilter').value;
    this.state.tier = document.getElementById('nscTierFilter').value;
    this.state.asset_class = document.getElementById('nscAssetFilter').value;

    document.getElementById('nscBody').innerHTML = '<div class="nsc-loading"><span class="nsc-spinner"></span>Loading...</div>';

    const params = new URLSearchParams({...this.state, compare: this.compare.join(',')});
    try {
      const res  = await fetch(NSC_API + '?' + params);
      const data = await res.json();
      if (!data.success) { document.getElementById('nscBody').innerHTML = `<div class="nsc-empty">Error: ${data.message}</div>`; return; }
      this._lastData = data;
      this.renderTable(data);
      this.renderPager(data);
    } catch(e) {
      document.getElementById('nscBody').innerHTML = `<div class="nsc-empty">⚠️ Could not load schemes. Check if DB has NPS schemes seeded.</div>`;
    }
  },

  renderTable(data) {
    if (!data.schemes.length) {
      document.getElementById('nscBody').innerHTML = '<div class="nsc-empty">🔍 No schemes found. Try different filters.</div>';
      document.getElementById('nscResultMeta').textContent = '0 results';
      return;
    }
    document.getElementById('nscResultMeta').textContent = `${data.total} schemes`;

    const rows = data.schemes.map(s => {
      const isCompare = this.compare.includes(s.id);
      const assetBadge = `<span class="nsc-asset-badge asset-${s.asset_class}">${s.asset_class}</span>`;
      const tierBadge  = `<span class="nsc-tier-badge ${s.tier === 'tier1' ? 'tier-i' : 'tier-ii'}">${s.tier === 'tier1' ? 'Tier I' : 'Tier II'}</span>`;
      const ret = (v, suffix='') => v == null
        ? '<span class="nsc-ret na">—</span>'
        : `<span class="nsc-ret ${v>=0?'pos':'neg'}">${v>=0?'+':''}${Number(v).toFixed(2)}%${suffix}</span>`;
      const navDate = s.latest_nav_date ? new Date(s.latest_nav_date).toLocaleDateString('en-IN',{day:'2-digit',month:'short'}) : '—';

      return `<tr class="${isCompare?'compare-selected':''}">
        <td style="width:28px;text-align:center">
          <button class="nsc-compare-btn ${isCompare?'selected':''}" onclick="NSC.toggleCompare(${s.id},'${s.scheme_name.replace(/'/g,"\\'")}')" title="${isCompare?'Remove from compare':'Add to compare'}">
            ${isCompare?'✓':'＋'}
          </button>
        </td>
        <td>
          <div class="nsc-scheme-name">${s.scheme_name}</div>
          <div class="nsc-pfm-tag">${s.pfm_name}</div>
        </td>
        <td>${tierBadge}</td>
        <td>${assetBadge}</td>
        <td class="text-right">
          <div class="nsc-nav-val">${s.latest_nav ? '₹' + Number(s.latest_nav).toFixed(4) : '—'}</div>
          <div class="nsc-nav-date">${navDate}</div>
        </td>
        <td class="text-right">${ret(s.return_1y)}</td>
        <td class="text-right">${ret(s.return_3y)}</td>
        <td class="text-right">${ret(s.return_5y)}</td>
        <td class="text-right">${ret(s.return_since)}</td>
        <td class="text-right" style="color:var(--text-muted);font-size:11px">${s.nav_history_count > 0 ? s.nav_history_count + ' days' : '<span style="color:#dc2626">No history</span>'}</td>
      </tr>`;
    }).join('');

    document.getElementById('nscBody').innerHTML = `
      <table class="nsc-table">
        <thead>
          <tr>
            <th style="width:28px"></th>
            <th>Scheme / PFM</th>
            <th>Tier</th>
            <th>Class</th>
            <th class="text-right">NAV (₹)</th>
            <th class="text-right sorted">1Y Return</th>
            <th class="text-right">3Y Return</th>
            <th class="text-right">5Y Return</th>
            <th class="text-right">Since Inception</th>
            <th class="text-right">NAV History</th>
          </tr>
        </thead>
        <tbody>${rows}</tbody>
      </table>`;
  },

  renderPager(data) {
    const pager = document.getElementById('nscPager');
    if (data.pages <= 1) { pager.style.display = 'none'; return; }
    pager.style.display = 'flex';
    const start = (data.page - 1) * data.per_page + 1;
    const end   = Math.min(data.page * data.per_page, data.total);
    document.getElementById('nscPagerInfo').textContent = `Showing ${start}–${end} of ${data.total}`;

    let btns = '';
    for (let i = 1; i <= data.pages; i++) {
      if (i === 1 || i === data.pages || Math.abs(i - data.page) <= 2) {
        btns += `<button class="nsc-page-btn ${i===data.page?'active':''}" onclick="NSC.goPage(${i})">${i}</button>`;
      } else if (Math.abs(i - data.page) === 3) {
        btns += `<span style="padding:0 4px;color:var(--text-muted)">…</span>`;
      }
    }
    document.getElementById('nscPagerBtns').innerHTML = btns;
  },

  goPage(p) { this.state.page = p; this.fetch(); window.scrollTo(0,0); },

  toggleCompare(id, name) {
    const idx = this.compare.indexOf(id);
    if (idx >= 0) {
      this.compare.splice(idx, 1);
    } else {
      if (this.compare.length >= 2) { alert('Maximum 2 schemes can be compared at a time.'); return; }
      this.compare.push(id);
    }
    this.updateCompareTray(id, name, idx < 0);
    this.fetch();
    document.getElementById('nscCompareCnt').textContent = this.compare.length;
    document.getElementById('nscCompareBtn').style.display = this.compare.length > 0 ? '' : 'none';
    document.getElementById('nscCompareBtn').classList.toggle('has-compare', this.compare.length > 0);
  },

  updateCompareTray(id, name, added) {
    const tray = document.getElementById('nscCompareTray');
    const items = document.getElementById('nscCompareTrayItems');
    if (!added) {
      document.getElementById('nscChip_' + id)?.remove();
    } else {
      items.insertAdjacentHTML('beforeend',
        `<div class="nsc-compare-chip" id="nscChip_${id}">${name.slice(0,30)}
          <button onclick="NSC.toggleCompare(${id},'${name.replace(/'/g,"\\'")}')" title="Remove">✕</button>
        </div>`
      );
    }
    tray.classList.toggle('show', this.compare.length > 0);
  },

  clearCompare() {
    this.compare = [];
    document.getElementById('nscCompareTrayItems').innerHTML = '';
    document.getElementById('nscCompareTray').classList.remove('show');
    document.getElementById('nscCompareBtn').style.display = 'none';
    document.getElementById('nscCompareCnt').textContent = '0';
    this.fetch();
  },

  async openCompare() {
    if (this.compare.length < 2) { alert('Select 2 schemes to compare.'); return; }
    document.getElementById('nscCompareModal').classList.add('open');
    document.getElementById('nscCompareContent').innerHTML = '<div class="nsc-loading"><span class="nsc-spinner"></span>Loading comparison...</div>';

    const params = new URLSearchParams({ compare: this.compare.join(','), per_page:1 });
    try {
      const res  = await fetch(NSC_API + '?' + params);
      const data = await res.json();
      if (!data.success || !data.compare.length) { document.getElementById('nscCompareContent').innerHTML = '<div class="nsc-empty">Could not load comparison data.</div>'; return; }
      this.renderCompare(data.compare);
    } catch(e) {
      document.getElementById('nscCompareContent').innerHTML = `<div class="nsc-empty">Error: ${e.message}</div>`;
    }
  },

  renderCompare(schemes) {
    const fields = [
      ['Scheme Name',    s => `<strong>${s.scheme_name}</strong>`],
      ['PFM',           s => s.pfm_name],
      ['Tier',          s => s.tier === 'tier1' ? '<span class="nsc-tier-badge tier-i">Tier I</span>' : '<span class="nsc-tier-badge tier-ii">Tier II</span>'],
      ['Asset Class',   s => `<span class="nsc-asset-badge asset-${s.asset_class}">${s.asset_class}</span>`],
      ['Latest NAV',    s => s.latest_nav ? `₹${Number(s.latest_nav).toFixed(4)}` : '—'],
      ['NAV Date',      s => s.latest_nav_date || '—'],
      ['1Y Return',     s => fmt_ret(s.return_1y)],
      ['3Y Return',     s => fmt_ret(s.return_3y)],
      ['5Y Return',     s => fmt_ret(s.return_5y)],
      ['Since Inception',s=> fmt_ret(s.return_since)],
      ['NAV History',   s => s.nav_history_count > 0 ? `${s.nav_history_count} days` : '<span style="color:#dc2626">No history</span>'],
    ];

    function fmt_ret(v) {
      if (v == null) return '<span style="color:var(--text-muted)">—</span>';
      const cls = v >= 0 ? 'pos' : 'neg';
      return `<span class="nsc-ret ${cls}">${v>=0?'+':''}${Number(v).toFixed(2)}%</span>`;
    }

    const s1 = schemes[0], s2 = schemes[1];
    const rows = fields.map(([label, fn]) => `
      <div class="nsc-cmp-label">${label}</div>
      <div class="nsc-cmp-cell">${fn(s1)}</div>
      <div class="nsc-cmp-cell">${fn(s2)}</div>
    `).join('');

    document.getElementById('nscCompareContent').innerHTML = `
      <div class="nsc-cmp-grid">
        <div class="nsc-cmp-label"></div>
        <div class="nsc-cmp-cell hdr">${s1.scheme_name}</div>
        <div class="nsc-cmp-cell hdr">${s2.scheme_name}</div>
        ${rows}
      </div>
      <p style="padding:14px 16px;font-size:11px;color:var(--text-muted)">
        ℹ️ Returns are CAGR calculated from NAV history. Returns require NAV history to be available.
        Run NAV backfill from Admin panel to populate history.
      </p>
    `;
  },

  closeCompare() {
    document.getElementById('nscCompareModal').classList.remove('open');
  },
};

document.addEventListener('DOMContentLoaded', () => NSC.fetch());
</script>

<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
