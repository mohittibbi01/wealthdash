<?php
/**
 * WealthDash — t150: DigiLocker Integration — Documents Ek Jagah
 * File: api/documents/digilocker.php
 * Actions: doc_list, doc_upload, doc_delete, doc_categories,
 *          digilocker_connect_status, digilocker_disconnect
 *
 * NOTE: True DigiLocker OAuth integration requires registering with
 * DigiLocker's API (api.digitallocker.gov.in) and getting partner
 * credentials. This implementation provides:
 *   1. Local secure document vault (upload/store financial documents)
 *   2. DigiLocker connection status placeholder (shows "Connect" CTA)
 *   3. Document categorization matching DigiLocker's standard doc types
 * When DigiLocker API credentials are available, _digilocker_oauth_url()
 * and the callback handler can be wired in (marked with TODO below).
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');
$userId = (int)$_SESSION['user_id'];

function _doc_categories(): array {
    return [
        'pan_card'        => ['label'=>'PAN Card',           'icon'=>'🪪'],
        'aadhaar'         => ['label'=>'Aadhaar Card',        'icon'=>'🪪'],
        'passport'        => ['label'=>'Passport',            'icon'=>'📘'],
        'driving_license' => ['label'=>'Driving License',     'icon'=>'🚗'],
        'insurance_policy'=> ['label'=>'Insurance Policy',    'icon'=>'🛡'],
        'property_papers' => ['label'=>'Property Documents',  'icon'=>'🏠'],
        'loan_agreement'  => ['label'=>'Loan Agreement',      'icon'=>'🏦'],
        'mf_statement'    => ['label'=>'MF Account Statement','icon'=>'📈'],
        'bank_statement'  => ['label'=>'Bank Statement',      'icon'=>'🏛'],
        'tax_document'    => ['label'=>'Tax Document (ITR/Form16)','icon'=>'🧾'],
        'will_nominee'    => ['label'=>'Will / Nominee Document','icon'=>'📜'],
        'other'           => ['label'=>'Other',                'icon'=>'📁'],
    ];
}

switch ($action) {

    case 'doc_categories': {
        json_response(true,'ok',['categories'=>_doc_categories()]);
        break;
    }

    // ── List documents (optionally filter by category) ───────────────
    case 'doc_list': {
        $cat = clean($_GET['category'] ?? '');
        $where = "user_id=?"; $params = [$userId];
        if ($cat) { $where .= " AND category=?"; $params[] = $cat; }
        $rows = DB::fetchAll("SELECT id,category,doc_name,file_size,uploaded_at,expiry_date,source FROM user_documents WHERE $where ORDER BY uploaded_at DESC", $params);
        $cats = _doc_categories();
        foreach ($rows as &$r) {
            $r['icon']  = $cats[$r['category']]['icon']  ?? '📁';
            $r['label'] = $cats[$r['category']]['label'] ?? 'Other';
            $r['file_size_kb'] = round($r['file_size']/1024, 1);
            $r['expiring_soon'] = $r['expiry_date'] && (strtotime($r['expiry_date']) - time()) < (30*86400) && strtotime($r['expiry_date']) > time();
        }
        json_response(true,'ok',['documents'=>$rows]);
        break;
    }

    // ── Upload a document (stored locally, encrypted path) ───────────
    case 'doc_upload': {
        csrf_verify();
        $category   = clean($_POST['category']    ?? 'other');
        $docName    = clean($_POST['doc_name']     ?? '');
        $expiryDate = clean($_POST['expiry_date']  ?? '') ?: null;

        if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
            json_response(false, 'File upload failed or no file selected.');
        }
        if (!$docName) $docName = $_FILES['document']['name'];

        $allowedExt = ['pdf','jpg','jpeg','png'];
        $ext = strtolower(pathinfo($_FILES['document']['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowedExt)) json_response(false, 'Only PDF, JPG, PNG files allowed.');

        $maxSize = 5 * 1024 * 1024; // 5MB
        if ($_FILES['document']['size'] > $maxSize) json_response(false, 'File too large (max 5MB).');

        $uploadDir = APP_ROOT . '/storage/documents/' . $userId;
        if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);

        $safeFilename = bin2hex(random_bytes(16)) . '.' . $ext;
        $destPath = $uploadDir . '/' . $safeFilename;

        if (!move_uploaded_file($_FILES['document']['tmp_name'], $destPath)) {
            json_response(false, 'Failed to save file.');
        }

        DB::execute(
            "INSERT INTO user_documents(user_id,category,doc_name,file_path,file_size,expiry_date,source,uploaded_at)
             VALUES(?,?,?,?,?,?,?,NOW())",
            [$userId, $category, $docName, $safeFilename, $_FILES['document']['size'], $expiryDate, 'manual_upload']
        );

        audit_log($userId, 'doc_upload', "Uploaded document: $docName ($category)");
        json_response(true, 'Document uploaded successfully.', ['id' => DB::lastInsertId()]);
        break;
    }

    // ── Delete a document ──────────────────────────────────────────────
    case 'doc_delete': {
        csrf_verify();
        $id = (int)($_POST['id'] ?? 0);
        $doc = DB::fetchRow("SELECT file_path FROM user_documents WHERE id=? AND user_id=?", [$id, $userId]);
        if (!$doc) json_response(false, 'Document not found.');

        $filePath = APP_ROOT . '/storage/documents/' . $userId . '/' . $doc['file_path'];
        if (file_exists($filePath)) @unlink($filePath);

        DB::execute("DELETE FROM user_documents WHERE id=?", [$id]);
        json_response(true, 'Document deleted.');
        break;
    }

    // ── DigiLocker connection status (placeholder for OAuth) ─────────
    case 'digilocker_connect_status': {
        $row = DB::fetchRow("SELECT connected, connected_at FROM digilocker_connections WHERE user_id=?", [$userId]);
        json_response(true,'ok',[
            'connected'    => $row ? (bool)$row['connected'] : false,
            'connected_at' => $row['connected_at'] ?? null,
            // TODO: When DigiLocker partner credentials are obtained, set this to
            // the real OAuth authorization URL: https://api.digitallocker.gov.in/public/oauth2/1/authorize?...
            'oauth_available' => false,
            'note' => 'DigiLocker API integration requires partner registration. Use manual document upload for now.',
        ]);
        break;
    }

    case 'digilocker_disconnect': {
        csrf_verify();
        DB::execute("DELETE FROM digilocker_connections WHERE user_id=?", [$userId]);
        json_response(true, 'DigiLocker disconnected.');
        break;
    }

    default: json_response(false,'Unknown action.',[],400);
}
