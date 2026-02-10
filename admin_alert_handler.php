<?php
session_start();
require_once __DIR__ . '/includes/Database.php';
require_once __DIR__ . '/includes/broadcast_map_update.php';

// Check admin authentication
$userId = $_SESSION['user_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$database = new Database();
$user = $database->fetchOne("SELECT id, email, is_admin FROM users WHERE id = ?", [$userId]);

// Verify admin status
$isAdmin = false;
if (isset($user['is_admin']) && $user['is_admin'] == 1) {
    $isAdmin = true;
} elseif (strpos(strtolower($user['email'] ?? ''), 'admin') !== false) {
    $isAdmin = true;
}

if (!$isAdmin) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Access denied']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            // Validate required fields
            $title = trim($_POST['title'] ?? '');
            $description = trim($_POST['description'] ?? '');
            $type = $_POST['type'] ?? 'info';
            $severity = $_POST['severity'] ?? 'medium';
            $locationName = trim($_POST['location_name'] ?? '');
            $latitude = !empty($_POST['latitude']) ? floatval($_POST['latitude']) : null;
            $longitude = !empty($_POST['longitude']) ? floatval($_POST['longitude']) : null;
            $radiusKm = !empty($_POST['radius_km']) ? floatval($_POST['radius_km']) : 1.0;
            $endTime = !empty($_POST['end_time']) ? $_POST['end_time'] : null;

            if (empty($title)) {
                throw new Exception('Alert title is required');
            }

            if (empty($description)) {
                throw new Exception('Alert description is required');
            }

            // Create alert
            $models = new SafeSpaceModels($database);
            $alertId = $models->createAlert([
                'title' => $title,
                'description' => $description,
                'type' => $type,
                'severity' => $severity,
                'location_name' => $locationName,
                'latitude' => $latitude,
                'longitude' => $longitude,
                'radius_km' => $radiusKm,
                'source_type' => 'admin',
                'source_user_id' => $userId,
                'related_report_id' => null
            ]);

            if ($endTime) {
                $database->update(
                    "UPDATE alerts SET end_time = ? WHERE id = ?",
                    [$endTime, $alertId]
                );
            }

            // Log admin action
            $database->insert(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, created_at)
                 VALUES (?, ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    'admin_create_alert',
                    'alerts',
                    $alertId,
                    json_encode([
                        'title' => $title,
                        'severity' => $severity,
                        'type' => $type,
                        'location' => $locationName
                    ])
                ]
            );

            // Broadcast alert to community
            try {
                broadcastNewAlert([
                    'id' => $alertId,
                    'title' => $title,
                    'description' => $description,
                    'type' => $type,
                    'severity' => $severity,
                    'location_name' => $locationName,
                    'latitude' => $latitude,
                    'longitude' => $longitude,
                    'radius_km' => $radiusKm,
                    'source_type' => 'admin'
                ]);
            } catch (Exception $e) {
                error_log('Broadcast error: ' . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message' => 'Alert created successfully',
                'alert_id' => $alertId
            ]);
            break;

        case 'update':
            $alertId = intval($_POST['alert_id'] ?? 0);
            if (!$alertId) {
                throw new Exception('Alert ID is required');
            }

            // Get existing alert
            $existingAlert = $database->fetchOne(
                "SELECT * FROM alerts WHERE id = ?",
                [$alertId]
            );

            if (!$existingAlert) {
                throw new Exception('Alert not found');
            }

            // Update fields
            $title = trim($_POST['title'] ?? $existingAlert['title']);
            $description = trim($_POST['description'] ?? $existingAlert['description']);
            $type = $_POST['type'] ?? $existingAlert['type'];
            $severity = $_POST['severity'] ?? $existingAlert['severity'];
            $locationName = trim($_POST['location_name'] ?? $existingAlert['location_name']);
            $latitude = isset($_POST['latitude']) ? floatval($_POST['latitude']) : $existingAlert['latitude'];
            $longitude = isset($_POST['longitude']) ? floatval($_POST['longitude']) : $existingAlert['longitude'];
            $radiusKm = isset($_POST['radius_km']) ? floatval($_POST['radius_km']) : $existingAlert['radius_km'];
            $isActive = isset($_POST['is_active']) ? intval($_POST['is_active']) : $existingAlert['is_active'];
            $endTime = $_POST['end_time'] ?? $existingAlert['end_time'];

            $database->update(
                "UPDATE alerts SET
                    title = ?,
                    description = ?,
                    type = ?,
                    severity = ?,
                    location_name = ?,
                    latitude = ?,
                    longitude = ?,
                    radius_km = ?,
                    is_active = ?,
                    end_time = ?
                 WHERE id = ?",
                [
                    $title,
                    $description,
                    $type,
                    $severity,
                    $locationName,
                    $latitude,
                    $longitude,
                    $radiusKm,
                    $isActive,
                    $endTime,
                    $alertId
                ]
            );

            // Log admin action
            $database->insert(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, old_values, new_values, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, NOW())",
                [
                    $userId,
                    'admin_update_alert',
                    'alerts',
                    $alertId,
                    json_encode($existingAlert),
                    json_encode([
                        'title' => $title,
                        'severity' => $severity,
                        'is_active' => $isActive
                    ])
                ]
            );

            // Broadcast update
            try {
                broadcastMapUpdate('alert_update', [
                    'id' => $alertId,
                    'title' => $title,
                    'severity' => $severity,
                    'is_active' => $isActive
                ]);
            } catch (Exception $e) {
                error_log('Broadcast error: ' . $e->getMessage());
            }

            echo json_encode([
                'success' => true,
                'message' => 'Alert updated successfully'
            ]);
            break;

        case 'delete':
            $alertId = intval($_POST['alert_id'] ?? 0);
            if (!$alertId) {
                throw new Exception('Alert ID is required');
            }

            // Soft delete - deactivate instead of removing
            $database->update(
                "UPDATE alerts SET is_active = 0 WHERE id = ?",
                [$alertId]
            );

            // Log admin action
            $database->insert(
                "INSERT INTO audit_logs (user_id, action, table_name, record_id, created_at)
                 VALUES (?, ?, ?, ?, NOW())",
                [$userId, 'admin_delete_alert', 'alerts', $alertId]
            );

            echo json_encode([
                'success' => true,
                'message' => 'Alert deactivated successfully'
            ]);
            break;

        case 'get_all':
            $alerts = $database->fetchAll(
                "SELECT a.*, u.display_name, u.email
                 FROM alerts a
                 LEFT JOIN users u ON a.source_user_id = u.id
                 ORDER BY a.start_time DESC
                 LIMIT 50"
            );

            echo json_encode([
                'success' => true,
                'alerts' => $alerts
            ]);
            break;

        case 'get_single':
            $alertId = intval($_GET['alert_id'] ?? 0);
            if (!$alertId) {
                throw new Exception('Alert ID is required');
            }

            $alert = $database->fetchOne(
                "SELECT * FROM alerts WHERE id = ?",
                [$alertId]
            );

            if (!$alert) {
                throw new Exception('Alert not found');
            }

            echo json_encode([
                'success' => true,
                'alert' => $alert
            ]);
            break;

        default:
            throw new Exception('Invalid action');
    }
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
