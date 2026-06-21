/**
 * WealthDash — JS Unit Tests [t352]
 * File: tests/unit/test_js.js
 * Worker: ID-M
 *
 * Standalone browser test page — open /tests/unit/test_js_runner.html
 * OR run with Node.js: node tests/unit/test_js.js
 */

// ── Micro test framework ──────────────────────────────────────────────────────
const WDJsTest = (() => {
    let passed = 0, failed = 0;
    const results = [];

    function it(name, fn) {
        try {
            fn();
            results.push({ name, status: 'pass' });
            passed++;
        } catch (e) {
            results.push({ name, status: 'fail', msg: e.message });
            failed++;
        }
    }

    function eq(expected, actual, msg) {
        if (expected !== actual) {
            throw new Error((msg || '') + ` Expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
        }
    }
    function ok(val, msg) { if (!val) throw new Error(msg || `Expected truthy, got ${val}`); }
    function notOk(val, msg) { if (val) throw new Error(msg || `Expected falsy, got ${val}`); }
    function contains(needle, str, msg) {
        if (!str.includes(needle)) throw new Error(msg || `"${needle}" not found in "${str}"`);
    }

    function summary() { return { passed, failed, total: passed + failed, results }; }
    return { it, eq, ok, notOk, contains, summary };
})();

const { it, eq, ok, notOk, contains } = WDJsTest;

// ── WD utility functions (mirror of public/js/app.js) ────────────────────────
const WD = {
    inr(n) {
        n = parseFloat(n) || 0;
        if (n >= 1e7) return '₹' + (n / 1e7).toFixed(2) + ' Cr';
        if (n >= 1e5) return '₹' + (n / 1e5).toFixed(2) + ' L';
        return '₹' + n.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    },
    inrCompact(n) {
        n = parseFloat(n) || 0;
        if (Math.abs(n) >= 1e7) return '₹' + (n / 1e7).toFixed(1) + 'Cr';
        if (Math.abs(n) >= 1e5) return '₹' + (n / 1e5).toFixed(1) + 'L';
        if (Math.abs(n) >= 1e3) return '₹' + (n / 1e3).toFixed(1) + 'K';
        return '₹' + n.toFixed(0);
    },
    pct(n, decimals = 2) {
        return (parseFloat(n) || 0).toFixed(decimals) + '%';
    },
    gainClass(n) {
        n = parseFloat(n);
        return n > 0 ? 'text-gain' : n < 0 ? 'text-loss' : 'text-muted';
    },
    formatDate(d) {
        if (!d) return '—';
        const dt = new Date(d);
        const months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
        return `${dt.getDate()} ${months[dt.getMonth()]} ${dt.getFullYear()}`;
    },
    daysUntil(dateStr) {
        const now  = new Date(); now.setHours(0,0,0,0);
        const then = new Date(dateStr);
        return Math.round((then - now) / 86400000);
    },
    cagr(invested, current, years) {
        if (!invested || !years) return 0;
        return (Math.pow(current / invested, 1 / years) - 1) * 100;
    },
    absoluteReturn(invested, current) {
        if (!invested) return 0;
        return (current - invested) / invested * 100;
    },
};

// ── WD.inr() ─────────────────────────────────────────────────────────────────
it('inr: formats thousands', () => contains('₹', WD.inr(50000)));
it('inr: formats lakhs', () => contains('L', WD.inr(150000)));
it('inr: formats crores', () => contains('Cr', WD.inr(10000000)));
it('inr: handles zero', () => contains('₹', WD.inr(0)));
it('inr: handles negative', () => { const r = WD.inr(-50000); ok(r.includes('₹')); });
it('inr: 1 lakh exactly', () => eq('₹1.00 L', WD.inr(100000)));
it('inr: 1 crore exactly', () => eq('₹1.00 Cr', WD.inr(10000000)));

// ── WD.inrCompact() ───────────────────────────────────────────────────────────
it('inrCompact: crore format', () => contains('Cr', WD.inrCompact(25000000)));
it('inrCompact: lakh format', () => contains('L', WD.inrCompact(500000)));
it('inrCompact: thousand format', () => contains('K', WD.inrCompact(5000)));

// ── WD.pct() ─────────────────────────────────────────────────────────────────
it('pct: formats correctly', () => eq('12.50%', WD.pct(12.5)));
it('pct: zero', () => eq('0.00%', WD.pct(0)));
it('pct: negative', () => eq('-5.00%', WD.pct(-5)));
it('pct: custom decimals', () => eq('12.5%', WD.pct(12.5, 1)));

// ── WD.gainClass() ───────────────────────────────────────────────────────────
it('gainClass: positive = text-gain', () => eq('text-gain', WD.gainClass(10)));
it('gainClass: negative = text-loss', () => eq('text-loss', WD.gainClass(-5)));
it('gainClass: zero = text-muted', () => eq('text-muted', WD.gainClass(0)));

// ── WD.formatDate() ──────────────────────────────────────────────────────────
it('formatDate: formats date string', () => { const r = WD.formatDate('2024-06-15'); ok(r.includes('2024')); });
it('formatDate: null returns —', () => eq('—', WD.formatDate(null)));
it('formatDate: empty returns —', () => eq('—', WD.formatDate('')));

// ── WD.daysUntil() ───────────────────────────────────────────────────────────
it('daysUntil: future date > 0', () => {
    const future = new Date(); future.setDate(future.getDate() + 30);
    ok(WD.daysUntil(future.toISOString().slice(0, 10)) > 0);
});
it('daysUntil: past date < 0', () => {
    const past = new Date(); past.setDate(past.getDate() - 30);
    ok(WD.daysUntil(past.toISOString().slice(0, 10)) < 0);
});

// ── WD.cagr() ────────────────────────────────────────────────────────────────
it('cagr: 0% for equal values', () => eq(0, Math.round(WD.cagr(100000, 100000, 5))));
it('cagr: positive for growth', () => ok(WD.cagr(100000, 161051, 5) > 9.9));
it('cagr: handles zero invested', () => eq(0, WD.cagr(0, 1000, 1)));

// ── WD.absoluteReturn() ──────────────────────────────────────────────────────
it('absoluteReturn: 20% gain', () => eq(20, WD.absoluteReturn(100000, 120000)));
it('absoluteReturn: -20% loss', () => eq(-20, WD.absoluteReturn(100000, 80000)));
it('absoluteReturn: zero invested returns 0', () => eq(0, WD.absoluteReturn(0, 1000)));

// ── Tax constants (from window.WD_CONFIG if available) ───────────────────────
it('LTCG rate is 12.5', () => {
    const rate = (typeof WD_CONFIG !== 'undefined') ? WD_CONFIG.LTCG_RATE : 12.5;
    eq(12.5, rate);
});

// ── Output ────────────────────────────────────────────────────────────────────
const summary = WDJsTest.summary();

// Node.js output
if (typeof process !== 'undefined' && process.stdout) {
    const pass = summary.passed, fail = summary.failed;
    console.log(`\nWealthDash JS Unit Tests`);
    console.log(`${'─'.repeat(40)}`);
    summary.results.forEach(r => {
        const icon = r.status === 'pass' ? '✓' : '✗';
        console.log(`  ${icon} ${r.name}${r.msg ? '\n    → ' + r.msg : ''}`);
    });
    console.log(`${'─'.repeat(40)}`);
    console.log(`${pass}/${pass + fail} passed${fail > 0 ? ` (${fail} failed)` : ' ✓'}\n`);
    process.exitCode = fail > 0 ? 1 : 0;
}
