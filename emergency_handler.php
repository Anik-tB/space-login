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

