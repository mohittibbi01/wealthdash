<?php
/**
 * WealthDash — MF Fund Notes API
 * t182: Personal notes per fund
 *
 * GET  ?action=fund_notes_get              → all notes for user
 * POST action=fund_note_save               → upsert a note
 * POST action=fund_note_delete             → delete a note
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

// Ensure table exists (auto-migrate)
$db->exec("
    CREATE TABLE IF NOT EXISTS `mf_fund_notes` (
        `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `user_id`    INT UNSIGNED NOT NULL,
        `fund_id`    INT UNSIGNED NOT NULL,
        `note`       TEXT,
        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `user_fund` (`user_id`,`fund_id`),
        KEY `fk_mfnotes_fund` (`fund_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
");

switch ($action) {

    // ── GET all notes ────────────────────────────────────────
    case 'fund_notes_get':
        $rows = $db->prepare("
            SELECT n.fund_id, n.note, n.updated_at, f.scheme_name
            FROM mf_fund_notes n
            JOIN funds f ON f.id = n.fund_id
            WHERE n.user_id = ?
            ORDER BY n.updated_at DESC
        ");
        $rows->execute([$userId]);
        $notes = [];
        foreach ($rows->fetchAll() as $r) {
            $notes[$r['fund_id']] = [
                'fund_id'    => (int)$r['fund_id'],
                'scheme_name'=> $r['scheme_name'],
                'note'       => $r['note'],
                'updated_at' => $r['updated_at'],
            ];
        }
        json_response(true, '', ['notes' => $notes]);
        break;

    // ── SAVE (upsert) note ───────────────────────────────────
    case 'fund_note_save':
        $fundId = (int)($_POST['fund_id'] ?? 0);
        $note   = trim($_POST['note'] ?? '');
        if (!$fundId) json_response(false, 'fund_id required.');

        // Verify fund exists
        $f = $db->prepare("SELECT id FROM funds WHERE id = ?");
        $f->execute([$fundId]);
        if (!$f->fetch()) json_response(false, 'Fund not found.');

        if ($note === '') {
            // Delete if empty
            $db->prepare("DELETE FROM mf_fund_notes WHERE user_id = ? AND fund_id = ?")
               ->execute([$userId, $fundId]);
            json_response(true, 'Note deleted.');
        } else {
            $db->prepare("
                INSERT INTO mf_fund_notes (user_id, fund_id, note, updated_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE note = VALUES(note), updated_at = NOW()
            ")->execute([$userId, $fundId, $note]);
            json_response(true, 'Note saved.');
        }
        break;

    // ── DELETE note ──────────────────────────────────────────
    case 'fund_note_delete':
        $fundId = (int)($_POST['fund_id'] ?? 0);
        if (!$fundId) json_response(false, 'fund_id required.');
        $db->prepare("DELETE FROM mf_fund_notes WHERE user_id = ? AND fund_id = ?")
           ->execute([$userId, $fundId]);
        json_response(true, 'Note deleted.');
        break;

    default:
        json_response(false, 'Unknown action.');
}
