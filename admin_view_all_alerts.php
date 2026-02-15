<?php
session_start();

require_once __DIR__ . '/includes/Database.php';

// STRICT ADMIN AUTHENTICATION
$userId = $_SESSION['user_id'] ?? null;
if (!$userId || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    session_destroy();
    header('Location: admin_login.php?error=access_denied');
    exit;
}

$database = new Database();
$user = $database->fetchOne("SELECT id, email, display_name, is_admin FROM users WHERE id = ?", [$userId]);
if (!$user || $user['is_admin'] != 1) {
    session_destroy();
    header('Location: admin_login.php?error=not_authorized');
    exit;
}

$_SESSION['admin_name'] = $user['display_name'] ?? $user['email'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>All Alerts - Admin Panel</title>
    <link rel="stylesheet" href="admin-dashboard.css">
    <style>
        .view-all-page-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 16px; margin-bottom: 24px; }
        .btn-back-dashboard { display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; background: #f1f5f9; border: 1px solid #e2e8f0; border-radius: 10px; font-size: 0.95rem; font-weight: 600; color: #475569; text-decoration: none; transition: all 0.2s ease; }
        .admin-body.dark-theme .btn-back-dashboard { background: #334155; border-color: #475569; color: #94a3b8; }
        .btn-back-dashboard:hover { background: #e2e8f0; border-color: #cbd5e1; }
        .admin-body.dark-theme .btn-back-dashboard:hover { background: #475569; border-color: #64748b; }
    </style>
</head>
<body class="admin-body" id="adminBody" data-view-all-alerts="1">
<div class="dashboard-shell">
    <aside class="dashboard-nav">
        <div class="dashboard-nav__brand">
            <span class="brand-mark">🛡️</span>
            <div>
                <strong>SafeSpace</strong>
                <small>Command Suite</small>
            </div>
        </div>
        <nav class="dashboard-nav__links">
            <a class="nav-item" href="admin_dashboard.php">
                <span style="margin-right: 8px;">📊</span> Overview
            </a>
            <a class="nav-item active" href="admin_view_all_alerts.php">
                <span style="margin-right: 8px;">🚨</span> All Alerts
            </a>
            <a class="nav-item" href="dashboard.php">
                <span style="margin-right: 8px;">👥</span> Citizen Portal
            </a>
            <a class="nav-item" href="report_incident.php">
                <span style="margin-right: 8px;">📝</span> Intake Desk
            </a>
            <a class="nav-item" href="dispute_center.php">
                <span style="margin-right: 8px;">⚖️</span> Disputes
            </a>
            <a class="nav-item" href="safety_resources.php">
                <span style="margin-right: 8px;">📚</span> Resources
            </a>
            <a class="nav-item" href="logout.php" style="color: #ef4444;">
                <span style="margin-right: 8px;">🚪</span> Logout
            </a>
        </nav>
        <div class="dashboard-nav__meta">
            <span>Operational</span>
            <small>All Alerts View</small>
        </div>
    </aside>
    <main class="dashboard-main">
        <header class="view-all-page-header">
            <div style="display: flex; align-items: center; gap: 12px;">
                <a href="admin_dashboard.php" class="btn-back-dashboard">← Back to Admin Dashboard</a>
                <button type="button" id="themeToggle" class="ghost-btn" title="Toggle theme" style="padding: 8px 14px;">🌙</button>
            </div>
            <button type="button" onclick="openCreateAlertModal()" class="btn-create-alert">
                <span class="btn-create-alert-icon">➕</span> Create New Alert
            </button>
        </header>

        <section class="alert-management-section">
            <article class="panel alert-management-panel">
                <header class="alert-management-header">
                    <h2 class="alert-management-title">🚨 All Alerts</h2>
                </header>
                <div id="alertManagementContainer" class="alert-management-container">
                    <p class="alert-management-loading">Loading alerts...</p>
                </div>
            </article>
        </section>
    </main>
</div>

<script>
    // Theme toggle for view-all page
    document.addEventListener('DOMContentLoaded', function() {
        const adminBody = document.getElementById('adminBody');
        const themeToggle = document.getElementById('themeToggle');
        const savedTheme = localStorage.getItem('adminTheme') || 'light';
        if (savedTheme === 'dark') {
            adminBody.classList.add('dark-theme');
            if (themeToggle) themeToggle.textContent = '☀️';
        }
        if (themeToggle) {
            themeToggle.addEventListener('click', function() {
                adminBody.classList.toggle('dark-theme');
                themeToggle.textContent = adminBody.classList.contains('dark-theme') ? '☀️' : '🌙';
                localStorage.setItem('adminTheme', adminBody.classList.contains('dark-theme') ? 'dark' : 'light');
            });
        }
    });
</script>
<script src="js/admin-alert-management.js" defer></script>
</body>
</html>
