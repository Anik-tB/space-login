<?php
/**
 * Update User Alert Settings API
 * Allows users to configure their community alert preferences
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

session_start();
require_once '../includes/Database.php';

// Check authentication
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Unauthorized. Please login.'
    ]);
    exit;
}

$database = new Database();

// Handle GET request - fetch current settings
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $settings = $database->fetchOne(
            "SELECT * FROM user_alert_settings WHERE user_id = ?",
            [$userId]
        );

        if (!$settings) {
            // Create default settings if not exists
            $database->execute(
                "INSERT INTO user_alert_settings (user_id) VALUES (?)",
                [$userId]
            );

            $settings = $database->fetchOne(
                "SELECT * FROM user_alert_settings WHERE user_id = ?",
                [$userId]
            );
        }

        echo json_encode([
            'success' => true,
            'settings' => [
                'allow_community_alerts' => (bool)$settings['allow_community_alerts'],
                'alert_radius' => intval($settings['alert_radius']),
                'notify_push' => (bool)$settings['notify_push'],
                'notify_sound' => (bool)$settings['notify_sound'],
                'notify_email' => (bool)$settings['notify_email'],
                'notify_sms' => (bool)$settings['notify_sms'],
                'quiet_hours_start' => $settings['quiet_hours_start'],
                'quiet_hours_end' => $settings['quiet_hours_end']
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage()
        ]);
    }
    exit;
}

// Handle POST request - update settings
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);

    try {
        // Validate inputs
        $allowAlerts = isset($input['allow_community_alerts']) ? (bool)$input['allow_community_alerts'] : true;
        $alertRadius = isset($input['alert_radius']) ? intval($input['alert_radius']) : 5000;
        $notifyPush = isset($input['notify_push']) ? (bool)$input['notify_push'] : true;
        $notifySound = isset($input['notify_sound']) ? (bool)$input['notify_sound'] : true;
        $notifyEmail = isset($input['notify_email']) ? (bool)$input['notify_email'] : false;
        $notifySms = isset($input['notify_sms']) ? (bool)$input['notify_sms'] : false;
        $quietStart = $input['quiet_hours_start'] ?? null;
        $quietEnd = $input['quiet_hours_end'] ?? null;

        // Validate radius (1km to 20km)
        $alertRadius = max(1000, min(20000, $alertRadius));

        // Check if settings exist
        $existing = $database->fetchOne(
            "SELECT id FROM user_alert_settings WHERE user_id = ?",
            [$userId]
        );

        if ($existing) {
            // Update existing settings
            $database->execute(
                "UPDATE user_alert_settings
                 SET allow_community_alerts = ?,
                     alert_radius = ?,
                     notify_push = ?,
                     notify_sound = ?,
                     notify_email = ?,
                     notify_sms = ?,
                     quiet_hours_start = ?,
                     quiet_hours_end = ?,
                     updated_at = NOW()
                 WHERE user_id = ?",
                [
                    $allowAlerts, $alertRadius, $notifyPush, $notifySound,
                    $notifyEmail, $notifySms, $quietStart, $quietEnd, $userId
                ]
            );
        } else {
            // Insert new settings
            $database->execute(
                "INSERT INTO user_alert_settings
                 (user_id, allow_community_alerts, alert_radius, notify_push, notify_sound, notify_email, notify_sms, quiet_hours_start, quiet_hours_end)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [
                    $userId, $allowAlerts, $alertRadius, $notifyPush, $notifySound,
                    $notifyEmail, $notifySms, $quietStart, $quietEnd
                ]
            );
        }

        echo json_encode([
            'success' => true,
            'message' => 'Settings updated successfully',
            'settings' => [
                'allow_community_alerts' => $allowAlerts,
                'alert_radius' => $alertRadius,
                'notify_push' => $notifyPush,
                'notify_sound' => $notifySound,
                'notify_email' => $notifyEmail,
                'notify_sms' => $notifySms,
                'quiet_hours_start' => $quietStart,
                'quiet_hours_end' => $quietEnd
            ]
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => 'Server error: ' . $e->getMessage()
        ]);
    }
}
