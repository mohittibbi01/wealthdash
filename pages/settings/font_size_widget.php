<?php
/**
 * WealthDash — t350: Font Size Preference Settings Widget
 * File: pages/settings/font_size_widget.php
 *
 * INCLUDE THIS in your Accessibility / Settings page:
 *   <?php include APP_ROOT . '/pages/settings/font_size_widget.php'; ?>
 */
defined('WEALTHDASH') or die('Direct access not allowed.');
?>
<div class="card" style="margin-bottom:20px;">
  <div class="card-header"><span class="card-title">🔤 Font Size</span></div>
  <div class="card-body">
    <p style="font-size:13px;color:var(--text-muted);margin-bottom:14px;">Adjust text size across the app for better readability.</p>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn btn-ghost fs-option" data-size="small"  onclick="WDFontSize.set('small');FSW.markActive('small')"  style="font-size:12px;">A Small</button>
      <button class="btn btn-ghost fs-option" data-size="medium" onclick="WDFontSize.set('medium');FSW.markActive('medium')" style="font-size:14px;">A Medium</button>
      <button class="btn btn-ghost fs-option" data-size="large"  onclick="WDFontSize.set('large');FSW.markActive('large')"  style="font-size:16px;">A Large</button>
      <button class="btn btn-ghost fs-option" data-size="xlarge" onclick="WDFontSize.set('xlarge');FSW.markActive('xlarge')" style="font-size:18px;">A Extra Large</button>
    </div>
  </div>
</div>
<script>
const FSW = {
  markActive(size) {
    document.querySelectorAll('.fs-option').forEach(b => b.classList.toggle('btn-primary', b.dataset.size === size) || b.classList.toggle('btn-ghost', b.dataset.size !== size));
  }
};
document.addEventListener('DOMContentLoaded', () => {
  const current = document.documentElement.getAttribute('data-font-size') || 'medium';
  FSW.markActive(current);
});
</script>
