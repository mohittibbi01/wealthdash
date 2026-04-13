<?php
/**
 * WealthDash — t106: NPS Import
 * Bank statement based NPS contribution auto-detect
 *
 * TODO: define actions
 *
 * TODO: implement fully
 * 
 */
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';

// TODO: implement

echo json_encode(['success' => false, 'message' => 'Not yet implemented — t106']);