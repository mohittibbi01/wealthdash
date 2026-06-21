<?php
/**
 * WealthDash — t454: New User Setup Wizard Modal
 * File: templates/setup_wizard_modal.php
 *
 * INCLUDE THIS in layout.php (e.g. near GLOBAL MODALS section):
 *   <?php include APP_ROOT . '/templates/setup_wizard_modal.php'; ?>
 *
 * Shows a 4-step modal wizard on first login. Calls setup_wizard_status
 * on page load; if show_wizard=true, displays modal automatically.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
?>
<!-- ═══════════════════════════════════════════════════════
     t454: NEW USER SETUP WIZARD
════════════════════════════════════════════════════════ -->
<div id="wzModal" class="modal-overlay" style="display:none;z-index:2000;">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <span class="modal-title">👋 Welcome to <?= e(APP_NAME) ?>!</span>
    </div>
    <div class="modal-body">
      <!-- Progress dots -->
      <div style="display:flex;gap:6px;justify-content:center;margin-bottom:20px;">
        <div class="wz-dot active" data-step="1"></div>
        <div class="wz-dot" data-step="2"></div>
        <div class="wz-dot" data-step="3"></div>
        <div class="wz-dot" data-step="4"></div>
      </div>

      <!-- Step 1: Name confirm -->
      <div class="wz-step" data-step="1">
        <div style="text-align:center;font-size:2.5rem;margin-bottom:12px;">👤</div>
        <h3 style="text-align:center;font-size:16px;margin-bottom:6px;">What should we call you?</h3>
        <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-bottom:16px;">This appears across your dashboard.</p>
        <input type="text" id="wz-name" class="form-control" placeholder="Your name">
      </div>

      <!-- Step 2: Theme -->
      <div class="wz-step" data-step="2" style="display:none;">
        <div style="text-align:center;font-size:2.5rem;margin-bottom:12px;">🎨</div>
        <h3 style="text-align:center;font-size:16px;margin-bottom:6px;">Pick your theme</h3>
        <div style="display:flex;gap:12px;justify-content:center;margin-top:16px;">
          <button class="wz-theme-btn" data-theme="light" onclick="WZ.selectTheme('light')" style="flex:1;padding:20px;border:2px solid var(--border);border-radius:12px;background:#fff;color:#1a1a2e;cursor:pointer;text-align:center;">☀️<br><span style="font-size:13px;font-weight:600;">Light</span></button>
          <button class="wz-theme-btn" data-theme="dark" onclick="WZ.selectTheme('dark')" style="flex:1;padding:20px;border:2px solid var(--border);border-radius:12px;background:#1a1a2e;color:#fff;cursor:pointer;text-align:center;">🌙<br><span style="font-size:13px;font-weight:600;">Dark</span></button>
        </div>
      </div>

      <!-- Step 3: Risk profile -->
      <div class="wz-step" data-step="3" style="display:none;">
        <div style="text-align:center;font-size:2.5rem;margin-bottom:12px;">⚖️</div>
        <h3 style="text-align:center;font-size:16px;margin-bottom:6px;">What's your risk appetite?</h3>
        <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-bottom:16px;">Helps us personalize AI recommendations.</p>
        <select id="wz-risk" class="form-control">
          <option value="conservative">🛡 Conservative — Safety first</option>
          <option value="moderate" selected>⚖️ Moderate — Balanced growth</option>
          <option value="moderately_aggressive">📈 Moderately Aggressive — Growth focused</option>
          <option value="aggressive">🚀 Aggressive — High growth, high risk</option>
        </select>
      </div>

      <!-- Step 4: First goal -->
      <div class="wz-step" data-step="4" style="display:none;">
        <div style="text-align:center;font-size:2.5rem;margin-bottom:12px;">🎯</div>
        <h3 style="text-align:center;font-size:16px;margin-bottom:6px;">Set your first goal</h3>
        <p style="text-align:center;font-size:13px;color:var(--text-muted);margin-bottom:16px;">Optional — you can add more later.</p>
        <div class="form-group"><label class="form-label">Goal Name</label><input type="text" id="wz-goal-name" class="form-control" placeholder="e.g. Retirement, House, Emergency Fund"></div>
        <div class="form-group"><label class="form-label">Target Amount (₹)</label><input type="number" id="wz-goal-amount" class="form-control" placeholder="e.g. 5000000" step="10000"></div>
      </div>
    </div>
    <div class="modal-footer" style="display:flex;justify-content:space-between;">
      <button class="btn btn-ghost" id="wz-skip-btn" onclick="WZ.skip()">Skip Setup</button>
      <div style="display:flex;gap:8px;">
        <button class="btn btn-ghost" id="wz-back-btn" onclick="WZ.back()" style="display:none;">← Back</button>
        <button class="btn btn-primary" id="wz-next-btn" onclick="WZ.next()">Next →</button>
      </div>
    </div>
  </div>
</div>

<style>
.wz-dot{width:10px;height:10px;border-radius:50%;background:var(--border);transition:.2s;}
.wz-dot.active{background:var(--accent);width:24px;border-radius:5px;}
.wz-theme-btn.selected{border-color:var(--accent) !important;box-shadow:0 0 0 3px rgba(37,99,235,.2);}
</style>

<script>
const WZ = {
  _step: 1, _maxStep: 4, _theme: 'light',

  init() {
    apiPost({action:'setup_wizard_status'}).then(r => {
      if (r.ok && r.data.show_wizard) {
        document.getElementById('wzModal').style.display = '';
        const nameInput = document.getElementById('wz-name');
        if (window.WD && window.WD.userName) nameInput.value = window.WD.userName;
      }
    });
  },

  _show(step) {
    document.querySelectorAll('.wz-step').forEach(el => el.style.display = el.dataset.step == step ? '' : 'none');
    document.querySelectorAll('.wz-dot').forEach(el => el.classList.toggle('active', el.dataset.step == step));
    document.getElementById('wz-back-btn').style.display = step > 1 ? '' : 'none';
    document.getElementById('wz-next-btn').textContent = step >= this._maxStep ? '🎉 Finish' : 'Next →';
    this._step = step;
  },

  selectTheme(theme) {
    this._theme = theme;
    document.querySelectorAll('.wz-theme-btn').forEach(b => b.classList.toggle('selected', b.dataset.theme === theme));
    if (typeof window.setTheme === 'function') window.setTheme(theme);
    else document.documentElement.setAttribute('data-theme', theme);
  },

  next() {
    // Save current step
    const step = this._step;
    let data = {};
    if (step === 1) data = { name: document.getElementById('wz-name').value.trim() };
    if (step === 2) data = { theme: this._theme };
    if (step === 3) data = { risk_profile: document.getElementById('wz-risk').value };
    if (step === 4) {
      const gn = document.getElementById('wz-goal-name').value.trim();
      const ga = document.getElementById('wz-goal-amount').value;
      if (gn && ga) data = { goal_name: gn, target_amount: ga };
    }

    const stepKeys = { 1: 'profile', 2: 'theme', 3: 'risk_profile', 4: 'first_goal' };
    apiPost({ action: 'setup_wizard_save_step', step: stepKeys[step], data: JSON.stringify(data) }).then(() => {
      if (step >= this._maxStep) {
        this.finish();
      } else {
        this._show(step + 1);
      }
    });
  },

  back() {
    if (this._step > 1) this._show(this._step - 1);
  },

  finish() {
    apiPost({ action: 'setup_wizard_complete' }).then(r => {
      document.getElementById('wzModal').style.display = 'none';
      showToast(r.message || 'Welcome! 🎉', 'success');
      setTimeout(() => window.location.reload(), 800);
    });
  },

  skip() {
    apiPost({ action: 'setup_wizard_complete' }).then(() => {
      document.getElementById('wzModal').style.display = 'none';
    });
  }
};

document.addEventListener('DOMContentLoaded', () => WZ.init());
</script>
