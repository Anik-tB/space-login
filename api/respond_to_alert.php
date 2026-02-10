<?php
/**
 * Respond to Emergency Alert API
 * Handles "I Can Help" responses from nearby users
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
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

// Get POST data
$input = json_decode(file_get_contents('php://input'), true);

$alertId = $input['alert_id'] ?? null;
$currentLat = $input['current_lat'] ?? null;
$currentLng = $input['current_lng'] ?? null;
$eta = $input['eta'] ?? null;
$message = $input['message'] ?? '';

// Validate required fields
if (!$alertId || !$currentLat || !$currentLng) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: alert_id, current_lat, current_lng'
    ]);
    exit;
}

try {
    $database = new Database();

    // Check if alert exists and is still active
    $alert = $database->fetchOne(
        "SELECT id, user_id, latitude, longitude, location_name, status
         FROM panic_alerts
         WHERE id = ?",
        [$alertId]
    );

    if (!$alert) {
        http_response_code(404);
        echo json_encode([
            'success' => false,
            'error' => 'Alert not found'
        ]);
        exit;
    }

    if ($alert['status'] !== 'active') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Alert is no longer active',
            'alert_status' => $alert['status']
        ]);
        exit;
    }

    // Check if user already responded
    $existingResponse = $database->fetchOne(
        "SELECT id, status FROM alert_responses
         WHERE alert_id = ? AND responder_id = ?",
        [$alertId, $userId]
    );

    if ($existingResponse) {
        // Update existing response
        $database->execute(
            "UPDATE alert_responses
             SET current_latitude = ?,
                 current_longitude = ?,
                 eta_minutes = ?,
                 message = ?,
                 status = 'responding',
                 updated_at = NOW()
             WHERE id = ?",
            [$currentLat, $currentLng, $eta, $message, $existingResponse['id']]
        );

        $responseId = $existingResponse['id'];
        $isNew = false;
    } else {
        // Create new response
        $database->execute(
            "INSERT INTO alert_responses
             (alert_id, responder_id, current_latitude, current_longitude, eta_minutes, message, status)
             VALUES (?, ?, ?, ?, ?, ?, 'responding')",
            [$alertId, $userId, $currentLat, $currentLng, $eta, $message]
        );

        $responseId = $database->getLastInsertId();
        $isNew = true;
    }

    // Get responder info
    $responder = $database->fetchOne(
        "SELECT id, display_name, email FROM users WHERE id = ?",
        [$userId]
    );

    // Get alert creator info
    $alertCreator = $database->fetchOne(
        "SELECT id, display_name, email FROM users WHERE id = ?",
        [$alert['user_id']]
    );

    // Calculate distance
    $distance = calculateDistance(
        $currentLat, $currentLng,
        $alert['latitude'], $alert['longitude']
    );

    // Send notification to alert creator
    if ($alertCreator) {
        $notificationMessage = $isNew
            ? "{$responder['display_name']} is coming to help you! ETA: " . ($eta ?? 'Unknown')
            : "{$responder['display_name']} updated their response";

        // Create notification
        $database->execute(
            "INSERT INTO notifications (user_id, title, message, type, action_url, created_at)
             VALUES (?, ?, ?, ?, ?, NOW())",
            [
                $alert['user_id'],
                '✅ Help is on the way!',
                $notificationMessage,
                'responder_coming',
                "panic_button.php?alert_id={$alertId}"
            ]
        );

        // TODO: Send push notification, SMS, email based on user preferences
    }

    // Get updated responders count
    $respondersCount = $database->fetchOne(
        "SELECT COUNT(*) as count
         FROM alert_responses
         WHERE alert_id = ? AND status = 'responding'",
        [$alertId]
    )['count'];

    echo json_encode([
        'success' => true,
        'message' => $isNew ? 'Response registered successfully' : 'Response updated successfully',
        'response_id' => $responseId,
        'is_new' => $isNew,
        'alert' => [
            'id' => $alert['id'],
            'location' => [
                'lat' => floatval($alert['latitude']),
                'lng' => floatval($alert['longitude']),
                'name' => $alert['location_name']
            ],
            'distance_km' => round($distance, 2),
            'responders_count' => intval($respondersCount)
        ],
        'responder' => [
            'id' => $responder['id'],
            'name' => $responder['display_name'],
            'eta' => $eta
        ],
        'notification_sent' => true
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * Calculate distance between two coordinates in kilometers
 */
function calculateDistance($lat1, $lon1, $lat2, $lon2) {
    $earthRadius = 6371; // km

    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);

    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);

    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    $distance = $earthRadius * $c;

    return $distance;
}
