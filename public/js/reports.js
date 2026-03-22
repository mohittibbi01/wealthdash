/**
 * WealthDash — reports.js
 * Handles: FY Gains Report, Tax Planning, Net Worth, Rebalancing
 * Loaded only on report pages
 */
'use strict';

/* ─── Polyfills: window.apiPost / window.showToast ─────────────────────── */
if (typeof window.apiPost !== 'function') {
    window.apiPost = async function(payload) {
        // Auto-detect base from current URL — e.g. localhost/wealthdash
        const pathParts = window.location.pathname.split('/');
        const appSegment = pathParts[1] ? '/' + pathParts[1] : '';
        const base = window.WD?.appUrl || window.APP_URL || appSegment;
        const fd   = new FormData();
        Object.entries(payload).forEach(([k, v]) => { if (v !== undefined && v !== null) fd.append(k, v); });
        const csrf = window.WD?.csrf || window.CSRF_TOKEN || document.querySelector('meta[name="csrf-token"]')?.content || '';
        if (csrf) fd.append('_csrf_token', csrf);
        const res  = await fetch(`${base}/api/?action=${payload.action}`, { method: 'POST', body: fd });
        if (!res.ok) throw new Error(`HTTP ${res.status}`);
        return await res.json();
    };
}

if (typeof window.showToast !== 'function') {
    window.showToast = function(msg, type = 'success') {
        // Try app's existing toast if available
        if (typeof showToast === 'function' && showToast !== window.showToast) { showToast(msg, type); return; }
        const el = document.createElement('div');
        el.textContent = msg;
        el.style.cssText = `position:fixed;bottom:24px;right:24px;z-index:9999;padding:12px 20px;border-radius:8px;
            font-size:14px;color:#fff;background:${type === 'error' ? '#ef4444' : '#22c55e'};
            box-shadow:0 4px 12px rgba(0,0,0,.2);transition:opacity .3s;`;
        document.body.appendChild(el);
        setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 300); }, 3000);
    };
}

/* ─── Shared helpers ───────────────────────────────────────────────────────── */
function inrFmt(n) {
    return '₹' + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}
function pctFmt(n) {
    const val = Number(n || 0);
    return (val >= 0 ? '+' : '') + val.toFixed(2) + '%';
}
function numFmt(n, dec = 2) {
    return Number(n || 0).toFixed(dec);
}
function truncate(s, len) {
    return s && s.length > len ? s.substring(0, len) + '…' : (s || '');
}
function gainCls(n) {
    return Number(n) >= 0 ? 'text-success' : 'text-danger';
}
function gainBadge(type) {
    const map = { LTCG: 'badge-success', STCG: 'badge-warning', SLAB: 'badge-info', MIXED: 'badge-secondary', NA: 'badge-secondary' };
    return `<span class="badge ${map[type] || 'badge-secondary'}">${type}</span>`;
}
function currentFyStr() {
    const m = new Date().getMonth() + 1;
    const y = new Date().getFullYear();
    return m >= 4 ? `${y}-${String(y + 1).slice(2)}` : `${(y - 1)}-${String(y).slice(2)}`;
}

/* ─── Determine which page we're on ─────────────────────────────────────── */
const pageId = document.body.dataset.page || '';

/* ══════════════════════════════════════════════════════════════════════════
   FY GAINS REPORT
══════════════════════════════════════════════════════════════════════════ */
if (document.getElementById('fySummaryBody')) {
    let reportData = null;
    const portId = () =>
        window.WD?.selectedPortfolio ||
        document.getElementById('portfolioSelect')?.value ||
        document.querySelector('[data-portfolio-id]')?.dataset.portfolioId ||
        '';

    async function loadFyReport(fy = '') {
        document.getElementById('fySummaryBody').innerHTML =
            `<tr><td colspan="10" class="text-center"><span class="spinner"></span></td></tr>`;
        const pid = portId();
        if (!pid) {
            document.getElementById('fySummaryBody').innerHTML =
                `<tr><td colspan="10" class="text-center text-secondary">Please select a portfolio to view FY Gains.</td></tr>`;
            return;
        }
        // t132 + t133: Read controls
        const lotMethod    = document.getElementById('lotMethodSelect')?.value || 'FIFO';
        const applyGf      = document.getElementById('applyGrandfathering')?.checked ? 1 : 0;

        try {
            const res = await window.apiPost({
                action: 'report_fy_gains', portfolio_id: pid, fy,
                lot_method: lotMethod,
                apply_grandfathering: applyGf,
            });
            if (!res.success) { window.showToast(res.message, 'error'); return; }
            reportData = res.data;
            renderFyFilters(reportData.fy_list);
            renderFySummaryCards(reportData.fy_summary);
            renderFySummaryTable(reportData.fy_summary);
            renderLtcgBar(reportData.fy_summary);
            renderMfGains(reportData.mf_gains_detail);
            renderStockGains(reportData.stock_gains_detail);
            renderMfDivs(reportData.mf_dividends);
            renderStDivs(reportData.stock_dividends);
            renderLossCF(reportData.loss_carry_forward || []);       // t135
            renderGrandfathering(reportData.total_gf_tax_savings || 0, lotMethod); // t132
        } catch (e) {
            console.error(e);
            window.showToast('Failed to load FY Gains report', 'error');
        }
    }

    function renderFyFilters(fyList) {
        const sel = document.getElementById('fyFilter');
        if (!sel) return;
        const cur = sel.value;
        sel.innerHTML = '<option value="">All Financial Years</option>';
        (fyList || []).forEach(fy => {
            sel.innerHTML += `<option value="${fy}" ${fy === cur ? 'selected' : ''}>${fy}</option>`;
        });
    }

    function renderFySummaryCards(summary) {
        const el = document.getElementById('fySummaryCards');
        if (!el) return;
        const totals = (summary || []).reduce((acc, r) => ({
            ltcg_equity: acc.ltcg_equity + r.ltcg_equity,
            stcg_equity: acc.stcg_equity + r.stcg_equity,
            total_gains: acc.total_gains + r.total_gains,
            total_dividends: acc.total_dividends + (r.total_dividends || 0),
        }), { ltcg_equity: 0, stcg_equity: 0, total_gains: 0, total_dividends: 0 });

        el.innerHTML = `
        <div class="stat-card">
            <div class="stat-label">Total LTCG (Equity)</div>
            <div class="stat-value ${gainCls(totals.ltcg_equity)}">${inrFmt(totals.ltcg_equity)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total STCG (Equity)</div>
            <div class="stat-value ${gainCls(totals.stcg_equity)}">${inrFmt(totals.stcg_equity)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Dividends</div>
            <div class="stat-value text-primary">${inrFmt(totals.total_dividends)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Gains (All)</div>
            <div class="stat-value ${gainCls(totals.total_gains)}">${inrFmt(totals.total_gains)}</div>
        </div>`;
    }

    function renderFySummaryTable(summary) {
        const body = document.getElementById('fySummaryBody');
        if (!summary || !summary.length) {
            body.innerHTML = `<tr><td colspan="10" class="text-center text-secondary">No transactions found</td></tr>`;
            return;
        }
        body.innerHTML = summary.map(r => `
        <tr>
            <td><strong>${r.fy}</strong></td>
            <td class="text-right ${gainCls(r.ltcg_equity)}">${inrFmt(r.ltcg_equity)}</td>
            <td class="text-right ${gainCls(r.ltcg_debt)}">${inrFmt(r.ltcg_debt)}</td>
            <td class="text-right ${gainCls(r.stcg_equity)}">${inrFmt(r.stcg_equity)}</td>
            <td class="text-right ${gainCls(r.stcg_debt)}">${inrFmt(r.stcg_debt)}</td>
            <td class="text-right">${inrFmt(r.slab_gains)}</td>
            <td class="text-right text-primary">${inrFmt(r.mf_dividends)}</td>
            <td class="text-right text-primary">${inrFmt(r.stock_dividends)}</td>
            <td class="text-right fw-600 ${gainCls(r.total_gains)}">${inrFmt(r.total_gains)}</td>
            <td class="text-right text-warning">${inrFmt(r.total_tax_approx)}</td>
        </tr>`).join('');
    }

    function renderLtcgBar(summary) {
        const card = document.getElementById('ltcgExemptionCard');
        if (!card) return;
        const fyNow = currentFyStr();
        const fyData = (summary || []).find(r => r.fy === fyNow);
        if (!fyData || !reportData) { card.style.display = 'none'; return; }
        card.style.display = '';
        const limit = reportData.ltcg_exemption || 125000;
        const used = fyData.ltcg_equity || 0;
        const pct = Math.min(100, (used / limit) * 100).toFixed(1);
        document.getElementById('ltcgExemptionFy').textContent = fyNow;
        document.getElementById('ltcgUsed').textContent = inrFmt(used);
        document.getElementById('ltcgLimit').textContent = inrFmt(limit);
        document.getElementById('ltcgRemaining').textContent = inrFmt(Math.max(0, limit - used));
        const bar = document.getElementById('ltcgProgressBar');
        bar.style.width = pct + '%';
        bar.className = 'progress-bar ' + (pct > 90 ? 'progress-danger' : pct > 60 ? 'progress-warning' : 'progress-success');
    }

    // ── Shared pagination helper for FY report tables ─────────────────────
    const fyPagination = {
        state: {
            mfGains:  { data:[], page:1, perPage:10 },
            stGains:  { data:[], page:1, perPage:10 },
            mfDiv:    { data:[], page:1, perPage:10 },
            stDiv:    { data:[], page:1, perPage:10 },
        },
        render(key) {
            const s       = this.state[key];
            const total   = s.data.length;
            const pages   = Math.max(1, Math.ceil(total / s.perPage));
            if (s.page > pages) s.page = 1;
            const start   = (s.page - 1) * s.perPage;
            const paged   = s.perPage >= 9999 ? s.data : s.data.slice(start, start + s.perPage);
            const bodyId  = { mfGains:'mfGainsBody', stGains:'stockGainsBody', mfDiv:'mfDivBody', stDiv:'stDivBody' }[key];
            const infoId  = key + 'PagInfo';
            const pagId   = key + 'Pag';
            const colspans= { mfGains:12, stGains:11, mfDiv:5, stDiv:5 };
            const emptyMsg= { mfGains:'No MF sell transactions', stGains:'No stock sell transactions', mfDiv:'No MF dividends', stDiv:'No stock dividends' };
            const renderFn= { mfGains: this._rowMfGain, stGains: this._rowStGain, mfDiv: this._rowMfDiv, stDiv: this._rowStDiv };

            const body = document.getElementById(bodyId);
            if (!body) return;
            if (!total) { body.innerHTML = `<tr><td colspan="${colspans[key]}" class="text-center text-secondary">${emptyMsg[key]}</td></tr>`; }
            else        { body.innerHTML = paged.map(renderFn[key]).join(''); }

            // Info
            const infoEl = document.getElementById(infoId);
            if (infoEl) infoEl.textContent = total > 0 ? `${Math.min(start+1,total)}–${Math.min(start+s.perPage,total)} of ${total}` : '';

            // Pagination buttons
            const pagEl = document.getElementById(pagId);
            if (!pagEl) return;
            if (pages <= 1) { pagEl.innerHTML = ''; return; }
            let html = '';
            if (s.page > 1) html += `<button class="btn btn-ghost btn-sm" onclick="fyPagination.go('${key}',${s.page-1})">‹</button>`;
            const ps = Math.max(1, s.page-2), pe = Math.min(pages, s.page+2);
            for (let p = ps; p <= pe; p++) html += `<button class="btn btn-ghost btn-sm ${p===s.page?'active':''}" onclick="fyPagination.go('${key}',${p})">${p}</button>`;
            if (s.page < pages) html += `<button class="btn btn-ghost btn-sm" onclick="fyPagination.go('${key}',${s.page+1})">›</button>`;
            pagEl.innerHTML = html;
        },
        go(key, page)    { this.state[key].page = page; this.render(key); },
        changePerPage(key, val) { this.state[key].perPage = parseInt(val); this.state[key].page = 1; this.render(key); },
        set(key, data)   { this.state[key].data = data || []; this.state[key].page = 1; this.render(key); },

        _rowMfGain: g => `<tr>
            <td>${g.fy}</td>
            <td class="text-nowrap" title="${g.name}">${truncate(g.name,35)}</td>
            <td><small>${g.category}</small></td>
            <td><small>${g.folio||'-'}</small></td>
            <td class="text-right">${numFmt(g.units,4)}</td>
            <td class="text-right">${inrFmt(g.sell_nav)}</td>
            <td class="text-right">${inrFmt(g.proceeds)}</td>
            <td class="text-right">${inrFmt(g.cost)}</td>
            <td class="text-right fw-600 ${gainCls(g.gain)}">${inrFmt(g.gain)}</td>
            <td>${g.days_held}d</td>
            <td>${gainBadge(g.gain_type)}</td>
            <td class="text-right text-warning">${g.tax_amount!=null?inrFmt(g.tax_amount):g.tax_rate!=null?g.tax_rate+'%':'Slab'}</td>
        </tr>`,

        _rowStGain: g => `<tr>
            <td>${g.fy}</td>
            <td><strong>${g.symbol}</strong></td>
            <td>${truncate(g.name,25)}</td>
            <td class="text-right">${g.quantity}</td>
            <td class="text-right">${inrFmt(g.sell_price)}</td>
            <td class="text-right">${inrFmt(g.proceeds)}</td>
            <td class="text-right">${inrFmt(g.cost)}</td>
            <td class="text-right fw-600 ${gainCls(g.gain)}">${inrFmt(g.gain)}</td>
            <td>${g.days_held}d</td>
            <td>${gainBadge(g.gain_type)}</td>
            <td class="text-right text-warning">${g.tax_amount!=null?inrFmt(g.tax_amount):'-'}</td>
        </tr>`,

        _rowMfDiv: d => `<tr><td>${d.fy}</td><td>${truncate(d.name,40)}</td><td>${d.fund_house}</td>
            <td>${d.date}</td><td class="text-right text-primary fw-600">${inrFmt(d.amount)}</td></tr>`,

        _rowStDiv: d => `<tr><td>${d.fy}</td><td><strong>${d.symbol}</strong></td><td>${d.name}</td>
            <td>${d.date}</td><td class="text-right text-primary fw-600">${inrFmt(d.amount)}</td></tr>`,
    };
    window.fyPagination = fyPagination; // expose globally for onchange handlers

    function renderMfGains(gains)   { fyPagination.set('mfGains', gains); }
    function renderStockGains(gains){ fyPagination.set('stGains', gains); }
    function renderMfDivs(divs)     { fyPagination.set('mfDiv',   divs);  }
    function renderStDivs(divs)     { fyPagination.set('stDiv',   divs);  }

    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab).style.display = '';
        });
    });

    const fyFilterEl = document.getElementById('fyFilter');
    if (fyFilterEl) {
        fyFilterEl.addEventListener('change', () => loadFyReport(fyFilterEl.value));
    }

    // t133: Lot method change → reload
    const lotSel = document.getElementById('lotMethodSelect');
    if (lotSel) lotSel.addEventListener('change', () => loadFyReport(fyFilterEl?.value || ''));

    // t132: Grandfathering toggle → reload
    const gfChk = document.getElementById('applyGrandfathering');
    if (gfChk) gfChk.addEventListener('change', () => loadFyReport(fyFilterEl?.value || ''));

    const exportTaxBtn = document.getElementById('exportTaxBtn');
    if (exportTaxBtn) {
        exportTaxBtn.addEventListener('click', () => {
            const fyVal = fyFilterEl?.value || currentFyStr();
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = window.WD?.apiUrl || '/wealthdash/api/reports/export_csv.php';
            f.innerHTML = `<input name="action" value="export_csv"><input name="export_type" value="tax_report">
                <input name="portfolio_id" value="${portId()}"><input name="fy" value="${fyVal}">
                <input name="_csrf" value="${window.WD?.csrf || ''}">`;
            document.body.appendChild(f); f.submit(); document.body.removeChild(f);
        });
    }

    // t135: Render Loss Carry Forward table
    function renderLossCF(losses) {
        const card = document.getElementById('lossCFCard');
        const body = document.getElementById('lossCFBody');
        if (!card || !body) return;
        if (!losses.length) { card.style.display = 'none'; return; }
        card.style.display = '';
        const curFy = currentFyStr();
        body.innerHTML = losses.map(l => {
            const isExpiringSoon = l.expiry_fy === curFy;
            const expColor = isExpiringSoon ? '#dc2626' : 'var(--text-muted)';
            const badge = isExpiringSoon
                ? `<span class="badge badge-danger">⚠️ Expiring this FY!</span>`
                : `<span class="badge badge-info">Active</span>`;
            const taxSaving = l.type === 'LTCG'
                ? inrFmt(l.loss_amount * 0.125)
                : inrFmt(l.loss_amount * 0.20);
            return `<tr>
                <td><strong>${l.fy}</strong></td>
                <td><span class="badge ${l.type==='LTCG'?'badge-warning':'badge-info'}">${l.type}</span></td>
                <td class="text-right fw-600 text-danger">-${inrFmt(l.loss_amount)}</td>
                <td style="font-size:12px;">${l.can_set_off}</td>
                <td style="color:${expColor};font-weight:600;">${l.expiry_fy}</td>
                <td>${badge}</td>
            </tr>`;
        }).join('');
    }

    // t132: Render Grandfathering savings
    function renderGrandfathering(savings, lotMethod) {
        const card = document.getElementById('gfCard');
        const badge = document.getElementById('gfBadge');
        const savEl = document.getElementById('gfTaxSavings');
        if (!card) return;
        const taxSaved = savings * 0.125; // 12.5% LTCG rate
        card.style.display = savings > 0 ? '' : 'none';
        if (badge) badge.textContent = `${lotMethod} method`;
        if (savEl) savEl.textContent = inrFmt(taxSaved);
    }

    // Always attempt load — portId fallback handles missing WD object
    loadFyReport();
    window.addEventListener('portfolioChanged', () => loadFyReport(fyFilterEl?.value || ''));
}

/* ══════════════════════════════════════════════════════════════════════════
   TAX PLANNING
══════════════════════════════════════════════════════════════════════════ */
if (document.getElementById('harvestBody')) {
    const portId = () => window.WD?.selectedPortfolio;

    async function loadTaxPlan(fy = '') {
        document.getElementById('harvestBody').innerHTML =
            `<tr><td colspan="11" class="text-center"><span class="spinner"></span></td></tr>`;
        try {
            const res = await window.apiPost({ action: 'report_tax_planning', portfolio_id: portId(), fy });
            if (!res.success) { window.showToast(res.message, 'error'); return; }
            const d = res.data;
            renderTaxSummaryCards(d);
            renderHarvestTable(d);
            renderWaitLtcg(d);
            renderFdSavings(d);
        } catch (e) {
            console.error(e);
            window.showToast('Failed to load tax planning', 'error');
        }
    }

    function renderTaxSummaryCards(d) {
        const el = document.getElementById('taxSummaryCards');
        if (!el) return;
        const pct = d.ltcg_exemption_limit > 0 ? Math.min(100, (d.ltcg_realised / d.ltcg_exemption_limit) * 100).toFixed(0) : 0;
        el.innerHTML = `
        <div class="stat-card">
            <div class="stat-label">LTCG Realised (FY ${d.fy})</div>
            <div class="stat-value text-danger">${inrFmt(d.ltcg_realised)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Exemption Used</div>
            <div class="stat-value">${inrFmt(d.ltcg_exemption_used)} <small class="text-secondary">(${pct}%)</small></div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Exemption Remaining</div>
            <div class="stat-value text-success">${inrFmt(d.ltcg_exemption_remaining)}</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Harvest Opportunities</div>
            <div class="stat-value text-primary">${d.harvest_suggestions?.length || 0} <small>funds/stocks</small></div>
        </div>`;

        // Update LTCG progress bar
        const fyBadge = document.getElementById('taxFyBadge');
        if (fyBadge) fyBadge.textContent = d.fy;
        const bar = document.getElementById('taxProgressBar');
        if (bar) {
            bar.style.width = pct + '%';
            bar.className = 'progress-bar ' + (pct > 90 ? 'progress-danger' : pct > 60 ? 'progress-warning' : 'progress-success');
        }
        const msgEl = document.getElementById('ltcgHarvestMsg');
        if (msgEl) {
            if (d.ltcg_exemption_remaining > 0) {
                msgEl.textContent = `You can book ${inrFmt(d.ltcg_exemption_remaining)} more in LTCG gains tax-free this FY.`;
            } else {
                msgEl.textContent = '⚠️ LTCG exemption exhausted. Further LTCG gains will be taxed @ 12.5%.';
                msgEl.className = 'text-sm text-warning mt-2';
            }
        }
        ['ltcgRealised','ltcgExUsed','ltcgExRemaining'].forEach(id => {
            const map = { ltcgRealised: d.ltcg_realised, ltcgExUsed: d.ltcg_exemption_used, ltcgExRemaining: d.ltcg_exemption_remaining };
            const el2 = document.getElementById(id);
            if (el2) el2.textContent = inrFmt(map[id]);
        });

        // t82: 80C Dashboard
        render80C(d);

        // t83: March deadline countdown
        renderDeadline(d);
    }

    // t82: 80C Dashboard renderer
    function render80C(d) {
        const c80  = d['80c_total']     || 0;
        const lim  = d['80c_limit']     || 150000;
        const rem  = d['80c_remaining'] || lim;
        const nps1b= d['80ccd1b_nps']   || 0;
        const lim1b= d['80ccd1b_limit'] || 50000;
        const pct  = Math.min(100, (c80 / lim) * 100).toFixed(1);
        const pct1b= Math.min(100, (nps1b / lim1b) * 100).toFixed(1);

        const fmt = v => inrFmt(v);
        const setT = (id, val) => { const e = document.getElementById(id); if (e) e.textContent = val; };
        const setW = (id, w, color) => { const e = document.getElementById(id); if (e) { e.style.width = w + '%'; if (color) e.style.background = color; } };

        setT('amt80C',     fmt(c80));
        setT('rem80C',     fmt(rem));
        setT('amt80cElss', fmt(d['80c_elss'] || 0));
        setT('amt80cPpf',  fmt(d['80c_ppf']  || 0));
        setT('amt80cNps',  fmt(d['80c_nps']  || 0));
        setT('amt80ccd1b', fmt(nps1b));

        const barColor = pct >= 100 ? '#16a34a' : pct >= 75 ? '#d97706' : '#3b82f6';
        setW('bar80C', pct, barColor);
        setW('bar80ccd1b', pct1b);

        const msgEl = document.getElementById('msg80C');
        if (msgEl) {
            if (rem <= 0) {
                msgEl.textContent = '✅ 80C limit fully utilised! Maximum tax saving achieved.';
                msgEl.style.cssText = 'font-size:12px;margin-top:10px;padding:8px 12px;border-radius:6px;background:rgba(22,163,74,.1);color:#16a34a;';
            } else {
                msgEl.textContent = `💡 Invest ${fmt(rem)} more in ELSS/PPF/NPS to maximise 80C deduction.`;
                msgEl.style.cssText = 'font-size:12px;margin-top:10px;padding:8px 12px;border-radius:6px;background:rgba(59,130,246,.08);color:var(--text-primary);';
            }
        }
        const msg1b = document.getElementById('msg80ccd1b');
        if (msg1b) {
            msg1b.textContent = nps1b > 0
                ? `+${fmt(Math.min(lim1b - nps1b, lim1b))} more NPS contribution can save extra tax under 80CCD(1B).`
                : 'Invest up to ₹50,000 in NPS Tier 1 for additional deduction under 80CCD(1B).';
        }
    }

    // t83: FY deadline countdown
    function renderDeadline(d) {
        const el = document.getElementById('deadlineCountdown');
        if (!el) return;
        const days = d.days_to_fy_end || 0;
        if (days <= 0) {
            el.textContent = '🔒 FY Closed';
            el.style.background = 'rgba(107,114,128,.1)';
            el.style.color = 'var(--text-muted)';
        } else if (days <= 7) {
            el.textContent = `🚨 ${days} days left!`;
            el.style.background = 'rgba(220,38,38,.12)';
            el.style.color = '#dc2626';
        } else if (days <= 30) {
            el.textContent = `⚠️ ${days} days to Mar 31`;
            el.style.background = 'rgba(245,158,11,.12)';
            el.style.color = '#d97706';
        } else {
            el.textContent = `📅 ${days} days to Mar 31`;
            el.style.background = 'rgba(59,130,246,.08)';
            el.style.color = 'var(--accent)';
        }
    }

    function renderHarvestTable(d) {
        const body = document.getElementById('harvestBody');
        const suggestions = d.harvest_suggestions || [];
        if (!suggestions.length) {
            body.innerHTML = `<tr><td colspan="11" class="text-center text-secondary">
                ${d.ltcg_exemption_remaining <= 0 ? '⚠️ LTCG exemption already exhausted for this FY.' : 'No LTCG-eligible holdings with gains found.'}
            </td></tr>`;
            return;
        }
        body.innerHTML = suggestions.map(s => `
        <tr>
            <td><span class="badge ${s.type === 'MF' ? 'badge-primary' : 'badge-success'}">${s.type}</span></td>
            <td title="${s.name}">${truncate(s.name, 35)}</td>
            <td><small>${s.category || s.exchange || ''}</small></td>
            <td class="text-right">${s.type === 'MF' ? numFmt(s.total_units, 4) : s.quantity}</td>
            <td class="text-right">${inrFmt(s.type === 'MF' ? s.latest_nav : s.latest_price)}</td>
            <td class="text-right text-success fw-600">${inrFmt(s.total_gain)}</td>
            <td>${s.ltcg_date} <small class="text-secondary">(${s.days_since_ltcg}d ago)</small></td>
            <td class="text-right text-primary fw-600">${numFmt(s.units_to_sell, s.type === 'MF' ? 4 : 0)}</td>
            <td class="text-right text-success">${inrFmt(s.gain_if_sell)}</td>
            <td class="text-right">${inrFmt(s.value_if_sell)}</td>
            <td class="text-right">${numFmt(s.cagr)}%</td>
        </tr>`).join('');
    }

    function renderWaitLtcg(d) {
        const body = document.getElementById('waitLtcgBody');
        if (!body) return;
        const items = d.wait_for_ltcg || [];
        if (!items.length) { body.innerHTML = `<tr><td colspan="7" class="text-center text-secondary">No upcoming LTCG conversions in 90 days</td></tr>`; return; }
        body.innerHTML = items.map(item => `
        <tr>
            <td>${item.type}</td><td>${truncate(item.name, 35)}</td><td>${item.category}</td>
            <td class="text-right text-success">${inrFmt(item.gain)}</td>
            <td>${item.ltcg_date}</td>
            <td><span class="badge badge-warning">${item.days_to_ltcg} days</span></td>
            <td class="text-sm">${item.message}</td>
        </tr>`).join('');
    }

    function renderFdSavings(d) {
        const fdInt = document.getElementById('fdInterest');
        const fdTds = document.getElementById('fdTds');
        const savInt = document.getElementById('savingsInterest');
        const tta = document.getElementById('tta80Benefit');
        if (fdInt) fdInt.textContent = inrFmt(d.fd_interest_fy);
        if (fdTds) fdTds.textContent = inrFmt(d.fd_tds_fy);
        if (savInt) savInt.textContent = inrFmt(d.savings_interest_fy);
        if (tta) tta.textContent = inrFmt(d.savings_80tta_benefit);
    }

    const loadBtn = document.getElementById('loadTaxPlanBtn');
    if (loadBtn) loadBtn.addEventListener('click', () => loadTaxPlan());

    if (portId()) loadTaxPlan();
    window.addEventListener('portfolioChanged', () => loadTaxPlan());
}

/* ══════════════════════════════════════════════════════════════════════════
   NET WORTH
══════════════════════════════════════════════════════════════════════════ */
if (document.getElementById('totalNetWorth')) {
    let nwCharts = {};
    const portId = () => window.WD?.selectedPortfolio;

    async function loadNetWorth() {
        document.getElementById('totalNetWorth').textContent = 'Loading...';
        document.getElementById('assetCards').innerHTML = '';
        try {
            const res = await window.apiPost({ action: 'report_net_worth', portfolio_id: portId() });
            if (!res.success) { window.showToast(res.message, 'error'); return; }
            const d = res.data;
            renderNwHero(d.summary);
            renderAssetCards(d.by_asset);
            renderNwCharts(d);
            renderNwDetails(d.by_asset);
        } catch (e) {
            console.error(e);
            window.showToast('Failed to load Net Worth', 'error');
        }
    }

    function renderNwHero(s) {
        document.getElementById('totalNetWorth').textContent = inrFmt(s.total_current_value);
        document.getElementById('totalInvested').textContent = inrFmt(s.total_invested);
        const glEl = document.getElementById('totalGainLoss');
        glEl.textContent = inrFmt(s.total_gain_loss);
        glEl.className = s.total_gain_loss >= 0 ? 'text-success' : 'text-danger';
        const pctEl = document.getElementById('totalGainPct');
        pctEl.textContent = pctFmt(s.total_gain_pct);
        pctEl.className = s.total_gain_loss >= 0 ? 'badge badge-success' : 'badge badge-danger';
    }

    function renderAssetCards(by) {
        const el = document.getElementById('assetCards');
        const assets = [
            { label: 'Mutual Funds', data: by.mutual_funds, icon: '📈', color: '#2563EB' },
            { label: 'Stocks',       data: by.stocks,       icon: '📊', color: '#059669' },
            { label: 'NPS',          data: by.nps,          icon: '🏛️',  color: '#7C3AED' },
            { label: 'Fixed Deposits', data: { invested: by.fd.invested, current_value: by.fd.current_value, gain_loss: by.fd.accrued_interest, gain_pct: by.fd.invested > 0 ? (by.fd.accrued_interest/by.fd.invested*100).toFixed(2) : 0 }, icon: '🏦', color: '#D97706' },
            { label: 'Savings',      data: { invested: by.savings.current_value, current_value: by.savings.current_value, gain_loss: 0, gain_pct: 0 }, icon: '💰', color: '#0891B2' },
        ];

        el.innerHTML = assets.map(a => {
            const d = a.data || {};
            return `
            <div class="stat-card" style="border-left: 3px solid ${a.color}">
                <div class="stat-label">${a.icon} ${a.label}</div>
                <div class="stat-value">${inrFmt(d.current_value)}</div>
                <div class="stat-sub ${gainCls(d.gain_loss)}">${inrFmt(d.gain_loss)} (${numFmt(d.gain_pct)}%)</div>
            </div>`;
        }).join('');
    }

    function renderNwCharts(d) {
        // Destroy existing
        Object.values(nwCharts).forEach(c => c.destroy());
        nwCharts = {};

        const alloc = d.allocation || [];
        const eqDebt = d.equity_debt_split || [];

        // Allocation pie
        const allocCtx = document.getElementById('allocationChart');
        if (allocCtx && alloc.length) {
            nwCharts.alloc = new Chart(allocCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: alloc.map(a => a.label),
                    datasets: [{ data: alloc.map(a => a.value), backgroundColor: alloc.map(a => a.color), borderWidth: 0 }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: 'var(--text-primary)', font: { size: 11 } } },
                        tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${inrFmt(ctx.raw)} (${alloc[ctx.dataIndex]?.pct}%)` } },
                    },
                },
            });
        }

        // Equity/Debt donut
        const eqCtx = document.getElementById('equityDebtChart');
        if (eqCtx && eqDebt.length) {
            nwCharts.eqDebt = new Chart(eqCtx.getContext('2d'), {
                type: 'doughnut',
                data: {
                    labels: eqDebt.map(e => e.label),
                    datasets: [{ data: eqDebt.map(e => e.value), backgroundColor: ['#2563EB', '#D97706', '#7C3AED'], borderWidth: 0 }],
                },
                options: {
                    responsive: true, maintainAspectRatio: false,
                    plugins: {
                        legend: { position: 'bottom', labels: { color: 'var(--text-primary)', font: { size: 11 } } },
                        tooltip: { callbacks: { label: ctx => ` ${ctx.label}: ${inrFmt(ctx.raw)} (${eqDebt[ctx.dataIndex]?.pct}%)` } },
                    },
                },
            });
        }
    }

    function renderNwDetails(by) {
        // MF by category
        const mfBody = document.getElementById('mfNwBody');
        if (mfBody) {
            const cats = by.mutual_funds?.by_category || [];
            mfBody.innerHTML = cats.length ? cats.map(c => `
            <tr><td>${c.category || '-'}</td><td>${c.sub_category || '-'}</td>
            <td class="text-right">${inrFmt(c.invested)}</td>
            <td class="text-right">${inrFmt(c.current_value)}</td>
            <td class="text-right">${c.count}</td></tr>`).join('') :
            `<tr><td colspan="5" class="text-center text-secondary">No MF holdings</td></tr>`;
        }

        // Stocks by sector
        const stBody = document.getElementById('stockNwBody');
        if (stBody) {
            const sects = by.stocks?.by_sector || [];
            stBody.innerHTML = sects.length ? sects.map(s => `
            <tr><td>${s.sector || 'Other'}</td><td class="text-right">${inrFmt(s.invested)}</td>
            <td class="text-right">${inrFmt(s.current_value)}</td><td class="text-right">${s.count}</td></tr>`).join('') :
            `<tr><td colspan="4" class="text-center text-secondary">No stock holdings</td></tr>`;
        }

        // NPS by tier
        const npsBody = document.getElementById('npsNwBody');
        if (npsBody) {
            const tiers = by.nps?.by_tier || [];
            npsBody.innerHTML = tiers.length ? tiers.map(t => `
            <tr><td>${t.tier?.toUpperCase()}</td><td class="text-right">${inrFmt(t.invested)}</td>
            <td class="text-right">${inrFmt(t.current_value)}</td>
            <td class="text-right ${gainCls(t.gain_loss)}">${inrFmt(t.gain_loss)}</td></tr>`).join('') :
            `<tr><td colspan="4" class="text-center text-secondary">No NPS holdings</td></tr>`;
        }

        // FD by bank
        const fdBody = document.getElementById('fdNwBody');
        if (fdBody) {
            const banks = by.fd?.by_bank || [];
            fdBody.innerHTML = banks.length ? banks.map(b => `
            <tr><td>${b.bank_name}</td><td class="text-right">${b.count}</td>
            <td class="text-right">${inrFmt(b.total_principal)}</td>
            <td class="text-right text-success">${inrFmt(b.accrued)}</td></tr>`).join('') :
            `<tr><td colspan="4" class="text-center text-secondary">No FDs</td></tr>`;

            // Maturing soon alert
            const maturing = by.fd?.maturing_soon || [];
            const alertEl = document.getElementById('fdMaturingAlert');
            const listEl = document.getElementById('fdMaturingList');
            if (alertEl && maturing.length > 0) {
                alertEl.style.display = '';
                listEl.innerHTML = maturing.map(fd => `
                <div class="text-sm mb-1">🔔 ${fd.bank_name} — ₹${Number(fd.principal).toLocaleString('en-IN')} matures on ${fd.maturity_date} 
                <span class="badge badge-warning">${fd.days_left} days left</span></div>`).join('');
            }
        }

        // Savings by bank
        const savBody = document.getElementById('savNwBody');
        if (savBody) {
            const banks = by.savings?.by_bank || [];
            savBody.innerHTML = banks.length ? banks.map(b => `
            <tr><td>${b.bank_name}</td><td>${b.account_type?.toUpperCase()}</td>
            <td class="text-right">${inrFmt(b.balance)}</td>
            <td class="text-right">${numFmt(b.avg_rate, 2)}%</td></tr>`).join('') :
            `<tr><td colspan="4" class="text-center text-secondary">No savings accounts</td></tr>`;
        }
    }

    // Tab switching for net worth page
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
            document.querySelectorAll('.tab-panel').forEach(p => p.style.display = 'none');
            btn.classList.add('active');
            document.getElementById(btn.dataset.tab).style.display = '';
        });
    });

    // Export
    const exportNwBtn = document.getElementById('exportNwBtn');
    if (exportNwBtn) {
        exportNwBtn.addEventListener('click', () => {
            const f = document.createElement('form');
            f.method = 'POST';
            f.action = window.WD?.apiUrl || '/wealthdash/api/reports/export_csv.php';
            f.innerHTML = `<input name="action" value="export_csv"><input name="export_type" value="net_worth">
                <input name="portfolio_id" value="${portId()}"><input name="_csrf" value="${window.WD?.csrf || ''}">`;
            document.body.appendChild(f); f.submit(); document.body.removeChild(f);
        });
    }

    if (portId()) loadNetWorth();
    window.addEventListener('portfolioChanged', () => loadNetWorth());
}

/* ══════════════════════════════════════════════════════════════════════════
   REBALANCING (loaded on rebalancing page if needed)
══════════════════════════════════════════════════════════════════════════ */
if (document.getElementById('rebalancingTable')) {
    const portId = () => window.WD?.selectedPortfolio;

    async function loadRebalancing() {
        try {
            const tE = document.getElementById('targetEquity')?.value || 60;
            const tD = document.getElementById('targetDebt')?.value || 30;
            const tG = document.getElementById('targetGold')?.value || 5;
            const res = await window.apiPost({
                action: 'report_rebalancing', portfolio_id: portId(),
                target_equity: tE, target_debt: tD, target_gold: tG
            });
            if (!res.success) { window.showToast(res.message, 'error'); return; }
            renderRebalancing(res.data);
        } catch (e) { window.showToast('Failed to load rebalancing', 'error'); }
    }

    function renderRebalancing(d) {
        const tbody = document.getElementById('rebalancingTable');
        if (!tbody) return;
        const current = d.current || [];
        const target = d.target || [];

        tbody.innerHTML = current.map((c, i) => {
            const t = target[i] || {};
            const diff = (c.pct - (t.pct || 0)).toFixed(2);
            return `
            <tr>
                <td><span style="display:inline-block;width:10px;height:10px;background:${c.color};border-radius:2px"></span> ${c.asset}</td>
                <td class="text-right">${inrFmt(c.value)}</td>
                <td class="text-right">${c.pct}%</td>
                <td class="text-right">${t.pct || 0}%</td>
                <td class="text-right">${inrFmt(t.value || 0)}</td>
                <td class="text-right ${diff > 0 ? 'text-danger' : diff < 0 ? 'text-success' : ''}">${diff > 0 ? '+' : ''}${diff}%</td>
            </tr>`;
        }).join('');

        const sugBox = document.getElementById('rebalancingSuggestions');
        if (sugBox) {
            if (d.is_balanced) {
                sugBox.innerHTML = `<div class="alert alert-success">✅ Portfolio is well-balanced within ±${d.threshold_used}% of targets.</div>`;
            } else {
                sugBox.innerHTML = (d.suggestions || []).map(s => `
                <div class="alert alert-${s.action === 'REDUCE' ? 'warning' : 'info'} mb-2">
                    <strong>${s.action}</strong> ${s.asset_class}: ${s.message}
                </div>`).join('');
            }
        }

        const concBox = document.getElementById('concentrationRisks');
        if (concBox) {
            const risks = d.concentration_risks || [];
            if (risks.length) {
                concBox.innerHTML = risks.map(r => `
                <div class="alert alert-warning mb-2">
                    ⚠️ <strong>${r.name}</strong> (${r.type}): ${r.warning} [${r.pct}% of portfolio]
                </div>`).join('');
            } else {
                concBox.innerHTML = `<p class="text-secondary">No concentration risks detected.</p>`;
            }
        }
    }

    document.getElementById('rebalanceBtn')?.addEventListener('click', loadRebalancing);
    if (portId()) loadRebalancing();
    window.addEventListener('portfolioChanged', () => loadRebalancing());
}