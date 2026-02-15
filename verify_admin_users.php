<?php
/**
 * Verify Admin Users
 * Checks the current admin status of users
 */

require_once __DIR__ . '/includes/Database.php';

try {
    $database = new Database();

    echo "=== Admin Users Verification ===\n\n";

    // Check all users with admin privileges
    $admins = $database->fetchAll(
        "SELECT id, email, display_name, is_admin, status FROM users WHERE is_admin = 1"
    );

    echo "Users with Admin Privileges (is_admin = 1):\n";
    if (empty($admins)) {
        echo "  ⚠️  No admin users found!\n";
    } else {
        foreach ($admins as $admin) {
            echo "  ✅ {$admin['email']} (ID: {$admin['id']}, Status: {$admin['status']})\n";
        }
    }

    echo "\n";

    // Check specific users
    echo "Specific User Status:\n";
    $users = $database->fetchAll(
        "SELECT id, email, display_name, is_admin, status FROM users WHERE email IN (?, ?)",
        ['admin@safespace.com', 'mdabu018717@gmail.com']
    );

    foreach ($users as $user) {
        $adminStatus = $user['is_admin'] == 1 ? '✅ ADMIN' : '👤 Regular User';
        echo "  - {$user['email']}: {$adminStatus}\n";
    }

    echo "\n✅ Verification complete!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}
?>
