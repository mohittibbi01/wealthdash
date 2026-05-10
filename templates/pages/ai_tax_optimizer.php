<?php
/**
 * WealthDash — t383: AI Tax Optimizer Page
 */
define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

$currentUser   = require_auth();
$pageTitle     = 'AI Tax Optimizer';
$activePage    = 'ai_tax_optimizer';
$activeSection = 'reports';

ob_start();
?>
<div class="page-header">
    <div>
        <h1 class="page-title">🤖 AI Tax Optimizer</h1>
        <p class="page-subtitle">Personalized tax-saving suggestions based on your portfolio data</p>
    </div>
    <div class="page-actions" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
        <input type="number" id="incomeInput" placeholder="Annual income (₹)" class="form-control"
            style="width:200px;font-size:13px;" min="0" step="10000">
        <button class="btn btn-primary" id="analyzeBtn" onclick="runAnalysis()">⚡ Analyze</button>
        <button class="btn btn-secondary" id="refreshBtn" onclick="refreshSuggestions()" style="display:none">🔄 Refresh</button>
    </div>
</div>

<!-- Summary bar -->
<div id="summaryBar" style="display:none;margin-bottom:20px;">
    <div class="cards-grid cards-grid-4">
        <div class="card" style="padding:16px 20px;">
            <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Potential Tax Saving</div>
            <div id="sumSaving" style="font-size:22px;font-weight:800;color:#16a34a;">—</div>
        </div>
        <div class="card" style="padding:16px 20px;">
            <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Tax Slab</div>
            <div id="sumSlab" style="font-size:22px;font-weight:800;">—</div>
        </div>
        <div class="card" style="padding:16px 20px;">
            <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Days Left in FY</div>
            <div id="sumDays" style="font-size:22px;font-weight:800;color:#f59e0b;">—</div>
        </div>
        <div class="card" style="padding:16px 20px;">
            <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:6px;">Suggestions</div>
            <div id="sumCount" style="font-size:22px;font-weight:800;">—</div>
        </div>
    </div>
</div>

<!-- Tabs -->
<div style="display:flex;gap:4px;margin-bottom:20px;border-bottom:2px solid var(--border-color);padding-bottom:0;">
    <?php foreach([['suggestions','💡 Suggestions'],['regime','⚖️ Regime Compare'],['checklist','✅ Checklist'],['deadlines','📅 Deadlines']] as [$id,$lbl]): ?>
    <button class="tab-btn" data-tab="<?= $id ?>" onclick="switchTab('<?= $id ?>')"
        style="padding:8px 16px;border:none;background:none;cursor:pointer;font-size:13px;font-weight:600;
        color:var(--text-muted);border-bottom:2px solid transparent;margin-bottom:-2px;">
        <?= $lbl ?>
    </button>
    <?php endforeach; ?>
</div>

<!-- Tab: Suggestions -->
<div id="tab-suggestions" class="tab-panel">
    <div id="suggestionsWrap">
        <div style="text-align:center;padding:60px 20px;color:var(--text-muted);">
            <div style="font-size:48px;margin-bottom:16px;">🤖</div>
            <div style="font-size:16px;font-weight:600;margin-bottom:8px;">Enter your annual income to get started</div>
            <div style="font-size:13px;">AI will analyze your portfolio and suggest personalized tax-saving actions</div>
        </div>
    </div>
</div>

<!-- Tab: Regime Compare -->
<div id="tab-regime" class="tab-panel" style="display:none;">
    <div class="card mb-4">
        <div class="card-header"><h3 class="card-title">⚖️ Old vs New Tax Regime</h3></div>
        <div class="card-body" id="regimeContent">
            <p style="color:var(--text-muted);text-align:center;padding:40px;">Run analysis first to see regime comparison.</p>
        </div>
    </div>
</div>

<!-- Tab: Checklist -->
<div id="tab-checklist" class="tab-panel" style="display:none;">
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">✅ FY-End Tax Checklist</h3>
            <span id="checklistProgress" style="font-size:12px;color:var(--text-muted);font-weight:600;"></span>
        </div>
        <div class="card-body" id="checklistContent">
            <p style="color:var(--text-muted);text-align:center;padding:40px;">Loading checklist...</p>
        </div>
    </div>
</div>

<!-- Tab: Deadlines -->
<div id="tab-deadlines" class="tab-panel" style="display:none;">
    <div class="card">
        <div class="card-header"><h3 class="card-title">📅 Upcoming Tax Deadlines</h3></div>
        <div class="card-body" id="deadlinesContent">
            <p style="color:var(--text-muted);text-align:center;padding:40px;">Loading deadlines...</p>
        </div>
    </div>
</div>

<style>
.tab-btn.active { color:var(--primary-color)!important; border-bottom-color:var(--primary-color)!important; }
.suggestion-card {
    background:var(--bg-card);border:1px solid var(--border-color);border-radius:12px;
    padding:20px;margin-bottom:12px;transition:box-shadow .2s;
}
.suggestion-card:hover { box-shadow:0 4px 16px rgba(0,0,0,.08); }
.urgency-critical { border-left:4px solid #dc2626; }
.urgency-high     { border-left:4px solid #f59e0b; }
.urgency-medium   { border-left:4px solid #3b82f6; }
.urgency-low      { border-left:4px solid var(--border-color); }
.urgency-badge {
    display:inline-block;padding:2px 10px;border-radius:99px;font-size:10px;font-weight:800;
    text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;
}
.ub-critical { background:rgba(220,38,38,.1); color:#dc2626; }
.ub-high     { background:rgba(245,158,11,.1); color:#d97706; }
.ub-medium   { background:rgba(59,130,246,.1); color:#3b82f6; }
.ub-low      { background:var(--bg-secondary);  color:var(--text-muted); }
.saving-chip {
    display:inline-flex;align-items:center;gap:4px;
    background:rgba(22,163,74,.1);color:#16a34a;
    border-radius:99px;padding:3px 12px;font-size:12px;font-weight:700;
}
.checklist-item {
    display:flex;align-items:flex-start;gap:12px;padding:12px 0;
    border-bottom:1px solid var(--border-color);
}
.checklist-item:last-child { border-bottom:none; }
.deadline-row {
    display:flex;align-items:center;gap:12px;padding:12px 16px;
    border-radius:8px;margin-bottom:6px;background:var(--bg-secondary);
}
.regime-col {
    flex:1;border-radius:12px;padding:20px;
}
.regime-col.winner { background:rgba(22,163,74,.08);border:2px solid #16a34a; }
.regime-col.loser  { background:var(--bg-secondary);border:2px solid var(--border-color); }
</style>

<script>
let _analysisData = null;
let _checklistState = {};

function switchTab(id) {
    document.querySelectorAll('.tab-btn').forEach(b => b.classList.toggle('active', b.dataset.tab === id));
    document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
    document.getElementById('tab-' + id).style.display = 'block';
}

function fmtMoney(n) {
    if (!n) return '₹0';
    if (n >= 10000000) return '₹' + (n/10000000).toFixed(2) + 'Cr';
    if (n >= 100000)   return '₹' + (n/100000).toFixed(2) + 'L';
    return '₹' + n.toLocaleString('en-IN');
}

function runAnalysis() {
    const income = parseFloat(document.getElementById('incomeInput').value || 0);
    if (!income || income < 1) {
        alert('Pehle annual income enter karo (e.g. 800000 for 8 lakh)');
        return;
    }
    document.getElementById('analyzeBtn').disabled = true;
    document.getElementById('analyzeBtn').textContent = '⏳ Analyzing...';

    fetch('<?= APP_URL ?>/api/?action=ai_tax_full_analysis&income=' + income)
        .then(r => r.json()).then(data => {
            document.getElementById('analyzeBtn').disabled = false;
            document.getElementById('analyzeBtn').textContent = '⚡ Analyze';
            document.getElementById('refreshBtn').style.display = 'inline-block';
            if (!data.success) { alert(data.message || 'Error'); return; }
            _analysisData = data;
            renderSummaryBar(data);
            renderSuggestions(data.suggestions);
            renderRegime(data);
            renderChecklist(data.checklist);
            renderDeadlines(data.deadlines);
            switchTab('suggestions');
        }).catch(() => {
            document.getElementById('analyzeBtn').disabled = false;
            document.getElementById('analyzeBtn').textContent = '⚡ Analyze';
            alert('Network error. Try again.');
        });
}

function refreshSuggestions() {
    const income = parseFloat(document.getElementById('incomeInput').value || _analysisData?.income || 0);
    if (income) { document.getElementById('incomeInput').value = income; runAnalysis(); }
}

function renderSummaryBar(data) {
    document.getElementById('summaryBar').style.display = 'block';
    document.getElementById('sumSaving').textContent = fmtMoney(data.total_potential_saving);
    document.getElementById('sumSlab').textContent    = data.slab_pct + '%';
    document.getElementById('sumDays').textContent    = data.days_left + ' days';
    document.getElementById('sumCount').textContent   = data.suggestions.length + ' found';
}

function renderSuggestions(suggs) {
    const wrap = document.getElementById('suggestionsWrap');
    if (!suggs || !suggs.length) {
        wrap.innerHTML = '<div style="text-align:center;padding:60px;color:var(--text-muted);">🎉 Koi major optimization nahi mila — aap pehle se tax-efficient ho!</div>';
        return;
    }
    const urgencyLabels = {critical:'🚨 Critical', high:'⚠️ High Priority', medium:'📋 Action Needed', low:'💡 Consider'};
    wrap.innerHTML = suggs.map(s => `
    <div class="suggestion-card urgency-${s.urgency}">
        <div style="display:flex;align-items:flex-start;gap:12px;">
            <div style="font-size:28px;line-height:1;">${s.icon}</div>
            <div style="flex:1;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px;">
                    <span class="urgency-badge ub-${s.urgency}">${urgencyLabels[s.urgency]||s.urgency}</span>
                    <span style="font-size:11px;color:var(--text-muted);font-weight:600;">${s.category}</span>
                </div>
                <div style="font-size:15px;font-weight:700;margin-bottom:4px;">${s.title}</div>
                <div style="font-size:13px;color:#3b82f6;font-weight:600;margin-bottom:8px;">→ ${s.action}</div>
                <div style="font-size:12px;color:var(--text-muted);line-height:1.6;margin-bottom:10px;">${s.detail}</div>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    ${s.saving > 0 ? `<span class="saving-chip">💰 ~${fmtMoney(s.saving)} bachega</span>` : ''}
                    <span style="font-size:11px;color:var(--text-muted);">${s.days_left} days left in FY</span>
                </div>
                ${s.options ? `<div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
                    ${s.options.map(o=>`<div style="background:var(--bg-secondary);border-radius:6px;padding:5px 10px;font-size:11px;">
                        <strong>${o.name}</strong>: ${o.why||o.benefit||''}
                    </div>`).join('')}
                </div>` : ''}
            </div>
        </div>
    </div>`).join('');
}

function renderRegime(data) {
    const rc = data.regime_compare;
    if (!rc) return;
    const html = `
    <div style="display:flex;gap:16px;flex-wrap:wrap;">
        <div class="regime-col ${rc.old_tax < rc.new_tax ? 'winner' : 'loser'}">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;">
                ${rc.old_tax < rc.new_tax ? '🏆 ' : ''}Old Tax Regime
            </div>
            <div style="font-size:28px;font-weight:800;margin-bottom:8px;">₹${rc.old_tax.toLocaleString('en-IN')}</div>
            <div style="font-size:12px;color:var(--text-muted);">With deductions (80C/NPS/HRA etc.)</div>
            ${rc.old_tax < rc.new_tax ? `<div style="margin-top:12px;font-size:13px;color:#16a34a;font-weight:700;">You save ₹${rc.saving.toLocaleString('en-IN')} more here</div>` : ''}
        </div>
        <div class="regime-col ${rc.new_tax < rc.old_tax ? 'winner' : 'loser'}">
            <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;">
                ${rc.new_tax < rc.old_tax ? '🏆 ' : ''}New Tax Regime
            </div>
            <div style="font-size:28px;font-weight:800;margin-bottom:8px;">₹${rc.new_tax.toLocaleString('en-IN')}</div>
            <div style="font-size:12px;color:var(--text-muted);">Flat slabs, ₹75K std deduction only</div>
            ${rc.new_tax < rc.old_tax ? `<div style="margin-top:12px;font-size:13px;color:#16a34a;font-weight:700;">You save ₹${rc.saving.toLocaleString('en-IN')} more here</div>` : ''}
        </div>
    </div>
    <div style="margin-top:16px;padding:14px 16px;background:rgba(59,130,246,.06);border-radius:8px;font-size:13px;">
        <strong>Verdict:</strong> ${rc.better} is better for you — saving ₹${rc.saving.toLocaleString('en-IN')}.
        ${rc.better === 'Old Regime' ? 'Maximize 80C/NPS/80D deductions to keep old regime advantage.' : 'Switch to new regime for lower tax with standard flat slabs.'}
    </div>`;
    document.getElementById('regimeContent').innerHTML = html;
}

function renderChecklist(items) {
    if (!items) return;
    const wrap = document.getElementById('checklistContent');
    let checked = 0;
    wrap.innerHTML = items.map((item, i) => `
    <div class="checklist-item" id="ci_${i}">
        <input type="checkbox" style="margin-top:2px;width:16px;height:16px;cursor:pointer;"
            onchange="toggleCheck(${i}, this)">
        <div style="flex:1;">
            <div style="font-size:13px;font-weight:600;" id="ci_label_${i}">${item.task}</div>
            <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">
                <span style="background:var(--bg-secondary);padding:1px 8px;border-radius:4px;">${item.section}</span>
                &nbsp;📅 Deadline: <strong>${item.deadline}</strong>
            </div>
        </div>
    </div>`).join('');
    updateChecklistProgress(0, items.length);
}

function toggleCheck(i, el) {
    const label = document.getElementById('ci_label_' + i);
    label.style.textDecoration = el.checked ? 'line-through' : '';
    label.style.color = el.checked ? 'var(--text-muted)' : '';
    _checklistState[i] = el.checked;
    const total = document.querySelectorAll('#checklistContent .checklist-item').length;
    const done  = Object.values(_checklistState).filter(Boolean).length;
    updateChecklistProgress(done, total);
}

function updateChecklistProgress(done, total) {
    document.getElementById('checklistProgress').textContent = done + '/' + total + ' done';
}

function renderDeadlines(deadlines) {
    if (!deadlines) return;
    const wrap = document.getElementById('deadlinesContent');
    wrap.innerHTML = deadlines.map(d => `
    <div class="deadline-row" style="${d.urgent ? 'background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.2);' : ''}">
        <div style="font-size:20px;">${d.urgent ? '⏰' : '📅'}</div>
        <div style="flex:1;">
            <div style="font-size:13px;font-weight:700;">${d.label}</div>
            <div style="font-size:11px;color:var(--text-muted);">${d.desc}</div>
        </div>
        <div style="text-align:right;">
            <div style="font-size:13px;font-weight:700;${d.urgent?'color:#f59e0b':''}">${d.date}</div>
            <div style="font-size:11px;color:var(--text-muted);">${d.days_away > 0 ? d.days_away + ' days away' : 'Past'}</div>
        </div>
    </div>`).join('');
}

// Init — load checklist and deadlines immediately
(function init() {
    switchTab('suggestions');
    // Load checklist
    fetch('<?= APP_URL ?>/api/?action=ai_tax_checklist')
        .then(r => r.json()).then(d => { if(d.success) renderChecklist(d.checklist); }).catch(()=>{});
    // Load deadlines
    fetch('<?= APP_URL ?>/api/?action=ai_tax_deadline_alerts')
        .then(r => r.json()).then(d => { if(d.success) renderDeadlines(d.deadlines); }).catch(()=>{});
    // Pre-fill income if stored in session via PHP
    <?php $si = isset($_SESSION['wd_tax_income']) ? (float)$_SESSION['wd_tax_income'] : 0; ?>
    <?php if ($si > 0): ?>
    document.getElementById('incomeInput').value = <?= $si ?>;
    <?php endif; ?>
})();
</script>
<?php
$pageContent = ob_get_clean();
require_once APP_ROOT . '/templates/layout.php';
?>
