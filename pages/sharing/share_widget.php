<?php
/**
 * WealthDash — t378: Report Sharing Widget
 * File: pages/sharing/share_widget.php
 *
 * INCLUDE THIS in any report/dashboard page where sharing is needed:
 *   <?php include APP_ROOT . '/pages/sharing/share_widget.php'; ?>
 * Then call ShareWidget.open() from a "Share" button.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
?>
<!-- Share Modal -->
<div id="sw-modal" class="modal-overlay" style="display:none;" onclick="if(event.target===this)ShareWidget.close()">
  <div class="modal" style="max-width:440px;">
    <div class="modal-header"><span class="modal-title">📤 Share Report</span><button class="modal-close" onclick="ShareWidget.close()">×</button></div>
    <div class="modal-body">
      <div class="form-group"><label class="form-label">Add a personal message (optional)</label><textarea id="sw-message" class="form-control" rows="2" placeholder="Check out my portfolio progress!"></textarea></div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:16px;">
        <button class="btn btn-primary" onclick="ShareWidget.shareWhatsApp()" style="background:#25D366;border-color:#25D366;">
          <span style="font-size:16px;">💬</span> WhatsApp
        </button>
        <button class="btn btn-secondary" onclick="ShareWidget.toggleEmail()">
          <span style="font-size:16px;">📧</span> Email
        </button>
      </div>

      <div id="sw-email-section" style="display:none;border-top:1px solid var(--border);padding-top:14px;">
        <div class="form-group"><label class="form-label">Recipient Email</label><input type="email" id="sw-email-to" class="form-control" placeholder="someone@example.com"></div>
        <button class="btn btn-primary btn-sm" style="width:100%;" onclick="ShareWidget.sendEmail()">📧 Send Email</button>
      </div>

      <div id="sw-preview" style="margin-top:16px;padding:12px;background:var(--bg-secondary);border-radius:8px;font-size:12px;white-space:pre-wrap;color:var(--text-muted);max-height:150px;overflow-y:auto;"></div>
    </div>
  </div>
</div>

<script>
const ShareWidget = {
  open(reportType='summary') {
    this._reportType = reportType;
    document.getElementById('sw-message').value = '';
    document.getElementById('sw-email-section').style.display = 'none';
    document.getElementById('sw-preview').textContent = 'Preview will appear here...';
    document.getElementById('sw-modal').style.display = '';
    this._loadPreview();
  },
  close() { document.getElementById('sw-modal').style.display = 'none'; },

  _loadPreview() {
    apiPost({ action: 'share_whatsapp_link', report_type: this._reportType || 'summary' }).then(r => {
      if (r.ok) document.getElementById('sw-preview').textContent = r.data.text;
    });
  },

  shareWhatsApp() {
    const msg = document.getElementById('sw-message').value;
    apiPost({ action: 'share_whatsapp_link', report_type: this._reportType || 'summary', custom_message: msg }).then(r => {
      if (!r.ok) { showToast(r.message, 'error'); return; }
      window.open(r.data.whatsapp_url, '_blank');
      this.close();
    });
  },

  toggleEmail() {
    const sec = document.getElementById('sw-email-section');
    sec.style.display = sec.style.display === 'none' ? '' : 'none';
  },

  sendEmail() {
    const to = document.getElementById('sw-email-to').value;
    const msg = document.getElementById('sw-message').value;
    if (!to) { showToast('Enter recipient email', 'warning'); return; }
    apiPost({ action: 'share_email_send', to_email: to, report_type: this._reportType || 'summary', custom_message: msg }).then(r => {
      showToast(r.message, r.ok ? 'success' : 'error');
      if (r.ok) this.close();
    });
  }
};
</script>
