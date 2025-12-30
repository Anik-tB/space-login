<?php
/**
 * Admin Navigation Helper
 * Checks if user is admin and returns admin navigation link
 */
function getAdminNavLink() {
    $userId = $_SESSION['user_id'] ?? null;
    if (!$userId) {
        return '';
    }

    try {
        require_once __DIR__ . '/Database.php';
        $database = new Database();
        $user = $database->fetchOne("SELECT id, email, display_name FROM users WHERE id = ?", [$userId]);

        if (!$user) {
            return '';
        }

        // Check if user is admin
        $isAdmin = strpos(strtolower($user['email'] ?? ''), 'admin') !== false ||
                   strtolower($user['email'] ?? '') === 'admin@safespace.com';

        if ($isAdmin) {
            return '<a href="admin_dashboard.php" class="text-white/70 hover:text-white transition-colors duration-200 flex items-center gap-2">
                        <i data-lucide="shield-check" class="w-4 h-4"></i>
                        <span>Admin Panel</span>
                    </a>';
        }
    } catch (Exception $e) {
        // Silently fail
    }

    return '';
}
?>

