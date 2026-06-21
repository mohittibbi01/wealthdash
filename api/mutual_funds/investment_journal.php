<?php
/**
 * WealthDash — Investment Journal API
 * Task t408: Daily market notes, fund tags, calendar view, AI summary
 * Actions: journal_list | journal_add | journal_edit | journal_delete | journal_calendar
 */

if (!defined('WEALTHDASH')) die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$action      = $_POST['action'] ?? $_GET['action'] ?? 'journal_list';
$db          = DB::conn();

// ── Ensure journal table ──────────────────────────────────────────────
$db->exec("CREATE TABLE IF NOT EXISTS investment_journal (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    entry_date DATE NOT NULL,
    title VARCHAR(200) NOT NULL DEFAULT '',
    body TEXT NOT NULL,
    mood ENUM('bullish','bearish','neutral','anxious','confident') NOT NULL DEFAULT 'neutral',
    tags JSON DEFAULT NULL COMMENT 'Array of tag strings e.g. [\"HDFC Flexi\",\"market\",\"SIP\"]',
    linked_fund_id INT UNSIGNED DEFAULT NULL,
    portfolio_change_pct DECIMAL(6,2) DEFAULT NULL COMMENT 'Portfolio change % on that day',
    nifty_change_pct DECIMAL(6,2) DEFAULT NULL,
    is_private TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    KEY idx_user_date (user_id, entry_date),
    KEY idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

switch ($action) {

// ══════════════════════════════════════════════════════════════════════════
// journal_list — paginated list with optional date/tag filter
// ══════════════════════════════════════════════════════════════════════════
case 'journal_list':
    $page      = max(1, (int)($_POST['page'] ?? $_GET['page'] ?? 1));
    $limit     = 20;
    $offset    = ($page - 1) * $limit;
    $month     = trim($_POST['month'] ?? $_GET['month'] ?? '');     // YYYY-MM
    $tag       = trim($_POST['tag']   ?? $_GET['tag']   ?? '');
    $search    = trim($_POST['search']?? $_GET['search']?? '');
    $mood      = trim($_POST['mood']  ?? $_GET['mood']  ?? '');

    $where  = ['j.user_id = ?'];
    $params = [$userId];

    if ($month)  { $where[] = "DATE_FORMAT(j.entry_date,'%Y-%m') = ?"; $params[] = $month; }
    if ($mood)   { $where[] = 'j.mood = ?';                             $params[] = $mood;  }
    if ($search) { $where[] = '(j.title LIKE ? OR j.body LIKE ?)';
                   $params[] = "%$search%"; $params[] = "%$search%"; }
    if ($tag)    { $where[] = "JSON_SEARCH(j.tags, 'one', ?) IS NOT NULL"; $params[] = $tag; }

    $whereStr = implode(' AND ', $where);

    $cntStmt = $db->prepare("SELECT COUNT(*) FROM investment_journal j WHERE $whereStr");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    $stmt = $db->prepare("
        SELECT
          j.id, j.entry_date, j.title, j.body, j.mood, j.tags,
          j.portfolio_change_pct, j.nifty_change_pct, j.created_at,
          f.fund_name AS linked_fund_name
        FROM investment_journal j
        LEFT JOIN funds f ON f.id = j.linked_fund_id
        WHERE $whereStr
        ORDER BY j.entry_date DESC, j.id DESC
        LIMIT $limit OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($rows as &$row) {
        $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
        $row['body_preview'] = mb_strlen($row['body']) > 200
            ? mb_substr($row['body'], 0, 200) . '…'
            : $row['body'];
    }

    // All unique tags for filter
    $tagStmt = $db->prepare("
        SELECT DISTINCT jt.tag
        FROM investment_journal j,
             JSON_TABLE(IFNULL(j.tags,'[]'), '$[*]' COLUMNS (tag VARCHAR(100) PATH '$')) AS jt
        WHERE j.user_id=?
        ORDER BY jt.tag
    ");
    $tagStmt->execute([$userId]);
    $allTags = $tagStmt->fetchAll(PDO::FETCH_COLUMN);

    echo json_encode([
        'success' => true,
        'data'    => [
            'rows'   => $rows,
            'total'  => $total,
            'page'   => $page,
            'pages'  => (int)ceil($total / $limit),
            'all_tags' => $allTags,
        ]
    ]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// journal_add — create new entry
// ══════════════════════════════════════════════════════════════════════════
case 'journal_add':
    $entryDate = trim($_POST['entry_date'] ?? date('Y-m-d'));
    $title     = trim($_POST['title']      ?? '');
    $body      = trim($_POST['body']       ?? '');
    $mood      = $_POST['mood'] ?? 'neutral';
    $tagsRaw   = trim($_POST['tags']       ?? '[]');
    $fundId    = (int)($_POST['linked_fund_id'] ?? 0) ?: null;
    $portChg   = isset($_POST['portfolio_change_pct']) ? (float)$_POST['portfolio_change_pct'] : null;
    $niftyChg  = isset($_POST['nifty_change_pct'])     ? (float)$_POST['nifty_change_pct']     : null;

    if (!$body) { echo json_encode(['success'=>false,'error'=>'Body is required']); break; }

    $validMoods = ['bullish','bearish','neutral','anxious','confident'];
    if (!in_array($mood, $validMoods)) $mood = 'neutral';

    // Parse tags — accept comma-separated or JSON
    $tags = [];
    if ($tagsRaw) {
        $decoded = json_decode($tagsRaw, true);
        if (is_array($decoded)) {
            $tags = array_map('trim', $decoded);
        } else {
            $tags = array_map('trim', explode(',', $tagsRaw));
        }
        $tags = array_filter($tags);
        $tags = array_slice(array_unique($tags), 0, 10); // max 10 tags
    }

    $stmt = $db->prepare("
        INSERT INTO investment_journal
          (user_id, entry_date, title, body, mood, tags, linked_fund_id, portfolio_change_pct, nifty_change_pct)
        VALUES (?,?,?,?,?,?,?,?,?)
    ");
    $stmt->execute([
        $userId, $entryDate, $title, $body, $mood,
        json_encode(array_values($tags)), $fundId, $portChg, $niftyChg
    ]);
    echo json_encode(['success'=>true, 'id'=>(int)$db->lastInsertId()]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// journal_edit — update entry
// ══════════════════════════════════════════════════════════════════════════
case 'journal_edit':
    $id       = (int)($_POST['id'] ?? 0);
    $title    = trim($_POST['title'] ?? '');
    $body     = trim($_POST['body']  ?? '');
    $mood     = $_POST['mood'] ?? 'neutral';
    $tagsRaw  = trim($_POST['tags']  ?? '[]');

    if (!$id || !$body) { echo json_encode(['success'=>false,'error'=>'ID and body required']); break; }

    // Ownership check
    $own = $db->prepare("SELECT id FROM investment_journal WHERE id=? AND user_id=?");
    $own->execute([$id, $userId]);
    if (!$own->fetch()) { echo json_encode(['success'=>false,'error'=>'Not found']); break; }

    $validMoods = ['bullish','bearish','neutral','anxious','confident'];
    if (!in_array($mood, $validMoods)) $mood = 'neutral';

    $tags = [];
    if ($tagsRaw) {
        $decoded = json_decode($tagsRaw, true);
        $tags = is_array($decoded) ? $decoded : array_map('trim', explode(',', $tagsRaw));
        $tags = array_slice(array_unique(array_filter(array_map('trim', $tags))), 0, 10);
    }

    $stmt = $db->prepare("
        UPDATE investment_journal
        SET title=?, body=?, mood=?, tags=?
        WHERE id=? AND user_id=?
    ");
    $stmt->execute([$title, $body, $mood, json_encode(array_values($tags)), $id, $userId]);
    echo json_encode(['success'=>true]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// journal_delete
// ══════════════════════════════════════════════════════════════════════════
case 'journal_delete':
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) { echo json_encode(['success'=>false,'error'=>'ID required']); break; }
    $stmt = $db->prepare("DELETE FROM investment_journal WHERE id=? AND user_id=?");
    $stmt->execute([$id, $userId]);
    echo json_encode(['success'=>true]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// journal_calendar — dates that have entries (for calendar heatmap)
// ══════════════════════════════════════════════════════════════════════════
case 'journal_calendar':
    $year  = (int)($_POST['year']  ?? $_GET['year']  ?? date('Y'));
    $month = (int)($_POST['month'] ?? $_GET['month'] ?? 0); // 0 = all year

    $params = [$userId, $year];
    $monthCond = '';
    if ($month > 0) { $monthCond = 'AND MONTH(j.entry_date) = ?'; $params[] = $month; }

    $stmt = $db->prepare("
        SELECT
          j.entry_date,
          COUNT(*) AS entry_count,
          GROUP_CONCAT(j.mood ORDER BY j.id) AS moods
        FROM investment_journal j
        WHERE j.user_id=? AND YEAR(j.entry_date)=? $monthCond
        GROUP BY j.entry_date
        ORDER BY j.entry_date
    ");
    $stmt->execute($params);
    $calendar = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Stats
    $statsStmt = $db->prepare("
        SELECT
          COUNT(*) AS total_entries,
          COUNT(DISTINCT entry_date) AS journaled_days,
          SUM(CASE WHEN mood='bullish' THEN 1 ELSE 0 END) AS bullish_count,
          SUM(CASE WHEN mood='bearish' THEN 1 ELSE 0 END) AS bearish_count,
          SUM(CASE WHEN mood='neutral' THEN 1 ELSE 0 END) AS neutral_count,
          MIN(entry_date) AS first_entry,
          MAX(entry_date) AS last_entry
        FROM investment_journal WHERE user_id=?
    ");
    $statsStmt->execute([$userId]);
    $stats = $statsStmt->fetch(PDO::FETCH_ASSOC);

    echo json_encode(['success'=>true,'data'=>['calendar'=>$calendar,'stats'=>$stats]]);
    break;

// ══════════════════════════════════════════════════════════════════════════
// journal_get — single entry detail
// ══════════════════════════════════════════════════════════════════════════
case 'journal_get':
    $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
    $stmt = $db->prepare("
        SELECT j.*, f.fund_name AS linked_fund_name
        FROM investment_journal j
        LEFT JOIN funds f ON f.id=j.linked_fund_id
        WHERE j.id=? AND j.user_id=?
    ");
    $stmt->execute([$id, $userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) { echo json_encode(['success'=>false,'error'=>'Not found']); break; }
    $row['tags'] = $row['tags'] ? json_decode($row['tags'], true) : [];
    echo json_encode(['success'=>true,'data'=>$row]);
    break;

default:
    echo json_encode(['success'=>false,'error'=>"Unknown action: $action"]);
}
