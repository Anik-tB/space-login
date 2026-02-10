<?php
/**
 * Update User Location API
 * Updates user's current location for nearby alert detection
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

$lat = isset($input['lat']) ? floatval($input['lat']) : null;
$lng = isset($input['lng']) ? floatval($input['lng']) : null;

// Validate required fields
if ($lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Missing required fields: lat, lng'
    ]);
    exit;
}

// Validate coordinates
if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid coordinates'
    ]);
    exit;
}

try {
    $database = new Database();

    // Update user location
    $database->execute(
        "UPDATE users
         SET current_latitude = ?,
             current_longitude = ?,
             last_location_update = NOW(),
             is_online = TRUE,
             last_seen = NOW()
         WHERE id = ?",
        [$lat, $lng, $userId]
    );

    echo json_encode([
        'success' => true,
        'message' => 'Location updated successfully',
        'location' => [
            'lat' => $lat,
            'lng' => $lng
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}
