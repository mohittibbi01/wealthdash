// ============================================================
// GA-55A SYSTEM — assets/js/02_bill_entry.js
// Bill entry page — auto calculate gross, deduction, net
// ============================================================

document.addEventListener('DOMContentLoaded', function () {

    // ── Calculate totals ──
    function calcTotals() {
        let gross = 0, ded = 0;

        document.querySelectorAll('.earning-field').forEach(function (el) {
            gross += parseAmount(el.value);
        });
        document.querySelectorAll('.deduction-field').forEach(function (el) {
            ded += parseAmount(el.value);
        });

        const net = gross - ded;

        document.getElementById('displayGross').textContent = '₹' + indianFormat(gross);
        document.getElementById('displayDed').textContent   = '₹' + indianFormat(ded);
        document.getElementById('displayNet').textContent   = '₹' + indianFormat(net);
    }

    // ── Only allow numbers + decimal in amount fields ──
    function sanitizeAmount(el) {
        let val = el.value.replace(/[^0-9.]/g, '');
        // Only one decimal point
        const parts = val.split('.');
        if (parts.length > 2) val = parts[0] + '.' + parts.slice(1).join('');
        // Max 2 decimal places
        if (parts[1] && parts[1].length > 2) val = parts[0] + '.' + parts[1].slice(0, 2);
        el.value = val;
    }

    // ── Bind events ──
    document.querySelectorAll('.earning-field, .deduction-field').forEach(function (el) {
        el.addEventListener('input', function () {
            sanitizeAmount(this);
            calcTotals();
        });
        el.addEventListener('focus', function () {
            // Select all text on focus
            this.select();
        });
        el.addEventListener('blur', function () {
            // Format on blur
            if (this.value !== '') {
                const num = parseAmount(this.value);
                if (!isNaN(num) && num >= 0) {
                    this.value = num.toFixed(2);
                }
            }
        });
    });

    // ── Initial calc (for edit mode — values already filled) ──
    calcTotals();

    // ── Enter key moves to next field ──
    const allFields = Array.from(document.querySelectorAll('.form-control'));
    allFields.forEach(function (el, idx) {
        el.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                const next = allFields[idx + 1];
                if (next) next.focus();
            }
        });
    });

    // ── Client-side bill_no + tv_no duplicate warning ──
    // (Server also validates — this is just a UX helper)
    const billNoEl = document.getElementById('bill_no');
    const tvNoEl   = document.getElementById('tv_no');

    function checkDuplicate() {
        const bn = billNoEl.value.trim();
        const tv = tvNoEl.value.trim();
        if (!bn || !tv) return;

        fetch('../api/api_02_bills.php?action=check_duplicate&bill_no=' + encodeURIComponent(bn) + '&tv_no=' + encodeURIComponent(tv))
            .then(r => r.json())
            .then(function (res) {
                const warn = document.getElementById('duplicateWarn');
                if (res.exists) {
                    if (!warn) {
                        const div = document.createElement('div');
                        div.id = 'duplicateWarn';
                        div.className = 'alert alert-warning';
                        div.style.marginTop = '6px';
                        div.innerHTML = '<i class="ti ti-alert-triangle"></i> Bill No aur TV No ka yeh combination pehle se exist karta hai!';
                        billNoEl.closest('.form-group').appendChild(div);
                    }
                } else {
                    if (warn) warn.remove();
                }
            }).catch(function () {});
    }

    if (billNoEl && tvNoEl) {
        billNoEl.addEventListener('blur', checkDuplicate);
        tvNoEl.addEventListener('blur', checkDuplicate);
    }
});
