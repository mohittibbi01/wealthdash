/**
 * WealthDash — Portfolio Health Score (tv06)
 * File: public/js/mf_health_score.js
 *
 * Single score 0–100 for MF portfolio health.
 * Factors: Diversification, Overlap, Cost (TER), Return Quality, Risk Balance, Consistency.
 *
 * Integration:
 *   1. Include in mf_holdings.php before </body>
 *   2. Call MF_HEALTH.init(holdings) after MF.renderHoldings
 *   3. Injects a health score card into #mfAnalyticsSection or .mf-holdings-main
 */

const MF_HEALTH = (() => {

  // ── Config ──────────────────────────────────────────
  const WEIGHTS = {
    diversification : 25,   // No fund > 30% of portfolio
    cost            : 20,   // Avg TER < 0.8%
    returnQuality   : 20,   // 3Y return vs Nifty 50 benchmark (12%)
    riskBalance     : 15,   // Equity:Debt appropriate for age
    overlap         : 10,   // Fund category overlap
    consistency     : 10,   // All active SIPs, no orphan holdings
  };

  const NIFTY_3Y_BENCHMARK = 14.0; // % — rough Nifty 50 3Y avg

  // ── Score Engine ────────────────────────────────────
  function compute(holdings) {
    if (!holdings || !holdings.length) return null;

    const total = holdings.reduce((s, h) => s + (h.current_value || 0), 0);
    if (!total) return null;

    // ─ 1. Diversification (25 pts) ──────────────────
    // Penalise if any single fund > 30%
    const maxPct = Math.max(...holdings.map(h => ((h.current_value||0) / total) * 100));
    let diversScore = 25;
    if (maxPct > 50)      diversScore = 5;
    else if (maxPct > 40) diversScore = 12;
    else if (maxPct > 30) diversScore = 18;
    // Also: number of funds (2–8 = optimal)
    const numFunds = holdings.length;
    if (numFunds === 1)       diversScore = Math.min(diversScore, 8);
    else if (numFunds > 15)   diversScore = Math.min(diversScore, 15); // too many
    else if (numFunds >= 4)   diversScore = Math.min(diversScore + 3, 25);

    // ─ 2. Cost / TER (20 pts) ─────────────────────────
    // Weighted avg TER
    let terNumer = 0, terDenom = 0;
    holdings.forEach(h => {
      const ter = parseFloat(h.expense_ratio || h.ter || 0);
      if (ter > 0) { terNumer += ter * (h.current_value||0); terDenom += (h.current_value||0); }
    });
    const avgTer = terDenom > 0 ? terNumer / terDenom : 1.0;
    let costScore = 20;
    if      (avgTer <= 0.3) costScore = 20;
    else if (avgTer <= 0.6) costScore = 17;
    else if (avgTer <= 0.8) costScore = 13;
    else if (avgTer <= 1.2) costScore = 8;
    else                    costScore = 3;

    // Direct plan penalty
    const regularCount = holdings.filter(h => (h.plan_type||'').toLowerCase() === 'regular').length;
    if (regularCount > 0) costScore = Math.max(costScore - (regularCount * 3), 0);

    // ─ 3. Return Quality (20 pts) ────────────────────
    // Holdings with 3Y returns available
    const withReturns = holdings.filter(h => h.returns_3y != null);
    let returnScore = 10; // default if no data
    if (withReturns.length) {
      const avgReturn = withReturns.reduce((s,h) => s + parseFloat(h.returns_3y), 0) / withReturns.length;
      if      (avgReturn >= NIFTY_3Y_BENCHMARK + 3) returnScore = 20;
      else if (avgReturn >= NIFTY_3Y_BENCHMARK)     returnScore = 16;
      else if (avgReturn >= NIFTY_3Y_BENCHMARK - 3) returnScore = 12;
      else if (avgReturn >= NIFTY_3Y_BENCHMARK - 6) returnScore = 7;
      else                                           returnScore = 3;
    }

    // ─ 4. Risk Balance (15 pts) ──────────────────────
    const EQUITY_CATS = ['equity','elss','index','large cap','mid cap','small cap','flexi cap','multi cap','hybrid equity','thematic'];
    let equityVal = 0, debtVal = 0;
    holdings.forEach(h => {
      const cat = (h.category||'').toLowerCase();
      if (EQUITY_CATS.some(c => cat.includes(c))) equityVal += (h.current_value||0);
      else debtVal += (h.current_value||0);
    });
    const equityPct = total > 0 ? (equityVal / total) * 100 : 100;
    // Ideal: 60–80% equity for long-term investors (simplified — no age input)
    let riskScore = 15;
    if      (equityPct >= 60 && equityPct <= 85) riskScore = 15;
    else if (equityPct >= 50 && equityPct < 60)  riskScore = 12;
    else if (equityPct >= 85 && equityPct <= 95) riskScore = 11;
    else if (equityPct < 50)                      riskScore = 7;
    else                                          riskScore = 5; // >95% equity — too aggressive

    // ─ 5. Overlap (10 pts) ──────────────────────────
    // Check duplicate categories (simplified: >3 funds in same category = overlap risk)
    const catCount = {};
    holdings.forEach(h => { const c = (h.category||'Unknown'); catCount[c] = (catCount[c]||0) + 1; });
    const maxCatDupes = Math.max(...Object.values(catCount));
    let overlapScore = 10;
    if (maxCatDupes >= 5)      overlapScore = 2;
    else if (maxCatDupes >= 4) overlapScore = 5;
    else if (maxCatDupes >= 3) overlapScore = 7;

    // ─ 6. Consistency (10 pts) ──────────────────────
    // Holdings with active SIPs vs orphan holdings
    const withSip = holdings.filter(h => (h.active_sip_count||0) > 0).length;
    const sipRatio = numFunds > 0 ? withSip / numFunds : 0;
    let consistScore = Math.round(sipRatio * 10);

    // ─ Total ─────────────────────────────────────────
    const total_score = diversScore + costScore + returnScore + riskScore + overlapScore + consistScore;

    // ─ Issues list ───────────────────────────────────
    const issues = [], positives = [];

    if (maxPct > 30) issues.push({ icon:'⚠️', text: `"${holdings.find(h=>(h.current_value/total*100)===maxPct)?.fund_name||'One fund'}" portfolio ka ${maxPct.toFixed(0)}% hai — diversify karo`, sev:'warn' });
    if (numFunds > 12) issues.push({ icon:'⚠️', text: `${numFunds} funds — bahut zyada overlap hoga. 6–10 funds optimal hain`, sev:'warn' });
    if (numFunds === 1) issues.push({ icon:'🔴', text: `Sirf 1 fund — concentration risk high hai`, sev:'danger' });
    if (avgTer > 1.0) issues.push({ icon:'💸', text: `Average TER ${avgTer.toFixed(2)}% — high cost. Direct plans try karo (TER 0.1–0.5%)`, sev:'warn' });
    if (regularCount > 0) issues.push({ icon:'💸', text: `${regularCount} Regular plan fund${regularCount>1?'s':''} — switch to Direct: save ~0.5–1% per year`, sev:'warn' });
    if (equityPct > 95) issues.push({ icon:'⚠️', text: `${equityPct.toFixed(0)}% equity — koi debt nahi. Emergency fund ke liye liquid fund consider karo`, sev:'warn' });
    if (equityPct < 40) issues.push({ icon:'⚠️', text: `Sirf ${equityPct.toFixed(0)}% equity — long-term growth ke liye equity badhao`, sev:'warn' });
    if (maxCatDupes >= 3) issues.push({ icon:'🔄', text: `Same category mein ${maxCatDupes} funds — high overlap likely. Consolidate karo`, sev:'warn' });

    if (diversScore >= 20) positives.push({ icon:'✅', text: 'Achha diversification — koi fund dominant nahi' });
    if (costScore >= 15)   positives.push({ icon:'✅', text: `Low cost portfolio — avg TER ${avgTer.toFixed(2)}%` });
    if (returnScore >= 16) positives.push({ icon:'✅', text: 'Funds Nifty 50 se better returns de rahe hain' });
    if (riskScore >= 14)   positives.push({ icon:'✅', text: 'Equity-Debt balance appropriate hai' });
    if (withSip > 0)       positives.push({ icon:'✅', text: `${withSip} active SIPs — consistent investing 👍` });

    return {
      total: total_score,
      breakdown: { diversScore, costScore, returnScore, riskScore, overlapScore, consistScore },
      meta: { maxPct, numFunds, avgTer, equityPct, maxCatDupes, withSip, regularCount },
      issues, positives
    };
  }

  // ── Render Card ─────────────────────────────────────
  function _scoreColor(s) {
    if (s >= 80) return { color:'#0d9f57', bg:'#edfbf2', border:'#a3e6c4', label:'Excellent' };
    if (s >= 65) return { color:'#2563eb', bg:'#eff6ff', border:'#93c5fd', label:'Good' };
    if (s >= 50) return { color:'#b45309', bg:'#fffbeb', border:'#fcd34d', label:'Average' };
    return       { color:'#dc2626', bg:'#fff1f2', border:'#fca5a5', label:'Needs Work' };
  }

  function _barWidth(score, max) { return Math.min((score / max) * 100, 100).toFixed(0) + '%'; }

  function renderCard(result) {
    const s = result.total;
    const sc = _scoreColor(s);
    const bd = result.breakdown;
    const mt = result.meta;

    return `
<div id="mfHealthScoreCard" style="
  background:var(--surface);border:1.5px solid ${sc.border};border-radius:14px;
  box-shadow:0 4px 16px rgba(0,0,0,.07);padding:18px 20px;margin:12px 0;
  animation:hs-in .3s ease;
">
<style>
@keyframes hs-in { from{opacity:0;transform:translateY(-6px)} to{opacity:1;transform:translateY(0)} }
.hs-grid  { display:grid;grid-template-columns:auto 1fr;gap:20px;align-items:start;margin-bottom:14px }
.hs-gauge { text-align:center;min-width:100px }
.hs-arc   { width:90px;height:90px;border-radius:50%;margin:0 auto 6px;
             background:conic-gradient(${sc.color} ${(s/100*360).toFixed(0)}deg, #e2e6f0 0deg);
             display:flex;align-items:center;justify-content:center;position:relative }
.hs-inner { width:68px;height:68px;border-radius:50%;background:#fff;
             display:flex;flex-direction:column;align-items:center;justify-content:center }
.hs-num   { font-size:22px;font-weight:800;line-height:1;color:${sc.color} }
.hs-den   { font-size:9px;color:var(--muted);font-weight:600 }
.hs-label { font-size:11px;font-weight:800;color:${sc.color} }
.hs-right {}
.hs-title { font-size:14px;font-weight:800;margin-bottom:10px;display:flex;align-items:center;gap:8px }
.hs-factors { display:flex;flex-direction:column;gap:5px }
.hf-row   { display:flex;align-items:center;gap:8px;font-size:11px }
.hf-lbl   { width:130px;color:var(--muted);flex-shrink:0 }
.hf-bar-bg{ flex:1;background:var(--border);border-radius:99px;height:5px;overflow:hidden }
.hf-bar   { height:100%;border-radius:99px;background:${sc.color};transition:width .6s .1s }
.hf-score { width:40px;text-align:right;font-weight:700;font-size:10px;color:${sc.color};flex-shrink:0 }
.hf-max   { font-size:9px;color:var(--muted2);width:28px;flex-shrink:0 }
.hs-issues{ margin-top:12px;display:flex;flex-direction:column;gap:4px }
.hs-issue { display:flex;gap:7px;align-items:flex-start;font-size:10px;padding:5px 8px;border-radius:6px;background:var(--red-bg);border:1px solid var(--red-border);color:var(--red) }
.hs-issue.warn{ background:var(--yellow-bg);border-color:var(--yellow-border);color:var(--yellow) }
.hs-pos   { display:flex;gap:7px;align-items:flex-start;font-size:10px;padding:5px 8px;border-radius:6px;background:var(--green-bg);border:1px solid var(--green-border);color:var(--green) }
.hs-toggle{ font-size:10px;color:var(--accent);cursor:pointer;margin-top:6px;text-decoration:underline }
.hs-meta  { display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;padding-top:10px;border-top:1px solid var(--border) }
.hs-meta-item{ font-size:10px;color:var(--muted) }
.hs-meta-item strong{ color:var(--text) }
@media(max-width:500px){ .hs-grid{ grid-template-columns:1fr } .hs-gauge{ display:flex;gap:12px;align-items:center;text-align:left } }
</style>

<div class="hs-grid">
  <div class="hs-gauge">
    <div class="hs-arc"><div class="hs-inner"><div class="hs-num">${s}</div><div class="hs-den">/100</div></div></div>
    <div class="hs-label">${sc.label}</div>
  </div>
  <div class="hs-right">
    <div class="hs-title">
      🩺 Portfolio Health Score
      <span style="font-size:10px;padding:2px 8px;border-radius:99px;background:${sc.bg};color:${sc.color};border:1px solid ${sc.border};font-weight:700">${sc.label}</span>
    </div>
    <div class="hs-factors">
      ${[
        ['Diversification', bd.diversScore, WEIGHTS.diversification],
        ['Cost (TER)',       bd.costScore,   WEIGHTS.cost],
        ['Return Quality',  bd.returnScore, WEIGHTS.returnQuality],
        ['Risk Balance',    bd.riskScore,   WEIGHTS.riskBalance],
        ['Low Overlap',     bd.overlapScore,WEIGHTS.overlap],
        ['SIP Consistency', bd.consistScore,WEIGHTS.consistency],
      ].map(([lbl,sc2,max]) => `
        <div class="hf-row">
          <span class="hf-lbl">${lbl}</span>
          <div class="hf-bar-bg"><div class="hf-bar" style="width:${_barWidth(sc2,max)}"></div></div>
          <span class="hf-score">${sc2}</span>
          <span class="hf-max">/${max}</span>
        </div>`).join('')}
    </div>
  </div>
</div>

${result.issues.length || result.positives.length ? `
<div>
  <div id="hsIssues" style="display:block">
    ${result.positives.map(p => `<div class="hs-pos">${p.icon} ${p.text}</div>`).join('')}
    ${result.issues.map(i => `<div class="hs-issue ${i.sev==='warn'?'warn':''}">${i.icon} ${i.text}</div>`).join('')}
  </div>
  <div class="hs-toggle" onclick="this.previousElementSibling.style.display=this.previousElementSibling.style.display==='none'?'block':'none';this.textContent=this.textContent.includes('Show')?'Hide details':'Show details'">Hide details</div>
</div>` : ''}

<div class="hs-meta">
  <div class="hs-meta-item"><strong>${mt.numFunds}</strong> funds</div>
  <div class="hs-meta-item">Avg TER <strong>${mt.avgTer.toFixed(2)}%</strong></div>
  <div class="hs-meta-item">Equity <strong>${mt.equityPct.toFixed(0)}%</strong></div>
  <div class="hs-meta-item">Max concentration <strong>${mt.maxPct.toFixed(0)}%</strong></div>
  <div class="hs-meta-item"><strong>${mt.withSip}</strong> active SIPs</div>
  ${mt.regularCount ? `<div class="hs-meta-item" style="color:var(--red)"><strong>${mt.regularCount}</strong> Regular plans ⚠️</div>` : ''}
</div>
</div>`;
  }

  // ── Public API ───────────────────────────────────────
  function init(holdings) {
    if (!holdings || !holdings.length) return;
    const result = compute(holdings);
    if (!result) return;

    // Remove old card
    const old = document.getElementById('mfHealthScoreCard');
    if (old) old.remove();

    // Find injection point — before overlap analyzer or after stat strip
    const anchor =
      document.querySelector('#mfAnalyticsSection') ||
      document.querySelector('.mf-analytics-section') ||
      document.querySelector('.mf-holdings-content') ||
      document.querySelector('#holdingsTableWrap');

    if (!anchor) return;

    const div = document.createElement('div');
    div.innerHTML = renderCard(result);
    anchor.insertBefore(div.firstElementChild, anchor.firstChild);
  }

  function refresh() {
    const holdings = window.MF && MF.holdings ? MF.holdings : [];
    init(holdings);
  }

  return { init, refresh, compute };
})();

// ── Auto-init ─────────────────────────────────────────
(function() {
  const _orig = window.MF && MF.renderHoldings;
  if (_orig) {
    const _patched = MF.renderHoldings;
    MF.renderHoldings = function() {
      _patched.apply(this, arguments);
      setTimeout(() => {
        MF_HEALTH.init(MF.holdings || []);
      }, 150);
    };
  } else {
    document.addEventListener('DOMContentLoaded', () => {
      setTimeout(() => MF_HEALTH.refresh(), 600);
    });
  }
})();
