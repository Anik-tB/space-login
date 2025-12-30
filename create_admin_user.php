<?php
/**
 * Create Admin User Script
 * Run this once to create an admin user in the database
 */
require_once __DIR__ . '/includes/Database.php';

$database = new Database();

// Admin user data
$adminEmail = 'admin@safespace.com';
$adminPassword = 'Admin@123'; // Change this to a strong password
$adminName = 'System Administrator';

try {
    // Check if admin already exists
    $existing = $database->fetchOne("SELECT id FROM users WHERE email = ?", [$adminEmail]);

    if ($existing) {
        // Update existing user to be admin
        $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

        // Check if is_admin column exists
        $columns = $database->getTableStructure('users');
        $hasIsAdmin = false;
        foreach ($columns as $col) {
            if (isset($col['Field']) && $col['Field'] === 'is_admin') {
                $hasIsAdmin = true;
                break;
            }
        }

        if ($hasIsAdmin) {
            $database->update(
                "UPDATE users SET password = ?, display_name = ?, is_admin = 1 WHERE email = ?",
                [$hashedPassword, $adminName, $adminEmail]
            );
        } else {
            // Add is_admin column first
            try {
                $database->executeRaw("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0");
            } catch (Exception $e) {
                // Column might already exist
            }
            $database->update(
                "UPDATE users SET password = ?, display_name = ?, is_admin = 1 WHERE email = ?",
                [$hashedPassword, $adminName, $adminEmail]
            );
        }

        echo "✅ Admin user updated successfully!<br>";
        echo "Email: $adminEmail<br>";
        echo "Password: $adminPassword<br><br>";
    } else {
        // Create new admin user
        $hashedPassword = password_hash($adminPassword, PASSWORD_DEFAULT);

        // Check if is_admin column exists
        $columns = $database->getTableStructure('users');
        $hasIsAdmin = false;
        foreach ($columns as $col) {
            if (isset($col['Field']) && $col['Field'] === 'is_admin') {
                $hasIsAdmin = true;
                break;
            }
        }

        if (!$hasIsAdmin) {
            try {
                $database->executeRaw("ALTER TABLE users ADD COLUMN is_admin TINYINT(1) DEFAULT 0");
            } catch (Exception $e) {
                // Column might already exist
            }
        }

        $userId = $database->insert(
            "INSERT INTO users (email, password, display_name, is_admin, email_verified, status, created_at, last_login)
             VALUES (?, ?, ?, 1, 1, 'active', NOW(), NOW())",
            [$adminEmail, $hashedPassword, $adminName]
        );

        echo "✅ Admin user created successfully!<br>";
        echo "User ID: $userId<br>";
        echo "Email: $adminEmail<br>";
        echo "Password: $adminPassword<br><br>";
    }

    echo "<a href='admin_login.php' style='padding: 10px 20px; background: #6366f1; color: white; text-decoration: none; border-radius: 8px; display: inline-block; margin-top: 20px;'>Go to Admin Login</a>";

} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>

