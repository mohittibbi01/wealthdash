<?php
/**
 * WealthDash — t59: AI Auto Categorization Page
 * File: pages/ai/auto_categorize.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle='AI Auto Categorization'; $activePage='ai'; $activeSection='ai';
ob_start();
?>
<div class="page-header"><h1 class="page-title">🏷 AI Auto Categorization</h1><p class="page-subtitle">Automatically categorize budget transactions + manage custom rules.</p></div>

<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;margin-bottom:20px;" class="responsive-grid-1col">
  <!-- Test categorizer -->
  <div class="card">
    <div class="card-header"><span class="card-title">🔍 Test Categorizer</span></div>
    <div class="card-body">
      <div class="form-group"><label class="form-label">Transaction Description</label><input type="text" id="ac-test-desc" class="form-control" placeholder="e.g. Swiggy order, Uber ride, Amazon purchase" onkeydown="if(event.key==='Enter')AC.test()"></div>
      <button class="btn btn-primary btn-sm" onclick="AC.test()">Categorize</button>
      <div id="ac-test-result" style="margin-top:14px;"></div>
    </div>
  </div>

  <!-- Bulk categorize -->
  <div class="card">
    <div class="card-header"><span class="card-title">⚡ Bulk Categorize</span></div>
    <div class="card-body">
      <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">Auto-categorize all uncategorized budget transactions using rules.</p>
      <button class="btn btn-primary" onclick="AC.bulkRun()">⚡ Run Bulk Categorization</button>
      <div id="ac-bulk-result" style="margin-top:14px;"></div>
    </div>
  </div>
</div>

<!-- Custom rules -->
<div class="card">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span class="card-title">⚙️ Custom Rules</span>
    <button class="btn btn-primary btn-sm" onclick="AC.openAddRule()">+ Add Rule</button>
  </div>
  <div class="card-body p-0"><div id="ac-rules-table"></div></div>
</div>

<!-- Add Rule Modal -->
<div id="ac-rule-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)AC.closeRule()">
  <div class="modal" style="max-width:400px;">
    <div class="modal-header"><span class="modal-title">+ Add Category Rule</span><button class="modal-close" onclick="AC.closeRule()">×</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Keyword (matches in description)</label><input type="text" id="ac-r-keyword" class="form-control" placeholder="e.g. 'starbucks', 'flipkart'"></div>
      <div class="form-group"><label class="form-label">Maps to Category</label>
        <select id="ac-r-category" class="form-control">
          <option>Salary</option><option>Business Income</option><option>Rental Income</option><option>Other Income</option>
          <option>Housing/Rent</option><option>Groceries</option><option>Transport</option><option>Utilities</option>
          <option>Insurance EMI</option><option>Loan EMI</option><option>Investments/SIP</option><option>Entertainment</option>
          <option>Healthcare</option><option>Education</option><option>Shopping</option><option>Dining Out</option>
          <option>Savings</option><option>Other Expense</option>
        </select>
      </div>
    </div>
    <div class="modal-footer"><button class="btn btn-ghost" onclick="AC.closeRule()">Cancel</button><button class="btn btn-primary" onclick="AC.saveRule()">Save Rule</button></div>
  </div>
</div>

<script>
const AC={
  test(){
    const desc=document.getElementById('ac-test-desc').value.trim();
    if(!desc)return;
    document.getElementById('ac-test-result').innerHTML='<div style="color:var(--text-muted);">Categorizing…</div>';
    apiPost({action:'ai_categorize_transaction',description:desc}).then(r=>{
      if(!r.ok){showToast(r.message,'error');return;}
      const d=r.data;
      const badge=d.method==='ai'?'🤖 AI':d.method==='rule'?'📋 Rule Match':'❓ Default';
      document.getElementById('ac-test-result').innerHTML=`<div class="alert alert-success"><strong>${esc(d.category)}</strong> <span class="badge" style="margin-left:8px;">${badge}</span><br><small>Confidence: ${esc(d.confidence)}</small></div>`;
    });
  },
  bulkRun(){
    document.getElementById('ac-bulk-result').innerHTML='<div style="color:var(--text-muted);">Running…</div>';
    apiPost({action:'ai_categorize_bulk'}).then(r=>{
      if(!r.ok){showToast(r.message,'error');return;}
      const d=r.data;
      document.getElementById('ac-bulk-result').innerHTML=`<div class="alert alert-success">Categorized ${d.categorized}/${d.total} transactions. ${d.remaining} need manual review.</div>`;
    });
  },
  loadRules(){
    apiPost({action:'ai_category_rules_list'}).then(r=>{
      if(!r.ok)return;
      const rows=r.data.rules||[];
      const wrap=document.getElementById('ac-rules-table');
      if(!rows.length){wrap.innerHTML='<div class="empty-state" style="padding:30px;"><div>No custom rules yet. Built-in rules cover common merchants (Swiggy, Uber, Amazon, etc.)</div></div>';return;}
      let html=`<div class="table-responsive"><table class="data-table"><thead><tr><th>Keyword</th><th>Category</th><th></th></tr></thead><tbody>`;
      for(const rule of rows){html+=`<tr><td style="font-family:monospace;font-size:12px;">${esc(rule.keyword)}</td><td>${esc(rule.category)}</td><td><button class="btn btn-danger btn-sm" onclick="AC.delRule(${rule.id})">✕</button></td></tr>`;}
      html+='</tbody></table></div>';
      wrap.innerHTML=html;
    });
  },
  openAddRule(){document.getElementById('ac-r-keyword').value='';document.getElementById('ac-rule-modal').style.display='';},
  closeRule(){document.getElementById('ac-rule-modal').style.display='none';},
  saveRule(){apiPost({action:'ai_category_rule_add',keyword:document.getElementById('ac-r-keyword').value,category:document.getElementById('ac-r-category').value}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok){this.closeRule();this.loadRules();}});},
  delRule(id){if(!confirm('Delete this rule?'))return;apiPost({action:'ai_category_rule_delete',id}).then(r=>{showToast(r.message,r.ok?'success':'error');if(r.ok)this.loadRules();});}
};
document.addEventListener('DOMContentLoaded',()=>AC.loadRules());
</script>
<?php $pageContent=ob_get_clean(); include APP_ROOT.'/templates/layout.php';
