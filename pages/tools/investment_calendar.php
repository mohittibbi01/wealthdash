<?php
/**
 * WealthDash — t498: Investment Calendar 2025-26
 * File: pages/tools/investment_calendar.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
$pageTitle    = 'Investment Calendar';
$activePage   = 'tools';
$activeSection= 'tools';
$currentFY    = (date('n') >= 4) ? date('Y') . '-' . substr(date('Y')+1,-2) : (date('Y')-1) . '-' . substr(date('Y'),-2);
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div>
    <h1 class="page-title">📅 Investment Calendar</h1>
    <p class="page-subtitle">All important tax & investment dates in one place.</p>
  </div>
  <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;">
    <select id="cal-fy-select" class="form-control" style="width:130px;" onchange="CalApp.changeFY()">
      <?php
      $baseYear = 2024;
      for ($y = $baseYear; $y <= $baseYear + 3; $y++) {
          $fy = $y . '-' . substr($y+1,-2);
          $sel = $fy === $currentFY ? 'selected' : '';
          echo "<option value=\"$fy\" $sel>FY $fy</option>";
      }
      ?>
    </select>
    <div style="display:flex;gap:6px;">
      <?php
      $types = ['tax'=>'🧾 Tax','sip'=>'📈 SIP','emi'=>'🏦 EMI','fd'=>'💰 FD','insurance'=>'🛡 Insurance','review'=>'🔍 Review','goal'=>'🎯 Goals'];
      foreach ($types as $t => $label) {
          echo "<button class=\"btn btn-secondary btn-sm cal-filter-btn active\" data-type=\"$t\" onclick=\"CalApp.toggleType('$t',this)\" style=\"font-size:11px;padding:4px 8px;\">$label</button>";
      }
      ?>
    </div>
  </div>
</div>

<!-- Calendar grid -->
<div id="cal-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;" class="responsive-grid-1col"></div>

<!-- Upcoming events sidebar-style list -->
<div class="card" style="margin-top:20px;">
  <div class="card-header" style="display:flex;align-items:center;justify-content:space-between;">
    <span class="card-title">⏰ Upcoming Events (Next 60 days)</span>
    <span id="cal-upcoming-count" class="badge"></span>
  </div>
  <div class="card-body p-0">
    <div id="cal-upcoming-list"></div>
  </div>
</div>

<style>
.cal-month-card{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;overflow:hidden;}
.cal-month-header{padding:10px 14px;font-weight:700;font-size:13px;background:var(--bg-secondary);border-bottom:1px solid var(--border);}
.cal-event{padding:8px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;}
.cal-event:last-child{border-bottom:none;}
.cal-event:hover{background:var(--bg-secondary);}
.cal-event-date{font-size:11px;color:var(--text-muted);font-variant-numeric:tabular-nums;margin-bottom:2px;}
.cal-event-title{font-size:13px;font-weight:600;}
.cal-event-desc{font-size:11px;color:var(--text-muted);margin-top:2px;line-height:1.4;}
.cal-dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:6px;flex-shrink:0;}
.dot-tax{background:#dc2626;} .dot-sip{background:#16a34a;} .dot-emi{background:#f97316;}
.dot-fd{background:#2563eb;} .dot-insurance{background:#7c3aed;} .dot-review{background:#0891b2;} .dot-goal{background:#d97706;}
.p-danger{border-left:3px solid #dc2626;} .p-warning{border-left:3px solid #f97316;}
.p-info{border-left:3px solid #2563eb;} .p-sip{border-left:3px solid #16a34a;} .p-emi{border-left:3px solid #f97316;}
</style>

<script>
const CalApp = {
  _events: [],
  _hiddenTypes: new Set(),

  init() { this.loadEvents(); },

  changeFY() { this.loadEvents(); },

  loadEvents() {
    const fy = document.getElementById('cal-fy-select').value;
    document.getElementById('cal-grid').innerHTML = '<div class="loading-row" style="grid-column:1/-1;">Loading…</div>';
    apiPost({ action: 'inv_calendar_events', fy }).then(r => {
      if (!r.ok) return;
      this._events = r.data.events || [];
      this._render();
    });
  },

  toggleType(type, btn) {
    btn.classList.toggle('active');
    if (this._hiddenTypes.has(type)) this._hiddenTypes.delete(type);
    else this._hiddenTypes.add(type);
    this._render();
  },

  _render() {
    const visible = this._events.filter(e => !this._hiddenTypes.has(e.type));
    // Group by month
    const byMonth = {};
    for (const e of visible) {
      const m = e.date.substring(0,7);
      if (!byMonth[m]) byMonth[m] = [];
      byMonth[m].push(e);
    }
    const months = Object.keys(byMonth).sort();
    let html = '';
    if (!months.length) {
      html = '<div class="empty-state" style="grid-column:1/-1;"><div class="empty-icon">📅</div><div>No events found for selected filters.</div></div>';
    } else {
      for (const m of months) {
        const label = new Date(m + '-01').toLocaleDateString('en-IN', { month:'long', year:'numeric' });
        html += `<div class="cal-month-card">
          <div class="cal-month-header">📅 ${esc(label)} <span style="float:right;font-weight:400;color:var(--text-muted);">${byMonth[m].length} events</span></div>`;
        for (const e of byMonth[m]) {
          const dotClass = 'dot-' + e.type;
          const priClass = 'p-' + (e.priority || 'info');
          html += `<div class="cal-event ${priClass}" onclick="CalApp._showDetail(${JSON.stringify(e).replace(/"/g,'&quot;')})">
            <div class="cal-event-date"><span class="cal-dot ${dotClass}"></span>${esc(e.date)}</div>
            <div class="cal-event-title">${esc(e.title)}</div>
            <div class="cal-event-desc">${esc(e.desc)}</div>
          </div>`;
        }
        html += '</div>';
      }
    }
    document.getElementById('cal-grid').innerHTML = html;

    // Upcoming 60 days
    const today = new Date().toISOString().substring(0,10);
    const future60 = new Date(); future60.setDate(future60.getDate()+60);
    const f60 = future60.toISOString().substring(0,10);
    const upcoming = visible.filter(e => e.date >= today && e.date <= f60);
    document.getElementById('cal-upcoming-count').textContent = upcoming.length;
    if (!upcoming.length) {
      document.getElementById('cal-upcoming-list').innerHTML = '<div class="empty-state"><div>No upcoming events in next 60 days.</div></div>';
    } else {
      let ul = `<div class="table-responsive"><table class="data-table"><thead><tr>
        <th>Date</th><th>Event</th><th>Type</th><th>Priority</th></tr></thead><tbody>`;
      const priLabel = { danger:'🔴 High', warning:'🟡 Medium', info:'🔵 Low', sip:'📈 SIP', emi:'🏦 EMI' };
      for (const e of upcoming) {
        ul += `<tr>
          <td class="wd-num" style="font-size:13px;">${esc(e.date)}</td>
          <td>
            <div style="font-weight:600;font-size:13px;">${esc(e.title)}</div>
            <div style="font-size:11px;color:var(--text-muted);">${esc(e.desc)}</div>
          </td>
          <td><span class="badge" style="text-transform:capitalize;">${esc(e.category||e.type)}</span></td>
          <td>${priLabel[e.priority] || e.priority}</td>
        </tr>`;
      }
      ul += '</tbody></table></div>';
      document.getElementById('cal-upcoming-list').innerHTML = ul;
    }
  },

  _showDetail(e) {
    // Simple toast-style detail (could be enhanced to modal)
    showToast(`${e.date}: ${e.title}`, 'info');
  }
};

document.addEventListener('DOMContentLoaded', () => CalApp.init());
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
