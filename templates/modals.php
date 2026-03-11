<?php
/**
 * WealthDash — Global Modals HTML
 * Available on every protected page
 */
if (!defined('WEALTHDASH')) die();
?>

<!-- ============================================================
     CONFIRM DELETE MODAL
     ============================================================ -->
<div class="modal-backdrop" id="confirmDeleteModal" style="display:none">
  <div class="modal modal-sm">
    <div class="modal-header">
      <h3 class="modal-title">Confirm Delete</h3>
      <button class="modal-close" onclick="closeModal('confirmDeleteModal')">×</button>
    </div>
    <div class="modal-body">
      <p id="confirmDeleteMessage">Are you sure you want to delete this record? This action cannot be undone.</p>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('confirmDeleteModal')">Cancel</button>
      <button class="btn btn-danger" id="confirmDeleteBtn">Delete</button>
    </div>
  </div>
</div>

<!-- ============================================================
     NEW PORTFOLIO MODAL
     ============================================================ -->
<div class="modal-backdrop" id="newPortfolioModal" style="display:none">
  <div class="modal modal-sm">
    <div class="modal-header">
      <h3 class="modal-title">New Portfolio</h3>
      <button class="modal-close" onclick="closeModal('newPortfolioModal')">×</button>
    </div>
    <div class="modal-body">
      <form id="newPortfolioForm">
        <div class="form-group">
          <label class="form-label">Portfolio Name <span class="required">*</span></label>
          <input type="text" name="name" class="form-input" placeholder="e.g. My Retirement Fund" maxlength="100" required>
        </div>
        <div class="form-group">
          <label class="form-label">Description (optional)</label>
          <input type="text" name="description" class="form-input" placeholder="Short description" maxlength="255">
        </div>
        <div class="form-group">
          <label class="form-label">Color</label>
          <div class="color-picker-row">
            <?php
            $colors = ['#2563EB','#7C3AED','#059669','#DC2626','#D97706','#0891B2','#BE185D','#1D4ED8'];
            foreach ($colors as $c):
            ?>
            <label class="color-swatch-label">
              <input type="radio" name="color" value="<?= e($c) ?>" class="sr-only" <?= $c === '#2563EB' ? 'checked' : '' ?>>
              <span class="color-swatch" style="background:<?= e($c) ?>"></span>
            </label>
            <?php endforeach; ?>
          </div>
        </div>
      </form>
      <div id="portfolioFormError" class="alert alert-error" style="display:none"></div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" onclick="closeModal('newPortfolioModal')">Cancel</button>
      <button class="btn btn-primary" onclick="submitNewPortfolio()">
        <span class="btn-text">Create Portfolio</span>
        <span class="btn-spinner spinner" style="display:none"></span>
      </button>
    </div>
  </div>
</div>

<!-- ============================================================
     LOADING OVERLAY
     ============================================================ -->
<div id="loadingOverlay" style="display:none" class="loading-overlay">
  <div class="loading-spinner"></div>
</div>

<!-- ============================================================
     TOAST NOTIFICATIONS
     ============================================================ -->
<div id="toastContainer" class="toast-container"></div>

