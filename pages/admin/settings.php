<?php
/**
 * WealthDash — t52: Global Settings Control Page
 * File: pages/admin/settings.php
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
if (!is_admin()) { header('Location: ' . APP_URL . '?page=dashboard'); exit; }
$pageTitle    = 'Global Settings';
$activePage   = 'admin';
$activeSection= 'admin_settings';
ob_start();
?>
<div class="page-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
  <div><h1 class="page-title">⚙️ Global Settings</h1><p class="page-subtitle">Application-wide configuration and controls.</p></div>
  <div style="display:flex;gap:8px;">
    <button class="btn btn-ghost btn-sm" onclick="GS.resetAll()">↩ Reset All Defaults</button>
    <button class="btn btn-primary" onclick="GS.saveAll()">💾 Save All</button>
  </div>
</div>

<div id="gs-sections"></div>

<div style="margin-top:20px;display:flex;justify-content:flex-end;gap:10px;">
  <button class="btn btn-ghost" onclick="GS.resetAll()">↩ Reset All</button>
  <button class="btn btn-primary" onclick="GS.saveAll()">💾 Save All Settings</button>
</div>

<style>
.gs-section{background:var(--bg-surface);border:1px solid var(--border);border-radius:12px;margin-bottom:16px;overflow:hidden;}
.gs-section-header{padding:14px 20px;background:var(--bg-secondary);border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.gs-section-title{font-weight:700;font-size:14px;}
.gs-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;padding:20px;}
@media(max-width:640px){.gs-grid{grid-template-columns:1fr;}}
.gs-field{display:flex;flex-direction:column;gap:6px;}
.gs-label{font-size:13px;font-weight:600;color:var(--text);}
.gs-toggle{display:flex;align-items:center;gap:10px;height:38px;}
.toggle-switch{position:relative;width:44px;height:24px;flex-shrink:0;}
.toggle-switch input{opacity:0;width:0;height:0;}
.toggle-slider{position:absolute;cursor:pointer;inset:0;background:#d1d5db;border-radius:24px;transition:.2s;}
.toggle-slider:before{content:'';position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s;}
input:checked + .toggle-slider{background:var(--accent);}
input:checked + .toggle-slider:before{transform:translateX(20px);}
</style>

<script>
const GS = {
  _schema: {},
  _data: {},

  init() {
    apiPost({ action: 'admin_settings_get' }).then(r => {
      if (!r.ok) return;
      this._schema = r.data.schema;
      this._render();
    });
  },

  _render() {
    let html = '';
    for (const [groupKey, group] of Object.entries(this._schema)) {
      html += `<div class="gs-section">
        <div class="gs-section-header">
          <span class="gs-section-title">${esc(group.label)}</span>
          <button class="btn btn-ghost btn-sm" onclick="GS.resetGroup('${esc(groupKey)}')">↩ Reset</button>
        </div>
        <div class="gs-grid">`;
      for (const [key, def] of Object.entries(group.settings)) {
        html += `<div class="gs-field"><label class="gs-label" for="gs-${esc(key)}">${esc(def.label)}</label>`;
        if (def.type === 'bool') {
          html += `<div class="gs-toggle">
            <label class="toggle-switch">
              <input type="checkbox" id="gs-${esc(key)}" data-key="${esc(key)}" ${def.value==='1'||def.value===true?'checked':''}>
              <span class="toggle-slider"></span>
            </label>
            <span id="gs-${esc(key)}-lbl" style="font-size:13px;color:var(--text-muted);">${def.value==='1'?'Enabled':'Disabled'}</span>
          </div>`;
        } else if (def.type === 'select') {
          html += `<select id="gs-${esc(key)}" data-key="${esc(key)}" class="form-control">`;
          for (const opt of (def.options||[])) {
            html += `<option value="${esc(opt)}" ${def.value===opt?'selected':''}>${esc(opt)}</option>`;
          }
          html += '</select>';
        } else if (def.type === 'number') {
          html += `<input type="number" id="gs-${esc(key)}" data-key="${esc(key)}" class="form-control" value="${esc(def.value)}" min="0">`;
        } else {
          html += `<input type="text" id="gs-${esc(key)}" data-key="${esc(key)}" class="form-control" value="${esc(def.value||'')}">`;
        }
        html += '</div>';
      }
      html += '</div></div>';
    }
    document.getElementById('gs-sections').innerHTML = html;

    // Toggle labels
    document.querySelectorAll('[data-key] input[type=checkbox]').forEach(cb => {
      cb.addEventListener('change', () => {
        const lbl = document.getElementById(cb.id + '-lbl');
        if (lbl) lbl.textContent = cb.checked ? 'Enabled' : 'Disabled';
      });
    });
  },

  _collectAll() {
    const settings = {};
    document.querySelectorAll('[data-key]').forEach(el => {
      const key = el.dataset.key;
      if (!key) return;
      if (el.type === 'checkbox') settings[key] = el.checked ? '1' : '0';
      else settings[key] = el.value;
    });
    return settings;
  },

  saveAll() {
    const settings = this._collectAll();
    apiPost({ action: 'admin_settings_save', settings }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
    });
  },

  resetGroup(group) {
    if (!confirm(`Reset all "${group}" settings to defaults?`)) return;
    apiPost({ action: 'admin_settings_reset', group }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) this.init();
    });
  },

  resetAll() {
    if (!confirm('Reset ALL settings to defaults?')) return;
    apiPost({ action: 'admin_settings_reset' }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) this.init();
    });
  }
};
document.addEventListener('DOMContentLoaded', () => GS.init());
</script>
<?php
$pageContent = ob_get_clean();
include APP_ROOT . '/templates/layout.php';
