<?php
/**
 * WealthDash — t145: Benchmark Compare
 * Stock picks vs Nifty 50 comparison
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

echo json_encode(['success' => false, 'message' => 'Not yet implemented — t145']);