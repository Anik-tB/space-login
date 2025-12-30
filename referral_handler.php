<?php
session_start();
require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'update_status':
        $referralId = intval($_POST['referral_id'] ?? 0);
        $status = $_POST['status'] ?? '';

        if ($referralId && in_array($status, ['pending', 'contacted', 'appointment_scheduled', 'completed', 'declined'])) {
            $referral = $models->getReferralById($referralId);

            // Check if user owns this referral
            if ($referral && $referral['user_id'] == $userId) {
                $result = $models->updateReferral($referralId, ['status' => $status]);

                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Status updated successfully']);
                } else {
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

    case 'update_appointment':
        $referralId = intval($_POST['referral_id'] ?? 0);
        $appointmentDate = $_POST['appointment_date'] ?? null;

        if ($referralId) {
            $referral = $models->getReferralById($referralId);

            if ($referral && $referral['user_id'] == $userId) {
                $updateData = [];
                if ($appointmentDate) {
                    $updateData['appointment_date'] = $appointmentDate;
                    $updateData['status'] = 'appointment_scheduled';
                }

                $result = $models->updateReferral($referralId, $updateData);

                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Appointment updated successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Failed to update appointment']);
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

