<?php
/**
 * WealthDash — th001: Daily Financial Journal — Investment Notes
 * File: api/journal/journal.php
 * Actions: journal_list, journal_add, journal_update, journal_delete,
 *          journal_search, journal_stats, journal_mood_chart
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

function _mood_emojis(): array {
    return ['confident'=>'😎','optimistic'=>'🙂','neutral'=>'😐','anxious'=>'😟','fearful'=>'😨','excited'=>'🤩','regretful'=>'😔'];
}

switch ($action) {

    // ── List entries (paginated, optional month filter) ─────────────
    case 'djournal_list': {
        $month = clean($_GET['month'] ?? '');
        $page  = max(1, (int)($_GET['page'] ?? 1));
        $limit = 20;
        $offset = ($page-1)*$limit;

        $where = "user_id=?"; $params=[$userId];
        if ($month) { $where .= " AND DATE_FORMAT(entry_date,'%Y-%m')=?"; $params[]=$month; }

        $total = (int)(DB::fetchVal("SELECT COUNT(*) FROM journal_entries WHERE $where", $params) ?? 0);
        $rows = DB::fetchAll("SELECT * FROM journal_entries WHERE $where ORDER BY entry_date DESC, id DESC LIMIT $limit OFFSET $offset", $params);

        $emojis = _mood_emojis();
        foreach ($rows as &$r) {
            $r['mood_emoji'] = $emojis[$r['mood']] ?? '📝';
            $r['tags'] = $r['tags'] ? explode(',', $r['tags']) : [];
        }

        json_response(true,'ok',['entries'=>$rows,'total'=>$total,'page'=>$page,'total_pages'=>max(1,ceil($total/$limit))]);
        break;
    }

    // ── Add entry ───────────────────────────────────────────────────────
    case 'djournal_add': {
        csrf_verify();
        $entryDate = clean($_POST['entry_date'] ?? date('Y-m-d'));
        $title     = clean($_POST['title']      ?? '');
        $content   = clean($_POST['content']    ?? '');
        $mood      = clean($_POST['mood']       ?? 'neutral');
        $tags      = clean($_POST['tags']       ?? ''); // comma-separated
        $relatedAction = clean($_POST['related_action'] ?? ''); // e.g. 'bought_fund', 'redeemed', 'market_event'

        if (!$content) json_response(false, 'Journal content cannot be empty.');
        if (!in_array($mood, array_keys(_mood_emojis()))) $mood = 'neutral';

        DB::execute(
            "INSERT INTO journal_entries(user_id,entry_date,title,content,mood,tags,related_action,created_at)
             VALUES(?,?,?,?,?,?,?,NOW())",
            [$userId, $entryDate, $title ?: 'Journal Entry', $content, $mood, $tags, $relatedAction]
        );

        json_response(true, 'Journal entry saved.', ['id' => DB::lastInsertId()]);
        break;
    }

    case 'djournal_update': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM journal_entries WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Not found.');

        $sets=[]; $params=[];
        foreach (['title','content','mood','tags','entry_date','related_action'] as $f) {
            if (isset($_POST[$f])) { $sets[]="$f=?"; $params[]=clean($_POST[$f]); }
        }
        if (!$sets) json_response(false, 'Nothing to update.');
        $params[] = $id;
        DB::execute("UPDATE journal_entries SET ".implode(',',$sets).",updated_at=NOW() WHERE id=?", $params);
        json_response(true, 'Updated.');
        break;
    }

    case 'djournal_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $own = DB::fetchVal("SELECT id FROM journal_entries WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$own) json_response(false, 'Not found.');
        DB::execute("DELETE FROM journal_entries WHERE id=?", [$id]);
        json_response(true, 'Deleted.');
        break;
    }

    // ── Search entries by keyword ────────────────────────────────────
    case 'djournal_search': {
        $q = clean($_GET['q'] ?? '');
        if (!$q) json_response(false, 'Search query required.');
        $rows = DB::fetchAll(
            "SELECT * FROM journal_entries WHERE user_id=? AND (title LIKE ? OR content LIKE ? OR tags LIKE ?) ORDER BY entry_date DESC LIMIT 30",
            [$userId, "%$q%", "%$q%", "%$q%"]
        );
        $emojis = _mood_emojis();
        foreach ($rows as &$r) { $r['mood_emoji'] = $emojis[$r['mood']] ?? '📝'; $r['tags'] = $r['tags'] ? explode(',', $r['tags']) : []; }
        json_response(true,'ok',['entries'=>$rows]);
        break;
    }

    // ── Stats: entry count, streak, mood distribution ────────────────
    case 'djournal_stats': {
        $total = (int)(DB::fetchVal("SELECT COUNT(*) FROM journal_entries WHERE user_id=?", [$userId]) ?? 0);

        $moodCounts = DB::fetchAll("SELECT mood, COUNT(*) AS count FROM journal_entries WHERE user_id=? GROUP BY mood", [$userId]);

        // Journaling streak (consecutive days with an entry)
        $dates = DB::fetchAll("SELECT DISTINCT entry_date FROM journal_entries WHERE user_id=? ORDER BY entry_date DESC LIMIT 60", [$userId]);
        $dateSet = array_column($dates, 'entry_date');
        $streak = 0; $cursor = date('Y-m-d');
        while (in_array($cursor, $dateSet)) {
            $streak++;
            $cursor = date('Y-m-d', strtotime("$cursor -1 day"));
        }

        $thisMonthCount = (int)(DB::fetchVal("SELECT COUNT(*) FROM journal_entries WHERE user_id=? AND DATE_FORMAT(entry_date,'%Y-%m')=?", [$userId, date('Y-m')]) ?? 0);

        json_response(true,'ok',[
            'total_entries' => $total,
            'this_month'    => $thisMonthCount,
            'streak_days'   => $streak,
            'mood_distribution' => $moodCounts,
        ]);
        break;
    }

    // ── Mood over time (for chart) ────────────────────────────────────
    case 'djournal_mood_chart': {
        $rows = DB::fetchAll(
            "SELECT entry_date, mood FROM journal_entries WHERE user_id=? ORDER BY entry_date ASC LIMIT 90",
            [$userId]
        );
        $moodScore = ['excited'=>3,'confident'=>2,'optimistic'=>1,'neutral'=>0,'anxious'=>-1,'fearful'=>-2,'regretful'=>-1];
        $chartData = array_map(fn($r) => ['date'=>$r['entry_date'], 'score'=>$moodScore[$r['mood']]??0, 'mood'=>$r['mood']], $rows);
        json_response(true,'ok',['data'=>$chartData]);
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
