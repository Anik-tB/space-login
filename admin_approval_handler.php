<?php
session_start();
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit(0);
}

require_once __DIR__ . '/includes/Database.php';

// STRICT ADMIN AUTHENTICATION
$userId = $_SESSION['user_id'] ?? null;
if (!$userId || !isset($_SESSION['is_admin']) || $_SESSION['is_admin'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Admin access required']);
    exit;
}

$database = new Database();
$user = $database->fetchOne("SELECT id, email, display_name, is_admin FROM users WHERE id = ?", [$userId]);
if (!$user || $user['is_admin'] != 1) {
    echo json_encode(['success' => false, 'message' => 'Access denied - Not an administrator']);
    exit;
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$itemType = $_POST['item_type'] ?? $_GET['item_type'] ?? '';
$itemId = $_POST['item_id'] ?? $_GET['item_id'] ?? null;
$approvalStatus = $_POST['status'] ?? $_GET['status'] ?? '';
$notes = $_POST['notes'] ?? $_GET['notes'] ?? '';

if (empty($action) || empty($itemType) || !$itemId) {
    echo json_encode(['success' => false, 'message' => 'Missing required parameters']);
    exit;
}

try {
    $result = false;
    $message = '';

    switch ($itemType) {
        case 'incident_report':
            // Approve/reject incident report
            if ($approvalStatus === 'approved') {
                $newStatus = 'under_review';
                $message = 'Report approved and moved to review';
            } elseif ($approvalStatus === 'rejected') {
                $newStatus = 'closed';
                $message = 'Report rejected and closed';
            } else {
                $newStatus = $approvalStatus;
                $message = 'Report status updated';
            }

            // Check if record exists and is in pending status
            $checkSql = "SELECT id, status FROM incident_reports WHERE id = ?";
            $existing = $database->fetchOne($checkSql, [$itemId]);

            if (!$existing) {
                echo json_encode(['success' => false, 'message' => 'Report not found']);
                exit;
            }

            $sql = "UPDATE incident_reports SET status = ?, updated_date = NOW() WHERE id = ?";
            $result = $database->update($sql, [$newStatus, $itemId]);
            break;

        case 'community_group':
            // Approve/reject community group
            $newStatus = $approvalStatus === 'approved' ? 'active' : ($approvalStatus === 'rejected' ? 'suspended' : $approvalStatus);
            $sql = "UPDATE neighborhood_groups SET status = ? WHERE id = ?";
            $result = $database->update($sql, [$newStatus, $itemId]);
            $message = $approvalStatus === 'approved' ? 'Group approved' : 'Group rejected';
            break;

        case 'legal_provider':
            // Approve/reject legal aid provider
            $isVerified = $approvalStatus === 'approved' ? 1 : 0;
            $sql = "UPDATE legal_aid_providers SET is_verified = ? WHERE id = ?";
            $result = $database->update($sql, [$isVerified, $itemId]);
            $message = $approvalStatus === 'approved' ? 'Legal provider verified' : 'Legal provider verification removed';
            break;

        case 'medical_provider':
            // Approve/reject medical provider
            $isVerified = $approvalStatus === 'approved' ? 1 : 0;
            $sql = "UPDATE medical_support_providers SET is_verified = ? WHERE id = ?";
            $result = $database->update($sql, [$isVerified, $itemId]);
            $message = $approvalStatus === 'approved' ? 'Medical provider verified' : 'Medical provider verification removed';
            break;

        case 'dispute':
            // Resolve dispute (using 'approved'/'rejected' to match database enum)
            $newStatus = $approvalStatus === 'approved' ? 'approved' : ($approvalStatus === 'rejected' ? 'rejected' : $approvalStatus);
            $sql = "UPDATE disputes SET status = ? WHERE id = ?";
            $result = $database->update($sql, [$newStatus, $itemId]);
            $message = $approvalStatus === 'approved' ? 'Dispute approved' : 'Dispute rejected';
            break;

        case 'alert':
            // Approve/activate alert
            $isActive = $approvalStatus === 'approved' ? 1 : 0;
            $sql = "UPDATE alerts SET is_active = ? WHERE id = ?";
            $result = $database->update($sql, [$isActive, $itemId]);
            $message = $approvalStatus === 'approved' ? 'Alert activated' : 'Alert deactivated';
            break;

        case 'safe_space':
            // Approve safe space (using 'pending_verification' to match database enum)
            $newStatus = $approvalStatus === 'approved' ? 'active' : ($approvalStatus === 'rejected' ? 'inactive' : $approvalStatus);
            $sql = "UPDATE safe_spaces SET status = ? WHERE id = ?";
            $result = $database->update($sql, [$newStatus, $itemId]);
            $message = $approvalStatus === 'approved' ? 'Safe space approved' : 'Safe space rejected';
            break;

        case 'user':
            // Approve/activate user
            $newStatus = $approvalStatus === 'approved' ? 'active' : ($approvalStatus === 'rejected' ? 'suspended' : $approvalStatus);
            $sql = "UPDATE users SET status = ? WHERE id = ?";
            $result = $database->update($sql, [$newStatus, $itemId]);
            $message = $approvalStatus === 'approved' ? 'User approved' : 'User suspended';
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid item type']);
            exit;
    }

    // Log admin action (using actual audit_logs table structure)
    if ($result && $database->tableExists('audit_logs')) {
        try {
            $logSql = "INSERT INTO audit_logs (user_id, action, table_name, record_id, new_values, created_at)
                      VALUES (?, ?, ?, ?, ?, NOW())";
            $newValues = json_encode([
                'action' => $action,
                'approval_status' => $approvalStatus,
                'item_type' => $itemType,
                'notes' => $notes
            ]);
            $database->insert($logSql, [$userId, 'admin_approval', $itemType, $itemId, $newValues]);
        } catch (Exception $e) {
            // Silently fail audit logging
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }

    if ($result) {
        // Get updated counts for all pending items
        $counts = [];

        // Count pending reports
        if ($database->tableExists('incident_reports')) {
            $counts['reports'] = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM incident_reports WHERE status = 'pending'"
            )['count'] ?? 0);
        }

        // Count pending groups
        if ($database->tableExists('neighborhood_groups')) {
            $counts['groups'] = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM neighborhood_groups WHERE status = 'pending_approval'"
            )['count'] ?? 0);
        }

        // Count pending disputes
        if ($database->tableExists('disputes')) {
            $counts['disputes'] = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM disputes WHERE status = 'pending'"
            )['count'] ?? 0);
        }

        // Count unverified legal providers
        if ($database->tableExists('legal_aid_providers')) {
            $counts['legal'] = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM legal_aid_providers WHERE is_verified = 0 OR is_verified IS NULL"
            )['count'] ?? 0);
        }

        // Count unverified medical providers
        if ($database->tableExists('medical_support_providers')) {
            $counts['medical'] = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM medical_support_providers WHERE is_verified = 0 OR is_verified IS NULL"
            )['count'] ?? 0);
        }

        // Count pending alerts
        if ($database->tableExists('alerts')) {
            $counts['alerts'] = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM alerts WHERE is_active = 0 OR is_active IS NULL"
            )['count'] ?? 0);
        }

        // Count pending safe spaces
        if ($database->tableExists('safe_spaces')) {
            $counts['spaces'] = (int)($database->fetchOne(
                "SELECT COUNT(*) as count FROM safe_spaces WHERE status = 'pending_verification' OR status IS NULL"
            )['count'] ?? 0);
        }

        echo json_encode([
            'success' => true,
            'message' => $message,
            'item_id' => $itemId,
            'item_type' => $itemType,
            'counts' => $counts
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Failed to update item']);
    }

} catch (Exception $e) {
    error_log('Admin approval error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>

