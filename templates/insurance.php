<?php
/**
 * WealthDash — Insurance Portfolio
 * t321: Term Insurance Tracker
 * t322: Health Insurance Tracker
 * t459: Term Insurance Adequacy
 * t324: Premium Calendar
 */
define('WEALTHDASH', true);
require_once dirname(dirname(__FILE__)) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$pageTitle   = 'Insurance Portfolio';
$activePage  = 'insurance';

$db = DB::conn();

// Portfolios
$pStmt = $db->prepare("SELECT id, name FROM portfolios WHERE user_id=? ORDER BY name ASC");
$pStmt->execute([$currentUser['id']]);
$portfolios  = $pStmt->fetchAll();
$portfolioId = (int)($portfolios[0]['id'] ?? 0);

// Summary
$summary = DB::fetchOne(
    "SELECT
        COUNT(*)                                   AS total_count,
        COALESCE(SUM(ip.sum_assured),0)            AS total_sum,
        COALESCE(SUM(CASE WHEN ip.policy_type IN ('term','endowment','ulip','money_back') THEN ip.sum_assured ELSE 0 END),0) AS life_cover,
        COALESCE(SUM(CASE WHEN ip.policy_type='health' THEN ip.sum_assured ELSE 0 END),0) AS health_cover,
        COALESCE(SUM(ip.premium_amount),0)         AS total_premium,
        COUNT(CASE WHEN ip.next_premium_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 30 DAY) THEN 1 END) AS due_month
     FROM insurance_policies ip
     JOIN portfolios p ON p.id = ip.portfolio_id
     WHERE ip.status='active' AND p.user_id=?",
    [$currentUser['id']]
) ?: [];

$totalCount   = (int)($summary['total_count']   ?? 0);
$totalSum     = (float)($summary['total_sum']    ?? 0);
$lifeCover    = (float)($summary['life_cover']   ?? 0);
$healthCover  = (float)($summary['health_cover'] ?? 0);
$totalPremium = (float)($summary['total_premium']?? 0);
$dueMonth     = (int)($summary['due_month']      ?? 0);

// Upcoming premiums (next 60 days)
$upcoming = DB::fetchAll(
    "SELECT ip.*, DATEDIFF(ip.next_premium_date, CURDATE()) AS days_left
     FROM insurance_policies ip
     JOIN portfolios p ON p.id = ip.portfolio_id
     WHERE ip.status='active' AND p.user_id=?
       AND ip.next_premium_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(),INTERVAL 60 DAY)
     ORDER BY ip.next_premium_date ASC",
    [$currentUser['id']]
);

ob_start();
?>
<div class="page-header">
  <div>
    <h1 class="page-title">🛡️ Insurance Portfolio</h1>
    <p class="page-subtitle">Term · Health · ULIP · Coverage Adequacy · Premium Calendar</p>
  </div>
  <div class="page-header-actions">
    <button class="btn btn-outline btn-sm" onclick="Ins.openAdequacy()" title="Check coverage adequacy">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
      Adequacy Check
    </button>
    <button class="btn btn-primary" onclick="Ins.openAdd()">
      <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      Add Policy
    </button>
  </div>
</div>

<!-- Summary Cards -->
<div class="stats-grid" style="margin-bottom:20px;">
  <div class="stat-card">
    <div class="stat-label">Active Policies</div>
    <div class="stat-value"><?= $totalCount ?></div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Life Cover</div>
    <div class="stat-value text-primary"><?= inr($lifeCover) ?></div>
    <div class="stat-sub">Term + ULIP + Endowment</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Health Cover</div>
    <div class="stat-value text-success"><?= inr($healthCover) ?></div>
    <div class="stat-sub">Mediclaim + Family Floater</div>
  </div>
  <div class="stat-card">
    <div class="stat-label">Annual Premium</div>
    <div class="stat-value"><?= inr($totalPremium) ?></div>
    <div class="stat-sub">Total across all policies</div>
  </div>
  <?php if ($dueMonth > 0): ?>
  <div class="stat-card" style="border-left:3px solid var(--warning,#f59e0b);">
    <div class="stat-label" style="color:var(--warning,#f59e0b);">⚠️ Due This Month</div>
    <div class="stat-value" style="color:var(--warning,#f59e0b);"><?= $dueMonth ?> <?= $dueMonth === 1 ? 'policy' : 'policies' ?></div>
    <div class="stat-sub">Premium due in 30 days</div>
  </div>
  <?php endif; ?>
</div>

<?php if (!empty($upcoming)): ?>
<!-- Upcoming Premiums Alert -->
<div class="card" style="margin-bottom:20px;border-left:4px solid var(--warning,#f59e0b);">
  <div class="card-header" style="padding-bottom:12px;">
    <h3 class="card-title">⏰ Upcoming Premiums — Next 60 Days</h3>
  </div>
  <div class="table-wrapper">
    <table class="table" style="margin:0;">
      <thead>
        <tr>
          <th>Policy / Insurer</th>
          <th>Type</th>
          <th class="text-right">Premium</th>
          <th>Frequency</th>
          <th>Due Date</th>
          <th>Days Left</th>
        </tr>
      </thead>
      <tbody>
        <?php
        $typeLabels = ['term'=>'Term Life','health'=>'Health','ulip'=>'ULIP','endowment'=>'Endowment','money_back'=>'Money Back','vehicle'=>'Vehicle','home'=>'Home','travel'=>'Travel','other'=>'Other'];
        $freqLabels = ['monthly'=>'Monthly','quarterly'=>'Quarterly','half_yearly'=>'Half-Yearly','yearly'=>'Yearly','single'=>'Single'];
        foreach ($upcoming as $u):
            $days = (int)$u['days_left'];
            $urgency = $days <= 7 ? 'danger' : ($days <= 30 ? 'warning' : 'info');
        ?>
        <tr>
          <td>
            <div style="font-weight:600;"><?= e($u['insurer_name']) ?></div>
            <?php if ($u['policy_number']): ?><div style="font-size:11px;color:var(--text-muted);">#<?= e($u['policy_number']) ?></div><?php endif; ?>
          </td>
          <td><span class="badge badge-outline"><?= $typeLabels[$u['policy_type']] ?? strtoupper($u['policy_type']) ?></span></td>
          <td class="text-right fw-600"><?= inr($u['premium_amount']) ?></td>
          <td style="font-size:13px;color:var(--text-muted);"><?= $freqLabels[$u['premium_frequency']] ?? $u['premium_frequency'] ?></td>
          <td><?= fmtDate($u['next_premium_date']) ?></td>
          <td><span class="badge badge-<?= $urgency ?>"><?= $days ?> days</span></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<!-- Tabs -->
<div class="tabs" style="margin-bottom:16px;" id="insTabs">
  <button class="tab-btn active" onclick="Ins.switchTab('all', this)">All Policies</button>
  <button class="tab-btn" onclick="Ins.switchTab('term', this)">🛡️ Term Life</button>
  <button class="tab-btn" onclick="Ins.switchTab('health', this)">🏥 Health</button>
  <button class="tab-btn" onclick="Ins.switchTab('ulip', this)">📊 ULIP / Investment</button>
  <button class="tab-btn" onclick="Ins.switchTab('other', this)">📄 Other</button>
  <button class="tab-btn" onclick="Ins.switchTab('calendar', this)">📅 Premium Calendar</button>
</div>

<!-- Policy Table (All / Term / Health / ULIP / Other) -->
<div id="insTablePanel">
  <div class="card">
    <div class="card-header" style="padding-bottom:12px;">
      <h3 class="card-title" id="insTableTitle">All Policies</h3>
      <div style="display:flex;gap:8px;align-items:center;">
        <select id="insFilterPortfolio" class="form-select" style="width:170px;" onchange="Ins.load()">
          <option value="">All Portfolios</option>
          <?php foreach ($portfolios as $p): ?>
          <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    </div>
    <div class="table-responsive">
      <table class="table">
        <thead>
          <tr>
            <th>Policy / Insurer</th>
            <th>Insured</th>
            <th>Type</th>
            <th class="text-right">Sum Assured</th>
            <th class="text-right">Premium</th>
            <th>Next Due</th>
            <th>End / Maturity</th>
            <th>Nominee</th>
            <th class="text-center">Status</th>
            <th class="text-center" style="width:80px;">Actions</th>
          </tr>
        </thead>
        <tbody id="insBody">
          <tr><td colspan="10" class="text-center" style="padding:40px;"><span class="spinner"></span></td></tr>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Premium Calendar Panel -->
<div id="insCalendarPanel" style="display:none;">
  <div class="card">
    <div class="card-header">
      <h3 class="card-title">📅 Premium Calendar</h3>
      <div style="display:flex;gap:8px;align-items:center;">
        <button class="btn btn-ghost btn-sm" onclick="Ins.calNav(-1)">‹ Prev</button>
        <span id="insCalMonthLabel" style="font-weight:600;min-width:120px;text-align:center;"></span>
        <button class="btn btn-ghost btn-sm" onclick="Ins.calNav(1)">Next ›</button>
      </div>
    </div>
    <div id="insCalBody" style="padding:16px;"></div>
  </div>
</div>

<!-- ═══ ADD / EDIT MODAL ═══════════════════════════════════════════════════ -->
<div class="modal-overlay" id="modalInsurance" style="display:none;">
  <div class="modal" style="max-width:580px;">
    <div class="modal-header">
      <h3 class="modal-title" id="insModalTitle">Add Insurance Policy</h3>
      <button class="modal-close" onclick="hideModal('modalInsurance')">✕</button>
    </div>
    <div class="modal-body">
      <input type="hidden" id="insFId">

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Portfolio *</label>
          <select id="insFPortfolio" class="form-select">
            <?php foreach ($portfolios as $p): ?>
            <option value="<?= $p['id'] ?>"><?= e($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Policy Type *</label>
          <select id="insFType" class="form-select" onchange="Ins.onTypeChange()">
            <option value="term">🛡️ Term Life</option>
            <option value="health">🏥 Health / Mediclaim</option>
            <option value="ulip">📊 ULIP</option>
            <option value="endowment">📋 Endowment</option>
            <option value="money_back">💰 Money Back</option>
            <option value="vehicle">🚗 Vehicle</option>
            <option value="home">🏠 Home</option>
            <option value="travel">✈️ Travel</option>
            <option value="other">📄 Other</option>
          </select>
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Insurer / Company *</label>
          <input type="text" id="insFInsurer" class="form-input" placeholder="LIC, HDFC Life, Star Health…">
        </div>
        <div class="form-group">
          <label class="form-label">Policy Number</label>
          <input type="text" id="insFPolicyNo" class="form-input" placeholder="Optional">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Insured Person Name</label>
          <input type="text" id="insFInsuredName" class="form-input" placeholder="Name of insured">
        </div>
        <div class="form-group">
          <label class="form-label">Nominee</label>
          <input type="text" id="insFNominee" class="form-input" placeholder="Nominee name">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label" id="insFSumLabel">Sum Assured (₹) *</label>
          <input type="number" id="insFSumAssured" class="form-input" placeholder="e.g. 10000000" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Premium Amount (₹) *</label>
          <input type="number" id="insFPremium" class="form-input" placeholder="e.g. 25000" min="0">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Premium Frequency</label>
          <select id="insFFreq" class="form-select">
            <option value="yearly">Yearly</option>
            <option value="half_yearly">Half-Yearly</option>
            <option value="quarterly">Quarterly</option>
            <option value="monthly">Monthly</option>
            <option value="single">Single Premium</option>
          </select>
        </div>
        <div class="form-group">
          <label class="form-label">Start Date *</label>
          <input type="date" id="insFStart" class="form-input" value="<?= date('Y-m-d') ?>">
        </div>
      </div>

      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Next Premium Due Date</label>
          <input type="date" id="insFNextPrem" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Policy End / Expiry Date</label>
          <input type="date" id="insFEnd" class="form-input">
        </div>
      </div>

      <!-- Maturity fields (shown for ULIP / Endowment / Money Back) -->
      <div id="insFMaturityRow" class="form-row" style="display:none;">
        <div class="form-group">
          <label class="form-label">Maturity Date</label>
          <input type="date" id="insFMatDate" class="form-input">
        </div>
        <div class="form-group">
          <label class="form-label">Maturity Amount (₹)</label>
          <input type="number" id="insFMatAmt" class="form-input" placeholder="Expected maturity value">
        </div>
      </div>

      <div class="form-group">
        <label class="form-label">Notes</label>
        <input type="text" id="insFNotes" class="form-input" placeholder="Optional notes">
      </div>

      <div id="insFError" class="form-error" style="display:none;"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="hideModal('modalInsurance')">Cancel</button>
      <button class="btn btn-primary" onclick="Ins.save()" id="insFSaveBtn">Save Policy</button>
    </div>
  </div>
</div>

<!-- ═══ ADEQUACY CHECKER MODAL (t459) ═══════════════════════════════════════ -->
<div class="modal-overlay" id="modalAdequacy" style="display:none;">
  <div class="modal" style="max-width:540px;">
    <div class="modal-header">
      <h3 class="modal-title">🎯 Insurance Adequacy Check</h3>
      <button class="modal-close" onclick="hideModal('modalAdequacy')">✕</button>
    </div>
    <div class="modal-body">
      <p style="font-size:13px;color:var(--text-muted);margin:0 0 16px;">
        Calculate your ideal life cover using Human Life Value (HLV) and Expense methods.
      </p>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">Annual Income (₹) *</label>
          <input type="number" id="adqIncome" class="form-input" placeholder="e.g. 1200000">
        </div>
        <div class="form-group">
          <label class="form-label">Current Age *</label>
          <input type="number" id="adqAge" class="form-input" value="35" min="18" max="65">
        </div>
      </div>
      <div class="form-row">
        <div class="form-group">
          <label class="form-label">No. of Dependents</label>
          <input type="number" id="adqDependents" class="form-input" value="2" min="0">
        </div>
        <div class="form-group">
          <label class="form-label">Monthly Expenses (₹)</label>
          <input type="number" id="adqExpense" class="form-input" placeholder="e.g. 60000">
        </div>
      </div>
      <div class="form-group">
        <label class="form-label">Outstanding Liabilities (₹)</label>
        <input type="number" id="adqLiabilities" class="form-input" placeholder="Home loan + other loans">
      </div>
      <button class="btn btn-primary" style="width:100%;margin-top:4px;" onclick="Ins.calcAdequacy()">Calculate</button>

      <div id="adqResult" style="display:none;margin-top:20px;"></div>
    </div>
  </div>
</div>

<style>
.ins-type-badge { display:inline-flex;align-items:center;gap:4px;padding:3px 8px;border-radius:5px;font-size:11px;font-weight:600; }
.ins-badge-term     { background:rgba(59,130,246,.12);color:#3b82f6; }
.ins-badge-health   { background:rgba(16,185,129,.12);color:#10b981; }
.ins-badge-ulip     { background:rgba(139,92,246,.12);color:#8b5cf6; }
.ins-badge-endowment{ background:rgba(245,158,11,.12);color:#f59e0b; }
.ins-badge-vehicle  { background:rgba(107,114,128,.12);color:#6b7280; }
.ins-badge-other    { background:rgba(107,114,128,.1);color:var(--text-muted); }
.ins-badge-money_back,.ins-badge-home,.ins-badge-travel { background:rgba(107,114,128,.1);color:var(--text-muted); }
.adq-meter { height:8px;border-radius:4px;background:var(--bg-secondary);overflow:hidden;margin:6px 0; }
.adq-meter-fill { height:100%;border-radius:4px;transition:width .5s; }
.adq-row { display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px; }
.adq-row:last-child { border-bottom:none; }
</style>

<script>
const Ins = (() => {
  let _data  = [];
  let _tab   = 'all';
  let _calY  = new Date().getFullYear();
  let _calM  = new Date().getMonth() + 1;

  const TYPE_ICONS  = {term:'🛡️',health:'🏥',ulip:'📊',endowment:'📋',money_back:'💰',vehicle:'🚗',home:'🏠',travel:'✈️',other:'📄'};
  const TYPE_LABELS = {term:'Term Life',health:'Health',ulip:'ULIP',endowment:'Endowment',money_back:'Money Back',vehicle:'Vehicle',home:'Home',travel:'Travel',other:'Other'};
  const FREQ_LABELS = {monthly:'Monthly',quarterly:'Quarterly',half_yearly:'Half-Yearly',yearly:'Yearly',single:'Single'};
  const MATURITY_TYPES = ['ulip','endowment','money_back'];

  function fmtI(v) {
    v = Math.abs(parseFloat(v)||0);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1)  + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }
  function fmtD(d) {
    if (!d || d === '0000-00-00') return '—';
    const [y,m,day] = d.split('-');
    const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    return `${parseInt(day)} ${months[parseInt(m)-1]} ${y}`;
  }
  function esc(t) {
    const d = document.createElement('div');
    d.appendChild(document.createTextNode(t||''));
    return d.innerHTML;
  }

  async function load() {
    const pid = document.getElementById('insFilterPortfolio')?.value || '';
    const url = `${APP_URL}/api/router.php?action=insurance_list&portfolio_id=${pid}`;
    try {
      const res = await fetch(url);
      const d   = await res.json();
      _data = d.success ? (d.data || []) : [];
    } catch(e) { _data = []; }
    renderTable();
  }

  function filterByTab(data) {
    if (_tab === 'all') return data;
    if (_tab === 'other') return data.filter(p => !['term','health','ulip'].includes(p.policy_type));
    return data.filter(p => p.policy_type === _tab);
  }

  function renderTable() {
    const body  = document.getElementById('insBody');
    const data  = filterByTab(_data);
    const title = document.getElementById('insTableTitle');
    const tabTitleMap = {all:'All Policies',term:'Term Life Policies',health:'Health Policies',ulip:'ULIP & Investment Policies',other:'Vehicle · Home · Other'};
    if (title) title.textContent = tabTitleMap[_tab] || 'Policies';

    if (!data.length) {
      body.innerHTML = `<tr><td colspan="10" class="text-center" style="padding:48px;color:var(--text-muted);">
        No policies found. <a href="#" onclick="Ins.openAdd()" style="color:var(--primary);">Add your first policy →</a>
      </td></tr>`;
      return;
    }

    body.innerHTML = data.map(p => {
      const days    = parseInt(p.days_to_premium);
      const premBadge = !p.next_premium_date
        ? '<span style="color:var(--text-muted)">—</span>'
        : days < 0
          ? '<span class="badge badge-neutral">N/A</span>'
          : days === 0
            ? '<span class="badge badge-danger">Due Today!</span>'
            : days <= 7
              ? `<span class="badge badge-danger">${days}d ⚠️</span>`
              : days <= 30
                ? `<span class="badge badge-warning">${days}d</span>`
                : `<span style="font-size:12px;">${fmtD(p.next_premium_date)}</span>`;

      const endDate  = p.end_date || p.maturity_date;
      const daysEnd  = endDate ? Math.ceil((new Date(endDate) - new Date()) / 86400000) : null;
      const endBadge = !endDate
        ? '<span style="color:var(--text-muted)">—</span>'
        : daysEnd !== null && daysEnd < 0
          ? `<span class="badge badge-neutral">Expired</span>`
          : daysEnd !== null && daysEnd <= 90
            ? `<span class="badge badge-warning">${daysEnd}d</span>`
            : `<span style="font-size:12px;">${fmtD(endDate)}</span>`;

      const typeBadge = `<span class="ins-type-badge ins-badge-${p.policy_type}">${TYPE_ICONS[p.policy_type]||'📄'} ${TYPE_LABELS[p.policy_type]||p.policy_type}</span>`;

      return `<tr>
        <td>
          <div style="font-weight:600;">${esc(p.insurer_name)}</div>
          ${p.policy_number ? `<div style="font-size:11px;color:var(--text-muted);">#${esc(p.policy_number)}</div>` : ''}
        </td>
        <td style="font-size:13px;">${esc(p.insured_name||'—')}</td>
        <td>${typeBadge}</td>
        <td class="text-right fw-600">${fmtI(p.sum_assured)}</td>
        <td class="text-right">
          ${fmtI(p.premium_amount)}
          <div style="font-size:11px;color:var(--text-muted);">${FREQ_LABELS[p.premium_frequency]||p.premium_frequency}</div>
        </td>
        <td>${premBadge}</td>
        <td>${endBadge}</td>
        <td style="font-size:12px;">${esc(p.nominee||'—')}</td>
        <td class="text-center"><span class="badge badge-success">Active</span></td>
        <td class="text-center">
          <button class="btn btn-xs btn-ghost" onclick="Ins.openEdit(${p.id})" title="Edit">✎</button>
          <button class="btn btn-xs btn-ghost" onclick="Ins.del(${p.id})" title="Delete" style="color:var(--danger,#ef4444);">✕</button>
        </td>
      </tr>`;
    }).join('');
  }

  function switchTab(tab, btn) {
    _tab = tab;
    document.querySelectorAll('#insTabs .tab-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');

    const tablePanel = document.getElementById('insTablePanel');
    const calPanel   = document.getElementById('insCalendarPanel');

    if (tab === 'calendar') {
      tablePanel.style.display = 'none';
      calPanel.style.display   = '';
      renderCalendar();
    } else {
      tablePanel.style.display = '';
      calPanel.style.display   = 'none';
      renderTable();
    }
  }

  async function renderCalendar() {
    const months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    document.getElementById('insCalMonthLabel').textContent = `${months[_calM-1]} ${_calY}`;

    const res = await fetch(`${APP_URL}/api/router.php?action=insurance_premium_calendar&year=${_calY}&month=${_calM}`);
    const d   = await res.json();
    const premiums = d.success ? (d.data?.premiums || []) : [];
    const body = document.getElementById('insCalBody');

    if (!premiums.length) {
      body.innerHTML = `<div style="text-align:center;padding:40px;color:var(--text-muted);">No premiums due in ${months[_calM-1]} ${_calY}.</div>`;
      return;
    }

    const totalPrem = premiums.reduce((s,p) => s + (parseFloat(p.premium_amount)||0), 0);

    body.innerHTML = `
      <div style="margin-bottom:16px;padding:12px 16px;background:var(--bg-secondary);border-radius:8px;display:flex;justify-content:space-between;align-items:center;">
        <span style="font-weight:600;">${premiums.length} premium${premiums.length>1?'s':''} due</span>
        <span style="font-size:20px;font-weight:700;color:var(--warning,#f59e0b);">Total: ${fmtI(totalPrem)}</span>
      </div>
      <table class="table" style="margin:0;">
        <thead><tr>
          <th>Insurer</th><th>Type</th><th>Frequency</th>
          <th class="text-right">Amount</th><th>Due Date</th>
        </tr></thead>
        <tbody>
          ${premiums.map(p => `<tr>
            <td style="font-weight:600;">${esc(p.insurer_name)}</td>
            <td><span class="ins-type-badge ins-badge-${p.policy_type}">${TYPE_ICONS[p.policy_type]||'📄'} ${TYPE_LABELS[p.policy_type]||p.policy_type}</span></td>
            <td style="font-size:13px;color:var(--text-muted);">${FREQ_LABELS[p.premium_frequency]||p.premium_frequency}</td>
            <td class="text-right fw-600">${fmtI(p.premium_amount)}</td>
            <td>${fmtD(p.next_premium_date)}</td>
          </tr>`).join('')}
        </tbody>
      </table>`;
  }

  function calNav(dir) {
    _calM += dir;
    if (_calM > 12) { _calM = 1;  _calY++; }
    if (_calM < 1)  { _calM = 12; _calY--; }
    renderCalendar();
  }

  function onTypeChange() {
    const t = document.getElementById('insFType').value;
    const matRow   = document.getElementById('insFMaturityRow');
    const sumLabel = document.getElementById('insFSumLabel');
    matRow.style.display = MATURITY_TYPES.includes(t) ? '' : 'none';
    sumLabel.textContent = t === 'health' ? 'Cover Amount (₹) *' : 'Sum Assured (₹) *';
  }

  function clearForm() {
    ['insFId','insFPolicyNo','insFInsuredName','insFNominee','insFNotes','insFNextPrem','insFEnd','insFMatDate','insFMatAmt'].forEach(id => {
      const el = document.getElementById(id);
      if (el) el.value = '';
    });
    document.getElementById('insFInsurer').value  = '';
    document.getElementById('insFSumAssured').value = '';
    document.getElementById('insFPremium').value  = '';
    document.getElementById('insFType').value     = 'term';
    document.getElementById('insFFreq').value     = 'yearly';
    document.getElementById('insFStart').value    = new Date().toISOString().slice(0,10);
    document.getElementById('insFError').style.display = 'none';
    onTypeChange();
  }

  function openAdd() {
    clearForm();
    document.getElementById('insModalTitle').textContent = 'Add Insurance Policy';
    document.getElementById('insFSaveBtn').textContent = 'Save Policy';
    showModal('modalInsurance');
  }

  function openEdit(id) {
    const p = _data.find(x => x.id == id);
    if (!p) return;
    clearForm();
    document.getElementById('insFId').value         = p.id;
    document.getElementById('insFType').value       = p.policy_type;
    document.getElementById('insFInsurer').value    = p.insurer_name;
    document.getElementById('insFPolicyNo').value   = p.policy_number || '';
    document.getElementById('insFInsuredName').value= p.insured_name  || '';
    document.getElementById('insFNominee').value    = p.nominee       || '';
    document.getElementById('insFSumAssured').value = p.sum_assured;
    document.getElementById('insFPremium').value    = p.premium_amount;
    document.getElementById('insFFreq').value       = p.premium_frequency;
    document.getElementById('insFStart').value      = p.start_date    || '';
    document.getElementById('insFNextPrem').value   = p.next_premium_date || '';
    document.getElementById('insFEnd').value        = p.end_date      || '';
    document.getElementById('insFMatDate').value    = p.maturity_date || '';
    document.getElementById('insFMatAmt').value     = p.maturity_amount || '';
    document.getElementById('insFNotes').value      = p.notes         || '';
    document.getElementById('insModalTitle').textContent = 'Edit Policy';
    document.getElementById('insFSaveBtn').textContent   = 'Update Policy';
    onTypeChange();
    showModal('modalInsurance');
  }

  async function save() {
    const insurerName = document.getElementById('insFInsurer').value.trim();
    const sumAssured  = document.getElementById('insFSumAssured').value;
    const premium     = document.getElementById('insFPremium').value;
    const errEl       = document.getElementById('insFError');

    if (!insurerName) { errEl.textContent='Insurer name is required.'; errEl.style.display=''; return; }
    if (!sumAssured || parseFloat(sumAssured)<=0) { errEl.textContent='Sum assured must be > 0.'; errEl.style.display=''; return; }
    errEl.style.display = 'none';

    const id = document.getElementById('insFId').value;
    const payload = {
      action:          id ? 'insurance_edit' : 'insurance_add',
      id:              id || undefined,
      portfolio_id:    document.getElementById('insFPortfolio').value,
      policy_type:     document.getElementById('insFType').value,
      insurer_name:    insurerName,
      policy_number:   document.getElementById('insFPolicyNo').value,
      insured_name:    document.getElementById('insFInsuredName').value,
      sum_assured:     sumAssured,
      premium_amount:  premium,
      premium_frequency: document.getElementById('insFFreq').value,
      start_date:      document.getElementById('insFStart').value,
      next_premium_date: document.getElementById('insFNextPrem').value,
      end_date:        document.getElementById('insFEnd').value,
      maturity_date:   document.getElementById('insFMatDate').value,
      maturity_amount: document.getElementById('insFMatAmt').value,
      nominee:         document.getElementById('insFNominee').value,
      notes:           document.getElementById('insFNotes').value,
    };

    const btn = document.getElementById('insFSaveBtn');
    btn.disabled = true; btn.textContent = 'Saving…';
    const res = await apiPost(payload);
    btn.disabled = false; btn.textContent = id ? 'Update Policy' : 'Save Policy';

    if (res.success) {
      hideModal('modalInsurance');
      showToast(id ? 'Policy updated!' : 'Policy added!', 'success');
      load();
    } else {
      errEl.textContent = res.message || 'Save failed.';
      errEl.style.display = '';
    }
  }

  async function del(id) {
    if (!confirm('Remove this policy from your portfolio?')) return;
    const res = await apiPost({action:'insurance_delete', id});
    if (res.success) { showToast('Policy removed.', 'success'); load(); }
  }

  function openAdequacy() { showModal('modalAdequacy'); }

  async function calcAdequacy() {
    const income      = parseFloat(document.getElementById('adqIncome').value) || 0;
    const age         = parseInt(document.getElementById('adqAge').value)       || 35;
    const dependents  = parseInt(document.getElementById('adqDependents').value)|| 0;
    const expense     = parseFloat(document.getElementById('adqExpense').value) || 0;
    const liabilities = parseFloat(document.getElementById('adqLiabilities').value) || 0;

    if (!income) { showToast('Enter your annual income first.', 'warning'); return; }

    const res = await apiPost({
      action:'insurance_adequacy',
      annual_income: income,
      age, dependents,
      monthly_expense: expense,
      liabilities
    });

    const r   = document.getElementById('adqResult');
    if (!res.success) { r.innerHTML = `<div class="form-error">${res.message}</div>`; r.style.display=''; return; }
    const d   = res.data;

    const ratingColors = {Excellent:'#10b981',Good:'#3b82f6',Adequate:'#f59e0b',Low:'#f97316',Critical:'#ef4444'};
    const ratingColor  = ratingColors[d.rating] || '#ef4444';
    const pct          = Math.min(100, Math.round((d.existing_cover / d.recommended_cover) * 100));
    const meterColor   = pct >= 85 ? '#10b981' : pct >= 60 ? '#f59e0b' : '#ef4444';

    const healthPct    = d.recommended_health > 0 ? Math.min(100, Math.round((d.existing_health / d.recommended_health)*100)) : 0;
    const healthColor  = healthPct >= 85 ? '#10b981' : healthPct >= 50 ? '#f59e0b' : '#ef4444';

    r.innerHTML = `
      <div style="text-align:center;margin-bottom:20px;">
        <div style="font-size:32px;font-weight:800;color:${ratingColor};">${d.rating}</div>
        <div style="font-size:13px;color:var(--text-muted);">Coverage Rating</div>
      </div>

      <div style="background:var(--bg-secondary);border-radius:8px;padding:16px;margin-bottom:16px;">
        <div style="font-weight:600;margin-bottom:10px;">Life Cover</div>
        <div class="adq-row"><span>Existing Cover</span><span style="font-weight:600;">${fmtI(d.existing_cover)}</span></div>
        <div class="adq-row"><span>Recommended (HLV Method)</span><span>${fmtI(d.recommended_cover)}</span></div>
        <div class="adq-row"><span>Shortfall</span><span style="color:${d.shortfall>0?'#ef4444':'#10b981'};font-weight:600;">${d.shortfall>0?fmtI(d.shortfall)+'  ⚠️':'✅ Adequate'}</span></div>
        <div style="margin-top:8px;">
          <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:4px;">
            <span>Coverage</span><span>${pct}%</span>
          </div>
          <div class="adq-meter"><div class="adq-meter-fill" style="width:${pct}%;background:${meterColor};"></div></div>
        </div>
      </div>

      <div style="background:var(--bg-secondary);border-radius:8px;padding:16px;margin-bottom:16px;">
        <div style="font-weight:600;margin-bottom:10px;">Health Cover</div>
        <div class="adq-row"><span>Existing Health Cover</span><span style="font-weight:600;">${fmtI(d.existing_health)}</span></div>
        <div class="adq-row"><span>Recommended (₹10L/person)</span><span>${fmtI(d.recommended_health)}</span></div>
        <div class="adq-row"><span>Shortfall</span><span style="color:${d.health_shortfall>0?'#ef4444':'#10b981'};font-weight:600;">${d.health_shortfall>0?fmtI(d.health_shortfall)+'  ⚠️':'✅ Adequate'}</span></div>
        <div style="margin-top:8px;">
          <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-muted);margin-bottom:4px;">
            <span>Coverage</span><span>${healthPct}%</span>
          </div>
          <div class="adq-meter"><div class="adq-meter-fill" style="width:${healthPct}%;background:${healthColor};"></div></div>
        </div>
      </div>

      <div style="background:var(--bg-secondary);border-radius:8px;padding:16px;">
        <div style="font-weight:600;margin-bottom:10px;">Calculation Breakdown</div>
        <div class="adq-row"><span>HLV Cover (income × ${d.years_to_retire}yr)</span><span>${fmtI(d.hlv_cover)}</span></div>
        <div class="adq-row"><span>Expense Method (10× annual)</span><span>${fmtI(d.expense_cover)}</span></div>
        <div class="adq-row"><span>Liability-adjusted</span><span>${fmtI(d.liability_cover)}</span></div>
        <div class="adq-row"><span>Coverage Ratio (x income)</span><span style="font-weight:600;">${d.coverage_ratio}×</span></div>
      </div>

      ${d.shortfall > 0 ? `<div style="margin-top:16px;padding:12px;background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.2);border-radius:8px;font-size:13px;">
        💡 <strong>Action:</strong> Consider a pure term plan for ₹${fmtI(d.shortfall)} additional cover.
        Annual premium for this cover would be approximately ₹${fmtI(d.shortfall * 0.0004)}–₹${fmtI(d.shortfall * 0.0008)} (est.).
      </div>` : `<div style="margin-top:16px;padding:12px;background:rgba(16,185,129,.07);border:1px solid rgba(16,185,129,.2);border-radius:8px;font-size:13px;">
        ✅ <strong>Great!</strong> Your life coverage appears adequate based on the inputs provided.
      </div>`}`;
    r.style.display = '';
  }

  return { load, switchTab, calNav, openAdd, openEdit, save, del, onTypeChange, openAdequacy, calcAdequacy };
})();

document.addEventListener('DOMContentLoaded', Ins.load);
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
