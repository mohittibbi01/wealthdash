<?php
/**
 * WealthDash — Insurance Portfolio
 * t321: Term Insurance Tracker
 * t322: Health Insurance Tracker
 * t459: Term Insurance Adequacy
 * t324: Premium Calendar
 * t460: Health Insurance Tracker (claims, members, waiting periods, NCB, TPA)
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
  <button class="tab-btn" onclick="Ins.switchTab('health_tracker', this)">🔬 Health Tracker</button>
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

<!-- ══════════════════════════════════════════════════════════════════════════
     t460: HEALTH INSURANCE TRACKER PANEL
     Shows when tab = 'health_tracker'
═══════════════════════════════════════════════════════════════════════════ -->
<div id="healthTrackerPanel" style="display:none;">

  <!-- ── Summary Cards ── -->
  <div id="htSummaryCards" class="stats-grid" style="margin-bottom:20px;">
    <div class="stat-card"><div class="stat-label">Health Policies</div><div class="stat-value" id="htStatPolicies">—</div></div>
    <div class="stat-card"><div class="stat-label">Total Cover</div><div class="stat-value text-success" id="htStatCover">—</div></div>
    <div class="stat-card"><div class="stat-label">Family Members</div><div class="stat-value" id="htStatMembers">—</div></div>
    <div class="stat-card"><div class="stat-label">Claimed This FY</div><div class="stat-value text-danger" id="htStatClaimed">—</div></div>
    <div class="stat-card"><div class="stat-label">Settled This FY</div><div class="stat-value" id="htStatSettled">—</div></div>
  </div>

  <!-- ── Expiring Soon Alert ── -->
  <div id="htExpiryAlert" style="display:none;margin-bottom:16px;padding:12px 16px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.4);border-radius:8px;font-size:13px;"></div>

  <!-- ── Policy selector + sub-tabs ── -->
  <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px;">
    <select id="htPolicySelect" class="form-select" style="min-width:240px;" onchange="HT.loadPolicy()">
      <option value="">— Select Health Policy —</option>
    </select>
    <div style="display:flex;gap:0;border:1px solid var(--border);border-radius:8px;overflow:hidden;">
      <button class="ht-sub-btn active" onclick="HT.subTab('details', this)" style="padding:7px 14px;font-size:12px;font-weight:600;border:none;background:var(--primary);color:#fff;cursor:pointer;">📋 Details</button>
      <button class="ht-sub-btn" onclick="HT.subTab('members', this)" style="padding:7px 14px;font-size:12px;font-weight:600;border:none;background:none;cursor:pointer;border-left:1px solid var(--border);">👨‍👩‍👧 Members</button>
      <button class="ht-sub-btn" onclick="HT.subTab('claims', this)" style="padding:7px 14px;font-size:12px;font-weight:600;border:none;background:none;cursor:pointer;border-left:1px solid var(--border);">🏥 Claims</button>
    </div>
    <button class="btn btn-ghost btn-sm" onclick="HT.loadSummary()">↻ Refresh</button>
  </div>

  <!-- ── DETAILS sub-panel ── -->
  <div id="htSubDetails">
    <div id="htDetailCards" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:16px;margin-bottom:16px;">
      <div class="card" style="padding:32px;text-align:center;color:var(--text-muted);">Select a policy above to see details</div>
    </div>
  </div>

  <!-- ── MEMBERS sub-panel ── -->
  <div id="htSubMembers" style="display:none;">
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title">👨‍👩‍👧 Covered Members</h3>
        <button class="btn btn-primary btn-sm" onclick="HT.openMemberForm()" id="htBtnAddMember" disabled>+ Add Member</button>
      </div>
      <div class="card-body" id="htMemberList"><p class="text-muted">Select a policy first.</p></div>
    </div>
  </div>

  <!-- ── CLAIMS sub-panel ── -->
  <div id="htSubClaims" style="display:none;">
    <div class="card">
      <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
        <h3 class="card-title">🏥 Claims History</h3>
        <button class="btn btn-primary btn-sm" onclick="HT.openClaimForm()" id="htBtnAddClaim" disabled>+ Add Claim</button>
      </div>
      <div class="table-responsive">
        <table class="table">
          <thead>
            <tr>
              <th>Date</th><th>Member</th><th>Hospital</th><th>Diagnosis</th>
              <th class="text-right">Claimed</th><th class="text-right">Settled</th>
              <th>Type</th><th>Status</th><th class="text-center">Actions</th>
            </tr>
          </thead>
          <tbody id="htClaimBody"><tr><td colspan="9" class="text-center" style="padding:24px;color:var(--text-muted);">Select a policy to view claims.</td></tr></tbody>
        </table>
      </div>
      <!-- Claims summary -->
      <div id="htClaimSummaryBar" style="display:none;padding:12px 20px;background:var(--bg-secondary);border-top:1px solid var(--border);display:flex;gap:24px;flex-wrap:wrap;font-size:13px;"></div>
    </div>
  </div>

</div>

<!-- ─── Health Details Edit Modal ─── -->
<div id="htDetailsModal" class="modal-overlay" style="display:none;">
  <div class="modal" style="max-width:600px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title">✏️ Edit Health Policy Details</h3>
      <button class="modal-close" onclick="HT.closeModal('htDetailsModal')">✕</button>
    </div>
    <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;max-height:70vh;overflow-y:auto;">
      <input type="hidden" id="htDmId">
      <div>
        <label class="form-label">Health Plan Type</label>
        <select id="htDmType" class="form-select">
          <option value="individual">Individual</option>
          <option value="family_floater">Family Floater</option>
          <option value="senior_citizen">Senior Citizen</option>
          <option value="super_topup">Super Top-Up</option>
          <option value="critical_illness">Critical Illness</option>
          <option value="personal_accident">Personal Accident</option>
        </select>
      </div>
      <div>
        <label class="form-label">Room Rent Limit (₹/day)</label>
        <input type="number" id="htDmRoomRent" class="form-input" placeholder="Leave blank for no limit">
      </div>
      <div>
        <label class="form-label">Copay %</label>
        <input type="number" id="htDmCopay" class="form-input" placeholder="0" min="0" max="50">
      </div>
      <div>
        <label class="form-label">Deductible (₹)</label>
        <input type="number" id="htDmDeductible" class="form-input" placeholder="0">
      </div>
      <div>
        <label class="form-label">Initial Waiting Period (days)</label>
        <input type="number" id="htDmWpInit" class="form-input" value="30">
      </div>
      <div>
        <label class="form-label">PED Waiting Period (days)</label>
        <input type="number" id="htDmWpPd" class="form-input" value="1095">
      </div>
      <div>
        <label class="form-label">TPA Name</label>
        <input type="text" id="htDmTpa" class="form-input" placeholder="e.g. Medi Assist">
      </div>
      <div>
        <label class="form-label">TPA Contact / Helpline</label>
        <input type="text" id="htDmTpaContact" class="form-input" placeholder="1800-xxx-xxxx">
      </div>
      <div>
        <label class="form-label">No-Claim Bonus (%)</label>
        <input type="number" id="htDmNcb" class="form-input" placeholder="0" step="5" min="0" max="100">
      </div>
      <div style="display:flex;flex-direction:column;gap:8px;padding-top:4px;">
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
          <input type="checkbox" id="htDmRestore"> Restoration Benefit
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
          <input type="checkbox" id="htDmDaycare" checked> Day-care Covered
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
          <input type="checkbox" id="htDmMaternity"> Maternity Benefit
        </label>
        <label style="display:flex;align-items:center;gap:8px;font-size:13px;cursor:pointer;">
          <input type="checkbox" id="htDmPortability"> Ported from another insurer
        </label>
      </div>
      <div>
        <label class="form-label">Ported from (Insurer name)</label>
        <input type="text" id="htDmPortFrom" class="form-input" placeholder="Previous insurer">
      </div>
      <div style="grid-column:span 2;">
        <label class="form-label">Network Hospitals (key ones)</label>
        <textarea id="htDmNetwork" class="form-input" rows="3" placeholder="Apollo, Fortis, Max Healthcare..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="HT.closeModal('htDetailsModal')">Cancel</button>
      <button class="btn btn-primary" onclick="HT.saveDetails()">Save Details</button>
    </div>
  </div>
</div>

<!-- ─── Member Form Modal ─── -->
<div id="htMemberModal" class="modal-overlay" style="display:none;">
  <div class="modal" style="max-width:480px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="htMemberModalTitle">Add Member</h3>
      <button class="modal-close" onclick="HT.closeModal('htMemberModal')">✕</button>
    </div>
    <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
      <input type="hidden" id="htMmId">
      <div style="grid-column:span 2;">
        <label class="form-label">Member Name *</label>
        <input type="text" id="htMmName" class="form-input" placeholder="Full name">
      </div>
      <div>
        <label class="form-label">Relation *</label>
        <select id="htMmRelation" class="form-select">
          <option value="self">Self</option>
          <option value="spouse">Spouse</option>
          <option value="son">Son</option>
          <option value="daughter">Daughter</option>
          <option value="father">Father</option>
          <option value="mother">Mother</option>
          <option value="father_in_law">Father-in-law</option>
          <option value="mother_in_law">Mother-in-law</option>
          <option value="other">Other</option>
        </select>
      </div>
      <div>
        <label class="form-label">Gender</label>
        <select id="htMmGender" class="form-select">
          <option value="">—</option>
          <option value="male">Male</option>
          <option value="female">Female</option>
        </select>
      </div>
      <div>
        <label class="form-label">Date of Birth</label>
        <input type="date" id="htMmDob" class="form-input">
      </div>
      <div>
        <label class="form-label">Individual SI (₹) if not floater</label>
        <input type="number" id="htMmSI" class="form-input" placeholder="—">
      </div>
      <div style="grid-column:span 2;">
        <label class="form-label">Pre-existing Conditions</label>
        <input type="text" id="htMmPreExist" class="form-input" placeholder="Diabetes, Hypertension...">
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="HT.closeModal('htMemberModal')">Cancel</button>
      <button class="btn btn-primary" onclick="HT.saveMember()">Save Member</button>
    </div>
  </div>
</div>

<!-- ─── Claim Form Modal ─── -->
<div id="htClaimModal" class="modal-overlay" style="display:none;">
  <div class="modal" style="max-width:560px;width:95%;">
    <div class="modal-header">
      <h3 class="modal-title" id="htClaimModalTitle">Add Claim</h3>
      <button class="modal-close" onclick="HT.closeModal('htClaimModal')">✕</button>
    </div>
    <div class="modal-body" style="display:grid;grid-template-columns:1fr 1fr;gap:14px;max-height:70vh;overflow-y:auto;">
      <input type="hidden" id="htCmId">
      <div>
        <label class="form-label">Claim Date *</label>
        <input type="date" id="htCmDate" class="form-input" value="<?= date('Y-m-d') ?>">
      </div>
      <div>
        <label class="form-label">Claim Number</label>
        <input type="text" id="htCmNumber" class="form-input" placeholder="CL-2025-XXXX">
      </div>
      <div>
        <label class="form-label">Claim Type</label>
        <select id="htCmType" class="form-select">
          <option value="cashless">Cashless</option>
          <option value="reimbursement">Reimbursement</option>
        </select>
      </div>
      <div>
        <label class="form-label">Member</label>
        <select id="htCmMember" class="form-select"><option value="">— Select —</option></select>
      </div>
      <div style="grid-column:span 2;">
        <label class="form-label">Hospital Name</label>
        <input type="text" id="htCmHospital" class="form-input" placeholder="Apollo Hospital, Delhi">
      </div>
      <div style="grid-column:span 2;">
        <label class="form-label">Diagnosis / Reason</label>
        <input type="text" id="htCmDiagnosis" class="form-input" placeholder="Appendectomy, Dengue...">
      </div>
      <div>
        <label class="form-label">Admission Date</label>
        <input type="date" id="htCmAdmission" class="form-input">
      </div>
      <div>
        <label class="form-label">Discharge Date</label>
        <input type="date" id="htCmDischarge" class="form-input">
      </div>
      <div>
        <label class="form-label">Claimed Amount (₹) *</label>
        <input type="number" id="htCmClaimed" class="form-input" placeholder="0">
      </div>
      <div>
        <label class="form-label">Approved Amount (₹)</label>
        <input type="number" id="htCmApproved" class="form-input" placeholder="—">
      </div>
      <div>
        <label class="form-label">Settled Amount (₹)</label>
        <input type="number" id="htCmSettled" class="form-input" placeholder="—">
      </div>
      <div>
        <label class="form-label">Deducted (Copay+Ded) (₹)</label>
        <input type="number" id="htCmDeducted" class="form-input" placeholder="0">
      </div>
      <div>
        <label class="form-label">Settlement Date</label>
        <input type="date" id="htCmSettleDate" class="form-input">
      </div>
      <div>
        <label class="form-label">Status</label>
        <select id="htCmStatus" class="form-select">
          <option value="submitted">Submitted</option>
          <option value="under_review">Under Review</option>
          <option value="approved">Approved</option>
          <option value="partially_approved">Partially Approved</option>
          <option value="settled">Settled ✅</option>
          <option value="rejected">Rejected ❌</option>
          <option value="withdrawn">Withdrawn</option>
        </select>
      </div>
      <div style="grid-column:span 2;">
        <label class="form-label">Rejection Reason (if rejected)</label>
        <input type="text" id="htCmRejectRsn" class="form-input" placeholder="—">
      </div>
      <div style="grid-column:span 2;">
        <label class="form-label">Notes</label>
        <textarea id="htCmNotes" class="form-input" rows="2" placeholder="Additional info..."></textarea>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="HT.closeModal('htClaimModal')">Cancel</button>
      <button class="btn btn-primary" onclick="HT.saveClaim()">Save Claim</button>
    </div>
  </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════════════════════
   t460: Health Insurance Tracker — HT namespace
══════════════════════════════════════════════════════════════════════════ */
const HT = (() => {
  'use strict';

  let _summary   = null;  // full summary response
  let _policyId  = null;  // currently selected policy id
  let _members   = [];    // members of current policy
  let _claims    = [];    // claims of current policy
  let _subTab    = 'details';

  const $ = id => document.getElementById(id);
  const fmtI = v => {
    const n = parseFloat(v) || 0;
    if (Math.abs(n) >= 1e7) return '₹' + (n/1e7).toFixed(2) + 'Cr';
    if (Math.abs(n) >= 1e5) return '₹' + (n/1e5).toFixed(2) + 'L';
    return '₹' + n.toLocaleString('en-IN');
  };
  const fmtD = d => d ? new Date(d).toLocaleDateString('en-IN', {day:'2-digit',month:'short',year:'numeric'}) : '—';
  const claimStatusClass = s => ({
    submitted:'color:#6366f1', under_review:'color:#f59e0b', approved:'color:#10b981',
    partially_approved:'color:#f59e0b', settled:'color:#16a34a',
    rejected:'color:#ef4444', withdrawn:'color:var(--text-muted)'
  }[s] || '');
  const claimStatusLabel = s => ({
    submitted:'Submitted', under_review:'Under Review', approved:'Approved',
    partially_approved:'Partial', settled:'Settled ✅', rejected:'Rejected ❌', withdrawn:'Withdrawn'
  }[s] || s);

  // ── Load global summary ──────────────────────────────────────────────────
  async function loadSummary() {
    try {
      const res = await fetch(`${APP_URL}/api/router.php?action=health_summary`);
      const d   = await res.json();
      if (!d.success) return;
      _summary = d.data;

      // Update stat cards
      $('htStatPolicies').textContent = d.data.policy_count;
      $('htStatCover').textContent    = fmtI(d.data.total_cover);
      $('htStatMembers').textContent  = d.data.total_members;
      $('htStatClaimed').textContent  = fmtI(d.data.claimed_fy);
      $('htStatSettled').textContent  = fmtI(d.data.settled_fy);

      // Expiry alert
      const ea = $('htExpiryAlert');
      if (d.data.expiring_soon && d.data.expiring_soon.length > 0) {
        ea.style.display = '';
        ea.innerHTML = `⚠️ <strong>${d.data.expiring_soon.length} health polic${d.data.expiring_soon.length>1?'ies':'y'} expiring soon:</strong> ` +
          d.data.expiring_soon.map(p => `<strong>${p.insurer_name}</strong> (${p.days_to_expiry} days)`).join(', ');
      } else {
        ea.style.display = 'none';
      }

      // Populate policy selector
      const sel = $('htPolicySelect');
      const prev = sel.value;
      sel.innerHTML = '<option value="">— Select Health Policy —</option>';
      (d.data.policies || []).forEach(p => {
        const opt = document.createElement('option');
        opt.value = p.id;
        opt.textContent = `${p.insurer_name}${p.policy_number ? ' ('+p.policy_number+')' : ''} — ${fmtI(p.effective_cover)} cover`;
        sel.appendChild(opt);
      });
      if (prev) sel.value = prev;
      if (sel.value) loadPolicy();
    } catch(e) { console.error('HT.loadSummary:', e); }
  }

  // ── Load per-policy data ─────────────────────────────────────────────────
  async function loadPolicy() {
    _policyId = parseInt($('htPolicySelect').value) || null;
    $('htBtnAddMember').disabled = !_policyId;
    $('htBtnAddClaim').disabled  = !_policyId;
    if (!_policyId) return;

    const pol = (_summary?.policies || []).find(p => p.id == _policyId);
    renderDetailCards(pol);

    // Load members + claims in parallel
    const [mRes, cRes] = await Promise.all([
      fetch(`${APP_URL}/api/router.php?action=health_members_list&policy_id=${_policyId}`).then(r=>r.json()),
      fetch(`${APP_URL}/api/router.php?action=health_claims_list&policy_id=${_policyId}`).then(r=>r.json()),
    ]);

    _members = mRes.success ? mRes.data : [];
    _claims  = cRes.success ? cRes.data : [];

    renderMembers();
    renderClaims();
  }

  // ── Render detail cards ──────────────────────────────────────────────────
  function renderDetailCards(pol) {
    if (!pol) { $('htDetailCards').innerHTML = '<div class="card" style="padding:24px;text-align:center;color:var(--text-muted);">Select a policy above.</div>'; return; }

    const wpInitDays = parseInt(pol.waiting_period_initial) || 30;
    const wpPdDays   = parseInt(pol.waiting_period_pd) || 1095;
    const startDate  = new Date(pol.start_date);
    const todayDate  = new Date();
    const daysActive = Math.floor((todayDate - startDate) / 86400000);
    const wpInitDone = daysActive >= wpInitDays;
    const wpPdDone   = daysActive >= wpPdDays;

    $('htDetailCards').innerHTML = `
      <!-- Policy Overview -->
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h4 class="card-title" style="margin:0;">📋 Policy Details</h4>
          <button class="btn btn-ghost btn-sm" onclick="HT.openDetailsModal(${pol.id})">✎ Edit</button>
        </div>
        <div class="card-body" style="font-size:13px;line-height:2.1;">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:4px 16px;">
            <div style="color:var(--text-muted);">Insurer</div><div><strong>${pol.insurer_name}</strong></div>
            <div style="color:var(--text-muted);">Policy No.</div><div>${pol.policy_number || '—'}</div>
            <div style="color:var(--text-muted);">Plan Type</div><div>${pol.health_type ? pol.health_type.replace(/_/g,' ').replace(/\b\w/g,c=>c.toUpperCase()) : '—'}</div>
            <div style="color:var(--text-muted);">Sum Insured</div><div><strong>${fmtI(pol.sum_assured)}</strong></div>
            <div style="color:var(--text-muted);">Effective Cover (NCB)</div><div><strong style="color:var(--success,#16a34a);">${fmtI(pol.effective_cover)}</strong></div>
            <div style="color:var(--text-muted);">No-Claim Bonus</div>
            <div style="display:flex;align-items:center;gap:8px;">
              <strong>${parseFloat(pol.no_claim_bonus)||0}%</strong>
              <button onclick="HT.openNcb(${pol.id}, ${parseFloat(pol.no_claim_bonus)||0})" class="btn btn-ghost btn-sm" style="padding:1px 6px;font-size:11px;">Update</button>
            </div>
            <div style="color:var(--text-muted);">Copay</div><div>${parseFloat(pol.copay_pct)||0}%</div>
            <div style="color:var(--text-muted);">Deductible</div><div>${pol.deductible ? fmtI(pol.deductible) : 'None'}</div>
            <div style="color:var(--text-muted);">Room Rent Limit</div><div>${pol.room_rent_limit ? fmtI(pol.room_rent_limit)+'/day' : 'No Limit'}</div>
            <div style="color:var(--text-muted);">Start / End</div><div>${fmtD(pol.start_date)} → ${fmtD(pol.end_date)}</div>
            <div style="color:var(--text-muted);">Next Premium</div><div>${fmtD(pol.next_premium_date)}</div>
            <div style="color:var(--text-muted);">TPA</div><div>${pol.tpa_name || '—'}${pol.tpa_contact ? ' · '+pol.tpa_contact : ''}</div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
            ${pol.restore_benefit   == 1 ? '<span style="font-size:11px;padding:2px 8px;background:rgba(99,102,241,.12);color:#6366f1;border-radius:12px;">✅ Restoration</span>' : ''}
            ${pol.daycare_covered   == 1 ? '<span style="font-size:11px;padding:2px 8px;background:rgba(16,185,129,.12);color:#059669;border-radius:12px;">✅ Day-care</span>' : ''}
            ${pol.maternity_covered == 1 ? '<span style="font-size:11px;padding:2px 8px;background:rgba(236,72,153,.12);color:#db2777;border-radius:12px;">✅ Maternity</span>' : ''}
            ${pol.portability_done  == 1 ? '<span style="font-size:11px;padding:2px 8px;background:rgba(245,158,11,.12);color:#d97706;border-radius:12px;">🔄 Ported</span>' : ''}
          </div>
        </div>
      </div>

      <!-- Waiting Periods -->
      <div class="card">
        <div class="card-header"><h4 class="card-title" style="margin:0;">⏳ Waiting Periods</h4></div>
        <div class="card-body" style="font-size:13px;">
          <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span>Initial Waiting (${wpInitDays} days)</span>
              <strong style="color:${wpInitDone?'#16a34a':'#f59e0b'};">${wpInitDone ? '✅ Done ('+daysActive+' days)' : '⏳ '+(wpInitDays - daysActive)+' days left'}</strong>
            </div>
            <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
              <div style="height:100%;width:${Math.min(100, daysActive/wpInitDays*100).toFixed(1)}%;background:${wpInitDone?'#16a34a':'#f59e0b'};border-radius:4px;transition:width .4s;"></div>
            </div>
          </div>
          <div style="margin-bottom:16px;">
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span>Pre-existing Disease (${Math.round(wpPdDays/365*10)/10} yrs)</span>
              <strong style="color:${wpPdDone?'#16a34a':'#ef4444'};">${wpPdDone ? '✅ Done' : '❌ '+(wpPdDays - daysActive)+' days left'}</strong>
            </div>
            <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
              <div style="height:100%;width:${Math.min(100, daysActive/wpPdDays*100).toFixed(1)}%;background:${wpPdDone?'#16a34a':'#ef4444'};border-radius:4px;transition:width .4s;"></div>
            </div>
          </div>
          ${pol.maternity_covered == 1 ? `
          <div>
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span>Maternity (${pol.maternity_waiting||730} days)</span>
              <strong style="color:${daysActive>=(pol.maternity_waiting||730)?'#16a34a':'#db2777'};">${daysActive>=(pol.maternity_waiting||730) ? '✅ Done' : '⏳ '+((pol.maternity_waiting||730) - daysActive)+' days left'}</strong>
            </div>
            <div style="height:8px;background:var(--border);border-radius:4px;overflow:hidden;">
              <div style="height:100%;width:${Math.min(100, daysActive/(pol.maternity_waiting||730)*100).toFixed(1)}%;background:#db2777;border-radius:4px;"></div>
            </div>
          </div>` : ''}
          <div style="margin-top:16px;padding:10px;background:var(--bg-secondary);border-radius:8px;font-size:12px;color:var(--text-muted);">
            Policy active for <strong style="color:var(--text);">${daysActive} days</strong> (since ${fmtD(pol.start_date)})
          </div>
        </div>
      </div>

      <!-- Network Hospitals -->
      <div class="card">
        <div class="card-header" style="display:flex;justify-content:space-between;align-items:center;">
          <h4 class="card-title" style="margin:0;">🏥 Network Hospitals</h4>
          <button class="btn btn-ghost btn-sm" onclick="HT.openDetailsModal(${pol.id})">✎</button>
        </div>
        <div class="card-body">
          ${pol.network_hospitals
            ? `<div style="font-size:13px;line-height:1.8;white-space:pre-wrap;">${pol.network_hospitals}</div>`
            : `<p style="color:var(--text-muted);font-size:13px;">No network hospitals added. <a href="#" onclick="HT.openDetailsModal(${pol.id});return false;">Add now →</a></p>`
          }
        </div>
      </div>

      <!-- FY Utilization -->
      <div class="card">
        <div class="card-header"><h4 class="card-title" style="margin:0;">📊 FY Utilization</h4></div>
        <div class="card-body" style="font-size:13px;">
          ${(() => {
            const claimed = parseFloat(pol.claimed_fy) || 0;
            const cover   = parseFloat(pol.effective_cover) || parseFloat(pol.sum_assured) || 1;
            const utilPct = Math.min(100, claimed/cover*100).toFixed(1);
            return `
            <div style="display:flex;justify-content:space-between;margin-bottom:4px;">
              <span>Claimed (${pol.fy_label || 'This FY'})</span>
              <strong style="color:${claimed>0?'#ef4444':'var(--text-muted);'};">${fmtI(claimed)}</strong>
            </div>
            <div style="height:10px;background:var(--border);border-radius:4px;overflow:hidden;margin-bottom:12px;">
              <div style="height:100%;width:${utilPct}%;background:#ef4444;border-radius:4px;"></div>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;text-align:center;">
              <div style="background:var(--bg-secondary);padding:10px;border-radius:8px;">
                <div style="color:var(--text-muted);font-size:11px;">Remaining Cover</div>
                <div style="font-weight:700;color:#16a34a;">${fmtI(Math.max(0,cover-claimed))}</div>
              </div>
              <div style="background:var(--bg-secondary);padding:10px;border-radius:8px;">
                <div style="color:var(--text-muted);font-size:11px;">Claims This FY</div>
                <div style="font-weight:700;">${pol.claims_fy || 0}</div>
              </div>
            </div>
            ${claimed === 0 ? '<div style="margin-top:12px;padding:8px;background:rgba(16,185,129,.08);border-radius:6px;font-size:12px;text-align:center;color:#059669;">🎉 No claims this FY — NCB may increase!</div>' : ''}
            `;
          })()}
        </div>
      </div>
    `;
  }

  // ── Render members list ──────────────────────────────────────────────────
  function renderMembers() {
    const el = $('htMemberList');
    if (!_members.length) {
      el.innerHTML = '<p style="color:var(--text-muted);font-size:13px;padding:12px 0;">No members added yet. Add family members to track individual coverage.</p>';
      return;
    }
    const relIcon = { self:'👤', spouse:'💑', son:'👦', daughter:'👧', father:'👨', mother:'👩', father_in_law:'👴', mother_in_law:'👵', other:'🧑' };
    el.innerHTML = `
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;padding:4px 0;">
        ${_members.map(m => `
          <div style="background:var(--bg-secondary);border-radius:10px;padding:14px;position:relative;">
            <div style="font-size:24px;margin-bottom:8px;">${relIcon[m.relation]||'🧑'}</div>
            <div style="font-weight:600;font-size:14px;margin-bottom:4px;">${m.member_name}</div>
            <div style="font-size:12px;color:var(--text-muted);text-transform:capitalize;">${m.relation.replace(/_/g,' ')}</div>
            ${m.dob ? `<div style="font-size:12px;color:var(--text-muted);">Age: ${m.computed_age || '—'} yrs</div>` : ''}
            ${m.pre_existing ? `<div style="font-size:11px;margin-top:6px;padding:4px 8px;background:rgba(239,68,68,.08);border-radius:4px;color:#ef4444;">${m.pre_existing}</div>` : ''}
            ${m.sum_insured ? `<div style="font-size:12px;margin-top:4px;">SI: ${fmtI(m.sum_insured)}</div>` : ''}
            <div style="position:absolute;top:10px;right:10px;display:flex;gap:4px;">
              <button class="btn btn-xs btn-ghost" onclick="HT.openMemberForm(${JSON.stringify(m).replace(/"/g,'&quot;')})">✎</button>
              <button class="btn btn-xs btn-ghost" style="color:var(--danger,#ef4444);" onclick="HT.delMember(${m.id})">✕</button>
            </div>
          </div>
        `).join('')}
      </div>`;
  }

  // ── Render claims table ──────────────────────────────────────────────────
  function renderClaims() {
    const tbody = $('htClaimBody');
    if (!_claims.length) {
      tbody.innerHTML = '<tr><td colspan="9" style="padding:32px;text-align:center;color:var(--text-muted);">No claims recorded yet.</td></tr>';
      $('htClaimSummaryBar').style.display = 'none';
      return;
    }
    tbody.innerHTML = _claims.map(c => `
      <tr>
        <td style="white-space:nowrap;">${fmtD(c.claim_date)}</td>
        <td>${c.member_name || '—'}</td>
        <td style="font-size:12px;">${c.hospital_name || '—'}</td>
        <td style="font-size:12px;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${c.diagnosis||''}">${c.diagnosis || '—'}</td>
        <td class="text-right">${fmtI(c.claimed_amount)}</td>
        <td class="text-right">${c.settled_amount ? fmtI(c.settled_amount) : '—'}</td>
        <td><span style="font-size:11px;padding:2px 6px;background:var(--bg-secondary);border-radius:4px;">${c.claim_type==='cashless'?'💳 Cashless':'💰 Reimb.'}</span></td>
        <td><span style="font-size:12px;font-weight:600;${claimStatusClass(c.status)}">${claimStatusLabel(c.status)}</span></td>
        <td class="text-center" style="white-space:nowrap;">
          <button class="btn btn-xs btn-ghost" onclick="HT.openClaimForm(${JSON.stringify(c).replace(/"/g,'&quot;')})">✎</button>
          <button class="btn btn-xs btn-ghost" style="color:var(--danger,#ef4444);" onclick="HT.delClaim(${c.id})">✕</button>
        </td>
      </tr>`).join('');

    const totClaimed  = _claims.reduce((s,c) => s + parseFloat(c.claimed_amount||0), 0);
    const totSettled  = _claims.reduce((s,c) => s + parseFloat(c.settled_amount||0), 0);
    const pending     = _claims.filter(c => ['submitted','under_review','approved'].includes(c.status));
    const bar = $('htClaimSummaryBar');
    bar.style.display = 'flex';
    bar.innerHTML = `
      <span>Total Claims: <strong>${_claims.length}</strong></span>
      <span>Total Claimed: <strong style="color:#ef4444;">${fmtI(totClaimed)}</strong></span>
      <span>Total Settled: <strong style="color:#16a34a;">${fmtI(totSettled)}</strong></span>
      ${pending.length ? `<span style="color:#f59e0b;">Pending: <strong>${pending.length}</strong></span>` : ''}
    `;
  }

  // ── Sub-tab switcher ─────────────────────────────────────────────────────
  function subTab(tab, btn) {
    _subTab = tab;
    $('htSubDetails').style.display  = tab === 'details'  ? '' : 'none';
    $('htSubMembers').style.display  = tab === 'members'  ? '' : 'none';
    $('htSubClaims').style.display   = tab === 'claims'   ? '' : 'none';
    document.querySelectorAll('.ht-sub-btn').forEach(b => {
      b.style.background = 'none';
      b.style.color = '';
    });
    if (btn) { btn.style.background = 'var(--primary)'; btn.style.color = '#fff'; }
  }

  // ── Modals ───────────────────────────────────────────────────────────────
  function openDetailsModal(id) {
    if (!_summary) return;
    const pol = (_summary.policies || []).find(p => p.id == id);
    if (!pol) return;
    $('htDmId').value         = pol.id;
    $('htDmType').value       = pol.health_type || 'individual';
    $('htDmRoomRent').value   = pol.room_rent_limit || '';
    $('htDmCopay').value      = pol.copay_pct || 0;
    $('htDmDeductible').value = pol.deductible || 0;
    $('htDmWpInit').value     = pol.waiting_period_initial || 30;
    $('htDmWpPd').value       = pol.waiting_period_pd || 1095;
    $('htDmTpa').value        = pol.tpa_name || '';
    $('htDmTpaContact').value = pol.tpa_contact || '';
    $('htDmNcb').value        = pol.no_claim_bonus || 0;
    $('htDmRestore').checked  = pol.restore_benefit == 1;
    $('htDmDaycare').checked  = pol.daycare_covered != 0;
    $('htDmMaternity').checked= pol.maternity_covered == 1;
    $('htDmPortability').checked = pol.portability_done == 1;
    $('htDmPortFrom').value   = pol.portability_from || '';
    $('htDmNetwork').value    = pol.network_hospitals || '';
    $('htDetailsModal').style.display = 'flex';
  }

  async function saveDetails() {
    const id = $('htDmId').value;
    const payload = {
      action: 'health_update_details', id,
      health_type: $('htDmType').value,
      room_rent_limit: $('htDmRoomRent').value || '',
      copay_pct: $('htDmCopay').value,
      deductible: $('htDmDeductible').value,
      waiting_period_initial: $('htDmWpInit').value,
      waiting_period_pd: $('htDmWpPd').value,
      tpa_name: $('htDmTpa').value,
      tpa_contact: $('htDmTpaContact').value,
      no_claim_bonus: $('htDmNcb').value,
      restore_benefit: $('htDmRestore').checked ? '1' : '0',
      daycare_covered: $('htDmDaycare').checked ? '1' : '0',
      maternity_covered: $('htDmMaternity').checked ? '1' : '0',
      maternity_waiting: '730',
      portability_done: $('htDmPortability').checked ? '1' : '0',
      portability_from: $('htDmPortFrom').value,
      network_hospitals: $('htDmNetwork').value,
    };
    const res = await apiPost(payload);
    if (res.success) {
      closeModal('htDetailsModal');
      await loadSummary();
      loadPolicy();
    } else { alert(res.message || 'Error saving.'); }
  }

  function openNcb(id, current) {
    const ncb = prompt(`Current NCB: ${current}%\nEnter new No-Claim Bonus %:`, current);
    if (ncb === null) return;
    apiPost({ action: 'health_ncb_update', id, ncb }).then(r => {
      if (r.success) { loadSummary().then(() => loadPolicy()); }
    });
  }

  function openMemberForm(m) {
    $('htMemberModalTitle').textContent = m?.id ? 'Edit Member' : 'Add Member';
    $('htMmId').value         = m?.id || '';
    $('htMmName').value       = m?.member_name || '';
    $('htMmRelation').value   = m?.relation || 'self';
    $('htMmGender').value     = m?.gender || '';
    $('htMmDob').value        = m?.dob ? m.dob.split(' ')[0] : '';
    $('htMmSI').value         = m?.sum_insured || '';
    $('htMmPreExist').value   = m?.pre_existing || '';
    $('htMemberModal').style.display = 'flex';
  }

  async function saveMember() {
    const id = $('htMmId').value;
    const payload = {
      action: id ? 'health_member_edit' : 'health_member_add',
      id, policy_id: _policyId,
      member_name: $('htMmName').value.trim(),
      relation: $('htMmRelation').value,
      gender: $('htMmGender').value,
      dob: $('htMmDob').value,
      sum_insured: $('htMmSI').value,
      pre_existing: $('htMmPreExist').value,
    };
    if (!payload.member_name) { alert('Member name required.'); return; }
    const res = await apiPost(payload);
    if (res.success) {
      closeModal('htMemberModal');
      const mRes = await fetch(`${APP_URL}/api/router.php?action=health_members_list&policy_id=${_policyId}`).then(r=>r.json());
      _members = mRes.success ? mRes.data : [];
      renderMembers();
      // Refresh claim member dropdown
      refreshMemberSelect();
    } else { alert(res.message || 'Error.'); }
  }

  async function delMember(id) {
    if (!confirm('Remove this member?')) return;
    const res = await apiPost({ action: 'health_member_delete', id });
    if (res.success) {
      _members = _members.filter(m => m.id !== id);
      renderMembers();
    }
  }

  function openClaimForm(c) {
    $('htClaimModalTitle').textContent = c?.id ? 'Edit Claim' : 'Add Claim';
    $('htCmId').value         = c?.id || '';
    $('htCmDate').value       = c?.claim_date ? c.claim_date.split(' ')[0] : new Date().toISOString().split('T')[0];
    $('htCmNumber').value     = c?.claim_number || '';
    $('htCmType').value       = c?.claim_type || 'reimbursement';
    $('htCmHospital').value   = c?.hospital_name || '';
    $('htCmDiagnosis').value  = c?.diagnosis || '';
    $('htCmAdmission').value  = c?.admission_date ? c.admission_date.split(' ')[0] : '';
    $('htCmDischarge').value  = c?.discharge_date ? c.discharge_date.split(' ')[0] : '';
    $('htCmClaimed').value    = c?.claimed_amount || '';
    $('htCmApproved').value   = c?.approved_amount || '';
    $('htCmSettled').value    = c?.settled_amount || '';
    $('htCmDeducted').value   = c?.deducted_amount || '';
    $('htCmSettleDate').value = c?.settlement_date ? c.settlement_date.split(' ')[0] : '';
    $('htCmStatus').value     = c?.status || 'submitted';
    $('htCmRejectRsn').value  = c?.rejection_reason || '';
    $('htCmNotes').value      = c?.notes || '';
    refreshMemberSelect(c?.member_id);
    $('htClaimModal').style.display = 'flex';
  }

  function refreshMemberSelect(selectedId) {
    const sel = $('htCmMember');
    sel.innerHTML = '<option value="">— Select member —</option>';
    _members.forEach(m => {
      const opt = document.createElement('option');
      opt.value = m.id;
      opt.textContent = `${m.member_name} (${m.relation.replace(/_/g,' ')})`;
      if (m.id == selectedId) opt.selected = true;
      sel.appendChild(opt);
    });
  }

  async function saveClaim() {
    const id = $('htCmId').value;
    const claimed = parseFloat($('htCmClaimed').value);
    if (!claimed || claimed <= 0) { alert('Claimed amount is required.'); return; }
    const payload = {
      action: id ? 'health_claim_edit' : 'health_claim_add',
      id, policy_id: _policyId,
      member_id: $('htCmMember').value,
      claim_number: $('htCmNumber').value,
      claim_type: $('htCmType').value,
      claim_date: $('htCmDate').value,
      hospital_name: $('htCmHospital').value,
      diagnosis: $('htCmDiagnosis').value,
      admission_date: $('htCmAdmission').value,
      discharge_date: $('htCmDischarge').value,
      claimed_amount: claimed,
      approved_amount: $('htCmApproved').value,
      settled_amount: $('htCmSettled').value,
      deducted_amount: $('htCmDeducted').value,
      settlement_date: $('htCmSettleDate').value,
      status: $('htCmStatus').value,
      rejection_reason: $('htCmRejectRsn').value,
      notes: $('htCmNotes').value,
    };
    const res = await apiPost(payload);
    if (res.success) {
      closeModal('htClaimModal');
      const cRes = await fetch(`${APP_URL}/api/router.php?action=health_claims_list&policy_id=${_policyId}`).then(r=>r.json());
      _claims = cRes.success ? cRes.data : [];
      renderClaims();
      loadSummary(); // refresh claimed_fy
    } else { alert(res.message || 'Error.'); }
  }

  async function delClaim(id) {
    if (!confirm('Delete this claim record?')) return;
    const res = await apiPost({ action: 'health_claim_delete', id });
    if (res.success) {
      _claims = _claims.filter(c => c.id !== id);
      renderClaims();
      loadSummary();
    }
  }

  function closeModal(id) {
    $(id).style.display = 'none';
  }

  // ── Hook into Ins.switchTab ──────────────────────────────────────────────
  // Called when 'health_tracker' tab is selected
  function activate() {
    $('healthTrackerPanel').style.display = '';
    if (!_summary) loadSummary();
  }

  function deactivate() {
    $('healthTrackerPanel').style.display = 'none';
  }

  return {
    loadSummary, loadPolicy, subTab,
    openDetailsModal, saveDetails, openNcb,
    openMemberForm, saveMember, delMember,
    openClaimForm, saveClaim, delClaim,
    closeModal, activate, deactivate,
  };
})();

// ── Patch Ins.switchTab to handle health_tracker tab ──────────────────────
(function() {
  const _orig = Ins.switchTab;
  Ins.switchTab = function(tab, btn) {
    const htPanel = document.getElementById('healthTrackerPanel');
    const mainPanels = ['insTablePanel', 'insCalendarPanel'];

    if (tab === 'health_tracker') {
      // Hide main panels
      mainPanels.forEach(id => { const el = document.getElementById(id); if (el) el.style.display = 'none'; });
      document.querySelectorAll('.tab-btn').forEach(b => { b.style.borderBottom = '2px solid transparent'; b.style.color = 'var(--text-muted)'; });
      if (btn) { btn.style.borderBottom = '2px solid var(--primary)'; btn.style.color = 'var(--primary)'; }
      HT.activate();
    } else {
      HT.deactivate();
      _orig.call(Ins, tab, btn);
    }
  };
})();
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
