<?php
/**
 * Get Nearby Emergency Alerts API
 * Returns active panic alerts within user's specified radius
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

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

// Get parameters
$userLat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$userLng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$radius = isset($_GET['radius']) ? intval($_GET['radius']) : null;

// Validate required parameters
if ($userLat === null || $userLng === null) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Location parameters (lat, lng) are required'
    ]);
    exit;
}

// Validate coordinates
if ($userLat < -90 || $userLat > 90 || $userLng < -180 || $userLng > 180) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Invalid coordinates'
    ]);
    exit;
}

try {
    $database = new Database();

    // Get user's alert settings
    $userSettings = $database->fetchOne(
        "SELECT alert_radius, allow_community_alerts
         FROM user_alert_settings
         WHERE user_id = ?",
        [$userId]
    );

    // Check if user has community alerts enabled
    if ($userSettings && !$userSettings['allow_community_alerts']) {
        echo json_encode([
            'success' => true,
            'alerts' => [],
            'count' => 0,
            'message' => 'Community alerts are disabled in your settings'
        ]);
        exit;
    }

    // Use provided radius or user's setting or default 5km
    if ($radius === null) {
        $radius = $userSettings['alert_radius'] ?? 5000;
    }

    // Limit radius to max 20km for performance
    $radius = min($radius, 20000);

    // Query for nearby active panic alerts
    $query = "
        SELECT
            pa.id,
            pa.user_id,
            pa.latitude,
            pa.longitude,
            pa.location_name,
            pa.message,
            pa.triggered_at,
            pa.status,
            pa.responders_count,
            pa.emergency_contacts_notified,
            pa.police_notified,
            pa.ambulance_notified,
            ST_Distance_Sphere(
                POINT(pa.longitude, pa.latitude),
                POINT(?, ?)
            ) as distance_meters,
            u.display_name as user_display_name,
            (SELECT COUNT(*)
             FROM alert_responses ar
             WHERE ar.alert_id = pa.id
               AND ar.status = 'responding'
            ) as active_responders
        FROM panic_alerts pa
        LEFT JOIN users u ON pa.user_id = u.id
        WHERE pa.status = 'active'
          AND pa.user_id != ?
          AND ST_Distance_Sphere(
              POINT(pa.longitude, pa.latitude),
              POINT(?, ?)
          ) <= ?
        ORDER BY distance_meters ASC
        LIMIT 50
    ";

    $alerts = $database->fetchAll($query, [
        $userLng, $userLat,  // For distance calculation
        $userId,              // Exclude own alerts
        $userLng, $userLat,  // For WHERE clause
        $radius               // Radius in meters
    ]);

    // Format response
    $formattedAlerts = array_map(function($alert) {
        $distanceKm = round($alert['distance_meters'] / 1000, 2);
        $distanceDisplay = $distanceKm < 1
            ? round($alert['distance_meters']) . 'm'
            : $distanceKm . 'km';

        return [
            'id' => intval($alert['id']),
            'location' => [
                'lat' => floatval($alert['latitude']),
                'lng' => floatval($alert['longitude']),
                'name' => $alert['location_name']
            ],
            'message' => $alert['message'],
            'timestamp' => $alert['triggered_at'],
            'time_ago' => getTimeAgo($alert['triggered_at']),
            'distance' => [
                'meters' => round($alert['distance_meters']),
                'km' => $distanceKm,
                'display' => $distanceDisplay
            ],
            'responders_count' => intval($alert['responders_count']),
            'active_responders' => intval($alert['active_responders']),
            'status' => $alert['status'],
            'services_notified' => [
                'emergency_contacts' => (bool)$alert['emergency_contacts_notified'],
                'police' => (bool)$alert['police_notified'],
                'ambulance' => (bool)$alert['ambulance_notified']
            ],
            'user_display_name' => $alert['user_display_name'] ?? 'Anonymous'
        ];
    }, $alerts);

    // Update user's current location
    $database->execute(
        "UPDATE users
         SET current_latitude = ?,
             current_longitude = ?,
             last_location_update = NOW(),
             is_online = TRUE,
             last_seen = NOW()
         WHERE id = ?",
        [$userLat, $userLng, $userId]
    );

    echo json_encode([
        'success' => true,
        'alerts' => $formattedAlerts,
        'count' => count($formattedAlerts),
        'user_location' => [
            'lat' => $userLat,
            'lng' => $userLng
        ],
        'radius' => $radius,
        'timestamp' => date('Y-m-d H:i:s')
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Server error: ' . $e->getMessage()
    ]);
}

/**
 * Helper function to get human-readable time ago
 */
function getTimeAgo($timestamp) {
    $time = strtotime($timestamp);
    $diff = time() - $time;

    if ($diff < 60) {
        return 'Just now';
    } elseif ($diff < 3600) {
        $mins = floor($diff / 60);
        return $mins . ' minute' . ($mins > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $hours = floor($diff / 3600);
        return $hours . ' hour' . ($hours > 1 ? 's' : '') . ' ago';
    } else {
        $days = floor($diff / 86400);
        return $days . ' day' . ($days > 1 ? 's' : '') . ' ago';
    }
}
