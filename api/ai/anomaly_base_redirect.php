<?php
/**
 * WealthDash — t60: AI Anomaly Detection — Base
 * File: api/ai/anomaly_base_redirect.php
 *
 * ⚠️ NOTE FOR MASTER DEV: This task's functionality is ALREADY FULLY BUILT
 * in earlier sessions:
 *   - t246 (Session 5): api/ai/anomaly_detector.php — basic Z-score + duplicate detection
 *   - t384 (Session 7): api/ai/anomaly_detector_v2.php — adds SIP gap, large
 *     redemption detection, persistent anomaly_log table, resolve/dismiss workflow
 *
 * t384 v2 is a SUPERSET of what "t60: AI Anomaly detection — base" would
 * require. No new functionality needed.
 *
 * This file provides a thin action-name alias so if t60 is referenced
 * anywhere by action name 'ai_anomaly_detect_base', it forwards to v2.
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$action = clean($_POST['action'] ?? $_GET['action'] ?? '');

if ($action === 'ai_anomaly_detect_base') {
    // Forward to t384's full implementation
    $_POST['action'] = 'ai_anomaly_v2_scan';
    $_GET['action']  = 'ai_anomaly_v2_scan';
    require APP_ROOT . '/api/ai/anomaly_detector_v2.php';
    exit;
}

json_response(false, 'Use ai_anomaly_v2_scan (t384) or ai_anomaly_detect (t246) instead.', [], 400);
