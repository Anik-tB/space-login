<?php
session_start();
require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Location: login.html');
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {
    case 'create_alert':
        $data = [
            'user_id' => $userId,
            'trigger_method' => $_POST['trigger_method'] ?? 'app_button',
            'location_name' => $_POST['location_name'] ?? null,
            'latitude' => !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null,
            'longitude' => !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null,
            'message' => trim($_POST['message'] ?? ''),
            'police_notified' => isset($_POST['police_notified']) ? 1 : 0,
            'ambulance_notified' => isset($_POST['ambulance_notified']) ? 1 : 0,
            'fire_service_notified' => isset($_POST['fire_service_notified']) ? 1 : 0,
            'notify_emergency_contacts' => isset($_POST['emergency_contacts_notified']) && $_POST['emergency_contacts_notified'] == '1'
        ];

        $alertId = $models->createPanicAlert($data);

        if ($alertId) {
            // Get the panic alert details
            $panicAlert = $models->getPanicAlertById($alertId);

            // Notify community members within 5km radius if location is available
            if ($panicAlert && $panicAlert['latitude'] && $panicAlert['longitude']) {
                try {
                    error_log("=== PANIC ALERT COMMUNITY NOTIFICATION START ===");
                    error_log("Panic Alert ID: " . $alertId);
                    error_log("Location: " . $panicAlert['latitude'] . ", " . $panicAlert['longitude']);

                    // Find nearby users within 5km (5000 meters)
                    $nearbyUsers = $database->fetchAll(
                        "CALL find_nearby_users(?, ?, ?, ?)",
                        [
                            $panicAlert['latitude'],
                            $panicAlert['longitude'],
                            5000, // 5km radius in meters
                            $userId // Exclude the user who triggered the alert
                        ]
                    );

                    error_log("Nearby users found: " . count($nearbyUsers));

                    // Create a community alert for this panic event
                    $communityAlertId = $models->createAlert([
                        'title' => '🚨 Emergency Alert Nearby',
                        'description' => 'Someone in your area needs immediate help! This is an emergency panic alert.',
                        'type' => 'emergency',
                        'severity' => 'critical',
                        'location_name' => $panicAlert['location_name'] ?? 'Unknown Location',
                        'latitude' => $panicAlert['latitude'],
                        'longitude' => $panicAlert['longitude'],
                        'radius_km' => 5.0,
                        'source_type' => 'community',
                        'source_user_id' => $userId,
                        'related_report_id' => null
                    ]);

                    // Broadcast panic alert to nearby users via WebSocket
                    require_once __DIR__ . '/includes/broadcast_map_update.php';

                    $broadcastData = [
                        'id' => $alertId,
                        'community_alert_id' => $communityAlertId,
                        'title' => '🚨 Emergency Alert Nearby',
                        'description' => 'Someone needs immediate help!',
                        'latitude' => $panicAlert['latitude'],
                        'longitude' => $panicAlert['longitude'],
                        'location_name' => $panicAlert['location_name'] ?? 'Unknown Location',
                        'severity' => 'critical',
                        'type' => 'emergency',
                        'triggered_at' => $panicAlert['triggered_at'],
                        'nearby_users_count' => is_array($nearbyUsers) ? count($nearbyUsers) : 0
                    ];

                    error_log("Broadcasting panic alert: " . json_encode($broadcastData));

                    if (function_exists('broadcastPanicAlert')) {
                        $broadcastResult = broadcastPanicAlert($broadcastData);
                        error_log("Broadcast result: " . ($broadcastResult ? 'SUCCESS' : 'FAILED'));
                    } else {
                        // Fallback to regular broadcast
                        $broadcastResult = broadcastMapUpdate('panic_alert', $broadcastData);
                        error_log("Broadcast result (fallback): " . ($broadcastResult ? 'SUCCESS' : 'FAILED'));
                    }

                    // Log community notification in audit logs
                    $database->insert(
                        "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, created_at)
                         VALUES (?, ?, ?, ?, ?, NOW())",
                        [
                            $userId,
                            'community_alert_broadcast',
                            'panic_alerts',
                            $alertId,
                            json_encode([
                                'broadcast_radius' => 5000,
                                'nearby_users_count' => is_array($nearbyUsers) ? count($nearbyUsers) : 0,
                                'community_alert_id' => $communityAlertId
                            ])
                        ]
                    );

                    error_log("=== PANIC ALERT COMMUNITY NOTIFICATION END ===");

                } catch (Exception $e) {
                    error_log("PANIC ALERT ERROR: " . $e->getMessage());
                    error_log("Stack trace: " . $e->getTraceAsString());
                }
            } else {
                error_log("PANIC ALERT: No location data available for alert ID: " . $alertId);
            }

            header('Location: panic_button.php?success=1');
            exit;
        } else {
            header('Location: panic_button.php?error=1');
            exit;
        }
        break;

    case 'update_status':
        $alertId = intval($_POST['alert_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        $responseTime = !empty($_POST['response_time']) ? intval($_POST['response_time']) : null;

        if ($alertId && in_array($status, ['active', 'acknowledged', 'false_alarm', 'resolved'])) {
            // Verify ownership
            $alert = $models->getPanicAlertById($alertId);

            if ($alert && $alert['user_id'] == $userId) {
                $result = $models->updatePanicAlertStatus($alertId, $status, $responseTime);

                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
                } else {
                    http_response_code(400);
                    echo json_encode(['success' => false, 'message' => 'Failed to update status']);
                }
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        break;

    default:
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Invalid action']);
        break;
}
?>

