<?php
/**
 * WealthDash — Data Validation Rules Engine
 * Task: t480
 * Worker: ID-M
 *
 * Actions:
 *   validate_entry        — Validate a set of fields before insert (returns errors)
 *   validation_rules_list — List all active rules (optionally filter by asset_type)
 *   validation_rule_save  — Admin: add/update a rule
 *   validation_rule_toggle— Admin: enable/disable a rule
 *   validation_rule_delete— Admin: delete a custom rule
 *   validation_scan       — Scan existing data for violations
 *   validation_violations — List open violations for a portfolio
 *   validation_resolve    — Mark a violation as resolved
 */
defined('WEALTHDASH') or die();

switch ($action) {

    // ── Validate before insert/update ────────────────────────────
    case 'validate_entry':
        $assetType = clean($_POST['asset_type'] ?? '');
        $fields    = $_POST['fields'] ?? [];   // assoc array fieldName => value

        if (!$assetType || empty($fields)) {
            json_response(false, 'asset_type and fields required.');
        }

        $errors = _wd_validate_fields($assetType, $fields);
        json_response(true, '', [
            'valid'  => empty($errors),
            'errors' => $errors,
        ]);

    // ── List rules ────────────────────────────────────────────────
    case 'validation_rules_list':
        $assetType = clean($_POST['asset_type'] ?? '');
        if ($assetType) {
            $rules = DB::fetchAll(
                "SELECT * FROM validation_rules WHERE (asset_type=? OR asset_type='all') AND is_active=1 ORDER BY asset_type, field_name",
                [$assetType]
            );
        } else {
            $rules = DB::fetchAll("SELECT * FROM validation_rules ORDER BY asset_type, field_name");
        }
        json_response(true, '', ['rules' => $rules]);

    // ── Admin: Save (add or edit) a rule ─────────────────────────
    case 'validation_rule_save':
        if (!$isAdmin) json_response(false, 'Admin only.', [], 403);

        $id         = (int)($_POST['id'] ?? 0);
        $ruleKey    = clean($_POST['rule_key'] ?? '');
        $assetType  = clean($_POST['asset_type'] ?? '');
        $fieldName  = clean($_POST['field_name'] ?? '');
        $ruleType   = clean($_POST['rule_type'] ?? '');
        $ruleValue  = clean($_POST['rule_value'] ?? '');
        $errorMsg   = clean($_POST['error_msg'] ?? '');

        if (!$ruleKey || !$assetType || !$fieldName || !$ruleType || !$errorMsg) {
            json_response(false, 'rule_key, asset_type, field_name, rule_type, error_msg are required.');
        }

        $validTypes  = ['min','max','required','regex','enum','date_past','date_future','positive','nonzero'];
        $validAssets = ['mf','stocks','nps','fd','savings','gold','realestate','crypto','all'];
        if (!in_array($ruleType, $validTypes))  json_response(false, 'Invalid rule_type.');
        if (!in_array($assetType, $validAssets)) json_response(false, 'Invalid asset_type.');

        if ($id) {
            $old = DB::fetchOne("SELECT * FROM validation_rules WHERE id=?", [$id]);
            DB::run(
                "UPDATE validation_rules SET rule_key=?,asset_type=?,field_name=?,rule_type=?,rule_value=?,error_msg=? WHERE id=?",
                [$ruleKey, $assetType, $fieldName, $ruleType, $ruleValue ?: null, $errorMsg, $id]
            );
            audit_log('validation_rule_update', 'validation_rules', $id, $old, $_POST);
            json_response(true, 'Rule updated.');
        } else {
            DB::run(
                "INSERT INTO validation_rules (rule_key,asset_type,field_name,rule_type,rule_value,error_msg) VALUES (?,?,?,?,?,?)",
                [$ruleKey, $assetType, $fieldName, $ruleType, $ruleValue ?: null, $errorMsg]
            );
            $newId = (int)DB::conn()->lastInsertId();
            audit_log('validation_rule_create', 'validation_rules', $newId, [], $_POST);
            json_response(true, 'Rule created.', ['id' => $newId]);
        }

    // ── Admin: Toggle active ──────────────────────────────────────
    case 'validation_rule_toggle':
        if (!$isAdmin) json_response(false, 'Admin only.', [], 403);
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'id required.');
        DB::run("UPDATE validation_rules SET is_active = NOT is_active WHERE id=?", [$id]);
        json_response(true, 'Rule toggled.');

    // ── Admin: Delete ─────────────────────────────────────────────
    case 'validation_rule_delete':
        if (!$isAdmin) json_response(false, 'Admin only.', [], 403);
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) json_response(false, 'id required.');
        $old = DB::fetchOne("SELECT * FROM validation_rules WHERE id=?", [$id]);
        DB::run("DELETE FROM validation_rules WHERE id=?", [$id]);
        audit_log('validation_rule_delete', 'validation_rules', $id, $old, []);
        json_response(true, 'Rule deleted.');

    // ── Scan existing data for violations ────────────────────────
    case 'validation_scan':
        $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
        if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);
        if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Invalid portfolio.');
        }

        $found = 0;
        $found += _wd_scan_mf_violations($portfolioId);
        $found += _wd_scan_stock_violations($portfolioId);
        $found += _wd_scan_fd_violations($portfolioId);

        json_response(true, 'Scan complete.', ['violations_found' => $found]);

    // ── List violations ───────────────────────────────────────────
    case 'validation_violations':
        $portfolioId = (int)($_POST['portfolio_id'] ?? 0);
        if (!$portfolioId) $portfolioId = get_user_portfolio_id($userId);
        if (!$portfolioId || !can_access_portfolio($portfolioId, $userId, $isAdmin)) {
            json_response(false, 'Invalid portfolio.');
        }
        $assetFilter = clean($_POST['asset_type'] ?? '');
        $showResolved = (bool)($_POST['show_resolved'] ?? false);

        $sql    = "SELECT * FROM validation_violations WHERE portfolio_id=?";
        $params = [$portfolioId];
        if ($assetFilter) { $sql .= " AND asset_type=?"; $params[] = $assetFilter; }
        if (!$showResolved) { $sql .= " AND is_resolved=0"; }
        $sql .= " ORDER BY created_at DESC LIMIT 200";

        $rows = DB::fetchAll($sql, $params);
        $total = (int)DB::fetchVal(
            "SELECT COUNT(*) FROM validation_violations WHERE portfolio_id=? AND is_resolved=0",
            [$portfolioId]
        );
        json_response(true, '', ['violations' => $rows, 'open_count' => $total]);

    // ── Resolve a violation ───────────────────────────────────────
    case 'validation_resolve':
        $vid = (int)($_POST['violation_id'] ?? 0);
        if (!$vid) json_response(false, 'violation_id required.');
        $row = DB::fetchOne("SELECT * FROM validation_violations WHERE id=?", [$vid]);
        if (!$row) json_response(false, 'Violation not found.');
        if (!can_access_portfolio((int)$row['portfolio_id'], $userId, $isAdmin)) {
            json_response(false, 'Access denied.', [], 403);
        }
        DB::run("UPDATE validation_violations SET is_resolved=1, resolved_at=NOW() WHERE id=?", [$vid]);
        json_response(true, 'Violation resolved.');

    default:
        json_response(false, 'Unknown action.', [], 400);
}

// ────────────────────────────────────────────────────────────────
// CORE VALIDATOR — used by validate_entry action
// ────────────────────────────────────────────────────────────────

/**
 * Validate a fields array against DB rules.
 * Returns array of ['field'=>..., 'message'=>...] on failure, empty on pass.
 */
function _wd_validate_fields(string $assetType, array $fields): array {
    $rules = DB::fetchAll(
        "SELECT * FROM validation_rules
         WHERE (asset_type=? OR asset_type='all') AND is_active=1",
        [$assetType]
    );

    $errors = [];
    foreach ($rules as $rule) {
        $field = $rule['field_name'];
        if (!array_key_exists($field, $fields)) continue;

        $value = $fields[$field];
        $err   = _wd_apply_rule($rule, $value);
        if ($err) {
            $errors[] = ['field' => $field, 'message' => $err];
        }
    }
    return $errors;
}

/**
 * Apply a single rule to a value. Returns error string or null.
 */
function _wd_apply_rule(array $rule, mixed $value): ?string {
    $ruleType  = $rule['rule_type'];
    $ruleValue = $rule['rule_value'];
    $msg       = $rule['error_msg'];

    switch ($ruleType) {
        case 'required':
            return ($value === null || $value === '') ? $msg : null;

        case 'positive':
            return ((float)$value <= 0) ? $msg : null;

        case 'nonzero':
            return ((float)$value == 0) ? $msg : null;

        case 'min':
            return ((float)$value < (float)$ruleValue) ? $msg : null;

        case 'max':
            return ((float)$value > (float)$ruleValue) ? $msg : null;

        case 'regex':
            return (!preg_match($ruleValue, (string)$value)) ? $msg : null;

        case 'enum':
            $allowed = array_map('trim', explode(',', $ruleValue));
            return (!in_array((string)$value, $allowed, true)) ? $msg : null;

        case 'date_past':
            // Value must be a date <= today
            if (!$value) return $msg;
            try {
                $d = new DateTime($value);
                return ($d > new DateTime('today')) ? $msg : null;
            } catch (Exception) { return $msg; }

        case 'date_future':
            // Value must be a date >= today
            if (!$value) return $msg;
            try {
                $d = new DateTime($value);
                return ($d < new DateTime('today')) ? $msg : null;
            } catch (Exception) { return $msg; }
    }

    return null;
}

// ────────────────────────────────────────────────────────────────
// SCAN FUNCTIONS — write violations to DB
// ────────────────────────────────────────────────────────────────

function _wd_scan_mf_violations(int $portfolioId): int {
    $txns = DB::fetchAll(
        "SELECT * FROM mf_transactions WHERE portfolio_id=?",
        [$portfolioId]
    );
    $count = 0;
    foreach ($txns as $txn) {
        $fields = [
            'units'    => $txn['units'],
            'nav'      => $txn['nav'],
            'amount'   => $txn['amount'],
            'txn_date' => $txn['txn_date'],
            'txn_type' => $txn['txn_type'],
        ];
        $errors = _wd_validate_fields('mf', $fields);
        foreach ($errors as $err) {
            _wd_upsert_violation($portfolioId, 'mf', (int)$txn['id'], $err['field'], $fields[$err['field']] ?? '', $err['message']);
            $count++;
        }
    }
    return $count;
}

function _wd_scan_stock_violations(int $portfolioId): int {
    $txns = DB::fetchAll(
        "SELECT * FROM stock_transactions WHERE portfolio_id=?",
        [$portfolioId]
    );
    $count = 0;
    foreach ($txns as $txn) {
        $fields = [
            'quantity' => $txn['quantity'],
            'price'    => $txn['price'],
            'txn_date' => $txn['txn_date'],
            'txn_type' => $txn['txn_type'],
            'brokerage'=> $txn['brokerage'],
        ];
        $errors = _wd_validate_fields('stocks', $fields);
        foreach ($errors as $err) {
            _wd_upsert_violation($portfolioId, 'stocks', (int)$txn['id'], $err['field'], $fields[$err['field']] ?? '', $err['message']);
            $count++;
        }
    }
    return $count;
}

function _wd_scan_fd_violations(int $portfolioId): int {
    $fds   = DB::fetchAll("SELECT * FROM fd_accounts WHERE portfolio_id=?", [$portfolioId]);
    $count = 0;
    foreach ($fds as $fd) {
        $fields = [
            'principal'     => $fd['principal'],
            'interest_rate' => $fd['interest_rate'],
            'maturity_date' => $fd['maturity_date'],
        ];
        $errors = _wd_validate_fields('fd', $fields);
        foreach ($errors as $err) {
            _wd_upsert_violation($portfolioId, 'fd', (int)$fd['id'], $err['field'], $fields[$err['field']] ?? '', $err['message']);
            $count++;
        }
    }
    return $count;
}

function _wd_upsert_violation(int $pid, string $assetType, int $entityId, string $field, string $badValue, string $errMsg): void {
    // Avoid duplicate violation rows for same entity+field
    $existing = DB::fetchVal(
        "SELECT id FROM validation_violations WHERE portfolio_id=? AND asset_type=? AND entity_id=? AND field_name=? AND is_resolved=0",
        [$pid, $assetType, $entityId, $field]
    );
    if ($existing) return;

    DB::run(
        "INSERT INTO validation_violations (portfolio_id, asset_type, entity_id, field_name, bad_value, error_msg, rule_key)
         VALUES (?,?,?,?,?,?, (SELECT rule_key FROM validation_rules WHERE field_name=? AND asset_type IN(?,?'all') LIMIT 1))",
        [$pid, $assetType, $entityId, $field, substr((string)$badValue, 0, 499), $errMsg, $field, $assetType]
    );
}
