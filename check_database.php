<?php
/**
 * Database Connection Checker
 * Use this to diagnose database connection issues
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Database Connection Diagnostic</h2>";
echo "<pre>";

try {
    require_once __DIR__ . '/includes/Database.php';

    echo "1. Testing Database Connection...\n";
    $database = new Database();
    $conn = $database->getConnection();

    if ($conn) {
        echo "   ✅ Database connection successful!\n\n";
    } else {
        echo "   ❌ Database connection failed!\n";
        exit;
    }

    echo "2. Checking Database Name...\n";
    $result = $conn->query("SELECT DATABASE() as dbname");
    $row = $result->fetch_assoc();
    echo "   Current database: " . ($row['dbname'] ?? 'NULL') . "\n\n";

    echo "3. Checking Required Tables...\n";
    $requiredTables = [
        'users',
        'incident_reports',
        'neighborhood_groups',
        'disputes',
        'legal_aid_providers',
        'medical_support_providers',
        'alerts',
        'safe_spaces'
    ];

    foreach ($requiredTables as $table) {
        $exists = $database->tableExists($table);
        echo "   " . ($exists ? "✅" : "❌") . " Table '$table': " . ($exists ? "EXISTS" : "MISSING") . "\n";
    }

    echo "\n4. Testing Sample Queries...\n";

    // Test users table
    if ($database->tableExists('users')) {
        try {
            $userCount = $database->fetchOne("SELECT COUNT(*) as count FROM users");
            echo "   ✅ Users table query: " . ($userCount['count'] ?? 0) . " users found\n";
        } catch (Exception $e) {
            echo "   ❌ Users table query failed: " . $e->getMessage() . "\n";
        }
    }

    // Test incident_reports table
    if ($database->tableExists('incident_reports')) {
        try {
            $reportCount = $database->fetchOne("SELECT COUNT(*) as count FROM incident_reports");
            echo "   ✅ Incident reports query: " . ($reportCount['count'] ?? 0) . " reports found\n";
        } catch (Exception $e) {
            echo "   ❌ Incident reports query failed: " . $e->getMessage() . "\n";
        }
    }

    echo "\n5. Database Configuration:\n";
    echo "   Host: localhost\n";
    echo "   User: root\n";
    echo "   Database: space_login\n";

    echo "\n✅ All checks completed!\n";
    echo "\nIf you see errors above, please:\n";
    echo "1. Make sure XAMPP MySQL is running\n";
    echo "2. Check that database 'space_login' exists\n";
    echo "3. Run the SQL file to create tables if missing\n";

} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";

echo "<p><a href='admin_dashboard.php' style='padding: 10px 20px; background: #4f46e5; color: white; text-decoration: none; border-radius: 5px;'>Try Admin Dashboard Again</a></p>";
?>

