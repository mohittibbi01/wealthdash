<?php
// ============================================================
// GA-55A SYSTEM — includes/header.php
// Common <head> — har page pe include karo
// Usage: include('../includes/header.php');
// $pageTitle variable set karo upar se
// ============================================================
if (!isset($pageTitle)) $pageTitle = 'GA-55A System';
?>
<!DOCTYPE html>
<html lang="hi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> | <?= APP_NAME ?></title>

    <!-- Tabler Icons (free, offline-friendly CDN) -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@tabler/icons-webfont@latest/tabler-icons.min.css">

    <!-- GA-55A CSS — order matter karta hai -->
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/01_variables.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/02_reset.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/03_layout.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/04_components.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/05_forms.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/06_responsive.css">
</head>
<body>
<div class="app-shell">
<!-- Sidebar overlay (mobile) -->
<div class="sidebar-overlay" id="sidebarOverlay" onclick="closeSidebar()"></div>
