<?php
/**
 * Update User Session Activity
 * Tracks user activity and updates session data for real-time metrics
 */

header('Content-Type: application/json');
session_start();

// Include database handler
require_once 'includes/Database.php';

try {
    $database = new Database();
    $models = new SafeSpaceModels($database);

    // Get current user ID (if logged in)
    $userId = $_SESSION['user_id'] ?? null;
    
    // Log session data for debugging
    error_log("Session Update - User ID: " . ($userId ?? 'null') . ", Session Token: " . ($_SESSION['session_token'] ?? 'null'));

    if ($userId) {
        // Update existing session
        $sessionToken = $_SESSION['session_token'] ?? null;

        if ($sessionToken) {
            // Update last activity for existing session
            $sql = "UPDATE user_sessions SET
                    last_activity = NOW(),
                    ip_address = ?,
                    user_agent = ?
                    WHERE session_token = ? AND user_id = ?";

            $affectedRows = $database->update($sql, [
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                $sessionToken,
                $userId
            ]);
            
            if ($affectedRows > 0) {
                error_log("Session updated successfully for user $userId");
            } else {
                error_log("No session found to update for user $userId with token $sessionToken");
            }
        } else {
            // Create new session
            $sessionToken = bin2hex(random_bytes(32));
            $_SESSION['session_token'] = $sessionToken;

            $sql = "INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, device_type, is_active)
                    VALUES (?, ?, ?, ?, ?, 1)";

            $newSessionId = $database->insert($sql, [
                $userId,
                $sessionToken,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                detectDeviceType($_SERVER['HTTP_USER_AGENT'] ?? '')
            ]);
            
            if ($newSessionId) {
                error_log("New session created for user $userId with ID $newSessionId");
            } else {
                error_log("Failed to create new session for user $userId");
            }
        }
    } else {
        // Anonymous session tracking (for analytics)
        $anonymousId = $_SESSION['anonymous_id'] ?? null;

        if (!$anonymousId) {
            $anonymousId = 'anon_' . bin2hex(random_bytes(16));
            $_SESSION['anonymous_id'] = $anonymousId;
        }

        // Track anonymous activity (optional)
        // You can implement this if you want to track anonymous users
        error_log("Anonymous session tracked: $anonymousId");
    }

    // Clean up old sessions (older than 24 hours)
    $sql = "UPDATE user_sessions SET is_active = 0, logout_time = NOW()
            WHERE last_activity < DATE_SUB(NOW(), INTERVAL 24 HOUR) AND is_active = 1";
    $cleanedRows = $database->executeRaw($sql);
    error_log("Cleaned up $cleanedRows old sessions");

    echo json_encode(['success' => true, 'message' => 'Session updated']);

} catch (Exception $e) {
    error_log("Session update error: " . $e->getMessage());
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}

/**
 * Detect device type from user agent
 */
function detectDeviceType($userAgent) {
    $userAgent = strtolower($userAgent);

    if (strpos($userAgent, 'mobile') !== false || strpos($userAgent, 'android') !== false || strpos($userAgent, 'iphone') !== false) {
        return 'mobile';
    } elseif (strpos($userAgent, 'tablet') !== false || strpos($userAgent, 'ipad') !== false) {
        return 'tablet';
    } else {
        return 'desktop';
    }
}
?>