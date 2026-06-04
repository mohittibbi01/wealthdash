<?php
/**
 * WealthDash — Bank Accounts Tracker Page [t43]
 * File: templates/pages/banks.php
 * Worker: ID-M
 */
if (!defined('WEALTHDASH')) die('Direct access not allowed.');
$user = require_auth();
$csrf = csrf_token();
?>

<div class="page-header">
    <div class="ph-left">
        <h1 class="page-title">🏦 Bank Accounts</h1>
        <p class="page-sub">Track savings, current, salary &amp; RD accounts with balances and transactions.</p>
    </div>
    <div class="ph-right">
        <button class="btn btn-outline btn-sm" id="btnRefreshBanks">↺ Refresh</button>
        <button class="btn btn-primary" id="btnAddBank">+ Add Account</button>
    </div>
</div>

<!-- Summary Cards -->
<div class="summary-cards" id="bankSummaryCards">
    <div class="scard scard-load">
        <div class="scard-skeleton"></div>
    </div>
</div>

<!-- Tabs -->
<div class="card mt-12">
    <div class="card-tabs">
        <button class="ctab active" data-tab="ba-accounts">Accounts</button>
        <button class="ctab" data-tab="ba-transactions">Transactions</button>
        <button class="ctab" data-tab="ba-chart">Balance Chart</button>
    </div>

    <!-- Accounts Tab -->
    <div id="ba-accounts" class="ctab-pane active">
        <div class="tbl-toolbar">
            <div class="tbl-filters">
                <select id="baStatusFilter" class="form-select-sm">
                    <option value="active">Active</option>
                    <option value="closed">Closed</option>
                    <option value="dormant">Dormant</option>
                    <option value="">All</option>
                </select>
            </div>
        </div>

        <div class="tbl-wrap">
            <table class="tbl" id="bankTable">
                <thead>
                    <tr>
                        <th>Bank / Nickname</th>
                        <th>Type</th>
                        <th>Account No.</th>
                        <th>IFSC</th>
                        <th>Interest %</th>
                        <th class="text-right">Balance</th>
                        <th>As of</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="bankTableBody">
                    <tr><td colspan="9" class="tbl-loading">Loading...</td></tr>
                </tbody>
                <tfoot>
                    <tr id="bankTableFoot" style="display:none">
                        <td colspan="5"><strong>Total (Active)</strong></td>
                        <td class="text-right"><strong id="bankTotalBalance">—</strong></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- Transactions Tab -->
    <div id="ba-transactions" class="ctab-pane">
        <div class="tbl-toolbar">
            <select id="baTxnAccountFilter" class="form-select-sm">
                <option value="">All Accounts</option>
            </select>
            <select id="baTxnTypeFilter" class="form-select-sm">
                <option value="">All Types</option>
                <option value="credit">Credit</option>
                <option value="debit">Debit</option>
            </select>
            <button class="btn btn-primary btn-sm" id="btnAddTxn">+ Add Transaction</button>
        </div>
        <div class="tbl-wrap">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Account</th>
                        <th>Type</th>
                        <th>Category</th>
                        <th>Description</th>
                        <th>Ref</th>
                        <th class="text-right">Amount</th>
                        <th class="text-right">Balance After</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody id="bankTxnBody">
                    <tr><td colspan="9" class="tbl-empty">Select an account or load all transactions.</td></tr>
                </tbody>
            </table>
        </div>
        <div class="tbl-pagination" id="bankTxnPager"></div>
    </div>

    <!-- Chart Tab -->
    <div id="ba-chart" class="ctab-pane">
        <div class="chart-controls">
            <select id="baChartAccount" class="form-select-sm">
                <option value="">All Accounts (Combined)</option>
            </select>
        </div>
        <div class="chart-wrap" style="height:320px;position:relative;">
            <canvas id="bankBalanceChart"></canvas>
            <div id="bankChartEmpty" class="chart-empty" style="display:none">
                No balance history yet. Add transactions to see the chart.
            </div>
        </div>
    </div>
</div>

<!-- ── ADD / EDIT ACCOUNT MODAL ─────────────────────────────────────── -->
<div id="modalBank" class="modal" style="display:none">
    <div class="modal-overlay" onclick="WDBank.closeModal()"></div>
    <div class="modal-box modal-md">
        <div class="modal-hdr">
            <h3 id="modalBankTitle">Add Bank Account</h3>
            <button class="modal-close" onclick="WDBank.closeModal()">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="bankId" value="">

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Bank Name <span class="req">*</span></label>
                    <input type="text" id="bankName" class="form-control" placeholder="e.g. HDFC Bank" list="bankNameList">
                    <datalist id="bankNameList"></datalist>
                </div>
                <div class="form-group">
                    <label>Nickname</label>
                    <input type="text" id="bankNickname" class="form-control" placeholder="e.g. Primary Salary A/c">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Account Type <span class="req">*</span></label>
                    <select id="bankAccountType" class="form-control">
                        <option value="savings">Savings</option>
                        <option value="current">Current</option>
                        <option value="salary">Salary</option>
                        <option value="nre">NRE</option>
                        <option value="nro">NRO</option>
                        <option value="fcnr">FCNR</option>
                        <option value="rd">Recurring Deposit (RD)</option>
                        <option value="cc">Cash Credit (CC)</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Account Number (last 4)</label>
                    <input type="text" id="bankAccountNumber" class="form-control" maxlength="30" placeholder="XXXX 1234">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Branch</label>
                    <input type="text" id="bankBranch" class="form-control" placeholder="Branch name">
                </div>
                <div class="form-group">
                    <label>IFSC Code</label>
                    <input type="text" id="bankIfsc" class="form-control" maxlength="15" placeholder="e.g. HDFC0001234"
                           style="text-transform:uppercase">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Opening / Current Balance (₹)</label>
                    <input type="number" id="bankOpeningBalance" class="form-control" min="0" step="0.01" value="0">
                </div>
                <div class="form-group">
                    <label>Interest Rate (% p.a.)</label>
                    <input type="number" id="bankInterestRate" class="form-control" min="0" max="20" step="0.01" placeholder="e.g. 3.5">
                </div>
            </div>

            <!-- RD fields (shown only for RD type) -->
            <div id="rdFields" style="display:none">
                <div class="form-grid-3">
                    <div class="form-group">
                        <label>Monthly Installment (₹)</label>
                        <input type="number" id="bankRdAmount" class="form-control" min="0" step="1">
                    </div>
                    <div class="form-group">
                        <label>Tenure (months)</label>
                        <input type="number" id="bankRdTenure" class="form-control" min="1" max="120">
                    </div>
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" id="bankRdStart" class="form-control">
                    </div>
                </div>
                <div class="form-group" style="max-width:240px">
                    <label>Maturity Date</label>
                    <input type="date" id="bankRdMaturity" class="form-control">
                </div>
            </div>

            <div class="form-grid-2">
                <div class="form-group">
                    <label>Nominee</label>
                    <input type="text" id="bankNominee" class="form-control" placeholder="Nominee name">
                </div>
                <div class="form-group" style="margin-top:26px">
                    <label class="checkbox-label">
                        <input type="checkbox" id="bankIsJoint"> Joint Account
                    </label>
                    <label class="checkbox-label ml-12">
                        <input type="checkbox" id="bankIsPrimary"> Primary Account
                    </label>
                </div>
            </div>

            <div id="jointHolder" style="display:none" class="form-group">
                <label>Joint Holder Name</label>
                <input type="text" id="bankJointHolder" class="form-control">
            </div>

            <div class="form-group">
                <label>Notes</label>
                <textarea id="bankNotes" class="form-control" rows="2" placeholder="Optional notes"></textarea>
            </div>

            <div id="bankEditStatus" class="form-group" style="display:none">
                <label>Status</label>
                <select id="bankStatus" class="form-control">
                    <option value="active">Active</option>
                    <option value="closed">Closed</option>
                    <option value="dormant">Dormant</option>
                </select>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="WDBank.closeModal()">Cancel</button>
            <button class="btn btn-primary" id="btnSaveBank" onclick="WDBank.saveAccount()">Save Account</button>
        </div>
    </div>
</div>

<!-- ── UPDATE BALANCE MODAL ──────────────────────────────────────────── -->
<div id="modalUpdateBalance" class="modal" style="display:none">
    <div class="modal-overlay" onclick="WDBank.closeBalModal()"></div>
    <div class="modal-box modal-sm">
        <div class="modal-hdr">
            <h3>Update Balance</h3>
            <button class="modal-close" onclick="WDBank.closeBalModal()">✕</button>
        </div>
        <div class="modal-body">
            <input type="hidden" id="balAccountId">
            <div class="form-group">
                <label>Account</label>
                <div id="balAccountName" class="form-static"></div>
            </div>
            <div class="form-group">
                <label>Current Balance (₹) <span class="req">*</span></label>
                <input type="number" id="balAmount" class="form-control" step="0.01" min="0">
            </div>
            <div class="form-group">
                <label>As of Date</label>
                <input type="date" id="balDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="WDBank.closeBalModal()">Cancel</button>
            <button class="btn btn-primary" onclick="WDBank.saveBalance()">Update</button>
        </div>
    </div>
</div>

<!-- ── ADD TRANSACTION MODAL ─────────────────────────────────────────── -->
<div id="modalTxn" class="modal" style="display:none">
    <div class="modal-overlay" onclick="WDBank.closeTxnModal()"></div>
    <div class="modal-box modal-md">
        <div class="modal-hdr">
            <h3>Add Transaction</h3>
            <button class="modal-close" onclick="WDBank.closeTxnModal()">✕</button>
        </div>
        <div class="modal-body">
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Account <span class="req">*</span></label>
                    <select id="txnAccountId" class="form-control"></select>
                </div>
                <div class="form-group">
                    <label>Type <span class="req">*</span></label>
                    <select id="txnType" class="form-control">
                        <option value="credit">Credit (Money In)</option>
                        <option value="debit">Debit (Money Out)</option>
                    </select>
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Amount (₹) <span class="req">*</span></label>
                    <input type="number" id="txnAmount" class="form-control" min="0.01" step="0.01">
                </div>
                <div class="form-group">
                    <label>Date <span class="req">*</span></label>
                    <input type="date" id="txnDate" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
            </div>
            <div class="form-grid-2">
                <div class="form-group">
                    <label>Category</label>
                    <select id="txnCategory" class="form-control">
                        <option value="other">Other</option>
                        <option value="salary">Salary</option>
                        <option value="rent">Rent</option>
                        <option value="emi">EMI</option>
                        <option value="utilities">Utilities</option>
                        <option value="transfer">Transfer</option>
                        <option value="fd_maturity">FD Maturity</option>
                        <option value="interest">Interest</option>
                        <option value="investment">Investment</option>
                        <option value="refund">Refund</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Value Date</label>
                    <input type="date" id="txnValueDate" class="form-control">
                </div>
            </div>
            <div class="form-group">
                <label>Description</label>
                <input type="text" id="txnDescription" class="form-control" placeholder="e.g. Amazon refund">
            </div>
            <div class="form-group">
                <label>Reference / UTR</label>
                <input type="text" id="txnRef" class="form-control" placeholder="e.g. UTR123456789">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-outline" onclick="WDBank.closeTxnModal()">Cancel</button>
            <button class="btn btn-primary" onclick="WDBank.saveTxn()">Add Transaction</button>
        </div>
    </div>
</div>

<script>
/* ═══════════════════════════════════════════════════════════
   WealthDash Bank Accounts Module — t43
   ═══════════════════════════════════════════════════════════ */
const WDBank = (() => {
    const API   = '/api/router.php';
    const CSRF  = '<?= $csrf ?>';
    let _accounts = [];
    let _txnOffset = 0;
    const TXN_LIMIT = 50;

    // ── API calls ─────────────────────────────────────────────────────────────
    async function apiFetch(action, params = {}, method = 'GET') {
        const isGet = method === 'GET';
        let url = API + '?action=' + action;
        const opts = { method, credentials: 'same-origin',
                       headers: { 'X-Requested-With': 'XMLHttpRequest' } };
        if (isGet) {
            if (params) url += '&' + new URLSearchParams(params).toString();
        } else {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({ ...params, _csrf_token: CSRF });
        }
        const res  = await fetch(url, opts);
        const data = await res.json();
        if (!data.success) throw new Error(data.message || 'API error');
        return data;
    }

    // ── Summary Cards ─────────────────────────────────────────────────────────
    async function loadSummary() {
        const d = await apiFetch('bank_summary');
        const r = d.data;
        const el = document.getElementById('bankSummaryCards');
        el.innerHTML = `
            <div class="scard">
                <div class="scard-val">${WD.inr(r.grand_total)}</div>
                <div class="scard-lbl">Total Balance</div>
            </div>
            <div class="scard">
                <div class="scard-val text-gain">${WD.inr(r.monthly_inflow)}</div>
                <div class="scard-lbl">This Month In</div>
            </div>
            <div class="scard">
                <div class="scard-val text-loss">${WD.inr(r.monthly_outflow)}</div>
                <div class="scard-lbl">This Month Out</div>
            </div>
            <div class="scard">
                <div class="scard-val ${r.net_cashflow >= 0 ? 'text-gain' : 'text-loss'}">${WD.inr(r.net_cashflow)}</div>
                <div class="scard-lbl">Net Cashflow</div>
            </div>
            <div class="scard">
                <div class="scard-val">${r.by_type ? r.by_type.length : 0}</div>
                <div class="scard-lbl">Account Types</div>
            </div>`;
    }

    // ── Accounts List ─────────────────────────────────────────────────────────
    async function loadAccounts() {
        const status = document.getElementById('baStatusFilter').value;
        const d = await apiFetch('bank_list', { status });
        _accounts = d.data.accounts || [];
        renderAccounts(_accounts, d.data.total_balance || 0);
        populateAccountDropdowns();
    }

    const TYPE_LABELS = {
        savings:'Savings', current:'Current', salary:'Salary',
        nre:'NRE', nro:'NRO', fcnr:'FCNR', rd:'RD', cc:'Cash Credit'
    };
    const TYPE_COLORS = {
        savings:'var(--accent2)', current:'var(--accent)', salary:'var(--warn)',
        nre:'#a78bfa', nro:'#f472b6', fcnr:'#fb923c', rd:'var(--done)', cc:'var(--danger)'
    };

    function renderAccounts(accounts, total) {
        const tb = document.getElementById('bankTableBody');
        if (!accounts.length) {
            tb.innerHTML = '<tr><td colspan="9" class="tbl-empty">No bank accounts yet. Click "+ Add Account" to get started.</td></tr>';
            document.getElementById('bankTableFoot').style.display = 'none';
            return;
        }
        tb.innerHTML = accounts.map(a => `
            <tr data-id="${a.id}">
                <td>
                    <div style="font-weight:600">${esc(a.bank_name)}</div>
                    ${a.nickname ? `<div class="tbl-sub">${esc(a.nickname)}</div>` : ''}
                    ${a.is_primary ? '<span class="pill pill-xs pill-accent">Primary</span>' : ''}
                    ${a.is_joint ? '<span class="pill pill-xs pill-warn">Joint</span>' : ''}
                </td>
                <td>
                    <span class="pill pill-xs" style="background:color-mix(in srgb,${TYPE_COLORS[a.account_type]||'var(--muted)'} 18%,transparent);color:${TYPE_COLORS[a.account_type]||'var(--muted)'}">
                        ${TYPE_LABELS[a.account_type] || a.account_type}
                    </span>
                </td>
                <td class="mono">${a.account_number ? '···· ' + esc(a.account_number) : '—'}</td>
                <td class="mono" style="font-size:11px">${a.ifsc_code || '—'}</td>
                <td class="text-right">${a.interest_rate ? a.interest_rate + '%' : '—'}</td>
                <td class="text-right" style="font-weight:600">${WD.inr(a.current_balance)}</td>
                <td style="color:var(--muted);font-size:11px">${a.balance_date || '—'}</td>
                <td>
                    <span class="pill pill-xs ${a.status==='active'?'pill-done':a.status==='closed'?'pill-danger':'pill-warn'}">
                        ${a.status}
                    </span>
                </td>
                <td>
                    <div class="tbl-actions">
                        <button class="tbl-btn" title="Update Balance" onclick="WDBank.openBalModal(${a.id})">₹</button>
                        <button class="tbl-btn" title="Edit" onclick="WDBank.openModal(${a.id})">✎</button>
                        <button class="tbl-btn tbl-btn-danger" title="Delete" onclick="WDBank.deleteAccount(${a.id})">✕</button>
                    </div>
                </td>
            </tr>`).join('');

        const foot = document.getElementById('bankTableFoot');
        foot.style.display = '';
        document.getElementById('bankTotalBalance').textContent = WD.inr(total);
    }

    // ── Populate dropdowns ────────────────────────────────────────────────────
    function populateAccountDropdowns() {
        const opts = _accounts.map(a =>
            `<option value="${a.id}">${esc(a.bank_name)}${a.nickname ? ' — ' + esc(a.nickname) : ''}</option>`
        ).join('');
        document.getElementById('baTxnAccountFilter').innerHTML = '<option value="">All Accounts</option>' + opts;
        document.getElementById('baChartAccount').innerHTML = '<option value="">All Accounts (Combined)</option>' + opts;
        document.getElementById('txnAccountId').innerHTML = opts;
    }

    // ── Open Add/Edit Modal ───────────────────────────────────────────────────
    async function openModal(id = 0) {
        resetForm();
        if (id) {
            document.getElementById('modalBankTitle').textContent = 'Edit Bank Account';
            document.getElementById('bankEditStatus').style.display = '';
            const d = await apiFetch('bank_get', { id });
            const a = d.data.account;
            document.getElementById('bankId').value             = a.id;
            document.getElementById('bankName').value           = a.bank_name;
            document.getElementById('bankNickname').value       = a.nickname || '';
            document.getElementById('bankAccountType').value    = a.account_type;
            document.getElementById('bankAccountNumber').value  = a.account_number || '';
            document.getElementById('bankBranch').value         = a.branch || '';
            document.getElementById('bankIfsc').value           = a.ifsc_code || '';
            document.getElementById('bankOpeningBalance').value = a.opening_balance || 0;
            document.getElementById('bankInterestRate').value   = a.interest_rate || '';
            document.getElementById('bankNominee').value        = a.nominee || '';
            document.getElementById('bankNotes').value          = a.notes || '';
            document.getElementById('bankStatus').value         = a.status;
            document.getElementById('bankIsJoint').checked      = !!a.is_joint;
            document.getElementById('bankIsPrimary').checked    = !!a.is_primary;
            document.getElementById('bankJointHolder').value    = a.joint_holder || '';
            document.getElementById('bankRdAmount').value       = a.rd_amount || '';
            document.getElementById('bankRdTenure').value       = a.rd_tenure_months || '';
            document.getElementById('bankRdStart').value        = a.rd_start_date || '';
            document.getElementById('bankRdMaturity').value     = a.rd_maturity_date || '';
            toggleRdFields();
            toggleJointField();
        }
        document.getElementById('modalBank').style.display = 'flex';
    }

    function closeModal() { document.getElementById('modalBank').style.display = 'none'; }

    function resetForm() {
        document.getElementById('bankId').value = '';
        document.getElementById('modalBankTitle').textContent = 'Add Bank Account';
        document.getElementById('bankEditStatus').style.display = 'none';
        ['bankName','bankNickname','bankAccountNumber','bankBranch','bankIfsc',
         'bankNominee','bankNotes','bankJointHolder','bankRdAmount','bankRdTenure',
         'bankRdStart','bankRdMaturity'].forEach(id => {
             const el = document.getElementById(id);
             if (el) el.value = '';
         });
        document.getElementById('bankOpeningBalance').value  = 0;
        document.getElementById('bankInterestRate').value   = '';
        document.getElementById('bankAccountType').value    = 'savings';
        document.getElementById('bankStatus').value         = 'active';
        document.getElementById('bankIsJoint').checked      = false;
        document.getElementById('bankIsPrimary').checked    = false;
        toggleRdFields(); toggleJointField();
        loadBanksList();
    }

    async function loadBanksList() {
        try {
            const d = await apiFetch('banks_list');
            const dl = document.getElementById('bankNameList');
            dl.innerHTML = (d.data.banks || []).map(b => `<option value="${esc(b.bank_name)}">`).join('');
        } catch(e) {}
    }

    // ── Save Account ──────────────────────────────────────────────────────────
    async function saveAccount() {
        const id = document.getElementById('bankId').value;
        const action = id ? 'bank_edit' : 'bank_add';
        const params = {
            id:                  id,
            bank_name:           document.getElementById('bankName').value.trim(),
            nickname:            document.getElementById('bankNickname').value.trim(),
            account_type:        document.getElementById('bankAccountType').value,
            account_number:      document.getElementById('bankAccountNumber').value.trim(),
            branch:              document.getElementById('bankBranch').value.trim(),
            ifsc_code:           document.getElementById('bankIfsc').value.trim(),
            opening_balance:     document.getElementById('bankOpeningBalance').value,
            interest_rate:       document.getElementById('bankInterestRate').value,
            nominee:             document.getElementById('bankNominee').value.trim(),
            notes:               document.getElementById('bankNotes').value.trim(),
            status:              document.getElementById('bankStatus').value,
            is_joint:            document.getElementById('bankIsJoint').checked ? 1 : 0,
            is_primary:          document.getElementById('bankIsPrimary').checked ? 1 : 0,
            joint_holder:        document.getElementById('bankJointHolder').value.trim(),
            rd_amount:           document.getElementById('bankRdAmount').value,
            rd_tenure_months:    document.getElementById('bankRdTenure').value,
            rd_start_date:       document.getElementById('bankRdStart').value,
            rd_maturity_date:    document.getElementById('bankRdMaturity').value,
        };
        if (!params.bank_name) { alert('Bank name is required.'); return; }
        const btn = document.getElementById('btnSaveBank');
        btn.disabled = true; btn.textContent = 'Saving...';
        try {
            await apiFetch(action, params, 'POST');
            closeModal();
            await Promise.all([loadAccounts(), loadSummary()]);
        } catch(e) {
            alert(e.message);
        } finally {
            btn.disabled = false; btn.textContent = 'Save Account';
        }
    }

    // ── Delete Account ────────────────────────────────────────────────────────
    async function deleteAccount(id) {
        if (!confirm('Delete this bank account and all its transactions? This cannot be undone.')) return;
        await apiFetch('bank_delete', { id }, 'POST');
        await Promise.all([loadAccounts(), loadSummary()]);
    }

    // ── Balance Modal ─────────────────────────────────────────────────────────
    function openBalModal(id) {
        const acc = _accounts.find(a => a.id == id);
        if (!acc) return;
        document.getElementById('balAccountId').value   = id;
        document.getElementById('balAccountName').textContent = acc.bank_name + (acc.nickname ? ' — ' + acc.nickname : '');
        document.getElementById('balAmount').value      = acc.current_balance;
        document.getElementById('balDate').value        = new Date().toISOString().slice(0,10);
        document.getElementById('modalUpdateBalance').style.display = 'flex';
    }
    function closeBalModal() { document.getElementById('modalUpdateBalance').style.display = 'none'; }

    async function saveBalance() {
        const id      = document.getElementById('balAccountId').value;
        const balance = document.getElementById('balAmount').value;
        const date    = document.getElementById('balDate').value;
        if (!balance) { alert('Enter a balance amount.'); return; }
        await apiFetch('bank_update_balance', { id, balance, date }, 'POST');
        closeBalModal();
        await Promise.all([loadAccounts(), loadSummary()]);
    }

    // ── Transactions ──────────────────────────────────────────────────────────
    async function loadTxns(reset = false) {
        if (reset) _txnOffset = 0;
        const accountId = document.getElementById('baTxnAccountFilter').value;
        const type      = document.getElementById('baTxnTypeFilter').value;
        const params = { limit: TXN_LIMIT, offset: _txnOffset };
        if (accountId) params.account_id = accountId;

        const d = await apiFetch('bank_txn_list', params);
        const txns = d.data.transactions || [];
        const total = d.data.total || 0;
        const tb = document.getElementById('bankTxnBody');

        if (!txns.length && !_txnOffset) {
            tb.innerHTML = '<tr><td colspan="9" class="tbl-empty">No transactions found.</td></tr>';
            return;
        }

        const accMap = {};
        _accounts.forEach(a => { accMap[a.id] = (a.bank_name + (a.nickname ? ' · ' + a.nickname : '')); });

        const filtered = type ? txns.filter(t => t.type === type) : txns;
        tb.innerHTML = filtered.map(t => `
            <tr>
                <td class="mono" style="font-size:11px">${t.txn_date}</td>
                <td>${esc(accMap[t.account_id] || '—')}</td>
                <td>
                    <span class="pill pill-xs ${t.type==='credit'?'pill-done':'pill-danger'}">
                        ${t.type === 'credit' ? '▲ Credit' : '▼ Debit'}
                    </span>
                </td>
                <td style="text-transform:capitalize;font-size:11px">${t.category || '—'}</td>
                <td style="max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${esc(t.description || '—')}</td>
                <td class="mono" style="font-size:11px">${esc(t.ref_number || '—')}</td>
                <td class="text-right ${t.type==='credit'?'text-gain':'text-loss'}" style="font-weight:600">
                    ${t.type==='credit' ? '+' : '−'}${WD.inr(t.amount)}
                </td>
                <td class="text-right mono" style="font-size:11px">${t.balance_after != null ? WD.inr(t.balance_after) : '—'}</td>
                <td>
                    <button class="tbl-btn tbl-btn-danger" onclick="WDBank.deleteTxn(${t.id})">✕</button>
                </td>
            </tr>`).join('');

        // Pagination
        const pager = document.getElementById('bankTxnPager');
        pager.innerHTML = '';
        if (_txnOffset > 0) {
            const prev = document.createElement('button');
            prev.className = 'btn btn-outline btn-sm'; prev.textContent = '← Previous';
            prev.onclick = () => { _txnOffset -= TXN_LIMIT; loadTxns(); };
            pager.appendChild(prev);
        }
        if ((_txnOffset + TXN_LIMIT) < total) {
            const next = document.createElement('button');
            next.className = 'btn btn-outline btn-sm'; next.textContent = 'Next →';
            next.style.marginLeft = '8px';
            next.onclick = () => { _txnOffset += TXN_LIMIT; loadTxns(); };
            pager.appendChild(next);
        }
        const info = document.createElement('span');
        info.style.cssText = 'font-size:11px;color:var(--muted);margin-left:10px;';
        info.textContent = `${_txnOffset+1}–${Math.min(_txnOffset+TXN_LIMIT,total)} of ${total}`;
        pager.appendChild(info);
    }

    function openTxnModal() {
        populateAccountDropdowns();
        document.getElementById('txnDate').value = new Date().toISOString().slice(0,10);
        document.getElementById('txnAmount').value = '';
        document.getElementById('txnDescription').value = '';
        document.getElementById('txnRef').value = '';
        document.getElementById('modalTxn').style.display = 'flex';
    }
    function closeTxnModal() { document.getElementById('modalTxn').style.display = 'none'; }

    async function saveTxn() {
        const params = {
            account_id:  document.getElementById('txnAccountId').value,
            type:        document.getElementById('txnType').value,
            amount:      document.getElementById('txnAmount').value,
            txn_date:    document.getElementById('txnDate').value,
            value_date:  document.getElementById('txnValueDate').value,
            category:    document.getElementById('txnCategory').value,
            description: document.getElementById('txnDescription').value.trim(),
            ref_number:  document.getElementById('txnRef').value.trim(),
        };
        if (!params.account_id) { alert('Select an account.'); return; }
        if (!params.amount || parseFloat(params.amount) <= 0) { alert('Enter a valid amount.'); return; }
        await apiFetch('bank_txn_add', params, 'POST');
        closeTxnModal();
        await Promise.all([loadAccounts(), loadSummary(), loadTxns(true)]);
    }

    async function deleteTxn(id) {
        if (!confirm('Delete this transaction? Balance will be recalculated.')) return;
        await apiFetch('bank_txn_delete', { txn_id: id }, 'POST');
        await Promise.all([loadAccounts(), loadSummary(), loadTxns(true)]);
    }

    // ── Balance Chart ─────────────────────────────────────────────────────────
    let _chart = null;
    async function loadChart() {
        const accountId = document.getElementById('baChartAccount').value;
        const params = accountId ? { account_id: accountId } : {};
        const d = await apiFetch('bank_balance_history', params);
        const history = d.data.history || [];
        const empty = document.getElementById('bankChartEmpty');

        if (!history.length) {
            empty.style.display = 'flex'; return;
        }
        empty.style.display = 'none';

        const labels = history.map(h => h.snap_date);
        const values = history.map(h => parseFloat(h.balance));

        const ctx = document.getElementById('bankBalanceChart').getContext('2d');
        if (_chart) _chart.destroy();
        _chart = new Chart(ctx, {
            type: 'line',
            data: {
                labels,
                datasets: [{
                    label: 'Balance',
                    data: values,
                    borderColor: 'var(--accent2)',
                    backgroundColor: 'color-mix(in srgb, var(--accent2) 12%, transparent)',
                    fill: true,
                    tension: 0.3,
                    pointRadius: history.length > 60 ? 0 : 3,
                }]
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                plugins: { legend: { display: false },
                           tooltip: { callbacks: { label: ctx => WD.inr(ctx.parsed.y) } } },
                scales: {
                    x: { ticks: { color: 'var(--muted)', maxTicksLimit: 12 }, grid: { color: 'var(--border)' } },
                    y: { ticks: { color: 'var(--muted)', callback: v => WD.inrCompact(v) }, grid: { color: 'var(--border)' } },
                }
            }
        });
    }

    // ── Helpers ───────────────────────────────────────────────────────────────
    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

    function toggleRdFields() {
        const isRd = document.getElementById('bankAccountType').value === 'rd';
        document.getElementById('rdFields').style.display = isRd ? '' : 'none';
    }
    function toggleJointField() {
        const isJoint = document.getElementById('bankIsJoint').checked;
        document.getElementById('jointHolder').style.display = isJoint ? '' : 'none';
    }

    // ── Tab switching ─────────────────────────────────────────────────────────
    function initTabs() {
        document.querySelectorAll('.ctab').forEach(tab => {
            tab.addEventListener('click', () => {
                document.querySelectorAll('.ctab').forEach(t => t.classList.remove('active'));
                document.querySelectorAll('.ctab-pane').forEach(p => p.classList.remove('active'));
                tab.classList.add('active');
                const pane = document.getElementById(tab.dataset.tab);
                if (pane) {
                    pane.classList.add('active');
                    if (tab.dataset.tab === 'ba-transactions') loadTxns(true);
                    if (tab.dataset.tab === 'ba-chart') loadChart();
                }
            });
        });
    }

    // ── Init ──────────────────────────────────────────────────────────────────
    async function init() {
        initTabs();
        document.getElementById('btnAddBank').addEventListener('click', () => openModal());
        document.getElementById('btnRefreshBanks').addEventListener('click', refresh);
        document.getElementById('baStatusFilter').addEventListener('change', loadAccounts);
        document.getElementById('bankAccountType').addEventListener('change', toggleRdFields);
        document.getElementById('bankIsJoint').addEventListener('change', toggleJointField);
        document.getElementById('btnAddTxn').addEventListener('click', openTxnModal);
        document.getElementById('baTxnAccountFilter').addEventListener('change', () => loadTxns(true));
        document.getElementById('baTxnTypeFilter').addEventListener('change', () => loadTxns(true));
        document.getElementById('baChartAccount').addEventListener('change', loadChart);

        await Promise.all([loadSummary(), loadAccounts()]);
    }

    async function refresh() {
        await Promise.all([loadSummary(), loadAccounts()]);
    }

    return { init, openModal, closeModal, saveAccount, deleteAccount,
             openBalModal, closeBalModal, saveBalance,
             openTxnModal: () => openTxnModal(), closeTxnModal, saveTxn, deleteTxn };
})();

document.addEventListener('DOMContentLoaded', WDBank.init);
</script>
