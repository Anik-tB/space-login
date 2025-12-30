<?php
session_start();
require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    http_response_code(401);
    exit('Unauthorized');
}

$action = $_GET['action'] ?? '';

switch ($action) {
    case 'download':
        $mediaId = intval($_GET['id'] ?? 0);
        if ($mediaId) {
            $media = $models->getGroupMediaById($mediaId);
            if ($media && $media['status'] === 'active' && file_exists($media['file_path'])) {
                // Check if user is member of the group
                $isMember = $models->isGroupMember($media['group_id'], $userId);
                if ($isMember || $media['is_public']) {
                    // Increment download count
                    $models->incrementMediaDownloads($mediaId);

                    // Set headers for download
                    header('Content-Type: ' . ($media['mime_type'] ?? 'application/octet-stream'));
                    header('Content-Disposition: attachment; filename="' . basename($media['file_name']) . '"');
                    header('Content-Length: ' . filesize($media['file_path']));
                    readfile($media['file_path']);
                    exit;
                }
            }
        }
        http_response_code(404);
        exit('File not found');

    case 'view':
        $mediaId = intval($_GET['id'] ?? 0);
        if ($mediaId) {
            $media = $models->getGroupMediaById($mediaId);
            if ($media && $media['status'] === 'active') {
                $isMember = $models->isGroupMember($media['group_id'], $userId);
                if ($isMember || $media['is_public']) {
                    $models->incrementMediaViews($mediaId);
                    echo json_encode(['success' => true]);
                    exit;
                }
            }
        }
        http_response_code(404);
        echo json_encode(['success' => false]);
        exit;

    default:
        http_response_code(400);
        exit('Invalid action');
}
?>

