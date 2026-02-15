<?php
/**
 * Admin Authentication Middleware
 * Include this file at the top of any admin-only page
 *
 * This middleware ensures that only users with is_admin = 1 can access admin pages.
 * It performs both session and database validation for maximum security.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/Database.php';

/**
 * Require admin authentication
 * Redirects to admin login if user is not authenticated as admin
 */
function requireAdmin() {
    // Check if user is logged in
    if (!isset($_SESSION['user_id']) || !isset($_SESSION['is_admin'])) {
        session_destroy();
        header('Location: /space-login/admin_login.php?error=not_logged_in');
        exit;
    }

    // Verify admin flag in session
    if ($_SESSION['is_admin'] !== true) {
        session_destroy();
        header('Location: /space-login/admin_login.php?error=not_authorized');
        exit;
    }

    // Double-check with database to prevent session hijacking
    try {
        $database = new Database();
        $user = $database->fetchOne(
            "SELECT is_admin FROM users WHERE id = ?",
            [$_SESSION['user_id']]
        );

        if (!$user || $user['is_admin'] != 1) {
            session_destroy();
            header('Location: /space-login/admin_login.php?error=invalid_admin');
            exit;
        }
    } catch (Exception $e) {
        error_log('Admin auth error: ' . $e->getMessage());
        session_destroy();
        header('Location: /space-login/admin_login.php?error=system_error');
        exit;
    }

    return true;
}

// Auto-execute authentication check
requireAdmin();
