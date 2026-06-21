/* ═══════════════════════════════════════════════════════════════════════════
   t114 — GOLD TRACKER (localStorage-based unified view)
   t152 — CIBIL Credit Score Tracker
   Both: standalone widgets, add to dashboard
═══════════════════════════════════════════════════════════════════════════ */

// ── t114: Gold Tracker ────────────────────────────────────────────────────
const GOLD_KEY = 'wd_gold_holdings_v1';
const GOLD_RATE_KEY = 'wd_gold_rate_v1';

function getGoldHoldings() {
  try { return JSON.parse(localStorage.getItem(GOLD_KEY) || '[]'); } catch(e) { return []; }
}
function saveGoldHoldings(h) {
  try { localStorage.setItem(GOLD_KEY, JSON.stringify(h)); } catch(e) {}
}

async function fetchGoldRate() {
  // Try cached rate first (< 1 hr old)
  try {
    const cached = JSON.parse(localStorage.getItem(GOLD_RATE_KEY) || '{}');
    if (cached.rate && (Date.now() - cached.ts) < 3600000) return cached.rate;
  } catch(e) {}
  // Fallback: approximate MCX gold rate (₹ per gram, 24K)
  // Real integration would use MCX API or commodities feed
  const approxRate = 7200; // ₹ per gram approx (update manually or via API)
  try { localStorage.setItem(GOLD_RATE_KEY, JSON.stringify({rate: approxRate, ts: Date.now()})); } catch(e) {}
  return approxRate;
}

async function renderGoldWidget(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const holdings  = getGoldHoldings();
  const goldRate  = await fetchGoldRate();
  const totalGrams= holdings.reduce((s,h) => s + (parseFloat(h.grams)||0), 0);
  const totalCost = holdings.reduce((s,h) => s + (parseFloat(h.grams)||0)*(parseFloat(h.purchase_price)||0), 0);
  const totalValue= totalGrams * goldRate;
  const gainPct   = totalCost > 0 ? ((totalValue-totalCost)/totalCost*100).toFixed(1) : 0;

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  container.innerHTML = `
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;">
      <div style="flex:1;">
        <div style="font-size:11px;color:var(--text-muted);">Total Gold</div>
        <div style="font-size:20px;font-weight:800;color:#d97706;">${totalGrams.toFixed(2)}g</div>
      </div>
      <div style="flex:1;text-align:center;">
        <div style="font-size:11px;color:var(--text-muted);">Current Value</div>
        <div style="font-size:16px;font-weight:800;">${fmtI(totalValue)}</div>
      </div>
      <div style="flex:1;text-align:right;">
        <div style="font-size:11px;color:var(--text-muted);">Gain/Loss</div>
        <div style="font-size:16px;font-weight:800;color:${gainPct>=0?'#16a34a':'#dc2626'};">${gainPct>=0?'+':''}${gainPct}%</div>
      </div>
    </div>
    ${holdings.length ? `
    <div style="font-size:12px;margin-bottom:10px;">
      ${holdings.map(h => {
        const val  = (parseFloat(h.grams)||0) * goldRate;
        const gain = val - (parseFloat(h.grams)||0)*(parseFloat(h.purchase_price)||0);
        const typeIcon = {physical:'🪙',digital:'💻',etf:'📊',sgb:'📜'}[h.type]||'🥇';
        return `<div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border);">
          <span>${typeIcon}</span>
          <div style="flex:1;">
            <div style="font-weight:600;">${h.name||h.type}</div>
            <div style="font-size:11px;color:var(--text-muted);">${h.grams}g · ₹${h.purchase_price}/g</div>
          </div>
          <div style="text-align:right;">
            <div style="font-weight:700;">${fmtI(val)}</div>
            <div style="font-size:11px;color:${gain>=0?'#16a34a':'#dc2626'};">${gain>=0?'+':''}${fmtI(gain)}</div>
          </div>
        </div>`;
      }).join('')}
    </div>` : `<div style="text-align:center;color:var(--text-muted);font-size:13px;padding:12px;">No gold holdings. Click + to add.</div>`}
    <div style="display:flex;gap:8px;margin-top:8px;">
      <button onclick="openAddGold()" style="flex:1;padding:7px;border-radius:7px;background:var(--accent);color:#fff;border:none;font-weight:700;font-size:12px;cursor:pointer;">+ Add Gold</button>
      <button onclick="updateGoldRate()" style="padding:7px 12px;border-radius:7px;background:none;border:1.5px solid var(--border);color:var(--text-muted);font-size:12px;cursor:pointer;" title="Update MCX rate">⟳ Rate: ₹${goldRate}/g</button>
    </div>`;
}

function openAddGold() {
  const type  = prompt('Type (physical/digital/etf/sgb):', 'physical');
  if (!type) return;
  const name  = prompt('Name (e.g. "Gold Coin 10g", "Sovereign Gold Bond Sr X"):') || type;
  const grams = parseFloat(prompt('Weight in grams:') || '0');
  const price = parseFloat(prompt('Purchase price per gram (₹):') || '0');
  const date  = prompt('Purchase date (YYYY-MM-DD):', new Date().toISOString().slice(0,10));
  if (!grams || !price) return;
  const holdings = getGoldHoldings();
  holdings.push({ id: Date.now(), type, name, grams, purchase_price: price, purchase_date: date });
  saveGoldHoldings(holdings);
  renderGoldWidget('goldTrackerWidget');
  if (typeof showToast === 'function') showToast(`✅ Added ${grams}g ${type} gold`, 'success');
}

function updateGoldRate() {
  const rate = parseFloat(prompt('Enter current MCX 24K gold rate (₹ per gram):') || '0');
  if (rate > 0) {
    try { localStorage.setItem(GOLD_RATE_KEY, JSON.stringify({rate, ts: Date.now()})); } catch(e) {}
    renderGoldWidget('goldTrackerWidget');
  }
}

// ── t152: CIBIL Credit Score Tracker ─────────────────────────────────────
const CIBIL_KEY = 'wd_cibil_scores_v1';

function getCibilScores() {
  try { return JSON.parse(localStorage.getItem(CIBIL_KEY) || '[]'); } catch(e) { return []; }
}

function renderCibilWidget(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const scores  = getCibilScores();
  const latest  = scores[scores.length - 1] || null;
  const prev    = scores[scores.length - 2] || null;
  const change  = latest && prev ? latest.score - prev.score : null;

  function scoreColor(s) {
    if (s >= 750) return '#16a34a';
    if (s >= 700) return '#d97706';
    if (s >= 650) return '#f59e0b';
    return '#dc2626';
  }
  function scoreLabel(s) {
    if (s >= 750) return 'Excellent';
    if (s >= 700) return 'Good';
    if (s >= 650) return 'Fair';
    return 'Poor';
  }

  container.innerHTML = `
    ${latest ? `
    <div style="text-align:center;margin-bottom:14px;">
      <div style="font-size:48px;font-weight:900;color:${scoreColor(latest.score)};">${latest.score}</div>
      <div style="font-size:13px;font-weight:700;color:${scoreColor(latest.score)};margin-bottom:2px;">${scoreLabel(latest.score)}</div>
      <div style="font-size:11px;color:var(--text-muted);">Updated: ${latest.date}</div>
      ${change !== null ? `<div style="font-size:12px;margin-top:4px;color:${change>=0?'#16a34a':'#dc2626'};font-weight:700;">${change>=0?'▲ +':'▼ '}${Math.abs(change)} from last check</div>` : ''}
    </div>
    ${scores.length > 1 ? `
    <div style="display:flex;align-items:flex-end;gap:4px;height:60px;margin-bottom:12px;">
      ${scores.slice(-12).map(s => {
        const pct = ((s.score - 300) / (900 - 300) * 100).toFixed(0);
        return `<div style="flex:1;background:${scoreColor(s.score)};border-radius:3px 3px 0 0;height:${pct}%;min-height:4px;" title="${s.score} on ${s.date}"></div>`;
      }).join('')}
    </div>` : ''}` : `<div style="text-align:center;color:var(--text-muted);font-size:13px;padding:20px;">No CIBIL score logged yet.</div>`}
    <div style="background:rgba(99,102,241,.06);border-radius:8px;padding:10px;font-size:11px;color:var(--text-muted);margin-bottom:10px;">
      💡 Check free score monthly at CIBIL, Experian, or via your bank app. Target 750+ for best loan rates.
    </div>
    <button onclick="logCibilScore()" style="width:100%;padding:7px;border-radius:7px;background:var(--accent);color:#fff;border:none;font-weight:700;font-size:12px;cursor:pointer;">+ Log Score</button>`;
}

function logCibilScore() {
  const score = parseInt(prompt('Enter your CIBIL score (300-900):') || '0');
  if (!score || score < 300 || score > 900) { alert('Invalid score. Must be 300-900.'); return; }
  const scores = getCibilScores();
  scores.push({ score, date: new Date().toLocaleDateString('en-IN') });
  if (scores.length > 24) scores.shift(); // keep last 24 months
  try { localStorage.setItem(CIBIL_KEY, JSON.stringify(scores)); } catch(e) {}
  renderCibilWidget('cibilWidget');
  if (typeof showToast === 'function') showToast(`✅ CIBIL score ${score} logged`, 'success');
}

/* ═══════════════════════════════════════════════════════════════════════════
   t120 — SMALLCASE PORTFOLIO TRACKER (localStorage-based)
═══════════════════════════════════════════════════════════════════════════ */
const SMALLCASE_KEY = 'wd_smallcases_v1';

function getSmallcases() {
  try { return JSON.parse(localStorage.getItem(SMALLCASE_KEY) || '[]'); } catch(e) { return []; }
}
function saveSmallcases(s) {
  try { localStorage.setItem(SMALLCASE_KEY, JSON.stringify(s)); } catch(e) {}
}

function renderSmallcaseWidget(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;
  const scs = getSmallcases();

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  const totalInvested = scs.reduce((s,sc) => s+(parseFloat(sc.invested)||0), 0);
  const totalCurrent  = scs.reduce((s,sc) => s+(parseFloat(sc.current_value)||0), 0);
  const totalGain     = totalCurrent - totalInvested;
  const totalGainPct  = totalInvested > 0 ? (totalGain/totalInvested*100).toFixed(1) : 0;

  container.innerHTML = `
    <div style="display:flex;align-items:center;gap:12px;margin-bottom:12px;flex-wrap:wrap;">
      <div style="flex:1;"><div style="font-size:11px;color:var(--text-muted);">Total Invested</div><div style="font-size:18px;font-weight:800;">${fmtI(totalInvested)}</div></div>
      <div style="flex:1;"><div style="font-size:11px;color:var(--text-muted);">Current Value</div><div style="font-size:18px;font-weight:800;">${fmtI(totalCurrent)}</div></div>
      <div style="flex:1;"><div style="font-size:11px;color:var(--text-muted);">Gain/Loss</div><div style="font-size:18px;font-weight:800;color:${totalGain>=0?'#16a34a':'#dc2626'};">${totalGain>=0?'+':''}${totalGainPct}%</div></div>
    </div>
    ${scs.length ? `<div style="font-size:12px;margin-bottom:10px;">
      ${scs.map((sc,i) => {
        const gain = (parseFloat(sc.current_value)||0) - (parseFloat(sc.invested)||0);
        const gainPct = sc.invested > 0 ? (gain/sc.invested*100).toFixed(1) : 0;
        const rebalDue = sc.last_rebalance ? Math.ceil((new Date() - new Date(sc.last_rebalance)) / (30*86400000)) >= 3 : false;
        return `<div style="display:flex;align-items:center;gap:8px;padding:8px 0;border-bottom:1px solid var(--border);">
          <div style="flex:1;">
            <div style="font-weight:700;">${sc.name}</div>
            <div style="font-size:11px;color:var(--text-muted);">${sc.manager||'—'} · ${sc.stocks||0} stocks${rebalDue?' · <span style="color:#d97706;">⚠️ Rebalance due</span>':''}</div>
          </div>
          <div style="text-align:right;">
            <div style="font-weight:700;">${fmtI(sc.current_value)}</div>
            <div style="font-size:11px;color:${gain>=0?'#16a34a':'#dc2626'};">${gain>=0?'+':''}${gainPct}%</div>
          </div>
          <button onclick="deleteSmallcase(${i},'${containerId}')" style="background:none;border:none;color:var(--text-muted);cursor:pointer;font-size:14px;padding:2px 6px;">✕</button>
        </div>`;
      }).join('')}
    </div>` : `<div style="text-align:center;color:var(--text-muted);font-size:13px;padding:16px;">No smallcases tracked. Add your first.</div>`}
    <button onclick="openAddSmallcase('${containerId}')" style="width:100%;padding:7px;border-radius:7px;background:var(--accent);color:#fff;border:none;font-weight:700;font-size:12px;cursor:pointer;">+ Add Smallcase</button>`;
}

function openAddSmallcase(containerId) {
  const name    = prompt('Smallcase name (e.g. "All Weather Investing"):');
  if (!name) return;
  const manager = prompt('Manager/Publisher:') || '';
  const invested= parseFloat(prompt('Amount invested (₹):') || '0');
  const current = parseFloat(prompt('Current value (₹):', invested) || invested);
  const stocks  = parseInt(prompt('Number of stocks in basket:') || '0');
  const scs = getSmallcases();
  scs.push({ name, manager, invested, current_value: current, stocks, last_rebalance: new Date().toISOString().slice(0,10), added: new Date().toISOString() });
  saveSmallcases(scs);
  renderSmallcaseWidget(containerId);
  if (typeof showToast === 'function') showToast(`✅ Smallcase "${name}" added`, 'success');
}

function deleteSmallcase(idx, containerId) {
  if (!confirm('Remove this smallcase?')) return;
  const scs = getSmallcases();
  scs.splice(idx, 1);
  saveSmallcases(scs);
  renderSmallcaseWidget(containerId);
}

function updateSmallcaseValue(idx, containerId) {
  const scs = getSmallcases();
  const sc  = scs[idx];
  if (!sc) return;
  const newVal = parseFloat(prompt(`Current value of "${sc.name}" (₹):`, sc.current_value) || sc.current_value);
  sc.current_value   = newVal;
  sc.last_rebalance  = new Date().toISOString().slice(0,10);
  saveSmallcases(scs);
  renderSmallcaseWidget(containerId);
}
