<?php
/**
 * WealthDash — Quick NAV Status Check (CLI)
 * php nav_history_downloader/check_nav_status.php
 */
define('DB_HOST','localhost'); define('DB_USER','root');
define('DB_PASS','');          define('DB_NAME','wealthdash');
$pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS,
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);

$counts = $pdo->query("
    SELECT COUNT(*) total,
           SUM(status='pending') pending,
           SUM(status='completed') completed,
           SUM(status='error') errors,
           SUM(status='in_progress') inprog,
           SUM(records_saved) total_records,
           MIN(last_downloaded_date) oldest,
           MAX(last_downloaded_date) latest
    FROM nav_download_progress")->fetch();

$navStats = $pdo->query("
    SELECT COUNT(*) total_rows, COUNT(DISTINCT fund_id) funds_with_data,
           MIN(nav_date) oldest_nav, MAX(nav_date) newest_nav
    FROM nav_history")->fetch();

$behind = $pdo->query("
    SELECT COUNT(*) FROM nav_download_progress
    WHERE status='completed' AND last_downloaded_date < CURDATE()")->fetchColumn();

$pct = $counts['total'] > 0 ? round($counts['completed']/$counts['total']*100,1) : 0;

echo "\n╔════════════════════════════════════════╗\n";
echo "║  WealthDash NAV Status — " . date('Y-m-d H:i') . "  ║\n";
echo "╚════════════════════════════════════════╝\n\n";

echo "📊 Download Progress:\n";
echo "  Total funds   : {$counts['total']}\n";
echo "  ✅ Completed  : {$counts['completed']} ({$pct}%)\n";
echo "  ⏳ Pending    : {$counts['pending']}\n";
echo "  🔄 In Progress: {$counts['inprog']}\n";
echo "  ❌ Errors     : {$counts['errors']}\n";
echo "  📅 Behind     : {$behind} (completed but not updated today)\n\n";

echo "💾 nav_history Table:\n";
echo "  Total rows    : " . number_format($navStats['total_rows']) . "\n";
echo "  Funds with data: {$navStats['funds_with_data']}\n";
echo "  Oldest NAV    : {$navStats['oldest_nav']}\n";
echo "  Newest NAV    : {$navStats['newest_nav']}\n\n";

if ($counts['pending'] == 0 && $counts['errors'] == 0) {
    echo "✅ ALL FUNDS COMPLETE! Full history downloaded.\n";
} elseif ($behind > 0) {
    echo "⚠️  {$behind} funds need incremental update (run nav_incremental_update.php)\n";
} else {
    echo "⏳ Download in progress... {$pct}% complete\n";
}
echo "\n";
