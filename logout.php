<?php
session_start();

// Include database handler
require_once 'includes/Database.php';

try {
    $database = new Database();

    // Mark session as inactive in database
    if (isset($_SESSION['user_id']) && isset($_SESSION['session_token'])) {
        $sql = "UPDATE user_sessions SET is_active = 0, logout_time = NOW()
                WHERE user_id = ? AND session_token = ?";
        $database->update($sql, [$_SESSION['user_id'], $_SESSION['session_token']]);
    }

} catch (Exception $e) {
    // Log error but don't stop logout process
    error_log("Logout error: " . $e->getMessage());
}

// Clear all session data
session_unset();
session_destroy();

// Redirect to login page
header('Location: index.html');
exit;
?>