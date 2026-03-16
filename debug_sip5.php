<?php
/**
 * Check exactly what browser gets when saving SIP
 * Intercepts the actual sip_add and logs everything
 */
define('WEALTHDASH', true);
require_once __DIR__ . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';
require_once APP_ROOT . '/includes/helpers.php';

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$portfolioId = (int)($_SESSION['selected_portfolio_id'] ?? 0);
if (!$portfolioId) {
    $p = DB::fetchOne("SELECT id FROM portfolios WHERE user_id=? LIMIT 1", [$userId]);
    $portfolioId = $p ? (int)$p['id'] : 0;
}
$fund = DB::fetchOne(
    "SELECT f.id, f.scheme_name FROM mf_holdings h
     JOIN funds f ON f.id=h.fund_id
     WHERE h.portfolio_id=? AND h.is_active=1 LIMIT 1",
    [$portfolioId]
);
$csrf = csrf_token();
?>
<!DOCTYPE html>
<html>
<head>
<title>SIP Save Direct Test</title>
<style>
body{font-family:monospace;background:#0f172a;color:#e2e8f0;padding:20px}
pre{background:#1e293b;padding:12px;border-radius:6px;white-space:pre-wrap;word-break:break-all}
.ok{color:#4ade80}.err{color:#f87171}.warn{color:#fbbf24}
button{background:#2563eb;color:#fff;padding:10px 20px;border:none;border-radius:6px;cursor:pointer;font-size:14px}
</style>
</head>
<body>
<h2 style="color:#38bdf8">🔍 SIP Save Direct Browser Test</h2>
<p>Portfolio: <b><?= $portfolioId ?></b> | Fund: <b><?= e($fund['scheme_name'] ?? 'none') ?></b> (id=<?= $fund['id'] ?? 0 ?>)</p>

<button onclick="testSave()">▶ Test SIP Save Now</button>
<div id="log" style="margin-top:20px"></div>

<script>
const CSRF    = '<?= $csrf ?>';
const APP_URL = '<?= APP_URL ?>';

function log(msg, type='') {
    const div = document.getElementById('log');
    const cls = type==='ok'?'ok':type==='err'?'err':type==='warn'?'warn':'';
    div.innerHTML += `<pre class="${cls}">${msg}</pre>`;
}

async function testSave() {
    document.getElementById('log').innerHTML = '';
    log('Starting test...');

    const payload = {
        action:       'sip_add',
        portfolio_id: <?= $portfolioId ?>,
        fund_id:      <?= $fund['id'] ?? 1 ?>,
        sip_amount:   100,
        frequency:    'monthly',
        sip_day:      1,
        start_date:   '01-01-2025',
        end_date:     '',
        folio_number: '',
        platform:     '',
        notes:        '',
        csrf_token:   CSRF,
    };

    log('Payload: ' + JSON.stringify(payload, null, 2));
    log('Sending to: ' + APP_URL + '/api/router.php');

    try {
        const res = await fetch(APP_URL + '/api/router.php', {
            method:  'POST',
            headers: {
                'Content-Type':     'application/json',
                'X-CSRF-Token':     CSRF,
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });

        log('HTTP Status: ' + res.status + ' ' + res.statusText, res.ok ? 'ok' : 'err');

        // Get raw text FIRST before trying JSON parse
        const rawText = await res.text();
        log('Raw response (' + rawText.length + ' bytes):\n' + rawText, rawText.length > 0 ? 'ok' : 'err');

        // Show hex of first chars
        let hex = 'First 50 bytes hex: ';
        for (let i=0; i<Math.min(50, rawText.length); i++) {
            const code = rawText.charCodeAt(i);
            hex += (code < 32 || code > 126) ? `[${code.toString(16).toUpperCase().padStart(2,'0')}]` : rawText[i];
        }
        log(hex, 'warn');

        // Try JSON parse
        try {
            const json = JSON.parse(rawText);
            log('✓ JSON parse OK: ' + JSON.stringify(json), 'ok');
            if (json.success) {
                log('✓ SUCCESS! SIP saved with id=' + (json.data?.id || json.id), 'ok');
                // Cleanup - delete test SIP
                await fetch(APP_URL + '/api/router.php', {
                    method: 'POST',
                    headers: {'Content-Type':'application/json','X-CSRF-Token':CSRF,'X-Requested-With':'XMLHttpRequest'},
                    body: JSON.stringify({action:'sip_delete', sip_id:json.data?.id||json.id, portfolio_id:<?= $portfolioId ?>, csrf_token:CSRF})
                });
                log('(test SIP deleted)', 'warn');
            } else {
                log('✗ API error: ' + json.message, 'err');
            }
        } catch(parseErr) {
            log('✗ JSON PARSE FAILED: ' + parseErr.message, 'err');
            log('The response is not valid JSON — something is prepended/appended to it', 'err');
        }

    } catch(fetchErr) {
        log('✗ FETCH ERROR: ' + fetchErr.message, 'err');
    }
}
</script>
</body>
</html>
