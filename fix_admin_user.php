<?php
/**
 * Fix Admin User Script
 * Sets admin@safespace.com as admin and removes admin privileges from mdabu018717@gmail.com
 */

require_once __DIR__ . '/includes/Database.php';

try {
    $database = new Database();

    echo "=== Fixing Admin Users ===\n\n";

    // Check current status
    echo "Current Status:\n";
    $users = $database->fetchAll(
        "SELECT id, email, display_name, is_admin FROM users WHERE email IN (?, ?)",
        ['admin@safespace.com', 'mdabu018717@gmail.com']
    );

    foreach ($users as $user) {
        echo "- {$user['email']}: is_admin = " . ($user['is_admin'] ?? 'NULL') . "\n";
    }
    echo "\n";

    // Fix admin@safespace.com - set as admin
    $adminUser = $database->fetchOne(
        "SELECT id, email FROM users WHERE email = ?",
        ['admin@safespace.com']
    );

    if ($adminUser) {
        $database->update(
            "UPDATE users SET is_admin = 1 WHERE email = ?",
            ['admin@safespace.com']
        );
        echo "✅ Set admin@safespace.com as admin (is_admin = 1)\n";
    } else {
        echo "⚠️  admin@safespace.com not found in database\n";
        echo "   Creating admin user...\n";

        // Create admin user with default password
        $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $database->insert(
            "INSERT INTO users (email, password, display_name, is_admin, email_verified, status, created_at)
             VALUES (?, ?, ?, 1, 1, 'active', NOW())",
            ['admin@safespace.com', $hashedPassword, 'Admin User']
        );
        echo "✅ Created admin@safespace.com with password: admin123\n";
        echo "   ⚠️  IMPORTANT: Change this password after first login!\n";
    }

    // Fix mdabu018717@gmail.com - remove admin privileges
    $regularUser = $database->fetchOne(
        "SELECT id, email FROM users WHERE email = ?",
        ['mdabu018717@gmail.com']
    );

    if ($regularUser) {
        $database->update(
            "UPDATE users SET is_admin = 0 WHERE email = ?",
            ['mdabu018717@gmail.com']
        );
        echo "✅ Removed admin privileges from mdabu018717@gmail.com (is_admin = 0)\n";
    } else {
        echo "ℹ️  mdabu018717@gmail.com not found in database (no action needed)\n";
    }

    echo "\n=== Final Status ===\n";
    $usersAfter = $database->fetchAll(
        "SELECT id, email, display_name, is_admin FROM users WHERE email IN (?, ?)",
        ['admin@safespace.com', 'mdabu018717@gmail.com']
    );

    foreach ($usersAfter as $user) {
        $status = $user['is_admin'] == 1 ? '✅ ADMIN' : '👤 Regular User';
        echo "- {$user['email']}: {$status}\n";
    }

    echo "\n✅ Admin user fix completed successfully!\n";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
?>
