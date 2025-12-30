<?php
session_start();
require_once 'includes/Database.php';

$database = new Database();
$models = new SafeSpaceModels($database);

$userId = $_SESSION['user_id'] ?? null;

if (!$userId) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

header('Content-Type: application/json');

try {
    switch ($action) {
       case 'join':
            $groupId = intval($_GET['group_id'] ?? $_POST['group_id'] ?? 0);

            if (!$groupId) {
                throw new Exception('Group ID is required');
            }

            // Check if group exists and is active
            $group = $models->getNeighborhoodGroupById($groupId);
            if (!$group || $group['status'] !== 'active') {
                throw new Exception('Group not found or not active');
            }

            // Check if already a member
            $isMember = $models->isGroupMember($groupId, $userId);
            if ($isMember) {
                throw new Exception('You are already a member of this group');
            }

            // Check privacy level
            if ($group['privacy_level'] === 'invite_only') {
                throw new Exception('This group is invite-only. Please contact the group administrator.');
            }

            // Add member
            $result = $models->addGroupMember($groupId, $userId, 'member');

            if ($result) {
                // *** FIX: FORCE UPDATE MEMBER COUNT IMMEDIATELY ***
                $models->updateGroupMemberCount($groupId);

                // Create notification
                $models->createNotification([
                    'user_id' => $userId,
                    'title' => 'Joined Community Group',
                    'message' => 'You have successfully joined "' . $group['group_name'] . '"',
                    'type' => 'system',
                    'action_url' => 'group_detail.php?id=' . $groupId
                ]);

                header('Location: group_detail.php?id=' . $groupId . '&joined=1');
                exit;
            } else {
                throw new Exception('Failed to join group');
            }
            break;
        case 'leave':
            $groupId = intval($_GET['group_id'] ?? $_POST['group_id'] ?? 0);

            if (!$groupId) {
                throw new Exception('Group ID is required');
            }

            // Check if member
            $isMember = $models->isGroupMember($groupId, $userId);
            if (!$isMember) {
                throw new Exception('You are not a member of this group');
            }

            // Check if founder (Founders should not be able to leave without transferring ownership)
            if ($isMember['role'] === 'founder') {
                throw new Exception('Founders cannot leave their group. Please transfer ownership first.');
            }

            // Remove member
            $result = $models->removeGroupMember($groupId, $userId);

            if ($result) {
                // *** FIX: FORCE UPDATE MEMBER COUNT IMMEDIATELY ***
                // This recalculates the count in the database so the UI updates instantly
                $models->updateGroupMemberCount($groupId);

                header('Location: community_groups.php?left=1');
                exit;
            } else {
                throw new Exception('Failed to leave group');
            }
            break;
    }
} catch (Exception $e) {
    if (!headers_sent()) {
        header('Location: community_groups.php?error=' . urlencode($e->getMessage()));
    } else {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>

