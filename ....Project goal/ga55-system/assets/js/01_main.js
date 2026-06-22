// ============================================================
// GA-55A SYSTEM — assets/js/01_main.js
// Common JS — har page pe load hota hai
// ============================================================

// ── Sidebar toggle (mobile) ──
function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('open');
    document.getElementById('sidebarOverlay').classList.toggle('open');
}
function closeSidebar() {
    document.getElementById('sidebar').classList.remove('open');
    document.getElementById('sidebarOverlay').classList.remove('open');
}

// ── Auto-hide alerts after 4 seconds ──
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert[data-autohide]');
    alerts.forEach(function (el) {
        setTimeout(function () {
            el.style.transition = 'opacity 0.4s';
            el.style.opacity = '0';
            setTimeout(function () { el.remove(); }, 400);
        }, 4000);
    });
});

// ── Confirm before delete ──
document.addEventListener('click', function (e) {
    if (e.target.closest('[data-confirm]')) {
        const msg = e.target.closest('[data-confirm]').getAttribute('data-confirm');
        if (!confirm(msg || 'Kya aap sure hain?')) {
            e.preventDefault();
        }
    }
});

// ── Number format Indian style (for display) ──
function indianFormat(num) {
    num = parseFloat(num) || 0;
    let s = num.toFixed(2).split('.');
    let int = s[0], dec = s[1];
    let lastThree = int.slice(-3);
    let rest = int.slice(0, -3);
    if (rest) lastThree = ',' + lastThree;
    let result = rest.replace(/\B(?=(\d{2})+(?!\d))/g, ',') + lastThree + '.' + dec;
    return result;
}

// ── Parse amount string to float ──
function parseAmount(val) {
    return parseFloat(String(val).replace(/,/g, '')) || 0;
}
