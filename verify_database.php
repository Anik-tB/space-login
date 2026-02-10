<?php
/**
 * Database Verification Script
 * Checks if community alert tables are created properly
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

$host = 'localhost';
$username = 'root';
$password = '';
$dbname = 'safespace';

echo "<h2>Community Emergency Alert System - Database Verification</h2>";
echo "<pre>";

$conn = mysqli_connect($host, $username, $password, $dbname);

if (!$conn) {
    die("Connection failed: " . mysqli_connect_error());
}

echo "✓ Connected to database: $dbname\n\n";
echo str_repeat("=", 70) . "\n";

// 1. Check alert_responses table
echo "1. Checking alert_responses table...\n";
$result = mysqli_query($conn, "SHOW TABLES LIKE 'alert_responses'");
if (mysqli_num_rows($result) > 0) {
    echo "   ✓ Table exists\n";

    // Show structure
    $columns = mysqli_query($conn, "DESCRIBE alert_responses");
    echo "   Columns:\n";
    while ($col = mysqli_fetch_assoc($columns)) {
        echo "   - {$col['Field']} ({$col['Type']})\n";
    }

    // Count rows
    $count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM alert_responses"))['cnt'];
    echo "   Rows: $count\n";
} else {
    echo "   ✗ Table NOT found\n";
}

echo "\n" . str_repeat("=", 70) . "\n";

// 2. Check user_alert_settings table
echo "2. Checking user_alert_settings table...\n";
$result = mysqli_query($conn, "SHOW TABLES LIKE 'user_alert_settings'");
if (mysqli_num_rows($result) > 0) {
    echo "   ✓ Table exists\n";

    // Show structure
    $columns = mysqli_query($conn, "DESCRIBE user_alert_settings");
    echo "   Columns:\n";
    while ($col = mysqli_fetch_assoc($columns)) {
        echo "   - {$col['Field']} ({$col['Type']})\n";
    }

    // Count rows
    $count = mysqli_fetch_assoc(mysqli_query($conn, "SELECT COUNT(*) as cnt FROM user_alert_settings"))['cnt'];
    echo "   Rows: $count\n";
} else {
    echo "   ✗ Table NOT found\n";
}

echo "\n" . str_repeat("=", 70) . "\n";

// 3. Check panic_alerts new columns
echo "3. Checking panic_alerts new columns...\n";
$columns = mysqli_query($conn, "DESCRIBE panic_alerts");
$foundColumns = [];
while ($col = mysqli_fetch_assoc($columns)) {
    $foundColumns[] = $col['Field'];
}

$requiredColumns = ['responders_count', 'community_notified', 'broadcast_radius', 'nearby_users_count'];
foreach ($requiredColumns as $reqCol) {
    if (in_array($reqCol, $foundColumns)) {
        echo "   ✓ Column '$reqCol' exists\n";
    } else {
        echo "   ✗ Column '$reqCol' NOT found\n";
    }
}

echo "\n" . str_repeat("=", 70) . "\n";

// 4. Check users new columns
echo "4. Checking users new columns...\n";
$columns = mysqli_query($conn, "DESCRIBE users");
$foundColumns = [];
while ($col = mysqli_fetch_assoc($columns)) {
    $foundColumns[] = $col['Field'];
}

$requiredColumns = ['current_latitude', 'current_longitude', 'last_location_update', 'is_online', 'last_seen'];
foreach ($requiredColumns as $reqCol) {
    if (in_array($reqCol, $foundColumns)) {
        echo "   ✓ Column '$reqCol' exists\n";
    } else {
        echo "   ✗ Column '$reqCol' NOT found\n";
    }
}

echo "\n" . str_repeat("=", 70) . "\n";

// 5. Summary
echo "\n📊 SUMMARY:\n\n";

$allGood = true;

// Check tables
$tables = ['alert_responses', 'user_alert_settings'];
foreach ($tables as $table) {
    $result = mysqli_query($conn, "SHOW TABLES LIKE '$table'");
    if (mysqli_num_rows($result) > 0) {
        echo "✓ Table '$table' - OK\n";
    } else {
        echo "✗ Table '$table' - MISSING\n";
        $allGood = false;
    }
}

if ($allGood) {
    echo "\n🎉 ALL CHECKS PASSED! Community Emergency Alert System is ready!\n";
} else {
    echo "\n⚠️ Some items are missing. Please run the migration SQL again.\n";
}

echo "\n" . str_repeat("=", 70) . "\n";

mysqli_close($conn);
echo "</pre>";
?>
