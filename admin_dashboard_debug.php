<?php
// Debug version to check why admin panel isn't opening
error_reporting(E_ALL);
ini_set('display_errors', 1);

session_start();

echo "<h2>Admin Panel Debug Information</h2>";
echo "<pre>";

echo "1. Session Status:\n";
echo "   Session ID: " . session_id() . "\n";
echo "   Session Data: ";
print_r($_SESSION);
echo "\n\n";

echo "2. User ID Check:\n";
$userId = $_SESSION['user_id'] ?? null;
echo "   User ID from session: " . ($userId ?? 'NULL') . "\n\n";

if (!$userId) {
    echo "❌ ERROR: No user ID in session. You need to login first.\n";
    echo "   <a href='login.html'>Go to Login</a>\n";
    exit;
}

echo "3. Database Connection:\n";
try {
    require_once __DIR__ . '/includes/Database.php';
    $database = new Database();
    echo "   ✅ Database connection successful\n\n";
} catch (Exception $e) {
    echo "   ❌ Database connection failed: " . $e->getMessage() . "\n";
    exit;
}

echo "4. User Lookup:\n";
try {
    $user = $database->fetchOne("SELECT id, email, display_name FROM users WHERE id = ?", [$userId]);
    if (!$user) {
        echo "   ❌ ERROR: User not found in database with ID: $userId\n";
        echo "   <a href='login.html'>Go to Login</a>\n";
        exit;
    }
    echo "   ✅ User found:\n";
    echo "      ID: " . $user['id'] . "\n";
    echo "      Email: " . $user['email'] . "\n";
    echo "      Display Name: " . ($user['display_name'] ?? 'NULL') . "\n\n";
} catch (Exception $e) {
    echo "   ❌ Error fetching user: " . $e->getMessage() . "\n";
    exit;
}

echo "5. Admin Check:\n";
$email = strtolower($user['email'] ?? '');
$hasAdminInEmail = strpos($email, 'admin') !== false;
$isExactAdmin = $email === 'admin@safespace.com';
$isAdmin = $hasAdminInEmail || $isExactAdmin;

echo "   Email: $email\n";
echo "   Contains 'admin': " . ($hasAdminInEmail ? 'YES' : 'NO') . "\n";
echo "   Is 'admin@safespace.com': " . ($isExactAdmin ? 'YES' : 'NO') . "\n";
echo "   Is Admin: " . ($isAdmin ? 'YES ✅' : 'NO ❌') . "\n\n";

if (!$isAdmin) {
    echo "❌ ERROR: You are not an admin user.\n";
    echo "   To access admin panel, your email must:\n";
    echo "   - Contain the word 'admin' (case-insensitive), OR\n";
    echo "   - Be exactly 'admin@safespace.com'\n\n";
    echo "   Your current email: $email\n\n";
    echo "   Options:\n";
    echo "   1. Update your email in the database to include 'admin'\n";
    echo "   2. Create a user with email 'admin@safespace.com'\n";
    echo "   3. <a href='dashboard.php'>Go to Dashboard</a>\n";
    exit;
}

echo "6. All Checks Passed!\n";
echo "   ✅ You should be able to access the admin panel.\n\n";
echo "</pre>";

echo "<h3>Next Steps:</h3>";
echo "<p><a href='admin_dashboard.php' style='padding: 10px 20px; background: #4f46e5; color: white; text-decoration: none; border-radius: 5px;'>Open Admin Dashboard</a></p>";

echo "<h3>Quick Fix - Make Current User Admin:</h3>";
echo "<form method='POST' style='margin-top: 20px;'>";
echo "<input type='hidden' name='make_admin' value='1'>";
echo "<button type='submit' style='padding: 10px 20px; background: #10b981; color: white; border: none; border-radius: 5px; cursor: pointer;'>Make My Email Admin (Add 'admin' to email)</button>";
echo "</form>";

if (isset($_POST['make_admin']) && $_POST['make_admin'] == '1') {
    try {
        $newEmail = 'admin_' . $user['email'];
        $updateSql = "UPDATE users SET email = ? WHERE id = ?";
        $database->update($updateSql, [$newEmail, $userId]);
        echo "<div style='margin-top: 20px; padding: 15px; background: #dcfce7; border: 1px solid #10b981; border-radius: 5px;'>";
        echo "✅ Success! Your email has been updated to: <strong>$newEmail</strong><br>";
        echo "Please <a href='admin_dashboard.php'>refresh the admin panel</a> or <a href='logout.php'>logout and login again</a>.";
        echo "</div>";
    } catch (Exception $e) {
        echo "<div style='margin-top: 20px; padding: 15px; background: #fee2e2; border: 1px solid #ef4444; border-radius: 5px;'>";
        echo "❌ Error updating email: " . $e->getMessage();
        echo "</div>";
    }
}
?>

