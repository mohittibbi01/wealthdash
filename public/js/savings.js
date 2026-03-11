/**
 * WealthDash — Savings Accounts JS (savings.js)
 * Handles: Account list, Add account, Record interest, 80TTA tracker
 */

const SAV = {
  accounts: [],
  portfolioFilter: '',
  delType: null,
  delId: null,

  init() {
    document.getElementById('btnAddAccount').addEventListener('click', () => SAV.openAcctModal());
    document.getElementById('btnAddInterestEntry').addEventListener('click', () => SAV.openIntModal());

    document.getElementById('closeAcctModal').addEventListener('click', () => SAV.closeAcctModal());
    document.getElementById('cancelAcct').addEventListener('click', () => SAV.closeAcctModal());
    document.getElementById('saveAcct').addEventListener('click', () => SAV.saveAccount());

    document.getElementById('closeIntModal').addEventListener('click', () => SAV.closeIntModal());
    document.getElementById('cancelInt').addEventListener('click', () => SAV.closeIntModal());
    document.getElementById('saveInt').addEventListener('click', () => SAV.saveInterest());

    document.getElementById('closeDelSav').addEventListener('click', () => SAV.closeDelModal());
    document.getElementById('cancelDelSav').addEventListener('click', () => SAV.closeDelModal());
    document.getElementById('confirmDelSav').addEventListener('click', () => SAV.confirmDelete());

    document.getElementById('intFilterAccount').addEventListener('change', () => SAV.loadInterestHistory());

    this.loadAccounts();
    this.loadInterestHistory();
  },

  async loadAccounts() {
    const body = document.getElementById('savBody');
    body.innerHTML = '<tr><td colspan="9" class="text-center" style="padding:40px"><span class="spinner"></span></td></tr>';

    const params = new URLSearchParams({ action: 'savings_list', type: 'accounts' });
    const pf = document.getElementById('filterPortfolio')?.value;
    if (pf) params.set('portfolio_id', pf);

    try {
      const res  = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      SAV.accounts = data.data || [];

      // Populate account filter in interest section
      const sel = document.getElementById('intFilterAccount');
      const cur = sel.value;
      sel.innerHTML = '<option value="">All Accounts</option>';
      SAV.accounts.forEach(a => {
        const opt = document.createElement('option');
        opt.value = a.id;
        opt.textContent = `${a.bank_name} — ${a.account_type}${a.account_number ? ' ****'+a.account_number.slice(-4) : ''}`;
        sel.appendChild(opt);
      });
      sel.value = cur;

      // Also populate add-interest account select
      const intSel = document.getElementById('intAccountId');
      intSel.innerHTML = SAV.accounts.map(a =>
        `<option value="${a.id}">${escHtml(a.bank_name)} — ${a.account_type}${a.account_number ? ' ****'+a.account_number.slice(-4):''}</option>`
      ).join('');

      if (!SAV.accounts.length) {
        body.innerHTML = '<tr><td colspan="9" class="text-center empty-state" style="padding:60px"><div style="font-size:40px">🏦</div><p>No savings accounts yet.<br>Add your first bank account.</p></td></tr>';
        return;
      }

      body.innerHTML = SAV.accounts.map(a => {
        const annualInt = parseFloat(a.annual_interest_earned || 0);
        return `<tr>
          <td>
            <div class="fund-name">${escHtml(a.bank_name)}</div>
            <div class="fund-sub">${escHtml(a.portfolio_name||'')}</div>
          </td>
          <td>${a.account_number ? '****'+a.account_number.slice(-4) : '—'}</td>
          <td><span class="badge badge-outline">${a.account_type}</span></td>
          <td>${escHtml(a.portfolio_name||'')}</td>
          <td class="text-right"><strong>${fmtInr(a.current_balance)}</strong><br><small style="color:var(--text-muted)">as of ${fmtDate(a.balance_date)}</small></td>
          <td class="text-right">${parseFloat(a.interest_rate||0).toFixed(2)}%</td>
          <td class="text-right text-success">${fmtInr(annualInt)}</td>
          <td class="text-right">${fmtDate(a.balance_date)}</td>
          <td class="text-center" style="white-space:nowrap">
            <button class="btn btn-sm btn-ghost" onclick="SAV.updateBalance(${a.id})" title="Update balance">📝 Update</button>
            <button class="btn btn-sm btn-danger-ghost" onclick="SAV.openDelModal('account',${a.id},'Delete this savings account?')" title="Delete">🗑️</button>
          </td>
        </tr>`;
      }).join('');
    } catch(e) {
      body.innerHTML = `<tr><td colspan="9" class="text-center text-danger" style="padding:40px">Error: ${e.message}</td></tr>`;
    }
  },

  async loadInterestHistory() {
    const body = document.getElementById('savIntBody');
    body.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:40px"><span class="spinner"></span></td></tr>';

    const acctId = document.getElementById('intFilterAccount')?.value;
    const params = new URLSearchParams({ action: 'savings_list', type: 'interest' });
    if (acctId) params.set('account_id', acctId);

    try {
      const res  = await fetch(APP_URL + '/api/router.php?' + params);
      const data = await res.json();
      if (!data.success) throw new Error(data.message);
      const rows = data.data || [];
      if (!rows.length) {
        body.innerHTML = '<tr><td colspan="7" class="text-center" style="padding:40px;color:var(--text-muted)">No interest credits recorded yet.</td></tr>';
        return;
      }
      body.innerHTML = rows.map(r => `<tr>
        <td>${fmtDate(r.interest_date)}</td>
        <td>${escHtml(r.bank_name)}</td>
        <td class="text-right text-success">${fmtInr(r.interest_amount)}</td>
        <td class="text-right">${r.balance_after ? fmtInr(r.balance_after) : '—'}</td>
        <td>${r.interest_fy}</td>
        <td>${escHtml(r.notes||'')}</td>
        <td class="text-center">
          <button class="btn btn-sm btn-danger-ghost" onclick="SAV.openDelModal('interest',${r.id},'Delete this interest entry?')" title="Delete">🗑️</button>
        </td>
      </tr>`).join('');
    } catch(e) {
      body.innerHTML = `<tr><td colspan="7" class="text-center text-danger" style="padding:40px">Error: ${e.message}</td></tr>`;
    }
  },

  openAcctModal() {
    ['savBankName','savAccountNum','savInterestRate','savBalance'].forEach(id => document.getElementById(id).value = '');
    document.getElementById('savBalanceDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('savAcctType').value = 'savings';
    document.getElementById('savAcctError').style.display = 'none';
    document.getElementById('modalAddAccount').style.display = 'flex';
  },
  closeAcctModal() { document.getElementById('modalAddAccount').style.display='none'; },

  async saveAccount() {
    const errEl = document.getElementById('savAcctError');
    errEl.style.display='none';
    const btn=document.getElementById('saveAcct');
    btn.disabled=true; btn.textContent='Saving...';
    const body = new URLSearchParams({
      action:        'savings_add',
      portfolio_id:  document.getElementById('savPortfolio').value,
      bank_name:     document.getElementById('savBankName').value,
      account_number: document.getElementById('savAccountNum').value,
      account_type:  document.getElementById('savAcctType').value,
      interest_rate: document.getElementById('savInterestRate').value,
      current_balance: document.getElementById('savBalance').value,
      balance_date:  document.getElementById('savBalanceDate').value,
    });
    try {
      const res=await fetch(APP_URL+'/api/router.php',{method:'POST',body});
      const data=await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeAcctModal(); showToast('Account added!','success');
      this.loadAccounts();
    } catch(e) { errEl.textContent=e.message; errEl.style.display='block'; }
    finally { btn.disabled=false; btn.textContent='Save Account'; }
  },

  openIntModal() {
    document.getElementById('intDate').value = new Date().toISOString().split('T')[0];
    document.getElementById('intAmount').value = '';
    document.getElementById('intBalanceAfter').value = '';
    document.getElementById('intNotes').value = '';
    document.getElementById('intError').style.display='none';
    document.getElementById('modalAddInterest').style.display='flex';
  },
  closeIntModal() { document.getElementById('modalAddInterest').style.display='none'; },

  async saveInterest() {
    const errEl=document.getElementById('intError');
    errEl.style.display='none';
    const btn=document.getElementById('saveInt');
    btn.disabled=true; btn.textContent='Saving...';
    const body = new URLSearchParams({
      action:         'savings_add_interest',
      account_id:     document.getElementById('intAccountId').value,
      interest_date:  document.getElementById('intDate').value,
      interest_amount: document.getElementById('intAmount').value,
      balance_after:  document.getElementById('intBalanceAfter').value,
      notes:          document.getElementById('intNotes').value,
    });
    try {
      const res=await fetch(APP_URL+'/api/router.php',{method:'POST',body});
      const data=await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeIntModal(); showToast('Interest recorded!','success');
      this.loadAccounts(); this.loadInterestHistory();
    } catch(e) { errEl.textContent=e.message; errEl.style.display='block'; }
    finally { btn.disabled=false; btn.textContent='Record Interest'; }
  },

  async updateBalance(accountId) {
    const bal = prompt('Enter new balance (₹):');
    if (bal === null) return;
    const body = new URLSearchParams({ action: 'savings_update_balance', account_id: accountId, balance: bal, balance_date: new Date().toISOString().split('T')[0] });
    try {
      const res=await fetch(APP_URL+'/api/router.php',{method:'POST',body});
      const data=await res.json();
      if (!data.success) throw new Error(data.message);
      showToast('Balance updated!','success'); this.loadAccounts();
    } catch(e) { showToast('Error: '+e.message,'error'); }
  },

  openDelModal(type, id, msg) {
    SAV.delType=type; SAV.delId=id;
    document.getElementById('delSavMsg').textContent=msg;
    document.getElementById('modalDelSav').style.display='flex';
  },
  closeDelModal() { document.getElementById('modalDelSav').style.display='none'; SAV.delType=null; SAV.delId=null; },
  async confirmDelete() {
    const action = SAV.delType==='account' ? 'savings_delete' : 'savings_delete_interest';
    const btn=document.getElementById('confirmDelSav');
    btn.disabled=true; btn.textContent='Deleting...';
    try {
      const res=await fetch(APP_URL+'/api/router.php',{method:'POST',body:new URLSearchParams({action,id:SAV.delId})});
      const data=await res.json();
      if (!data.success) throw new Error(data.message);
      this.closeDelModal(); showToast('Deleted.','success');
      this.loadAccounts(); this.loadInterestHistory();
    } catch(e) { showToast('Error: '+e.message,'error'); }
    finally { btn.disabled=false; btn.textContent='Delete'; }
  },
};

function fmtInr(v){ const n=parseFloat(v||0); return(n<0?'-':'')+'₹'+Math.abs(n).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2}); }
function fmtDate(d){ if(!d)return'—'; const[y,m,dd]=d.split('-'); return`${dd}-${m}-${y}`; }
function escHtml(t){ const d=document.createElement('div'); d.appendChild(document.createTextNode(t||'')); return d.innerHTML; }

document.addEventListener('DOMContentLoaded', () => SAV.init());
