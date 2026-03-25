<?php
/**
 * WealthDash — Admin Panel: NAV Download Section
 * 
 * YEH CODE apne admin.php mein paste karo jahan NAV section banana hai
 * Path: templates/pages/admin.php (relevant section mein add karo)
 * 
 * Agar admin.php mein already tabs/sections hain to ek naya tab/card banao
 */

// ── Data fetch for admin panel ────────────────────────────────────────────────
// Yeh PHP block apne admin.php ke top (data fetching) section mein add karo:

/*
// NAV Download Stats
$navDlStats = [];
try {
    $navDlStats = DB::fetchOne("
        SELECT
            COUNT(*)                        AS total,
            SUM(status='pending')           AS pending,
            SUM(status='in_progress')       AS working,
            SUM(status='completed')         AS completed,
            SUM(status='error')             AS errors,
            SUM(records_saved)              AS total_records,
            MAX(last_downloaded_date)       AS latest_dl
        FROM nav_download_progress
    ");
} catch(Exception $e) { $navDlStats = []; }

$navHistoryCount = 0;
try {
    $navHistoryCount = (int) DB::fetchVal("SELECT COUNT(*) FROM nav_history");
} catch(Exception $e) {}
*/
?>

<!-- 
====================================================================
ADMIN PANEL — NAV History Download Section
Admin.php ke andar yeh HTML block paste karo (apne card/section structure ke andar)
====================================================================
-->

<!-- NAV DOWNLOAD SECTION START -->
<div class="admin-section" id="section-nav-download" style="
    background:#fff;
    border:1px solid #e2e8f0;
    border-radius:12px;
    padding:20px 24px;
    margin-bottom:20px;
">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
        <h3 style="font-size:15px;font-weight:700;color:#0f172a;display:flex;align-items:center;gap:8px">
            📥 Full NAV History Download
        </h3>
        <a href="/wealthdash/nav_download/status.php" target="_blank"
           style="font-size:12px;color:#0891b2;text-decoration:none;font-weight:600;
                  background:#ecfeff;padding:5px 12px;border-radius:6px;border:1px solid #a5f3fc">
            🔗 Open Full Dashboard →
        </a>
    </div>

    <!-- STAT CARDS ROW -->
    <div style="display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:16px" id="adm-nav-cards">
        <div style="background:#eff6ff;border-radius:8px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#2563eb" id="adm-nav-total">—</div>
            <div style="font-size:10px;color:#64748b;text-transform:uppercase">Total Funds</div>
        </div>
        <div style="background:#f0fdf4;border-radius:8px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#16a34a" id="adm-nav-done">—</div>
            <div style="font-size:10px;color:#64748b;text-transform:uppercase">Downloaded ✅</div>
        </div>
        <div style="background:#fff7ed;border-radius:8px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#ea580c" id="adm-nav-pend">—</div>
            <div style="font-size:10px;color:#64748b;text-transform:uppercase">Pending</div>
        </div>
        <div style="background:#fef2f2;border-radius:8px;padding:12px;text-align:center">
            <div style="font-size:22px;font-weight:800;color:#dc2626" id="adm-nav-err">—</div>
            <div style="font-size:10px;color:#64748b;text-transform:uppercase">Errors</div>
        </div>
        <div style="background:#f0fdfa;border-radius:8px;padding:12px;text-align:center">
            <div style="font-size:18px;font-weight:800;color:#0891b2" id="adm-nav-recs">—</div>
            <div style="font-size:10px;color:#64748b;text-transform:uppercase">NAV Records</div>
        </div>
    </div>

    <!-- PROGRESS BAR -->
    <div style="margin-bottom:12px">
        <div style="display:flex;justify-content:space-between;margin-bottom:4px">
            <span style="font-size:12px;color:#64748b;font-weight:600">Download Progress</span>
            <span style="font-size:14px;font-weight:800;color:#0891b2" id="adm-nav-pct">0%</span>
        </div>
        <div style="background:#e2e8f0;border-radius:99px;height:8px;overflow:hidden">
            <div id="adm-nav-bar" style="height:100%;background:linear-gradient(90deg,#0891b2,#2563eb);border-radius:99px;transition:width .6s;width:0%"></div>
        </div>
        <div style="font-size:11px;color:#94a3b8;margin-top:4px" id="adm-nav-meta">Latest: —</div>
    </div>

    <!-- WARNING -->
    <div style="background:#fefce8;border:1px solid #fde047;border-radius:8px;padding:10px 14px;font-size:11px;color:#854d0e;margin-bottom:12px">
        ⚠️ <strong>Storage Note:</strong> ~5M+ NAV records expected. 14,000 funds × avg 1,000 days = ~200MB+ DB size.
        Latest: <strong id="adm-nav-latest">—</strong>
    </div>

    <!-- ACTION BUTTONS -->
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <a href="/wealthdash/nav_download/status.php" target="_blank"
           style="padding:7px 16px;background:#0891b2;color:#fff;border-radius:7px;text-decoration:none;font-size:12px;font-weight:600">
            ▶ Start / Monitor Download
        </a>
        <button onclick="adminNavRefresh()"
                style="padding:7px 16px;background:#f1f5f9;color:#475569;border:1px solid #e2e8f0;border-radius:7px;font-size:12px;font-weight:600;cursor:pointer">
            ↺ Refresh Stats
        </button>
    </div>
</div>
<!-- NAV DOWNLOAD SECTION END -->

<script>
// Yeh script apne admin.php ke existing script section mein add karo

async function adminNavLoadStats() {
    try {
        const d = await fetch('/wealthdash/nav_download/api.php?action=summary&_='+Date.now(), {cache:'no-store'}).then(r=>r.json());
        if (d.error) return;

        const fmt = n => Number(n||0).toLocaleString('en-IN');

        document.getElementById('adm-nav-total').textContent = fmt(d.total);
        document.getElementById('adm-nav-done').textContent  = fmt(d.completed);
        document.getElementById('adm-nav-pend').textContent  = fmt(d.pending);
        document.getElementById('adm-nav-err').textContent   = fmt(d.errors);
        document.getElementById('adm-nav-recs').textContent  = fmt(d.total_records);
        document.getElementById('adm-nav-pct').textContent   = d.pct + '%';
        document.getElementById('adm-nav-bar').style.width   = d.pct + '%';
        document.getElementById('adm-nav-meta').textContent  = 'Latest download: ' + (d.counts?.latest_dl || '—');
        document.getElementById('adm-nav-latest').textContent = (d.counts?.latest_dl || '—');
    } catch(e) {}
}

function adminNavRefresh() { adminNavLoadStats(); }

// Page load pe run karo
adminNavLoadStats();
// Har 30 sec mein refresh (sirf admin panel mein)
setInterval(adminNavLoadStats, 30000);
</script>
