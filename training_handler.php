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
    case 'enroll':
        $courseId = intval($_POST['course_id'] ?? 0);

        if ($courseId) {
            $enrollmentId = $models->enrollInCourse($userId, $courseId);

            if ($enrollmentId) {
                header('Location: safety_education.php?success=1');
                exit;
            } else {
                header('Location: safety_education.php?error=1');
                exit;
            }
        } else {
            header('Location: safety_education.php?error=1');
            exit;
        }
        break;

    case 'update_progress':
        header('Content-Type: application/json');
        $enrollmentId = intval($_POST['enrollment_id'] ?? 0);
        $progress = floatval($_POST['progress'] ?? 0);
        $status = $_POST['status'] ?? null;

        if ($enrollmentId && $progress >= 0 && $progress <= 100) {
            $enrollment = $models->getEnrollmentById($enrollmentId);

            if ($enrollment && $enrollment['user_id'] == $userId) {
                $newStatus = $status;
                if ($progress >= 100 && !$newStatus) {
                    $newStatus = 'completed';
                } elseif ($progress > 0 && !$newStatus) {
                    $newStatus = 'in_progress';
                }

                $result = $models->updateEnrollmentProgress($enrollmentId, $progress, $newStatus);

                // Issue certificate if completed
                if ($result && $newStatus === 'completed' && !$enrollment['certificate_issued']) {
                    $models->issueCertificate($userId, $enrollment['course_id'], $enrollmentId);
                }

                echo json_encode(['success' => true, 'message' => 'Progress updated successfully']);
            } else {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            }
        } else {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid parameters']);
        }
        break;

    case 'submit_rating':
        header('Content-Type: application/json');
        $enrollmentId = intval($_POST['enrollment_id'] ?? 0);
        $rating = intval($_POST['rating'] ?? 0);
        $feedback = trim($_POST['feedback'] ?? '');

        if ($enrollmentId && $rating >= 1 && $rating <= 5) {
            $enrollment = $models->getEnrollmentById($enrollmentId);

            if ($enrollment && $enrollment['user_id'] == $userId) {
                $result = $models->updateEnrollmentRating($enrollmentId, $rating, $feedback);

                if ($result) {
                    echo json_encode(['success' => true, 'message' => 'Rating submitted successfully']);
                } else {
                    http_response_code(500);
                    echo json_encode(['success' => false, 'message' => 'Failed to submit rating']);
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

