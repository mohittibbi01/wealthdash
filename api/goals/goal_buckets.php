<?php
/**
 * WealthDash — Goal-Based Buckets API
 * Task t139: Retirement · Education · Emergency buckets
 *
 * Actions (read-only — CSRF exempt):
 *   bucket_list        — list all buckets for current user
 *   bucket_progress    — progress + projections for one bucket
 *   bucket_summary     — aggregate summary across all buckets
 *
 * Actions (write — CSRF required):
 *   bucket_add         — create new bucket
 *   bucket_edit        — update bucket
 *   bucket_delete      — delete bucket
 *   bucket_contribute  — log a contribution
 *   bucket_link_asset  — link fund/sip to bucket
 *   bucket_unlink_asset
 *   bucket_mark_achieved
 */
defined('WEALTHDASH') or die();

$userId = (int)$_SESSION['user_id'];
$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

// ── BUCKET TYPE META ─────────────────────────────────────────────────────────
const BUCKET_TYPES = [
    'retirement' => ['label' => 'Retirement',   'emoji' => '🏖️',  'color' => '#6366f1', 'risk' => 'aggressive'],
    'education'  => ['label' => 'Education',    'emoji' => '🎓',  'color' => '#0ea5e9', 'risk' => 'moderate'],
    'emergency'  => ['label' => 'Emergency',    'emoji' => '🚨',  'color' => '#ef4444', 'risk' => 'conservative'],
    'house'      => ['label' => 'House/Property','emoji'=> '🏠',  'color' => '#f59e0b', 'risk' => 'moderate'],
    'vehicle'    => ['label' => 'Vehicle',       'emoji'=> '🚗',  'color' => '#10b981', 'risk' => 'moderate'],
    'travel'     => ['label' => 'Travel',        'emoji'=> '✈️',  'color' => '#8b5cf6', 'risk' => 'moderate'],
    'wedding'    => ['label' => 'Wedding',       'emoji'=> '💍',  'color' => '#ec4899', 'risk' => 'moderate'],
    'custom'     => ['label' => 'Custom',        'emoji'=> '🎯',  'color' => '#64748b', 'risk' => 'moderate'],
];

// ── HELPERS ───────────────────────────────────────────────────────────────────
function _bucket_owned(int $userId, int $bucketId): bool {
    $row = DB::fetchOne(
        "SELECT id FROM goal_buckets WHERE id=? AND user_id=?",
        [$bucketId, $userId]
    );
    return (bool)$row;
}

function _calc_projection(float $current, float $monthly, float $target, float $returnPct, string $targetDate): array {
    $monthsLeft   = max(1, (int)ceil((strtotime($targetDate) - time()) / (30.44 * 86400)));
    $r            = ($returnPct / 100) / 12;
    $fvCurrent    = $r > 0 ? $current * pow(1 + $r, $monthsLeft) : $current;
    $fvSip        = $r > 0
        ? $monthly * ((pow(1 + $r, $monthsLeft) - 1) / $r) * (1 + $r)
        : $monthly * $monthsLeft;
    $projected    = round($fvCurrent + $fvSip, 2);
    $shortfall    = max(0, $target - $projected);
    $pct          = $target > 0 ? min(100, round($current / $target * 100, 1)) : 0;
    // Required monthly SIP to reach target from current corpus
    $reqMonthly   = 0;
    if ($r > 0 && $monthsLeft > 0 && $target > $fvCurrent) {
        $need = $target - $fvCurrent;
        $reqMonthly = round($need * $r / (((pow(1 + $r, $monthsLeft) - 1) / $r) * (1 + $r)), 2);
    }
    return [
        'months_left'       => $monthsLeft,
        'projected_value'   => $projected,
        'shortfall'         => $shortfall,
        'on_track'          => $shortfall <= 0,
        'progress_pct'      => $pct,
        'required_monthly'  => max(0, $reqMonthly),
    ];
}

// ── ACTIONS ───────────────────────────────────────────────────────────────────

switch ($action) {

    // ── bucket_list ──────────────────────────────────────────────────────────
    case 'bucket_list': {
        $buckets = DB::fetchAll(
            "SELECT b.*,
                    (SELECT COALESCE(SUM(c.amount),0) FROM goal_bucket_contributions c WHERE c.bucket_id = b.id) AS contributed,
                    (SELECT COUNT(*) FROM goal_fund_links l WHERE l.goal_id = b.id) AS linked_count
             FROM goal_buckets b
             WHERE b.user_id = ?
             ORDER BY FIELD(b.bucket_type,'retirement','education','emergency','house','vehicle','travel','wedding','custom'), b.priority DESC, b.created_at ASC",
            [$userId]
        );

        foreach ($buckets as &$b) {
            $meta          = BUCKET_TYPES[$b['bucket_type']] ?? BUCKET_TYPES['custom'];
            $b['type_label'] = $meta['label'];
            $b['current_amount'] = (float)($b['current_amount'] ?? 0) + (float)($b['contributed'] ?? 0);
            $b['target_amount']  = (float)$b['target_amount'];
            $b['monthly_target'] = (float)$b['monthly_target'];
            $b['progress_pct']   = $b['target_amount'] > 0
                ? min(100, round($b['current_amount'] / $b['target_amount'] * 100, 1))
                : 0;
        }
        unset($b);

        json_response(true, '', ['buckets' => $buckets, 'types' => BUCKET_TYPES]);
    }

    // ── bucket_summary ───────────────────────────────────────────────────────
    case 'bucket_summary': {
        $rows = DB::fetchAll(
            "SELECT b.bucket_type,
                    COUNT(*) AS count,
                    SUM(b.target_amount) AS total_target,
                    SUM(b.current_amount) AS total_current,
                    SUM(b.monthly_target) AS total_monthly
             FROM goal_buckets b
             WHERE b.user_id = ? AND b.is_achieved = 0
             GROUP BY b.bucket_type",
            [$userId]
        );

        $totalTarget  = array_sum(array_column($rows, 'total_target'));
        $totalCurrent = array_sum(array_column($rows, 'total_current'));
        $totalMonthly = array_sum(array_column($rows, 'total_monthly'));

        json_response(true, '', [
            'by_type'       => $rows,
            'total_target'  => (float)$totalTarget,
            'total_current' => (float)$totalCurrent,
            'total_monthly' => (float)$totalMonthly,
            'overall_pct'   => $totalTarget > 0 ? min(100, round($totalCurrent / $totalTarget * 100, 1)) : 0,
        ]);
    }

    // ── bucket_progress ──────────────────────────────────────────────────────
    case 'bucket_progress': {
        $bucketId = (int)($_POST['bucket_id'] ?? $_GET['bucket_id'] ?? 0);
        if (!$bucketId || !_bucket_owned($userId, $bucketId)) {
            json_response(false, 'Bucket not found.', [], 404);
        }

        $b = DB::fetchOne("SELECT * FROM goal_buckets WHERE id=?", [$bucketId]);
        $returnPct   = (float)($_POST['return_pct'] ?? match($b['risk_profile']) {
            'conservative' => 6.5,
            'aggressive'   => 13.0,
            default        => 10.0,
        });

        $contributed = (float)DB::fetchVal(
            "SELECT COALESCE(SUM(amount),0) FROM goal_bucket_contributions WHERE bucket_id=?",
            [$bucketId]
        );
        $current  = (float)$b['current_amount'] + $contributed;
        $target   = (float)$b['target_amount'];
        $monthly  = (float)$b['monthly_target'];
        $projDate = $b['target_date'] ?? date('Y-m-d', strtotime('+10 years'));

        $proj = _calc_projection($current, $monthly, $target, $returnPct, $projDate);

        // Contribution history (last 12)
        $history = DB::fetchAll(
            "SELECT * FROM goal_bucket_contributions WHERE bucket_id=? ORDER BY contribution_date DESC LIMIT 12",
            [$bucketId]
        );

        // Linked assets
        $links = DB::fetchAll(
            "SELECT l.*, f.fund_name, s.fund_name AS sip_fund_name, s.monthly_amount
             FROM goal_fund_links l
             LEFT JOIN funds f ON f.id = l.fund_id
             LEFT JOIN sip_investments s ON s.id = l.sip_id
             WHERE l.goal_id = ?",
            [$bucketId]
        );

        json_response(true, '', [
            'bucket'      => $b,
            'current'     => $current,
            'projection'  => $proj,
            'history'     => $history,
            'links'       => $links,
            'return_pct'  => $returnPct,
        ]);
    }

    // ── bucket_add ───────────────────────────────────────────────────────────
    case 'bucket_add': {
        $name        = trim(clean($_POST['name'] ?? ''));
        $bucketType  = clean($_POST['bucket_type'] ?? 'custom');
        $target      = (float)($_POST['target_amount'] ?? 0);
        $targetDate  = clean($_POST['target_date'] ?? '');
        $monthly     = (float)($_POST['monthly_target'] ?? 0);
        $risk        = clean($_POST['risk_profile'] ?? 'moderate');
        $priority    = clean($_POST['priority'] ?? 'medium');
        $color       = clean($_POST['color'] ?? BUCKET_TYPES[$bucketType]['color'] ?? '#6366f1');
        $emoji       = clean($_POST['emoji'] ?? BUCKET_TYPES[$bucketType]['emoji'] ?? '🎯');
        $notes       = trim(clean($_POST['notes'] ?? ''));

        if (!$name)   json_response(false, 'Name required.');
        if ($target < 0) json_response(false, 'Invalid target amount.');
        if (!array_key_exists($bucketType, BUCKET_TYPES)) $bucketType = 'custom';
        if (!in_array($risk, ['conservative','moderate','aggressive'])) $risk = 'moderate';
        if (!in_array($priority, ['high','medium','low'])) $priority = 'medium';
        if ($targetDate && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $targetDate)) $targetDate = null;

        $id = DB::insert(
            "INSERT INTO goal_buckets
               (user_id, name, bucket_type, emoji, color, target_amount, target_date,
                monthly_target, risk_profile, priority, notes)
             VALUES (?,?,?,?,?,?,?,?,?,?,?)",
            [$userId, $name, $bucketType, $emoji, $color, $target,
             $targetDate ?: null, $monthly, $risk, $priority, $notes ?: null]
        );

        json_response(true, 'Bucket created.', ['id' => $id]);
    }

    // ── bucket_edit ──────────────────────────────────────────────────────────
    case 'bucket_edit': {
        $bucketId = (int)($_POST['bucket_id'] ?? 0);
        if (!$bucketId || !_bucket_owned($userId, $bucketId)) {
            json_response(false, 'Bucket not found.', [], 404);
        }

        $fields = [];
        $params = [];

        $map = [
            'name'           => 'string',
            'bucket_type'    => 'string',
            'emoji'          => 'string',
            'color'          => 'string',
            'target_amount'  => 'float',
            'target_date'    => 'string',
            'monthly_target' => 'float',
            'risk_profile'   => 'string',
            'priority'       => 'string',
            'notes'          => 'string',
        ];

        foreach ($map as $col => $type) {
            if (!isset($_POST[$col])) continue;
            $val = clean($_POST[$col]);
            if ($type === 'float') $val = (float)$val;
            $fields[] = "`$col`=?";
            $params[] = $val ?: null;
        }

        if (!$fields) json_response(false, 'Nothing to update.');

        $params[] = $bucketId;
        DB::run("UPDATE goal_buckets SET " . implode(',', $fields) . " WHERE id=?", $params);
        json_response(true, 'Bucket updated.');
    }

    // ── bucket_delete ────────────────────────────────────────────────────────
    case 'bucket_delete': {
        $bucketId = (int)($_POST['bucket_id'] ?? 0);
        if (!$bucketId || !_bucket_owned($userId, $bucketId)) {
            json_response(false, 'Bucket not found.', [], 404);
        }
        DB::run("DELETE FROM goal_buckets WHERE id=? AND user_id=?", [$bucketId, $userId]);
        json_response(true, 'Bucket deleted.');
    }

    // ── bucket_contribute ────────────────────────────────────────────────────
    case 'bucket_contribute': {
        $bucketId = (int)($_POST['bucket_id'] ?? 0);
        $amount   = (float)($_POST['amount'] ?? 0);
        $date     = clean($_POST['date'] ?? date('Y-m-d'));
        $note     = trim(clean($_POST['note'] ?? ''));

        if (!$bucketId || !_bucket_owned($userId, $bucketId)) {
            json_response(false, 'Bucket not found.', [], 404);
        }
        if ($amount <= 0) json_response(false, 'Invalid amount.');

        $id = DB::insert(
            "INSERT INTO goal_bucket_contributions (bucket_id, amount, contribution_date, note) VALUES (?,?,?,?)",
            [$bucketId, $amount, $date, $note ?: null]
        );

        // Update current_amount on bucket
        DB::run(
            "UPDATE goal_buckets SET current_amount = current_amount + ? WHERE id=?",
            [$amount, $bucketId]
        );

        // Auto-mark achieved if target reached
        $b = DB::fetchOne("SELECT target_amount, current_amount FROM goal_buckets WHERE id=?", [$bucketId]);
        if ((float)$b['current_amount'] >= (float)$b['target_amount'] && (float)$b['target_amount'] > 0) {
            DB::run(
                "UPDATE goal_buckets SET is_achieved=1, achieved_at=CURDATE() WHERE id=? AND is_achieved=0",
                [$bucketId]
            );
        }

        json_response(true, 'Contribution saved.', ['contribution_id' => $id]);
    }

    // ── bucket_link_asset ────────────────────────────────────────────────────
    case 'bucket_link_asset': {
        $bucketId  = (int)($_POST['bucket_id'] ?? 0);
        $fundId    = (int)($_POST['fund_id'] ?? 0) ?: null;
        $sipId     = (int)($_POST['sip_id'] ?? 0) ?: null;
        $linkType  = clean($_POST['link_type'] ?? 'holding');

        if (!$bucketId || !_bucket_owned($userId, $bucketId)) {
            json_response(false, 'Bucket not found.', [], 404);
        }
        if (!$fundId && !$sipId) json_response(false, 'fund_id or sip_id required.');

        // Prevent duplicate links
        $exists = DB::fetchVal(
            "SELECT id FROM goal_fund_links WHERE goal_id=? AND fund_id<=>? AND sip_id<=>?",
            [$bucketId, $fundId, $sipId]
        );
        if ($exists) json_response(false, 'Asset already linked.');

        DB::insert(
            "INSERT INTO goal_fund_links (goal_id, fund_id, sip_id, link_type) VALUES (?,?,?,?)",
            [$bucketId, $fundId, $sipId, $linkType]
        );
        json_response(true, 'Asset linked.');
    }

    // ── bucket_unlink_asset ──────────────────────────────────────────────────
    case 'bucket_unlink_asset': {
        $linkId   = (int)($_POST['link_id'] ?? 0);
        $bucketId = (int)($_POST['bucket_id'] ?? 0);

        if (!$linkId) json_response(false, 'link_id required.');
        // Ownership: ensure link belongs to one of user's buckets
        $owned = DB::fetchVal(
            "SELECT l.id FROM goal_fund_links l
             JOIN goal_buckets b ON b.id = l.goal_id
             WHERE l.id=? AND b.user_id=?",
            [$linkId, $userId]
        );
        if (!$owned) json_response(false, 'Link not found.', [], 404);

        DB::run("DELETE FROM goal_fund_links WHERE id=?", [$linkId]);
        json_response(true, 'Asset unlinked.');
    }

    // ── bucket_mark_achieved ─────────────────────────────────────────────────
    case 'bucket_mark_achieved': {
        $bucketId = (int)($_POST['bucket_id'] ?? 0);
        $achieved = (int)($_POST['achieved'] ?? 1);

        if (!$bucketId || !_bucket_owned($userId, $bucketId)) {
            json_response(false, 'Bucket not found.', [], 404);
        }

        if ($achieved) {
            DB::run(
                "UPDATE goal_buckets SET is_achieved=1, achieved_at=CURDATE() WHERE id=?",
                [$bucketId]
            );
        } else {
            DB::run(
                "UPDATE goal_buckets SET is_achieved=0, achieved_at=NULL WHERE id=?",
                [$bucketId]
            );
        }
        json_response(true, $achieved ? 'Marked as achieved 🎉' : 'Reopened.');
    }

    default:
        json_response(false, 'Unknown action: ' . $action, [], 400);
}
