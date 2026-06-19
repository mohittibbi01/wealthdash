<?php
require_once __DIR__ . '/auth.php';
if (is_logged_in()) log_activity('logout');
session_destroy();
header('Location: login.php');
exit;
