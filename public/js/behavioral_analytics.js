/* ═══════════════════════════════════════════════════════════════════════════
   t142 — FINANCIAL HEALTH SCORE (CIBIL-style, behavior-based)
   t145 — STOCK PICKER REALITY CHECK
   t146 — BEHAVIORAL SCORE (Anti-FOMO, Anti-Panic)
   All localStorage-based, no DB needed
═══════════════════════════════════════════════════════════════════════════ */

/* ─────────────────────────────────────────────────────────────────────────
   t142: Financial Health Score
   Components: SIP consistency + Diversification + Emergency Fund +
               Insurance + Debt-income + Tax efficiency
───────────────────────────────────────────────────────────────────────── */
const FHS_KEY = 'wd_fhs_inputs_v1';

function calcFinancialHealthScore(inputs = {}) {
  // inputs: { sipConsistencyPct, fundCount, categories, emergencyMonths,
  //           hasTermInsurance, hasHealthInsurance, debtEmiPct, elssAmt, ppfAmt }
  let score = 300; // base

  // 1. SIP Consistency (max 135 pts = 25% of 540 range)
  const sipPct = Math.min(100, inputs.sipConsistencyPct || 0);
  score += (sipPct / 100) * 135;

  // 2. Diversification (max 108 pts = 20%)
  const cats = (inputs.categories || []).length;
  const divScore = Math.min(1, cats / 4); // 4+ asset classes = full score
  score += divScore * 108;

  // 3. Emergency Fund (max 81 pts = 15%)
  const efMonths = inputs.emergencyMonths || 0;
  score += Math.min(1, efMonths / 6) * 81;

  // 4. Insurance (max 81 pts = 15%)
  const insScore = ((inputs.hasTermInsurance ? 0.6 : 0) + (inputs.hasHealthInsurance ? 0.4 : 0));
  score += insScore * 81;

  // 5. Debt burden (max 54 pts = 10%) — lower EMI% = better
  const emiPct = inputs.debtEmiPct || 0;
  const debtScore = emiPct <= 30 ? 1 : emiPct <= 50 ? 0.5 : 0;
  score += debtScore * 54;

  // 6. Tax efficiency (max 81 pts = 15%) — ELSS/PPF/NPS usage
  const taxAmt = (inputs.elssAmt || 0) + (inputs.ppfAmt || 0) + (inputs.npsAmt || 0);
  const taxScore = Math.min(1, taxAmt / 150000);
  score += taxScore * 81;

  return Math.round(Math.min(900, score));
}

function getScoreLabel(s) {
  if (s >= 800) return { label: 'Excellent', color: '#15803d', emoji: '🏆' };
  if (s >= 700) return { label: 'Good', color: '#16a34a', emoji: '✅' };
  if (s >= 600) return { label: 'Average', color: '#d97706', emoji: '📈' };
  if (s >= 500) return { label: 'Fair', color: '#f59e0b', emoji: '⚠️' };
  return { label: 'Needs Work', color: '#dc2626', emoji: '🔴' };
}

function renderFinancialHealthScore(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  let inputs = {};
  try { inputs = JSON.parse(localStorage.getItem(FHS_KEY) || '{}'); } catch(e) {}

  const score = calcFinancialHealthScore(inputs);
  const { label, color, emoji } = getScoreLabel(score);

  // Improvement suggestions
  const tips = [];
  if ((inputs.sipConsistencyPct || 0) < 80) tips.push({ pts: 30, tip: 'Maintain SIP consistency above 80%' });
  if ((inputs.emergencyMonths || 0) < 6)    tips.push({ pts: 25, tip: 'Build 6-month emergency fund in liquid fund' });
  if (!inputs.hasTermInsurance)              tips.push({ pts: 49, tip: 'Get term insurance (10x annual income)' });
  if (!inputs.hasHealthInsurance)            tips.push({ pts: 32, tip: 'Get health insurance (₹5L+ family floater)' });
  if ((inputs.elssAmt || 0) < 50000)        tips.push({ pts: 20, tip: 'Maximize 80C via ELSS/PPF to save tax' });

  container.innerHTML = `
    <div style="text-align:center;padding:16px 0;">
      <div style="font-size:56px;font-weight:900;color:${color};">${score}</div>
      <div style="font-size:14px;font-weight:700;color:${color};">${emoji} ${label} Financial Health</div>
      <div style="font-size:11px;color:var(--text-muted);margin-top:4px;">Based on your investment behavior</div>
    </div>
    <div style="height:10px;background:linear-gradient(90deg,#dc2626,#d97706,#16a34a);border-radius:99px;position:relative;margin:0 16px 16px;">
      <div style="position:absolute;top:-4px;left:${((score-300)/600*100).toFixed(1)}%;transform:translateX(-50%);width:18px;height:18px;background:white;border:3px solid ${color};border-radius:50%;box-shadow:0 2px 6px rgba(0,0,0,.3);"></div>
    </div>
    ${tips.length ? `
    <div style="padding:0 16px 8px;">
      <div style="font-size:11px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:8px;">How to improve:</div>
      ${tips.slice(0,3).map(t => `
        <div style="display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid var(--border);font-size:12px;">
          <span style="background:rgba(99,102,241,.1);color:#6366f1;padding:2px 7px;border-radius:4px;font-weight:800;white-space:nowrap;">+${t.pts}</span>
          <span>${t.tip}</span>
        </div>`).join('')}
    </div>` : `<div style="text-align:center;color:#16a34a;font-size:13px;padding:8px;">🎉 Excellent financial discipline!</div>`}
    <button onclick="openFhsSetup('${containerId}')" style="width:calc(100% - 32px);margin:12px 16px 16px;padding:8px;border-radius:7px;background:var(--accent);color:#fff;border:none;font-weight:700;font-size:12px;cursor:pointer;">⚙️ Update Inputs</button>`;
}

function openFhsSetup(containerId) {
  let inputs = {};
  try { inputs = JSON.parse(localStorage.getItem(FHS_KEY) || '{}'); } catch(e) {}

  const sip  = prompt('SIP consistency last 12 months (% months paid):', inputs.sipConsistencyPct || 90);
  const ef   = prompt('Emergency fund adequacy (months):', inputs.emergencyMonths || 3);
  const term = confirm('Do you have term life insurance?');
  const health = confirm('Do you have health insurance?');
  const emi  = prompt('EMI/loan payments as % of monthly income:', inputs.debtEmiPct || 0);
  const elss = prompt('Annual ELSS + PPF + NPS investment (₹):', inputs.elssAmt || 0);

  const updated = {
    sipConsistencyPct: parseFloat(sip) || 0,
    emergencyMonths:   parseFloat(ef)  || 0,
    hasTermInsurance:  term,
    hasHealthInsurance:health,
    debtEmiPct:        parseFloat(emi) || 0,
    elssAmt:           parseFloat(elss)|| 0,
    categories:        inputs.categories || ['Equity','Debt'],
    updatedAt:         new Date().toISOString(),
  };
  try { localStorage.setItem(FHS_KEY, JSON.stringify(updated)); } catch(e) {}
  renderFinancialHealthScore(containerId);
  if (typeof showToast === 'function') showToast('✅ Financial Health Score updated!', 'success');
}

/* ─────────────────────────────────────────────────────────────────────────
   t145: Stock Picker Reality Check
───────────────────────────────────────────────────────────────────────── */
function renderStockPickerCheck(containerId, stocksData = []) {
  const container = document.getElementById(containerId);
  if (!container) return;

  if (!stocksData.length) {
    container.innerHTML = `<div style="text-align:center;color:var(--text-muted);padding:20px;font-size:13px;">Load your stock holdings to see this analysis.</div>`;
    return;
  }

  const totalInvested   = stocksData.reduce((s,h) => s + (parseFloat(h.total_invested)||0), 0);
  const totalValue      = stocksData.reduce((s,h) => s + (parseFloat(h.current_value)||0), 0);
  const portfolioReturn = totalInvested > 0 ? ((totalValue/totalInvested - 1)*100) : 0;

  // Average first buy date weighted by investment
  let weightedDays = 0;
  stocksData.forEach(h => {
    const inv  = parseFloat(h.total_invested) || 0;
    const days = h.first_buy_date
      ? Math.ceil((Date.now() - new Date(h.first_buy_date)) / 86400000)
      : 365;
    weightedDays += days * inv;
  });
  const avgDays  = totalInvested > 0 ? weightedDays / totalInvested : 365;
  const avgYears = avgDays / 365;

  // Nifty 50 historical return: 14% CAGR over long term
  const niftyCAGR      = 14;
  const niftyEquiv     = totalInvested * Math.pow(1 + niftyCAGR/100, avgYears);
  const stockCAGR      = avgYears > 0 ? (Math.pow(totalValue/Math.max(1,totalInvested), 1/avgYears) - 1) * 100 : 0;
  const alpha          = stockCAGR - niftyCAGR;
  const opportunityCost= Math.max(0, niftyEquiv - totalValue);

  // Brokerage estimate: 0.1% per trade, ~8 trades per stock per year
  const brokerageEst   = totalInvested * 0.001 * stocksData.length * Math.min(avgYears, 3);

  function fmtI(v) {
    v = Math.abs(v);
    if (v >= 1e7) return '₹' + (v/1e7).toFixed(2) + 'Cr';
    if (v >= 1e5) return '₹' + (v/1e5).toFixed(1) + 'L';
    return '₹' + v.toLocaleString('en-IN', {maximumFractionDigits:0});
  }

  const beating = alpha > 0;

  container.innerHTML = `
    <div style="margin-bottom:14px;padding:12px;background:${beating?'rgba(22,163,74,.07)':'rgba(220,38,38,.07)'};border-radius:10px;border:1px solid ${beating?'#86efac':'#fca5a5'};">
      <div style="font-size:13px;font-weight:700;color:${beating?'#15803d':'#dc2626'};margin-bottom:6px;">
        ${beating ? '🏆 You\'re beating the index!' : '📉 Nifty 50 would have done better'}
      </div>
      <div style="font-size:12px;color:var(--text-muted);">
        Your stocks: <strong>${stockCAGR.toFixed(1)}% CAGR</strong> vs Nifty 50: <strong>${niftyCAGR}% CAGR</strong> over ~${avgYears.toFixed(1)} years
        <br>Alpha: <span style="font-weight:800;color:${alpha>=0?'#16a34a':'#dc2626'};">${alpha>=0?'+':''}${alpha.toFixed(1)}%</span>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:12px;">
      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Your Portfolio</div>
        <div style="font-size:18px;font-weight:800;">${fmtI(totalValue)}</div>
        <div style="font-size:11px;color:${portfolioReturn>=0?'#16a34a':'#dc2626'};">${portfolioReturn>=0?'+':''}${portfolioReturn.toFixed(1)}%</div>
      </div>
      <div style="background:var(--bg-secondary);border-radius:8px;padding:12px;text-align:center;">
        <div style="font-size:10px;font-weight:700;color:var(--text-muted);text-transform:uppercase;margin-bottom:4px;">Nifty 50 Equivalent</div>
        <div style="font-size:18px;font-weight:800;">${fmtI(niftyEquiv)}</div>
        <div style="font-size:11px;color:var(--text-muted);">@${niftyCAGR}% CAGR</div>
      </div>
    </div>
    ${!beating && opportunityCost > 0 ? `
    <div style="font-size:12px;color:#d97706;padding:8px 10px;background:rgba(245,158,11,.08);border-radius:7px;margin-bottom:10px;">
      ⚠️ Opportunity cost: <strong>${fmtI(opportunityCost)}</strong> (what you missed by not investing in Nifty index)
      <br>Estimated brokerage paid: ~${fmtI(brokerageEst)}
    </div>` : ''}
    <div style="font-size:11px;color:var(--text-muted);padding:8px;background:rgba(99,102,241,.05);border-radius:6px;">
      💡 ${beating
        ? 'Great stock picking! But verify if this is consistent across market cycles, not just a bull market effect.'
        : `Consider allocating ${Math.min(50, Math.round(opportunityCost/totalInvested*100))}% of stock budget to Nifty 50 index funds for guaranteed market returns.`}
    </div>`;
}

/* ─────────────────────────────────────────────────────────────────────────
   t146: Behavioral Score
───────────────────────────────────────────────────────────────────────── */
const BEHAV_KEY = 'wd_behavioral_score_v1';

function getBehavioralData() {
  try { return JSON.parse(localStorage.getItem(BEHAV_KEY) || '{}'); } catch(e) { return {}; }
}

function renderBehavioralScore(containerId) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const data = getBehavioralData();
  const panicSells     = data.panicSells     || 0;
  const fomoBuys       = data.fomoBuys       || 0;
  const sipStreak      = data.sipStreak      || 0;
  const avgHoldMonths  = data.avgHoldMonths  || 0;
  const nfoCount       = data.nfoCount       || 0;

  // Score calculation (max 100)
  let score = 100;
  score -= panicSells * 15;    // each panic sell = -15
  score -= fomoBuys   * 10;    // each FOMO buy = -10
  score -= nfoCount   * 8;     // each NFO = -8 (mostly FOMO)
  score += Math.min(20, sipStreak * 2); // SIP streak bonus
  score += Math.min(15, avgHoldMonths * 0.5); // holding time bonus
  score = Math.max(0, Math.min(100, Math.round(score)));

  const grades = score >= 80 ? {g:'A', c:'#15803d', l:'Disciplined Investor'}
               : score >= 60 ? {g:'B', c:'#16a34a', l:'Good Investor'}
               : score >= 40 ? {g:'C', c:'#d97706', l:'Improving'}
               : {g:'D', c:'#dc2626', l:'Needs Discipline'};

  container.innerHTML = `
    <div style="display:flex;align-items:center;gap:16px;margin-bottom:14px;">
      <div style="width:70px;height:70px;border-radius:50%;background:${grades.c}20;border:3px solid ${grades.c};display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;">
        <div style="font-size:24px;font-weight:900;color:${grades.c};">${grades.g}</div>
      </div>
      <div>
        <div style="font-size:15px;font-weight:800;color:${grades.c};">${grades.l}</div>
        <div style="font-size:12px;color:var(--text-muted);">Behavioral Score: ${score}/100</div>
        <div style="font-size:11px;color:var(--text-muted);margin-top:2px;">Anti-panic · Anti-FOMO · Consistency</div>
      </div>
    </div>
    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:12px;font-size:12px;">
      <div style="background:${panicSells>0?'rgba(220,38,38,.07)':'rgba(22,163,74,.07)'};border-radius:7px;padding:8px;">
        <div style="font-weight:700;color:var(--text-muted);font-size:10px;text-transform:uppercase;margin-bottom:2px;">Panic Sells</div>
        <div style="font-size:16px;font-weight:800;color:${panicSells>0?'#dc2626':'#16a34a'};">${panicSells === 0 ? '0 ✅' : panicSells + ' ⚠️'}</div>
      </div>
      <div style="background:${fomoBuys>0?'rgba(245,158,11,.07)':'rgba(22,163,74,.07)'};border-radius:7px;padding:8px;">
        <div style="font-weight:700;color:var(--text-muted);font-size:10px;text-transform:uppercase;margin-bottom:2px;">FOMO Buys</div>
        <div style="font-size:16px;font-weight:800;color:${fomoBuys>0?'#d97706':'#16a34a'};">${fomoBuys === 0 ? '0 ✅' : fomoBuys + ' ⚠️'}</div>
      </div>
      <div style="background:rgba(59,130,246,.07);border-radius:7px;padding:8px;">
        <div style="font-weight:700;color:var(--text-muted);font-size:10px;text-transform:uppercase;margin-bottom:2px;">SIP Streak</div>
        <div style="font-size:16px;font-weight:800;color:#3b82f6;">${sipStreak} months 🔥</div>
      </div>
      <div style="background:rgba(139,92,246,.07);border-radius:7px;padding:8px;">
        <div style="font-weight:700;color:var(--text-muted);font-size:10px;text-transform:uppercase;margin-bottom:2px;">Avg Hold Time</div>
        <div style="font-size:16px;font-weight:800;color:#8b5cf6;">${avgHoldMonths}+ mo</div>
      </div>
    </div>
    <button onclick="openBehavioralSetup('${containerId}')" style="width:100%;padding:7px;border-radius:7px;background:var(--accent);color:#fff;border:none;font-weight:700;font-size:12px;cursor:pointer;">✎ Log Behavior</button>`;
}

function openBehavioralSetup(containerId) {
  const data = getBehavioralData();
  const panic = parseInt(prompt('Panic sells last 1 year (times you sold during market crash):', data.panicSells || 0) || '0');
  const fomo  = parseInt(prompt('FOMO buys last 1 year (bought after big rally/tip):', data.fomoBuys || 0) || '0');
  const nfo   = parseInt(prompt('NFO applications last 1 year:', data.nfoCount || 0) || '0');
  const sip   = parseInt(prompt('Current SIP streak (consecutive months):', data.sipStreak || 0) || '0');
  const hold  = parseInt(prompt('Average fund holding period (months):', data.avgHoldMonths || 0) || '0');

  const updated = { panicSells:panic, fomoBuys:fomo, nfoCount:nfo, sipStreak:sip, avgHoldMonths:hold, updatedAt:new Date().toISOString() };
  try { localStorage.setItem(BEHAV_KEY, JSON.stringify(updated)); } catch(e) {}
  renderBehavioralScore(containerId);
  if (typeof showToast === 'function') showToast('✅ Behavioral score updated!', 'success');
}
