<?php
/**
 * Emergency Services API
 * Find nearby police stations, hospitals, and women's helpdesks
 *
 * Endpoints:
 * GET /api/emergency_services.php - Get all services or filter by type
 * GET /api/emergency_services.php?lat=X&lng=Y - Get nearby services with distance
 * GET /api/emergency_services.php?type=police_station - Filter by type
 * GET /api/emergency_services.php?helplines=1 - Get helpline numbers
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once '../db.php';

// Get parameters
$lat = isset($_GET['lat']) ? floatval($_GET['lat']) : null;
$lng = isset($_GET['lng']) ? floatval($_GET['lng']) : null;
$type = isset($_GET['type']) ? $_GET['type'] : 'all';
$radius = isset($_GET['radius']) ? intval($_GET['radius']) : 5000; // Default 5km
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$helplines = isset($_GET['helplines']) ? true : false;

// Get helpline numbers
if ($helplines) {
    try {
        $category = isset($_GET['category']) ? $conn->real_escape_string($_GET['category']) : null;

        $sql = "SELECT id, name, name_bn, number, category, description, description_bn,
                       organization, is_toll_free, operating_hours
                FROM helpline_numbers
                WHERE is_active = 1";

        if ($category && $category !== 'all') {
            $sql .= " AND category = '$category'";
        }

        $sql .= " ORDER BY priority ASC";

        $result = $conn->query($sql);

        if (!$result) {
            throw new Exception($conn->error);
        }

        $helplinesList = [];
        while ($row = $result->fetch_assoc()) {
            $helplinesList[] = $row;
        }

        echo json_encode([
            'success' => true,
            'count' => count($helplinesList),
            'data' => $helplinesList
        ]);
        exit();

    } catch (Exception $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error: ' . $e->getMessage()]);
        exit();
    }
}

// Get emergency services
try {
    // Base query
    $selectFields = "id, name, name_bn, type, address, address_bn, phone, emergency_phone,
                     email, website, latitude, longitude, operating_hours,
                     has_womens_cell, has_emergency_unit, verified, rating, total_ratings, image_url";

    // Escape type if provided
    $escapedType = $conn->real_escape_string($type);

    // If user location provided, calculate distance
    if ($lat !== null && $lng !== null) {
        // Haversine formula for distance in meters
        $sql = "SELECT $selectFields,
                (6371000 * acos(
                    cos(radians($lat)) * cos(radians(latitude)) *
                    cos(radians(longitude) - radians($lng)) +
                    sin(radians($lat)) * sin(radians(latitude))
                )) AS distance_meters
                FROM emergency_services
                WHERE 1=1";

        if ($type !== 'all') {
            $sql .= " AND type = '$escapedType'";
        }

        // Filter women's cell only
        if (isset($_GET['womens_cell']) && $_GET['womens_cell'] == '1') {
            $sql .= " AND has_womens_cell = 1";
        }

        $sql .= " HAVING distance_meters <= $radius
                  ORDER BY distance_meters ASC
                  LIMIT $limit";

    } else {
        // No location provided, return all services
        $sql = "SELECT $selectFields FROM emergency_services WHERE 1=1";

        if ($type !== 'all') {
            $sql .= " AND type = '$escapedType'";
        }

        if (isset($_GET['womens_cell']) && $_GET['womens_cell'] == '1') {
            $sql .= " AND has_womens_cell = 1";
        }

        $sql .= " ORDER BY type, name LIMIT $limit";
    }

    $result = $conn->query($sql);

    if (!$result) {
        throw new Exception($conn->error);
    }

    $services = [];
    while ($row = $result->fetch_assoc()) {
        $services[] = $row;
    }

    // Format response
    $formattedServices = array_map(function($service) {
        // Format distance if available
        if (isset($service['distance_meters'])) {
            $distance = floatval($service['distance_meters']);
            $service['distance'] = [
                'meters' => round($distance),
                'km' => round($distance / 1000, 2),
                'text' => $distance < 1000
                    ? round($distance) . ' m'
                    : round($distance / 1000, 1) . ' km'
            ];
            unset($service['distance_meters']);
        }

        // Convert boolean fields
        $service['has_womens_cell'] = (bool)$service['has_womens_cell'];
        $service['has_emergency_unit'] = (bool)$service['has_emergency_unit'];
        $service['verified'] = (bool)$service['verified'];
        $service['rating'] = floatval($service['rating']);
        $service['latitude'] = floatval($service['latitude']);
        $service['longitude'] = floatval($service['longitude']);

        // Add type label
        $typeLabels = [
            'police_station' => ['en' => 'Police Station', 'bn' => 'থানা'],
            'hospital' => ['en' => 'Hospital', 'bn' => 'হাসপাতাল'],
            'fire_station' => ['en' => 'Fire Station', 'bn' => 'ফায়ার স্টেশন'],
            'womens_helpdesk' => ['en' => "Women's Helpdesk", 'bn' => 'মহিলা হেল্পডেস্ক'],
            'ngo' => ['en' => 'NGO', 'bn' => 'এনজিও']
        ];
        $service['type_label'] = $typeLabels[$service['type']] ?? ['en' => $service['type'], 'bn' => $service['type']];

        // Add marker icon info
        $icons = [
            'police_station' => '👮',
            'hospital' => '🏥',
            'fire_station' => '🚒',
            'womens_helpdesk' => '👩‍⚖️',
            'ngo' => '🤝'
        ];
        $service['icon'] = $icons[$service['type']] ?? '📍';

        return $service;
    }, $services);

    // Group by type for easier frontend processing
    $grouped = [];
    foreach ($formattedServices as $service) {
        $grouped[$service['type']][] = $service;
    }

    echo json_encode([
        'success' => true,
        'count' => count($formattedServices),
        'user_location' => $lat !== null ? ['lat' => $lat, 'lng' => $lng] : null,
        'radius_meters' => $radius,
        'data' => $formattedServices,
        'grouped' => $grouped
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Database error: ' . $e->getMessage()
    ]);
}
